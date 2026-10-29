# AGENTS.md: Kloxo CP

**Read `docs/HANDOFF_BRIEFING.md` first**; it is the source of truth for decisions, state and the work plan.

## What this is
Kloxo CP: a hosting control panel forked from Kloxo-MR (AGPLv3), being modernised for Ubuntu 24.04,
Nginx + PHP-FPM only for sites, Hiawatha for the panel itself, no billing for now, existing UI kept,
mail via Postfix + Dovecot + Rspamd + MariaDB (done and tested), easy install/update with automatic
rollback (designed, not built).

## Status
0.1.0-dev. NOT safe to run. The legacy PHP does not run on PHP 8.3. Audit C1 (unauthenticated
unserialize in `process_server_input`), C3 (committed keys) and C4 (setuid helpers) are closed.
C2 and C5 remain (see `docs/AUDIT_REPORT.md` section 3 and briefing section 4).
Next: P0 items 4–5 in briefing section 7.

## Commands
```
php -l <file>                         # lint with PHP 8.3
bash tests/tls/run-tests.sh           # generate-tls-keys checks (php-cli + openssl; safe locally)
php kloxo/bin/install/generate-tls-keys --help
bash tests/remote/run-tests.sh        # C1 auth-before-unserialize checks (php-cli only)
bash tests/mail/run-tests.sh          # 73 mail checks (needs MariaDB root socket; disposable machine only)
python3 tests/mail/e2e.py             # 25 live mail checks (needs running Postfix/Dovecot/Rspamd; see tests/mail/README.md)
php kloxo/bin/mail/kloxo-mail help
```

## Hard rules
- Never weaken the security defaults in `docs/MAIL_DESIGN.md` section 3 or `docs/AUDIT_REPORT.md`.
- Do not mass-rename `lx*` identifiers, DB columns or the `lxlabs` service account.
- Keep AGPLv3 notices and `NOTICE`; no secrets or private keys in the repo.
- New code: PHP 8.3, `declare(strict_types=1)`, prepared statements, no shell strings, validated input, tests.
- Service config changes: render, validate with the service's own checker, atomic install, roll back on failure.
- Say exactly what you tested and what you did not. Update `docs/` as you go.
