-- Kloxo CP mail database (MariaDB 10.6+)
-- Separate from the panel database on purpose: a panel schema change or a panel DB
-- problem must never be able to affect mail flow.
-- Read by Postfix (proxy:mysql) and Dovecot with a SELECT-only account; written by
-- `kloxo-mail` with a read-write account.

CREATE TABLE IF NOT EXISTS schema_version (
  version INT UNSIGNED NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS domains (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(253) NOT NULL,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  owner         VARCHAR(64)  NULL COMMENT 'panel client name (informational)',
  max_mailboxes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  default_quota_mb INT UNSIGNED NOT NULL DEFAULT 1024,
  dkim_selector VARCHAR(63)  NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_domain_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS mailboxes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_id     INT UNSIGNED NOT NULL,
  local_part    VARCHAR(64)  NOT NULL,
  email         VARCHAR(320) NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'Dovecot scheme prefix, e.g. {ARGON2ID}$argon2id$...',
  quota_mb      INT UNSIGNED NOT NULL DEFAULT 1024 COMMENT '0 = unlimited',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mailbox_email (email),
  KEY ix_mailbox_domain (domain_id),
  CONSTRAINT fk_mailbox_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An alias is a source address ('user@example.com' or the catch-all '@example.com')
-- with one or more targets (local mailboxes or external addresses = forwarders).
CREATE TABLE IF NOT EXISTS aliases (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_id  INT UNSIGNED NOT NULL,
  source     VARCHAR(320) NOT NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias_source (source),
  KEY ix_alias_domain (domain_id),
  CONSTRAINT fk_alias_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS alias_targets (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alias_id  INT UNSIGNED NOT NULL,
  target    VARCHAR(320) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias_target (alias_id, target),
  CONSTRAINT fk_target_alias FOREIGN KEY (alias_id) REFERENCES aliases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO schema_version (version) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM schema_version);
