# AGENTS.md: Kloxo CP

**Read `docs/HANDOFF_BRIEFING.md` first**; it is the source of truth for decisions, state and the work plan.

## What this is
Kloxo CP: a hosting control panel forked from Kloxo-MR (AGPLv3), being modernised for Ubuntu 24.04,
Nginx + PHP-FPM only for sites, Hiawatha for the panel itself, no billing for now, existing UI kept,
mail via Postfix + Dovecot + Rspamd + MariaDB (done and tested), easy install/update with automatic
rollback (designed, not built).

## Status
0.1.0-dev. NOT safe to run. The legacy PHP does not run on PHP 8.3. Audit C1–C5 for phase 1a
(`process_server_input` auth, daemon bind/TLS/size, committed keys, setuid helpers, easyinstaller
HTTP) are closed. P1 web/DNS shrink is done (Nginx + panel Hiawatha; BIND/PowerDNS; qmail still
present). P2 first-party `php -l` is clean and `include.php` loads on PHP 8 (`tests/p2/test_boot.php`).
P3 CLI skeleton is in (`kloxo/bin/kloxocp`: status, doctor, snapshot, rollback).
`install.sh` bootstraps Ubuntu 24.04 from https://github.com/ARDZIVE/kloxocp.git
(`update` and signed apt packaging are not). Browser login against MariaDB is not
verified. Next: remaining P2 runtime, Debian packaging, or P4 postfix UI. See
briefing section 7.

## Commands
```
php -l <file>                         # lint with PHP 8.3
bash tests/tls/run-tests.sh           # generate-tls-keys checks (php-cli + openssl; safe locally)
php kloxo/bin/install/generate-tls-keys --help
bash tests/remote/run-tests.sh        # C1 auth-before-unserialize checks (php-cli only)
bash tests/security/run-tests.sh      # P0.4/P0.5 source invariants (php-cli only)
bash tests/p1/run-tests.sh            # P1 stack-shrink source invariants (php-cli only)
bash tests/p2/run-tests.sh            # P2 first-party PHP 8 parse + autoload (php-cli only)
bash tests/p3/run-tests.sh            # P3 kloxocp CLI + snapshot/rollback (php-cli only)
bash tests/mail/run-tests.sh          # 73 mail checks (needs MariaDB root socket; disposable machine only)
python3 tests/mail/e2e.py             # 25 live mail checks (needs running Postfix/Dovecot/Rspamd; see tests/mail/README.md)
php kloxo/bin/mail/kloxo-mail help
php kloxo/bin/kloxocp help            # status, doctor, snapshot, rollback; update exits 2
```

## Hard rules
- Never weaken the security defaults in `docs/MAIL_DESIGN.md` section 3 or `docs/AUDIT_REPORT.md`.
- Do not mass-rename `lx*` identifiers, DB columns or the `lxlabs` service account.
- Keep AGPLv3 notices and `NOTICE`; no secrets or private keys in the repo.
- New code: PHP 8.3, `declare(strict_types=1)`, prepared statements, no shell strings, validated input, tests.
- Service config changes: render, validate with the service's own checker, atomic install, roll back on failure.
- Say exactly what you tested and what you did not. Update `docs/` as you go.
