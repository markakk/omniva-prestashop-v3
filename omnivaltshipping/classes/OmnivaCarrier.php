<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaCarrier
{
    private static array $methods = [
        'omnivalt_pt' => 'Parcel terminal',
        'omnivalt_c' => 'Courier',
        'omnivalt_int_premium' => 'International (Premium)',
        'omnivalt_int_standard' => 'International (Standard)',
        'omnivalt_int_economy' => 'International (Economy)',
        'omnivalt_el' => 'Express Letter',
    ];

    public static function getAllMethods(): array
    {
        return self::$methods;
    }

    public static function getIdKey(string $method_key): string
    {
        return $method_key . '_id';
    }

    public static function getReferenceKey(string $method_key): string
    {
        return $method_key . '_reference';
    }

    public static function updateMappingValues(string $method_key, int $carrier_id, $carrier_reference = false): void
    {
        Configuration::updateValue(self::getIdKey($method_key), $carrier_id);
        if ($carrier_reference) {
            Configuration::updateValue(self::getReferenceKey($method_key), $carrier_reference);
        }
    }

    public static function getId(string $method_key): int
    {
        return (int) Configuration::get(self::getIdKey($method_key));
    }

    public static function getReference(string $method_key): int
    {
        return (int) Configuration::get(self::getReferenceKey($method_key));
    }

    public static function getAllMethodsData(): array
    {
        $methods_data = [];
        foreach (self::$methods as $key => $title) {
            $short_key = OmnivaApiInternational::getPackageKeyFromMethodKey($key);
            $methods_data[$short_key] = [
                'id' => $key,
                'title' => $title,
                'carrier_id' => self::getId($key),
                'carrier_reference' => self::getReference($key),
                'is_international' => OmnivaApiInternational::isInternationalMethod($key),
            ];
        }

        return $methods_data;
    }

    public static function createCarrier(string $method_key, string $title, string $module_name, $logo_dir = false): bool
    {
        $carrier = new Carrier();
        $carrier->name = $title;
        $carrier->active = true;
        $carrier->deleted = 0;
        $carrier->shipping_handling = true;
        $carrier->range_behavior = 0;
        $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = '1-2 business days';
        $carrier->shipping_external = true;
        $carrier->is_module = true;
        $carrier->external_module_name = $module_name;
        $carrier->need_range = true;
        $carrier->url = 'https://www.omniva.lt/verslo/siuntos_sekimas?barcode=@';

        if (!$carrier->add()) {
            return false;
        }

        $groups = Group::getGroups(true);
        foreach ($groups as $group) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int) $carrier->id,
                'id_group' => (int) $group['id_group'],
            ]);
        }

        $rangePrice = new RangePrice();
        $rangePrice->id_carrier = $carrier->id;
        $rangePrice->delimiter1 = '0';
        $rangePrice->delimiter2 = '1000';
        $rangePrice->add();

        $rangeWeight = new RangeWeight();
        $rangeWeight->id_carrier = $carrier->id;
        $rangeWeight->delimiter1 = '0';
        $rangeWeight->delimiter2 = '1000';
        $rangeWeight->add();

        $zones = Zone::getZones(true);
        foreach ($zones as $zone) {
            Db::getInstance()->insert('carrier_zone', [
                'id_carrier' => (int) $carrier->id,
                'id_zone' => (int) $zone['id_zone'],
            ]);
            Db::getInstance()->insert('delivery', [
                'id_carrier' => (int) $carrier->id,
                'id_range_price' => (int) $rangePrice->id,
                'id_range_weight' => null,
                'id_zone' => (int) $zone['id_zone'],
                'price' => '0',
            ], true);
            Db::getInstance()->insert('delivery', [
                'id_carrier' => (int) $carrier->id,
                'id_range_price' => null,
                'id_range_weight' => (int) $rangeWeight->id,
                'id_zone' => (int) $zone['id_zone'],
                'price' => '0',
            ], true);
        }

        if ($logo_dir && file_exists($logo_dir)) {
            OmnivaHelper::copyImageAsJpg($logo_dir, _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg');
        }

        self::updateMappingValues($method_key, (int) $carrier->id, (int) $carrier->id);

        return true;
    }

    public static function getCarrierMethodKey($carrier_id = false, $carrier_ref_id = false)
    {
        foreach (self::$methods as $key => $title) {
            if ($carrier_id && $carrier_id == self::getId($key)) {
                return $key;
            }
            if ($carrier_ref_id && $carrier_ref_id == self::getReference($key)) {
                return $key;
            }
        }

        return false;
    }

    public static function getCarrier(string $method_key)
    {
        $carrier_id = self::getId($method_key);
        if (!$carrier_id) {
            return false;
        }

        $carrier = new Carrier($carrier_id);
        return !empty($carrier->id) ? $carrier : false;
    }

    public static function getCarrierById(int $carrier_id)
    {
        $carrier = new Carrier($carrier_id);
        return !empty($carrier->id) ? $carrier : false;
    }

    public static function isOmnivaCarrier($carrier_id = false, $carrier_ref_id = false): bool
    {
        return (bool) self::getCarrierMethodKey($carrier_id, $carrier_ref_id);
    }

    public static function isOmnivaTerminalCarrier($carrier_id = false, $carrier_ref_id = false): bool
    {
        $method_key = self::getCarrierMethodKey($carrier_id, $carrier_ref_id);
        return $method_key === 'omnivalt_pt';
    }

    public static function markAsDeleted(string $method_key): bool
    {
        $carrier = self::getCarrier($method_key);
        if (!$carrier) {
            return false;
        }
        $carrier->deleted = true;
        $carrier->update();

        return true;
    }

    public static function unmarkAsDeleted(string $method_key): bool
    {
        $carrier = self::getCarrier($method_key);
        if (!$carrier) {
            return false;
        }

        $newest_carrier_id = (int) Db::getInstance()->getValue(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE id_reference = ' . (int) $carrier->id_reference . ' ORDER BY id_carrier DESC'
        );

        $newest_carrier = self::getCarrierById($newest_carrier_id);
        if (!$newest_carrier) {
            return false;
        }

        $newest_carrier->deleted = false;
        $newest_carrier->update();

        self::updateMappingValues($method_key, (int) $newest_carrier->id, (int) $newest_carrier->id_reference);

        return true;
    }
}
