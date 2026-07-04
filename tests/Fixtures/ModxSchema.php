<?php

declare(strict_types=1);

namespace SuperExport\Tests\Fixtures;

use PDO;

final class ModxSchema
{
    public static function create(PDO $pdo, string $prefix = 'modx_'): void
    {
        $pdo->exec("CREATE TABLE {$prefix}site_content (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pagetitle TEXT,
            alias TEXT,
            content TEXT DEFAULT '',
            introtext TEXT DEFAULT '',
            parent INTEGER DEFAULT 0,
            isfolder INTEGER DEFAULT 0,
            published INTEGER DEFAULT 1,
            createdon INTEGER DEFAULT 0,
            editedon INTEGER DEFAULT 0,
            menuindex INTEGER DEFAULT 0,
            deleted INTEGER DEFAULT 0,
            class_key TEXT DEFAULT 'modDocument'
        )");

        $pdo->exec("CREATE TABLE {$prefix}site_tmplvars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT
        )");

        $pdo->exec("CREATE TABLE {$prefix}site_tmplvar_contentvalues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tmplvarid INTEGER,
            contentid INTEGER,
            value TEXT
        )");

        $now = time();
        $pdo->exec("INSERT INTO {$prefix}site_content (pagetitle, alias, content, introtext, published, createdon, editedon, isfolder, class_key)
            VALUES ('MODX Post', 'modx-post', '<p>MODX content</p>', 'Excerpt', 1, {$now}, {$now}, 0, 'modDocument')");
    }

    public static function writeConfig(string $dir, string $prefix = 'modx_'): void
    {
        $configDir = $dir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0775, true);
        }

        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'config.inc.php', <<<PHP
<?php
\$database_type = 'mysql';
\$database_server = 'localhost';
\$database_user = 'test';
\$database_password = 'test';
\$dbase = 'superexport_test';
\$table_prefix = '{$prefix}';
\$site_url = 'https://modx.test/';
PHP);
    }
}
