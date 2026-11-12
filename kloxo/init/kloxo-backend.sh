#!/bin/sh
# Start the PHP lxserver as master or slave. Slave is detected from slave-db.db.
# Prefer the pinned panel PHP (etc/path/php) so PHP Used / update-alternatives
# cannot point us at a Sury series without pdo_sqlite. Ignore a stale pin.
set -eu
ROOT="${KLOXO_ROOT:-/opt/kloxo}"

php_has_sqlite() {
	[ -n "$1" ] && [ -x "$1" ] \
		&& "$1" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1
}

pin_php() {
	mkdir -p "${ROOT}/etc/path"
	printf '%s\n' "$1" > "${ROOT}/etc/path/php"
}

PHP=""
if [ -n "${KLOXO_PHP:-}" ] && php_has_sqlite "${KLOXO_PHP}"; then
	PHP="${KLOXO_PHP}"
elif [ -f "${ROOT}/etc/path/php" ]; then
	PIN="$(tr -d '\n\r' < "${ROOT}/etc/path/php" 2>/dev/null || true)"
	if php_has_sqlite "${PIN}"; then
		PHP="${PIN}"
	fi
fi
if [ -z "${PHP}" ]; then
	for p in /usr/bin/php8.3 /usr/bin/php8.5 /usr/bin/php8.4 /usr/bin/php8.6 /usr/bin/php; do
		if php_has_sqlite "$p"; then
			PHP="$p"
			pin_php "$p"
			break
		fi
	done
fi
PHP="${PHP:-/usr/bin/php}"
# Refuse to start without pdo_sqlite: every panel page needs panel.sqlite.
if ! php_has_sqlite "${PHP}"; then
	ver="$("${PHP}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
	echo "kloxo-backend: no PHP with pdo_sqlite (tried pin and /usr/bin/php*)." >&2
	echo "  apt-get install -y php${ver:-8.3}-sqlite3" >&2
	echo "  rm -f ${ROOT}/etc/path/php" >&2
	exit 1
fi
if [ -f "${ROOT}/etc/conf/slave-db.db" ]; then
	mode=slave
else
	mode=master
fi
cd "${ROOT}/httpdocs"
exec "${PHP}" -f "${ROOT}/sbin/kloxo.php" "${mode}"
