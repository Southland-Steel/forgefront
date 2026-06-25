CREATE DATABASE IF NOT EXISTS forgefront CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'forgefront.reporter'@'%' IDENTIFIED BY 'changeme';
GRANT ALL PRIVILEGES ON forgefront.* TO 'forgefront.reporter'@'%';
FLUSH PRIVILEGES;

USE forgefront;

CREATE TABLE sites (
    site_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    abbreviation VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE campuses (
    campus_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE locations (
    location_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campus_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campus_id) REFERENCES campuses(campus_id)
);

CREATE TABLE employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE employee_sites (
    employee_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    PRIMARY KEY (employee_id, site_id),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    FOREIGN KEY (site_id) REFERENCES sites(site_id)
);

CREATE TABLE employee_campuses (
    employee_id INT UNSIGNED NOT NULL,
    campus_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (employee_id, campus_id),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    FOREIGN KEY (campus_id)   REFERENCES campuses(campus_id)
);

CREATE TABLE asset_categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

CREATE TABLE assets (
    asset_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(20) NOT NULL UNIQUE,
    category_id INT UNSIGNED NOT NULL,
    make VARCHAR(100),
    model VARCHAR(100),
    serial_number VARCHAR(150),
    status ENUM('Active','Inactive','In Repair','Retired','Lost') NOT NULL DEFAULT 'Active',
    assigned_employee_id INT UNSIGNED NULL,
    assigned_location_id INT UNSIGNED NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES asset_categories(category_id),
    FOREIGN KEY (assigned_employee_id) REFERENCES employees(employee_id),
    FOREIGN KEY (assigned_location_id) REFERENCES locations(location_id)
);

CREATE TABLE asset_history (
    history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    action ENUM('Created','Assigned','Unassigned','Moved','Status Changed') NOT NULL,
    employee_id INT UNSIGNED NULL,
    location_id INT UNSIGNED NULL,
    changed_by VARCHAR(100),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id)
);

INSERT INTO sites (name, abbreviation) VALUES
('Southland Steel Fabricators', 'SSF'),
('Grid Structures', 'GRID'),
('Solar Pile USA', 'SPUSA'),
('Southland Industrial Coating', 'GALV');

INSERT INTO campuses (name) VALUES ('Amite'), ('Greensburg');

INSERT INTO asset_categories (category_id, name) VALUES
(1, 'Laptop'),
(2, 'Desktop'),
(3, 'Tablet'),
(4, 'Monitor'),
(5, 'Switch'),
(6, 'Router'),
(7, 'Printer'),
(8, 'Phone'),
(9, 'Server'),
(11, 'Dock'),
(12, 'Battery Backup'),
(13, 'Access Point'),
(14, 'Hard Drive'),
(15, 'TV/Display'),
(16, 'Miscellaneous');

CREATE TABLE IF NOT EXISTS servers (
    server_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)               NOT NULL,
    host        VARCHAR(255)               NOT NULL,
    port        INT UNSIGNED               NOT NULL DEFAULT 80,
    protocol    ENUM('tcp','http','https') NOT NULL DEFAULT 'tcp',
    description VARCHAR(255)               NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
