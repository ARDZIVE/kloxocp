#!/bin/sh
# Distro PHP CLI wrapper. Replaces /usr/bin/lxphp.exe.
# Prefer pinned panel PHP (pdo_sqlite) so PHP Used cannot break panel tools.
# Override with KLOXO_PHP=/path/to/php
set -e
ROOT="${KLOXO_ROOT:-/opt/kloxo}"
if [ -n "${KLOXO_PHP:-}" ] && [ -x "$KLOXO_PHP" ]; then
	exec "$KLOXO_PHP" "$@"
fi
if [ -f "${ROOT}/etc/path/php" ]; then
	PIN="$(tr -d '\n\r' < "${ROOT}/etc/path/php" 2>/dev/null || true)"
	if [ -n "${PIN}" ] && [ -x "${PIN}" ]; then
		exec "${PIN}" "$@"
	fi
fi
for p in /usr/bin/php8.6 /usr/bin/php8.5 /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php; do
	if [ -x "$p" ] && "$p" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; then
		mkdir -p "${ROOT}/etc/path" 2>/dev/null || true
		printf '%s\n' "$p" > "${ROOT}/etc/path/php" 2>/dev/null || true
		exec "$p" "$@"
	fi
done
for p in /usr/bin/php /usr/bin/php8.6 /usr/bin/php8.5 /usr/bin/php8.4 /usr/bin/php8.3; do
	if [ -x "$p" ]; then
		exec "$p" "$@"
	fi
done
echo "kloxo php-cli: no PHP 8 binary found (install php-cli)" >&2
exit 127
