<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaDb
{
    const SQL_GUARD = '89485368X846aModer416xa1656ax1';

    const TABLES = [
        'omniva_order',
        'omniva_cart_terminal',
        'omniva_order_history',
        'omniva_product',
    ];

    public function createTables(): bool
    {
        $sql_path = dirname(__FILE__) . '/../sql/';
        $sql_files = scandir($sql_path);

        foreach ($sql_files as $sql_file) {
            if (pathinfo($sql_file, PATHINFO_EXTENSION) !== 'sql') {
                continue;
            }

            $content = file_get_contents($sql_path . $sql_file);
            $parts = explode(self::SQL_GUARD, $content);

            if (count($parts) !== 2 || $parts[1] !== '') {
                continue;
            }

            $query = str_replace(
                ['_DB_PREFIX_', '_MYSQL_ENGINE_'],
                [_DB_PREFIX_, _MYSQL_ENGINE_],
                $parts[0]
            );

            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    public function deleteTables(): bool
    {
        foreach (self::TABLES as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . pSQL($table));
        }

        return true;
    }
}
