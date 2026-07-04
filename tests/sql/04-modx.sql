-- Minimal MODX schema for SuperExport integration tests (MySQL)
CREATE TABLE IF NOT EXISTS modx_site_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pagetitle VARCHAR(255),
    alias VARCHAR(255),
    content MEDIUMTEXT,
    introtext TEXT,
    parent INT DEFAULT 0,
    isfolder TINYINT DEFAULT 0,
    published TINYINT DEFAULT 1,
    createdon INT DEFAULT 0,
    editedon INT DEFAULT 0,
    menuindex INT DEFAULT 0,
    deleted TINYINT DEFAULT 0,
    class_key VARCHAR(100) DEFAULT 'modDocument'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS modx_site_tmplvars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS modx_site_tmplvar_contentvalues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tmplvarid INT,
    contentid INT,
    value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO modx_site_content (pagetitle, alias, content, introtext, published, createdon, editedon, isfolder, class_key)
VALUES ('MODX Post', 'modx-post', '<p>MODX content</p>', 'Excerpt', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'modDocument');
