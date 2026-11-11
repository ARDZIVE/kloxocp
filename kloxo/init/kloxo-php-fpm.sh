#!/bin/sh
# Start the panel php-fpm with the distro binary.
# Ubuntu 24.04: /usr/sbin/php-fpm8.3
# Ubuntu 26.04: /usr/sbin/php-fpm8.5
# Override with KLOXO_PHP_FPM=/path/to/php-fpm
set -e
CONF=/opt/kloxo/init/kloxo-php-fpm.conf
if [ -n "${KLOXO_PHP_FPM:-}" ] && [ -x "$KLOXO_PHP_FPM" ]; then
	exec "$KLOXO_PHP_FPM" --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@"
fi
# Prefer FPM matching the pinned CLI (pdo_sqlite) when etc/path/php is set.
ROOT="${KLOXO_ROOT:-/opt/kloxo}"
if [ -f "${ROOT}/etc/path/php" ]; then
	PIN="$(tr -d '\n\r' < "${ROOT}/etc/path/php" 2>/dev/null || true)"
	case "$PIN" in
		*/php8.6) [ -x /usr/sbin/php-fpm8.6 ] && exec /usr/sbin/php-fpm8.6 --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@" ;;
		*/php8.5) [ -x /usr/sbin/php-fpm8.5 ] && exec /usr/sbin/php-fpm8.5 --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@" ;;
		*/php8.4) [ -x /usr/sbin/php-fpm8.4 ] && exec /usr/sbin/php-fpm8.4 --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@" ;;
		*/php8.3) [ -x /usr/sbin/php-fpm8.3 ] && exec /usr/sbin/php-fpm8.3 --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@" ;;
	esac
fi
for p in /usr/sbin/php-fpm8.6 /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm; do
	if [ -x "$p" ]; then
		exec "$p" --nodaemonize -y "$CONF" --fpm-config "$CONF" "$@"
	fi
done
echo "kloxo php-fpm: no php-fpm binary found (install php-fpm)" >&2
exit 127
