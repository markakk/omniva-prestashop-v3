<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaProduct extends ObjectModel
{
    public $id;
    public $id_product;
    public $is_18_plus;
    public $is_fragile;

    public static $definition = [
        'table' => 'omniva_product',
        'primary' => 'id',
        'fields' => [
            'id_product' => ['type' => self::TYPE_INT],
            'is_18_plus' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'is_fragile' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
        ],
    ];

    public static function isExists(int $id_product): bool
    {
        $query = (new DbQuery())
            ->select('id')
            ->from(self::$definition['table'])
            ->where('id_product = ' . (int) $id_product);

        return (bool) Db::getInstance()->getValue($query);
    }

    public static function get18PlusStatus(int $id_product): bool
    {
        $query = (new DbQuery())
            ->select('is_18_plus')
            ->from(self::$definition['table'])
            ->where('id_product = ' . (int) $id_product);

        return (bool) Db::getInstance()->getValue($query);
    }

    public static function getFragileStatus(int $id_product): bool
    {
        $query = (new DbQuery())
            ->select('is_fragile')
            ->from(self::$definition['table'])
            ->where('id_product = ' . (int) $id_product);

        return (bool) Db::getInstance()->getValue($query);
    }

    public static function saveProductSettings(int $id_product, bool $is_18_plus, bool $is_fragile): void
    {
        if (!self::isExists($id_product)) {
            Db::getInstance()->insert(self::$definition['table'], [
                'id_product' => $id_product,
                'is_18_plus' => (int) $is_18_plus,
                'is_fragile' => (int) $is_fragile,
            ]);
        } else {
            Db::getInstance()->update(self::$definition['table'], [
                'is_18_plus' => (int) $is_18_plus,
                'is_fragile' => (int) $is_fragile,
            ], 'id_product = ' . (int) $id_product);
        }
    }
}
