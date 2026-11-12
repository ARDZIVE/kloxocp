#!/bin/sh
# Distro PHP CLI wrapper. Replaces /usr/bin/lxphp.exe.
# Prefer pinned panel PHP (pdo_sqlite) so PHP Used cannot break panel tools.
# Override with KLOXO_PHP=/path/to/php (must provide pdo_sqlite).
set -e
ROOT="${KLOXO_ROOT:-/opt/kloxo}"

php_has_sqlite() {
	[ -n "$1" ] && [ -x "$1" ] \
		&& "$1" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1
}

pin_php() {
	mkdir -p "${ROOT}/etc/path" 2>/dev/null || true
	printf '%s\n' "$1" > "${ROOT}/etc/path/php" 2>/dev/null || true
}

if [ -n "${KLOXO_PHP:-}" ] && php_has_sqlite "$KLOXO_PHP"; then
	exec "$KLOXO_PHP" "$@"
fi

# Stale pin (PHP Used → Sury 8.6 without php8.6-sqlite3) must be ignored.
if [ -f "${ROOT}/etc/path/php" ]; then
	PIN="$(tr -d '\n\r' < "${ROOT}/etc/path/php" 2>/dev/null || true)"
	if php_has_sqlite "$PIN"; then
		exec "${PIN}" "$@"
	fi
fi

# Distro panel PHP first (usually has phpX.Y-sqlite3). Sury site series last.
for p in /usr/bin/php8.3 /usr/bin/php8.5 /usr/bin/php8.4 /usr/bin/php8.6 /usr/bin/php; do
	if php_has_sqlite "$p"; then
		pin_php "$p"
		exec "$p" "$@"
	fi
done

echo "kloxo php-cli: no PHP with pdo_sqlite found." >&2
echo "  apt-get install -y php8.3-sqlite3   # or php8.5-sqlite3 / php8.6-sqlite3" >&2
echo "  # then: rm -f ${ROOT}/etc/path/php && $0 ..." >&2
exit 127
