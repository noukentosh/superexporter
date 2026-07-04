<?php

declare(strict_types=1);

namespace SuperExport\Tests\Fixtures;

use PDO;

final class JoomlaSchema
{
    public static function create(PDO $pdo, string $prefix = 'jos_'): void
    {
        $pdo->exec("CREATE TABLE {$prefix}categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            alias TEXT,
            description TEXT DEFAULT '',
            parent_id INTEGER DEFAULT 0,
            published INTEGER DEFAULT 1,
            access INTEGER DEFAULT 1,
            extension TEXT DEFAULT 'com_content',
            language TEXT DEFAULT '*'
        )");

        $pdo->exec("CREATE TABLE {$prefix}content (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            alias TEXT,
            introtext TEXT DEFAULT '',
            fulltext TEXT DEFAULT '',
            state INTEGER DEFAULT 1,
            catid INTEGER DEFAULT 0,
            created TEXT,
            modified TEXT,
            created_by_alias TEXT DEFAULT '',
            access INTEGER DEFAULT 1,
            language TEXT DEFAULT '*'
        )");

        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO {$prefix}categories (title, alias, description, parent_id)
            VALUES ('Joomla Cat', 'joomla-cat', 'Category', 0)");
        $pdo->exec("INSERT INTO {$prefix}content (title, alias, introtext, fulltext, state, catid, created, modified, created_by_alias)
            VALUES ('Joomla Post', 'joomla-post', 'Intro', '<p>Joomla body</p>', 1, 1, '{$now}', '{$now}', 'Editor')");
    }

    public static function writeConfig(string $dir, string $prefix = 'jos_'): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . 'configuration.php', <<<PHP
<?php
class JConfig {
    public \$host = 'localhost';
    public \$user = 'test';
    public \$password = 'test';
    public \$db = 'superexport_test';
    public \$dbprefix = '{$prefix}';
    public \$live_site = 'https://joomla.test';
}
PHP);
    }
}
