# AGENTS.md: Kloxo CP

**Read `docs/HANDOFF_BRIEFING.md` first**; it is the source of truth for decisions, state and the work plan.

## What this is
Kloxo CP: a hosting control panel forked from Kloxo-MR (AGPLv3), being modernised for Ubuntu 24.04
and Ubuntu 26.x,
Nginx + PHP-FPM default for sites (Apache selectable), Hiawatha for the panel itself, no billing for now, existing UI kept,
mail via Postfix + Dovecot + Rspamd + MariaDB (done and tested), easy install/update with automatic
rollback (designed, not built).

## Status
0.1.0-dev. NOT safe to run. The legacy PHP does not run on PHP 8.3. Audit C1–C5 for phase 1a
(`process_server_input` auth, daemon bind/TLS/size, committed keys, setuid helpers, easyinstaller
HTTP) are closed. P1 web/DNS shrink is done (Nginx + panel Hiawatha; BIND/PowerDNS; qmail still
present). P2 first-party `php -l` is clean and `include.php` loads on PHP 8 (`tests/p2/test_boot.php`).
P3 CLI skeleton is in (`kloxo/bin/kloxocp`: status, doctor, snapshot, rollback).
`install.sh` bootstraps Ubuntu 24.04 or 26.x from https://github.com/ARDZIVE/kloxocp.git
(Hiawatha 12.4 source build + `init-panel-db`; MariaDB 11.8 row-size rewrite;
the README one-liner fetches `origin/main` and hard-resets when the clone already exists;
`kloxocp update` and signed apt packaging are not). Hiawatha is not an apt package. Default panel login is admin/admin; the UI requires a password
change after first login (required Change Password modal with the form
inside; Esc/Close cannot skip it). Admin Servers list seeds `localhost`. The Update
screen shows the local `kloxoversion` (`kloxocp update` still exits 2).
Switch Program DNS default is BIND (`pg_dns=bind`); PowerDNS is packaged and
prepared (`install-pdns`) but the `pdns` unit stays disabled until selected.
Web default is Nginx (`pg_web=nginx`); Apache is packaged and prepared
(`install-apache`, PHP-FPM via proxy_fcgi, no mod_php) but `apache2` stays
disabled until selected. Admin SQL Manager opens phpMyAdmin via panel
sign-on (`kloxo_pma`, all databases; no password in the URL). Component Info
lists Ubuntu packages (nginx, apache2, bind9, pdns-server, mariadb-server,
postfix, dovecot, rspamd, phpX.Y-cli/fpm) plus source-built Hiawatha, with
on/off from dpkg. SMTP `none` / `exim`
(qmail-toaster is no longer the selectable MTA). PHP Module Status
reads Ubuntu `mods-available`. Empty allowedip/blockedip lists no longer
`count(null)` after login. Install seeds a `localhost` driver row and
`generalmisc_b` so the home page does not fatal on a missing driver or
null `used`. Panel FPM hides PHP errors (`log/php.err`). The middle column
is no longer dropped by splash `flush()`/`ob_start()` or an
uncaught PHP 8 `Error` after the header. Dashboard tiles and the
simplicity top-bar items after Server (Security, Basic, Task,
Help, Logout) no longer vanish on a missing action description.
Status, Show/Hide and the clock stay on the top right in one row.
The green menu strip fills the middle of the red bar (Home…Task on
the left of the green, Help/Logout on the right of the green, still
immediately left of Status). Content tabs sit on the beige header
like Kloxo-MR; the logo sits on the right of the screen.
The content white panel fills to the bottom of the page.
Customer sites default to the newest installed PHP-FPM; 5.6.40 and 7.4.33
are installed beside it (`install-site-php`, ppa:ondrej/php) and can be
chosen on Webserver Configure (PHP Used / PHP Branch list php8.3, php7.4,
php5.6 — not php52m). The panel itself stays on distro PHP. Log/driver/php/backup/webserver/SSH Configure/LxGuard/SSH Terminal/File Manager show pages
omit the left Find box. LxGuard list tabs (connections, raw hits, whitelist)
also omit the Find/search input. SSH Terminal is admin-only (under the server, not client
home) and shows a copyable `ssh` command. Install seeds a Welcome HelpDesk ticket (Created By Kloxo). List/driver calls guard a missing
Ubuntu driver so the page does not TypeError. Re Read IPaddress
uses `ipaddress::fixstatus` and live `ip -4 addr` on Ubuntu.
Directory Index list does not `count()` a missing `dirindexlist_a`.
Cron Tasks Mail To does not need the C daemon on TCP 7776.
DNS Template links list `dnstemplate`, not Backup/Restore.
FTP Configure is `serverftp` under localhost, not Backup FTP Configuration.
FTP Sessions lists `ftpsession` under localhost `pserver`, not a parentless client list.
Admin Servers can add a remote host (hostname or IPv4 plus the slave password)
on the list page; there is no separate Add Server tab. Adding a client does
not implode() a string (contact email / coma_* lists). Adding a Resource Plan
does not need a DNS template, does not TypeError on a missing listpriv, and
accepts a display name with spaces (sanitized to a plan id).
`install.sh --slave` writes `slave-db.db` and starts `kloxo-backend` on TLS 7779
(all interfaces); plaintext 7776 stays on localhost. Live two-host add is unverified.
Home keeps
Find / Information / Resource on the left and the dashboard tiles
on the right.
First-party `ereg*`/`split`
and live theme `escape`/`document.all` are gone. Browser login as
admin/admin against the live panel succeeded; every page still died
in the Server menu at the SSH Client title until `kloxo_menu_title`
and `install.sh` git-pull/rsync. Re-run install after pulling this
tree, then re-check Home.
Next: remaining P2 runtime, Debian packaging, or P4 postfix UI. See briefing
section 7.

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
bash tests/install/run-tests.sh       # Ubuntu schema + Hiawatha installer invariants (php-cli only)
php kloxo/bin/install/init-panel-db --help
php kloxo/bin/install/init-slave --help
php kloxo/bin/install/install-pdns --help
php kloxo/bin/install/install-apache --help
bash kloxo/bin/install/install-hiawatha --help
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
