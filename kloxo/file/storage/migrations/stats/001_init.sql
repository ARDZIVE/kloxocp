-- Daily stats the UI reads. Raw logs stay on disk.
CREATE TABLE domain_daily (
    domain_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    bytes INTEGER NOT NULL DEFAULT 0,
    requests INTEGER NOT NULL DEFAULT 0,
    disk_bytes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, day)
);

CREATE TABLE domain_monthly (
    domain_id INTEGER NOT NULL,
    month TEXT NOT NULL,
    bytes INTEGER NOT NULL DEFAULT 0,
    requests INTEGER NOT NULL DEFAULT 0,
    disk_bytes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, month)
);

-- Incremental log read: inode + byte offset so a second run is a no-op.
CREATE TABLE log_cursor (
    path TEXT PRIMARY KEY,
    inode INTEGER NOT NULL,
    offset INTEGER NOT NULL
);
