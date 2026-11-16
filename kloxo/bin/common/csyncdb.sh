#!/bin/sh
# Sync DB from a secondary host. Password never on the mysql argv (security DD1).
set -eu

program=${1-}
shift || true

case "$program" in
	kloxo) db="kloxo4_2" ;;
	hypervm) db="hypervm1_0" ;;
	*)
		echo "usage: csyncdb.sh kloxo|hypervm <ssh-args...>" >&2
		exit 1
		;;
esac

if ! printf '%s' "$program" | grep -Eq '^[A-Za-z0-9_-]+$'; then
	echo "invalid program name" >&2
	exit 1
fi

passfile="/opt/kloxo/${program}/etc/conf/${program}.pass"
if [ ! -r "$passfile" ]; then
	echo "cannot read ${passfile}" >&2
	exit 1
fi

echo -n "Taking backup of the current databse...   "
lxphp.exe ../bin/common/mebackup.php >/dev/null
echo "done.."

if [ -z "${1-}" ]; then
	echo "need the secondary slave address" >&2
	exit 1
fi

cnf=$(mktemp)
trap 'rm -f -- "$cnf"' EXIT INT TERM
chmod 600 "$cnf"
{
	printf '[client]\nuser=%s\npassword=' "$program"
	# Strip CR/LF from the password file; never put it on argv.
	tr -d '\r\n' <"$passfile"
	printf '\n'
} >"$cnf"

echo "Syncing database from $* to this server"
# shellcheck disable=SC2029
ssh "$@" "sh /opt/kloxo/${program}/bin/common/databasedump ${program}" \
	| mysql --defaults-extra-file="$cnf" "$db"
echo "done"
