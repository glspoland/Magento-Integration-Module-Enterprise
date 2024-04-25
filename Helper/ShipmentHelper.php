<?php

declare(strict_types=1);

namespace GlsPoland\Shipping\Helper;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;

class ShipmentHelper
{
    /** @var ShipmentRepositoryInterface */
    protected ShipmentRepositoryInterface $shipmentRepository;

    /** @var SearchCriteriaBuilder */
    protected SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * Class constructor
     *
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Get Service SRS by Order ID and Packages (products) from Shipment
     *
     * @param int $orderId
     * @param array $rmaPackages
     * @return bool
     */
    public function getServiceSrs(int $orderId, array $rmaPackages): bool
    {
        $shipments = $this->getShipments($orderId);

        if ($shipments === null) {
            return false;
        }

        $rmaProductIds = $this->getProductIds($rmaPackages);

        foreach ($shipments as $shipment) {
            $shipmentPackages = $shipment->getPackages();
            $shipmentProductIds = $this->getProductIds($shipmentPackages);

            foreach ($shipmentProductIds as $shipmentProductId) {
                if (in_array($shipmentProductId, $rmaProductIds, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get ShipmentInterface array by order ID
     *
     * @param int $orderId
     * @return ShipmentInterface[]|null
     */
    private function getShipments(int $orderId): ?array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('order_id', $orderId)
            ->addFilter('gls_poland_service_srs', 1)
            ->create();

        $shipments = $this->shipmentRepository->getList($searchCriteria);

        if ($shipments->getTotalCount() > 0) {
            return $shipments->getItems();
        }

        return null;
    }

    /**
     * Get product IDs from packages
     *
     * @param array $packages
     * @return array
     */
    private function getProductIds(array $packages): array
    {
        $productIds = [];

        foreach ($packages as $package) {
            foreach ($package['items'] as $item) {
                $productIds[] = (int)$item['product_id'];
            }
        }

        return $productIds;
    }
}
