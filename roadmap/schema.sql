USE forgefront;

CREATE TABLE roadmap_items (
    item_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('idea','task') NOT NULL DEFAULT 'idea',
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status      ENUM('backlog','in_progress','done','archived') NOT NULL DEFAULT 'backlog',
    priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    assigned_to INT NULL,
    start_date  DATE NULL,
    due_date    DATE NULL,
    created_by  VARCHAR(150) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_type_status (type, status),
    KEY idx_due_date (due_date),
    KEY idx_assigned_to (assigned_to),
    -- ff_users.user_id is a SIGNED int, so assigned_to has to match it.
    CONSTRAINT fk_roadmap_items_assignee FOREIGN KEY (assigned_to) REFERENCES ff_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A goal is a CHILD of a task: it describes part of what that task has to
-- achieve ("Tekla replacement" -> "Implementation of our own fabrication
-- database"). A task can have several. Goals are not on the timeline; the
-- task carries the dates, and each goal is what a whiteboard hangs off.
CREATE TABLE roadmap_goals (
    goal_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id     INT UNSIGNED NOT NULL,
    -- The Excalidraw frame on the task's board that this goal occupies, or
    -- NULL for a goal typed straight into the list that has no region yet.
    -- UNIQUE so two goals cannot claim the same region; MySQL allows repeated
    -- NULLs in a unique index, so any number of goals may have none.
    -- Deliberately NOT a foreign key — it points into the scene JSON, not a
    -- table — so deleting the frame on the canvas ORPHANS the goal rather than
    -- destroying it. An orphaned goal keeps its title and can be re-linked.
    frame_id    VARCHAR(64) NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status      ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
    owner_id    INT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    created_by  VARCHAR(150) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_item_id (item_id),
    KEY idx_owner_id (owner_id),
    UNIQUE KEY uk_roadmap_goals_frame (frame_id),
    -- A goal has no meaning without its task, so it goes when the task goes.
    CONSTRAINT fk_roadmap_goals_item FOREIGN KEY (item_id) REFERENCES roadmap_items (item_id) ON DELETE CASCADE,
    CONSTRAINT fk_roadmap_goals_owner FOREIGN KEY (owner_id) REFERENCES ff_users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ONE whiteboard per task. Goals do not get their own canvas: a goal is a
-- named REGION of its task's board — an Excalidraw frame — recorded in
-- roadmap_goals.frame_id above. You sketch the goals out on the task board,
-- promote each frame into a goal, and the goal's whiteboard button then opens
-- just that region.
--
-- That is why there is no goal_id here. An earlier version of this table
-- carried one board per goal plus one per task; once goals became regions of
-- the task's canvas there was nothing left for a goal-owned board to hold.
--
-- scene_json is an Excalidraw document as produced by serializeAsJSON():
-- {type, version, source, elements, appState, files}. It is stored verbatim
-- rather than shredded into tables — nothing outside the canvas reads an
-- individual shape, and Excalidraw owns the format.
--
-- element_count is maintained by ajax/save_board.php purely so the lists can
-- say whether a board has anything on it without parsing the JSON.
CREATE TABLE roadmap_boards (
    item_id       INT UNSIGNED NOT NULL PRIMARY KEY,
    scene_json    LONGTEXT NULL,
    element_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by    VARCHAR(150) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_roadmap_boards_item FOREIGN KEY (item_id) REFERENCES roadmap_items (item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
