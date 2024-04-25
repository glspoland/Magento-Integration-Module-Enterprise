<?php

declare(strict_types=1);

namespace GlsPoland\Shipping\Block\Adminhtml\Shipping;

use GlsPoland\Shipping\Config\Config;
use GlsPoland\Shipping\Model\ShippingMethods;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;

class GlsPackaging extends Template
{
    /** @var ShipmentRepositoryInterface */
    protected ShipmentRepositoryInterface $shipmentRepository;

    /** @var OrderRepositoryInterface */
    protected OrderRepositoryInterface $orderRepository;

    /** @var RequestInterface */
    protected RequestInterface $request;

    /** @var Config */
    protected Config $config;

    /**
     *  Class constructor.
     *
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param RequestInterface $request
     * @param Config $config
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param array $data
     */
    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        RequestInterface $request,
        Config $config,
        Context $context,
        OrderRepositoryInterface $orderRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->shipmentRepository = $shipmentRepository;
        $this->request = $request;
        $this->config = $config;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Get is Service SRS Active
     *
     * @return bool
     */
    public function isServiceSrsAvailable(): bool
    {
        $isGlsPolandShipping = $this->isGlsPolandShipping();
        $availableCountries = $this->config->getServicesCountriesSRS();
        $countryId = $this->getCountryId();

        return $isGlsPolandShipping && in_array($countryId, $availableCountries);
    }

    /**
     * Get is Gls Poland Shipping
     *
     * @return bool
     */
    public function isGlsPolandShipping(): bool
    {
        $shippingMethod = $this->getShippingMethod();

        if (isset(ShippingMethods::METHODS[$shippingMethod])) {
            return true;
        }

        return false;
    }

    /**
     * Get Order
     *
     * @return OrderInterface|null
     */
    private function getOrder(): ?OrderInterface
    {
        $orderId = $this->request->getParam('order_id');

        return $this->orderRepository->get($orderId);
    }

    /**
     * Get Shipping Method
     *
     * @return string|null
     */
    private function getShippingMethod(): ?string
    {
        return $this->getOrder()?->getShippingMethod();
    }

    /**
     * Get Shipping Method
     *
     * @return string
     */
    private function getCountryId(): string
    {
        return $this->getOrder()?->getShippingAddress()?->getCountryId();
    }
}
