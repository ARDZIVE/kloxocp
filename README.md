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
> Ubuntu 24.04 / 26.x `install.sh` clones https://github.com/ARDZIVE/kloxocp.git,
> builds pinned Hiawatha 12.4, and runs `init-panel-db`.
> `kloxocp update` and signed apt packages are not built.
>
> Read `docs/AUDIT_REPORT.md` (security and PHP 8), `docs/INSTALL_AND_UPDATE_DESIGN.md`
> (the intended production installer), and `docs/MAIL_DESIGN.md` (mail stack, which is
> implemented and tested).

## Goals

| Item | Decision |
|---|---|
| Install | One command on Ubuntu 24.04 or 26.x (`install.sh` clones this git tree and bootstraps; apt repo not built) |
| Updates | One command or one button, with backup, health check and rollback (`snapshot`/`rollback` exist; `update` not built) |
| Target OS | Ubuntu 24.04 LTS and Ubuntu 26.x; AlmaLinux/Rocky 9 later |
| Customer websites | Nginx + PHP-FPM (multiple PHP versions side by side) |
| Mail | Postfix + Dovecot + Rspamd, mailboxes in MariaDB |
| Panel's own web server | Hiawatha (panel UI only) |
| Billing integrations | None for now |
| UI | Existing UI kept |
| License | GNU AGPL v3 (unchanged) |

## Installation

**Ubuntu 24.04 or 26.x, one line (as root).** Clones
https://github.com/ARDZIVE/kloxocp.git (public, branch `main`) if needed,
then runs `install.sh`. Safe to repeat after a failed or partial install:

```
sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y git ca-certificates && { test -f /usr/local/src/kloxo-cp/install.sh || git clone --depth 1 --branch main https://github.com/ARDZIVE/kloxocp.git /usr/local/src/kloxo-cp; } && bash /usr/local/src/kloxo-cp/install.sh'
```

Do **not** pipe `install.sh` into bash (`curl | bash`): apt would read stdin.
The line above is the supported form. Do not run this on a machine that
already has cPanel, Plesk, or ISPConfig. It installs whatever is on GitHub
`main`. To install this working tree instead, copy it to the server and run
`sudo bash install.sh` from that directory.

When it finishes, the installer prints a box with the panel URL
(`https://<ip>:7777/`), username `admin`, and password `admin`. Accept the
self-signed certificate and change the password on first login. If the page
does not load, allow TCP 7777 (and 7778) in the host firewall.

`install.sh` installs distro packages, links `/opt/kloxo` to the clone,
generates TLS keys, starts `kloxo-php` when a distro `php-fpm` is present
(8.3 on 24.04, 8.5 on 26.04),
builds pinned Hiawatha 12.4, loads the panel MariaDB schema
(`init-panel-db`, including the `localhost` server row), and installs
phpMyAdmin under `httpdocs/thirdparty/phpMyAdmin`. Default login is
**admin / admin**; the panel blocks the rest of the UI until that password
is changed. The password is also written to `/root/kloxo-admin-password`
(mode 0600). Override with `KLOXO_ADMIN_PASSWORD` (min 8 characters).
Machines under ~2 GB RAM or 10 GB free disk need `--force`. It does not
run `kloxo-mail apply` unless you pass `--profile=full` for mail *packages*
only, and does not prove a browser login. Log: `/var/log/kloxo-install.log`.

From an existing clone (re-run or recover; do not `git clone` again):

```
sudo bash /usr/local/src/kloxo-cp/install.sh
sudo bash /usr/local/src/kloxo-cp/install.sh --update    # git fetch main, then re-run
sudo bash /usr/local/src/kloxo-cp/install.sh --force     # below 2 GB RAM / 10 GB disk
sudo bash /usr/local/src/kloxo-cp/install.sh --profile=full    # also apt-install postfix/dovecot/rspamd
sudo bash /usr/local/src/kloxo-cp/install.sh --help
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
at distro PHP 8, create runtime directories, install OS packages, build pinned
Hiawatha 12.4, load the panel MariaDB schema (`init-panel-db`, including the
`localhost` server), install phpMyAdmin, start `kloxo-php` / `kloxo-web`,
optionally apt-install mail packages (`--profile=full`), and confirm
`include.php` loads.

**Does not (yet):** a signed apt package, Let's Encrypt for the panel hostname, or
a verified admin login through the browser. The legacy `kloxo/install/` scripts
are CentOS/RPM-only — do not run them on Ubuntu.

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

Hiawatha is **not** in Ubuntu 24.04 or 26.x. `install.sh` (or the command in section 8)
builds pinned 12.4. PHP-FPM for the panel can still be configured first.

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
# FastCGI:   127.0.0.1:39000
systemctl daemon-reload
systemctl enable --now kloxo-php
systemctl status kloxo-php --no-pager
```

The unit runs `/opt/kloxo/init/kloxo-php-fpm.sh`, which execs the distro
`php-fpm` (`/usr/sbin/php-fpm8.3` on 24.04, `/usr/sbin/php-fpm8.5` on 26.04).
The pool still listens as root with mode `0666` (legacy). Do not consider that a security
default for production.

### 8. Panel Hiawatha (pinned 12.4 source build)

Ubuntu 24.04 and 26.x have no `hiawatha` package. Build the pinned release, render the
config, and enable `kloxo-web`:

```
sudo bash /opt/kloxo/bin/install/install-hiawatha --root=/opt/kloxo
systemctl status kloxo-web --no-pager
ss -lnt | grep -E ':7777|:7778'
```

`install-hiawatha` downloads
`https://hiawatha.leisink.net/files/download/hiawatha-12.4.tar.gz` (sha256
pinned in the script), copies the binary to `/opt/kloxo/init/kloxo-hiawatha`,
writes `hiawatha.conf` from `hiawatha.conf.base` (ports 7778 / 7777), checks it
with `hiawatha -k -c /opt/kloxo/init`, and starts `kloxo-web`. Pass `--force` to
rebuild. Pass `--dry-run` to print the plan.

If `hiawatha -k` names a bad directive, paste that output before editing
`hiawatha.conf` by hand. Do not keep `MinTLSversion` (removed in Hiawatha 11).

Open in a browser: `https://YOUR-HOST:7777/` (self-signed cert; expect a warning).
Unauthenticated requests redirect to `/login/`. Log in as **admin / admin**
and change the password when the panel asks. **Login against MariaDB is not verified.**
The IP list is read from `ip -4 addr` on Ubuntu (Refresh is safe). Do not use
Add / Update / Delete on that page: those still write CentOS-style network
files and can take the host off the network.

phpMyAdmin is installed at `/thirdparty/phpMyAdmin/` by
`kloxo/bin/install/install-phpmyadmin` (not shipped in git). A 405 from
Hiawatha on that URL means the directory has no `index.php`; run the
installer as root.

### 9. MariaDB for the panel

The panel database name is `kloxo`, DB user `kloxo`. Use the Ubuntu installer
(not the backgrounded CentOS `mysql … &` path):

```
# recover a half-finished install, or create a fresh schema:
sudo KLOXO_ADMIN_PASSWORD='YOUR_ADMIN_UI_PASS' \
  php /opt/kloxo/bin/install/init-panel-db \
  --root=/opt/kloxo --admin-password-env=KLOXO_ADMIN_PASSWORD --db-rootuser=root
# Ubuntu MariaDB root is the unix socket; omit --db-rootpassword
```

`init-panel-db` is idempotent (`CREATE TABLE IF NOT EXISTS`, insert-or-update
admin). It writes `/opt/kloxo/etc/conf/kloxo.pass` (0600). With no override
it seeds **admin / admin** (the UI then requires a change). A custom
`--admin-password` must be at least 8 characters.

The legacy wrapper still works from `httpdocs` and now calls `init-panel-db`:

```
cd /opt/kloxo/httpdocs
php ../bin/install/create.php --install-type=master \
  --db-rootuser=root --db-rootpassword= \
  --admin-password=YOUR_ADMIN_UI_PASS
```

Do not treat a run that only created `general` (0 rows, no `client` table) as a
working panel; re-run `init-panel-db`. Browser login is still **UNVERIFIED**.

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
bash tests/install/run-tests.sh
php kloxo/bin/install/generate-tls-keys --help
php kloxo/bin/install/init-panel-db --help
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
  file/           service config templates (nginx, bind, pdns, qmail, mail)
  install/        LEGACY RPM/CentOS installer (do not use on Ubuntu)
  bin/kloxocp            operator CLI (status, doctor, snapshot, rollback)
  bin/mail/kloxo-mail    mail provisioning tool (PHP 8.3)
  bin/install/generate-tls-keys  unique TLS keys per install
install.sh        Ubuntu 24.04 / 26.x bootstrap (clone + apt + layout + TLS)
tests/            mail, tls, remote, security, p1, p2, p3
docs/             audit, design, mail stack, changelog, legacy notes
```

## Licensing and naming

Free software under the **GNU Affero General Public License v3** (`LICENSE.txt`).
If you run a modified version as a network service you must offer its source to its users.
Upstream copyright notices are preserved; see `NOTICE`. "Kloxo" is the name of the upstream
projects: this fork is **not affiliated with or endorsed by** LxCenter or MRatWork.
