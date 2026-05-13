<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mijora\Omniva\OmnivaException;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;
use Mijora\Omniva\Shipment\Label;
use Mijora\Omniva\Shipment\Manifest;
use Mijora\Omniva\Shipment\Order as ApiOrder;
use Mijora\Omniva\Shipment\Tracking;
use Mijora\Omniva\Shipment\CallCourier;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;
use Mijora\Omniva\Shipment\Package\Measures;
use Mijora\Omniva\Shipment\Package\ServicePackage;
use Mijora\Omniva\PowerBi\OmnivaPowerBi;

class OmnivaApi
{
    const LABEL_COMMENT_TYPE_NONE = 0;
    const LABEL_COMMENT_TYPE_ORDER_ID = 1;
    const LABEL_COMMENT_TYPE_ORDER_REF = 2;

    protected string $username;
    protected string $password;

    protected array $methods_types = [
        'parcel' => ['omnivalt_pt', 'omnivalt_c'],
        'letter' => ['omnivalt_el'],
        'pallet' => [],
    ];

    protected array $methods_channels = [
        'terminal' => ['omnivalt_pt'],
        'courier' => ['omnivalt_c'],
        'post' => [],
        'postbox' => ['omnivalt_el'],
    ];

    public function __construct(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;
        $this->declareIntegrationAgent();
    }

    protected function declareIntegrationAgent(): void
    {
        if (!defined('_OMNIVA_INTEGRATION_AGENT_ID_')) {
            $version = Db::getInstance()->getValue(
                "SELECT version FROM " . _DB_PREFIX_ . "module WHERE name = 'omnivaltshipping'"
            ) ?: '0.0.0';
            define('_OMNIVA_INTEGRATION_AGENT_ID_', '7005511 Prestashop v' . $version);
        }
    }

    /*======================== Shipment Creation ========================*/

    public function createShipment(int $id_order): array
    {
        try {
            $orderObjs = OmnivaData::getOrderObjects($id_order);
            $omnivaObjs = OmnivaData::getOmnivaObjects($orderObjs->order);

            $country_iso = OmnivaData::getCountryIso($orderObjs->address);
            $id_terminal = $omnivaObjs->cart_terminal->id_terminal;
            $receiver_data = OmnivaData::getReceiverData($orderObjs->address, $orderObjs->customer);
            $sender_data = $this->getSenderData($orderObjs->order);
        } catch (\Exception $e) {
            return ['msg' => OmnivaHelper::buildExceptionMessage($e, 'Failed to get Order data')];
        }

        try {
            $shipment_codes = $this->getShipmentCodes($orderObjs->order->id_carrier);
            if (!$shipment_codes->main_service) {
                throw new OmnivaException('Failed to get shipment service');
            }
            if (!$shipment_codes->delivery_service) {
                throw new OmnivaException('Failed to get delivery service');
            }

            if (!self::isOmnivaMethodAllowed(['type' => $shipment_codes->type_key, 'channel' => $shipment_codes->channel_key], $country_iso)) {
                $countries_txt = ['LT' => 'Lithuania', 'LV' => 'Latvia', 'EE' => 'Estonia', 'FI' => 'Finland'];
                $sender_country_code = $sender_data->country;
                if (empty($sender_country_code)) {
                    throw new OmnivaException('The sender country is not specified in the module settings');
                }
                throw new OmnivaException(sprintf(
                    'Shipment type "%s" is not allowed to send via "%s" from %s to %s',
                    $shipment_codes->main_service,
                    $shipment_codes->delivery_service,
                    $countries_txt[$sender_country_code] ?? $sender_country_code,
                    $countries_txt[$country_iso] ?? $country_iso
                ));
            }

            $is_consolidated = ($omnivaObjs->order->packs > 1 && $omnivaObjs->order->cod);
            $pack_weight = $this->getPackageWeight($omnivaObjs->order);
            $terminals_type = OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key);
            $additional_services = self::getAdditionalServices($orderObjs->order);

            $api_shipment = new Shipment();
            $api_shipment->setComment($this->getLabelComment($orderObjs->order));

            $api_shipment_header = new ShipmentHeader();
            $api_shipment_header->setSenderCd($this->username)->setFileId(date('Ymdhis'));
            $api_shipment->setShipmentHeader($api_shipment_header);

            $packages = [];
            for ($i = 0; $i < $omnivaObjs->order->packs; $i++) {
                $package_id = (string) $id_order;
                if ($omnivaObjs->order->packs > 1 && !$is_consolidated) {
                    $package_id .= '_' . ($i + 1);
                }

                $api_package = new Package();
                $api_package->setId($package_id);
                $api_package->setService($shipment_codes->main_service, $shipment_codes->delivery_service);
                $api_package->setReturnAllowed($this->shouldSendReturnCode());

                // Additional services
                foreach ($additional_services as $service_code => $service) {
                    if ($i > 0 && $is_consolidated && $service_code !== 'fragile') {
                        continue;
                    }

                    $api_additional_service = new $service['class']();

                    if ($service_code === 'cod') {
                        $api_additional_service->setCodAmount($omnivaObjs->order->cod_amount);
                        $api_additional_service->setCodReceiver($sender_data->name);
                        $api_additional_service->setCodIban($sender_data->bank_account);
                        $api_additional_service->setCodReference($api_additional_service::calculateReferenceNumber($id_order));
                    }
                    if ($service_code === 'insurance') {
                        $api_additional_service->setInsuranceValue($orderObjs->order->total_products_wt);
                    }

                    $api_package->setAdditionalServiceOmx($api_additional_service);
                }

                // Measures
                if ($shipment_codes->type_key !== 'letter') {
                    $api_measures = new Measures();
                    $api_measures->setWeight($pack_weight);
                    $api_package->setMeasures($api_measures);
                }

                // Receiver
                $api_receiver_address = new Address();
                $api_receiver_address->setCountry($receiver_data->country);
                $api_receiver_address->setPostcode($receiver_data->postcode);
                $api_receiver_address->setDeliverypoint($receiver_data->city);
                $api_receiver_address->setStreet($receiver_data->street);
                if ($terminals_type) {
                    $api_receiver_address->setOffloadPostcode($id_terminal);
                }

                $api_receiver_contact = new Contact();
                $api_receiver_contact->setAddress($api_receiver_address);
                $api_receiver_contact->setPersonName($receiver_data->name);
                $api_receiver_contact->setPhone($receiver_data->phone);
                $api_receiver_contact->setMobile($receiver_data->mobile);
                if (Configuration::get('send_delivery_email')) {
                    $api_receiver_contact->setEmail($receiver_data->email);
                }

                $api_package->setReceiverContact($api_receiver_contact);
                $api_package->setSenderContact($this->getSenderContact($sender_data));

                // Service package
                if ($shipment_codes->type_key === 'parcel') {
                    if ($shipment_codes->channel_key === 'terminal' && $receiver_data->country === 'FI') {
                        $int_service_code = $this->getInternationalServiceCode('standard');
                        if ($int_service_code) {
                            $api_package->setServicePackage(new ServicePackage($int_service_code));
                        }
                    }
                }
                if ($shipment_codes->type_key === 'letter') {
                    $letter_service_code = $this->getLetterServiceCode($shipment_codes->delivery_service);
                    if ($letter_service_code) {
                        $api_package->setServicePackage(new ServicePackage($letter_service_code));
                    }
                }

                $packages[] = $api_package;
            }

            if (empty($packages)) {
                throw new OmnivaException('Failed to get packages');
            }

            $api_shipment->setPackages($packages);
            $this->setAuth($api_shipment);

            return $api_shipment->registerShipment(false);
        } catch (OmnivaException $e) {
            return ['msg' => $e->getMessage()];
        }
    }

    /*======================== Labels & Manifests ========================*/

    public function getOrderLabels(array $tracking_numbers): void
    {
        $print_type_bool = (Configuration::get('omnivalt_print_type') === 'four');

        $api_label = new Label();
        $this->setAuth($api_label);
        $api_label->downloadLabels($tracking_numbers, $print_type_bool, 'D', 'Omniva_labels_' . date('Ymd_His'));
    }

    public function getBulkLabels(array $order_ids, array $history_ids = []): void
    {
        $tracking_numbers = [];
        foreach ($order_ids as $id_order) {
            $omnivaOrder = new OmnivaOrder((int) $id_order);
            if (Validate::isLoadedObject($omnivaOrder) && $omnivaOrder->tracking_numbers) {
                $tracking_numbers = array_merge($tracking_numbers, json_decode($omnivaOrder->tracking_numbers, true));
            }
        }

        foreach ($history_ids as $hist_id) {
            $history = new OmnivaOrderHistory((int) $hist_id);
            if (Validate::isLoadedObject($history) && $history->tracking_numbers) {
                $tracking_numbers = array_merge($tracking_numbers, json_decode($history->tracking_numbers, true));
            }
        }

        if (empty($tracking_numbers)) {
            throw new OmnivaException('Failed to get tracking numbers');
        }

        $this->getOrderLabels(array_unique($tracking_numbers));
    }

    public function getManifest($orders_ids = false, array $extra_history_ids = []): void
    {
        $omnivaOrderHistoryIds = [];
        // Track which orders were selected via main row checkbox (= move all their histories)
        $wholeOrderIds = [];
        if (empty($orders_ids) && empty($extra_history_ids)) {
            $omnivaOrderHistoryIds = OmnivaOrderHistory::getManifestOrders();
        } else {
            if (!empty($orders_ids)) {
                foreach ($orders_ids as $id_order) {
                    if (empty($id_order)) {
                        continue;
                    }
                    $history = OmnivaOrderHistory::getLatestOrderHistory((int) $id_order);
                    if ($history && Validate::isLoadedObject($history) && !empty($history->tracking_numbers)) {
                        $wholeOrderIds[] = (int) $id_order;
                        $omnivaOrderHistoryIds[] = $history->id;
                    }
                }
            }
            foreach ($extra_history_ids as $hist_id) {
                $h = new OmnivaOrderHistory((int) $hist_id);
                if (Validate::isLoadedObject($h) && !empty($h->tracking_numbers) && !in_array($hist_id, $omnivaOrderHistoryIds)) {
                    $omnivaOrderHistoryIds[] = (int) $hist_id;
                }
            }
        }

        if (empty($omnivaOrderHistoryIds)) {
            header('Content-Type: application/json');
            die(json_encode(['error' => 'No orders with registered labels were found. Manifest was not generated.']));
        }

        $manifest_orders = $this->getManifestOrders($omnivaOrderHistoryIds);

        $api_manifest = new Manifest();
        $api_manifest->setSender($this->getSenderContact($this->getSenderData()));
        $api_manifest->showBarcode(false);

        foreach ($manifest_orders as $order) {
            $api_manifest->addOrder($order);
        }

        // Assign manifest number to selected histories
        $manifestNum = (int) Configuration::get('omnivalt_manifest');
        $manifestUsed = false;

        if (empty($orders_ids) && empty($extra_history_ids)) {
            // "Generate manifest (all)" — assign manifest number to all histories with manifest=0 that have tracking
            $updated = Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'omniva_order_history`
                SET `manifest` = ' . (int) $manifestNum . '
                WHERE `manifest` = 0
                    AND `tracking_numbers` IS NOT NULL
                    AND `tracking_numbers` != ""'
            );
            if ($updated && Db::getInstance()->Affected_Rows() > 0) {
                $manifestUsed = true;
            }
            // Archive any remaining manifest=0 entries (without tracking) for orders whose latest was manifested
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'omniva_order_history` ooh
                SET ooh.`manifest` = -2
                WHERE ooh.`manifest` = 0
                    AND EXISTS (
                        SELECT 1 FROM (
                            SELECT `id_order` FROM `' . _DB_PREFIX_ . 'omniva_order_history`
                            WHERE `manifest` = ' . (int) $manifestNum . '
                        ) AS manifested
                        WHERE manifested.`id_order` = ooh.`id_order`
                    )'
            );
        } else {
            // Orders selected via main row checkbox — move only the latest history (the one shown in the main row)
            foreach ($wholeOrderIds as $id_order) {
                $history = OmnivaOrderHistory::getLatestOrderHistory((int) $id_order);
                if ($history && Validate::isLoadedObject($history) && (int) $history->manifest === 0) {
                    $history->manifest = $manifestNum;
                    $history->update();
                    $manifestUsed = true;
                }
            }

            // History entries selected individually — move only those specific entries
            foreach ($extra_history_ids as $hist_id) {
                $h = new OmnivaOrderHistory((int) $hist_id);
                if (Validate::isLoadedObject($h) && (int) $h->manifest === 0) {
                    $h->manifest = $manifestNum;
                    $h->update();
                    $manifestUsed = true;
                }
            }

            // If the latest history of an order was manifested, archive all remaining manifest=0 histories
            // (they are considered obsolete since the user chose the newest label)
            $allAffectedOrderIds = array_unique(array_merge($wholeOrderIds, array_map(function ($hist_id) {
                $h = new OmnivaOrderHistory((int) $hist_id);
                return Validate::isLoadedObject($h) ? (int) $h->id_order : 0;
            }, $extra_history_ids)));

            foreach ($allAffectedOrderIds as $id_order) {
                if (!$id_order) {
                    continue;
                }
                $latest = OmnivaOrderHistory::getLatestOrderHistory((int) $id_order);
                if ($latest && (int) $latest->manifest > 0) {
                    // Latest was manifested — archive older entries still in New
                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'omniva_order_history`
                        SET `manifest` = -2
                        WHERE `id_order` = ' . (int) $id_order . '
                            AND `manifest` = 0'
                    );
                }
            }
        }

        if ($manifestUsed) {
            Configuration::updateValue('omnivalt_manifest', $manifestNum + 1);
        }
        $api_manifest->downloadManifest('D', 'Omniva_manifest_' . date('Ymd_His'));
    }

    /**
     * Generate manifest PDF for given orders/histories without moving them to Completed.
     */
    public function downloadManifestOnly(array $order_ids, array $history_ids): void
    {
        $omnivaOrderHistoryIds = [];

        foreach ($order_ids as $id_order) {
            if (empty($id_order)) {
                continue;
            }
            $history = OmnivaOrderHistory::getLatestOrderHistory((int) $id_order);
            if ($history && Validate::isLoadedObject($history)) {
                $omnivaOrderHistoryIds[] = $history->id;
            }
        }

        foreach ($history_ids as $hist_id) {
            if ($hist_id && !in_array($hist_id, $omnivaOrderHistoryIds)) {
                $omnivaOrderHistoryIds[] = (int) $hist_id;
            }
        }

        $manifest_orders = $this->getManifestOrders($omnivaOrderHistoryIds);

        $api_manifest = new Manifest();
        $api_manifest->setSender($this->getSenderContact($this->getSenderData()));
        $api_manifest->showBarcode(false);

        foreach ($manifest_orders as $order) {
            $api_manifest->addOrder($order);
        }

        $api_manifest->downloadManifest('D', 'Omniva_manifest_' . date('Ymd_His'));
    }

    public function downloadManifestByNumber(int $manifestNum): void
    {
        $sql = 'SELECT `id` FROM `' . _DB_PREFIX_ . 'omniva_order_history`
            WHERE `manifest` = ' . (int) $manifestNum . '
                AND `tracking_numbers` IS NOT NULL
                AND `tracking_numbers` != ""';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        if (!$rows) {
            header('Content-Type: application/json');
            die(json_encode(['error' => 'No orders found for this manifest.']));
        }

        $historyIds = array_column($rows, 'id');
        $manifest_orders = $this->getManifestOrders(array_map('intval', $historyIds));

        $api_manifest = new Manifest();
        $api_manifest->setSender($this->getSenderContact($this->getSenderData()));
        $api_manifest->showBarcode(false);

        foreach ($manifest_orders as $order) {
            $api_manifest->addOrder($order);
        }

        $api_manifest->downloadManifest('D', 'Omniva_manifest_' . $manifestNum);
    }

    /*======================== Tracking ========================*/

    public function getTracking(array $tracking_numbers): array
    {
        try {
            $tracking = new Tracking();
            $this->setAuth($tracking);

            $tracking_data = [];
            foreach ($tracking_numbers as $barcode) {
                $tracking_data[$barcode] = $tracking->getTrackingOmx($barcode);
            }
            return $tracking_data;
        } catch (OmnivaException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /*======================== Courier Calls ========================*/

    public function callCarrier(): array
    {
        $pickup_start = Configuration::get('omnivalt_pick_up_time_start') ?: '8:00';
        $pickup_end = Configuration::get('omnivalt_pick_up_time_finish') ?: '17:00';

        try {
            $api_call = new CallCourier();
            $this->setAuth($api_call);
            $api_call->setSender($this->getSenderContact($this->getSenderData()));
            $api_call->setEarliestPickupTime($pickup_start);
            $api_call->setLatestPickupTime($pickup_end);
            $api_call->setTimezone('Europe/Tallinn');

            $result = $api_call->callCourier();
            if (!$result) {
                return ['error' => 'Failed to call courier'];
            }

            $result_data = $api_call->getResponseBody();
            $call_data = [
                'id' => $result_data['courierOrderNumber'],
                'start' => date('Y-m-d H:i', strtotime($result_data['startTime'] . ' UTC')),
                'end' => date('Y-m-d H:i', strtotime($result_data['endTime'] . ' UTC')),
            ];

            // Check if this call ID already exists
            $existing_calls = OmnivaHelper::getScheduledCourierCalls();
            if (isset($existing_calls[$call_data['id']])) {
                return [
                    'warning' => 'A courier call for the same date and time is already registered (ID: ' . $call_data['id'] . '). No new call was created.',
                ];
            }

            OmnivaHelper::addScheduledCourierCall($call_data['id'], $call_data['start'], $call_data['end']);

            return [
                'status' => true,
                'call_id' => $call_data['id'],
                'start_time' => $call_data['start'],
                'end_time' => $call_data['end'],
            ];
        } catch (OmnivaException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function cancelCarrier(string $call_id): array
    {
        try {
            $api_call = new CallCourier();
            $this->setAuth($api_call);

            $result = $api_call->cancelCourierOmx($call_id);
            if (!$result) {
                return ['error' => 'Failed to cancel the courier'];
            }
            return ['status' => true, 'call_id' => $call_id];
        } catch (OmnivaException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /*======================== Statistics ========================*/

    public function sendStatistics(array $shipments_data, bool $test_mode = false): bool
    {
        if (empty($shipments_data)) {
            return false;
        }

        $prepared_prices = [];
        if (isset($shipments_data['shipping_prices'])) {
            foreach ($shipments_data['shipping_prices'] as $country => $country_methods) {
                if (!in_array($country, ['LT', 'LV', 'EE', 'FI'])) {
                    continue;
                }
                $preparing_prices = [];
                foreach ($country_methods as $method => $method_data) {
                    if (!$method_data['enabled']) {
                        continue;
                    }
                    $price_values = ['min' => null, 'max' => null];
                    if (is_array($method_data['prices'])) {
                        $prices_only = array_column($method_data['prices'], 'price');
                        $price_values['min'] = min($prices_only);
                        $price_values['max'] = max($prices_only);
                    } else {
                        $price_values['min'] = $method_data['prices'];
                    }
                    $preparing_prices[] = ['method' => $method, 'prices' => $price_values];
                }
                $prepared_prices[$country] = ['courier' => null, 'terminal' => null];
                foreach ($preparing_prices as $price) {
                    if (array_key_exists($price['method'], $prepared_prices[$country])) {
                        $prepared_prices[$country][$price['method']] = $price['prices'];
                    }
                }
            }
        }

        try {
            $powerbi = new OmnivaPowerBi($this->username, $test_mode);
            $powerbi
                ->setPluginVersion($shipments_data['module_version'])
                ->setPlatform('Prestashop v' . $shipments_data['platform_version'])
                ->setSenderName($shipments_data['client_name'])
                ->setSenderCountry($shipments_data['client_country'])
                ->setDateTimeStamp($shipments_data['track_since'])
                ->setOrderCountCourier($shipments_data['total_orders']['courier'] ?? 0)
                ->setOrderCountTerminal($shipments_data['total_orders']['terminal'] ?? 0);

            foreach ($prepared_prices as $country => $prices) {
                if ($prices['courier'] !== null) {
                    $powerbi->setCourierPrice($country, $prices['courier']['min'], $prices['courier']['max']);
                }
                if ($prices['terminal'] !== null) {
                    $powerbi->setTerminalPrice($country, $prices['terminal']['min'], $prices['terminal']['max']);
                }
            }

            OmnivaHelper::printToLog("Sending data to PowerBi:\n" . print_r($powerbi, true), 'powerbi');
            $result = $powerbi->send();
            if ($result) {
                OmnivaHelper::printToLog('Data sent successfully.', 'powerbi');
                return true;
            }
            OmnivaHelper::printToLog('Failed to send data.', 'powerbi');
        } catch (OmnivaException $e) {
            OmnivaHelper::printToLog('Error while sending statistics data.', 'powerbi');
        }

        return false;
    }

    /*======================== Helpers ========================*/

    public static function getTrackingUrl(string $country_iso): string
    {
        $urls = [
            'LT' => 'https://mano.omniva.lt/track/',
            'LV' => 'https://mana.omniva.lv/track/',
            'EE' => 'https://minu.omniva.ee/track/',
            'FI' => 'https://minu.omniva.ee/track/',
        ];
        return $urls[strtoupper($country_iso)] ?? $urls['LT'];
    }

    public static function getAdditionalServices(Order $order): array
    {
        $services = [];
        $all_services = OmnivaApiServices::getAdditionalServices();
        $omnivaOrder = new OmnivaOrder($order->id);

        if ($omnivaOrder->cod && isset($all_services['cod'])) {
            $services['cod'] = $all_services['cod'];
        }

        foreach ($order->getProducts() as $order_product) {
            $product_id = (int) $order_product['product_id'];

            if (OmnivaProduct::get18PlusStatus($product_id) && isset($all_services['persons_over_18'])) {
                $services['over_18'] = $all_services['persons_over_18'];
            }
            if (OmnivaProduct::getFragileStatus($product_id) && isset($all_services['fragile'])) {
                $services['fragile'] = $all_services['fragile'];
            }
        }

        return $services;
    }

    public function getShipmentCodes(int $id_carrier): object
    {
        $type_key = $this->getShipmentTypeKey($id_carrier);
        $channel_key = $this->getShipmentChannelKey($id_carrier);

        return (object) [
            'type_key' => $type_key,
            'main_service' => $this->getShipmentTypeCode($type_key),
            'channel_key' => $channel_key,
            'delivery_service' => $this->getShipmentChannelCode($channel_key),
            '_exists' => (!empty($this->getShipmentTypeCode($type_key)) && !empty($this->getShipmentChannelCode($channel_key))),
        ];
    }

    protected function getSenderData(?Order $order = null): object
    {
        if ($order) {
            $idShop = (int) $order->id_shop;
            $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        } else {
            $context = Context::getContext();
            $idShop = (int) $context->shop->id;
            $idShopGroup = (int) $context->shop->id_shop_group;
        }

        return (object) [
            'name' => Configuration::get('omnivalt_company', null, $idShopGroup, $idShop),
            'address' => Configuration::get('omnivalt_address', null, $idShopGroup, $idShop),
            'city' => Configuration::get('omnivalt_city', null, $idShopGroup, $idShop),
            'postcode' => Configuration::get('omnivalt_postcode', null, $idShopGroup, $idShop),
            'country' => Configuration::get('omnivalt_countrycode', null, $idShopGroup, $idShop),
            'phone' => Configuration::get('omnivalt_phone', null, $idShopGroup, $idShop),
            'bank_account' => Configuration::get('omnivalt_bank_account', null, $idShopGroup, $idShop),
        ];
    }

    protected function getSenderContact(object $sender_data): Contact
    {
        $address = new Address();
        $address->setCountry($sender_data->country);
        $address->setPostcode($sender_data->postcode);
        $address->setDeliverypoint($sender_data->city);
        $address->setStreet($sender_data->address);

        $contact = new Contact();
        $contact->setAddress($address);
        $contact->setPersonName($sender_data->name);
        $contact->setMobile($sender_data->phone);

        return $contact;
    }

    protected function getInternationalServiceCode(string $service_key)
    {
        $codes = OmnivaApiServices::getInternationalServiceCodes();
        return $codes[$service_key] ?? false;
    }

    protected function getLetterServiceCode(string $channel_code)
    {
        $letter_codes = OmnivaApiServices::getLetterServiceCodes();
        $channels = OmnivaApiServices::getChannels();

        if (isset($channels['postbox']) && $channel_code === $channels['postbox']) {
            return $letter_codes['express'] ?? false;
        }

        return $letter_codes['registered'] ?? false;
    }

    protected function setAuth(object $object): void
    {
        if (method_exists($object, 'setAuth')) {
            $object->setAuth($this->username, $this->password);
        }
    }

    protected function getLabelComment(Order $order): string
    {
        $type = (int) Configuration::get('omnivalt_label_comment_type');

        switch ($type) {
            case self::LABEL_COMMENT_TYPE_ORDER_ID:
                return 'Order ID: ' . $order->id;
            case self::LABEL_COMMENT_TYPE_ORDER_REF:
                return 'Order Ref: ' . $order->getUniqReference();
            default:
                return '';
        }
    }

    protected function shouldSendReturnCode(): bool
    {
        return (bool) Configuration::get('omnivalt_send_return');
    }

    private function getShipmentTypeKey(int $id_carrier)
    {
        foreach ($this->methods_types as $type => $methods) {
            if (empty($methods)) {
                continue;
            }
            $carrier_ids = OmnivaltShipping::getCarrierIds($methods);
            if (in_array($id_carrier, $carrier_ids, true)) {
                return $type;
            }
        }
        return false;
    }

    protected function getShipmentTypeCode($type_key)
    {
        if (!$type_key) {
            return false;
        }
        $types = OmnivaApiServices::getShipmentTypes();
        return $types[$type_key] ?? false;
    }

    private function getShipmentChannelKey(int $id_carrier)
    {
        foreach ($this->methods_channels as $channel => $methods) {
            if (empty($methods)) {
                continue;
            }
            $carrier_ids = OmnivaltShipping::getCarrierIds($methods);
            if (in_array($id_carrier, $carrier_ids, true)) {
                return $channel;
            }
        }
        return false;
    }

    protected function getShipmentChannelCode($channel_key)
    {
        if (!$channel_key) {
            return false;
        }
        $channels = OmnivaApiServices::getChannels();
        return $channels[$channel_key] ?? false;
    }

    public static function isOmnivaMethodAllowed(array $keys, string $receiver_country): bool
    {
        $type_key = $keys['type'] ?? false;
        $channel_key = $keys['channel'] ?? false;
        if (!$type_key && !$channel_key) {
            return false;
        }

        $receiver_country = strtoupper($receiver_country);
        if (!in_array($receiver_country, ['LT', 'LV', 'EE', 'FI'])) {
            return false;
        }

        $api_country = strtoupper(Configuration::get('omnivalt_api_country') ?: '');
        $sender_country = strtoupper(Configuration::get('omnivalt_countrycode') ?: '');
        if (empty($api_country) || empty($sender_country)) {
            return false;
        }

        if ($api_country === 'EE' && $receiver_country === 'EE' && !Configuration::get('omnivalt_ee_service')) {
            return false;
        }
        if ($api_country === 'EE' && $receiver_country === 'FI' && !Configuration::get('omnivalt_fi_service')) {
            return false;
        }

        if ($type_key === 'parcel' && $channel_key === 'terminal') {
            if ($api_country !== 'EE' && $receiver_country === 'FI') {
                return false;
            }
        } elseif ($receiver_country === 'FI') {
            return false;
        }

        if ($type_key === 'letter') {
            if ($api_country !== 'EE' || $sender_country !== 'EE' || $receiver_country !== 'EE') {
                return false;
            }
        }

        return true;
    }

    protected function getPackageWeight(OmnivaOrder $omnivaOrder): float
    {
        $weight = (float) $omnivaOrder->weight;
        if ($weight <= 0) {
            $weight = 1.0;
        }
        if ((int) $omnivaOrder->packs > 0) {
            $weight = round($weight / (int) $omnivaOrder->packs, 2);
        }
        return $weight;
    }

    protected function getManifestOrders(array $historyIds): array
    {
        $manifest_orders = [];
        foreach ($historyIds as $history_id) {
            $history = new OmnivaOrderHistory((int) $history_id);
            if (!Validate::isLoadedObject($history) || empty($history->tracking_numbers)) {
                continue;
            }

            $barcodes = json_decode($history->tracking_numbers, true);
            if (!is_array($barcodes)) {
                continue;
            }

            $orderObjs = OmnivaData::getOrderObjects($history->id_order);
            $receiver_data = OmnivaData::getReceiverData($orderObjs->address, $orderObjs->customer);

            // Build receiver address string
            $isTerminal = OmnivaCarrier::isOmnivaTerminalCarrier($orderObjs->order->id_carrier);
            if ($isTerminal) {
                $omnivaObjs = OmnivaData::getOmnivaObjects($orderObjs->order);
                $terminalAddress = OmnivaLocations::getTerminalAddress($omnivaObjs->cart_terminal->id_terminal ?? '');
                $receiverStr = $receiver_data->name . ', ' . ($terminalAddress ?: $receiver_data->street . ', ' . $receiver_data->city);
            } else {
                $receiverStr = $receiver_data->name . ', ' . $receiver_data->street . ', ' . $receiver_data->city . ', ' . $receiver_data->postcode;
            }

            foreach ($barcodes as $barcode) {
                $api_order = new ApiOrder();
                $api_order->setTracking($barcode);
                $api_order->setQuantity(1);
                $api_order->setWeight((float) ($orderObjs->order->getTotalWeight() ?: 1));
                $api_order->setReceiver($receiverStr);
                $api_order->setOrderNumber((string) $history->id_order);

                $manifest_orders[] = $api_order;
            }
        }

        return $manifest_orders;
    }

    protected static function getOrderProductsData(int $psOrderId): array
    {
        $order = new Order($psOrderId);
        if (!Validate::isLoadedObject($order)) {
            return [];
        }

        $products_data = [];
        foreach ($order->getProducts() as $product) {
            $products_data[] = [
                'name' => $product['product_name'] ?? '',
                'quantity' => $product['product_quantity'] ?? 1,
                'weight' => $product['product_weight'] ?? 0,
                'price' => $product['product_price'] ?? 0,
            ];
        }

        return $products_data;
    }
}
