# Kloxo CP

Hosting control panel for **Ubuntu 24.04** (PHP 8.3) and **Ubuntu 26.x** (PHP 8.5).
GNU AGPLv3. Version **10.1-2026110601**.

https://github.com/ARDZIVE/kloxocp.git

**Not for production.** Do not expose it on a public network until you have
hardened it and verified the install on your own host.

## Stack

| Role | Default | Also available |
|---|---|---|
| Sites | Nginx + PHP-FPM | Apache 2.4 + PHP-FPM (`proxy_fcgi`) |
| Site PHP | Newest installed `phpX.Y-fpm` | Extra versions from [Sury](https://packages.sury.org/php/) |
| Panel UI | Hiawatha 12.4 (ports 7777 / 7778) | — |
| DNS | BIND 9 | Knot DNS |
| Mail | Exim + Dovecot + Rspamd | Postfix (replaces Exim) |
| SQL | MariaDB | phpMyAdmin (SQL Manager) |
| FTP / cache / firewall | Pure-FTPd, Redis, UFW, Fail2Ban | Nginx web cache in front of the site server |
| Panel database | SQLite (`/var/lib/kloxo/panel.sqlite`) | — |

No billing. Mailboxes live under `/var/vmail`. Switch Program chooses web, DNS,
SMTP, IMAP and spam. Apache and Knot stay installed but disabled until selected.

## Install

Fresh **Ubuntu 24.04 or 26.x**, as root, at least ~2 GB RAM and 10 GB disk.
Do not install on a machine that already runs another control panel. Do not
pipe the script into bash (`curl | bash` breaks apt).

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
apt-get -o DPkg::Lock::Timeout=600 update
apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl
curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh
bash /tmp/kloxocp-install.sh'
```

The script clones or resets `/usr/local/src/kloxo-cp` to GitHub `main`,
installs to `/opt/kloxo`, enables UFW (panel ports 7777/7778 and common
SSH/web/FTP/DNS/mail), and starts the panel. Log: `/var/log/kloxo-install.log`.

| Flag | Use |
|---|---|
| *(none)* | Full stack (Exim, Dovecot, Rspamd) |
| `--profile=core` | Panel only, no MTA packages |
| `--force` | Below the RAM/disk floor |
| `--fresh` | Wipe the previous panel first (asks `YES`; add `--yes` to skip) |
| `--slave --slave-password=SECRET` | Backend host, no UI (open TCP 7779 from the master) |

`--fresh` removes the program, panel SQLite, panel MariaDB accounts and saved
passwords, then reinstalls. Customer sites, `/var/vmail`, customer databases,
UFW rules and apt packages are kept.

To install this working tree instead of GitHub `main`, copy it to the server
**without** `.git` and run `sudo bash install.sh` from that directory.

### First login

Open `https://<ip>:7777/` (self-signed certificate). Username **admin**,
password **admin** (also in `/root/kloxo-admin-password` on a first install).
The UI requires a password change before anything else.

Recovery console (panel down, MariaDB down): `https://<ip>:7777/recovery/` —
password printed at install and saved in `/root/kloxo-recovery-password`.

### Update

After the last commit is on GitHub `main`, re-run the installer on the server
(as root). It fetches that commit, resets `/usr/local/src/kloxo-cp`, and
installs into `/opt/kloxo`. The admin password is kept unless you set
`KLOXO_ADMIN_PASSWORD`.

```bash
curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && sudo bash /tmp/kloxocp-install.sh
```

`kloxocp update` is not built (exits 2). Signed apt packages are not published.

### After install

- **Webserver Configure** — default PHP, extra `phpX.Y` install/remove
- **Switch Program** — Nginx/Apache, BIND/Knot, Exim/Postfix, Dovecot
- **Security** — UFW Firewall, Fail2Ban, SSH, LxGuard
- **SQL Manager** — phpMyAdmin in a new tab
- **SSH Terminal** — admin, in-browser, as root

## Operator CLI

```
php /opt/kloxo/bin/kloxocp help
php /opt/kloxo/bin/kloxocp status
php /opt/kloxo/bin/kloxocp doctor
php /opt/kloxo/bin/kloxocp snapshot
php /opt/kloxo/bin/kloxocp rollback
```

## License

GNU Affero General Public License v3 (`LICENSE.txt`). If you run a modified
version as a network service you must offer its source to its users.

Copyright and third-party notices: `NOTICE`. This project is independent of
LxCenter and MRatWork.

Developers: `docs/HANDOFF_BRIEFING.md`, `docs/AUDIT_REPORT.md`,
`docs/MAIL_DESIGN.md`, `docs/INSTALL_AND_UPDATE_DESIGN.md`. Tests: `tests/`.
