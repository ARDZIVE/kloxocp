# Kloxo CP

Hosting control panel for **Ubuntu 24.04** (PHP 8.3) and **Ubuntu 26.x** (PHP 8.5).
GNU AGPLv3. Version **10.1-2026111210**.

https://github.com/ARDZIVE/kloxocp.git

**Not for production.** Do not expose it on a public network until you have
hardened it and verified the install on your own host.

## Stack

| Role | Default | Also available |
|---|---|---|
| Sites | Nginx + PHP-FPM | Apache 2.4 + PHP-FPM (`proxy_fcgi`) |
| Site PHP | Newest installed `phpX.Y-fpm` | Extra versions from [Sury](https://packages.sury.org/php/) |
| Panel UI | Hiawatha 12.4 (ports 7777 / 7778) | — |
| Panel PHP | Distro PHP with `pdo_sqlite` (pinned in `etc/path/php`) | — |
| DNS | BIND 9 | Knot DNS |
| Mail | Exim + Dovecot + Rspamd | Postfix (replaces Exim) |
| SQL | MariaDB | phpMyAdmin (SQL Manager) |
| FTP / cache / firewall | Pure-FTPd, Redis, UFW, Fail2Ban | Nginx web cache in front of the site server |
| Panel database | SQLite (`/var/lib/kloxo/panel.sqlite`) | — |

No billing. Mailboxes live under `/var/vmail`. Switch Program chooses web, DNS,
SMTP, IMAP and spam. Apache and Knot stay installed but disabled until selected.

## Install

Fresh **Ubuntu 24.04 or 26.x**, as root, at least ~2 GB RAM and 10 GB disk.
Do not install on a machine that already runs another control panel.

```bash
curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/get | sudo bash
```

That is the whole install. No questions, no hostname quiz, no service picker.
Full stack by default (Nginx, MariaDB, Exim, Dovecot, Rspamd, BIND, Pure-FTPd,
UFW, Fail2Ban). Progress prints as `[1/8] … [8/8]`. Log:
`/var/log/kloxo-install.log`.

| Flag | Use |
|---|---|
| *(none)* | Full stack |
| `--profile=core` | Panel + sites, no MTA packages |
| `--force` | Below the RAM/disk floor |
| `--fresh --yes` | Wipe the previous panel first, then install |
| `--slave --slave-password=SECRET` | Backend host, no UI (open TCP 7779 from the master) |

Pass flags after `bash -s --`:

```bash
# Full stack (same as no flags)
curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/get | sudo bash

# Panel + sites only
curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/get | sudo bash -s -- --profile=core

# Wipe previous panel install, then reinstall (keeps sites, mail, UFW, apt packages)
curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/get | sudo bash -s -- --fresh --yes
```

`--fresh` removes the program, panel SQLite, panel MariaDB accounts and saved
passwords, then reinstalls. Customer sites, `/var/vmail`, customer databases,
UFW rules and apt packages are kept.

To install this working tree instead of GitHub `main`, copy it to the server
and run `sudo bash get` (or `sudo bash install.sh`) from that directory.

### First login

Open `https://<ip>:7777/` (self-signed certificate). Username **admin**,
password **admin** (also in `/root/kloxo-admin-password` on a first install).
The UI requires a password change before anything else.

If the login page shows logos but no form, or a red “Panel database is not ready”
hint, the panel PHP lacks `pdo_sqlite` (common after **PHP Used** pointed
`/usr/bin/php` at a Sury series). Repair as root:

```bash
# Prefer a PHP that has sqlite (php8.5 on Ubuntu 26, php8.3 on 24 after php8.3-sqlite3)
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/install/init-panel-db --sqlite-only
systemctl restart kloxo-php kloxo-backend kloxo-web
```

Do **not** rely on bare `php` for panel tools after PHP Used. Use
`/opt/kloxo/init/php-cli.sh` (also linked as `/usr/bin/lxphp.exe`).

Recovery console (panel down, MariaDB down): `https://<ip>:7777/recovery/` —
password printed at install and saved in `/root/kloxo-recovery-password`.

### Update

cPanel-style update with automatic rollback (preferred):

```bash
kloxocp upcp
# same as:
kloxocp update
# or:
sh /script/upcp
```

Flow: pre-flight → snapshot → sync from the git clone (`/usr/local/src/kloxo-cp`)
→ health check. If the health check fails, the snapshot is restored automatically.
Log: `/var/log/kloxo-update.log`. Useful flags: `--check` (plan only), `--no-fetch`,
`--no-rollback`, `--src=DIR`.

You can still re-run the install one-liner (keeps the admin password unless
`KLOXO_ADMIN_PASSWORD` is set). Signed apt packages are not published yet.

### After install

- **Webserver Configure** — default PHP, extra `phpX.Y` install/remove
- **Switch Program** — Nginx/Apache, BIND/Knot, Exim/Postfix, Dovecot
- **Security** — UFW Firewall, Fail2Ban, SSH, LxGuard
- **SQL Manager** — phpMyAdmin in a new tab
- **SSH Terminal** — admin, in-browser, as root

## Operator CLI

Use the panel PHP wrapper so **PHP Used** cannot break SQLite tools:

```bash
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/kloxocp help
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/kloxocp status
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/kloxocp doctor
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/kloxocp upcp
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/kloxocp snapshot
/opt/kloxo/init/php-cli.sh /opt/kloxo/bin/kloxocp rollback

# same wrapper (symlinks):
lxphp.exe /opt/kloxo/bin/kloxocp help
```

`kloxocp` on `PATH` (after install) also works when it goes through the wrapper.
Panel PHP is pinned in `/opt/kloxo/etc/path/php` to a binary that has
`pdo_sqlite` (rewritten if a stale pin points at a series without sqlite).

## License

GNU Affero General Public License v3 (`LICENSE.txt`). If you run a modified
version as a network service you must offer its source to its users.

Copyright and third-party notices: `NOTICE`. This project is independent of
LxCenter and MRatWork.

Developers: `docs/HANDOFF_BRIEFING.md`, `docs/AUDIT_REPORT.md`,
`docs/MAIL_DESIGN.md`, `docs/INSTALL_AND_UPDATE_DESIGN.md`. Tests: `tests/`.
