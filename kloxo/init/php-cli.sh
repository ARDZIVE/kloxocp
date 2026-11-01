#!/bin/sh
# Distro PHP CLI wrapper. Replaces /usr/bin/lxphp.exe.
# Ubuntu 24.04: php8.3; Ubuntu 26.04: php8.5. Override with KLOXO_PHP=/path/to/php
set -e
if [ -n "${KLOXO_PHP:-}" ] && [ -x "$KLOXO_PHP" ]; then
	exec "$KLOXO_PHP" "$@"
fi
for p in /usr/bin/php /usr/bin/php8.5 /usr/bin/php8.4 /usr/bin/php8.3; do
	if [ -x "$p" ]; then
		exec "$p" "$@"
	fi
done
echo "kloxo php-cli: no PHP 8 binary found (install php-cli)" >&2
exit 127
