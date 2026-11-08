-- Panel config: users, domains, DNS, mail, vhosts, settings.
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'client' CHECK (role IN ('admin', 'reseller', 'client')),
    active INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL
);

CREATE TABLE domains (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    document_root TEXT NOT NULL DEFAULT '',
    php_version TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL
);

CREATE TABLE dns_records (
    id INTEGER PRIMARY KEY,
    domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    content TEXT NOT NULL,
    ttl INTEGER NOT NULL DEFAULT 3600,
    prio INTEGER
);

CREATE INDEX idx_dns_domain ON dns_records (domain_id);

CREATE TABLE mail_accounts (
    id INTEGER PRIMARY KEY,
    domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    local_part TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    quota_mb INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    UNIQUE (domain_id, local_part)
);

CREATE TABLE vhosts (
    id INTEGER PRIMARY KEY,
    domain_id INTEGER NOT NULL UNIQUE REFERENCES domains(id) ON DELETE CASCADE,
    website_root TEXT NOT NULL,
    start_file TEXT NOT NULL DEFAULT 'index.php',
    ssl INTEGER NOT NULL DEFAULT 0,
    extra TEXT NOT NULL DEFAULT ''
);

CREATE TABLE settings (
    "key" TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
