<?php

declare(strict_types=1);

namespace SuperExport\Tests\Fixtures;

use PDO;

final class BitrixSchema
{
    public static function create(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE b_iblock (
            ID INTEGER PRIMARY KEY,
            IBLOCK_TYPE_ID TEXT,
            CODE TEXT,
            NAME TEXT,
            ACTIVE TEXT DEFAULT \'Y\'
        )');
        $pdo->exec('CREATE TABLE b_iblock_element (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            IBLOCK_ID INTEGER,
            NAME TEXT,
            CODE TEXT,
            DETAIL_TEXT TEXT,
            PREVIEW_TEXT TEXT,
            ACTIVE TEXT DEFAULT \'Y\',
            DATE_CREATE TEXT,
            TIMESTAMP_X TEXT,
            IBLOCK_SECTION_ID INTEGER DEFAULT 0,
            SORT INTEGER DEFAULT 500
        )');
        $pdo->exec('CREATE TABLE b_iblock_section (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            IBLOCK_ID INTEGER,
            NAME TEXT,
            CODE TEXT,
            DESCRIPTION TEXT,
            IBLOCK_SECTION_ID INTEGER DEFAULT 0
        )');
        $pdo->exec('CREATE TABLE b_iblock_property (
            ID INTEGER PRIMARY KEY,
            CODE TEXT,
            IBLOCK_ID INTEGER
        )');
        $pdo->exec('CREATE TABLE b_iblock_element_property (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            IBLOCK_ELEMENT_ID INTEGER,
            IBLOCK_PROPERTY_ID INTEGER,
            VALUE TEXT
        )');

        $pdo->exec("INSERT INTO b_iblock (ID, IBLOCK_TYPE_ID, CODE, NAME, ACTIVE) VALUES (1, 'news', 'news', 'News', 'Y')");
        $pdo->exec("INSERT INTO b_iblock (ID, IBLOCK_TYPE_ID, CODE, NAME, ACTIVE) VALUES (2, 'services', 'services', 'Services', 'Y')");
        $pdo->exec("INSERT INTO b_iblock_section (IBLOCK_ID, NAME, CODE, DESCRIPTION) VALUES (1, 'Bitrix Cat', 'bitrix-cat', 'Section')");
        $pdo->exec("INSERT INTO b_iblock_element (IBLOCK_ID, NAME, CODE, DETAIL_TEXT, PREVIEW_TEXT, ACTIVE, DATE_CREATE, TIMESTAMP_X, IBLOCK_SECTION_ID)
            VALUES (1, 'Bitrix Post', 'bitrix-post', '<p>From Bitrix</p>', 'Excerpt', 'Y', datetime('now'), datetime('now'), 1)");
        $pdo->exec("INSERT INTO b_iblock_element (IBLOCK_ID, NAME, CODE, DETAIL_TEXT, PREVIEW_TEXT, ACTIVE, DATE_CREATE, TIMESTAMP_X)
            VALUES (2, 'Service Item', 'service-item', '<p>Custom iblock</p>', 'Excerpt', 'Y', datetime('now'), datetime('now'))");
    }

    public static function writeConfig(string $dir): void
    {
        $bitrixDir = $dir . DIRECTORY_SEPARATOR . 'bitrix';
        if (!is_dir($bitrixDir)) {
            mkdir($bitrixDir, 0775, true);
        }

        $settings = <<<'PHP'
<?php
return [
    'connections' => [
        'value' => [
            'default' => [
                'host' => 'localhost',
                'database' => 'test',
                'login' => 'test',
                'password' => 'test',
            ],
        ],
    ],
];
PHP;
        file_put_contents($bitrixDir . DIRECTORY_SEPARATOR . '.settings.php', $settings);
    }
}
