#!/bin/bash
# Kloxo CP bootstrap for Ubuntu 24.04 LTS and Ubuntu 26.x.
#
# Install (downloads this script from GitHub main, then clones or fetches origin/main):
#   sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
#   apt-get -o DPkg::Lock::Timeout=600 update
#   apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl
#   curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh
#   bash /tmp/kloxocp-install.sh'
# One line:
#   sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update; apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'
#
# Optional: KLOXO_GIT_TOKEN for a private fork. Do not pipe this file into bash
# (apt would steal stdin); wget/curl to a file, then bash it.
#
# Does: pre-flight, clone (if needed), apt packages, /opt/kloxo layout, TLS keys,
# lxphp.exe wrappers, kloxo-php systemd unit, pinned Hiawatha 12.4 build,
# panel MariaDB schema via init-panel-db (also seeds the local database admin
# row; a re-run keeps an existing admin UI password), phpMyAdmin under httpdocs/thirdparty,
# site PHP-FPM 5.6 / 7.4 / latest (install-site-php; default latest),
# PowerDNS packaged and prepared (disabled; BIND stays default on 53),
# Apache packaged and prepared (disabled; Nginx stays default on 80/443),
# MariaDB enabled as the default SQL (mysqld/mysql aliases not separately enabled),
# imagemagick for File Manager Thumbnail, Exim default MTA on --profile=full
# (Postfix is not co-installed; Switch Program installs Postfix),
# ufw packaged and enabled by default (panel ports allowed first),
# fail2ban packaged and configured by install-fail2ban (nftables bans; jails for SSH,
# panel, mail, FTP, web auth; the installer's SSH client is whitelisted),
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
KCP_VERSION="10.1-2026110601"

# Default is full (Exim/Dovecot/Rspamd). Use --profile=core for a panel-only host.
PROFILE="full"
FORCE=0
DRY=0
UPDATE=0
FRESH=0
YES=0
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
  --profile=core   php, nginx, mariadb, bind9, pure-ftpd, redis-server, ufw (enabled), fail2ban (enabled), imagemagick, webcache:8080, webproxy:30080; apache2/pdns packaged, disabled
  --slave          backend-only host; no panel UI (Kloxo-MR slave)
  --slave-password=SECRET
                   password the master uses when adding this host
  --yes            non-interactive (assumed when stdin is not a TTY)
  --force          continue below 2 GB RAM / 10 GB disk
  --update         accepted; every run already git-fetches the clone
  --fresh          remove the previous Kloxo CP install first (program root, panel
                   database and its accounts, kloxo units, saved passwords, Fail2Ban
                   panel jails), then install from scratch. Asks you to type YES
                   (or pass --yes). Kept: apt packages, customer sites in /home/kloxo,
                   mail in /var/vmail, customer databases, firewall rules, the lxlabs user.
  --dry-run        print the plan and exit
  --src=DIR        clone location (default: ${KLOXO_SRC_DEFAULT})
  --root=DIR       program root (default: ${KLOXO_ROOT_DEFAULT})
  --branch=NAME    git branch (default: ${KLOXO_GIT_BRANCH})
  --repo=URL       git URL (default: ${KLOXO_GIT_URL})
  --help

Default panel login is admin/admin; the UI requires a password change
after the first login. Override with KLOXO_ADMIN_PASSWORD (min 8 chars).
Re-running on an existing install keeps the current admin password unless
KLOXO_ADMIN_PASSWORD is set.
Slave password: --slave-password= or KLOXO_SLAVE_PASSWORD (min 8 chars).

Install on Ubuntu 24.04 or 26.x (root). Downloads this script from GitHub main,
then clones or fetches origin/main:

  sudo bash -c 'export DEBIAN_FRONTEND=noninteractive
  apt-get -o DPkg::Lock::Timeout=600 update
  apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl
  curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=\$(date +%s)" -o /tmp/kloxocp-install.sh
  bash /tmp/kloxocp-install.sh'

One line:

  sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -o DPkg::Lock::Timeout=600 update; apt-get -o DPkg::Lock::Timeout=600 install -y ca-certificates curl && curl -fsSL "https://raw.githubusercontent.com/ARDZIVE/kloxocp/main/install.sh?nocache=\$(date +%s)" -o /tmp/kloxocp-install.sh && bash /tmp/kloxocp-install.sh'

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
  local url="$1" alt="$2" user="$3" pass="$4" rec="${5:-}"
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
  box "A first-install password is saved in /root/kloxo-admin-password (0600)."
  if [[ -n "$rec" ]]; then
    box ""
    box "Recovery console (still works when MariaDB or Nginx is down):"
    box "  ${url}recovery/"
    box "  Password : ${rec}"
    box "Saved in /root/kloxo-recovery-password (0600). Reset it with:"
    box "  php /opt/kloxo/bin/kloxocp recovery-password --generate"
  fi
  echo "$bar"
  echo
}

fresh_wipe() {
  local u unit root_real src_real here_real
  log "fresh: removing the previous Kloxo CP install"

  for unit in kloxo-watchdog kloxo-backend kloxo-web kloxo-php kloxo-wrap kloxo-webcache kloxo-webproxy hiawatha; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
  done
  rm -f /etc/systemd/system/kloxo-*.service /etc/systemd/system/multi-user.target.wants/kloxo-*.service \
    /etc/systemd/system/mariadb.service.d/kloxo-restart.conf || true
  systemctl daemon-reload >/dev/null 2>&1 || true
  systemctl reset-failed >/dev/null 2>&1 || true

  if command -v mysql >/dev/null 2>&1 && systemctl is-active --quiet mariadb 2>/dev/null; then
    if mysql --protocol=socket -uroot -e "DROP DATABASE IF EXISTS \`kloxo\`;
        DROP USER IF EXISTS 'kloxo'@'localhost', 'kloxo'@'127.0.0.1', 'kloxo_pma'@'localhost', 'kloxo_pma'@'127.0.0.1';
        FLUSH PRIVILEGES;" >>"$LOG" 2>&1; then
      log "fresh: panel database and accounts dropped"
    else
      log "warn: could not drop the panel database (an old admin password may survive)"
    fi
  else
    log "warn: MariaDB not running or not installed; no panel database dropped"
  fi

  rm -f /etc/fail2ban/jail.d/kloxo-cp.local /etc/fail2ban/filter.d/kloxo-panel.conf \
    /etc/fail2ban/filter.d/kloxo-recovery.conf /etc/logrotate.d/kloxo-auth || true
  systemctl reload fail2ban >/dev/null 2>&1 || true

  rm -f /script /usr/bin/lxphp.exe /usr/bin/lphp.exe /usr/local/sbin/kloxocp \
    /root/kloxo-admin-password /root/kloxo-recovery-password || true

  if [[ -L "$ROOT" ]]; then
    rm -f "$ROOT"
  elif [[ -d "$ROOT" && -f "$ROOT/bin/kloxocp" && -d "$ROOT/httpdocs" && "$ROOT" =~ ^/[^/]+/[^/]+ ]]; then
    rm -rf "$ROOT"
  elif [[ -e "$ROOT" ]]; then
    die "fresh: $ROOT is not a Kloxo program root; not removing it"
  fi

  # The clone: removed when it is the default one; kept (cleaned) when this script runs from inside it.
  if [[ -d "$SRC/.git" && -f "$SRC/kloxo/bin/kloxocp" ]]; then
    src_real="$(cd "$SRC" && pwd -P)"
    here_real=""
    [[ -n "$HERE" ]] && here_real="$(cd "$HERE" 2>/dev/null && pwd -P || true)"
    if [[ "$src_real" == "$here_real" ]]; then
      git -C "$SRC" clean -fdxq || true
    elif [[ "$src_real" =~ ^/[^/]+/[^/]+ ]]; then
      rm -rf "$src_real"
    fi
  fi
  log "fresh: previous install removed"
}

for a in "$@"; do
  case "$a" in
    --help|-h) usage; exit 0 ;;
    --yes|-y) YES=1 ;;
    --fresh) FRESH=1 ;;
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
  fresh   $([ "$FRESH" -eq 1 ] && echo 'yes: previous install is removed first' || echo no)
  sitephp 5.6, 7.4, latest (install-site-php; default latest)
  dns     bind9 enabled (default); pdns-server prepared and disabled
  web     nginx enabled (default); apache2 prepared and disabled
  cache   kloxo-webcache :8080 and kloxo-webproxy :30080 (nginx; no varnish/squid)
  smtp    exim4 default (full); postfix via Switch Program (cannot co-install)
  ftp     pure-ftpd (enabled)
  redis   redis-server (enabled; Rspamd Bayes backend)
  f2b     fail2ban (enabled; jails for SSH, panel, mail, FTP, web auth; nftables bans)
  skip    no bogofilter/spamassassin/sqlite apt packages
  token   $([ -n "${KLOXO_GIT_TOKEN:-}" ] && echo set || echo unset)
EOF
  exit 0
fi

[[ "$(id -u)" -eq 0 ]] || die "run as root (sudo bash install.sh)"

if [[ "$FRESH" -eq 1 ]]; then
  if [[ "$YES" -ne 1 ]]; then
    [[ -t 0 ]] || die "--fresh deletes the existing panel; run it from a terminal or add --yes"
    printf 'This deletes the existing Kloxo CP install (%s), its panel database and saved passwords.\n' "$ROOT"
    printf 'Customer sites, mail and customer databases are kept. Type YES to continue: '
    read -r answer
    [[ "$answer" == "YES" ]] || die "cancelled"
  fi
  fresh_wipe
fi

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

# A fresh Ubuntu server usually runs unattended-upgrades (or cloud-init apt) for
# the first minutes and holds the dpkg lock. Wait for it instead of aborting:
# apt gets a lock timeout for every apt/dpkg call (including the helper
# scripts), and we also poll the lock files before our own first apt call.
KLOXO_APT_CONF=/etc/apt/apt.conf.d/99kloxo-install
KLOXO_APT_WAIT="${KLOXO_APT_WAIT:-900}"
write_apt_conf() {
  printf '%s\n' \
    "// Temporary Kloxo CP install settings (removed when install.sh exits)" \
    "DPkg::Lock::Timeout \"${KLOXO_APT_WAIT}\";" \
    "Acquire::Retries \"3\";" > "$KLOXO_APT_CONF" || true
}
wait_for_apt_locks() {
  local waited=0 locks held
  locks=(/var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock)
  command -v fuser >/dev/null 2>&1 || return 0
  while :; do
    held=0
    for f in "${locks[@]}"; do
      if [[ -e "$f" ]] && fuser "$f" >/dev/null 2>&1; then
        held=1
        break
      fi
    done
    [[ "$held" -eq 0 ]] && return 0
    if [[ "$waited" -ge "$KLOXO_APT_WAIT" ]]; then
      die "apt/dpkg is still locked after ${KLOXO_APT_WAIT}s (another updater is running); try again later"
    fi
    if [[ $((waited % 30)) -eq 0 ]]; then
      log "waiting for another apt/dpkg process to finish (unattended-upgrades?) ... ${waited}s"
    fi
    sleep 5
    waited=$((waited + 5))
  done
}
write_apt_conf
wait_for_apt_locks

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
  rm -f "$KLOXO_APT_CONF"
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

drop_dead_ondrej_ppa() {
  # ppa:ondrej/php answers 404 for an Ubuntu release it has no packages for yet; a
  # leftover source file then makes every `apt-get update` exit with an error.
  local f c
  c="${VERSION_CODENAME:-}"
  [[ -n "$c" ]] || return 0
  for f in /etc/apt/sources.list.d/ondrej-*php*.sources /etc/apt/sources.list.d/ondrej-*php*.list; do
    [[ -e "$f" ]] || continue
    if command -v curl >/dev/null 2>&1 \
      && ! curl -fsI --max-time 20 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${c}/Release" >/dev/null 2>&1; then
      log "removing $f: ppa:ondrej/php has no packages for Ubuntu ${c}"
      rm -f -- "$f"
    fi
  done
}

wait_for_apt_locks
drop_dead_ondrej_ppa
apt-get update -y || log "warn: apt-get update reported errors; continuing"
# Core: panel + sites + DNS + FTP + Redis (Rspamd Bayes backend). Mail spam is
# Rspamd (full profile), not bogofilter/spamassassin. Panel DB is MariaDB (the
# Sqlite class is mysqli); slave-db.db is PHP serialize — do not apt-install sqlite.
apt-get install -y ca-certificates git curl openssl rsync \
  php-cli php-fpm php-mysql php-xml php-mbstring php-curl \
  mariadb-server nginx apache2 bind9 pdns-server pdns-backend-mysql \
  pure-ftpd redis-server iproute2 ufw imagemagick \
  fail2ban python3-systemd nftables \
  build-essential cmake make gcc pkg-config libssl-dev zlib1g-dev

if [[ "$WITH_MAIL" -eq 1 ]]; then
  # Exim and Postfix both Provide/Conflict mail-transport-agent — never
  # apt-install them in one transaction. Default MTA is Exim; Switch Program
  # smtp=postfix removes Exim and installs Postfix.
  wait_for_apt_locks
  echo "exim4-config exim4/dc_eximconfigconfigtype select none" | debconf-set-selections
  echo "exim4-config exim4/dc_use_split_config boolean false" | debconf-set-selections
  # Stop a leftover postfix unit before Exim lands (apt may remove the package).
  systemctl disable --now postfix 2>/dev/null || true
  apt-get install -y exim4-daemon-heavy \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd \
    dovecot-mysql dovecot-sieve dovecot-managesieved \
    rspamd
  # Dovecot is the default POP3/IMAP program (pg_pop3=dovecot).
  systemctl enable dovecot 2>/dev/null || true
  systemctl disable --now postfix 2>/dev/null || true
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

enable_ufw() {
  # Host firewall on by default: UFW over nftables/iptables-nft. Allow panel
  # ports first so install does not lock out 7777/7778.
  if ! command -v ufw >/dev/null 2>&1; then
    log "warn: ufw not installed; skip enable"
    return 0
  fi
  local ufw_php="${ROOT}/httpdocs/lib/php/kloxo_ufw.php"
  if [[ -f "$ufw_php" ]] && command -v php >/dev/null 2>&1; then
    if php -r 'require $argv[1]; $r = KloxoUfw::enable(); fwrite(STDOUT, (string)($r["out"] ?? "")); exit(!empty($r["ok"]) ? 0 : 1);' \
      "$ufw_php"; then
      log "UFW enabled (protected ports allowed first)"
      return 0
    fi
    log "warn: KloxoUfw::enable failed; trying ufw CLI"
  fi
  for p in 21 22 25 80 110 143 443 465 587 993 995 7777 7778 8080 30080; do
    ufw allow "${p}/tcp" >/dev/null 2>&1 || true
  done
  ufw allow 53/tcp >/dev/null 2>&1 || true
  ufw allow 53/udp >/dev/null 2>&1 || true
  if ufw --force enable >/dev/null 2>&1; then
    log "UFW enabled via CLI"
  else
    log "warn: ufw --force enable failed"
  fi
}

# MariaDB holds the panel data: bring it back by itself after a crash.
# (The recovery console at /recovery/ covers the case where it stays down.)
install_restart_dropins() {
  install -d -m 0755 /etc/systemd/system/mariadb.service.d
  cat > /etc/systemd/system/mariadb.service.d/kloxo-restart.conf <<'DROPIN'
[Service]
Restart=on-failure
RestartSec=5s
DROPIN
  chmod 0644 /etc/systemd/system/mariadb.service.d/kloxo-restart.conf
  systemctl daemon-reload 2>/dev/null || true
}

enable_default_stack() {
  install_restart_dropins
  # Default SQL is MariaDB only. Do not enable mysql/mysqld by name (aliases of
  # mariadb on Ubuntu; a separate mysql-server package stays stopped until the
  # admin starts it from Services).
  systemctl enable --now nginx mariadb named 2>/dev/null \
    || systemctl enable --now nginx mariadb bind9 2>/dev/null || true
  systemctl enable --now redis-server 2>/dev/null \
    || systemctl enable --now redis 2>/dev/null || true
  systemctl enable --now pure-ftpd 2>/dev/null || true
  systemctl reset-failed pdns apache2 postfix 2>/dev/null || true
  systemctl disable --now pdns 2>/dev/null || true
  systemctl disable --now apache2 2>/dev/null || true
  # Default MTA is Exim (full profile). Never leave a preinstalled Postfix
  # unit enabled alongside Exim (mail-transport-agent conflict / dual listeners).
  systemctl disable --now postfix 2>/dev/null || true
  # If Oracle/community mysql.service is a distinct unit (not mariadb), leave it stopped.
  if systemctl cat mysql >/dev/null 2>&1; then
    frag_mysql="$(systemctl show -p FragmentPath --value mysql 2>/dev/null || true)"
    frag_maria="$(systemctl show -p FragmentPath --value mariadb 2>/dev/null || true)"
    if [[ -n "$frag_mysql" && -n "$frag_maria" && "$frag_mysql" != "$frag_maria" ]]; then
      systemctl disable --now mysql 2>/dev/null || true
    fi
  fi
  enable_ufw
}

prepare_apache() {
  chmod 0755 "$ROOT/bin/install/install-apache" || true
  php "$ROOT/bin/install/install-apache" --root="$ROOT" \
    || log "warn: install-apache failed; Switch Program can retry"
  systemctl disable --now apache2 2>/dev/null || true
  systemctl enable --now nginx 2>/dev/null || true
}

prepare_webfront() {
  chmod 0755 "$ROOT/bin/install/install-webfront" "$ROOT/bin/install/install-webcache" \
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

# Address of the person running this installer (SSH session), so Fail2Ban
# never bans the installer's own connection.
installer_client_ip() {
  local ip=""
  if [[ -n "${SSH_CLIENT:-}" ]]; then
    ip="${SSH_CLIENT%% *}"
  elif [[ -n "${SSH_CONNECTION:-}" ]]; then
    ip="${SSH_CONNECTION%% *}"
  else
    ip="$(who -m 2>/dev/null | sed -n 's/.*(\([0-9a-fA-F:.]*\)).*/\1/p' | head -n 1)"
  fi
  if [[ "$ip" =~ ^[0-9a-fA-F:.]+$ ]]; then
    printf '%s' "$ip"
  fi
}

# Fail2Ban: bans addresses that keep failing logins (SSH, panel, mail, FTP, web
# auth). Jails come from etc/conf/fail2ban.json and are managed in the panel
# under Security > Fail2Ban. Bans use nftables, not the UFW rule list.
prepare_fail2ban() {
  chmod 0755 "$ROOT/bin/install/install-fail2ban" || true
  if ! command -v fail2ban-client >/dev/null 2>&1; then
    log "warn: fail2ban is not installed; skipping"
    return 0
  fi
  local cip args=()
  cip="$(installer_client_ip)"
  [[ -n "$cip" ]] && args+=("--ignore-ip=$cip")
  touch "$ROOT/log/auth-failures.log" 2>/dev/null || true
  chmod 0664 "$ROOT/log/auth-failures.log" 2>/dev/null || true
  chown lxlabs:lxlabs "$ROOT/log/auth-failures.log" 2>/dev/null || true
  php "$ROOT/bin/install/install-fail2ban" --root="$ROOT" "${args[@]}" \
    || log "warn: install-fail2ban failed; Security > Fail2Ban can retry (Reload)"
}

# Restarts crashed or unresponsive server applications (nginx, mariadb, bind9,
# exim4, dovecot, php-fpm, ...). Its own systemd service: it needs neither the
# panel nor MariaDB. Units stopped from the panel are left alone.
install_watchdog() {
  chmod 0755 "$ROOT/bin/kloxo-watchdog" || true
  install -d -m 0755 "$ROOT/var/watchdog" "$ROOT/log"
  install -m 0644 "$ROOT/init/kloxo-watchdog.service" /etc/systemd/system/kloxo-watchdog.service
  systemctl daemon-reload
  systemctl enable kloxo-watchdog >/dev/null 2>&1 || true
  systemctl restart kloxo-watchdog || systemctl start kloxo-watchdog || true
  wait_unit kloxo-watchdog || log "warn: kloxo-watchdog is not active"
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
  install_watchdog
  prepare_fail2ban
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

# Re-running the installer on an existing panel must not reset a password the
# owner already changed: keep it unless KLOXO_ADMIN_PASSWORD is given.
keep_admin=0
if [[ -z "${KLOXO_ADMIN_PASSWORD:-}" && -s "$ROOT/etc/conf/kloxo.pass" ]]; then
  # only when an admin row really exists (not a half-finished first install)
  if [[ "$(mariadb -N -B -e "SELECT COUNT(*) FROM kloxo.client WHERE nname='admin'" 2>/dev/null || true)" == "1" ]]; then
    keep_admin=1
  fi
fi
admin_env="${KLOXO_ADMIN_PASSWORD:-admin}"
if [[ "$admin_env" != "admin" && ${#admin_env} -lt 8 ]]; then
  die "admin password must be at least 8 characters (or the default admin)"
fi
if [[ "$keep_admin" -eq 1 ]]; then
  log "existing install: keeping the current admin password (set KLOXO_ADMIN_PASSWORD to change it)"
  KLOXO_ADMIN_PASSWORD="$admin_env" php "$ROOT/bin/install/init-panel-db" \
      --root="$ROOT" --admin-password-env=KLOXO_ADMIN_PASSWORD --keep-admin-password --db-rootuser=root
else
  install -d -m 0700 /root
  printf '%s\n' "$admin_env" > /root/kloxo-admin-password
  chmod 600 /root/kloxo-admin-password
  KLOXO_ADMIN_PASSWORD="$admin_env" php "$ROOT/bin/install/init-panel-db" \
      --root="$ROOT" --admin-password-env=KLOXO_ADMIN_PASSWORD --db-rootuser=root
fi
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

# Recovery console login (a file, not the database): created on first install,
# kept on re-runs. The password is shown once in the final box.
recovery_pw=""
if id -u lxlabs >/dev/null 2>&1; then
  install -d -m 0700 -o lxlabs -g lxlabs "$ROOT/var/recovery"
else
  install -d -m 0700 "$ROOT/var/recovery"
fi
if [[ ! -s "$ROOT/etc/conf/recovery.json" ]]; then
  if recovery_pw="$(php "$ROOT/bin/kloxocp" recovery-password --generate --raw --root="$ROOT")" && [[ -n "$recovery_pw" ]]; then
    install -d -m 0700 /root
    printf '%s\n' "$recovery_pw" > /root/kloxo-recovery-password
    chmod 600 /root/kloxo-recovery-password
    log "recovery console password created (/root/kloxo-recovery-password)"
  else
    recovery_pw=""
    log "warn: could not create the recovery console password; run: php $ROOT/bin/kloxocp recovery-password --generate"
  fi
else
  log "recovery console password kept (unchanged)"
fi

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
install_watchdog
prepare_fail2ban

host="$(hostname -f 2>/dev/null || hostname)"
ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
url="https://${ip:-$host}:7777/"
alt=""
if [[ -n "$ip" && -n "$host" ]]; then
  alt="https://${host}:7777/"
fi
if [[ "$keep_admin" -eq 1 ]]; then
  print_access_box "$url" "$alt" "admin" "(unchanged: your current admin password)" "$recovery_pw" | tee -a "$LOG"
else
  print_access_box "$url" "$alt" "admin" "$admin_env" "$recovery_pw" | tee -a "$LOG"
fi
exit 0
