<?php

declare(strict_types=1);

namespace Semaio\StockUpdaterFix\Content\Product\DataAbstractionLayer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Content\Product\DataAbstractionLayer\StockUpdater as BaseStockUpdater;
use Shopware\Core\Content\Product\Events\ProductNoLongerAvailableEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\ChangeSetAware;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Profiling\Profiler;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class StockUpdater extends BaseStockUpdater implements EventSubscriberInterface
{
    /**
     * @internal
     */
    public function __construct(private Connection $connection, private EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Returns a list of custom business events to listen where the product maybe changed
     *
     * @return array<string, string|array{0: string, 1: int}|list<array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents()
    {
        return [
            CheckoutOrderPlacedEvent::class => 'orderPlaced',
            StateMachineTransitionEvent::class => 'stateChanged',
            PreWriteValidationEvent::class => 'triggerChangeSet',
            OrderEvents::ORDER_LINE_ITEM_WRITTEN_EVENT => 'lineItemWritten',
            OrderEvents::ORDER_LINE_ITEM_DELETED_EVENT => 'lineItemWritten',
        ];
    }

    public function triggerChangeSet(PreWriteValidationEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getCommands() as $command) {
            if (!$command instanceof ChangeSetAware) {
                continue;
            }

            /** @var DeleteCommand|InsertCommand|UpdateCommand $command */
            if ($command->getDefinition()->getEntityName() !== OrderLineItemDefinition::ENTITY_NAME) {
                continue;
            }

            if ($command instanceof InsertCommand) {
                continue;
            }

            if ($command instanceof DeleteCommand) {
                $command->requestChangeSet();

                continue;
            }

            /** @var WriteCommand&ChangeSetAware $command */
            if ($command->hasField('referenced_id') || $command->hasField('product_id') || $command->hasField('quantity')) {
                $command->requestChangeSet();
            }
        }
    }

    public function orderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $lineItems = $event->getOrder()->getLineItems();
        if ($lineItems === null) {
            return;
        }

        $ids = [];
        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $referencedId = $lineItem->getReferencedId();
            if ($referencedId === null) {
                continue;
            }

            if (!\array_key_exists($referencedId, $ids)) {
                $ids[$referencedId] = 0;
            }

            $ids[$referencedId] += $lineItem->getQuantity();
        }

        // order placed event is a high load event. Because of the high load, we simply reduce the quantity here instead of executing the high costs `update` function
        $query = new RetryableQuery(
            $this->connection,
            $this->connection->prepare('UPDATE product SET stock = stock - :quantity, available_stock = available_stock - :quantity, updated_at = :now WHERE id = :id')
        );

        Profiler::trace('order::update-stock', static function () use ($query, $ids): void {
            foreach ($ids as $id => $quantity) {
                $query->execute([
                    'id' => Uuid::fromHexToBytes((string) $id),
                    'quantity' => $quantity,
                    'now' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]);
            }
        });

        Profiler::trace('order::update-flag', function () use ($ids, $event): void {
            $this->updateAvailableFlag(\array_keys($ids), $event->getContext());
        });
    }

    /**
     * If the product of an order item changed, the stocks of the old product and the new product must be updated.
     */
    public function lineItemWritten(EntityWrittenEvent $event): void
    {
        $ids = [];

        // we don't want to trigger to `update` method when we are inside the order process
        if ($event->getContext()->hasState('checkout-order-route')) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            if ($result->hasPayload('referencedId') && $result->getProperty('type') === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $ids[] = $result->getProperty('referencedId');
            }

            if ($result->getOperation() === EntityWriteResult::OPERATION_INSERT) {
                continue;
            }

            $changeSet = $result->getChangeSet();
            if (!$changeSet) {
                continue;
            }

            $type = $changeSet->getBefore('type');
            if ($type !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            if (!$changeSet->hasChanged('referenced_id') && !$changeSet->hasChanged('quantity')) {
                continue;
            }

            $ids[] = $changeSet->getBefore('referenced_id');
            $ids[] = $changeSet->getAfter('referenced_id');
        }

        $ids = array_filter(array_unique($ids));
        if (empty($ids)) {
            return;
        }

        $this->update($ids, $event->getContext());
    }

    public function stateChanged(StateMachineTransitionEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        if ($event->getEntityName() !== 'order') {
            return;
        }

        if ($event->getFromPlace()->getTechnicalName() === OrderStates::STATE_CANCELLED) {
            $this->decreaseStock($event);
        }

        if ($event->getToPlace()->getTechnicalName() === OrderStates::STATE_CANCELLED) {
            $this->increaseStock($event);
        }
    }

    public function update(array $ids, Context $context): void
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $this->updateAvailableStockAndSales($ids, $context);
        $this->updateAvailableFlag($ids, $context);
    }

    private function increaseStock(StateMachineTransitionEvent $event): void
    {
        $products = $this->getProductsOfOrder($event->getEntityId());

        $ids = array_column($products, 'referenced_id');

        $this->updateStock($products, +1);
        $this->updateAvailableStockAndSales($ids, $event->getContext());
        $this->updateAvailableFlag($ids, $event->getContext());
    }

    private function decreaseStock(StateMachineTransitionEvent $event): void
    {
        $products = $this->getProductsOfOrder($event->getEntityId());

        $ids = array_column($products, 'referenced_id');

        $this->updateStock($products, -1);
        $this->updateAvailableStockAndSales($ids, $event->getContext());
        $this->updateAvailableFlag($ids, $event->getContext());
    }

    private function updateAvailableStockAndSales(array $ids, Context $context): void
    {
        $ids = array_filter(array_keys(array_flip($ids)));
        if (empty($ids)) {
            return;
        }

        $update = new RetryableQuery(
            $this->connection,
            $this->connection->prepare('UPDATE product SET available_stock = stock, updated_at = :now WHERE id = :id')
        );

        foreach ($ids as $id) {
            $update->execute([
                'id' => Uuid::fromHexToBytes((string) $id),
                'now' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }
    }

    private function updateAvailableFlag(array $ids, Context $context): void
    {
        $ids = array_filter(array_unique($ids));
        if (empty($ids)) {
            return;
        }

        $bytes = Uuid::fromHexToBytesList($ids);

        $sql = '
            UPDATE product
            LEFT JOIN product parent
                ON parent.id = product.parent_id
                AND parent.version_id = product.version_id

            SET product.available = IFNULL((
                IFNULL(product.is_closeout, parent.is_closeout) * product.available_stock
                >=
                IFNULL(product.is_closeout, parent.is_closeout) * IFNULL(product.min_purchase, parent.min_purchase)
            ), 0)
            WHERE product.id IN (:ids)
            AND product.version_id = :version
        ';

        RetryableQuery::retryable($this->connection, function () use ($sql, $context, $bytes): void {
            $this->connection->executeUpdate(
                $sql,
                [
                    'ids' => $bytes,
                    'version' => Uuid::fromHexToBytes($context->getVersionId()),
                ],
                [
                    'ids' => Connection::PARAM_STR_ARRAY,
                ]
            );
        });

        $updated = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM product WHERE available = 0 AND id IN (:ids) AND product.version_id = :version',
            [
                'ids' => $bytes,
                'version' => Uuid::fromHexToBytes($context->getVersionId()),
            ],
            [
                'ids' => Connection::PARAM_STR_ARRAY,
            ]
        );

        if (!empty($updated)) {
            $this->dispatcher->dispatch(new ProductNoLongerAvailableEvent($updated, $context));
        }
    }

    private function updateStock(array $products, int $multiplier): void
    {
        $query = new RetryableQuery(
            $this->connection,
            $this->connection->prepare('UPDATE product SET stock = stock + :quantity WHERE id = :id AND version_id = :version')
        );

        foreach ($products as $product) {
            $quantity = (int) $product['quantity'] * $multiplier;
            if ($quantity < 0) {
                $quantity = 0;
            }

            $query->execute([
                'quantity' => $quantity,
                'id' => Uuid::fromHexToBytes($product['referenced_id']),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]);
        }
    }

    private function getProductsOfOrder(string $orderId): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['referenced_id', 'quantity']);
        $query->from('order_line_item');
        $query->andWhere('type = :type');
        $query->andWhere('order_id = :id');
        $query->andWhere('version_id = :version');
        $query->setParameter('id', Uuid::fromHexToBytes($orderId));
        $query->setParameter('version', Uuid::fromHexToBytes(Defaults::LIVE_VERSION));
        $query->setParameter('type', LineItem::PRODUCT_LINE_ITEM_TYPE);

        return $query->execute()->fetchAllAssociative(); // @phpstan-ignore-line
    }
}
