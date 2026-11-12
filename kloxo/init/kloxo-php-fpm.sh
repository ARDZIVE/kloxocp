#!/bin/sh
# Start the panel php-fpm with the distro binary.
# Ubuntu 24.04: /usr/sbin/php-fpm8.3
# Ubuntu 26.04: /usr/sbin/php-fpm8.5
# Override with KLOXO_PHP_FPM=/path/to/php-fpm
# Match a CLI that has pdo_sqlite (stale etc/path/php pointing at Sury 8.6
# without sqlite blanked the login form).
set -e
CONF=/opt/kloxo/init/kloxo-php-fpm.conf
ROOT="${KLOXO_ROOT:-/opt/kloxo}"

if [ -n "${KLOXO_PHP_FPM:-}" ] && [ -x "$KLOXO_PHP_FPM" ]; then
	exec "$KLOXO_PHP_FPM" --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@"
fi

php_has_sqlite() {
	[ -n "$1" ] && [ -x "$1" ] \
		&& "$1" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1
}

# Prefer FPM for a CLI that actually has pdo_sqlite.
for ver in 8.3 8.5 8.4 8.6; do
	cli="/usr/bin/php${ver}"
	fpm="/usr/sbin/php-fpm${ver}"
	if php_has_sqlite "$cli" && [ -x "$fpm" ]; then
		mkdir -p "${ROOT}/etc/path" 2>/dev/null || true
		printf '%s\n' "$cli" > "${ROOT}/etc/path/php" 2>/dev/null || true
		exec "$fpm" --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@"
	fi
done

for p in /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm8.6 /usr/sbin/php-fpm; do
	if [ -x "$p" ]; then
		exec "$p" --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@"
	fi
done
echo "kloxo php-fpm: no php-fpm binary found (install php-fpm)" >&2
exit 127
