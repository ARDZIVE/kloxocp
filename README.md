# Kloxo CP

A web hosting control panel, derived from **Kloxo-MR** (itself a fork of **Kloxo** by LxLabs / LxCenter).

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
> Do not expose this on a public network. The legacy PHP is still PHP 5-era in behaviour,
> apt packaging is not built, and a full UI login against MariaDB is not verified.
> First-party PHP now **parses and loads** on PHP 8 (`include.php` boot smoke).
> The `kloxocp` CLI (`status`, `doctor`, `snapshot`, `rollback`) is in this tree.
> Ubuntu 24.04 `install.sh` clones https://github.com/ARDZIVE/kloxocp.git;
> `kloxocp update` and signed apt packages are not built.
>
> Read `docs/AUDIT_REPORT.md` (security and PHP 8), `docs/INSTALL_AND_UPDATE_DESIGN.md`
> (the intended production installer), and `docs/MAIL_DESIGN.md` (mail stack, which is
> implemented and tested).

## Goals

| Item | Decision |
|---|---|
| Install | One command on Ubuntu 24.04 (`install.sh` clones this git tree and bootstraps; apt repo not built) |
| Updates | One command or one button, with backup, health check and rollback (`snapshot`/`rollback` exist; `update` not built) |
| Target OS | Ubuntu 24.04 LTS first; AlmaLinux/Rocky 9 later |
| Customer websites | Nginx + PHP-FPM (multiple PHP versions side by side) |
| Mail | Postfix + Dovecot + Rspamd, mailboxes in MariaDB |
| Panel's own web server | Hiawatha (panel UI only) |
| Billing integrations | None for now |
| UI | Existing UI kept |
| License | GNU AGPL v3 (unchanged) |

## Installation

**Ubuntu 24.04, one line (as root).** This clones
https://github.com/ARDZIVE/kloxocp.git (public, branch `main`) and runs `install.sh`:

```
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y git ca-certificates && git clone --depth 1 --branch main https://github.com/ARDZIVE/kloxocp.git /usr/local/src/kloxo-cp && bash /usr/local/src/kloxo-cp/install.sh'
```

Do **not** pipe `install.sh` into bash (`curl | bash`): apt would read stdin.
The clone-then-run line above is the supported form. Do not run this on a
machine that already has cPanel, Plesk, or ISPConfig. Push this tree to
`kloxocp` before running the one-liner on a server, or it will install an
older revision.

`install.sh` installs distro packages, links `/opt/kloxo` to the clone,
generates TLS keys, and starts `kloxo-php` when `php-fpm8.3` is present.
It does **not** create the panel MariaDB schema (legacy `create.php` is
CentOS-only), does not run `kloxo-mail apply` unless you pass
`--profile=full` for mail *packages* only, and does not prove a browser
login. Hiawatha is used if `apt` provides it; otherwise the UI does not
listen. Log: `/var/log/kloxo-install.log`.

From an existing clone:

```
sudo bash install.sh
sudo bash install.sh --profile=full    # also apt-install postfix/dovecot/rspamd
sudo bash install.sh --help
```

Signed apt packages (`docs/INSTALL_AND_UPDATE_DESIGN.md`) are not built.
The rest of this section is the same path, spelled out by hand.

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

### 0. What this procedure does and does not do

**Does:** unpack the tree under `/opt/kloxo`, generate unique TLS keys, point `lxphp.exe`
at distro PHP 8, create runtime directories, install OS packages, optionally configure
the mail stack, and confirm `include.php` loads.

**Does not (yet):** a signed apt package, Let's Encrypt for the panel hostname, a
verified Hiawatha Ubuntu package, panel DB schema, or a verified admin login
through the browser. The legacy `kloxo/install/` scripts are CentOS/RPM-only —
do not run them on Ubuntu.

### 1. Requirements

- Fresh **Ubuntu 24.04 LTS** (2 GB RAM, 10 GB disk minimum)
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

```
apt update
apt install -y \
  php-cli php-fpm php-mysql php-xml php-mbstring php-curl \
  mariadb-server \
  nginx \
  bind9 \
  openssl git make gcc pkg-config libssl-dev \
  redis-server
```

Optional (mail stack, only if you will run `kloxo-mail`):

```
apt install -y \
  postfix postfix-mysql postfix-lmdb \
  dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd \
  dovecot-mysql dovecot-sieve dovecot-managesieved \
  rspamd
```

Hiawatha is **not** in Ubuntu 24.04 by default (UNVERIFIED). If `apt install hiawatha`
fails, you must build a pinned binary before the panel UI can listen. PHP-FPM for the
panel can still be configured.

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
```

### 5. Point `lxphp.exe` at distro PHP 8

```
ln -sfn /opt/kloxo/init/php-cli.sh /usr/bin/lxphp.exe
ln -sfn /opt/kloxo/init/php-cli.sh /usr/bin/lphp.exe
lxphp.exe -v
# expected: PHP 8.3.x (or 8.x)

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

### 7. Panel PHP-FPM (Hiawatha talks to this socket)

```
install -d -m 0755 /opt/kloxo/init /opt/kloxo/log
cp -a /opt/kloxo/init/kloxo-php.service /etc/systemd/system/kloxo-php.service
# pool file: /opt/kloxo/init/kloxo-php-fpm.conf
# socket:    /opt/kloxo/init/kloxo-phpcgi.sock
systemctl daemon-reload
systemctl enable --now kloxo-php
systemctl status kloxo-php --no-pager
```

The unit runs `/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /opt/kloxo/init/kloxo-php-fpm.conf`.
The pool still listens as root with mode `0666` (legacy). Do not consider that a security
default for production.

### 8. Panel Hiawatha (only if the binary exists)

```
# if you have a hiawatha binary:
install -m 0755 "$(command -v hiawatha)" /opt/kloxo/init/kloxo-hiawatha
# render ports into a live copy of the base config (defaults 7778 / 7777):
cp /opt/kloxo/init/hiawatha.conf.base /opt/kloxo/init/hiawatha.conf
sed -i 's/__nonssl_port__/7778/; s/__ssl_port__/7777/; s/__fpmdisabled__//' \
  /opt/kloxo/init/hiawatha.conf
cp /opt/kloxo/init/kloxo-web.service /etc/systemd/system/kloxo-web.service
systemctl daemon-reload
systemctl enable --now kloxo-web
```

Open in a browser: `https://YOUR-HOST:7777/` (self-signed cert; expect a warning).
Unauthenticated requests redirect to `/login/`. **Login against MariaDB is not verified.**

### 9. MariaDB for the panel (schema installer is still legacy)

The panel database name is `kloxo`, DB user `kloxo`. The schema loader is
`kloxo/bin/install/create.php`, written for CentOS/`lxphp.exe`/yum. On Ubuntu it is
**UNVERIFIED**. The P3 CLI does not replace it yet.

If you experiment anyway, you will need a MariaDB root account and:

```
cd /opt/kloxo/httpdocs
php ../bin/install/create.php --install-type=master \
  --db-rootuser=root --db-rootpassword=YOUR_ROOT_DB_PASS \
  --admin-password=YOUR_ADMIN_UI_PASS
```

Expect CentOS-specific steps inside that script to fail. Do not treat a partial run as a
working panel.

### 10. Mail stack (independent of the panel UI)

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
php kloxo/bin/install/generate-tls-keys --help
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
  file/           service config templates (nginx, bind, pdns, qmail, mail)
  install/        LEGACY RPM/CentOS installer (do not use on Ubuntu)
  bin/kloxocp            operator CLI (status, doctor, snapshot, rollback)
  bin/mail/kloxo-mail    mail provisioning tool (PHP 8.3)
  bin/install/generate-tls-keys  unique TLS keys per install
install.sh        Ubuntu 24.04 bootstrap (clone + apt + layout + TLS)
tests/            mail, tls, remote, security, p1, p2, p3
docs/             audit, design, mail stack, changelog, legacy notes
```

## Licensing and naming

Free software under the **GNU Affero General Public License v3** (`LICENSE.txt`).
If you run a modified version as a network service you must offer its source to its users.
Upstream copyright notices are preserved; see `NOTICE`. "Kloxo" is the name of the upstream
projects: this fork is **not affiliated with or endorsed by** LxCenter or MRatWork.
