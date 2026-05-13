<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/OmnivaDb.php';
require_once __DIR__ . '/classes/OmnivaCartTerminal.php';
require_once __DIR__ . '/classes/OmnivaOrder.php';
require_once __DIR__ . '/classes/OmnivaOrderHistory.php';
require_once __DIR__ . '/classes/OmnivaProduct.php';
require_once __DIR__ . '/classes/OmnivaCarrier.php';
require_once __DIR__ . '/classes/OmnivaHelper.php';
require_once __DIR__ . '/classes/OmnivaLocations.php';
require_once __DIR__ . '/classes/OmnivaData.php';
require_once __DIR__ . '/classes/OmnivaApi.php';
require_once __DIR__ . '/classes/OmnivaApiInternational.php';
require_once __DIR__ . '/classes/OmnivaApiServices.php';

require_once __DIR__ . '/vendor/autoload.php';

class OmnivaltShipping extends CarrierModule
{
    /** @var OmnivaHelper */
    public $helper;
    /** @var OmnivaApiInternational */
    public $api;
    /** @var bool */
    private bool $_servicesInitialized = false;

    const CONTROLLER_OMNIVA_AJAX = 'AdminOmnivaAjax';
    const CONTROLLER_OMNIVA_ORDERS = 'AdminOmnivaOrders';

    const UPDATE_URL = 'https://api.github.com/repos/mijora/omniva-prestashop/releases/latest';
    const DOWNLOAD_URL = 'https://github.com/mijora/omniva-prestashop/releases/latest/download/omnivaltshipping.zip';

    const SHIPPING_SETS = [
        'baltic' => [
            'pt pt' => 'PA',
            'pt c' => 'PK',
            'c pt' => 'PU',
            'c c' => 'QH',
            'courier_call' => 'QH',
        ],
        'estonia' => [
            'pt pt' => 'PA',
            'pt po' => 'PO',
            'pt c' => 'PK',
            'c pt' => 'PU',
            'c c' => 'CI',
            'c cp' => 'LX',
            'po cp' => 'LH',
            'po pt' => 'PV',
            'po po' => 'CD',
            'po c' => 'CE',
            'lc pt' => 'PP',
            'courier_call' => 'CI',
        ],
        'finland' => [
            'c pc' => 'QB',
            'c po' => 'CD',
            'c cp' => 'CE',
            'c pt' => 'CD',
            'pt pt' => 'CD',
            'courier_call' => 'CE',
        ],
    ];

    protected array $_hooks = [
        'actionCarrierUpdate',
        'displayAdminProductsExtra',
        'actionProductUpdate',
        'displayHeader',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
        'actionValidateOrder',
        'actionObjectOrderUpdateAfter',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'actionEmailSendBefore',
        'displayCarrierExtraContent',
    ];

    public static array $_codModules = ['ps_cashondelivery', 'venipakcod', 'codpro'];

    public $id_carrier;
    private static array $_omniva_cache = [];

    public function __construct()
    {
        $this->name = 'omnivaltshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '3.0.0';
        $this->author = 'Mijora';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Omniva Shipping', [], 'Modules.Omnivaltshipping.Admin');
        $this->description = $this->trans('Shipping module for Omniva carrier', [], 'Modules.Omnivaltshipping.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Omnivaltshipping.Admin');

        if (!$this->isRegisteredInHook('displayHeader')) {
            // Module not yet installed, skip runtime logic
            return;
        }

        if (!Configuration::get('omnivalt_api_user')) {
            $this->warning = $this->trans('Please set up module', [], 'Modules.Omnivaltshipping.Admin');
        }

        $this->initServices();
    }

    private function initServices(): void
    {
        if ($this->_servicesInitialized) {
            return;
        }
        $this->_servicesInitialized = true;

        $this->helper = new OmnivaHelper();
        $this->api = new OmnivaApiInternational(
            Configuration::get('omnivalt_api_user') ?: '',
            Configuration::get('omnivalt_api_pass') ?: ''
        );
    }

    private function runDeferredUpdates(): void
    {
        $this->updateTerminalsIfNeeded();
        $this->sendStatisticsIfNeeded();
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /*======================== Install / Uninstall ========================*/

    public function install(): bool
    {
        if (version_compare(_PS_VERSION_, '8.0.0', '<')) {
            $this->_errors[] = $this->trans(
                'This version of the module requires PrestaShop 8.0.0 or later. Your current version is %ps_version%.',
                ['%ps_version%' => _PS_VERSION_],
                'Modules.Omnivaltshipping.Admin'
            );
            return false;
        }

        if (!parent::install()) {
            return false;
        }

        foreach ($this->_hooks as $hook) {
            if (!$this->registerHook($hook)) {
                $this->_errors[] = sprintf('Failed to register hook: %s', $hook);
                $this->uninstall();
                return false;
            }
        }

        if (!$this->createDbTables()) {
            $this->_errors[] = $this->trans('Failed to create tables.', [], 'Modules.Omnivaltshipping.Admin');
            $this->uninstall();
            return false;
        }

        if (!$this->registerTabs()) {
            $this->_errors[] = $this->trans('Failed to register tabs.', [], 'Modules.Omnivaltshipping.Admin');
            $this->uninstall();
            return false;
        }

        Configuration::updateValue('omnivalt_manifest', 1);
        $this->getCustomOrderState();
        $this->getErrorOrderState();

        return true;
    }

    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        $this->deleteTabs();

        if (Configuration::get('omnivalt_uninstall_tables')) {
            (new OmnivaDb())->deleteTables();
        }

        if (Configuration::get('omnivalt_uninstall_carriers')) {
            $this->deleteCarriers();
        }

        Configuration::deleteByName('omnivalt_uninstall_tables');
        Configuration::deleteByName('omnivalt_uninstall_carriers');

        return true;
    }

    /*======================== Carrier Module Methods ========================*/

    public function getOrderShippingCost($params, $shipping_cost)
    {
        $carrier = self::$_omniva_cache[(int) $this->id_carrier]
            ?? (self::$_omniva_cache[(int) $this->id_carrier] = new Carrier((int) $this->id_carrier));

        $omniva_references = [];
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $omniva_references[$key] = OmnivaCarrier::getReference($key);
        }

        if (!isset($this->context->cart->id_address_delivery)) {
            return $shipping_cost;
        }

        $address = new Address($this->context->cart->id_address_delivery);
        $shipment_codes = $this->api->getShipmentCodes((int) $carrier->id);
        $shipment_keys = [
            'type' => $shipment_codes->type_key,
            'channel' => $shipment_codes->channel_key,
        ];

        $default_iso_code = Configuration::get('omnivalt_default_receiver_countrycode')
            ?: $this->context->language->iso_code;
        $iso_code = $address->id_country
            ? strtoupper(Country::getIsoById($address->id_country))
            : strtoupper($default_iso_code);

        if ((int) $carrier->id_reference === $omniva_references['omnivalt_pt']) {
            $terminals_type = OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key);
            if (!$terminals_type || !OmnivaApiServices::haveTerminals($iso_code)) {
                return false;
            }

            if ((float) $carrier->max_depth > 0 && (float) $carrier->max_width > 0 && (float) $carrier->max_height > 0) {
                $products = OmnivaHelper::getCartItems($params->getProducts(false, false), true);
                $cart_size = OmnivaHelper::predictOrderSize($products, [
                    'length' => (float) $carrier->max_depth,
                    'width' => (float) $carrier->max_width,
                    'height' => (float) $carrier->max_height,
                ]);
                if (!$cart_size) {
                    return false;
                }
            }
        } elseif (in_array((int) $carrier->id_reference, $omniva_references)) {
            $method_key = array_search((int) $carrier->id_reference, $omniva_references);
            $shipment_keys['method'] = $method_key;
            if (!OmnivaApiInternational::isOmnivaMethodAllowed($shipment_keys, $iso_code)) {
                return false;
            }
            if (OmnivaApiInternational::isInternationalMethod($method_key)) {
                $package_key = OmnivaApiInternational::getPackageKeyFromMethodKey($method_key);
                $products = OmnivaHelper::getCartItems($params->getProducts(false, false), true);
                if (!OmnivaApiInternational::isPackageAvailableForItems($package_key, $iso_code, $products)) {
                    return false;
                }
            }
        }

        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    /*======================== Hooks ========================*/

    public function hookActionCarrierUpdate(array $params): void
    {
        $id_carrier_old = (int) $params['id_carrier'];
        $id_carrier_new = (int) $params['carrier']->id;

        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            if ($id_carrier_old == OmnivaCarrier::getId($key)) {
                OmnivaCarrier::updateMappingValues($key, $id_carrier_new);
            }
        }
    }

    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $id_product = (int) $params['id_product'];

        $this->context->smarty->assign([
            'is18Plus' => OmnivaProduct::get18PlusStatus($id_product),
            'isFragile' => OmnivaProduct::getFragileStatus($id_product),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/productTab.tpl');
    }

    public function hookActionProductUpdate(array $params): void
    {
        $productID = (int) $params['id_product'];
        $is18Plus = (bool) Tools::getValue('omnivaltshipping_is_18_plus');
        $isFragile = (bool) Tools::getValue('omnivaltshipping_is_fragile');

        OmnivaProduct::saveProductSettings($productID, $is18Plus, $isFragile);
    }

    public function hookDisplayHeader(array $params): void
    {
        $this->runDeferredUpdates();

        $allowed_pages = ['order-opc', 'order'];
        if (!in_array($this->context->controller->php_self, $allowed_pages)) {
            return;
        }

        Media::addJsDef([
            'omnivalt_params' => [
                'url' => [
                    'plugin' => Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/',
                    'images' => Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/views/img/',
                    'controller_ajax' => $this->context->link->getModuleLink('omnivaltshipping', 'ajax'),
                ],
                'token' => Tools::getToken(false),
                'methods' => [
                    'omniva_terminal' => OmnivaCarrier::getId('omnivalt_pt'),
                ],
                'show_map' => (bool) Configuration::get('omnivalt_map'),
            ],
            'omnivalt_text' => [
                'select_terminal' => $this->trans('Select terminal', [], 'Modules.Omnivaltshipping.Shop'),
                'select_terminal_desc' => $this->trans('Please select a parcel terminal', [], 'Modules.Omnivaltshipping.Shop'),
                'select_terminal_error' => $this->trans('Please select parcel terminal', [], 'Modules.Omnivaltshipping.Shop'),
                'search_placeholder' => $this->trans('Enter postcode', [], 'Modules.Omnivaltshipping.Shop'),
                'search_desc' => $this->trans('Enter an address, if you want to find terminals', [], 'Modules.Omnivaltshipping.Shop'),
                'not_found' => $this->trans('Place not found', [], 'Modules.Omnivaltshipping.Shop'),
                'enter_address' => $this->trans('Enter postcode/address', [], 'Modules.Omnivaltshipping.Shop'),
                'show_in_map' => $this->trans('Show in map', [], 'Modules.Omnivaltshipping.Shop'),
                'show_more' => $this->trans('Show more', [], 'Modules.Omnivaltshipping.Shop'),
                'variables' => [
                    'omniva' => ['modal_title' => $this->trans('Omniva parcel terminals', [], 'Modules.Omnivaltshipping.Shop')],
                    'matkahuolto' => ['modal_title' => $this->trans('Matkahuolto parcel terminals', [], 'Modules.Omnivaltshipping.Shop')],
                ],
            ],
        ]);

        $this->context->controller->registerJavascript(
            'leaflet',
            'modules/' . $this->name . '/views/js/leaflet.js',
            ['priority' => 190]
        );
        $this->context->controller->registerJavascript(
            'omnivalt',
            'modules/' . $this->name . '/views/js/omniva.js',
            ['priority' => 200]
        );
        $this->context->controller->addCSS($this->_path . 'views/css/leaflet.css');
        $this->context->controller->addCSS($this->_path . 'views/css/omniva.css');
    }

    public function hookActionAdminControllerSetMedia(): void
    {
        $this->runDeferredUpdates();

        $controller = $this->context->controller;
        $isModuleConfig = Tools::getValue('configure') === $this->name;

        // Bulk actions JS — load on all admin pages (JS self-guards with #order_grid check)
        Media::addJsDef([
            'omnivalt_bulk_labels' => $this->trans('Omniva: Print labels', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_bulk_manifests' => $this->trans('Omniva: Print manifests', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_admin_action_labels' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=bulkPrintLabels',
            'omnivalt_admin_action_manifests' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=printManifest',
        ]);
        $controller->addJS($this->_path . 'views/js/adminOmnivalt.js');

        // Module config page specific
        if ($isModuleConfig) {
            return;
        }
    }

    public function hookDisplayCarrierExtraContent(array $params): string
    {
        $carrier_id = $this->getCarrierIdFromParams($params);
        if (!$carrier_id) {
            return '';
        }

        $selected = '';
        if (isset($params['cart']->id)) {
            $omnivaCart = new OmnivaCartTerminal($params['cart']->id);
            $selected = (string) ($omnivaCart->id_terminal ?? '');
        }

        $iso_code = $this->getCartCountryCode($params['cart']);

        // Check if this carrier requires terminal selection
        $shipment_codes = $this->api->getShipmentCodes($carrier_id);
        if (!$shipment_codes->_exists || !OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key)) {
            return '';
        }

        $terminals = $this->getTerminalsList($carrier_id, $iso_code);
        if (empty($terminals)) {
            return $this->display(__FILE__, 'displayCarrierExtraContentError.tpl');
        }

        $marker_img = ($iso_code === 'FI') ? 'sasi_mh.svg' : 'sasi.png';
        $showMap = Configuration::get('omnivalt_map');

        $postcode = '';
        if (!empty($params['cart']->id_address_delivery)) {
            $address = new Address($params['cart']->id_address_delivery);
            if (Validate::isLoadedObject($address)) {
                $postcode = $address->postcode;
            }
        }

        $this->context->smarty->assign([
            'module_url' => $this->_path,
            'parcel_terminals' => $this->getTerminalsOptions($terminals, $selected),
            'terminals_list' => $this->getTerminalForMap($terminals, $iso_code),
            'marker_img' => $marker_img,
            'select_block_theme' => ($iso_code === 'FI') ? 'matkahuolto' : 'omniva',
            'omniva_map' => $showMap,
            'omniva_current_country' => $iso_code,
            'omniva_postcode' => $postcode,
            'omniva_autoselect' => (int) Configuration::get('omnivalt_autoselect'),
        ]);

        return $this->display(__FILE__, 'displayCarrierExtraContent.tpl');
    }

    public function hookDisplayAdminOrderMain(array $params): string
    {
        $order = new Order((int) $params['id_order']);
        $carrier = OmnivaCarrier::getCarrierById((int) $order->id_carrier);

        if (!$carrier) {
            return '';
        }

        $method_key = OmnivaCarrier::getCarrierMethodKey((int) $order->id_carrier, (int) $carrier->id_reference);
        if (!$method_key) {
            return '';
        }

        $international_package_key = OmnivaApiInternational::isInternationalMethod($method_key)
            ? OmnivaApiInternational::getPackageKeyFromMethodKey($method_key)
            : false;

        $cart = new OmnivaCartTerminal($order->id_cart);
        $id_terminal = $cart->id_terminal ?? '';

        $address = new Address($order->id_address_delivery);
        $countryCode = Country::getIsoById($address->id_country);

        $omnivaOrder = new OmnivaOrder($order->id);
        $printLabelsUrl = $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=printLabels&id_order=' . $order->id;

        $error_msg = $omnivaOrder->error ? $this->displayError($omnivaOrder->error) : false;

        $shipment_additional_services_names = [];
        try {
            if (!$international_package_key) {
                $services = OmnivaApi::getAdditionalServices($order);
                foreach ($services as $key => $service) {
                    $shipment_additional_services_names[$key] = $service['title'];
                }
            }
        } catch (\Exception $e) {
            $error_msg = $this->displayError($e->getMessage());
        }

        $terminals = $this->getTerminalsList((int) $order->id_carrier, $countryCode);

        $this->smarty->assign([
            'is_international' => (bool) $international_package_key,
            'total_weight' => $omnivaOrder->weight,
            'packs' => $omnivaOrder->packs,
            'total_paid_tax_incl' => $omnivaOrder->cod_amount,
            'is_cod' => $omnivaOrder->cod,
            'parcel_terminals' => $this->getTerminalsOptions($terminals, $id_terminal),
            'active_additional_services' => implode(', ', $shipment_additional_services_names),
            'carriers' => $this->getCarriersOptions((int) $order->id_carrier),
            'order_id' => $order->id,
            'moduleurl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=saveOrderInfo',
            'generateLabelsUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=generateLabels',
            'printLabelsUrl' => $printLabelsUrl,
            'downloadLabelsUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=printLabels',
            'is_tracked' => !empty(json_decode($omnivaOrder->tracking_numbers ?: '[]')),
            'error' => $error_msg,
            'orderHistory' => OmnivaOrderHistory::getHistoryByOrder($omnivaOrder->id),
            'omnivalt_methods' => json_encode(OmnivaCarrier::getAllMethodsData()),
            'omniva_js_path' => $this->_path . 'views/js/omniva-admin-order.js',
            'omnivalt_text' => json_encode([
                'ajax_parsererror' => $this->trans('An invalid response was received', [], 'Modules.Omnivaltshipping.Admin'),
                'ajax_unknownerror' => $this->trans('Unknown error', [], 'Modules.Omnivaltshipping.Admin'),
                'save_success' => $this->trans('Successfully saved', [], 'Modules.Omnivaltshipping.Admin'),
            ]),
            'success_add_trans' => $this->trans('The shipment was successfully registered. Labels are downloading...', [], 'Modules.Omnivaltshipping.Admin'),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/blockinorder.tpl');
    }

    public function hookDisplayOrderConfirmation(array $params): string
    {
        if (!Validate::isLoadedObject($params['order']) ||
            !OmnivaCarrier::isOmnivaTerminalCarrier((int) $params['order']->id_carrier)
        ) {
            return '';
        }

        $cartTerminal = new OmnivaCartTerminal($params['order']->id_cart);
        if (!Validate::isLoadedObject($cartTerminal)) {
            return '';
        }

        $this->context->smarty->assign([
            'terminal_address' => OmnivaLocations::getTerminalAddress($cartTerminal->id_terminal),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/orderconfirmation.tpl');
    }

    public function hookDisplayOrderDetail(array $params): string
    {
        if (!Validate::isLoadedObject($params['order']) ||
            !OmnivaCarrier::isOmnivaCarrier((int) $params['order']->id_carrier)
        ) {
            return '';
        }

        $terminal_address = '';
        if (OmnivaCarrier::isOmnivaTerminalCarrier((int) $params['order']->id_carrier)) {
            $cartTerminal = new OmnivaCartTerminal($params['order']->id_cart);
            if (Validate::isLoadedObject($cartTerminal)) {
                $terminal_address = OmnivaLocations::getTerminalAddress($cartTerminal->id_terminal);
            }
        }

        $tracking_info = [];
        $omnivaOrder = new OmnivaOrder($params['order']->id);
        if (Validate::isLoadedObject($omnivaOrder) && $omnivaOrder->tracking_numbers) {
            $tracking_info = $this->api->getTracking(json_decode($omnivaOrder->tracking_numbers));
        }

        $address = new Address($params['order']->id_address_delivery);
        $iso_code = Validate::isLoadedObject($address) ? Country::getIsoById($address->id_country) : 'LT';

        $this->context->controller->addCSS($this->_path . 'views/css/omniva-front.css');

        $this->context->smarty->assign([
            'logo' => $this->_path . 'views/img/omnivalt-logo-horizontal.png',
            'country_code' => $iso_code,
            'terminal_address' => $terminal_address,
            'tracking_info' => $tracking_info,
            'tracking_url' => OmnivaApi::getTrackingUrl($iso_code),
            'show' => (!empty($terminal_address) || !empty($tracking_info)),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/orderdetail.tpl');
    }

    public function hookActionEmailSendBefore(array &$params): void
    {
        $params['templateVars']['{omniva_terminal_name}'] = '';
        $params['templateVars']['{omniva_terminal_text}'] = '';

        if (empty($params['templateVars']['{id_order}'])) {
            return;
        }

        $order = new Order((int) $params['templateVars']['{id_order}']);
        if (!Validate::isLoadedObject($order) ||
            !OmnivaCarrier::isOmnivaTerminalCarrier((int) $order->id_carrier)
        ) {
            return;
        }

        $cartTerminal = new OmnivaCartTerminal($order->id_cart);
        if (!Validate::isLoadedObject($cartTerminal)) {
            return;
        }

        $terminal_address = OmnivaLocations::getTerminalAddress($cartTerminal->id_terminal);
        if (!empty($terminal_address)) {
            $params['templateVars']['{omniva_terminal_name}'] = $terminal_address;
            $params['templateVars']['{omniva_terminal_text}'] = '<span style="font-weight: bold;">'
                . $this->trans('Omniva parcel terminal', [], 'Modules.Omnivaltshipping.Shop') . ':</span> ' . $terminal_address;
        }
    }

    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'];
        $carrier = new Carrier($order->id_carrier);

        if ($carrier->external_module_name !== $this->name) {
            return;
        }

        $log_prefix = 'Cart #' . $order->id_cart . ' Order #' . $order->id . '. ';
        OmnivaHelper::printToLog($log_prefix . 'Validating Order...', 'order');

        $omnivaOrder = new OmnivaOrder();
        $omnivaOrder->force_id = true;
        $omnivaOrder->id = $order->id;
        $omnivaOrder->packs = 1;
        $omnivaOrder->weight = $order->getTotalWeight() ?: 1;
        $omnivaOrder->cod = in_array($order->module, self::$_codModules) ? 1 : 0;
        $omnivaOrder->cod_amount = $order->total_paid_tax_incl;
        $omnivaOrder->add();

        OmnivaHelper::printToLog($log_prefix . print_r(get_object_vars($omnivaOrder), true), 'order');

        $omnivaOrderHistory = new OmnivaOrderHistory();
        $omnivaOrderHistory->id_order = $order->id;
        $omnivaOrderHistory->manifest = 0;
        $omnivaOrderHistory->add();
    }

    public function hookActionObjectOrderUpdateAfter(array $params): void
    {
        $order = $params['object'];
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $omnivaOrder = new OmnivaOrder($order->id);
        if (!Validate::isLoadedObject($omnivaOrder)) {
            return;
        }

        $omnivaOrder->weight = $order->getTotalWeight() ?: 1;
        $omnivaOrder->cod = in_array($order->module, self::$_codModules) ? 1 : 0;
        $omnivaOrder->cod_amount = $order->total_paid_tax_incl;
        $omnivaOrder->update();
    }

    /*======================== Configuration ========================*/

    public function getContent(): string
    {
        $output = '';

        $updateData = $this->checkForUpdate();
        if ($updateData && version_compare($this->version, $updateData['version'], '<')) {
            $this->context->smarty->assign([
                'update_url' => self::UPDATE_URL,
                'release_url' => $updateData['url'],
                'download_url' => self::DOWNLOAD_URL,
                'version' => $updateData['version'],
            ]);
            $output .= $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/update.tpl');
        }

        if (Tools::getValue('forceUpdateTerminals')) {
            $locations = new OmnivaLocations();
            if ($locations->update()) {
                $output .= $this->displayConfirmation($this->trans('Terminals updated', [], 'Modules.Omnivaltshipping.Admin'));
            } else {
                $output .= $this->displayError($this->trans('Failed to update terminals', [], 'Modules.Omnivaltshipping.Admin') . ': ' . $locations->getLastError());
            }
        }

        if (Tools::getValue('forceSendStatistics')) {
            $this->sendStatistics(true, true);
        }

        if (Tools::isSubmit($this->name . '_submit_settings')) {
            $output .= $this->processSettingsForm();
        }

        if (Tools::isSubmit($this->name . '_submit_refresh_carriers')) {
            $output .= $this->processCarriersForm();
        }

        if (Tools::isSubmit($this->name . '_submit_uninstall')) {
            $output .= $this->processUninstallForm();
        }

        return $output . $this->displayForm();
    }

    /*======================== Public Utility Methods ========================*/

    public static function getCarrierIds(array $carriers = []): array
    {
        $carriers = !empty($carriers) ? $carriers : array_keys(OmnivaCarrier::getAllMethods());
        $refs = [];
        foreach ($carriers as $value) {
            $ref = OmnivaCarrier::getReference($value);
            if ($ref) {
                $refs[] = $ref;
            }
        }

        if (empty($refs)) {
            return [];
        }

        $sql = 'SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE id_reference IN(' . implode(',', array_map('intval', $refs)) . ')';
        $result = Db::getInstance()->executeS($sql);

        $data = array_map(fn($row) => (int) $row['id_carrier'], $result ?: []);
        sort($data);
        return $data;
    }

    public function changeOrderStatus(int $id_order, int $status, $template_vars = false): void
    {
        $order = new Order($id_order);
        if ((int) $order->current_state === $status) {
            return;
        }

        $history = new OrderHistory();
        $history->id_order = $id_order;
        $history->id_employee = (int) ($this->context->employee->id ?? 0);
        $history->changeIdOrderState($status, $order);
        $history->addWithEmail(true, $template_vars ?: []);
    }

    public function refreshShippingCost(Order $order)
    {
        if (empty($order->id) || !Configuration::get('PS_ORDER_RECALCULATE_SHIPPING')) {
            return $order;
        }

        $fake_cart = new Cart((int) $order->id_cart);
        $new_cart_data = $fake_cart->duplicate();
        $new_cart = $new_cart_data['cart'];

        $new_cart->id_address_delivery = (int) $order->id_address_delivery;
        $new_cart->id_carrier = (int) $order->id_carrier;

        foreach ($new_cart->getProducts() as $product) {
            $new_cart->deleteProduct((int) $product['id_product'], (int) $product['id_product_attribute']);
        }

        foreach ($order->getProducts() as $product) {
            $new_cart->updateQty(
                $product['product_quantity'],
                (int) $product['product_id'],
                null,
                false,
                'up',
                0,
                null,
                true,
                true
            );
        }

        $base_total_shipping_tax_incl = (float) $new_cart->getPackageShippingCost((int) $new_cart->id_carrier, true, null);
        $base_total_shipping_tax_excl = (float) $new_cart->getPackageShippingCost((int) $new_cart->id_carrier, false, null);

        $order->total_shipping_tax_incl = $base_total_shipping_tax_incl;
        $order->total_shipping_tax_excl = $base_total_shipping_tax_excl;
        $order->total_shipping = $base_total_shipping_tax_incl;

        $order_carrier = new OrderCarrier((int) $order->getIdOrderCarrier());
        $order_carrier->shipping_cost_tax_incl = $base_total_shipping_tax_incl;
        $order_carrier->shipping_cost_tax_excl = $base_total_shipping_tax_excl;
        $order_carrier->update();

        $order->update();

        // Cleanup duplicated cart
        $new_cart->delete();

        return $order;
    }

    public function getCustomOrderState(): int
    {
        $state_id = (int) Configuration::get('omnivalt_order_state');
        $order_status = new OrderState($state_id, (int) $this->context->language->id);

        if ($order_status->id && $state_id) {
            return $state_id;
        }

        return $this->createOrderState(
            'omnivalt_order_state',
            ['default' => 'Shipment ready for Omniva', 'lt' => 'Paruošta siųsti su Omniva'],
            '#DDEEFF'
        );
    }

    public function getErrorOrderState(): int
    {
        $state_id = (int) Configuration::get('omnivalt_error_state');
        $order_status = new OrderState($state_id, (int) $this->context->language->id);

        if ($order_status->id && $state_id) {
            return $state_id;
        }

        return $this->createOrderState(
            'omnivalt_error_state',
            ['default' => 'Error with Omniva parcel', 'lt' => 'Omnivos siuntos klaida'],
            '#F22323'
        );
    }

    /*======================== Private Methods ========================*/

    private function updateTerminalsIfNeeded(): void
    {
        $locations = new OmnivaLocations();
        $locations->updateIfNeeded();
    }

    private function sendStatisticsIfNeeded(): void
    {
        try {
            $this->sendStatistics();
        } catch (\Exception $e) {
            OmnivaHelper::printToLog('Failed to send statistics. Error: ' . $e->getMessage(), 'powerbi');
        }
    }

    private function sendStatistics(bool $force = false, bool $test_mode = false): void
    {
        $last_send = Configuration::get('omnivalt_last_statistics_send');
        $last_try = Configuration::get('omnivalt_last_statistics_try');
        $date_minus_month = strtotime('-1 month');
        $date_minus_day = strtotime('-1 day');

        $send_now = $force || (
            date('j') == 2
            && (!$last_send || $date_minus_month > (int) $last_send)
            && (!$last_try || $date_minus_day > (int) $last_try)
        );

        if (!$send_now) {
            return;
        }

        $result = $this->api->sendStatistics($this->collectShipmentsData(), $test_mode);
        if ($result) {
            Configuration::updateValue('omnivalt_last_statistics_send', time());
        }
        Configuration::updateValue('omnivalt_last_statistics_try', time());
    }

    private function collectShipmentsData(): array
    {
        // Statistics collection - same logic as before
        return [
            'module_version' => $this->version,
            'platform_version' => _PS_VERSION_,
            'client_name' => Configuration::get('omnivalt_company'),
            'client_country' => Configuration::get('omnivalt_countrycode'),
            'track_since' => Configuration::get('omnivalt_last_statistics_send') ?: time(),
            'total_orders' => ['courier' => 0, 'terminal' => 0],
            'shipping_prices' => [],
        ];
    }

    private function createOrderState(string $config_key, array $names, string $color): int
    {
        $orderState = new OrderState();
        $orderState->name = [];
        foreach (Language::getLanguages() as $language) {
            $iso = strtolower($language['iso_code']);
            $orderState->name[$language['id_lang']] = $names[$iso] ?? $names['default'];
        }
        $orderState->send_email = false;
        $orderState->color = $color;
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->logable = true;
        $orderState->invoice = false;
        $orderState->unremovable = false;

        if ($orderState->add()) {
            Configuration::updateValue($config_key, $orderState->id);
            return (int) $orderState->id;
        }

        return 0;
    }

    private function getModuleTabs(): array
    {
        return [
            self::CONTROLLER_OMNIVA_AJAX => [
                'title' => $this->trans('Omniva Admin Ajax', [], 'Modules.Omnivaltshipping.Admin'),
                'parent_tab' => null,
            ],
            self::CONTROLLER_OMNIVA_ORDERS => [
                'title' => $this->trans('Omniva Orders', [], 'Modules.Omnivaltshipping.Admin'),
                'parent_tab' => 'AdminParentShipping',
            ],
        ];
    }

    private function registerTabs(): bool
    {
        foreach ($this->getModuleTabs() as $controller => $tabData) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $controller;
            $tab->name = [];
            foreach (Language::getLanguages(false) as $language) {
                $tab->name[$language['id_lang']] = $tabData['title'];
            }
            $tab->id_parent = $tabData['parent_tab'] ? Tab::getIdFromClassName($tabData['parent_tab']) : -1;
            $tab->module = $this->name;
            if (!$tab->save()) {
                return false;
            }
        }
        return true;
    }

    private function deleteTabs(): void
    {
        foreach (array_keys($this->getModuleTabs()) as $controller) {
            $idTab = (int) Tab::getIdFromClassName($controller);
            if ($idTab) {
                $tab = new Tab($idTab);
                if (Validate::isLoadedObject($tab)) {
                    $tab->delete();
                }
            }
        }
    }

    private function createDbTables(): bool
    {
        return (new OmnivaDb())->createTables();
    }

    private function createCarriers(): bool
    {
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $this->createCarrier($key);
        }
        return true;
    }

    private function createCarrier(string $method_key): bool
    {
        $methods = OmnivaCarrier::getAllMethods();
        $title = $methods[$method_key] ?? 'Omniva carrier';
        return OmnivaCarrier::createCarrier($method_key, $title, $this->name, __DIR__ . '/views/img/omnivalt-logo-vertical.png');
    }

    private function deleteCarriers(): void
    {
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            OmnivaCarrier::markAsDeleted($key);
        }
    }

    private function getCartCountryCode(Cart $cart): string
    {
        $default = $this->context->country->iso_code ?? $this->context->language->iso_code;
        $address = new Address($cart->id_address_delivery);
        $iso_code = $address->id_country ? Country::getIsoById($address->id_country) : $default;
        return strtoupper($iso_code);
    }

    private function getCarrierIdFromParams(array $params): int
    {
        if (is_object($params['carrier'])) {
            return (int) $params['carrier']->id;
        }
        if (is_array($params['carrier']) && isset($params['carrier']['id'])) {
            return (int) $params['carrier']['id'];
        }
        return 0;
    }

    private function getTerminalsList(int $carrier_id, string $country = 'LT'): array
    {
        if (!$country) {
            $country = Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        }

        if (!OmnivaApiServices::haveTerminals($country)) {
            return [];
        }

        $shipment_codes = $this->api->getShipmentCodes($carrier_id);
        if (!$shipment_codes->_exists) {
            return [];
        }

        $terminals_type = OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key);
        if (!$terminals_type) {
            return [];
        }

        $terminals_type_code = ($terminals_type === 'post') ? 1 : 0;

        return OmnivaLocations::getFiltered($country, $terminals_type_code);
    }

    private function getTerminalsOptions(array $terminals_list, string $selected = ''): string
    {
        if (empty($terminals_list)) {
            return '';
        }

        $grouped_options = [];
        foreach ($terminals_list as $terminal) {
            $group_name = (string) $terminal['A1_NAME'];
            $address = trim(
                $terminal['A2_NAME'] . ' '
                . ($terminal['A5_NAME'] !== 'NULL' ? $terminal['A5_NAME'] : '') . ' '
                . ($terminal['A7_NAME'] !== 'NULL' ? $terminal['A7_NAME'] : '')
            );
            $grouped_options[$group_name][(string) $terminal['ZIP']] = $terminal['NAME'] . ' (' . $address . ')';
        }
        ksort($grouped_options);

        $this->context->smarty->assign([
            'grouped_options' => $grouped_options,
            'selected' => $selected,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/front/omniva-terminals.tpl');
    }

    private function getTerminalForMap(array $terminals_list, string $country = 'LT'): array
    {
        if (empty($terminals_list)) {
            return [];
        }

        $comment_keys = ['LT' => 'comment_lit', 'LV' => 'comment_lav', 'EE' => 'comment_est'];
        $comment_key = $comment_keys[strtoupper($country)] ?? 'comment_lit';

        return array_map(fn($terminal) => [
            $terminal['NAME'],
            $terminal['Y_COORDINATE'],
            $terminal['X_COORDINATE'],
            $terminal['ZIP'],
            $terminal['A1_NAME'],
            $terminal['A2_NAME'],
            $terminal[$comment_key] ?? '',
        ], $terminals_list);
    }

    private function getCarriersOptions(int $selected = 0): string
    {
        $selected_reference = 0;
        if ($selected) {
            $selected_carrier = new Carrier($selected);
            if (Validate::isLoadedObject($selected_carrier)) {
                $selected_reference = (int) $selected_carrier->id_reference;
            }
        }

        $html = '';
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $carrier = OmnivaCarrier::getCarrier($key);
            if (!$carrier || empty($carrier->id)) {
                continue;
            }
            $sel = ($selected_reference && (int) $carrier->id_reference == $selected_reference) ? ' selected' : '';
            $html .= '<option value="' . (int) $carrier->id . '"' . $sel . '>' . htmlspecialchars($title) . '</option>';
        }
        return $html;
    }

    private function checkForUpdate(): ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::UPDATE_URL);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Awesome-Octocat-App');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response);
        if (isset($data->tag_name)) {
            return [
                'version' => str_replace('v', '', $data->tag_name),
                'url' => $data->html_url ?? '#',
            ];
        }

        return null;
    }

    /*======================== Form Processing ========================*/

    private function processSettingsForm(): string
    {
        $fields = $this->getSettingsFields();
        $required = [
            'omnivalt_api_user', 'omnivalt_api_pass', 'omnivalt_api_country',
            'omnivalt_company', 'omnivalt_address', 'omnivalt_city', 'omnivalt_postcode',
            'omnivalt_countrycode', 'omnivalt_phone', 'omnivalt_pick_up_time_start',
            'omnivalt_pick_up_time_finish', 'omnivalt_send_off',
        ];

        $values = [];
        $missing = [];
        foreach ($fields as $field_key => $title) {
            $values[$field_key] = trim((string) Tools::getValue($field_key));
            if ($values[$field_key] === '' && in_array($field_key, $required)) {
                $missing[] = $title;
            }
        }

        if (!empty($missing)) {
            return $this->displayError(sprintf(
                $this->trans('Failed to save. These fields are required: %s', [], 'Modules.Omnivaltshipping.Admin'),
                '<br/><b>' . implode('<br/>', $missing) . '</b>'
            ));
        }

        foreach ($values as $key => $val) {
            Configuration::updateValue($key, $val);
        }
        return $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Omnivaltshipping.Admin'));
    }

    private function processCarriersForm(): string
    {
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            if (Tools::getValue($key)) {
                if (!OmnivaCarrier::unmarkAsDeleted($key)) {
                    $this->createCarrier($key);
                }
            } else {
                OmnivaCarrier::markAsDeleted($key);
            }
        }
        return $this->displayConfirmation($this->trans('Carriers updated', [], 'Modules.Omnivaltshipping.Admin'));
    }

    private function processUninstallForm(): string
    {
        Configuration::updateValue('omnivalt_uninstall_tables', (string) Tools::getValue('omnivalt_uninstall_tables'));
        Configuration::updateValue('omnivalt_uninstall_carriers', (string) Tools::getValue('omnivalt_uninstall_carriers'));
        return $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Omnivaltshipping.Admin'));
    }

    private function getSettingsFields(): array
    {
        return [
            'omnivalt_map' => $this->trans('Display map', [], 'Modules.Omnivaltshipping.Admin'),
            'send_delivery_email' => $this->trans('Send delivery email', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_api_url' => $this->trans('Api URL', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_api_user' => $this->trans('API login user', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_api_pass' => $this->trans('API login password', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_api_country' => $this->trans('API login country', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_ee_service' => $this->trans('Estonia Carrier Service', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_fi_service' => $this->trans('Finland Carrier Service', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_send_off' => $this->trans('Send off type', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_bank_account' => $this->trans('Bank account', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_company' => $this->trans('Company name', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_address' => $this->trans('Company address', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_city' => $this->trans('Company city', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_postcode' => $this->trans('Company postcode', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_countrycode' => $this->trans('Company country code', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_phone' => $this->trans('Company phone number', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_pick_up_time_start' => $this->trans('Pick up time start', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_pick_up_time_finish' => $this->trans('Pick up time finish', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_print_type' => $this->trans('Labels print type', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_send_return' => $this->trans('Send return code', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_manifest_lang' => $this->trans('Manifest language', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_label_comment_type' => $this->trans('Label comment', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_autoselect' => $this->trans('Autoselect terminal', [], 'Modules.Omnivaltshipping.Admin'),
            'omnivalt_default_receiver_countrycode' => $this->trans('Default country of delivery', [], 'Modules.Omnivaltshipping.Admin'),
        ];
    }

    public function displayForm(): string
    {
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $countries_list = OmnivaHelper::getEuCountriesList($this->context->language->id, 'eu');
        $settings_fields = $this->getSettingsFields();

        $last_update_formated = OmnivaLocations::getLastUpdateFormatted();

        $fields_form = $this->buildSettingsForm($settings_fields, $countries_list, $last_update_formated);

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->trans('Save', [], 'Modules.Omnivaltshipping.Admin'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->trans('Back to list', [], 'Modules.Omnivaltshipping.Admin'),
            ],
        ];

        $helper->fields_value = $this->getFormValues();

        return $helper->generateForm($fields_form);
    }

    private function getFormValues(): array
    {
        $values = [
            'omnivalt_api_url' => Configuration::get('omnivalt_api_url') ?: 'https://edixml.post.ee',
            'omnivalt_api_user' => Configuration::get('omnivalt_api_user'),
            'omnivalt_api_pass' => Configuration::get('omnivalt_api_pass'),
            'omnivalt_api_country' => Configuration::get('omnivalt_api_country'),
            'omnivalt_ee_service' => Configuration::get('omnivalt_ee_service'),
            'omnivalt_fi_service' => Configuration::get('omnivalt_fi_service'),
            'omnivalt_send_off' => Configuration::get('omnivalt_send_off'),
            'omnivalt_company' => Configuration::get('omnivalt_company'),
            'omnivalt_address' => Configuration::get('omnivalt_address'),
            'omnivalt_city' => Configuration::get('omnivalt_city'),
            'omnivalt_postcode' => Configuration::get('omnivalt_postcode'),
            'omnivalt_countrycode' => Configuration::get('omnivalt_countrycode'),
            'omnivalt_phone' => Configuration::get('omnivalt_phone'),
            'omnivalt_bank_account' => Configuration::get('omnivalt_bank_account'),
            'omnivalt_pick_up_time_start' => Configuration::get('omnivalt_pick_up_time_start') ?: '8:00',
            'omnivalt_pick_up_time_finish' => Configuration::get('omnivalt_pick_up_time_finish') ?: '17:00',
            'omnivalt_default_receiver_countrycode' => Configuration::get('omnivalt_default_receiver_countrycode'),
            'omnivalt_map' => Configuration::get('omnivalt_map'),
            'omnivalt_autoselect' => Configuration::get('omnivalt_autoselect'),
            'send_delivery_email' => Configuration::get('send_delivery_email'),
            'omnivalt_send_return' => Configuration::get('omnivalt_send_return'),
            'omnivalt_print_type' => Configuration::get('omnivalt_print_type') ?: 'four',
            'omnivalt_label_comment_type' => Configuration::get('omnivalt_label_comment_type') ?: OmnivaApi::LABEL_COMMENT_TYPE_NONE,
            'omnivalt_manifest_lang' => Configuration::get('omnivalt_manifest_lang') ?: 'en',
            'omnivalt_uninstall_tables' => Configuration::get('omnivalt_uninstall_tables'),
            'omnivalt_uninstall_carriers' => Configuration::get('omnivalt_uninstall_carriers'),
        ];

        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $carrier = OmnivaCarrier::getCarrier($key);
            $values[$key] = ($carrier && !$carrier->deleted) ? 1 : 0;
        }

        return $values;
    }

    private function buildSettingsForm(array $settings_fields, array $countries_list, string $last_update_formated): array
    {
        $switch_values = [
            ['id' => 'label2_on', 'value' => 1, 'label' => $this->trans('Enabled', [], 'Modules.Omnivaltshipping.Admin')],
            ['id' => 'label2_off', 'value' => 0, 'label' => $this->trans('Disabled', [], 'Modules.Omnivaltshipping.Admin')],
        ];

        $fields_form = [];

        // Settings form
        $fields_form[0]['form'] = [
            'legend' => ['title' => $this->trans('Settings', [], 'Modules.Omnivaltshipping.Admin')],
            'input' => [
                // API settings
                ['type' => 'hidden', 'label' => $settings_fields['omnivalt_api_url'], 'name' => 'omnivalt_api_url', 'size' => 20],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_api_user'], 'name' => 'omnivalt_api_user', 'size' => 20, 'required' => true],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_api_pass'], 'name' => 'omnivalt_api_pass', 'size' => 20, 'required' => true],
                ['type' => 'select', 'label' => $settings_fields['omnivalt_api_country'], 'name' => 'omnivalt_api_country', 'required' => true,
                    'desc' => $this->trans('Select the Omniva department country, from which you got the logins.', [], 'Modules.Omnivaltshipping.Admin'),
                    'options' => ['query' => [
                        ['id_option' => 'lt', 'name' => $this->trans('Lithuania', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'lv', 'name' => $this->trans('Latvia', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'ee', 'name' => $this->trans('Estonia', [], 'Modules.Omnivaltshipping.Admin')],
                    ], 'id' => 'id_option', 'name' => 'name']],
                ['type' => 'switch', 'label' => $settings_fields['omnivalt_ee_service'], 'name' => 'omnivalt_ee_service', 'is_bool' => true, 'values' => $switch_values,
                    'desc' => $this->trans('Activate this service, if your e-shop clients want to receive parcels in Estonia. Only available for Estonia API country.', [], 'Modules.Omnivaltshipping.Admin')],
                ['type' => 'switch', 'label' => $settings_fields['omnivalt_fi_service'], 'name' => 'omnivalt_fi_service', 'is_bool' => true, 'values' => $switch_values,
                    'desc' => $this->trans('Activate this service, if you want to send parcels to Finland. Only available for Estonia API country.', [], 'Modules.Omnivaltshipping.Admin')],
                
                ['type' => 'html', 'name' => 'omnivalt_separator_sender', 'html_content' => '<hr/>'],
                
                // Sender information
                ['type' => 'text', 'label' => $settings_fields['omnivalt_company'], 'name' => 'omnivalt_company', 'size' => 20, 'required' => true],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_address'], 'name' => 'omnivalt_address', 'size' => 20, 'required' => true],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_city'], 'name' => 'omnivalt_city', 'size' => 20, 'required' => true],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_postcode'], 'name' => 'omnivalt_postcode', 'size' => 20, 'required' => true],
                ['type' => 'select', 'label' => $settings_fields['omnivalt_countrycode'], 'name' => 'omnivalt_countrycode', 'required' => true,
                    'options' => ['query' => $this->buildCountriesFieldOptions($countries_list), 'id' => 'id_option', 'name' => 'name']],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_phone'], 'name' => 'omnivalt_phone', 'size' => 20, 'required' => true],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_bank_account'], 'name' => 'omnivalt_bank_account', 'size' => 20,
                    'desc' => $this->trans('Required if you intend to use COD payment method.', [], 'Modules.Omnivaltshipping.Admin')],
                
                ['type' => 'html', 'name' => 'omnivalt_separator_front', 'html_content' => '<hr/>'],
                
                // Checkout options
                ['type' => 'select', 'label' => $settings_fields['omnivalt_default_receiver_countrycode'], 'name' => 'omnivalt_default_receiver_countrycode',
                    'desc' => $this->trans('You can specify a default customer country that will be used until the delivery address is entered.', [], 'Modules.Omnivaltshipping.Admin'),
                    'options' => ['query' => $this->buildCountriesFieldOptions($countries_list, $this->trans('Not specified', [], 'Modules.Omnivaltshipping.Admin')), 'id' => 'id_option', 'name' => 'name']],
                ['type' => 'switch', 'label' => $settings_fields['omnivalt_map'], 'name' => 'omnivalt_map', 'is_bool' => true, 'values' => $switch_values],
                ['type' => 'switch', 'label' => $settings_fields['omnivalt_autoselect'], 'name' => 'omnivalt_autoselect', 'is_bool' => true, 'values' => $switch_values],
                
                ['type' => 'html', 'name' => 'omnivalt_separator_label', 'html_content' => '<hr/>'],
                
                // Label options
                ['type' => 'switch', 'label' => $settings_fields['send_delivery_email'], 'name' => 'send_delivery_email', 'is_bool' => true, 'values' => $switch_values],
                ['type' => 'switch', 'label' => $settings_fields['omnivalt_send_return'], 'name' => 'omnivalt_send_return', 'is_bool' => true, 'values' => $switch_values,
                    'desc' => $this->trans('Please note that extra charges may apply.', [], 'Modules.Omnivaltshipping.Admin')],
                ['type' => 'select', 'label' => $settings_fields['omnivalt_print_type'], 'name' => 'omnivalt_print_type',
                    'options' => ['query' => [
                        ['id_option' => 'single', 'name' => $this->trans('Original (single label)', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'four', 'name' => $this->trans('A4 (4 labels)', [], 'Modules.Omnivaltshipping.Admin')],
                    ], 'id' => 'id_option', 'name' => 'name']],
                ['type' => 'select', 'label' => $settings_fields['omnivalt_label_comment_type'], 'name' => 'omnivalt_label_comment_type',
                    'options' => ['query' => [
                        ['id_option' => OmnivaApi::LABEL_COMMENT_TYPE_NONE, 'name' => $this->trans('No comment', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => OmnivaApi::LABEL_COMMENT_TYPE_ORDER_ID, 'name' => $this->trans('Order ID', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => OmnivaApi::LABEL_COMMENT_TYPE_ORDER_REF, 'name' => $this->trans('Order reference', [], 'Modules.Omnivaltshipping.Admin')],
                    ], 'id' => 'id_option', 'name' => 'name']],
                
                ['type' => 'html', 'name' => 'omnivalt_separator_manifest', 'html_content' => '<hr/>'],
                
                // Manifest options
                ['type' => 'select', 'label' => $settings_fields['omnivalt_manifest_lang'], 'name' => 'omnivalt_manifest_lang',
                    'options' => ['query' => [
                        ['id_option' => 'en', 'name' => $this->trans('English', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'ee', 'name' => $this->trans('Estonian', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'lv', 'name' => $this->trans('Latvian', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'lt', 'name' => $this->trans('Lithuanian', [], 'Modules.Omnivaltshipping.Admin')],
                    ], 'id' => 'id_option', 'name' => 'name']],
                
                ['type' => 'html', 'name' => 'omnivalt_separator_front', 'html_content' => '<hr/>'],

                // Pick up options
                ['type' => 'text', 'label' => $settings_fields['omnivalt_pick_up_time_start'], 'name' => 'omnivalt_pick_up_time_start', 'size' => 20, 'required' => true],
                ['type' => 'text', 'label' => $settings_fields['omnivalt_pick_up_time_finish'], 'name' => 'omnivalt_pick_up_time_finish', 'size' => 20, 'required' => true],
                ['type' => 'select', 'label' => $settings_fields['omnivalt_send_off'], 'name' => 'omnivalt_send_off', 'required' => true,
                    'desc' => $this->trans('Please select send off from store type', [], 'Modules.Omnivaltshipping.Admin'),
                    'options' => ['query' => [
                        ['id_option' => 'pt', 'name' => $this->trans('Parcel terminal', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'c', 'name' => $this->trans('Courier', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'po', 'name' => $this->trans('Post Office', [], 'Modules.Omnivaltshipping.Admin')],
                        ['id_option' => 'lc', 'name' => $this->trans('Logistics Center', [], 'Modules.Omnivaltshipping.Admin')],
                    ], 'id' => 'id_option', 'name' => 'name']],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Modules.Omnivaltshipping.Admin'), 'class' => 'btn btn-default pull-right', 'name' => $this->name . '_submit_settings'],
            'buttons' => [[
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'js' => 'omivaltshippingForceTerminalUpdate(this); return false;',
                'type' => 'button',
                'id' => 'omniva-update-terminals',
                'name' => 'updateTerminals',
                'icon' => 'process-icon-refresh',
                'title' => $this->trans('Updated Terminals:', [], 'Modules.Omnivaltshipping.Admin') . ' ' . $last_update_formated,
            ]],
        ];

        // Carriers form
        $carrier_inputs = [];
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $carrier_inputs[] = [
                'type' => 'switch', 'label' => $title, 'name' => $key,
                'is_bool' => true, 'values' => [
                    ['id' => 'carrier_on', 'value' => 1, 'label' => $this->trans('Added', [], 'Modules.Omnivaltshipping.Admin')],
                    ['id' => 'carrier_off', 'value' => 0, 'label' => $this->trans('Removed', [], 'Modules.Omnivaltshipping.Admin')],
                ],
            ];
        }

        $fields_form[1]['form'] = [
            'legend' => ['title' => $this->trans('Carriers', [], 'Modules.Omnivaltshipping.Admin')],
            'description' => $this->trans('After activating the shipping method below, a new Carrier is created in the Prestashop shipping carriers list.', [], 'Modules.Omnivaltshipping.Admin'),
            'input' => $carrier_inputs,
            'submit' => ['title' => $this->trans('Save', [], 'Modules.Omnivaltshipping.Admin'), 'class' => 'btn btn-default pull-right', 'name' => $this->name . '_submit_refresh_carriers'],
        ];

        // Uninstall form
        $fields_form[2]['form'] = [
            'legend' => ['title' => $this->trans('Module uninstall', [], 'Modules.Omnivaltshipping.Admin')],
            'warning' => $this->trans('The enabled actions in this section will be performed when uninstalling the module.', [], 'Modules.Omnivaltshipping.Admin'),
            'input' => [
                ['type' => 'switch', 'label' => $this->trans('Delete database tables', [], 'Modules.Omnivaltshipping.Admin'), 'name' => 'omnivalt_uninstall_tables',
                    'desc' => $this->trans('Delete tables created by the module from the database.', [], 'Modules.Omnivaltshipping.Admin'),
                    'is_bool' => true, 'values' => $switch_values],
                ['type' => 'switch', 'label' => $this->trans('Completely delete carriers', [], 'Modules.Omnivaltshipping.Admin'), 'name' => 'omnivalt_uninstall_carriers',
                    'desc' => $this->trans('Completely delete carriers created by this module from the database.', [], 'Modules.Omnivaltshipping.Admin'),
                    'is_bool' => true, 'values' => $switch_values],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Modules.Omnivaltshipping.Admin'), 'class' => 'btn btn-default pull-right', 'name' => $this->name . '_submit_uninstall'],
        ];

        return $fields_form;
    }

    private function buildCountriesFieldOptions(array $countries_list, $empty_value_label = false): array
    {
        $options = [];
        if ($empty_value_label) {
            $options[] = ['id_option' => '', 'name' => '- ' . $empty_value_label . ' -'];
        }
        foreach ($countries_list as $code => $name) {
            $options[] = ['id_option' => $code, 'name' => $name];
        }
        return $options;
    }
}
