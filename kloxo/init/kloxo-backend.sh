#!/bin/sh
# Start the PHP lxserver as master or slave. Slave is detected from slave-db.db.
set -eu
ROOT="${KLOXO_ROOT:-/opt/kloxo}"
PHP="${KLOXO_PHP:-/usr/bin/php}"
if [ -f "${ROOT}/etc/conf/slave-db.db" ]; then
	mode=slave
else
	mode=master
fi
cd "${ROOT}/httpdocs"
exec "${PHP}" -f "${ROOT}/sbin/kloxo.php" "${mode}"
