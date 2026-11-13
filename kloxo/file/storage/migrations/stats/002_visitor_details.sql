-- Visitor / page / referrer / status / UA detail (still no raw log lines).
-- domain_identity maps hostnames when the panel uses the legacy domain table.

CREATE TABLE IF NOT EXISTS domain_identity (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
);

ALTER TABLE domain_daily ADD COLUMN visitors INTEGER NOT NULL DEFAULT 0;
ALTER TABLE domain_monthly ADD COLUMN visitors INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS path_daily (
    domain_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    path TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    bytes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, day, path)
);

CREATE TABLE IF NOT EXISTS referrer_daily (
    domain_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    host TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, day, host)
);

CREATE TABLE IF NOT EXISTS status_daily (
    domain_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    status INTEGER NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, day, status)
);

CREATE TABLE IF NOT EXISTS ua_daily (
    domain_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    family TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, day, family)
);

-- Privacy: hashed visitor tokens only (never store raw IPs).
CREATE TABLE IF NOT EXISTS visitor_seen (
    domain_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    token TEXT NOT NULL,
    PRIMARY KEY (domain_id, day, token)
);

CREATE INDEX IF NOT EXISTS idx_path_daily_day ON path_daily (domain_id, day);
CREATE INDEX IF NOT EXISTS idx_referrer_daily_day ON referrer_daily (domain_id, day);
CREATE INDEX IF NOT EXISTS idx_visitor_seen_day ON visitor_seen (domain_id, day);
