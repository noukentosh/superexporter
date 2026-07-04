<?php

declare(strict_types=1);

namespace SuperExport\Tests\Fixtures;

use PDO;

final class OpenCartSchema
{
    public static function create(PDO $pdo, string $prefix = 'oc_'): void
    {
        $pdo->exec("CREATE TABLE {$prefix}language (
            language_id INTEGER PRIMARY KEY,
            name TEXT,
            code TEXT
        )");

        $pdo->exec("CREATE TABLE {$prefix}category (
            category_id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER DEFAULT 0,
            top INTEGER DEFAULT 0,
            column INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            status INTEGER DEFAULT 1,
            date_added TEXT,
            date_modified TEXT
        )");

        $pdo->exec("CREATE TABLE {$prefix}category_description (
            category_id INTEGER,
            language_id INTEGER,
            name TEXT,
            description TEXT DEFAULT ''
        )");

        $pdo->exec("CREATE TABLE {$prefix}product (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model TEXT,
            sku TEXT DEFAULT '',
            price REAL DEFAULT 0,
            quantity INTEGER DEFAULT 0,
            status INTEGER DEFAULT 1,
            date_added TEXT,
            date_modified TEXT
        )");

        $pdo->exec("CREATE TABLE {$prefix}product_description (
            product_id INTEGER,
            language_id INTEGER,
            name TEXT,
            description TEXT DEFAULT '',
            meta_description TEXT DEFAULT ''
        )");

        $pdo->exec("CREATE TABLE {$prefix}product_to_category (
            product_id INTEGER,
            category_id INTEGER
        )");

        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO {$prefix}language (language_id, name, code) VALUES (1, 'English', 'en')");
        $pdo->exec("INSERT INTO {$prefix}category (parent_id, status, date_added, date_modified)
            VALUES (0, 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO {$prefix}category_description (category_id, language_id, name, description)
            VALUES (1, 1, 'Shop Cat', 'Category')");
        $pdo->exec("INSERT INTO {$prefix}product (model, sku, price, status, date_added, date_modified)
            VALUES ('OC-001', 'SKU1', 19.99, 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO {$prefix}product_description (product_id, language_id, name, description, meta_description)
            VALUES (1, 1, 'OpenCart Product', '<p>Product body</p>', 'Short desc')");
        $pdo->exec("INSERT INTO {$prefix}product_to_category (product_id, category_id) VALUES (1, 1)");
    }

    public static function writeConfig(string $dir, string $prefix = 'oc_'): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . 'config.php', <<<PHP
<?php
define('HTTP_SERVER', 'https://opencart.test/');
define('DB_HOSTNAME', 'localhost');
define('DB_USERNAME', 'test');
define('DB_PASSWORD', 'test');
define('DB_DATABASE', 'superexport_test');
define('DB_PREFIX', '{$prefix}');
PHP);
    }
}
