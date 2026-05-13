<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOmnivaOrdersController extends ModuleAdminController
{
    private string $_carriers = '';
    private string $_path;
    private int $perPage = 30;

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        $this->addCSS($this->_path . 'views/css/omniva-admin.css');
        $this->addJS($this->_path . 'views/js/omniva-orders.js');
        Media::addJsDef([
            'check_orders' => $this->module->l('Please select orders'),
            'carrier_cal_url' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&callCourier=1',
            'cancel_courier_call' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&cancelCourier=',
            'finished_trans' => $this->module->l('Finished.'),
            'message_sent_trans' => $this->module->l('Message successfully sent.'),
            'courier_call_success' => $this->module->l('Registered courier call'),
            'courier_arrival_between' => $this->module->l('The courier will arrive between'),
            'incorrect_response_trans' => $this->module->l('Incorrect response.'),
            'ajaxCall' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&ajax=1',
            'orderLink' => $this->context->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => 0]),
            'manifestLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&action=printManifest',
            'downloadManifestLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&action=downloadManifest',
            'labelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&action=printLabels',
            'bulkLabelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&action=bulkPrintLabels',
            'labels_trans' => $this->module->l('Labels'),
            'not_found_trans' => $this->module->l('Nothing found'),
        ]);
    }

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->_path = __PS_BASE_URI__ . 'modules/' . $this->module->name . '/';
        $this->_carriers = $this->getCarrierIdsString();
    }

    public function postProcess()
    {
        $this->handleActions();
        return parent::postProcess();
    }

    private function handleActions(): void
    {
        if (Tools::getValue('orderSkip') !== null && Tools::getValue('orderSkip') !== false) {
            $this->skipOrder();
        } elseif (Tools::getValue('cancelSkip') !== null && Tools::getValue('cancelSkip') !== false) {
            $this->cancelSkip();
        } elseif (Tools::getValue('callCourier')) {
            $result = $this->module->api->callCarrier();
            $this->ajaxDie(json_encode($result));
        } elseif (Tools::getValue('cancelCourier')) {
            $call_id = Tools::getValue('cancelCourier');
            if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $call_id)) {
                $this->ajaxDie(json_encode(['error' => 'Invalid courier call ID.']));
            }
            $result = $this->module->api->cancelCarrier($call_id);
            if (isset($result['error'])) {
                $this->ajaxDie(json_encode($result));
            }
            OmnivaHelper::removeScheduledCourierCall($call_id);
            $this->ajaxDie(json_encode($result));
        }
    }

    private function getCarrierIdsString(): string
    {
        $ids = OmnivaltShipping::getCarrierIds();
        return !empty($ids) ? implode(',', $ids) : '0';
    }

    public function displayAjax(): void
    {
        $customer = Tools::getValue('customer');
        $tracking = Tools::getValue('tracking_nr');
        $date = Tools::getValue('input-date-added');
        $orderId = Tools::getValue('order_id');
        $orderDate = Tools::getValue('input-order-date');
        $where = '';

        if ($orderId && $orderId !== 'undefined') {
            $where .= ' AND a.id_order = ' . (int) $orderId . ' ';
        }
        if ($tracking && $tracking !== 'undefined') {
            $where .= ' AND ooh.tracking_numbers LIKE "%' . pSQL($tracking) . '%" ';
        }
        if ($customer && $customer !== 'undefined') {
            $where .= ' AND CONCAT(oh.firstname, " ",oh.lastname) LIKE "%' . pSQL($customer) . '%" ';
        }
        if ($date && $date !== 'undefined') {
            $where .= ' AND oc.date_add LIKE "%' . pSQL($date) . '%" ';
        }
        if ($orderDate && $orderDate !== 'undefined') {
            $where .= ' AND a.date_add LIKE "' . pSQL($orderDate) . '%" ';
        }

        if ($where === '') {
            $this->ajaxDie(json_encode([]));
        }

        $sql = 'SELECT a.id_order, a.date_add, a.date_upd, a.total_paid_tax_incl,
                CONCAT(UPPER(LEFT(oh.firstname, 1)), ". ", oh.lastname) as full_name,
                ooh.tracking_numbers, ooh.id as history, ooh.date_add as labels_registered
            FROM ' . _DB_PREFIX_ . 'orders a
            INNER JOIN ' . _DB_PREFIX_ . 'customer oh ON a.id_customer = oh.id_customer
            LEFT JOIN ' . _DB_PREFIX_ . 'order_carrier oc ON a.id_order = oc.id_order
            JOIN ' . _DB_PREFIX_ . 'omniva_order oo ON a.id_order = oo.id
                AND a.id_carrier IN (' . $this->_carriers . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'omniva_order_history ooh
                ON ooh.id_order = a.id_order
                AND ooh.tracking_numbers IS NOT NULL
                AND ooh.tracking_numbers != ""
            WHERE 1=1
                ' . $where . '
            ORDER BY a.id_order DESC, ooh.id DESC';

        $results = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: [];

        array_walk($results, function (&$row) {
            $row['tracking_numbers'] = implode(', ', json_decode($row['tracking_numbers'], true) ?: []);
        });

        $this->ajaxDie(json_encode($results));
    }

    public function initContent(): void
    {
        parent::initContent();

        $page = max(1, (int) Tools::getValue('p', 1));
        $tab = Tools::getValue('tab', 'new');

        $newCount = $this->getOrdersCount('new');
        $finishedCount = $this->getOrdersCount('finished');

        $courier_calls = OmnivaHelper::getScheduledCourierCalls();
        $controllerLink = $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS);
        $ajaxLink = $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX);

        $this->context->smarty->assign([
            'orders' => $this->getCompletedOrders($page - 1),
            'sender' => Configuration::get('omnivalt_company'),
            'phone' => Configuration::get('omnivalt_phone'),
            'postcode' => Configuration::get('omnivalt_postcode'),
            'address' => Configuration::get('omnivalt_address'),
            'skippedOrders' => $this->getSkippedOrders(),
            'newOrders' => $this->getNewOrders($page - 1),
            'orderLink' => $this->context->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => 0]),
            'orderSkip' => $controllerLink . '&orderSkip=',
            'cancelSkip' => $controllerLink . '&cancelSkip=',
            'page' => $page,
            'manifestLink' => $ajaxLink . '&action=printManifest',
            'downloadManifestLink' => $ajaxLink . '&action=downloadManifest',
            'downloadManifestByNumLink' => $ajaxLink . '&action=downloadManifestByNumber',
            'labelsLink' => $ajaxLink . '&action=printLabels',
            'generateLabelsLink' => $ajaxLink . '&action=generateLabels&redirect=1&id_order=',
            'bulkLabelsLink' => $ajaxLink . '&action=bulkPrintLabels',
            'manifestNum' => (string) Configuration::get('omnivalt_manifest'),
            'total' => $this->_listTotal ?? 0,
            'courier_calls' => OmnivaHelper::splitScheduledCourierCalls($courier_calls),
            'pickup_start' => Configuration::get('omnivalt_pick_up_time_start') ?: '8:00',
            'pickup_end' => Configuration::get('omnivalt_pick_up_time_finish') ?: '17:00',
        ]);

        // Pagination for new orders
        $this->assignPagination($newCount, $page, $controllerLink . '&tab=new', 'finished_pagination_content');
        // Pagination for completed orders
        $this->assignPagination($finishedCount, $page, $controllerLink . '&tab=completed', 'generated_pagination_content');

        $content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/omnivaOrders.tpl'
        );

        $this->context->smarty->assign(['content' => $this->content . $content]);
    }

    /*======================== Query Methods ========================*/

    private function getCompletedOrders(int $page): array
    {
        $offset = $page * $this->perPage;

        $sql = 'SELECT a.id_order, a.date_add, a.total_paid_tax_incl,
                oh.firstname, oh.lastname,
                ooh.id as history_id, ooh.tracking_numbers, ooh.date_add as history_date, ooh.manifest
            FROM ' . _DB_PREFIX_ . 'orders a
            INNER JOIN ' . _DB_PREFIX_ . 'customer oh ON a.id_customer = oh.id_customer
            INNER JOIN ' . _DB_PREFIX_ . 'omniva_order oo ON oo.id = a.id_order
                AND a.id_carrier IN (' . $this->_carriers . ')
            INNER JOIN ' . _DB_PREFIX_ . 'omniva_order_history ooh
                ON ooh.id_order = a.id_order
                AND ooh.manifest > 0
            ORDER BY ooh.manifest DESC, a.id_order DESC, ooh.id DESC
            LIMIT ' . (int) $this->perPage . ' OFFSET ' . (int) $offset;

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: [];
    }

    private function getNewOrders(int $page): array
    {
        $offset = $page * $this->perPage;

        $sql = 'SELECT *, ooh.id as history, ooh.tracking_numbers as history_tracking_numbers, ooh.date_add as history_date FROM ' . _DB_PREFIX_ . 'orders a
            INNER JOIN ' . _DB_PREFIX_ . 'customer c ON a.id_customer = c.id_customer
            INNER JOIN ' . _DB_PREFIX_ . 'order_carrier oc ON a.id_order = oc.id_order
            INNER JOIN ' . _DB_PREFIX_ . 'order_state os ON a.current_state = os.id_order_state
                AND os.deleted = 0 AND os.shipped = 0
            INNER JOIN ' . _DB_PREFIX_ . 'order_state_lang osl ON a.current_state = osl.id_order_state
                AND a.id_lang = osl.id_lang
                AND (osl.template IN ("", "preparation", "cashondelivery", "bankwire", "cheque", "payment_error", "in_transit")
                    OR (os.paid = 1 AND osl.template = "payment"))
            INNER JOIN ' . _DB_PREFIX_ . 'omniva_order oo ON oo.id = a.id_order
                AND a.id_carrier IN (' . $this->_carriers . ')
            INNER JOIN ' . _DB_PREFIX_ . 'omniva_order_history ooh ON ooh.id_order = a.id_order
                AND ooh.manifest = 0
            ORDER BY a.id_order DESC, ooh.id DESC
            LIMIT ' . (int) $this->perPage . ' OFFSET ' . (int) $offset;

        return $this->groupOrderHistories(Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: []);
    }

    private function getSkippedOrders(): array
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'orders a
            INNER JOIN ' . _DB_PREFIX_ . 'customer oh ON a.id_customer = oh.id_customer
            LEFT JOIN ' . _DB_PREFIX_ . 'order_carrier oc ON a.id_order = oc.id_order
            INNER JOIN ' . _DB_PREFIX_ . 'omniva_order oo ON oo.id = a.id_order
                AND a.id_carrier IN (' . $this->_carriers . ')
            INNER JOIN ' . _DB_PREFIX_ . 'omniva_order_history ooh
                ON ooh.id_order = a.id_order AND ooh.manifest = -1
            ORDER BY a.id_order DESC';

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: [];
    }

    private function getOrdersCount(string $type): int
    {
        if ($type === 'new') {
            $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'omniva_order_history ooh
                WHERE ooh.manifest = 0';
        } else {
            $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'omniva_order_history ooh
                WHERE ooh.manifest > 0';
        }

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    /*======================== Actions ========================*/

    private function skipOrder(): void
    {
        $id_order = (int) Tools::getValue('orderSkip');
        if ($id_order) {
            $history = OmnivaOrderHistory::getLatestOrderHistory($id_order);
            if ($history && Validate::isLoadedObject($history)) {
                $history->manifest = -1;
                $history->update();
            }
        }
        Tools::redirectAdmin($this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
    }

    private function cancelSkip(): void
    {
        $id_order = (int) Tools::getValue('cancelSkip');
        if ($id_order) {
            $history = OmnivaOrderHistory::getLatestOrderHistory($id_order);
            if ($history && Validate::isLoadedObject($history)) {
                $history->manifest = 0;
                $history->update();
            }
        }
        Tools::redirectAdmin($this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
    }

    /*======================== Pagination Helper ========================*/

    private function assignPagination(int $total, int $page, string $baseUrl, string $varName): void
    {
        $pagesToShow = (int) ceil($total / $this->perPage);
        if ($page > $pagesToShow) {
            $page = 1;
        }

        $endGroup = min($pagesToShow, max($page + 2, 5));
        $startGroup = max(1, $endGroup - 4);

        $this->context->smarty->assign([
            'nb_products' => $total,
            'products_per_page' => $this->perPage,
            'pages_nb' => $pagesToShow,
            'prev_p' => $page > 1 ? $page - 1 : 1,
            'next_p' => min($page + 1, $pagesToShow),
            'requestPage' => $baseUrl,
            'current_url' => $baseUrl,
            'requestNb' => $baseUrl,
            'p' => $page,
            'n' => $this->perPage,
            'start' => $startGroup,
            'stop' => $endGroup,
        ]);

        $this->context->smarty->assign([
            $varName => $this->context->smarty->fetch(
                _PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/pagination.tpl'
            ),
        ]);
    }

    /**
     * Groups flat SQL rows by order ID. Each order gets a 'histories' array
     * with all its history entries. The first history is the latest (ORDER BY ooh.id DESC).
     */
    private function groupOrderHistories(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $id = $row['id_order'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = $row;
                $grouped[$id]['histories'] = [];
            }
            if (!empty($row['history'])) {
                $grouped[$id]['histories'][] = [
                    'id' => $row['history'],
                    'tracking_numbers' => $row['history_tracking_numbers'],
                    'date' => $row['history_date'],
                    'manifest' => (int) ($row['history_manifest'] ?? 0),
                ];
            }
        }
        return array_values($grouped);
    }

    protected function ajaxDie($value = null, $controller = null, $method = null)
    {
        if (method_exists(get_parent_class($this), 'ajaxDie')) {
            parent::ajaxDie($value, $controller, $method);
        } else {
            header('Content-Type: application/json');
            die($value);
        }
    }
}
