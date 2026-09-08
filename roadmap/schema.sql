USE forgefront;

CREATE TABLE roadmap_items (
    item_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('idea','task') NOT NULL DEFAULT 'idea',
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status      ENUM('backlog','in_progress','done','archived') NOT NULL DEFAULT 'backlog',
    priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    start_date  DATE NULL,
    due_date    DATE NULL,
    created_by  VARCHAR(150) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_type_status (type, status),
    KEY idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
