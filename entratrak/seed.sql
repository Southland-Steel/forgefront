-- Starter data for entratrak's known-IP store (SQLite).
-- The app auto-creates the tables on first load; this only seeds the
-- company plant IPs so the Legacy site rollup works out of the box.
-- Import with:  sqlite3 data/known_ips.sqlite < seed.sql
-- (Everything else is populated by the "Harvest" button, per install.)

CREATE TABLE IF NOT EXISTS known_ips (
    ip         TEXT PRIMARY KEY,
    label      TEXT NOT NULL DEFAULT '',
    type       TEXT NOT NULL DEFAULT 'other',
    city       TEXT NOT NULL DEFAULT '',
    state      TEXT NOT NULL DEFAULT '',
    country    TEXT NOT NULL DEFAULT '',
    isp        TEXT NOT NULL DEFAULT '',
    category   TEXT NOT NULL DEFAULT 'other',
    notes      TEXT NOT NULL DEFAULT '',
    added_by   TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ip_users (
    ip        TEXT NOT NULL,
    upn       TEXT NOT NULL,
    name      TEXT NOT NULL DEFAULT '',
    logins    INTEGER NOT NULL DEFAULT 0,
    last_seen TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (ip, upn)
);

-- Company plant egress IPs (edit for your own sites)
INSERT OR IGNORE INTO known_ips (ip, label, type, category, added_by) VALUES
    ('142.190.33.182', 'Greensburg plant', 'plant', 'company', 'seed'),
    ('142.190.33.202', 'Amite plant',      'plant', 'company', 'seed'),
    ('69.63.164.133',  'Washington plant', 'plant', 'company', 'seed');
