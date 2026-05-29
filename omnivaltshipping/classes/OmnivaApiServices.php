<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Package\ServicePackage;
use Mijora\Omniva\Shipment\AdditionalService\CodService;
use Mijora\Omniva\Shipment\AdditionalService\DeliveryToAnAdultService;
use Mijora\Omniva\Shipment\AdditionalService\DeliveryToSpecificPersonService;
use Mijora\Omniva\Shipment\AdditionalService\DocumentReturnService;
use Mijora\Omniva\Shipment\AdditionalService\FragileService;
use Mijora\Omniva\Shipment\AdditionalService\InsuranceService;
use Mijora\Omniva\Shipment\AdditionalService\LetterDeliveryToASpecificPersonService;
use Mijora\Omniva\Shipment\AdditionalService\RegisteredAdviceOfDeliveryService;
use Mijora\Omniva\Shipment\AdditionalService\SameDayDeliveryService;
use Mijora\Omniva\Shipment\AdditionalService\SecondDeliveryAttemptOnSaturdayService;
use Mijora\Omniva\Shipment\AdditionalService\StandardAdviceOfDeliveryService;

class OmnivaApiServices
{
    public static function getChannels(): array
    {
        return [
            'terminal' => self::getConstantValue('Package', 'CHANNEL_PARCEL_MACHINE'),
            'courier' => self::getConstantValue('Package', 'CHANNEL_COURIER'),
            'post' => self::getConstantValue('Package', 'CHANNEL_POST_OFFICE'),
            'postbox' => self::getConstantValue('Package', 'CHANNEL_POST_BOX'),
        ];
    }

    public static function getShipmentTypes(): array
    {
        return [
            'parcel' => self::getConstantValue('Package', 'MAIN_SERVICE_PARCEL'),
            'letter' => self::getConstantValue('Package', 'MAIN_SERVICE_LETTER'),
            'pallet' => self::getConstantValue('Package', 'MAIN_SERVICE_PALLET'),
        ];
    }

    public static function getInternationalServiceCodes(): array
    {
        return [
            'economy' => self::getConstantValue('ServicePackage', 'CODE_ECONOMY'),
            'standard' => self::getConstantValue('ServicePackage', 'CODE_STANDARD'),
            'premium' => self::getConstantValue('ServicePackage', 'CODE_PREMIUM'),
        ];
    }

    public static function getLetterServiceCodes(): array
    {
        return [
            'document' => self::getConstantValue('ServicePackage', 'CODE_PROCEDURAL_DOCUMENT'),
            'registered' => self::getConstantValue('ServicePackage', 'CODE_REGISTERED_LETTER'),
            'maxiletter' => self::getConstantValue('ServicePackage', 'CODE_REGISTERED_MAXILETTER'),
            'express' => self::getConstantValue('ServicePackage', 'CODE_EXPRESS_LETTER'),
        ];
    }

    public static function getAdditionalServices(): array
    {
        $module = Module::getInstanceByName('omnivaltshipping');

        return [
            'cod' => [
                'title' => $module->translate('Cash on delivery', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new CodService())->getServiceCode(),
                'class' => CodService::class,
            ],
            'persons_over_18' => [
                'title' => $module->translate('Issue to persons at the age of 18+', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new DeliveryToAnAdultService())->getServiceCode(),
                'class' => DeliveryToAnAdultService::class,
            ],
            'personal_delivery' => [
                'title' => $module->translate('Personal delivery', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new DeliveryToSpecificPersonService())->getServiceCode(),
                'class' => DeliveryToSpecificPersonService::class,
            ],
            'personal_delivery_letter' => [
                'title' => $module->translate('Personal delivery', [], 'Modules.Omnivaltshipping.Admin') . ' (' . $module->translate('Letter', [], 'Modules.Omnivaltshipping.Admin') . ')',
                'code' => (new LetterDeliveryToASpecificPersonService())->getServiceCode(),
                'class' => LetterDeliveryToASpecificPersonService::class,
            ],
            'doc_return' => [
                'title' => $module->translate('Document return', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new DocumentReturnService())->getServiceCode(),
                'class' => DocumentReturnService::class,
            ],
            'fragile' => [
                'title' => $module->translate('Fragile', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new FragileService())->getServiceCode(),
                'class' => FragileService::class,
            ],
            'insurance' => [
                'title' => $module->translate('Insurance', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new InsuranceService())->getServiceCode(),
                'class' => InsuranceService::class,
            ],
            'standard_advice_delivery' => [
                'title' => $module->translate('Standard Advice Of Delivery', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new StandardAdviceOfDeliveryService())->getServiceCode(),
                'class' => StandardAdviceOfDeliveryService::class,
            ],
            'registered_advice_delivery' => [
                'title' => $module->translate('Registered Advice Of Delivery', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new RegisteredAdviceOfDeliveryService())->getServiceCode(),
                'class' => RegisteredAdviceOfDeliveryService::class,
            ],
            'same_day_delivery' => [
                'title' => $module->translate('Same day delivery', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new SameDayDeliveryService())->getServiceCode(),
                'class' => SameDayDeliveryService::class,
            ],
            'second_delivery_saturday' => [
                'title' => $module->translate('Second delivery attempt on Saturday', [], 'Modules.Omnivaltshipping.Admin'),
                'code' => (new SecondDeliveryAttemptOnSaturdayService())->getServiceCode(),
                'class' => SecondDeliveryAttemptOnSaturdayService::class,
            ],
        ];
    }

    public static function haveTerminals(string $country_iso): bool
    {
        return in_array(strtoupper($country_iso), ['LT', 'LV', 'EE', 'FI']);
    }

    public static function getTerminalsType(string $shipment_type_key, string $channel_key)
    {
        if ($shipment_type_key === 'parcel') {
            if ($channel_key === 'terminal') {
                return 'terminal';
            }
            if ($channel_key === 'post') {
                return 'post';
            }
        }
        return false;
    }

    private static function getConstantValue(string $class_name, string $constant_key, mixed $on_fail = false): mixed
    {
        $classes = [
            'Package' => Package::class,
            'ServicePackage' => ServicePackage::class,
        ];

        if (!isset($classes[$class_name])) {
            return $on_fail;
        }

        $fqn = $classes[$class_name] . '::' . $constant_key;
        return defined($fqn) ? constant($fqn) : $on_fail;
    }
}
