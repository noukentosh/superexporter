-- Minimal OpenCart schema for SuperExport integration tests (MySQL)
CREATE TABLE IF NOT EXISTS oc_language (
    language_id INT PRIMARY KEY,
    name VARCHAR(32),
    code VARCHAR(5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oc_category (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT DEFAULT 0,
    top TINYINT DEFAULT 0,
    `column` INT DEFAULT 1,
    sort_order INT DEFAULT 0,
    status TINYINT DEFAULT 1,
    date_added DATETIME,
    date_modified DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oc_category_description (
    category_id INT,
    language_id INT,
    name VARCHAR(255),
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oc_product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    model VARCHAR(64),
    sku VARCHAR(64) DEFAULT '',
    price DECIMAL(15,4) DEFAULT 0,
    quantity INT DEFAULT 0,
    status TINYINT DEFAULT 1,
    date_added DATETIME,
    date_modified DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oc_product_description (
    product_id INT,
    language_id INT,
    name VARCHAR(255),
    description TEXT,
    meta_description VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oc_product_to_category (
    product_id INT,
    category_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO oc_language (language_id, name, code) VALUES (1, 'English', 'en');
INSERT INTO oc_category (parent_id, status, date_added, date_modified) VALUES (0, 1, NOW(), NOW());
INSERT INTO oc_category_description (category_id, language_id, name, description) VALUES (1, 1, 'Shop Cat', 'Category');
INSERT INTO oc_product (model, sku, price, status, date_added, date_modified)
VALUES ('OC-001', 'SKU1', 19.99, 1, NOW(), NOW());
INSERT INTO oc_product_description (product_id, language_id, name, description, meta_description)
VALUES (1, 1, 'OpenCart Product', '<p>Product body</p>', 'Short desc');
INSERT INTO oc_product_to_category (product_id, category_id) VALUES (1, 1);
