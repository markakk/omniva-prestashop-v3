<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaCartTerminal extends ObjectModel
{
    public $id;
    public $id_terminal;

    public static $definition = [
        'table' => 'omniva_cart_terminal',
        'primary' => 'id_cart',
        'fields' => [
            'id_terminal' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 50],
        ],
    ];
}
