<?php

declare(strict_types=1);

namespace SuperExport\Tests\Fixtures;

use PDO;

final class DrupalSchema
{
    public static function create(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE node (
            nid INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            uuid TEXT,
            langcode TEXT DEFAULT \'en\'
        )');

        $pdo->exec('CREATE TABLE node_field_data (
            nid INTEGER,
            vid INTEGER,
            type TEXT,
            langcode TEXT DEFAULT \'en\',
            status INTEGER DEFAULT 1,
            uid INTEGER DEFAULT 1,
            title TEXT,
            created INTEGER DEFAULT 0,
            changed INTEGER DEFAULT 0,
            promote INTEGER DEFAULT 1,
            sticky INTEGER DEFAULT 0,
            default_langcode INTEGER DEFAULT 1
        )');

        $pdo->exec('CREATE TABLE node__body (
            bundle TEXT,
            deleted INTEGER DEFAULT 0,
            entity_id INTEGER,
            revision_id INTEGER,
            langcode TEXT DEFAULT \'en\',
            delta INTEGER DEFAULT 0,
            body_value TEXT,
            body_format TEXT DEFAULT \'basic_html\'
        )');

        $pdo->exec('CREATE TABLE path_alias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path TEXT,
            alias TEXT,
            langcode TEXT DEFAULT \'en\'
        )');

        $pdo->exec('CREATE TABLE taxonomy_term_data (
            tid INTEGER PRIMARY KEY AUTOINCREMENT,
            vid TEXT,
            uuid TEXT,
            langcode TEXT DEFAULT \'en\'
        )');

        $pdo->exec('CREATE TABLE taxonomy_term_field_data (
            tid INTEGER,
            vid TEXT,
            langcode TEXT DEFAULT \'en\',
            name TEXT,
            description__value TEXT DEFAULT \'\',
            description__format TEXT DEFAULT \'basic_html\',
            weight INTEGER DEFAULT 0,
            changed INTEGER DEFAULT 0,
            default_langcode INTEGER DEFAULT 1
        )');

        $pdo->exec('CREATE TABLE taxonomy_index (
            nid INTEGER,
            tid INTEGER
        )');

        $now = time();
        $pdo->exec("INSERT INTO node (type, uuid, langcode) VALUES ('article', '00000000-0000-0000-0000-000000000001', 'en')");
        $pdo->exec("INSERT INTO node_field_data (nid, vid, type, status, title, created, changed)
            VALUES (1, 1, 'article', 1, 'Drupal Post', {$now}, {$now})");
        $pdo->exec("INSERT INTO node__body (bundle, entity_id, revision_id, body_value)
            VALUES ('article', 1, 1, '<p>Drupal body</p>')");
        $pdo->exec("INSERT INTO path_alias (path, alias) VALUES ('/node/1', '/drupal-post')");
        $pdo->exec("INSERT INTO taxonomy_term_data (vid, uuid) VALUES ('category', '00000000-0000-0000-0000-000000000002')");
        $pdo->exec("INSERT INTO taxonomy_term_field_data (tid, vid, name, description__value, changed)
            VALUES (1, 'category', 'Drupal Cat', 'Term', {$now})");
        $pdo->exec('INSERT INTO taxonomy_index (nid, tid) VALUES (1, 1)');
    }

    public static function writeConfig(string $dir): void
    {
        $settingsDir = $dir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default';
        if (!is_dir($settingsDir)) {
            mkdir($settingsDir, 0775, true);
        }

        file_put_contents($settingsDir . DIRECTORY_SEPARATOR . 'settings.php', <<<'PHP'
<?php
$databases['default']['default'] = array (
  'database' => 'superexport_test',
  'username' => 'test',
  'password' => 'test',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
);
PHP);
    }
}
