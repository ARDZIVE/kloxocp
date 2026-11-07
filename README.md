# Kloxo CP

A web hosting control panel, forked from **Kloxo-MR** (itself a fork of **Kloxo**
by LxLabs / LxCenter). This tree modernises that panel for Ubuntu 24.04 and
Ubuntu 26.x. The existing UI is kept. There is no billing.

Public git remote: https://github.com/ARDZIVE/kloxocp.git (`main`).

## Version

| What | Value |
|---|---|
| **Kloxo CP** (this fork) | **10.1-2026110601** |
| `kloxo/bin/kloxoversion` | **10.1-2026110601** |
| Upstream base | Kloxo-MR `dev`, commit `e0dfc28a15663fc3c682ee55c35d41f4032633f4` |

`php kloxo/bin/kloxocp version` and the panel Update page both show **10.1-2026110601**.
The Update page note is **Kloxo fork by Hampik TALATINIAN**.

> **Status: 10.1-2026110601, NOT FOR PRODUCTION.**
> Do not expose this on a public network. Phase-1a security items (C1–C5) are
> closed, first-party PHP parses and loads on PHP 8, and `install.sh` can
> bootstrap a panel, but the legacy PHP is still PHP 5-era in behaviour.
> Signed apt packaging and `kloxocp update` are not built. The hosted-mail
> stack is implemented; the panel mail screens are not fully wired to it yet
> (P4).
>
> Read `docs/HANDOFF_BRIEFING.md` (current state), `docs/AUDIT_REPORT.md`
> (security and PHP 8), `docs/INSTALL_AND_UPDATE_DESIGN.md` (intended
> production installer), and `docs/MAIL_DESIGN.md` (mail stack).

## Installation command (Ubuntu 24.04 or 26.x, as root)

**Recommended (current `main`, version 10.1-2026110601):**

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
apt-get -o DPkg::Lock::Timeout=600 update
apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl
curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh
bash /tmp/kloxocp-install.sh'
```

Fresh install (wipe the previous Kloxo CP and start over; on a server that already has this panel):

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; rm -f /tmp/kloxocp-install.sh; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh --fresh'
```

`--fresh` asks you to type `YES` first (add `--yes` to skip the question, for
example from a script; it refuses to run without a terminal otherwise). It stops
and removes the Kloxo units, deletes the program root `/opt/kloxo` and the
clone in `/usr/local/src/kloxo-cp`, drops the panel database `kloxo` and the
`kloxo` / `kloxo_pma` MariaDB accounts, deletes `/root/kloxo-admin-password`,
`/root/kloxo-recovery-password` and the Kloxo Fail2Ban panel jails, and then
runs the normal install, so the panel comes back with `admin` / `admin`, new
TLS keys and a new recovery password. Kept: apt packages (re-installed or
updated by the install), customer sites in `/home/kloxo`, mail in `/var/vmail`,
databases created for customers, firewall rules and the `lxlabs` user. Run it
from your own SSH session so Fail2Ban whitelists your address. Not tested on a
real Ubuntu server: the wipe is tested in a sandbox with fake `systemctl` and
`mysql` (`tests/install/run-tests.sh`).

One line (same steps):

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'
```

Low-RAM / small-disk VPS (add `--force`):

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh --force'
```

Panel-only host (skip Exim/Dovecot/Rspamd packages):

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh --profile=core'
```

On a brand-new server `unattended-upgrades` often holds the dpkg lock for the
first minutes (`Could not get lock /var/lib/dpkg/lock-frontend`). The
one-liner waits for it (`-o DPkg::Lock::Timeout=600`), and `install.sh` waits
up to 15 minutes (`KLOXO_APT_WAIT=<seconds>` to change) before it gives up; it
never kills the other apt process. The lock is not a Kloxo CP error, so just
let it finish or re-run the command.

Downloads `install.sh` from GitHub `main` to `/tmp`, then that script
clones or fetches `origin/main` (hard-reset). Do **not** pipe into bash
(`curl | bash` breaks apt stdin). Re-run the same command to refresh an
existing clone to current `main` (**10.1-2026110601**: Switch Program audited
(only the six program keys are processed, unknown names rejected, installs
non-interactive with the apt error shown and the old choice kept, mail fix
script `fixmail`); Spam SpamAssassin installs through apt; Web Cache
**nginx** proxy cache in front of Nginx or Apache; default UFW rules carry a
purpose comment; the download URL carries a cache-busting query).

Default profile is **full**: Exim (default MTA), Dovecot, Rspamd, **pure-ftpd**,
**redis-server**, **imagemagick**, and Ubuntu **universe** enabled when needed.
Postfix is **not** installed with Exim (both conflict on
`mail-transport-agent`); a leftover `postfix` unit is **disabled** at install.
Switch Program `smtp=postfix` removes Exim and installs Postfix. It does
**not** install SpamAssassin or sqlite at install time (panel DB
is **MariaDB**; spam is Rspamd; Switch Program installs SpamAssassin
from apt only when you select it). Bogofilter is not offered. Starts Nginx, **MariaDB**, BIND, Redis, Pure-FTPd, webcache on
**8080**, and webproxy on **30080**. Default SQL is MariaDB only
(`mysql`/`mysqld` aliases are not a second started service). Packages and
**enables `ufw` by default** (panel ports 7777/7778 plus common
SSH/web/FTP/DNS/mail ports allowed first; dual-stack IPv4+IPv6 when
`IPV6=yes`). Apache and PowerDNS are packaged and stay disabled.

When it finishes: `https://<ip>:7777/` — **admin / admin**. The same box prints the
**recovery console** address (`https://<ip>:7777/recovery/`) and its separate password,
for managing MariaDB, Nginx and the other applications if the panel itself cannot load. Accept the
self-signed certificate. Change that password on first login. Flags and
details are under [Installation](#installation).

## Stack

| Role | Choice |
|---|---|
| Target OS | Ubuntu 24.04 LTS (PHP 8.3) and Ubuntu 26.x (PHP 8.5) |
| Panel UI web server | Hiawatha 12.4 (panel only; source-built, not an apt package) |
| Panel PHP | Distro PHP-FPM as user `lxlabs` on `127.0.0.1:39000` |
| Customer websites | **Nginx + PHP-FPM** default; Apache 2.4 + PHP-FPM (`proxy_fcgi`, no mod_php) packaged and selectable |
| Webcache / webproxy | nginx frontends `kloxo-webcache` **:8080** and `kloxo-webproxy` **:30080** (to `127.0.0.1:80`; not Varnish/Squid) |
| Site PHP | Newest installed PHP-FPM by default; 5.6.40 and 7.4.33 installed beside it. Multiple PHP Install queues under `$ROOT/log/`. |
| DNS | **BIND 9** default; PowerDNS packaged and prepared, disabled until selected |
| SMTP / hosted mail | **Exim + Dovecot + Rspamd + MariaDB** (see `docs/MAIL_DESIGN.md`) |
| Switch Program | Web **Nginx** (Apache selectable); Web Cache **none** (default) / `nginx`; DNS **BIND** (PowerDNS selectable); Spam `none` / `spamassassin` (apt, on demand); POP3/IMAP `none` / **dovecot**; SMTP `none` / **exim** (default) / `postfix` (apt-swaps Exim; cannot co-install). Content tab labelled **Switch Program**. |
| SMTP protection | Mail Server Settings > **SMTP Protection** (replaces Spamdyke): greet delay, recipient limit, graylisting (Postgrey), rDNS and DNS RBL checks, blacklist headers, whitelist IPs; applied to Exim or Postfix, whichever Switch Program selects. Rendered and unit-tested only; not yet run against a live Exim/Postfix. |
| Database | **MariaDB** default (started at install; panel DB `kloxo`; mail DB is separate). A distinct MySQL package, if present, stays stopped until started from Services. |
| Database admin | Seeded by `init-panel-db` as the `kloxo_pma` MariaDB account (needed by Add Database) |
| SQL Manager | phpMyAdmin in a **new browser tab** (panel-admin SSO as MariaDB `kloxo_pma`@`127.0.0.1`) |
| Billing | None |
| License | GNU AGPL v3 |

### Panel UI (after install)

Default skin is simplicity. Content tabs sit on the beige header (Kloxo-MR layout).
The Kloxo CP logo sits on the right of the screen. First login is **admin / admin**;
the Change Password modal is required (Esc/Close cannot skip it).

| Screen | Behaviour |
|---|---|
| SQL Manager | Opens `/thirdparty/phpMyAdmin/kloxo-signon.php` with `target="_blank"`. A live panel-admin session (read from the session file `<root>/session/<id>`, matched to the `kloxo-session-id` cookie) is signed in as MariaDB `kloxo_pma`@`127.0.0.1` (full privileges; `root`@`localhost` is unix_socket and is refused over TCP with error 1698) and redirected into phpMyAdmin in that tab. Other users still see the MySQL logon form. Password is never put in the URL. |
| Switch Program | One content tab labelled **Switch Program**. Defaults: web Nginx, Web Cache none, DNS BIND, SMTP **exim**. Postfix is selectable and apt-swaps with Exim. Web Cache `nginx` puts an Nginx proxy cache on 80/443 in front of the Nginx or Apache site server (which moves to loopback 8081/8444); it rolls back on failure. Spam SpamAssassin installs from apt; a failed install shows the apt error and keeps the old choice. Tested with fake apt/systemctl only; a live switch is unverified. |
| Backup / Restore | Exactly five tabs **Backup / Restore**, **FTP Configuration**, **Schedule Configuration**, **File Manager**, **Upload**. Show page: Backup Now, Restore From File, Restore From FTP. File Manager / Upload under a backup keep those five tabs. |
| File Manager | Tabs **File Manager**, **Permissions**, **Ownership**, **Disk Usage**, **Upload**, **Thumbnail**. Toolbar: T Hidden, New dir, New file, Copy, Cut, Paste, Trash, Remove, Zip, Refresh, Show All. |
| FTP Configure | Under localhost (not Backup FTP Configuration). One beige tab labelled **FTP Configure**. |
| SSH Configure | Under localhost. One beige tab labelled **SSH Configure** (not Home). |
| PHP Configure | Exactly two tabs **PHP Configure** and **Advanced PHP Configure** (different links; no parent Home/Domain tabs on either page). Heading uses the parent name. |
| PHPinfo | In-panel **PHPinfo** tab (site `php -i` of the PHP selected for that domain). |
| Change Owner | Client select of candidate owners; does not die on a missing parent. |
| SSL Certificates | Under a domain: **SSL Certificates**, **Add SSL File**, **Add SSL Text**, **Add Let's Encrypt**, **Add SSL Link**; Switch To Another of sibling domains. |
| Manage DNS | Tabs **Manage DNS**, **Rebuild**, **NS**, **A**, **AAAA**, **CNAME**, **FCNAME**, **MX**, **TXT**, **CAA**, **SRV**, **General Settings**. No left Find box. Show does not rewrite the zone. |
| DNS Templates | Add form on the list tab (name/primary/secondary empty). No separate Add this template tab. |
| Reboot | Tabs **Reboot** and **Poweroff** (`systemctl --no-block`; not `system("reboot")`). |
| Update | **10.1-2026110601**, note **Kloxo fork by Hampik TALATINIAN**. No left Find box. `kloxocp update` is not built (exits 2). |
| Services | Lists only installed units; running/boot from systemd. RHEL/Debian aliases collapse to one row (`apache2` not `httpd`, `bind9` not `named`). Default SQL is **MariaDB** (started at install); `mysql`/`mysqld` are not shown as a second installed row while MariaDB is present. A separate MySQL package stays stopped until started here. Columns **Start** / **Stop** / **Restart**; icon clicks run `systemctl --no-block` immediately. Stop/restart of MariaDB and panel units is refused (icons stay visible; Sb/State view-only for those). If every page shows `No such file or directory`, run `systemctl start mariadb`. |
| Traceroute | Parses `traceroute` and `tracepath`. Response times show three decimals; unreachable hops show `*` (not `no`). |
| Servers | `localhost` is seeded. Add a remote host on the list (no separate Add Server tab). |
| SSH Terminal | Admin-only, in-page as root via kloxo-backend (no Java applet). |
| LxGuard | First beige tab **LxGuard**, then Connections / Raw Connections / White List. Switch To Another across servers. No left Find box. |
| UFW Firewall | Security-section button. **UFW enabled by default** at install. Default rules carry their purpose as the comment (SSH, HTTP, SMTP, IMAP...). List-only show; Add Rule / Edit on separate pages (TCP/UDP/ICMP, optional Comment). Toolbar Refresh / Add Rule / Delete with multi-select. No Family column (IPv4+IPv6 collapsed). Actions: edit / suspend|unsuspend / delete; suspended rows show a red ●. Live UFW after deploy is unverified. |
| HelpDesk | A Welcome ticket is seeded on install. Ticket show: Switch To Another, Ticket / Add Reply / Escalate. |
| Component Info | Ubuntu package names (nginx, apache2, bind9, pdns-server, mariadb-server, pure-ftpd, redis-server, exim4, postfix, dovecot, rspamd, phpX.Y, source-built Hiawatha). |
| Site PHP | Newest installed PHP-FPM by default; 5.6.40 and 7.4.33 beside it (Webserver Configure). Multiple PHP Install queues `install-site-php` under `$ROOT/log/` (not `/tmp`). |
| Recovery console | `https://<server>:7777/recovery/` (HTTPS only). Works without MariaDB, Nginx or Apache: it is served by the panel's own Hiawatha + PHP-FPM and logs in with its own **recovery password** (a hash in `etc/conf/recovery.json`; the installer prints the password once and saves it to `/root/kloxo-recovery-password`; reset with `php /opt/kloxo/bin/kloxocp recovery-password --generate`). Lists the server applications (nginx, apache2, mariadb, bind9, pdns, exim4, dovecot, rspamd, pure-ftpd, redis-server, ufw, phpX.Y-fpm, the Kloxo units) with Start / Stop / Restart and a log view; panel units can only be started (stopping them would end the page). Five wrong passwords lock an address for 15 minutes. When MariaDB is unreachable the panel pages show a link to it. Root actions go through `kloxo-backend`, which now starts even while MariaDB is down. Tested with fake systemctl and a local backend only; live use is unverified. |
| Watchdog | The `kloxo-watchdog` service (installed and enabled by `install.sh`, independent of the panel and MariaDB) checks the server applications every minute and restarts one that crashed or stopped answering on its port; after 3 restarts in 30 minutes it stops trying and mails the admin once. Applications you stopped yourself (recovery console or Services page) are left alone until you start them again. Switch a service off on the panel's Watchdog screen, or tune `etc/conf/watchdog.json`. It mails the admin contact email (the address entered in the panel profile; or `notify` in `etc/conf/watchdog.json`) when it restarts a service, when a restart fails and when it gives up; `kloxo-watchdog --test-mail` checks delivery, and the recovery console warns while no address is set. `kloxo-watchdog --status` shows what it did; log in `log/watchdog.log`. Tested with a fake systemd/clock/mailer only; a live crash-and-restart on Ubuntu is unverified. |
| Fail2Ban | Installed by `install.sh` (`fail2ban`, `python3-systemd`, `nftables`) and configured by `bin/install/install-fail2ban`; the address you ran the installer from over SSH is whitelisted so you cannot lock yourself out. Security > **Fail2Ban** (admin only) lists every situation it can watch as a jail: SSH, repeat offenders (recidive), the panel login and the recovery console (their failures go to `log/auth-failures.log`, rotated weekly), Exim, Dovecot, Postfix, Nginx auth and bot searches, Apache auth/noscript/botsearch/overflows, Pure-FTPd and MariaDB. Each row shows whether it can run on this server (program installed, filter and log present), its state, limits, failed/banned counters, and play/pause and edit icons (Max Retry, Find Time, Ban Time per jail). Ban IP, Unban, Unban All, Reload, Settings (defaults and the whitelist) and a Recent Activity list from `/var/log/fail2ban.log` are on the same page. Bans use the nftables action, so they are not mixed into the UFW rule list; LxGuard and the Block IP list still work and only overlap in purpose. The jail file `/etc/fail2ban/jail.d/kloxo-cp.local` is generated from `etc/conf/fail2ban.json`, checked with `fail2ban-client -t`, installed atomically and rolled back (file and saved settings) if Fail2Ban refuses it. The watchdog keeps the `fail2ban` unit running. Tested with a fake `fail2ban-client`/systemctl and the filter regexes translated to PHP (`tests/p2/test_fail2ban.php`, `test_server_pages.php`); a real Fail2Ban, nftables, the journal matches, live bans and a live install on Ubuntu are unverified. |
| Watchdog | Eight services. webcache (8080) and webproxy (30080) are nginx frontends to port 80 (`kloxo-webcache` / `kloxo-webproxy`), not Varnish/Squid. |

## Goals

| Item | Decision |
|---|---|
| Install | One command on Ubuntu 24.04 or 26.x (`install.sh` clones this git tree and bootstraps; apt repo not built) |
| Updates | One command or one button, with backup, health check and rollback (`snapshot`/`rollback` exist; `update` not built) |
| Target OS | Ubuntu 24.04 LTS and Ubuntu 26.x; AlmaLinux/Rocky 9 later |
| Customer websites | Nginx + PHP-FPM default; Apache selectable; multiple PHP versions side by side |
| DNS | BIND default; PowerDNS selectable |
| Mail | Exim + Dovecot + Rspamd, mailboxes in MariaDB |
| Panel's own web server | Hiawatha (panel UI only) |
| Billing integrations | None for now |
| UI | Existing UI kept |
| License | GNU AGPL v3 (unchanged) |

## Installation

### Installation command

**Ubuntu 24.04 or 26.x, as root** (same as [Installation command](#installation-command-ubuntu-2404-or-26x-as-root) above):

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
apt-get -o DPkg::Lock::Timeout=600 update
apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl
curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh
bash /tmp/kloxocp-install.sh'
```

One-line form:

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'
```

Downloads the current `install.sh` from GitHub `main`
(https://github.com/ARDZIVE/kloxocp) and runs it (default **full**: Exim,
Dovecot, Rspamd; Postfix not co-installed and any leftover Postfix unit
disabled; Redis, Pure-FTPd, and ImageMagick enabled; **MariaDB** is the
default SQL; **UFW enabled** with panel ports allowed first). That script
clones the tree if needed, or fetches `origin/main` and hard-resets when
`/usr/local/src/kloxo-cp` is already a git clone. Safe to repeat after a
failed install or to pick up GitHub `main` (local edits in that clone are
discarded; picks up **10.1-2026110601**). Use `--profile=core` for a panel
without mail packages.

A 2 GB VPS often reports ~1.6 GB RAM. If the installer stops on the RAM
or disk floor, add `--force` at the end:

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh --force'
```

Do **not** pipe `install.sh` into bash (`curl | bash`): apt would read stdin.
The lines above write the script to `/tmp` first. Do not run this on a
machine that already has cPanel, Plesk, or ISPConfig. It installs
whatever is on GitHub `main`. To install this working tree instead, copy
it to the server **without** `.git` and run `sudo bash install.sh` from
that directory (a git clone is reset to GitHub `main`).

When it finishes, the installer prints a box with the panel URL
(`https://<ip>:7777/`), username `admin`, and password `admin`. Accept the
self-signed certificate. The first screen after login is the password-change
form; the home dashboard appears after that password is changed. UFW is
already enabled with 7777/7778 allowed; manage further rules under Security →
**UFW Firewall** (IPv4 and IPv6 rows). Watchdog webcache and webproxy listen
on **8080** and **30080**.

### What `install.sh` does

On a fresh Ubuntu 24.04 (PHP 8.3) or 26.x (PHP 8.5) host, as root:

1. Refuses another control panel, and refuses less than ~2 GB RAM / 10 GB disk
   unless you pass `--force`.
2. Installs the **core** apt set: `php-cli` `php-fpm` `php-mysql` `php-xml`
   `php-mbstring` `php-curl`, **MariaDB**, Nginx, BIND 9, **pure-ftpd**,
   **redis-server**, **imagemagick**, `iproute2`, and the
   compilers Hiawatha needs (`build-essential` `cmake` `libssl-dev` `zlib1g-dev`),
   and `ufw` (**enabled by default**; panel ports 7777/7778 plus common
   SSH/web/FTP/DNS/mail ports allowed first; IPv4+IPv6 when `IPV6=yes`).
   Also packages `pdns-server` and `apache2` but does not let apt start them
   (BIND stays on 53, Nginx on 80/443). Does **not** install SpamAssassin
   or sqlite (Switch Program installs SpamAssassin on demand).
3. Clones the git tree to `/usr/local/src/kloxo-cp` if that tree is missing.
   If it is already a git clone, fetches `origin/main` and `reset --hard` first. If `/opt/kloxo`
   is a real directory (not a symlink to the clone), copies `httpdocs` /
   `bin` / `init` from the clone. Running `install.sh` from a copied tree
   uses that directory.
4. Symlinks `/opt/kloxo` → `<clone>/kloxo`, creates `lxlabs`, and points
   `lxphp.exe` / `lphp.exe` / `/usr/local/sbin/kloxocp` at this tree.
5. Generates unique TLS keys (`generate-tls-keys`) and copies
   `/opt/kloxo/etc/program.pem`.
6. Starts panel PHP-FPM (`kloxo-php`) as **`lxlabs`** on `127.0.0.1:39000`.
7. Builds pinned **Hiawatha 12.4** (`install-hiawatha`) **before** the database
   schema, then starts `kloxo-web` on 7777/7778.
8. Enables Nginx, **MariaDB** (default SQL; not a separate mysqld enable), and
   BIND. Starts webcache on **8080** and webproxy on **30080**
   (`install-webfront`; dedicated nginx processes, not Varnish or Squid).
   Prepares Apache (`install-apache`) and PowerDNS (`install-pdns`) and leaves
   those units **disabled**. A distinct MySQL package unit, if present, stays
   stopped until started from Services. `--profile=full` installs Exim
   (default MTA) only — Postfix conflicts with Exim on `mail-transport-agent`
   and is apt-installed when Switch Program selects `smtp=postfix`.
9. Loads the panel MariaDB schema (`init-panel-db`: admin, `localhost` pserver,
   `localhost` driver with `dns` = `bind`, `web` = `nginx`, and `smtp` = `exim`, simplicity appearance,
   `generalmisc_b`). Writes `/opt/kloxo/etc/conf/kloxo.pass` (`0640`, `lxlabs`).
10. Installs phpMyAdmin under `httpdocs/thirdparty/phpMyAdmin`.
11. Installs customer-site PHP-FPM **5.6** (5.6.40), **7.4** (7.4.33), and
    the newest `phpX.Y-fpm` apt can see (`install-site-php`; default is that
    newest). Old versions are only for legacy sites (Webserver Configure →
    PHP Used). The panel FPM stays `kloxo-php`.
12. Restarts FPM, waits until port 39000 is listening, creates the Hiawatha
    work directories, bounces `kloxo-web`, and pings `/fcgi-ping.php`.
13. Starts `kloxo-backend` (PHP lxserver). A master binds TLS 7779 and
    plaintext 7776 on localhost. A `--slave` run skips the panel UI, still
    installs site PHP 5.6 / 7.4 / latest, and binds TLS 7779 on all interfaces.

Default login is **admin / admin**; the panel blocks the rest of the UI until
that password is changed. The password is also written to
`/root/kloxo-admin-password` (mode 0600) on a first install. Override with
`KLOXO_ADMIN_PASSWORD` (min 8 characters, or the literal default `admin`).
Re-running the installer on an existing panel keeps the current admin
password unless that variable is set. Log:
`/var/log/kloxo-install.log`.

It does **not** run `kloxo-mail apply`, Let's Encrypt, or a signed apt
install. `--profile=full` only apt-installs mail packages (Exim, Dovecot,
Rspamd); Redis is already in core. It does not configure mail. The installer
does not run a browser login.

### Flags and environment

```
sudo bash install.sh
sudo bash install.sh --update              # accepted; every run already git-fetches the clone
sudo bash install.sh --fresh               # wipe the previous Kloxo CP install first (asks for YES; --yes skips)
sudo bash install.sh --force               # below 2 GB RAM / 10 GB disk
sudo bash install.sh                       # default --profile=full (Exim, Dovecot, Rspamd; no Postfix)
sudo bash install.sh --with-mail           # same as --profile=full
sudo bash install.sh --profile=full        # explicit full
sudo bash install.sh --profile=core        # php, nginx, mariadb, bind9, pure-ftpd, redis-server, ufw (enabled), imagemagick; no mail MTAs
sudo bash install.sh --dry-run             # print repo/src/root/profile and exit
sudo bash install.sh --src=/path           # clone location (default /usr/local/src/kloxo-cp)
sudo bash install.sh --root=/opt/kloxo     # program root
sudo bash install.sh --branch=main         # git branch
sudo bash install.sh --repo=URL            # git URL
sudo bash install.sh --help
sudo KLOXO_ADMIN_PASSWORD='...' bash install.sh         # set/change the admin UI password (min 8)
sudo bash install.sh --slave --slave-password=SECRET   # second machine; no panel UI
```

| Variable | Meaning |
|---|---|
| `KLOXO_ADMIN_PASSWORD` | Panel admin password (first-install default `admin`; otherwise min 8 chars). Unset on a re-run keeps the current password. |
| `KLOXO_SLAVE_PASSWORD` | Slave backend password (min 8 chars; used with `--slave`) |
| `KLOXO_GIT_URL` | Git remote (default `https://github.com/ARDZIVE/kloxocp.git`) |
| `KLOXO_GIT_BRANCH` | Git branch (default `main`) |
| `KLOXO_GIT_TOKEN` | Optional GitHub token for a private fork |
| `KLOXO_ROOT` | Program root override used by tools and tests |
| `KLOXO_PHP` | PHP binary override (default `/usr/bin/php`) |

The one-liner above always downloads a fresh `install.sh` from GitHub
`main`. That script then **fetches `origin/main` and `reset --hard`** when
`/usr/local/src/kloxo-cp` is already a git clone. You do not need
`--update` or `rm -rf` for a normal refresh. Remove the directory only if
it is corrupt or is not a git clone:

```
sudo rm -rf /usr/local/src/kloxo-cp
```

then run the one-liner again.

### Updating an existing install

The installer only ever installs what is on GitHub `main`, so push your
changes first. Then, on the server, run the same command again (it replaces
`/tmp/kloxocp-install.sh`, resets the clone to `origin/main`, re-runs
`init-panel-db` and restarts the panel services):

```bash
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update && apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'
```

A re-run on an existing panel **keeps the current admin password** (the
installer prints "unchanged"). Set `KLOXO_ADMIN_PASSWORD='...'` (at least 8
characters) on the `sudo` call only when you want to change it. A first
install, or a half-finished one with no admin row, still seeds `admin` /
`admin` (the UI then requires a change).

`raw.githubusercontent.com` can serve a cached copy for a few minutes after a
push. The command appends `?nocache=<unix time>` to the URL so each run asks
for a fresh copy of `install.sh`. If the run still does not show your change,
check that the change is pushed to `main`.

If only the database schema or seed rows need refreshing (for example the
database admin row below), run `init-panel-db` alone:

```bash
sudo php /opt/kloxo/bin/install/init-panel-db --root=/opt/kloxo --keep-admin-password --db-rootuser=root
```

### Multi-server (master / slave)

Same workflow as Kloxo-MR, with the localhost-only bind from the audit kept
on a master:

1. On the **second machine**, install as a slave (no panel UI on 7777):

```
sudo bash install.sh --slave --slave-password='at-least-8-chars'
```

2. Allow **TCP 7779** from the master to that host. Port 7776 stays on
   localhost and is never opened to the network.
3. On the **master** panel: **Servers → Add**, IP or hostname of the slave,
   and the same password. The master talks TLS 1.2+ to `:7779`.

Live two-host add is not proven by the installer. Do not expose 7776.

Signed apt packages (`docs/INSTALL_AND_UPDATE_DESIGN.md`) are not built.
The rest of this section is the same path, spelled out by hand, for
recovery or a laptop that will only run php-cli tests.

On any host with `php-cli` (including a laptop):

```
php kloxo/bin/kloxocp help
php kloxo/bin/kloxocp doctor --root="$(pwd)/kloxo"
php kloxo/bin/kloxocp status --root="$(pwd)/kloxo" --json
# snapshot / rollback of allow-listed paths (bin/kloxoversion, init, etc,
# file/ssl, httpdocs/login) into --snap-dir (default: <root>/var/backups/kloxocp):
php kloxo/bin/kloxocp snapshot --root="$(pwd)/kloxo" --snap-dir=/tmp/kloxocp-snaps
php kloxo/bin/kloxocp rollback --root="$(pwd)/kloxo" --snap-dir=/tmp/kloxocp-snaps
php kloxo/bin/kloxocp install   # doctor --install only; Ubuntu bootstrap is install.sh
```

A Mac or other non-Ubuntu host can run the php-cli tests and generate TLS keys. It cannot
run Hiawatha, the systemd units, or the live mail tests.

### 0. What the manual path does and does not do

**Does:** unpack the tree under `/opt/kloxo`, generate unique TLS keys, point `lxphp.exe`
at distro PHP 8, create runtime directories, install OS packages, build pinned
Hiawatha 12.4, load the panel MariaDB schema (`init-panel-db`: admin,
`localhost` server and driver, appearance, `generalmisc_b`, Login Message, and
`enablecronforall.flg`), install phpMyAdmin,
prepare Apache and PowerDNS without starting them, start `kloxo-php` /
`kloxo-web` / `kloxo-backend`, optionally apt-install mail packages
(`--profile=full`), and confirm `include.php` loads.

**Does not (yet):** a signed apt package, Let's Encrypt for the panel hostname,
or `kloxo-mail apply` (mail packages only; hosted mail is not configured).
The legacy `kloxo/install/` scripts are CentOS/RPM-only — do not run them on
Ubuntu.

### 1. Requirements

- Fresh **Ubuntu 24.04 LTS or Ubuntu 26.x** (2 GB RAM, 10 GB disk minimum)
- Root shell
- A hostname (FQDN preferred)
- Ports **7777/tcp** (panel HTTPS) and **7778/tcp** (panel HTTP) free
- For mail later: ports 25, 465, 587, 993, 995 free

```
lsb_release -a
hostname -f
ss -lnt | grep -E ':7777|:7778|:80|:443' || true
```

### 2. Packages

Core (matches `install.sh --profile=core`):

```
apt update
apt install -y \
  php-cli php-fpm php-mysql php-xml php-mbstring php-curl \
  mariadb-server \
  nginx \
  apache2 \
  bind9 \
  pdns-server pdns-backend-mysql \
  pure-ftpd \
  redis-server \
  iproute2 \
  ufw \
  imagemagick \
  ca-certificates git curl openssl \
  build-essential cmake make gcc pkg-config libssl-dev zlib1g-dev
```

`imagemagick` provides `/usr/bin/convert` for File Manager Thumbnail / resize
(the panel no longer probes with CentOS `rpm -q ImageMagick`).

Mail packages (only with `--profile=full` / `--with-mail`; does **not**
configure Exim). Do **not** apt-install Postfix here — it Conflicts with
Exim via `mail-transport-agent`. Switch Program `smtp=postfix` removes Exim
and installs Postfix.

```
apt install -y \
  exim4-daemon-heavy \
  dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd \
  dovecot-mysql dovecot-sieve dovecot-managesieved \
  rspamd
```

Hiawatha is **not** in Ubuntu 24.04 or 26.x. `install.sh` (or the command in section 8)
builds pinned 12.4. `redis-server` is a core package (Rspamd Bayes backend).
`pdns-server` is installed and left **disabled**; BIND stays on port 53 until
Switch Program selects PowerDNS. `apache2` is installed and left **disabled**;
Nginx stays on 80/443 until Switch Program selects Apache (PHP-FPM via
`proxy_fcgi`, not mod_php). `ufw` is installed and **enabled by default**
(7777/7778 and common ports allowed first; dual-stack when IPv6 is on).
After Nginx starts,
`install-webfront` enables **kloxo-webcache** (8080) and
**kloxo-webproxy** (30080). During apt,
`install.sh` tells `invoke-rc.d` not to start pdns and apache2 (they have no
backend yet, and would fight BIND / Nginx for the ports).

### 3. Place the tree at `/opt/kloxo`

The running panel expects program root `/opt/kloxo`, data `/home/kloxo`, mail home
`/home/kloxomail`. Clone or copy so that `httpdocs/` lives at `/opt/kloxo/httpdocs`.

```
git clone <this-repo-url> /usr/local/src/kloxo-cp
# development checkout: symlink the kloxo/ directory
ln -sfn /usr/local/src/kloxo-cp/kloxo /opt/kloxo
# or copy:
# cp -a /usr/local/src/kloxo-cp/kloxo /opt/kloxo

mkdir -p /opt/kloxo/etc/conf /opt/kloxo/pid /opt/kloxo/log \
  /home/kloxo/httpd /home/kloxo/client /home/kloxomail/mail
ln -sfn /opt/kloxo/pscript /script
ln -sfn /opt/kloxo/bin/kloxocp /usr/local/sbin/kloxocp
```

Override the program root in tests or a non-standard layout:

```
export KLOXO_ROOT=/opt/kloxo
export KLOXO_PHP=/usr/bin/php
```

### 4. Unique TLS keys (required; never use git copies)

Committed keys were deleted. Generate them on this machine:

```
php /opt/kloxo/bin/install/generate-tls-keys --root=/opt/kloxo --hostname="$(hostname -f)" --force
ls -l /opt/kloxo/init/backend.key /opt/kloxo/file/ssl/default.pem
# keys must be 0600, not world-readable
```

Panel HTTPS (`hiawatha.conf.base`) currently points at `/opt/kloxo/etc/program.pem`. Until
the installer writes that file, copy the generated PEM:

```
install -m 0600 /opt/kloxo/file/ssl/default.pem /opt/kloxo/etc/program.pem
chown lxlabs:lxlabs /opt/kloxo/etc/program.pem
```

### 5. Point `lxphp.exe` at distro PHP 8

```
ln -sfn /opt/kloxo/init/php-cli.sh /usr/bin/lxphp.exe
ln -sfn /opt/kloxo/init/php-cli.sh /usr/bin/lphp.exe
lxphp.exe -v
# expected: PHP 8.3.x on Ubuntu 24.04, PHP 8.5.x on Ubuntu 26.x

# same thing via the legacy helper, which now prefers distro PHP 8:
sh /opt/kloxo/pscript/fixlxphpexe
```

`sgbl` also uses `PHP_BINARY` / `/usr/bin/php` directly, so the panel no longer **requires**
the `lxphp.exe` name. The symlink keeps old scripts working.

### 6. Confirm the PHP core loads

From the **repository root** (or with `KLOXO_ROOT` set):

```
cd /usr/local/src/kloxo-cp
bash tests/p2/run-tests.sh
# includes test_boot.php: include.php must construct Sgbl without throwing
```

On the server, the same check:

```
KLOXO_ROOT=/opt/kloxo php /usr/local/src/kloxo-cp/tests/p2/test_boot.php
```

That proves the library bootstrap. It does **not** prove a browser login.

### 7. Panel PHP-FPM (Hiawatha talks to this port)

The FPM pool user is **`lxlabs`** (PHP 8.5 exits 78 if the pool is `root`).
Listen address is TCP `127.0.0.1:39000` (a unix socket plus restarting FPM
without bouncing Hiawatha produced HTTP 503).

```
install -d -m 0755 /opt/kloxo/init /opt/kloxo/log /opt/kloxo/session /opt/kloxo/pid
chown lxlabs:lxlabs /opt/kloxo/log /opt/kloxo/session /opt/kloxo/pid
cp -a /opt/kloxo/init/kloxo-php.service /etc/systemd/system/kloxo-php.service
# pool file: /opt/kloxo/init/kloxo-php-fpm.conf
# FastCGI:   127.0.0.1:39000
php-fpm8.3 -y /opt/kloxo/init/kloxo-php-fpm.conf --fpm-config /opt/kloxo/init/kloxo-php-fpm.conf -t
systemctl daemon-reload
systemctl enable --now kloxo-php
systemctl status kloxo-php --no-pager
ss -lnt | grep 39000
```

The unit runs `/opt/kloxo/init/kloxo-php-fpm.sh`, which execs the distro
`php-fpm` (`/usr/sbin/php-fpm8.3` on 24.04, `/usr/sbin/php-fpm8.5` on 26.x).
If `Type=notify` leaves FPM dead, the installer retries the unit as
`Type=simple`. `kloxo.pass` must be readable by `lxlabs` (`0640`). A 503
from Hiawatha means this port is down.

### 8. Panel Hiawatha (pinned 12.4 source build)

Ubuntu 24.04 and 26.x have no `hiawatha` package. Build the pinned release
**before** loading the MariaDB schema, render the config, and enable `kloxo-web`:

```
sudo bash /opt/kloxo/bin/install/install-hiawatha --root=/opt/kloxo
mkdir -p /opt/kloxo/var/hiawatha/upload /usr/local/var/lib/hiawatha/upload
chown lxlabs:lxlabs /opt/kloxo/var/hiawatha /opt/kloxo/var/hiawatha/upload \
  /usr/local/var/lib/hiawatha /usr/local/var/lib/hiawatha/upload
systemctl restart kloxo-web
systemctl status kloxo-web --no-pager
ss -lnt | grep -E ':7777|:7778'
curl -sS --max-time 8 http://127.0.0.1:7778/fcgi-ping.php
# expected: a body containing "ok"
```

`install-hiawatha` downloads
`https://hiawatha.leisink.net/files/download/hiawatha-12.4.tar.gz` (sha256
pinned in the script), copies the binary to `/opt/kloxo/init/kloxo-hiawatha`,
writes `hiawatha.conf` from `hiawatha.conf.base` (ports 7778 / 7777), checks it
with `hiawatha -k -c /opt/kloxo/init`, and starts `kloxo-web`. Pass `--force` to
rebuild. Pass `--dry-run` to print the plan.

If `hiawatha -k` names a bad directive, paste that output before editing
`hiawatha.conf` by hand. Do not keep `MinTLSversion` (removed in Hiawatha 11).
Hiawatha 12.4 needs a writable work dir or `kloxo-web` exits 1.

Open in a browser: `https://YOUR-HOST:7777/` (self-signed cert; expect a warning).
Unauthenticated requests redirect to `/login/`. Log in as **admin / admin**
and change the password when the panel asks (required Change Password modal;
Esc/Close cannot skip it). After that change the home dashboard should show
(admin has no parent; the schema must include the `localhost` driver row). A
blank page there usually means an older tree without those seeds: update the
files, re-run `init-panel-db`, and `systemctl restart kloxo-php`. A fresh
admin has no allowed-IP / blocked-IP rows; that empty list must not crash
login. The IP list is read from `ip -4 addr` on Ubuntu (Refresh is safe). Do
not use Add / Update / Delete on that page: those still write CentOS-style
network files and can take the host off the network.

phpMyAdmin is installed at `/thirdparty/phpMyAdmin/` by
`kloxo/bin/install/install-phpmyadmin` (not shipped in git). Admin **SQL Manager**
opens `/thirdparty/phpMyAdmin/kloxo-signon.php` in a new browser tab
(`target="_blank"`). A live panel-admin session is signed in as MariaDB
`kloxo_pma`@`127.0.0.1` (all databases, WITH GRANT OPTION) and redirected into
phpMyAdmin in that tab. Cross-site GET does not auto-sign-on. The MariaDB
password is never put in the URL. Other users still see the MySQL logon form;
panel admin may leave the password blank to use `pma.pass`. OS `root`@`localhost`
stays unix_socket. SQL Manager is under Basic, not a second Domain tile.
A 405 from Hiawatha on that URL means the directory has no `index.php`.
Re-run `install.sh` (or `install-phpmyadmin` plus `init-panel-db`) after
pulling this tree so sign-on and `root`@`127.0.0.1` exist. Live phpMyAdmin
login after re-install is unverified.

### 9. MariaDB for the panel

The panel database name is `kloxo`, DB user `kloxo`. Use the Ubuntu installer
(not the backgrounded CentOS `mysql … &` path). `install.sh` builds Hiawatha
first, then waits for a MariaDB unix-socket root login, then runs this:

```
# recover a half-finished install, or create a fresh schema:
sudo KLOXO_ADMIN_PASSWORD='YOUR_ADMIN_UI_PASS' \
  php /opt/kloxo/bin/install/init-panel-db \
  --root=/opt/kloxo --admin-password-env=KLOXO_ADMIN_PASSWORD --db-rootuser=root
# Ubuntu MariaDB root is the unix socket; omit --db-rootpassword
```

`init-panel-db` is idempotent (`CREATE TABLE IF NOT EXISTS`, insert-or-update
admin; `--keep-admin-password` leaves an existing admin password alone, which
`install.sh` uses on re-runs). It writes `/opt/kloxo/etc/conf/kloxo.pass` (0640, `lxlabs`). With no override
it seeds **admin / admin** (the UI then requires a change), the `localhost`
pserver and driver rows, simplicity appearance, and `generalmisc_b`. A custom
`--admin-password` must be at least 8 characters. Re-running it on an existing
database fills those rows if they are missing. On MariaDB 10.6+/11.8 it rewrites
the 2014 dump so `client`/`domain`/`web` do not hit error 1118 (row size).

It also seeds the **database administrator** row (`dbadmin`, `mysql___localhost`)
that Add Database reads. Because OS `root`@`localhost` is unix_socket and cannot
log in with a password, the row uses the `kloxo_pma` MariaDB account (ALL
PRIVILEGES; password in `/opt/kloxo/etc/conf/pma.pass`). Without this row,
creating a database fails with *There are no Database administrator entries
configured for this particular server*. Live database creation after
re-install is unverified.

The legacy wrapper still works from `httpdocs` and now calls `init-panel-db`:

```
cd /opt/kloxo/httpdocs
php ../bin/install/create.php --install-type=master \
  --db-rootuser=root --db-rootpassword= \
  --admin-password=YOUR_ADMIN_UI_PASS
```

Do not treat a run that only created `general` (0 rows, no `client` table) as a
working panel; re-run `init-panel-db`.

### 10. Mail stack (independent of the panel UI)

Hosted mail is **Exim** (SMTP) + **Dovecot** (IMAP/POP3/LMTP) + **Rspamd**
+ a dedicated MariaDB database. Switch Program SMTP is `none` / `exim`
(default) / `postfix` (the content tab is labelled **Switch Program**);
`exim` apt-installs `exim4-daemon-heavy` and removes Postfix; `postfix`
apt-installs Postfix and removes Exim (they cannot co-exist). Hosted-mail
templates stay Exim (`kloxo-mail`). qmail-toaster is not the selectable MTA.
P4 will wire the panel mail screens to `kloxo-mail`. The qmail, courier-imap and
ezmlm drivers, templates and scripts are deleted; a missing mail driver degrades
to a no-op.

On a **disposable** machine only (`tests/mail/README.md`): live tests rewrite
`/etc/exim4`, `/etc/dovecot`, `/etc/rspamd/local.d`.

```
export KLOXO_MAIL_TEMPLATES=/opt/kloxo/file/mail
KM="php /opt/kloxo/bin/mail/kloxo-mail"
mkdir -p /etc/kloxo/tls /etc/kloxo/mail.d
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /etc/kloxo/tls/mail.key -out /etc/kloxo/tls/mail.pem \
  -subj "/CN=$(hostname -f)" -days 30
chmod 640 /etc/kloxo/tls/mail.key
$KM init --hostname="$(hostname -f)" \
  --tls-cert=/etc/kloxo/tls/mail.pem --tls-key=/etc/kloxo/tls/mail.key
$KM apply
```

Static mail tests (need MariaDB root via unix socket):

```
bash tests/mail/run-tests.sh
```

### 11. Other tests (safe on a laptop)

From the repository root, php-cli only:

```
bash tests/tls/run-tests.sh
bash tests/remote/run-tests.sh
bash tests/security/run-tests.sh
bash tests/p1/run-tests.sh
bash tests/p2/run-tests.sh
bash tests/p3/run-tests.sh
bash tests/install/run-tests.sh
php kloxo/bin/install/generate-tls-keys --help
php kloxo/bin/install/init-panel-db --help
php kloxo/bin/install/install-pdns --help
php kloxo/bin/install/install-apache --help
php kloxo/bin/install/install-webfront --help
bash kloxo/bin/install/install-hiawatha --help
php kloxo/bin/mail/kloxo-mail help
php kloxo/bin/kloxocp help
```

### Intended future install (apt path not implemented)

```
wget https://<project-domain>/install.sh
sha256sum -c install.sh.sha256
sudo bash install.sh
```

`install.sh` bootstraps from git (see the one-liner above). There is no
published checksum yet. The signed apt repo, Debian packages, and
`kloxocp update` are specified in `docs/INSTALL_AND_UPDATE_DESIGN.md` and
are not built. `kloxocp.invalid` is a placeholder, not a download host.

## Layout

```
kloxo/            panel source; installs to /opt/kloxo
  httpdocs/       web UI, libraries, drivers, login/
  bin/ sbin/ pscript/   backend and maintenance scripts
  cexe/           C backend daemon (source only; setuid helpers removed)
  init/           panel systemd units, Hiawatha, PHP-FPM pool, php-cli.sh
  file/           service config templates (nginx, apache, bind, pdns, mail)
  install/        LEGACY RPM/CentOS installer (do not use on Ubuntu)
  bin/kloxocp            operator CLI (status, doctor, snapshot, rollback)
  bin/mail/kloxo-mail    mail provisioning tool (PHP 8.3)
  bin/install/generate-tls-keys  unique TLS keys per install
  bin/install/init-panel-db      Ubuntu MariaDB panel schema
  bin/install/init-slave         slave-db.db for a --slave host
  bin/install/install-hiawatha   pinned Hiawatha 12.4 source build
  bin/install/install-pdns       PowerDNS schema + gmysql snippet (unit stays disabled)
  bin/install/install-apache     Apache 2.4 PHP-FPM vhosts (unit stays disabled)
  bin/install/install-webfront   nginx webcache :8080 and webproxy :30080
  bin/install/install-phpmyadmin phpMyAdmin under httpdocs/thirdparty
  bin/install/install-site-php   site PHP-FPM 5.6 / 7.4 / latest
install.sh        Ubuntu 24.04 / 26.x bootstrap (clone + apt + layout + TLS)
tests/            mail, tls, remote, security, p1, p2, p3, install
docs/             handoff, audit, design, mail stack, changelog, legacy notes
```

## Licensing and naming

Free software under the **GNU Affero General Public License v3** (`LICENSE.txt`).
If you run a modified version as a network service you must offer its source to its users.
Upstream copyright notices are preserved; see `NOTICE`. "Kloxo" is the name of the upstream
projects: this fork is **not affiliated with or endorsed by** LxCenter or MRatWork.
