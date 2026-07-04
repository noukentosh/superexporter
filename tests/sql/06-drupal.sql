-- Minimal Drupal schema for SuperExport integration tests (MySQL)
CREATE TABLE IF NOT EXISTS node (
    nid INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(32),
    uuid CHAR(36),
    langcode VARCHAR(12) DEFAULT 'en'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS node_field_data (
    nid INT,
    vid INT,
    type VARCHAR(32),
    langcode VARCHAR(12) DEFAULT 'en',
    status TINYINT DEFAULT 1,
    uid INT DEFAULT 1,
    title VARCHAR(255),
    created INT DEFAULT 0,
    changed INT DEFAULT 0,
    promote TINYINT DEFAULT 1,
    sticky TINYINT DEFAULT 0,
    default_langcode TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS node__body (
    bundle VARCHAR(128),
    deleted TINYINT DEFAULT 0,
    entity_id INT,
    revision_id INT,
    langcode VARCHAR(12) DEFAULT 'en',
    delta INT DEFAULT 0,
    body_value LONGTEXT,
    body_format VARCHAR(32) DEFAULT 'basic_html'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS path_alias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(255),
    alias VARCHAR(255),
    langcode VARCHAR(12) DEFAULT 'en'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS taxonomy_term_data (
    tid INT AUTO_INCREMENT PRIMARY KEY,
    vid VARCHAR(32),
    uuid CHAR(36),
    langcode VARCHAR(12) DEFAULT 'en'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS taxonomy_term_field_data (
    tid INT,
    vid VARCHAR(32),
    langcode VARCHAR(12) DEFAULT 'en',
    name VARCHAR(255),
    description__value LONGTEXT,
    description__format VARCHAR(32) DEFAULT 'basic_html',
    weight INT DEFAULT 0,
    changed INT DEFAULT 0,
    default_langcode TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS taxonomy_index (
    nid INT,
    tid INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO node (type, uuid, langcode) VALUES ('article', '00000000-0000-0000-0000-000000000001', 'en');
INSERT INTO node_field_data (nid, vid, type, status, title, created, changed)
VALUES (1, 1, 'article', 1, 'Drupal Post', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
INSERT INTO node__body (bundle, entity_id, revision_id, body_value) VALUES ('article', 1, 1, '<p>Drupal body</p>');
INSERT INTO path_alias (path, alias) VALUES ('/node/1', '/drupal-post');
INSERT INTO taxonomy_term_data (vid, uuid) VALUES ('category', '00000000-0000-0000-0000-000000000002');
INSERT INTO taxonomy_term_field_data (tid, vid, name, description__value, changed)
VALUES (1, 'category', 'Drupal Cat', 'Term', UNIX_TIMESTAMP());
INSERT INTO taxonomy_index (nid, tid) VALUES (1, 1);
