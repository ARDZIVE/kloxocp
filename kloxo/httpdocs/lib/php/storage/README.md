# Kloxo CP storage

Panel config, stats and Hiawatha config files. Raw access/error logs stay
on disk; they are never stored in SQLite.

## Layout

| Path | Role |
|---|---|
| `/var/lib/kloxo/panel.sqlite` | Users, domains, DNS, mail accounts, vhosts, settings |
| `/var/lib/kloxo/stats.sqlite` | Daily/monthly bandwidth and disk usage |
| `/var/lib/kloxo/backup/` | Nightly `VACUUM INTO` copies (last 7 kept) |
| `/var/log/kloxo/domains/<domain>/access.log` | Per-domain access log (logrotate) |
| `/var/log/kloxo/domains/<domain>/error.log` | Per-domain error log |
| `/var/run/kloxo/regen.lock` | Exclusive flock while regenerating Hiawatha config |
| `kloxo/file/storage/migrations/panel/` | Versioned SQL, applied with `PRAGMA user_version` |
| `kloxo/file/storage/migrations/stats/` | Same for the stats database |

Override the data directory with `KLOXO_VAR_LIB` (tests) or `--data-dir`.

## Rules

- One PDO factory (`KloxoStore`). Every connection sets WAL, a 5s busy
  timeout, foreign keys, and NORMAL sync (see comments on those pragmas).
- Prepared statements only in this layer. Multi-table changes run in a
  transaction (deleting a domain removes DNS, mail and vhost rows with it).
- Directory mode 0700, database files 0600, owner `lxlabs`, local disk only.
- Hiawatha never talks to SQLite. The panel renders flat files, validates
  with `hiawatha -k -c`, then `rename()`s into place.
- The UI reads stats tables only, never the raw logs.

## Commands

```
php kloxo/bin/storage/kloxo-migrate
php kloxo/bin/storage/kloxo-regen
php kloxo/bin/storage/kloxo-stats
php kloxo/bin/storage/kloxo-backup-sqlite
```
