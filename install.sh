#!/bin/bash
# Kloxo CP bootstrap for Ubuntu 24.04 LTS and Ubuntu 26.x.
#
# One line (server has git, repo must be cloneable):
#   sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y git ca-certificates && git clone --depth 1 --branch main https://github.com/ARDZIVE/kloxocp.git /usr/local/src/kloxo-cp && bash /usr/local/src/kloxo-cp/install.sh'
#
# Optional: KLOXO_GIT_TOKEN for a private fork. Do not pipe this file into bash
# (apt would steal stdin); wget/curl to a file, then bash it.
#
# Does: pre-flight, clone (if needed), apt packages, /opt/kloxo layout, TLS keys,
# lxphp.exe wrappers, kloxo-php systemd unit, pinned Hiawatha 12.4 build,
# panel MariaDB schema via init-panel-db, phpMyAdmin under httpdocs/thirdparty.
# Does not: Let's Encrypt, apt repo, kloxo-mail apply, verified browser login.
set -euo pipefail
LOG="/dev/null"

KLOXO_GIT_URL="${KLOXO_GIT_URL:-https://github.com/ARDZIVE/kloxocp.git}"
KLOXO_GIT_BRANCH="${KLOXO_GIT_BRANCH:-main}"
KLOXO_SRC_DEFAULT="/usr/local/src/kloxo-cp"
KLOXO_ROOT_DEFAULT="/opt/kloxo"
KCP_VERSION="0.1.0-dev"

PROFILE="core"
FORCE=0
DRY=0
UPDATE=0
WITH_MAIL=0
SRC=""
ROOT="$KLOXO_ROOT_DEFAULT"

usage() {
  cat <<EOF
Kloxo CP ${KCP_VERSION} installer (Ubuntu 24.04 or 26.x)

  sudo bash install.sh [--profile=core|full] [--yes] [--force]

  --profile=core   php, nginx, mariadb, bind9 (default)
  --profile=full   core plus postfix, dovecot, rspamd, redis
  --yes            non-interactive (assumed when stdin is not a TTY)
  --force          continue below 2 GB RAM / 10 GB disk
  --update         git fetch the existing clone
  --dry-run        print the plan and exit
  --src=DIR        clone location (default: ${KLOXO_SRC_DEFAULT})
  --root=DIR       program root (default: ${KLOXO_ROOT_DEFAULT})
  --branch=NAME    git branch (default: ${KLOXO_GIT_BRANCH})
  --repo=URL       git URL (default: ${KLOXO_GIT_URL})
  --help

Default panel login is admin/admin; the UI requires a password change
after the first login. Override with KLOXO_ADMIN_PASSWORD (min 8 chars).

One-line install on a clean Ubuntu 24.04 or 26.x machine (root):

  sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y git ca-certificates && git clone --depth 1 --branch main https://github.com/ARDZIVE/kloxocp.git /usr/local/src/kloxo-cp && bash /usr/local/src/kloxo-cp/install.sh'

Source: https://github.com/ARDZIVE/kloxocp.git (public, branch main).
This tree is NOT production-safe.
EOF
}

log() { printf '%s\n' "$*" | tee -a "$LOG" ; }
die() { printf 'error: %s\n' "$*" | tee -a "$LOG" >&2 ; exit 1; }

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

apt-get update -y
apt-get install -y ca-certificates git curl openssl \
  php-cli php-fpm php-mysql php-xml php-mbstring php-curl \
  mariadb-server nginx bind9 \
  build-essential cmake make gcc pkg-config libssl-dev zlib1g-dev

if [[ "$WITH_MAIL" -eq 1 ]]; then
  echo "postfix postfix/main_mailer_type select No configuration" | debconf-set-selections
  apt-get install -y postfix postfix-mysql postfix-lmdb \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd \
    dovecot-mysql dovecot-sieve dovecot-managesieved \
    rspamd redis-server
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
    git -C "$SRC" fetch --depth 1 origin "$KLOXO_GIT_BRANCH"
    git -C "$SRC" checkout "$KLOXO_GIT_BRANCH"
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
elif [[ "$UPDATE" -eq 1 && -d "$SRC/.git" ]]; then
  log "updating clone in $SRC"
  git -C "$SRC" fetch --depth 1 origin "$KLOXO_GIT_BRANCH"
  git -C "$SRC" checkout "$KLOXO_GIT_BRANCH"
  git -C "$SRC" pull --ff-only origin "$KLOXO_GIT_BRANCH" || true
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
else
  die "$ROOT exists and is not a Kloxo program root"
fi
mkdir -p "$ROOT/etc/conf" "$ROOT/pid" "$ROOT/log"

if ! id -u lxlabs >/dev/null 2>&1; then
  useradd --system --home /home/kloxo --shell /usr/sbin/nologin --user-group lxlabs
fi
chown -R lxlabs:lxlabs /home/kloxo /home/kloxomail || true
ln -sfn "$ROOT/pscript" /script
ln -sfn "$ROOT/init/php-cli.sh" /usr/bin/lxphp.exe
ln -sfn "$ROOT/init/php-cli.sh" /usr/bin/lphp.exe
ln -sfn "$ROOT/bin/kloxocp" /usr/local/sbin/kloxocp
chmod +x "$ROOT/init/php-cli.sh" "$ROOT/bin/kloxocp" \
  "$ROOT/bin/install/generate-tls-keys" \
  "$ROOT/bin/install/init-panel-db" \
  "$ROOT/bin/install/install-hiawatha" \
  "$ROOT/bin/install/install-phpmyadmin" || true

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

install -d -m 0755 "$ROOT/init" "$ROOT/log"
php_fpm=""
for p in /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm; do
  if [[ -x "$p" ]]; then
    php_fpm="$p"
    break
  fi
done
chmod +x "$ROOT/init/kloxo-php-fpm.sh" || true
if [[ -n "$php_fpm" ]]; then
  cp -a "$ROOT/init/kloxo-php.service" /etc/systemd/system/kloxo-php.service
  systemctl daemon-reload
  systemctl enable --now kloxo-php || log "warn: kloxo-php unit failed to start ($php_fpm)"
else
  die "no php-fpm binary (install php-fpm)"
fi

log "building Hiawatha 12.4 from source (not in Ubuntu apt)"
bash "$ROOT/bin/install/install-hiawatha" --root="$ROOT"

systemctl enable --now nginx mariadb named 2>/dev/null || systemctl enable --now nginx mariadb bind9 2>/dev/null || true

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
if ! wait_mariadb; then
  die "MariaDB is not accepting unix_socket logins as root"
fi

admin_env="${KLOXO_ADMIN_PASSWORD:-admin}"
if [[ "$admin_env" != "admin" && ${#admin_env} -lt 8 ]]; then
  die "admin password must be at least 8 characters (or the default admin)"
fi
install -d -m 0700 /root
printf '%s\n' "$admin_env" > /root/kloxo-admin-password
chmod 600 /root/kloxo-admin-password
KLOXO_ADMIN_PASSWORD="$admin_env" php "$ROOT/bin/install/init-panel-db" \
    --root="$ROOT" --admin-password-env=KLOXO_ADMIN_PASSWORD --db-rootuser=root
log "panel schema ready"

bash "$ROOT/bin/install/install-phpmyadmin" --root="$ROOT"
systemctl is-active --quiet kloxo-php || die "kloxo-php is not active"
systemctl is-active --quiet kloxo-web || die "kloxo-web is not active"

export KLOXO_ROOT="$ROOT"
export KLOXO_PHP="${KLOXO_PHP:-/usr/bin/php}"
if [[ -f "$SRC/tests/p2/test_boot.php" ]]; then
  php "$SRC/tests/p2/test_boot.php" >/dev/null
fi

php "$ROOT/bin/kloxocp" doctor --root="$ROOT" >/dev/null || true

host="$(hostname -f 2>/dev/null || hostname)"
ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
url="https://${ip:-$host}:7777/"
cat <<EOF | tee -a "$LOG"

Kloxo CP ${KCP_VERSION} is installed.
  Panel    ${url}
  User     admin
  Password ${admin_env}
  Saved in /root/kloxo-admin-password (mode 0600)

Open the URL, accept the self-signed certificate, log in as admin.
Change the password on first login (the panel will not continue until you do).
EOF
exit 0
