# Kloxo CP

A web hosting control panel, forked from **Kloxo-MR** (itself a fork of **Kloxo**
by LxLabs / LxCenter). This tree modernises that panel for Ubuntu 24.04 and
Ubuntu 26.x. The existing UI is kept. There is no billing.

Public git remote: https://github.com/ARDZIVE/kloxocp.git (`main`).

## Version

| What | Value |
|---|---|
| **Kloxo CP** (this fork) | **0.1.0-dev** |
| Legacy `kloxo/bin/kloxoversion` | **7.0.0.c-2019072201** |
| Upstream base | Kloxo-MR `dev`, commit `e0dfc28a15663fc3c682ee55c35d41f4032633f4` |

`php kloxo/bin/kloxocp version` prints the fork version (`0.1.0-dev`). The panel UI
and legacy parsers still read `kloxo/bin/kloxoversion` (`7.0.0.c-2019072201`); that
file is **not** rewritten, because existing version parsers expect that format. A
single Kloxo CP version scheme comes with packaging (not built yet).

> **Status: 0.1.0-dev, NOT FOR PRODUCTION.**
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

## Stack

| Role | Choice |
|---|---|
| Target OS | Ubuntu 24.04 LTS (PHP 8.3) and Ubuntu 26.x (PHP 8.5) |
| Panel UI web server | Hiawatha 12.4 (panel only; source-built, not an apt package) |
| Panel PHP | Distro PHP-FPM as user `lxlabs` on `127.0.0.1:39000` |
| Customer websites | **Nginx + PHP-FPM** default; Apache 2.4 + PHP-FPM (`proxy_fcgi`, no mod_php) packaged and selectable |
| Site PHP | Newest installed PHP-FPM by default; 5.6.40 and 7.4.33 installed beside it |
| DNS | **BIND 9** default; PowerDNS packaged and prepared, disabled until selected |
| SMTP / hosted mail | **Postfix + Dovecot + Rspamd + MariaDB** (see `docs/MAIL_DESIGN.md`) |
| Switch Program SMTP | `none` or `exim` (`exim4` stub only; it does not replace Postfix) |
| Database | MariaDB (panel DB `kloxo`; mail DB is separate) |
| SQL Manager | MySQL logon, then phpMyAdmin (`/thirdparty/phpMyAdmin/kloxo-signon.php`) |
| Billing | None |
| License | GNU AGPL v3 |

## Goals

| Item | Decision |
|---|---|
| Install | One command on Ubuntu 24.04 or 26.x (`install.sh` clones this git tree and bootstraps; apt repo not built) |
| Updates | One command or one button, with backup, health check and rollback (`snapshot`/`rollback` exist; `update` not built) |
| Target OS | Ubuntu 24.04 LTS and Ubuntu 26.x; AlmaLinux/Rocky 9 later |
| Customer websites | Nginx + PHP-FPM default; Apache selectable; multiple PHP versions side by side |
| DNS | BIND default; PowerDNS selectable |
| Mail | Postfix + Dovecot + Rspamd, mailboxes in MariaDB |
| Panel's own web server | Hiawatha (panel UI only) |
| Billing integrations | None for now |
| UI | Existing UI kept |
| License | GNU AGPL v3 (unchanged) |

## Installation

**Ubuntu 24.04 or 26.x, one line (as root).** Clones
https://github.com/ARDZIVE/kloxocp.git (public, branch `main`) if needed,
or fetches `origin/main` and hard-resets the clone when it already exists,
then runs `install.sh`. Safe to repeat after a failed install or to pick
up GitHub `main` (local edits in `/usr/local/src/kloxo-cp` are discarded):

```
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y git ca-certificates && { if [ -d /usr/local/src/kloxo-cp/.git ]; then git -C /usr/local/src/kloxo-cp fetch --depth 1 origin main && git -C /usr/local/src/kloxo-cp checkout -f -B main FETCH_HEAD && git -C /usr/local/src/kloxo-cp reset --hard FETCH_HEAD; elif [ ! -f /usr/local/src/kloxo-cp/install.sh ]; then git clone --depth 1 --branch main https://github.com/ARDZIVE/kloxocp.git /usr/local/src/kloxo-cp; fi; } && bash /usr/local/src/kloxo-cp/install.sh'
```

Do **not** pipe `install.sh` into bash (`curl | bash`): apt would read stdin.
The line above is the supported form. Do not run this on a machine that
already has cPanel, Plesk, or ISPConfig. It installs whatever is on GitHub
`main`. To install this working tree instead, copy it to the server
**without** `.git` and run `sudo bash install.sh` from that directory
(a git clone is reset to GitHub `main`).

When it finishes, the installer prints a box with the panel URL
(`https://<ip>:7777/`), username `admin`, and password `admin`. Accept the
self-signed certificate. The first screen after login is the password-change
form; the home dashboard appears after that password is changed. If the page
does not load, allow TCP 7777 (and 7778) in the host firewall.

### What `install.sh` does

On a fresh Ubuntu 24.04 (PHP 8.3) or 26.x (PHP 8.5) host, as root:

1. Refuses another control panel, and refuses less than ~2 GB RAM / 10 GB disk
   unless you pass `--force`.
2. Installs the **core** apt set: `php-cli` `php-fpm` `php-mysql` `php-xml`
   `php-mbstring` `php-curl`, MariaDB, Nginx, BIND 9, `iproute2`, and the
   compilers Hiawatha needs (`build-essential` `cmake` `libssl-dev` `zlib1g-dev`).
   Also packages `pdns-server` and `apache2` but does not let apt start them
   (BIND stays on 53, Nginx on 80/443).
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
8. Enables Nginx, MariaDB, and BIND. Prepares Apache (`install-apache`) and
   PowerDNS (`install-pdns`) and leaves those units **disabled**.
9. Loads the panel MariaDB schema (`init-panel-db`: admin, `localhost` pserver,
   `localhost` driver with `dns` = `bind` and `web` = `nginx`, simplicity appearance,
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
`/root/kloxo-admin-password` (mode 0600). Override with `KLOXO_ADMIN_PASSWORD`
(min 8 characters, or the literal default `admin`). Log:
`/var/log/kloxo-install.log`.

It does **not** run `kloxo-mail apply`, Let's Encrypt, or a signed apt
install. `--profile=full` only apt-installs mail packages (Postfix, Dovecot,
Rspamd, Redis); it does not configure mail. The installer does not run a
browser login.

### Flags and environment

```
sudo bash install.sh
sudo bash install.sh --update              # accepted; every run already git-fetches the clone
sudo bash install.sh --force               # below 2 GB RAM / 10 GB disk
sudo bash install.sh --profile=full        # also apt-install postfix, dovecot, rspamd, redis
sudo bash install.sh --with-mail           # same as --profile=full
sudo bash install.sh --profile=core        # default: php, nginx, mariadb, bind9; apache2/pdns packaged, disabled
sudo bash install.sh --dry-run             # print repo/src/root/profile and exit
sudo bash install.sh --src=/path           # clone location (default /usr/local/src/kloxo-cp)
sudo bash install.sh --root=/opt/kloxo     # program root
sudo bash install.sh --branch=main         # git branch
sudo bash install.sh --repo=URL            # git URL
sudo bash install.sh --help
sudo bash install.sh --slave --slave-password=SECRET   # second machine; no panel UI
```

| Variable | Meaning |
|---|---|
| `KLOXO_ADMIN_PASSWORD` | Panel admin password (default `admin`; otherwise min 8 chars) |
| `KLOXO_SLAVE_PASSWORD` | Slave backend password (min 8 chars; used with `--slave`) |
| `KLOXO_GIT_URL` | Git remote (default `https://github.com/ARDZIVE/kloxocp.git`) |
| `KLOXO_GIT_BRANCH` | Git branch (default `main`) |
| `KLOXO_GIT_TOKEN` | Optional GitHub token for a private fork |
| `KLOXO_ROOT` | Program root override used by tools and tests |
| `KLOXO_PHP` | PHP binary override (default `/usr/bin/php`) |

The one-liner above **fetches `origin/main` and `reset --hard`** when
`/usr/local/src/kloxo-cp` is already a git clone, then runs that updated
`install.sh`. You do not need `--update` or `rm -rf` for a normal refresh.
Remove the directory only if it is corrupt or is not a git clone:

```
sudo rm -rf /usr/local/src/kloxo-cp
```

then run the one-liner again.

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
`localhost` server and driver, appearance, `generalmisc_b`), install phpMyAdmin,
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
  iproute2 \
  ca-certificates git curl openssl \
  build-essential cmake make gcc pkg-config libssl-dev zlib1g-dev
```

Mail packages (only with `--profile=full` / `--with-mail`; does **not**
configure Postfix):

```
apt install -y \
  postfix postfix-mysql postfix-lmdb \
  dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd \
  dovecot-mysql dovecot-sieve dovecot-managesieved \
  rspamd redis-server
```

Hiawatha is **not** in Ubuntu 24.04 or 26.x. `install.sh` (or the command in section 8)
builds pinned 12.4. Redis is a mail-stack dependency, not a core panel package.
`pdns-server` is installed and left **disabled**; BIND stays on port 53 until
Switch Program selects PowerDNS. `apache2` is installed and left **disabled**;
Nginx stays on 80/443 until Switch Program selects Apache (PHP-FPM via
`proxy_fcgi`, not mod_php). During apt, `install.sh` tells `invoke-rc.d` not
to start those two units (they have no backend yet, and would fight BIND /
Nginx for the ports).

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
opens the MySQL logon (`/thirdparty/phpMyAdmin/kloxo-signon.php`) in a new tab;
Go then redirects into phpMyAdmin as MariaDB `root`@`127.0.0.1` (all databases,
WITH GRANT OPTION). The MariaDB password is never put in the URL. Panel admin
may leave the logon password blank to use `pma.pass`. OS `root`@`localhost`
stays unix_socket.
A 405 from Hiawatha on that URL means the directory has no `index.php`.
Re-run `install.sh` (or `install-phpmyadmin` plus `init-panel-db`) after
pulling this tree so sign-on and `root`@`127.0.0.1` exist.

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
admin). It writes `/opt/kloxo/etc/conf/kloxo.pass` (0640, `lxlabs`). With no override
it seeds **admin / admin** (the UI then requires a change), the `localhost`
pserver and driver rows, simplicity appearance, and `generalmisc_b`. A custom
`--admin-password` must be at least 8 characters. Re-running it on an existing
database fills those rows if they are missing. On MariaDB 10.6+/11.8 it rewrites
the 2014 dump so `client`/`domain`/`web` do not hit error 1118 (row size).

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

Hosted mail is **Postfix** (SMTP) + **Dovecot** (IMAP/POP3/LMTP) + **Rspamd**
+ a dedicated MariaDB database. Switch Program SMTP is still `none` / `exim`
only; `exim` starts Ubuntu `exim4` and does not replace this stack. P4 will
wire the panel mail screens to `kloxo-mail`. qmail remains in the tree until
those drivers exist.

On a **disposable** machine only (`tests/mail/README.md`): live tests rewrite
`/etc/postfix`, `/etc/dovecot`, `/etc/rspamd/local.d`.

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

`install.sh` bootstraps from git (see the one-liner above). The signed apt repo,
Debian packages, and `kloxocp update` are specified in
`docs/INSTALL_AND_UPDATE_DESIGN.md` and are not built. `kloxocp.invalid` is a
placeholder, not a download host.

## Layout

```
kloxo/            panel source; installs to /opt/kloxo
  httpdocs/       web UI, libraries, drivers, login/
  bin/ sbin/ pscript/   backend and maintenance scripts
  cexe/           C backend daemon (source only; setuid helpers removed)
  init/           panel systemd units, Hiawatha, PHP-FPM pool, php-cli.sh
  file/           service config templates (nginx, apache, bind, pdns, qmail, mail)
  install/        LEGACY RPM/CentOS installer (do not use on Ubuntu)
  bin/kloxocp            operator CLI (status, doctor, snapshot, rollback)
  bin/mail/kloxo-mail    mail provisioning tool (PHP 8.3)
  bin/install/generate-tls-keys  unique TLS keys per install
  bin/install/init-panel-db      Ubuntu MariaDB panel schema
  bin/install/init-slave         slave-db.db for a --slave host
  bin/install/install-hiawatha   pinned Hiawatha 12.4 source build
  bin/install/install-pdns       PowerDNS schema + gmysql snippet (unit stays disabled)
  bin/install/install-apache     Apache 2.4 PHP-FPM vhosts (unit stays disabled)
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
