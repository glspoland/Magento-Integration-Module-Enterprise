<?php

declare(strict_types=1);

namespace GlsPoland\Shipping\Model;

use GlsPoland\Shipping\Config\Config;
use GlsPoland\Shipping\Helper\CarrierHelper;
use GlsPoland\Shipping\Helper\DateHelper;
use GlsPoland\Shipping\Service\Soap\SoapRequest;
use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Shipment;
use stdClass;

class ApiHandler
{
    /** @var SoapRequest */
    private SoapRequest $soapRequest;

    /** @var Config */
    private Config $config;

    /** @var DateHelper */
    private DateHelper $dateHelper;

    /** @var CarrierHelper */
    private CarrierHelper $carrierHelper;

    /**
     * Controller constructor
     *
     * @param SoapRequest $soapRequest
     * @param Config $config
     * @param DateHelper $dateHelper
     * @param CarrierHelper $carrierHelper
     */
    public function __construct(
        SoapRequest $soapRequest,
        Config $config,
        DateHelper $dateHelper,
        CarrierHelper $carrierHelper
    ) {
        $this->soapRequest = $soapRequest;
        $this->config = $config;
        $this->dateHelper = $dateHelper;
        $this->carrierHelper = $carrierHelper;
    }

    /**
     * Create preparing box
     *
     * @param DataObject $request
     * @return DataObject
     */
    public function createPreparingBox(DataObject $request): DataObject
    {
        $consign = $this->createConsign($request);

        return $this->soapRequest->insertPreparingBox($consign);
    }

    /**
     * Get Preparing Box Consign Docs
     *
     * @param string $preparingBoxId
     * @return stdClass|null
     */
    public function getPreparingBoxConsignDocs(string $preparingBoxId): ?stdClass
    {
        return $this->soapRequest->getPreparingBoxConsignDocs(
            (int)$preparingBoxId,
            $this->config->getShippingLabelType()
        );
    }

    /**
     * Get Preparing Box Consign Customs Dec
     *
     * @param string $preparingBoxId
     * @return string|null
     */
    public function getPreparingBoxConsignCustomsDec(string $preparingBoxId): ?string
    {
        return $this->soapRequest->getPreparingBoxConsignCustomsDec((int)$preparingBoxId);
    }

    /**
     * Create Pickup
     *
     * @param ConsignsIDsArray $ids
     * @param string $shippingCode
     * @return int|null
     */
    public function createPickup(ConsignsIDsArray $ids, string $shippingCode): ?int
    {
        return $this->soapRequest->pickupCreate($ids, $this->config->getDefaultComment($shippingCode));
    }

    /**
     * Get Pickup Consign IDs
     *
     * @param int $id
     * @return ConsignsIDsArray|null
     */
    public function getPickupConsignIDs(int $id): ?ConsignsIDsArray
    {
        return $this->soapRequest->pickupGetConsignIDs($id);
    }

    /**
     * Get Pickup Receipt
     *
     * @param int $id
     * @return string|null
     */
    public function getPickupReceipt(int $id): ?string
    {
        return $this->soapRequest->pickupGetReceipt($id, 'with_barcodes');
    }

    /**
     * Get Pickup Labels
     *
     * @param int $id
     * @param string $shippingLabelType
     * @return string|null
     */
    public function getPickupLabels(int $id, string $shippingLabelType = 'one_label_on_a4_lt_pdf'): ?string
    {
        return $this->soapRequest->pickupGetLabels($id, $shippingLabelType);
    }

    /**
     * Get Pickup Ident
     *
     * @param int $id
     * @return string|null
     */
    public function getPickupIdent(int $id): ?string
    {
        return $this->soapRequest->pickupGetIdent($id);
    }

    /**
     * Get Pickup Consign Customs Dec
     *
     * @param int $id
     * @return string|null
     */
    public function getPickupConsignCustomsDec(int $id): ?string
    {
        return $this->soapRequest->pickupGetConsignCustomsDec($id);
    }

    /**
     * Get Pickup Consign PODs
     *
     * @param int $id
     * @return PodsArray|null
     */
    public function getPickupConsignPODs(int $id): ?PodsArray
    {
        return $this->soapRequest->pickupGetConsignPODs($id);
    }

    /**
     * Get Pickup Consign
     *
     * @param int $id
     * @return Consign|null
     */
    public function getPickupConsign(int $id): ?Consign
    {
        return $this->soapRequest->pickupGetConsign($id);
    }

    /**
     * Create consign
     *
     * @param DataObject $request
     * @return Consign
     */
    private function createConsign(DataObject $request): Consign
    {
        $orderShipment = $request->getOrderShipment();
        $order = $orderShipment->getOrder();
        $packages = $request->getPackages();
        $receiverAddress = $this->getReceiverAddress($request);
        $serviceBOOL = $this->createServiceBOOL($request);
        $serviceAde = $this->createSrvAde($request);
        $serviceSDS = $this->createServiceSDS($request);
        $servicePPE = $this->createServicePPE($request);
        $isSrs = $request->hasIsReturn() && $request->getIsReturn() &&
            $request->hasServiceSrs() && $request->getServiceSrs();

        $parcels = [];
        $shippingWeight = 0.0;

        foreach ($packages as $packageId => $package) {
            $packageWeight = $package['params']['weight']
                ? (float)$package['params']['weight']
                : (float)$this->config->getDefaultPackageWeight($order->getShippingMethod());

            $shippingWeight += $packageWeight;

            $parcels[] = new Parcel(
                (string)$packageId,
                $this->config->getDefaultReference($order->getShippingMethod())
                    ?? (string)$order->getEntityId(),
                $packageWeight,
                $serviceBOOL,
                $serviceAde
            );
        }

        $parcelsArray = new ParcelsArray($parcels);

        return new Consign(
            $receiverAddress->name1,
            $receiverAddress->country,
            $receiverAddress->zipcode,
            $receiverAddress->city,
            $receiverAddress->street,
            $receiverAddress->phone,
            $receiverAddress->email,
            $receiverAddress->name2,
            $receiverAddress->name3,
            $this->config->getDefaultReference($order->getShippingMethod()) ??$order->getEntityId(),
            $this->config->getDefaultComment($order->getShippingMethod()),
            count($packages),
            $shippingWeight,
            $this->dateHelper->getNextWorkDay(null, true),
            null,
            !$isSrs && $this->config->getUseAlternativeSenderAddress()
                ? $this->config->getSenderAddress()
                : null,
            $serviceBOOL,
            null,
            null,
            null,
            $servicePPE,
            $serviceSDS,
            $parcelsArray,
        );
    }

    /**
     * Create Service PPE
     *
     * @param DataObject $request
     * @return ServicePPE|null
     */
    private function createServicePPE(DataObject $request): ?ServicePPE
    {
        if (!$request->hasIsReturn() ||
            !$request->getIsReturn() ||
            !$request->hasServiceSrs() ||
            !$request->getServiceSrs()
        ) {
            return null;
        }

        $orderShipment = $request->getOrderShipment();
        $order = $orderShipment->getOrder();
        $packages = $request->getPackages();
        $shippingWeight = 0.0;

        foreach ($packages as $package) {
            $packageWeight = $package['params']['weight']
                ? (float)$package['params']['weight']
                : (float)$this->config->getDefaultPackageWeight($order->getShippingMethod());

            $shippingWeight += $packageWeight;
        }

        $receiverAddress = $this->getReceiverAddress($request);
        $senderAddress = $this->getSenderAddress($request);

        return new ServicePPE(
            $senderAddress->name1,
            $senderAddress->country,
            $senderAddress->zipcode,
            $senderAddress->city,
            $senderAddress->street,
            $receiverAddress->name1,
            $receiverAddress->country,
            $receiverAddress->zipcode,
            $receiverAddress->city,
            $receiverAddress->street,
            $shippingWeight,
            $senderAddress->name2,
            $senderAddress->name3,
            $senderAddress->phone,
            null,
            $receiverAddress->name2,
            $receiverAddress->name3,
            $receiverAddress->phone,
            null,
            $this->config->getDefaultReference($order->getShippingMethod())
                ?? __('Order: ') . $order->getEntityId(),
        );
    }

    /**
     * Get sender Address
     *
     * @param DataObject $request
     * @return stdClass
     */
    private function getSenderAddress(DataObject $request): stdClass
    {
        $senderAddress = new stdClass();
        $isSrs = $request->hasIsReturn() && $request->getIsReturn() &&
            $request->hasServiceSrs() && $request->getServiceSrs();

        if ($isSrs) {
            $orderShipment = $request->getOrderShipment();
            $order = $orderShipment->getOrder();
            $shippingData = $order->getShippingAddress();
            $senderAddress->name1 = $shippingData?->getFirstname();
            $senderAddress->name2 = $shippingData?->getMiddlename();
            $senderAddress->name3 = $shippingData?->getLastname();
            $senderAddress->country = $shippingData?->getCountryId();
            $senderAddress->zipcode = $shippingData?->getPostcode();
            $senderAddress->city = $shippingData?->getCity();
            $street = $shippingData?->getStreetLine(1);
            $street .= $shippingData?->getStreetLine(2) ? ' ' . $shippingData?->getStreetLine(2) : '';
            $street .= $shippingData?->getStreetLine(3) ? ' ' . $shippingData?->getStreetLine(3) : '';
            $street .= $shippingData?->getStreetLine(4) ? ' ' . $shippingData?->getStreetLine(4) : '';
            $senderAddress->street = $street;
            $senderAddress->phone = $shippingData?->getTelephone();
            $senderAddress->email = $shippingData?->getEmail();
        } else {
            $storeAddress = $this->config->getStoreAddress();
            $senderAddress->name1 = $storeAddress->name;
            $senderAddress->name2 = null;
            $senderAddress->name3 = null;
            $senderAddress->country = $storeAddress->country;
            $senderAddress->zipcode = $storeAddress->zipcode;
            $senderAddress->city = $storeAddress->city;
            $senderAddress->street = $storeAddress->street;
            $senderAddress->phone = $storeAddress->phone;
            $senderAddress->email = $storeAddress->mail;
        }

        return $senderAddress;
    }

    /**
     * Get receiver Address
     *
     * @param DataObject $request
     * @return stdClass
     */
    private function getReceiverAddress(DataObject $request): stdClass
    {
        $receiverAddress = new stdClass();
        $isSrs = $request->hasIsReturn() && $request->getIsReturn() &&
            $request->hasServiceSrs() && $request->getServiceSrs();

        if ($isSrs) {
            $storeAddress = $this->config->getStoreAddress();
            $receiverAddress->name1 = $storeAddress->name;
            $receiverAddress->name2 = null;
            $receiverAddress->name3 = null;
            $receiverAddress->country = $storeAddress->country;
            $receiverAddress->zipcode = $storeAddress->zipcode;
            $receiverAddress->city = $storeAddress->city;
            $receiverAddress->street = $storeAddress->street;
            $receiverAddress->phone = $storeAddress->phone;
            $receiverAddress->email = $storeAddress->mail;
        } else {
            $orderShipment = $request->getOrderShipment();
            $order = $orderShipment->getOrder();
            $shippingData = $order->getShippingAddress();
            $receiverAddress->name1 = $shippingData?->getFirstname();
            $receiverAddress->name2 = $shippingData?->getMiddlename();
            $receiverAddress->name3 = $shippingData?->getLastname();
            $receiverAddress->country = $shippingData?->getCountryId();
            $receiverAddress->zipcode = $shippingData?->getPostcode();
            $receiverAddress->city = $shippingData?->getCity();
            $street = $shippingData?->getStreetLine(1);
            $street .= $shippingData?->getStreetLine(2) ? ' ' . $shippingData?->getStreetLine(2) : '';
            $street .= $shippingData?->getStreetLine(3) ? ' ' . $shippingData?->getStreetLine(3) : '';
            $street .= $shippingData?->getStreetLine(4) ? ' ' . $shippingData?->getStreetLine(4) : '';
            $receiverAddress->street = $street;
            $receiverAddress->phone = $shippingData?->getTelephone();
            $receiverAddress->email = $shippingData?->getEmail();
        }

        return $receiverAddress;
    }

    /**
     * Create service BOOL
     *
     * @param DataObject $request
     * @return ServiceBOOL
     */
    private function createServiceBOOL(DataObject $request): ServiceBOOL
    {
        $orderShipment = $request->getOrderShipment();
        $order = $orderShipment->getOrder();
        $method = $order->getShippingMethod();
        $isSrs = $request->hasIsReturn() && $request->getIsReturn() &&
            $request->hasServiceSrs() && $request->getServiceSrs();

        return new ServiceBOOL(
            $this->carrierHelper->isShippingCod($order) && !$isSrs,
            $this->carrierHelper->isShippingCod($order) && !$isSrs ? (float)$order->getGrandTotal() : 0.0,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            (bool)ShippingMethods::METHODS[$method]['s10'] && !$isSrs,
            (bool)ShippingMethods::METHODS[$method]['s12'] && !$isSrs,
            (bool)ShippingMethods::METHODS[$method]['sat'] && !$isSrs,
            false,
            $isSrs,
            (bool)ShippingMethods::METHODS[$method]['parcel'] && !$isSrs,
            false,
            0.0,
            '',
            false,
        );
    }

    /**
     * Create svr ADE
     *
     * @param DataObject $request
     * @return string
     */
    private function createSrvAde(DataObject $request): string
    {
        $orderShipment = $request->getOrderShipment();
        $order = $orderShipment->getOrder();
        $shippingMethod = $order->getShippingMethod();
        $isSrs = $request->hasIsReturn() && $request->getIsReturn() &&
            $request->hasServiceSrs() && $request->getServiceSrs();
        $ade = [];

        if (!$isSrs && $this->carrierHelper->isShippingCod($order)) {
            $ade[] = 'COD ' . (float)$order->getGrandTotal() . $order->getOrderCurrencyCode();
        }

        if (!$isSrs && ShippingMethods::METHODS[$shippingMethod]['s10']) {
            $ade[] = '10:00';
        }

        if (!$isSrs && ShippingMethods::METHODS[$shippingMethod]['s12']) {
            $ade[] = '12:00';
        }

        if (!$isSrs && ShippingMethods::METHODS[$shippingMethod]['sat']) {
            $ade[] = 'SAT';
        }

        if (!$isSrs && ShippingMethods::METHODS[$shippingMethod]['parcel']) {
            $ade[] = 'SDS';
        }

        if ($isSrs) {
            $ade[] = 'SRS';
        }

        return implode(',', $ade);
    }

    /**
     * Create service SDS
     *
     * @param DataObject $request
     * @return ServiceSDS|null
     */
    private function createServiceSDS(DataObject $request): ?ServiceSDS
    {
        if ($request->hasIsReturn() && $request->getIsReturn()) {
            return null;
        }

        $orderShipment = $request->getOrderShipment();
        $order = $orderShipment->getOrder();
        $shippingMethod = $order->getShippingMethod();

        if (isset(ShippingMethods::METHODS[$shippingMethod]) && ShippingMethods::METHODS[$shippingMethod]['parcel']) {
            $glsPolandParcelShopId = (string)$order->getData('gls_poland_parcel_shop_id');

            if ($glsPolandParcelShopId) {
                return new ServiceSDS($glsPolandParcelShopId);
            }
        }

        return null;
    }

    /**
     * Delete preparing box from GLS
     *
     * @param int $id
     * @return int|null
     */
    public function deletePreparingBox(int $id): ?int
    {
        return $this->soapRequest->getPreparingBoxDeleteConsign($id);
    }

    /**
     * Get Services Guaranteed
     *
     * @param string $zipcode
     * @return ServiceBOOL|null
     */
    public function getServicesGuaranteed(string $zipcode): ?ServiceBOOL
    {
        return $this->soapRequest->getServicesGuaranteed($zipcode);
    }

    /**
     * Get Service Max COD
     *
     * @return float|null
     */
    public function getServicesMaxCOD(): ?float
    {
        return $this->soapRequest->getServicesMaxCOD();
    }

    /**
     * Get Service Allowed
     *
     * @return ServiceBool|null
     */
    public function getServicesAllowed(): ?ServiceBool
    {
        return $this->soapRequest->getServicesAllowed();
    }

    /**
     * Get Services Countries SDS
     *
     * @return array|null
     */
    public function getServicesCountriesSDS(): ?array
    {
        return $this->soapRequest->getServicesCountriesSDS();
    }

    /**
     * Get Services Countries SRS
     *
     * @return ServiceBool|null
     */
    public function getServicesCountriesSRS(): ?array
    {
        return $this->soapRequest->getServicesCountriesSRS();
    }

    /**
     * Get Parcel Shop Search By ID
     *
     * @param string $id
     * @return ParcelShop|null
     */
    public function getParcelShopSearchByID(string $id): ?ParcelShop
    {
        return $this->soapRequest->getParcelShopSearchByID($id);
    }
}
