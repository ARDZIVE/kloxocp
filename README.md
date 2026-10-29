# Kloxo CP

A web hosting control panel, derived from **Kloxo-MR** (itself a fork of **Kloxo** by LxLabs / LxCenter).

> **Status: 0.1.0-dev, NOT FOR PRODUCTION.**
> Do not expose this on a public network. The legacy PHP is still PHP 5-era in behaviour,
> the one-command installer is not built yet, and a full UI login against MariaDB is not
> verified. First-party PHP now **parses and loads** on PHP 8 (`include.php` boot smoke).
>
> Read `docs/AUDIT_REPORT.md` (security and PHP 8), `docs/INSTALL_AND_UPDATE_DESIGN.md`
> (the intended production installer), and `docs/MAIL_DESIGN.md` (mail stack, which is
> implemented and tested).

## Goals

| Item | Decision |
|---|---|
| Install | One command, under 10 minutes on a clean server (designed, not built) |
| Updates | One command or one button, with backup, health check and rollback (designed, not built) |
| Target OS | Ubuntu 24.04 LTS first; AlmaLinux/Rocky 9 later |
| Customer websites | Nginx + PHP-FPM (multiple PHP versions side by side) |
| Mail | Postfix + Dovecot + Rspamd, mailboxes in MariaDB |
| Panel's own web server | Hiawatha (panel UI only) |
| Billing integrations | None for now |
| UI | Existing UI kept |
| License | GNU AGPL v3 (unchanged) |

## Installation

There is **no** `install.sh` / apt repo yet (`docs/INSTALL_AND_UPDATE_DESIGN.md` is the
design). What follows is the current **manual** path on a disposable Ubuntu 24.04 machine
as root. It is the procedure that matches the code in this tree. Re-run commands are
idempotent where noted.

A Mac or other non-Ubuntu host can run the php-cli tests and generate TLS keys. It cannot
run Hiawatha, the systemd units, or the live mail tests.

### 0. What this procedure does and does not do

**Does:** unpack the tree under `/opt/kloxo`, generate unique TLS keys, point `lxphp.exe`
at distro PHP 8, create runtime directories, install OS packages, optionally configure
the mail stack, and confirm `include.php` loads.

**Does not (yet):** a signed apt package, automatic rollback installer, Let's Encrypt for
the panel hostname, a verified Hiawatha Ubuntu package, or a verified admin login through
the browser. The legacy `kloxo/install/` scripts are CentOS/RPM-only — do not run them on
Ubuntu.

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
**UNVERIFIED**. A future P3 installer will replace it.

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
php kloxo/bin/install/generate-tls-keys --help
php kloxo/bin/mail/kloxo-mail help
```

### Intended future install (not implemented)

```
wget https://<project-domain>/install.sh
sha256sum -c install.sh.sha256
sudo bash install.sh
```

That path is specified in `docs/INSTALL_AND_UPDATE_DESIGN.md`. Do not look for those
URLs yet (`kloxocp.invalid` is a placeholder).

## Layout

```
kloxo/            panel source; installs to /opt/kloxo
  httpdocs/       web UI, libraries, drivers, login/
  bin/ sbin/ pscript/   backend and maintenance scripts
  cexe/           C backend daemon (source only; setuid helpers removed)
  init/           panel systemd units, Hiawatha, PHP-FPM pool, php-cli.sh
  file/           service config templates (nginx, bind, pdns, qmail, mail)
  install/        LEGACY RPM/CentOS installer (do not use on Ubuntu)
  bin/mail/kloxo-mail    mail provisioning tool (PHP 8.3)
  bin/install/generate-tls-keys  unique TLS keys per install
tests/            mail, tls, remote, security, p1, p2
docs/             audit, design, mail stack, changelog, legacy notes
```

## Licensing and naming

Free software under the **GNU Affero General Public License v3** (`LICENSE.txt`).
If you run a modified version as a network service you must offer its source to its users.
Upstream copyright notices are preserved; see `NOTICE`. "Kloxo" is the name of the upstream
projects: this fork is **not affiliated with or endorsed by** LxCenter or MRatWork.
