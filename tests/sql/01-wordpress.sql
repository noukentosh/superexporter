-- Minimal WordPress schema for SuperExport integration tests (MySQL)
CREATE TABLE IF NOT EXISTS wp_users (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    user_login VARCHAR(60),
    display_name VARCHAR(250)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_posts (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    post_author INT DEFAULT 1,
    post_date DATETIME,
    post_date_gmt DATETIME,
    post_content LONGTEXT,
    post_title TEXT,
    post_excerpt TEXT,
    post_status VARCHAR(20),
    comment_status VARCHAR(20) DEFAULT 'closed',
    ping_status VARCHAR(20) DEFAULT 'closed',
    post_password VARCHAR(255) DEFAULT '',
    post_name VARCHAR(200),
    to_ping TEXT,
    pinged TEXT,
    post_modified DATETIME,
    post_modified_gmt DATETIME,
    post_content_filtered LONGTEXT,
    post_parent INT DEFAULT 0,
    guid VARCHAR(255) DEFAULT '',
    menu_order INT DEFAULT 0,
    post_type VARCHAR(20),
    post_mime_type VARCHAR(100) DEFAULT '',
    comment_count INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_terms (
    term_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200),
    slug VARCHAR(200),
    term_group INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_term_taxonomy (
    term_taxonomy_id INT AUTO_INCREMENT PRIMARY KEY,
    term_id INT,
    taxonomy VARCHAR(32),
    description LONGTEXT,
    parent INT DEFAULT 0,
    count INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_term_relationships (
    object_id INT,
    term_taxonomy_id INT,
    term_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_postmeta (
    meta_id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT,
    meta_key VARCHAR(255),
    meta_value LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wp_users (user_login, display_name) VALUES ('admin', 'Admin');
INSERT INTO wp_posts (post_title, post_name, post_content, post_status, post_type, post_date, post_modified)
VALUES ('Hello World', 'hello-world', '<p>Content</p>', 'publish', 'post', NOW(), NOW());
INSERT INTO wp_terms (name, slug) VALUES ('News', 'news');
INSERT INTO wp_term_taxonomy (term_id, taxonomy, description, parent) VALUES (1, 'category', 'News cat', 0);
