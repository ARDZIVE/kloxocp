#!/bin/sh
# Start the panel php-fpm with the distro binary.
# Ubuntu 24.04: /usr/sbin/php-fpm8.3
# Ubuntu 26.04: /usr/sbin/php-fpm8.5
# Override with KLOXO_PHP_FPM=/path/to/php-fpm
set -e
CONF=/opt/kloxo/init/kloxo-php-fpm.conf
if [ -n "${KLOXO_PHP_FPM:-}" ] && [ -x "$KLOXO_PHP_FPM" ]; then
	exec "$KLOXO_PHP_FPM" --nodaemonize --fpm-config "$CONF" "$@"
fi
for p in /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm; do
	if [ -x "$p" ]; then
		exec "$p" --nodaemonize --fpm-config "$CONF" "$@"
	fi
done
echo "kloxo php-fpm: no php-fpm binary found (install php-fpm)" >&2
exit 127
