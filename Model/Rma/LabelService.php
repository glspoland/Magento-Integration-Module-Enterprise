<?php

declare(strict_types=1);

namespace GlsPoland\Shipping\Model\Rma;

use Exception;
use GlsPoland\Shipping\Config\Config as GlsPolandConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Rma\Helper\Data;
use Magento\Rma\Model\ResourceModel\ShippingFactory as ShippingResourceFactory;
use Magento\Rma\Model\Rma;
use Magento\Rma\Model\Shipping;
use Magento\Rma\Model\Shipping\LabelService as BaseLabelService;
use Magento\Rma\Model\ShippingFactory;
use Zend_Pdf_Exception;

class LabelService extends BaseLabelService
{
    private const GLS_POLAND_PARCEL_SHOP_CODE = 'glspoland_gls_parcel_shop';

    /** @var Data */
    private Data $rmaHelper;

    /** @var ShippingFactory */
    private ShippingFactory $shippingFactory;

    /** @var ShippingResourceFactory */
    private ShippingResourceFactory $shippingResourceFactory;

    /** @var Json */
    private Json $json;

    /** @var GlsPolandConfig */
    private GlsPolandConfig $glsPolandConfig;

    /**
     * Class constructor
     *
     * @param Data $rmaHelper
     * @param ShippingFactory $shippingFactory
     * @param ShippingResourceFactory $shippingResourceFactory
     * @param Filesystem $filesystem
     * @param GlsPolandConfig $glsPolandConfig
     * @param Json|null $json
     */
    public function __construct(
        Data $rmaHelper,
        ShippingFactory $shippingFactory,
        ShippingResourceFactory $shippingResourceFactory,
        Filesystem $filesystem,
        GlsPolandConfig $glsPolandConfig,
        Json $json = null
    ) {
        parent::__construct($rmaHelper, $shippingFactory, $shippingResourceFactory, $filesystem, $json);

        $this->rmaHelper = $rmaHelper;
        $this->shippingFactory = $shippingFactory;
        $this->shippingResourceFactory = $shippingResourceFactory;
        $this->filesystem = $filesystem;
        $this->glsPolandConfig = $glsPolandConfig;
        $this->json = $json ?: ObjectManager::getInstance()->get(Json::class);
    }

    /**
     * Create shipping label for specific shipment with validation
     *
     * @param Rma $rmaModel
     * @param array $data
     * @return bool
     * @throws LocalizedException
     * @throws Zend_Pdf_Exception
     * @throws Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function createShippingLabel(Rma $rmaModel, $data = []): bool
    {
        if (empty($data['code']) || $data['code'] !== self::GLS_POLAND_PARCEL_SHOP_CODE) {
            parent::createShippingLabel($rmaModel, $data);
        }

        if (!$this->glsPolandConfig->getActiveRmaSrs()) {
            return false;
        }

        if (empty($data['packages'])) {
            return false;
        }

        $carrier = $this->rmaHelper->getCarrier($data['code'], $rmaModel->getStoreId());

        if (!$carrier->isShippingLabelsAvailable()) {
            return false;
        }

        $shippingModel = $this->shippingFactory->create();

        /** @var $shipment Shipping */
        $shipment = $shippingModel->getShippingLabelByRma($rmaModel);

        $shipment->setPackages($data['packages']);
        $shipment->setCode($data['code']);

        [$carrierCode, $methodCode] = explode('_', $data['code'], 2);
        $shipment->setCarrierCode($carrierCode);
        $shipment->setMethodCode($methodCode);

        $weight = 0;

        foreach ($data['packages'] as $package) {
            $weight += $package['params']['weight'];
        }

        $shipment->setWeight($weight);
        $shipment->setCarrierTitle($data['carrier_title']);
        $shipment->setMethodTitle($data['method_title']);
        $shipment->setPrice($data['price']);
        $shipment->setRma($rmaModel);
        $shipment->setIncrementId($rmaModel->getIncrementId());

        $response = $shipment->requestToShipment();

        if ($response->hasErrorMsg()) {
            throw new LocalizedException(__($response->getErrorMsg()));
        }

        if (!$response->hasPreparingBoxId()) {
            throw new LocalizedException(__('Response Preparing Box Id is not exist.'));
        }

        if (!$response->hasShippingConfirmationId()) {
            throw new LocalizedException(__('Shipping Confirmation ID is empty.'));
        }

        if (!$response->hasShippingLabel()) {
            throw new LocalizedException(__('Pickup Labels are empty.'));
        }

        if (!$response->hasTrackingNumbers()) {
            throw new LocalizedException(__('Tracking Numbers are empty.'));
        }

        $shippingResource = $this->shippingResourceFactory->create();

        switch ($response->getShippingLabelFormat()) {
            case 'roll_160x100_datamax':
            case 'roll_160x100_zebra':
            case 'roll_160x100_zebra_300':
            case 'roll_160x100_zebra_epl':
                $shipment->setShippingLabel($response->getShippingLabel());
                break;
            default:
                $shipment->setShippingLabel($this->combineLabelsPdf([$response->getShippingLabel()])->render());
                break;
        }

        $shipment->setPackages($this->json->serialize($data['packages']));
        $shipment->setIsAdmin(Shipping::IS_ADMIN_STATUS_ADMIN_LABEL);
        $shipment->setRmaEntityId($rmaModel->getId());
        $shipment->setGlsPolandPreparingBoxId($response->getPreparingBoxId() ?: null);
        $shipment->setGlsPolandShippingConfirmationId($response->getShippingConfirmationId() ?: null);
        $shipment->setGlsPolandShippingLabelFormat($response->getShippingLabelFormat() ?: null);
        $shippingResource->save($shipment);

        if ($response->hasTrackingNumbers() && count($response->getTrackingNumbers()) > 0) {

            $shippingResource->deleteTrackingNumbers($rmaModel);

            foreach ($response->getTrackingNumbers() as $trackingNumber) {
                $this->addTrack(
                    $rmaModel->getId(),
                    $trackingNumber,
                    $carrier->getCarrierCode(),
                    $carrier->getConfigData('title'),
                    Shipping::IS_ADMIN_STATUS_ADMIN_LABEL_TRACKING_NUMBER
                );
            }
        }

        return true;
    }
}
