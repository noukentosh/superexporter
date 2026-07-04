-- Minimal Joomla schema for SuperExport integration tests (MySQL)
CREATE TABLE IF NOT EXISTS jos_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    alias VARCHAR(400),
    description TEXT,
    parent_id INT DEFAULT 0,
    published TINYINT DEFAULT 1,
    access INT DEFAULT 1,
    extension VARCHAR(50) DEFAULT 'com_content',
    language CHAR(7) DEFAULT '*'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jos_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    alias VARCHAR(400),
    introtext MEDIUMTEXT,
    `fulltext` MEDIUMTEXT,
    state TINYINT DEFAULT 1,
    catid INT DEFAULT 0,
    created DATETIME,
    modified DATETIME,
    created_by_alias VARCHAR(255) DEFAULT '',
    access INT DEFAULT 1,
    language CHAR(7) DEFAULT '*'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO jos_categories (title, alias, description, parent_id)
VALUES ('Joomla Cat', 'joomla-cat', 'Category', 0);
INSERT INTO jos_content (title, alias, introtext, `fulltext`, state, catid, created, modified, created_by_alias)
VALUES ('Joomla Post', 'joomla-post', 'Intro', '<p>Joomla body</p>', 1, 1, NOW(), NOW(), 'Editor');
