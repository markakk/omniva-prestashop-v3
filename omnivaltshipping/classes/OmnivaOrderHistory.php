<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaOrderHistory extends ObjectModel
{
    public $id;
    public $id_order;
    public $service_code;
    public $tracking_numbers;
    public $manifest;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'omniva_order_history',
        'primary' => 'id',
        'fields' => [
            'id_order' => ['type' => self::TYPE_INT, 'size' => 10],
            'service_code' => ['type' => self::TYPE_STRING, 'size' => 64],
            'tracking_numbers' => ['type' => self::TYPE_STRING, 'size' => 512],
            'manifest' => ['type' => self::TYPE_INT, 'size' => 10],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public static function getHistoryByOrder(int $id_order): array
    {
        $query = (new DbQuery())
            ->select('id')
            ->from(self::$definition['table'])
            ->where('id_order = ' . (int) $id_order);

        $results = Db::getInstance()->executeS($query);

        return array_map(
            fn($row) => new self((int) $row['id']),
            $results ?: []
        );
    }

    public static function getLatestOrderHistory(int $id_order): ?self
    {
        $query = (new DbQuery())
            ->select('id')
            ->from(self::$definition['table'])
            ->where('id_order = ' . (int) $id_order)
            ->orderBy('id DESC');

        $id = Db::getInstance()->getValue($query);

        return $id ? new self((int) $id) : null;
    }

    public static function getManifestOrders(): array
    {
        $query = (new DbQuery())
            ->select('id, id_order')
            ->from(self::$definition['table'])
            ->where('manifest = 0')
            ->where('tracking_numbers IS NOT NULL')
            ->where('tracking_numbers != ""');

        $entries = Db::getInstance()->executeS($query);
        if (!$entries) {
            return [];
        }

        $unique = [];
        foreach ($entries as $entry) {
            $unique[$entry['id_order']] = (int) $entry['id'];
        }

        return array_values($unique);
    }
}
