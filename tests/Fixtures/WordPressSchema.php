<?php

declare(strict_types=1);

namespace SuperExport\Tests\Fixtures;

use PDO;

final class WordPressSchema
{
    public static function create(PDO $pdo, string $prefix = 'wp_'): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');

        $pdo->exec("CREATE TABLE {$prefix}users (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            user_login TEXT,
            display_name TEXT
        )");

        $pdo->exec("CREATE TABLE {$prefix}posts (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            post_author INTEGER DEFAULT 1,
            post_date TEXT,
            post_date_gmt TEXT,
            post_content TEXT,
            post_title TEXT,
            post_excerpt TEXT,
            post_status TEXT,
            comment_status TEXT DEFAULT 'closed',
            ping_status TEXT DEFAULT 'closed',
            post_password TEXT DEFAULT '',
            post_name TEXT,
            to_ping TEXT DEFAULT '',
            pinged TEXT DEFAULT '',
            post_modified TEXT,
            post_modified_gmt TEXT,
            post_content_filtered TEXT DEFAULT '',
            post_parent INTEGER DEFAULT 0,
            guid TEXT DEFAULT '',
            menu_order INTEGER DEFAULT 0,
            post_type TEXT,
            post_mime_type TEXT DEFAULT '',
            comment_count INTEGER DEFAULT 0
        )");

        $pdo->exec("CREATE TABLE {$prefix}terms (
            term_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            slug TEXT,
            term_group INTEGER DEFAULT 0
        )");

        $pdo->exec("CREATE TABLE {$prefix}term_taxonomy (
            term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT,
            term_id INTEGER,
            taxonomy TEXT,
            description TEXT,
            parent INTEGER DEFAULT 0,
            count INTEGER DEFAULT 0
        )");

        $pdo->exec("CREATE TABLE {$prefix}term_relationships (
            object_id INTEGER,
            term_taxonomy_id INTEGER,
            term_order INTEGER DEFAULT 0
        )");

        $pdo->exec("CREATE TABLE {$prefix}postmeta (
            meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER,
            meta_key TEXT,
            meta_value TEXT
        )");

        $pdo->exec("INSERT INTO {$prefix}users (user_login, display_name) VALUES ('admin', 'Admin')");
        $pdo->exec("INSERT INTO {$prefix}posts (post_title, post_name, post_content, post_status, post_type, post_date, post_modified)
            VALUES ('Hello World', 'hello-world', '<p>Content</p>', 'publish', 'post', datetime('now'), datetime('now'))");
        $pdo->exec("INSERT INTO {$prefix}terms (name, slug) VALUES ('News', 'news')");
        $pdo->exec("INSERT INTO {$prefix}term_taxonomy (term_id, taxonomy, description, parent) VALUES (1, 'category', 'News cat', 0)");
    }

    public static function writeConfig(string $dir, string $prefix = 'wp_'): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'wp-config.php', <<<PHP
<?php
define('DB_NAME', 'test');
define('DB_USER', 'test');
define('DB_PASSWORD', 'test');
define('DB_HOST', 'localhost');
\$table_prefix = '{$prefix}';
PHP);
    }
}
