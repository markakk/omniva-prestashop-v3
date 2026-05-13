<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mijora\BoxCalculator\Elements\Item as BoxCalcItem;
use Mijora\BoxCalculator\CalculateBox;

class OmnivaHelper
{
    const ENABLE_LOGS = false;

    public static function getModuleDir(): string
    {
        return dirname(__DIR__) . '/';
    }

    /*======================== Logging ========================*/

    public static function printToLog($log_data, string $file_name = 'debug'): void
    {
        if (!self::ENABLE_LOGS) {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9_-]*$/', $file_name)) {
            $file_name = 'debug';
        }

        $dir = self::getModuleDir() . 'logs';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $time = date('Y-m-d H:i:s');
        $content = '[' . $time . '] ' . print_r($log_data, true);
        file_put_contents($dir . '/' . $file_name . '.log', $content . PHP_EOL, FILE_APPEND);
    }

    public static function buildExceptionMessage(\Exception $e, string $prefix = ''): string
    {
        $msg = $e->getMessage();
        return $prefix ? $prefix . ': ' . $msg : $msg;
    }

    /*======================== Image ========================*/

    public static function copyImageAsJpg(string $source, string $dest, int $quality = 90): bool
    {
        if (!file_exists($source)) {
            return false;
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $img = imagecreatefrompng($source);
            $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
            imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            $result = imagejpeg($bg, $dest, $quality);
            imagedestroy($img);
            imagedestroy($bg);
            return $result;
        }

        return copy($source, $dest);
    }

    /*======================== Cart & Products ========================*/

    public static function getCartItems(array $cart_products, bool $split_quantity = false): array
    {
        $items = [];
        foreach ($cart_products as $prod) {
            if (!isset($prod['id_product'])) {
                continue;
            }
            if (!empty($prod['is_virtual'])) {
                continue;
            }

            $item = [
                'product_id' => $prod['id_product'],
                'product_attribute_id' => $prod['id_product_attribute'] ?? 0,
                'shop_id' => $prod['id_shop'] ?? 1,
                'quantity' => $prod['cart_quantity'] ?? 1,
                'name' => $prod['name'] ?? '',
                'price_org' => $prod['price_without_reduction'] ?? 0,
                'discount' => $prod['reduction'] ?? 0,
                'price' => $prod['price_with_reduction'] ?? 0,
                'price_total' => $prod['total_wt'] ?? 0,
                'tax_rate' => $prod['rate'] ?? 0,
                'tax_name' => $prod['tax_name'] ?? '',
                'attribute' => $prod['attributes'] ?? '',
                'weight' => $prod['weight'] ?? 0,
                'width' => $prod['width'] ?? 0,
                'height' => $prod['height'] ?? 0,
                'length' => $prod['depth'] ?? 0,
                'dimensions_unit' => Configuration::get('PS_DIMENSION_UNIT'),
                'weight_unit' => Configuration::get('PS_WEIGHT_UNIT'),
            ];

            if ($split_quantity && $item['quantity'] > 1) {
                for ($i = 0; $i < $item['quantity']; $i++) {
                    $split = $item;
                    $split['quantity'] = 1;
                    $split['price_total'] = $item['price'];
                    $items[] = $split;
                }
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }

    /*======================== Unit Conversions ========================*/

    public static function convertWeightUnit(float $value, string $unit_to, $unit_from = false): float
    {
        if (!$unit_from) {
            $unit_from = Configuration::get('PS_WEIGHT_UNIT') ?: 'kg';
        }

        if ($unit_from === $unit_to) {
            return $value;
        }

        // Convert to kg first
        $weight_map_to_kg = ['mg' => 1_000_000, 'g' => 1000, 't' => 0.001];
        $value_in_kg = isset($weight_map_to_kg[$unit_from])
            ? ($unit_from === 't' ? $value * 1000 : $value / $weight_map_to_kg[$unit_from])
            : $value;

        // Convert from kg to target
        $weight_map_from_kg = ['mg' => 1_000_000, 'g' => 1000, 't' => 0.001];
        if (isset($weight_map_from_kg[$unit_to])) {
            return $unit_to === 't' ? $value_in_kg / 1000 : $value_in_kg * $weight_map_from_kg[$unit_to];
        }

        return $value_in_kg;
    }

    public static function convertDimensionsUnit(float $value, string $unit_to, $unit_from = false): float
    {
        if (!$unit_from) {
            $unit_from = Configuration::get('PS_DIMENSION_UNIT') ?: 'cm';
        }

        if ($unit_from === $unit_to) {
            return $value;
        }

        // Convert to cm first
        $to_cm = ['mm' => 0.1, 'dm' => 10, 'm' => 100];
        $value_in_cm = isset($to_cm[$unit_from]) ? $value * $to_cm[$unit_from] : $value;

        // Convert from cm to target
        $from_cm = ['mm' => 10, 'dm' => 0.1, 'm' => 0.01];
        if (isset($from_cm[$unit_to])) {
            return $value_in_cm * $from_cm[$unit_to];
        }

        return $value_in_cm;
    }

    /*======================== Box Size Prediction ========================*/

    public static function predictOrderSize(array $items_data, array $max_dimension = [])
    {
        $items_list = array_map(
            fn($prod) => new BoxCalcItem($prod['width'], $prod['height'], $prod['length']),
            $items_data
        );

        $box_calculator = new CalculateBox($items_list);
        $box_calculator->setBoxWallThickness(0);

        if (!empty($max_dimension)) {
            $box_calculator->setMaxBoxSize(
                $max_dimension['width'] ?? 999999,
                $max_dimension['height'] ?? 999999,
                $max_dimension['length'] ?? 999999
            );
            $box_size = $box_calculator->findBoxSizeUntilMaxSize();
        } else {
            $box_size = $box_calculator->findMinBoxSize();
        }

        if (!$box_size) {
            return false;
        }

        return [
            'length' => $box_size->getLength(),
            'width' => $box_size->getWidth(),
            'height' => $box_size->getHeight(),
        ];
    }

    /*======================== Courier Calls ========================*/

    public static function getScheduledCourierCalls(): array
    {
        $raw = Configuration::get('omnivalt_courier_calls');
        if (!$raw) {
            return [];
        }
        $all_calls = json_decode($raw, true);
        if (!is_array($all_calls)) {
            // Backwards compatibility: try unserializing old format
            $all_calls = @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($all_calls)) {
                return [];
            }
            // Migrate to JSON format
            Configuration::updateValue('omnivalt_courier_calls', json_encode($all_calls));
        }

        return array_filter($all_calls, fn($time) =>
            !(time() > strtotime($time['start']) && time() > strtotime($time['end']))
        );
    }

    public static function splitScheduledCourierCalls(array $calls): array
    {
        $split = [];
        foreach ($calls as $id => $time) {
            $split[$id] = [
                'id' => $id,
                'start_date' => date('Y-m-d', strtotime($time['start'])),
                'start_time' => date('H:i', strtotime($time['start'])),
                'end_date' => date('Y-m-d', strtotime($time['end'])),
                'end_time' => date('H:i', strtotime($time['end'])),
            ];
        }
        return $split;
    }

    public static function addScheduledCourierCall(string $id, string $start_time, string $end_time): void
    {
        $all_calls = self::getScheduledCourierCalls();
        $all_calls[$id] = ['start' => $start_time, 'end' => $end_time];
        Configuration::updateValue('omnivalt_courier_calls', json_encode($all_calls));
    }

    public static function removeScheduledCourierCall(string $id): bool
    {
        $all_calls = self::getScheduledCourierCalls();
        if (!isset($all_calls[$id])) {
            return false;
        }

        unset($all_calls[$id]);
        Configuration::updateValue('omnivalt_courier_calls', json_encode($all_calls));
        return true;
    }

    /*======================== Country helpers ========================*/

    /**
     * Get European countries list filtered by scope.
     *
     * Available scopes:
     *  - 'eu'              — EU countries only (27)
     *  - 'non_eu'          — All European countries except EU (EEA + partners + other)
     *  - 'eu_eea'          — EU + EEA countries (EU + IS, LI, NO)
     *  - 'eu_eea_partners' — EU + EEA + countries with strong economic ties (CH, GB)
     *  - 'non_eu_eea'      — European countries that are neither EU nor EEA (partners + other)
     *  - 'europe'          — All European countries (EU + EEA + partners + other)
     *
     * @param int $lang_id PrestaShop language ID for country name translation
     * @param string $scope Filter scope (default: 'eu')
     * @return array Associative array [ISO_CODE => Country name]
     */
    public static function getEuCountriesList(int $lang_id, string $scope = 'eu'): array
    {
        $eu_iso_codes = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        $eea_only_iso_codes = ['IS', 'LI', 'NO'];

        $partner_iso_codes = ['CH', 'GB'];

        $other_europe_iso_codes = [
            'AL', 'AD', 'BY', 'BA', 'GE', 'MD', 'MC', 'ME',
            'MK', 'RS', 'SM', 'TR', 'UA', 'VA', 'XK',
        ];

        switch ($scope) {
            case 'non_eu':
                $allowed = array_merge($eea_only_iso_codes, $partner_iso_codes, $other_europe_iso_codes);
                break;
            case 'eu_eea':
                $allowed = array_merge($eu_iso_codes, $eea_only_iso_codes);
                break;
            case 'eu_eea_partners':
                $allowed = array_merge($eu_iso_codes, $eea_only_iso_codes, $partner_iso_codes);
                break;
            case 'non_eu_eea':
                $allowed = array_merge($partner_iso_codes, $other_europe_iso_codes);
                break;
            case 'europe':
                $allowed = array_merge($eu_iso_codes, $eea_only_iso_codes, $partner_iso_codes, $other_europe_iso_codes);
                break;
            case 'eu':
            default:
                $allowed = $eu_iso_codes;
                break;
        }

        $countries_list = [];
        $all_countries = Country::getCountries($lang_id, false, false, false);

        foreach ($all_countries as $country) {
            if (!empty($country['iso_code']) && in_array(strtoupper($country['iso_code']), $allowed)) {
                $countries_list[strtoupper($country['iso_code'])] = $country['name'] ?? $country['country'] ?? $country['iso_code'];
            }
        }

        return $countries_list;
    }
}
