#!/bin/sh
# Distro PHP CLI wrapper. Replaces /usr/bin/lxphp.exe on Ubuntu 24.04.
# Override with KLOXO_PHP=/path/to/php
set -e
if [ -n "${KLOXO_PHP:-}" ] && [ -x "$KLOXO_PHP" ]; then
	exec "$KLOXO_PHP" "$@"
fi
for p in /usr/bin/php8.3 /usr/bin/php; do
	if [ -x "$p" ]; then
		exec "$p" "$@"
	fi
done
echo "kloxo php-cli: no PHP 8 binary found (install php-cli)" >&2
exit 127
