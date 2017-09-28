<?php declare(strict_types=1);

namespace Shopware\Order\Loader;

use Doctrine\DBAL\Connection;
use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Struct\SortArrayByKeysTrait;
use Shopware\Order\Factory\OrderDetailFactory;
use Shopware\Order\Struct\OrderDetailCollection;
use Shopware\Order\Struct\OrderDetailStruct;
use Shopware\OrderDelivery\Loader\OrderDeliveryDetailLoader;
use Shopware\OrderDelivery\Searcher\OrderDeliverySearcher;
use Shopware\OrderLineItem\Searcher\OrderLineItemSearcher;
use Shopware\OrderLineItem\Searcher\OrderLineItemSearchResult;
use Shopware\Search\Criteria;
use Shopware\Search\Query\TermsQuery;

class OrderDetailLoader
{
    use SortArrayByKeysTrait;

    /**
     * @var OrderDetailFactory
     */
    private $factory;

    /**
     * @var OrderLineItemSearcher
     */
    private $orderLineItemSearcher;

    /**
     * @var OrderDeliverySearcher
     */
    private $orderDeliverySearcher;

    /**
     * @var OrderDeliveryDetailLoader
     */
    private $orderDeliveryDetailLoader;

    public function __construct(
        OrderDetailFactory $factory,
        OrderLineItemSearcher $orderLineItemSearcher,
        OrderDeliverySearcher $orderDeliverySearcher,
        OrderDeliveryDetailLoader $orderDeliveryDetailLoader
    ) {
        $this->factory = $factory;
        $this->orderLineItemSearcher = $orderLineItemSearcher;
        $this->orderDeliverySearcher = $orderDeliverySearcher;
        $this->orderDeliveryDetailLoader = $orderDeliveryDetailLoader;
    }

    public function load(array $uuids, TranslationContext $context): OrderDetailCollection
    {
        if (empty($uuids)) {
            return new OrderDetailCollection();
        }

        $ordersCollection = $this->read($uuids, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new TermsQuery('order_line_item.order_uuid', $uuids));
        /** @var OrderLineItemSearchResult $lineItems */
        $lineItems = $this->orderLineItemSearcher->search($criteria, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new TermsQuery('order_delivery.order_uuid', $uuids));
        $deliveriesUuids = $this->orderDeliverySearcher->searchUuids($criteria, $context);
        $deliveries = $this->orderDeliveryDetailLoader->load($deliveriesUuids->getUuids(), $context);

        /** @var OrderDetailStruct $order */
        foreach ($ordersCollection as $order) {
            $order->setLineItems($lineItems->filterByOrderUuid($order->getUuid()));

            $order->setDeliveries($deliveries->filterByOrderUuid($order->getUuid()));
        }

        return $ordersCollection;
    }

    private function read(array $uuids, TranslationContext $context): OrderDetailCollection
    {
        $query = $this->factory->createQuery($context);

        $query->andWhere('order.uuid IN (:ids)');
        $query->setParameter(':ids', $uuids, Connection::PARAM_STR_ARRAY);

        $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        $structs = [];
        foreach ($rows as $row) {
            $struct = $this->factory->hydrate($row, new OrderDetailStruct(), $query->getSelection(), $context);
            $structs[$struct->getUuid()] = $struct;
        }

        return new OrderDetailCollection(
            $this->sortIndexedArrayByKeys($uuids, $structs)
        );
    }
}