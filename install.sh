#!/bin/bash
# Kloxo CP bootstrap for Ubuntu 24.04 LTS and Ubuntu 26.x.
#
# Install (downloads this script from GitHub main, then clones or fetches origin/main):
#   sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
#   apt-get update
#   apt-get install -y ca-certificates curl
#   curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh -o /tmp/kloxocp-install.sh
#   bash /tmp/kloxocp-install.sh'
# One line:
#   sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y ca-certificates curl && curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'
#
# Optional: KLOXO_GIT_TOKEN for a private fork. Do not pipe this file into bash
# (apt would steal stdin); wget/curl to a file, then bash it.
#
# Does: pre-flight, clone (if needed), apt packages, /opt/kloxo layout, TLS keys,
# lxphp.exe wrappers, kloxo-php systemd unit, pinned Hiawatha 12.4 build,
# panel MariaDB schema via init-panel-db, phpMyAdmin under httpdocs/thirdparty,
# site PHP-FPM 5.6 / 7.4 / latest (install-site-php; default latest),
# PowerDNS packaged and prepared (disabled; BIND stays default on 53),
# Apache packaged and prepared (disabled; Nginx stays default on 80/443),
# MariaDB enabled as the default SQL (mysqld/mysql aliases not separately enabled),
# imagemagick for File Manager Thumbnail, Exim default MTA on --profile=full
# (Postfix is not co-installed; Switch Program installs Postfix),
# ufw packaged and left inactive (enable from Security → UFW Firewall),
# webcache (8080) and webproxy (30080) as nginx frontends (install-webfront),
# kloxo-backend (lxserver). --slave writes slave-db.db, installs site PHP,
# and skips the panel UI.
# Does not: Let's Encrypt, signed kloxocp apt repo, kloxo-mail apply, verified two-host add.
set -euo pipefail
LOG="/dev/null"

KLOXO_GIT_URL="${KLOXO_GIT_URL:-https://github.com/ARDZIVE/kloxocp.git}"
KLOXO_GIT_BRANCH="${KLOXO_GIT_BRANCH:-main}"
KLOXO_SRC_DEFAULT="/usr/local/src/kloxo-cp"
KLOXO_ROOT_DEFAULT="/opt/kloxo"
KCP_VERSION="10.1-2026110402"

# Default is full (Exim/Dovecot/Rspamd). Use --profile=core for a panel-only host.
PROFILE="full"
FORCE=0
DRY=0
UPDATE=0
WITH_MAIL=1
INSTALL_TYPE="master"
SLAVE_PASSWORD=""
SRC=""
ROOT="$KLOXO_ROOT_DEFAULT"

usage() {
  cat <<EOF
Kloxo CP ${KCP_VERSION} installer (Ubuntu 24.04 or 26.x)

  sudo bash install.sh [--profile=full|core] [--yes] [--force]
  sudo bash install.sh --slave --slave-password=SECRET

  --profile=full   default: core plus exim4-daemon-heavy (default MTA), dovecot, rspamd
                   (Postfix is not apt-installed here; it conflicts with Exim)
  --profile=core   php, nginx, mariadb, bind9, pure-ftpd, redis-server, ufw (inactive), imagemagick, webcache:8080, webproxy:30080; apache2/pdns packaged, disabled
  --slave          backend-only host; no panel UI (Kloxo-MR slave)
  --slave-password=SECRET
                   password the master uses when adding this host
  --yes            non-interactive (assumed when stdin is not a TTY)
  --force          continue below 2 GB RAM / 10 GB disk
  --update         accepted; every run already git-fetches the clone
  --dry-run        print the plan and exit
  --src=DIR        clone location (default: ${KLOXO_SRC_DEFAULT})
  --root=DIR       program root (default: ${KLOXO_ROOT_DEFAULT})
  --branch=NAME    git branch (default: ${KLOXO_GIT_BRANCH})
  --repo=URL       git URL (default: ${KLOXO_GIT_URL})
  --help

Default panel login is admin/admin; the UI requires a password change
after the first login. Override with KLOXO_ADMIN_PASSWORD (min 8 chars).
Slave password: --slave-password= or KLOXO_SLAVE_PASSWORD (min 8 chars).

Install on Ubuntu 24.04 or 26.x (root). Downloads this script from GitHub main,
then clones or fetches origin/main:

  sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y ca-certificates curl
  curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh -o /tmp/kloxocp-install.sh
  bash /tmp/kloxocp-install.sh'

One line:

  sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y ca-certificates curl && curl -fsSL https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'

Source: https://github.com/ARDZIVE/kloxocp.git (public, branch main).
This tree is NOT production-safe. Version ${KCP_VERSION}.
EOF
}

log() { printf '%s\n' "$*" | tee -a "$LOG" ; }
die() { printf 'error: %s\n' "$*" | tee -a "$LOG" >&2 ; exit 1; }

wait_unit() {
  local unit="$1" i
  for i in 1 2 3 4 5 6 7 8 9 10; do
    if systemctl is-active --quiet "$unit"; then
      return 0
    fi
    sleep 1
  done
  return 1
}

force_fcgi_tcp() {
  if [[ -f "$ROOT/init/kloxo-php-fpm.conf" ]]; then
    sed -i -E 's|^listen[[:space:]]*=.*|listen = 127.0.0.1:39000|' "$ROOT/init/kloxo-php-fpm.conf"
  fi
  if [[ -f "$ROOT/init/hiawatha.conf" ]]; then
    sed -i -E 's|^[[:space:]]*ConnectTo[[:space:]]*=.*|	ConnectTo = 127.0.0.1:39000|' "$ROOT/init/hiawatha.conf"
    sed -i 's/__fpmdisabled__//g' "$ROOT/init/hiawatha.conf"
  fi
}

print_slave_box() {
  local host="$1" ip="$2" pass="$3"
  local w=70 bar
  bar="+$(printf '%0.s-' $(seq 1 $((w + 2))))+"
  box() { printf '| %-*s |\n' "$w" "$1"; }
  echo
  echo "$bar"
  box "Kloxo CP ${KCP_VERSION} slave is installed."
  box ""
  box "On the master panel: Servers -> Add, then:"
  box "  IP or hostname : ${ip:-$host}"
  box "  Password       : ${pass}"
  box ""
  box "Allow TCP 7779 from the master (TLS 1.2+). Port 7776 stays localhost."
  box "This host has no panel UI on 7777."
  echo "$bar"
  echo
}

print_access_box() {
  local url="$1" alt="$2" user="$3" pass="$4"
  local w=70 bar
  bar="+$(printf '%0.s-' $(seq 1 $((w + 2))))+"
  box() { printf '| %-*s |\n' "$w" "$1"; }
  echo
  echo "$bar"
  box "Kloxo CP ${KCP_VERSION} is installed."
  box ""
  box "Open this address in your browser:"
  box "  ${url}"
  if [[ -n "$alt" && "$alt" != "$url" ]]; then
    box "  ${alt}"
  fi
  box ""
  box "Log in with:"
  box "  Username : ${user}"
  box "  Password : ${pass}"
  box ""
  box "Accept the self-signed certificate warning."
  box "Change the password on the first login; the panel requires it."
  box ""
  box "If the page does not load, allow TCP 7777 (and 7778) in the firewall."
  box "Password is also saved in /root/kloxo-admin-password (mode 0600)."
  echo "$bar"
  echo
}

for a in "$@"; do
  case "$a" in
    --help|-h) usage; exit 0 ;;
    --yes|-y) ;;
    --force) FORCE=1 ;;
    --dry-run) DRY=1 ;;
    --update) UPDATE=1 ;;
    --with-mail|--profile=full) PROFILE="full"; WITH_MAIL=1 ;;
    --profile=core) PROFILE="core"; WITH_MAIL=0 ;;
    --profile=*) die "unknown profile (use core or full)" ;;
    --slave) INSTALL_TYPE="slave" ;;
    --slave-password=*) INSTALL_TYPE="slave"; SLAVE_PASSWORD="${a#--slave-password=}" ;;
    --src=*) SRC="${a#--src=}" ;;
    --root=*) ROOT="${a#--root=}" ;;
    --branch=*) KLOXO_GIT_BRANCH="${a#--branch=}" ;;
    --repo=*) KLOXO_GIT_URL="${a#--repo=}" ;;
    *) die "unknown option: $a (try --help)" ;;
  esac
done

if [[ "$ROOT" == *..* ]]; then
  die "invalid --root"
fi
if [[ -n "$SRC" && "$SRC" == *..* ]]; then
  die "invalid --src"
fi

HERE=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
  HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi
if [[ -z "$SRC" ]]; then
  if [[ -n "$HERE" && -f "$HERE/kloxo/bin/kloxocp" ]]; then
    SRC="$HERE"
  else
    SRC="$KLOXO_SRC_DEFAULT"
  fi
fi

if [[ "$(id -u)" -eq 0 ]]; then
  mkdir -p /var/log
  LOG="/var/log/kloxo-install.log"
  touch "$LOG"
  chmod 600 "$LOG"
fi

if [[ "$DRY" -eq 1 ]]; then
  cat <<EOF
plan: Kloxo CP ${KCP_VERSION}
  repo    $KLOXO_GIT_URL (branch $KLOXO_GIT_BRANCH)
  src     $SRC
  root    $ROOT
  profile $PROFILE
  type    $INSTALL_TYPE
  sitephp 5.6, 7.4, latest (install-site-php; default latest)
  dns     bind9 enabled (default); pdns-server prepared and disabled
  web     nginx enabled (default); apache2 prepared and disabled
  cache   kloxo-webcache :8080 and kloxo-webproxy :30080 (nginx; no varnish/squid)
  smtp    exim4 default (full); postfix via Switch Program (cannot co-install)
  ftp     pure-ftpd (enabled)
  redis   redis-server (enabled; Rspamd Bayes backend)
  skip    no bogofilter/spamassassin/sqlite apt packages
  token   $([ -n "${KLOXO_GIT_TOKEN:-}" ] && echo set || echo unset)
EOF
  exit 0
fi

[[ "$(id -u)" -eq 0 ]] || die "run as root (sudo bash install.sh)"

export DEBIAN_FRONTEND=noninteractive
# Piped invocation must not let apt/debconf consume the script.
exec </dev/null

os_id=""; os_ver=""; os_pretty=""
if [[ -r /etc/os-release ]]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  os_id="${ID:-}"
  os_ver="${VERSION_ID:-}"
  os_pretty="${PRETTY_NAME:-}"
fi
kloxo_ubuntu_ok() {
  # 24.04 LTS (PHP 8.3) and 26.x (26.04 LTS / 26.10; PHP 8.5).
  [[ "${1:-}" == "ubuntu" ]] || return 1
  case "${2:-}" in
    24.04*|26.*) return 0 ;;
    *) return 1 ;;
  esac
}
kloxo_ubuntu_ok "$os_id" "$os_ver" || die "Ubuntu 24.04 or 26.x required (found ${os_pretty:-unknown})"

for p in /usr/local/cpanel /usr/local/psa /usr/local/ispconfig; do
  [[ -d "$p" ]] && die "another control panel is present at $p"
done

mem_kb=$(awk '/MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
disk_kb=$(df -Pk / | awk 'NR==2 {print $4}')
if [[ "$mem_kb" -lt 1800000 || "$disk_kb" -lt 10485760 ]]; then
  msg="need ~2 GB RAM and 10 GB free disk (ram_kb=$mem_kb disk_kb=$disk_kb)"
  if [[ "$FORCE" -eq 1 ]]; then
    log "warn: $msg (continuing with --force)"
  else
    die "$msg (pass --force to override)"
  fi
fi

log "Kloxo CP ${KCP_VERSION} install starting $(date -u +%Y-%m-%dT%H:%M:%SZ)"
log "host=$(hostname -f 2>/dev/null || hostname) os=${os_pretty} profile=${PROFILE}"

# apt postinst starts packaged daemons. pdns would fail (no gmysql yet, and
# BIND owns port 53) and apache2 would race Nginx on 80/443. policy-rc.d 101
# skips those starts without failing the package; a runtime mask covers
# postinst that calls systemctl directly. BIND, Nginx, and Exim still start.
# Postfix is not installed with Exim (mail-transport-agent conflict).
KLOXO_POLICY_RC=/usr/sbin/policy-rc.d
KLOXO_POLICY_PREV=/usr/sbin/policy-rc.d.kloxo-prev
KLOXO_POLICY_OWNED=0
hold_standby_units() {
  log "holding pdns and apache2 until Switch Program selects them"
  if [[ -e "$KLOXO_POLICY_RC" ]] && ! grep -q 'Temporary Kloxo CP policy' "$KLOXO_POLICY_RC" 2>/dev/null; then
    if [[ ! -e "$KLOXO_POLICY_PREV" ]]; then
      mv "$KLOXO_POLICY_RC" "$KLOXO_POLICY_PREV"
    fi
  fi
  cat > "$KLOXO_POLICY_RC" <<'EOF'
#!/bin/sh
# Temporary Kloxo CP policy during install. BIND, Nginx, and Exim stay the listeners.
case "${1:-}" in
  pdns|pdns.service|pdns-server|apache2|apache2.service)
    exit 101
    ;;
esac
if [ -x /usr/sbin/policy-rc.d.kloxo-prev ]; then
  exec /usr/sbin/policy-rc.d.kloxo-prev "$@"
fi
exit 0
EOF
  chmod 0755 "$KLOXO_POLICY_RC"
  KLOXO_POLICY_OWNED=1
  systemctl mask --runtime pdns.service apache2.service >/dev/null 2>&1 \
    || systemctl mask pdns.service apache2.service >/dev/null 2>&1 \
    || true
}
release_standby_units() {
  systemctl unmask --runtime pdns.service apache2.service >/dev/null 2>&1 || true
  systemctl unmask pdns.service apache2.service >/dev/null 2>&1 || true
  systemctl reset-failed pdns apache2 >/dev/null 2>&1 || true
  systemctl disable --now pdns apache2 >/dev/null 2>&1 || true
  if [[ "${KLOXO_POLICY_OWNED:-0}" -eq 1 ]]; then
    rm -f "$KLOXO_POLICY_RC"
    if [[ -e "$KLOXO_POLICY_PREV" ]]; then
      mv "$KLOXO_POLICY_PREV" "$KLOXO_POLICY_RC"
    fi
    KLOXO_POLICY_OWNED=0
  fi
}
trap release_standby_units EXIT
hold_standby_units

# pdns / pure-ftpd / rspamd live in universe on Ubuntu cloud images.
ensure_ubuntu_universe() {
  local f changed=0
  for f in /etc/apt/sources.list.d/ubuntu.sources /etc/apt/sources.list; do
    [[ -f "$f" ]] || continue
    if grep -qE '^\s*Components:.*\buniverse\b' "$f" 2>/dev/null \
      || grep -qE '^deb .* universe' "$f" 2>/dev/null; then
      continue
    fi
    if grep -qE '^\s*Components:' "$f" 2>/dev/null; then
      sed -i -E 's/^(Components:.*\bmain\b)(.*)$/\1 universe\2/' "$f" || true
      changed=1
    elif grep -qE '^deb ' "$f" 2>/dev/null; then
      sed -i -E 's/^(deb .* main)( restricted)?(.*)$/\1\2 universe\3/' "$f" || true
      changed=1
    fi
  done
  if [[ "$changed" -eq 1 ]]; then
    log "enabled Ubuntu universe component for pure-ftpd / pdns / rspamd"
  fi
}
ensure_ubuntu_universe

apt-get update -y
# Core: panel + sites + DNS + FTP + Redis (Rspamd Bayes backend). Mail spam is
# Rspamd (full profile), not bogofilter/spamassassin. Panel DB is MariaDB (the
# Sqlite class is mysqli); slave-db.db is PHP serialize — do not apt-install sqlite.
apt-get install -y ca-certificates git curl openssl rsync \
  php-cli php-fpm php-mysql php-xml php-mbstring php-curl \
  mariadb-server nginx apache2 bind9 pdns-server pdns-backend-mysql \
  pure-ftpd redis-server iproute2 ufw imagemagick \
  build-essential cmake make gcc pkg-config libssl-dev zlib1g-dev

if [[ "$WITH_MAIL" -eq 1 ]]; then
  # Exim and Postfix both Provide/Conflict mail-transport-agent — never
  # apt-install them in one transaction. Default MTA is Exim; Switch Program
  # smtp=postfix removes Exim and installs Postfix.
  echo "exim4-config exim4/dc_eximconfigconfigtype select none" | debconf-set-selections
  echo "exim4-config exim4/dc_use_split_config boolean false" | debconf-set-selections
  apt-get install -y exim4-daemon-heavy \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd \
    dovecot-mysql dovecot-sieve dovecot-managesieved \
    rspamd
fi

log "Hiawatha is not an Ubuntu package; will build pinned 12.4 from source"

clone_url="$KLOXO_GIT_URL"
if [[ -n "${KLOXO_GIT_TOKEN:-}" ]]; then
  case "$KLOXO_GIT_URL" in
    https://github.com/*)
      clone_url="https://x-access-token:${KLOXO_GIT_TOKEN}@${KLOXO_GIT_URL#https://}"
      ;;
  esac
fi

if [[ ! -f "$SRC/kloxo/bin/kloxocp" ]]; then
  if [[ -d "$SRC/.git" ]]; then
    log "updating clone in $SRC"
    git -C "$SRC" fetch --depth 1 origin "$KLOXO_GIT_BRANCH"
    git -C "$SRC" checkout -f -B "$KLOXO_GIT_BRANCH" FETCH_HEAD
    git -C "$SRC" reset --hard FETCH_HEAD
  else
    mkdir -p "$(dirname "$SRC")"
    if [[ -d "$SRC" && -n "$(ls -A "$SRC" 2>/dev/null)" ]]; then
      die "$SRC exists but is not a Kloxo CP tree"
    fi
    log "cloning $KLOXO_GIT_URL ($KLOXO_GIT_BRANCH) -> $SRC"
    if ! git clone --depth 1 --branch "$KLOXO_GIT_BRANCH" "$clone_url" "$SRC"; then
      die "git clone failed from $KLOXO_GIT_URL (check network, or set KLOXO_GIT_TOKEN)"
    fi
  fi
elif [[ -d "$SRC/.git" ]]; then
  log "updating clone in $SRC"
  git -C "$SRC" fetch --depth 1 origin "$KLOXO_GIT_BRANCH"
  git -C "$SRC" checkout -f -B "$KLOXO_GIT_BRANCH" FETCH_HEAD
  git -C "$SRC" reset --hard FETCH_HEAD
fi

if [[ -d "$SRC/.git" ]]; then
  git -C "$SRC" remote set-url origin "$KLOXO_GIT_URL" || true
fi

[[ -f "$SRC/kloxo/bin/kloxocp" ]] || die "clone is missing kloxo/bin/kloxocp"

mkdir -p /home/kloxo/httpd/cp /home/kloxo/client /home/kloxomail/mail
if [[ -L "$ROOT" || ! -e "$ROOT" ]]; then
  ln -sfn "$SRC/kloxo" "$ROOT"
elif [[ -d "$ROOT/httpdocs" && -d "$ROOT/bin" ]]; then
  log "using existing program root $ROOT"
  src_kloxo="$(cd "$SRC/kloxo" && pwd)"
  root_real="$(cd "$ROOT" && pwd)"
  if [[ "$root_real" != "$src_kloxo" ]]; then
    log "syncing program files from $SRC/kloxo to $ROOT"
    rsync -a --delete \
      --exclude 'thirdparty/phpMyAdmin/' \
      --exclude 'login/images/user-logo.png' \
      "$SRC/kloxo/httpdocs/" "$ROOT/httpdocs/"
    rsync -a "$SRC/kloxo/bin/" "$ROOT/bin/"
    rsync -a --exclude 'kloxo-phpcgi.pid' --exclude 'kloxo-phpcgi.sock' \
      --exclude 'hiawatha.conf' \
      "$SRC/kloxo/init/" "$ROOT/init/"
  fi
else
  die "$ROOT exists and is not a Kloxo program root"
fi
mkdir -p "$ROOT/etc/conf" "$ROOT/etc/flag" "$ROOT/pid" "$ROOT/log"

if ! id -u lxlabs >/dev/null 2>&1; then
  useradd --system --home /home/kloxo --shell /usr/sbin/nologin --user-group lxlabs
fi
chown -R lxlabs:lxlabs /home/kloxo /home/kloxomail || true
ln -sfn "$ROOT/pscript" /script
ln -sfn "$ROOT/init/php-cli.sh" /usr/bin/lxphp.exe
ln -sfn "$ROOT/init/php-cli.sh" /usr/bin/lphp.exe
ln -sfn "$ROOT/bin/kloxocp" /usr/local/sbin/kloxocp
chmod +x "$ROOT/init/php-cli.sh" "$ROOT/bin/kloxocp" \
  "$ROOT/init/kloxo-backend.sh" \
  "$ROOT/bin/install/generate-tls-keys" \
  "$ROOT/bin/install/init-panel-db" \
  "$ROOT/bin/install/init-slave" \
  "$ROOT/bin/install/install-hiawatha" \
  "$ROOT/bin/install/install-phpmyadmin" \
  "$ROOT/bin/install/install-site-php" || true

if [[ ! -f "$ROOT/init/backend.key" ]]; then
  php "$ROOT/bin/install/generate-tls-keys" --root="$ROOT" --hostname="$(hostname -f 2>/dev/null || hostname)"
fi
if [[ -f "$ROOT/file/ssl/default.pem" && ! -f "$ROOT/etc/program.pem" ]]; then
  install -m 0600 "$ROOT/file/ssl/default.pem" "$ROOT/etc/program.pem"
fi
if [[ -f "$ROOT/etc/program.pem" ]]; then
  chown lxlabs:lxlabs "$ROOT/etc/program.pem" || true
  chmod 0600 "$ROOT/etc/program.pem"
fi

install -d -m 0755 "$ROOT/init" "$ROOT/log" "$ROOT/session" "$ROOT/pid"
if id -u lxlabs >/dev/null 2>&1; then
  chown lxlabs:lxlabs "$ROOT/log" "$ROOT/session" "$ROOT/pid" || true
fi

wait_mariadb() {
  local i
  for i in $(seq 1 20); do
    if mariadb --protocol=socket -u root -e 'SELECT 1' >/dev/null 2>&1 \
      || mysql --protocol=socket -u root -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

enable_default_stack() {
  # Default SQL is MariaDB only. Do not enable mysql/mysqld by name (aliases of
  # mariadb on Ubuntu; a separate mysql-server package stays stopped until the
  # admin starts it from Services).
  systemctl enable --now nginx mariadb named 2>/dev/null \
    || systemctl enable --now nginx mariadb bind9 2>/dev/null || true
  systemctl enable --now redis-server 2>/dev/null \
    || systemctl enable --now redis 2>/dev/null || true
  systemctl enable --now pure-ftpd 2>/dev/null || true
  systemctl reset-failed pdns apache2 2>/dev/null || true
  systemctl disable --now pdns 2>/dev/null || true
  systemctl disable --now apache2 2>/dev/null || true
  # If Oracle/community mysql.service is a distinct unit (not mariadb), leave it stopped.
  if systemctl cat mysql >/dev/null 2>&1; then
    frag_mysql="$(systemctl show -p FragmentPath --value mysql 2>/dev/null || true)"
    frag_maria="$(systemctl show -p FragmentPath --value mariadb 2>/dev/null || true)"
    if [[ -n "$frag_mysql" && -n "$frag_maria" && "$frag_mysql" != "$frag_maria" ]]; then
      systemctl disable --now mysql 2>/dev/null || true
    fi
  fi
}

prepare_apache() {
  chmod 0755 "$ROOT/bin/install/install-apache" || true
  php "$ROOT/bin/install/install-apache" --root="$ROOT" \
    || log "warn: install-apache failed; Switch Program can retry"
  systemctl disable --now apache2 2>/dev/null || true
  systemctl enable --now nginx 2>/dev/null || true
}

prepare_webfront() {
  chmod 0755 "$ROOT/bin/install/install-webfront" \
    "$ROOT/pscript/restart-webcache" "$ROOT/pscript/restart-webproxy" || true
  php "$ROOT/bin/install/install-webfront" --root="$ROOT" \
    || log "warn: install-webfront failed; webcache/webproxy stay down"
}

prepare_pdns() {
  chmod 0755 "$ROOT/bin/install/install-pdns" || true
  if ! wait_mariadb; then
    log "warn: MariaDB is not ready; PowerDNS schema skipped"
    return 0
  fi
  php "$ROOT/bin/install/install-pdns" --root="$ROOT" \
    || log "warn: install-pdns failed; Switch Program can retry"
}

install_backend() {
  chmod 0755 "$ROOT/init/kloxo-backend.sh" || true
  install -m 0644 "$ROOT/init/kloxo-backend.service" /etc/systemd/system/kloxo-backend.service
  systemctl daemon-reload
  systemctl enable kloxo-backend >/dev/null 2>&1 || true
  systemctl restart kloxo-backend || systemctl start kloxo-backend || true
  wait_unit kloxo-backend || log "warn: kloxo-backend is not active"
}

if [[ "$INSTALL_TYPE" == "slave" ]]; then
  slave_pw="${SLAVE_PASSWORD:-${KLOXO_SLAVE_PASSWORD:-}}"
  if [[ -z "$slave_pw" ]]; then
    die "slave install needs --slave-password= or KLOXO_SLAVE_PASSWORD"
  fi
  if [[ ${#slave_pw} -lt 8 ]]; then
    die "slave password must be at least 8 characters"
  fi
  KLOXO_SLAVE_PASSWORD="$slave_pw" php "$ROOT/bin/install/init-slave" \
    --root="$ROOT" --password-env=KLOXO_SLAVE_PASSWORD
  enable_default_stack
  prepare_apache
  prepare_webfront
  prepare_pdns
  log "installing site PHP 5.6, 7.4, and latest (default) on this slave"
  if ! bash "$ROOT/bin/install/install-site-php" --root="$ROOT"; then
    log "warn: extra site PHP versions failed; Webserver Configure can retry"
  fi
  install_backend
  host="$(hostname -f 2>/dev/null || hostname)"
  ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  print_slave_box "$host" "$ip" "$slave_pw" | tee -a "$LOG"
  exit 0
fi

php_fpm=""
for p in /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm; do
  if [[ -x "$p" ]]; then
    php_fpm="$p"
    break
  fi
done
chmod +x "$ROOT/init/kloxo-php-fpm.sh" || true
if [[ -z "$php_fpm" ]]; then
  die "no php-fpm binary (install php-fpm)"
fi
force_fcgi_tcp
if ! "$php_fpm" -y "$ROOT/init/kloxo-php-fpm.conf" --fpm-config "$ROOT/init/kloxo-php-fpm.conf" -t; then
  die "php-fpm rejected $ROOT/init/kloxo-php-fpm.conf ($php_fpm -t)"
fi
rm -f "$ROOT/init/kloxo-phpcgi.pid" "$ROOT/init/kloxo-phpcgi.sock"
cp -a "$ROOT/init/kloxo-php.service" /etc/systemd/system/kloxo-php.service
systemctl daemon-reload
systemctl enable kloxo-php >/dev/null
if ! systemctl start kloxo-php || ! wait_unit kloxo-php; then
  log "kloxo-php notify start failed; retrying as Type=simple"
  sed -i 's/^Type=notify/Type=simple/' /etc/systemd/system/kloxo-php.service
  systemctl daemon-reload
  systemctl reset-failed kloxo-php 2>/dev/null || true
  systemctl start kloxo-php || true
  wait_unit kloxo-php || log "warn: kloxo-php still not active (will retry after Hiawatha)"
fi

log "building Hiawatha 12.4 from source (not in Ubuntu apt)"
bash "$ROOT/bin/install/install-hiawatha" --root="$ROOT"

enable_default_stack
prepare_apache
prepare_webfront

if ! wait_mariadb; then
  die "MariaDB is not accepting unix_socket logins as root"
fi
prepare_pdns

admin_env="${KLOXO_ADMIN_PASSWORD:-admin}"
if [[ "$admin_env" != "admin" && ${#admin_env} -lt 8 ]]; then
  die "admin password must be at least 8 characters (or the default admin)"
fi
install -d -m 0700 /root
printf '%s\n' "$admin_env" > /root/kloxo-admin-password
chmod 600 /root/kloxo-admin-password
KLOXO_ADMIN_PASSWORD="$admin_env" php "$ROOT/bin/install/init-panel-db" \
    --root="$ROOT" --admin-password-env=KLOXO_ADMIN_PASSWORD --db-rootuser=root
if id -u lxlabs >/dev/null 2>&1; then
  chown -R lxlabs:lxlabs "$ROOT/etc/conf" || true
  chmod 0750 "$ROOT/etc/conf" || true
  if [[ -f "$ROOT/etc/conf/kloxo.pass" ]]; then
    chmod 0640 "$ROOT/etc/conf/kloxo.pass"
  fi
  if [[ -f "$ROOT/etc/conf/pdns.pass" ]]; then
    chmod 0640 "$ROOT/etc/conf/pdns.pass"
  fi
  if [[ -f "$ROOT/etc/conf/pma.pass" ]]; then
    chmod 0640 "$ROOT/etc/conf/pma.pass"
  fi
fi
log "panel schema ready"

bash "$ROOT/bin/install/install-phpmyadmin" --root="$ROOT"
log "installing site PHP 5.6, 7.4, and latest (default); panel kloxo-php is unchanged"
if ! bash "$ROOT/bin/install/install-site-php" --root="$ROOT"; then
  log "warn: extra site PHP versions failed; Webserver Configure can retry"
fi
force_fcgi_tcp
systemctl reset-failed kloxo-php 2>/dev/null || true
rm -f "$ROOT/init/kloxo-phpcgi.pid" "$ROOT/init/kloxo-phpcgi.sock"
systemctl restart kloxo-php || true
if ! wait_unit kloxo-php; then
  sed -i 's/^Type=notify/Type=simple/' /etc/systemd/system/kloxo-php.service
  systemctl daemon-reload
  systemctl reset-failed kloxo-php 2>/dev/null || true
  systemctl restart kloxo-php || true
fi
if ! wait_unit kloxo-php; then
  journalctl -u kloxo-php -n 40 --no-pager || true
  "$php_fpm" --fpm-config "$ROOT/init/kloxo-php-fpm.conf" -t || true
  die "kloxo-php is not active"
fi
fpm_ready=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if ss -lnt 2>/dev/null | grep -q ':39000' || netstat -lnt 2>/dev/null | grep -q ':39000'; then
    fpm_ready=1
    break
  fi
  sleep 1
done
if [[ "$fpm_ready" -ne 1 ]]; then
  journalctl -u kloxo-php -n 40 --no-pager || true
  die "kloxo-php is active but not listening on 127.0.0.1:39000"
fi
mkdir -p "$ROOT/var/hiawatha/upload" /usr/local/var/lib/hiawatha/upload
if id -u lxlabs >/dev/null 2>&1; then
  chown lxlabs:lxlabs "$ROOT/var/hiawatha" "$ROOT/var/hiawatha/upload" \
    /usr/local/var/lib/hiawatha /usr/local/var/lib/hiawatha/upload || true
fi
# Hiawatha caches a dead FastCGI backend; always bounce it after FPM is listening.
systemctl reset-failed kloxo-web 2>/dev/null || true
systemctl restart kloxo-web || systemctl start kloxo-web || true
if ! wait_unit kloxo-web; then
  journalctl -u kloxo-web -n 40 --no-pager || true
  die "kloxo-web is not active"
fi
ping=""
if command -v curl >/dev/null 2>&1; then
  ping="$(curl -sS --max-time 8 http://127.0.0.1:7778/fcgi-ping.php 2>/dev/null || true)"
fi
if [[ "$ping" != *ok* ]]; then
  log "warn: FastCGI ping did not return ok (kloxo-php/kloxo-web logs follow)"
  journalctl -u kloxo-php -n 20 --no-pager || true
  journalctl -u kloxo-web -n 20 --no-pager || true
  ss -lnt 2>/dev/null | grep 39000 || true
fi

export KLOXO_ROOT="$ROOT"
export KLOXO_PHP="${KLOXO_PHP:-/usr/bin/php}"
if [[ -f "$SRC/tests/p2/test_boot.php" ]]; then
  php "$SRC/tests/p2/test_boot.php" >/dev/null || log "warn: boot smoke did not pass"
fi

php "$ROOT/bin/kloxocp" doctor --root="$ROOT" >/dev/null || true

install_backend

host="$(hostname -f 2>/dev/null || hostname)"
ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
url="https://${ip:-$host}:7777/"
alt=""
if [[ -n "$ip" && -n "$host" ]]; then
  alt="https://${host}:7777/"
fi
print_access_box "$url" "$alt" "admin" "$admin_env" | tee -a "$LOG"
exit 0
