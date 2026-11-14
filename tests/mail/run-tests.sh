#!/bin/bash
# Integration tests for kloxo-mail + the Exim/Dovecot/Rspamd templates.
# Needs: php-cli, a running MariaDB (root via unix socket), exim4-daemon-heavy,
# dovecot-core + dovecot-mysql, rspamd. Run as root on a DISPOSABLE machine/container:
# it creates a system user "vmail" and (in the live-apply test) rewrites /etc/exim4,
# /etc/dovecot and /etc/rspamd/local.d.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
KM=(php "$ROOT/kloxo/bin/mail/kloxo-mail")
T="$(mktemp -d)"
export KLOXO_MAIL_CONF="$T/mail.ini"
export KLOXO_MAIL_TEMPLATES="$ROOT/kloxo/file/mail"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d" "command failed: $*"; fi; }
fails(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d" "expected failure: $*"; else ok "$d"; fi; }
eq()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
sql()  { mysql -N -e "$1" kloxo_mail_test; }
# Same lookups Exim uses (quote_mysql on these keys is identity).
qdom() { sql "SELECT 1 FROM domains WHERE name='$1' AND active=1"; }
qmbox(){ sql "SELECT 1 FROM mailboxes m JOIN domains d ON d.id=m.domain_id WHERE m.email='$1' AND m.active=1 AND d.active=1"; }
qalias(){ sql "SELECT t.target FROM aliases a JOIN alias_targets t ON t.alias_id=a.id JOIN domains d ON d.id=a.domain_id WHERE a.source='$1' AND a.active=1 AND d.active=1" | tr '\t' '\n' | sort | tr '\n' ' '; }
qlogin(){ sql "SELECT login FROM (
  SELECT m.email AS login FROM mailboxes m JOIN domains d ON d.id=m.domain_id WHERE m.email='$1' AND m.active=1 AND d.active=1
  UNION
  SELECT m.email AS login FROM aliases a JOIN alias_targets t ON t.alias_id=a.id JOIN mailboxes m ON m.email=t.target JOIN domains d ON d.id=a.domain_id WHERE a.source='$1' AND a.active=1 AND d.active=1 AND m.active=1
) s" | sort | tr '\n' ' '; }
PW='correct horse battery'
EXIMBIN="$(command -v exim4 2>/dev/null || command -v exim 2>/dev/null || true)"

echo "== source invariants"
if grep -A40 'function validateLive' "$ROOT/kloxo/bin/mail/kloxo-mail" | grep -q 'exim binary not found'; then
  ok "validateLive fails closed when exim is missing"
else
  bad "validateLive fails closed when exim is missing"
fi
if grep -A40 'function validateLive' "$ROOT/kloxo/bin/mail/kloxo-mail" | grep -q 'array_filter'; then
  bad "validateLive does not drop a missing exim checker via array_filter"
else
  ok "validateLive does not drop a missing exim checker via array_filter"
fi
if grep -A50 'function validateLive' "$ROOT/kloxo/bin/mail/kloxo-mail" | grep -q 'not found; cannot validate mail configuration'; then
  ok "validateLive fails closed when doveconf or rspamadm is missing"
else
  bad "validateLive fails closed when doveconf or rspamadm is missing"
fi

echo "== setup"
mysql -e "DROP DATABASE IF EXISTS kloxo_mail_test; DROP USER IF EXISTS 'kloxo_mail_test_rw'@'localhost'; DROP USER IF EXISTS 'kloxo_mail_test_ro'@'localhost';"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$T/tls.key" -out "$T/tls.pem" -subj "/CN=mail.example.com" -days 2 >/dev/null 2>&1
check "init" "${KM[@]}" init --hostname=mail.example.com --db-name=kloxo_mail_test --vmail-dir="$T/vmail" --dkim-dir="$T/dkim" --rspamd-dir="$T/rspamd" --tls-cert="$T/tls.pem" --tls-key="$T/tls.key"
eq "config file is 0600" "$(stat -c %a "$KLOXO_MAIL_CONF")" "600"
fails "init refuses to overwrite config" "${KM[@]}" init --hostname=mail.example.com --db-name=kloxo_mail_test

echo "== input validation (injection / bad input)"
fails "bad domain rejected"            "${KM[@]}" domain:add "example.com'; DROP TABLE domains;--"
fails "uppercase+space domain rejected" "${KM[@]}" domain:add "exa mple.com"
fails "no TLD rejected"                "${KM[@]}" domain:add "localhost"
check "valid domain added"             "${KM[@]}" domain:add example.com
check "second domain added"            "${KM[@]}" domain:add example.org --no-dkim
fails "duplicate domain rejected"      "${KM[@]}" domain:add example.com
check "domains table still exists"     sql "SELECT 1 FROM domains LIMIT 1"
echo "$PW" | "${KM[@]}" mailbox:add alice@example.com --password-stdin --quota=500 >/dev/null 2>&1 && ok "mailbox alice created" || bad "mailbox alice created"
echo "$PW" | "${KM[@]}" mailbox:add bob@example.com   --password-stdin >/dev/null 2>&1 && ok "mailbox bob created" || bad "mailbox bob created"
echo "$PW" | "${KM[@]}" mailbox:add carol@example.com --password-stdin >/dev/null 2>&1 && ok "mailbox carol created" || bad "mailbox carol created"
echo "short" | "${KM[@]}" mailbox:add dave@example.com --password-stdin >/dev/null 2>&1 && bad "short password rejected" || ok "short password rejected"
echo "$PW" | "${KM[@]}" mailbox:add "x'@example.com" --password-stdin >/dev/null 2>&1 && bad "quote in local part rejected" || ok "quote in local part rejected"
echo "$PW" | "${KM[@]}" mailbox:add alice@example.com --password-stdin >/dev/null 2>&1 && bad "duplicate mailbox rejected" || ok "duplicate mailbox rejected"
echo "$PW" | "${KM[@]}" mailbox:add eve@nodomain.com --password-stdin >/dev/null 2>&1 && bad "mailbox in unknown domain rejected" || ok "mailbox in unknown domain rejected"
fails "password on argv is not accepted" "${KM[@]}" mailbox:add zed@example.com --password=secretsecret
eq "password stored as Argon2id" "$(sql "SELECT LEFT(password_hash,20) FROM mailboxes WHERE email='alice@example.com'")" '{ARGON2ID}$argon2id$'
eq "quota stored" "$(sql "SELECT quota_mb FROM mailboxes WHERE email='alice@example.com'")" "500"

echo "== aliases, forwarders, catch-all"
check "alias info -> alice"      "${KM[@]}" alias:add info@example.com alice@example.com
check "catch-all -> alice"       "${KM[@]}" alias:add @example.com alice@example.com
check "forward-only carol -> external" "${KM[@]}" alias:add carol@example.com carol.private@example.net
check "forward+copy bob"         "${KM[@]}" alias:add bob@example.com bob@example.com bob.mobile@example.net
fails "alias for unknown domain" "${KM[@]}" alias:add x@unknown.com alice@example.com

echo "== render"
check "render" "${KM[@]}" render --out="$T/out"
EXIMCONF="$T/out/etc/exim4/exim4.conf"
eq "Exim config is 0640" "$(stat -c %a "$EXIMCONF")" "640"
if grep -rq '@@' "$T/out"; then bad "no unreplaced placeholders" "$(grep -rn '@@' "$T/out" | head -3)"; else ok "no unreplaced placeholders"; fi

check "DKIM map lists only domains with a key (example.com)" grep -q '^example.com kloxo$' "$T/out/etc/rspamd/kloxo/dkim_selectors.map"
if grep -q 'example.org' "$T/out/etc/rspamd/kloxo/dkim_selectors.map"; then bad "domain created with --no-dkim is not in the DKIM map"; else ok "domain created with --no-dkim is not in the DKIM map"; fi
check "dkim_signing.conf points to the maps" grep -q "map = \"$T/rspamd/kloxo/dkim_selectors.map\"" "$T/out/etc/rspamd/local.d/dkim_signing.conf"

echo "== Exim: configuration syntax"
if [ -n "$EXIMBIN" ]; then
  check "exim4.conf parses" "$EXIMBIN" -C "$EXIMCONF" -bV
else
  bad "exim4.conf parses" "exim4 not installed"
fi
check "AUTH advertised only with TLS on 587/465" grep -q 'auth_advertise_hosts' "$EXIMCONF"
check "TLS 1.0/1.1 disabled" grep -q 'VERS-TLS1.1' "$EXIMCONF"
check "implicit TLS on 465" grep -q 'tls_on_connect_ports = 465' "$EXIMCONF"
check "quota socket before local permit" grep -q '/run/dovecot/quota-status' "$EXIMCONF"
if grep -q 'spam = nobody:true' "$EXIMCONF" && ! grep -q 'defer_ok' "$EXIMCONF"; then
  ok "Rspamd failure tempfails (no defer_ok)"
else
  bad "Rspamd failure tempfails (no defer_ok)"
fi
mbox_line=$(grep -n '^kloxo_mailbox:' "$EXIMCONF" | head -1 | cut -d: -f1)
catch_line=$(grep -n '^kloxo_catchall:' "$EXIMCONF" | head -1 | cut -d: -f1)
alias_line=$(grep -n '^kloxo_alias:' "$EXIMCONF" | head -1 | cut -d: -f1)
if [ -n "$alias_line" ] && [ -n "$mbox_line" ] && [ "$alias_line" -lt "$mbox_line" ]; then
  ok "exact alias router before mailbox"
else
  bad "exact alias router before mailbox"
fi
if [ -n "$mbox_line" ] && [ -n "$catch_line" ] && [ "$mbox_line" -lt "$catch_line" ]; then
  ok "catch-all router after mailbox"
else
  bad "catch-all router after mailbox"
fi
# 2.3 uses absolute /run/dovecot/...; 2.4 uses base_dir-relative names (absolute
# paths duplicate the default listeners and make doveconf fatal on Ubuntu 26).
check "Dovecot LMTP owned by Exim" grep -qE 'unix_listener (/run/dovecot/)?lmtp' "$T/out/etc/dovecot/dovecot.conf"
check "Dovecot SASL owned by Exim" grep -qE 'unix_listener (/run/dovecot/)?auth-client' "$T/out/etc/dovecot/dovecot.conf"

echo "== Exim: SQL lookups (same queries as the routers/ACLs)"
eq "domain exists"                 "$(qdom example.com)" "1"
eq "unknown domain"                "$(qdom nope.com)" ""
eq "mailbox exists"                "$(qmbox alice@example.com)" "1"
eq "unknown mailbox"               "$(qmbox ghost@example.com)" ""
eq "alias expands"                 "$(qalias info@example.com)" "alice@example.com "
eq "catch-all key expands"         "$(qalias @example.com)" "alice@example.com "
eq "real mailbox maps to itself (catch-all cannot swallow it)" "$(qalias bob.nonalias@example.com)" ""
eq "mailbox without alias has no exact alias" "$(qalias alice@example.com)" ""
eq "mailbox without alias is still a mailbox" "$(qmbox alice@example.com)" "1"
eq "forward-only mailbox -> external only" "$(qalias carol@example.com)" "carol.private@example.net "
eq "forward+copy -> both"          "$(qalias bob@example.com)" "bob.mobile@example.net bob@example.com "
eq "sender login: own address"     "$(qlogin alice@example.com)" "alice@example.com "
eq "sender login: alias owner"     "$(qlogin info@example.com)" "alice@example.com "
eq "sender login: stranger"        "$(qlogin stranger@example.com)" ""
eq "SQL injection string in key"   "$(sql "SELECT 1 FROM mailboxes m JOIN domains d ON d.id=m.domain_id WHERE m.email='x\\' OR \\'1\\'=\\'1' AND m.active=1 AND d.active=1")" ""
"${KM[@]}" domain:disable example.org >/dev/null
eq "disabled domain not accepted"  "$(qdom example.org)" ""
"${KM[@]}" domain:enable example.org >/dev/null
"${KM[@]}" mailbox:disable bob@example.com >/dev/null
eq "disabled mailbox not accepted" "$(qmbox bob@example.com)" ""
"${KM[@]}" mailbox:enable bob@example.com >/dev/null

echo "== Dovecot: configuration and password hashes"
check "doveconf parses rendered config" doveconf -c "$T/out/etc/dovecot/dovecot.conf" -n
H="$(sql "SELECT password_hash FROM mailboxes WHERE email='alice@example.com'")"
check "Dovecot verifies the PHP-made hash" doveadm pw -t "$H" -p "$PW"
fails "Dovecot rejects a wrong password"   doveadm pw -t "$H" -p "wrong password here"
eq "TLS required"     "$(doveconf -c "$T/out/etc/dovecot/dovecot.conf" -h ssl)" "required"
eq "plaintext auth off" "$(doveconf -c "$T/out/etc/dovecot/dovecot.conf" -h disable_plaintext_auth)" "yes"
check "sieve script compiles" sievec "$T/out/var/lib/dovecot/sieve/before.d/00-kloxo-spam.sieve" "$T/spam.svbin"

echo "== DKIM and DNS"
check "DKIM key exists"  test -f "$T/dkim/example.com.kloxo.key"
eq "DKIM key mode"       "$(stat -c %a "$T/dkim/example.com.kloxo.key")" "640"
case "$("${KM[@]}" dkim:show example.com)" in *"v=DKIM1; k=rsa; p=MII"*) ok "DKIM TXT record";; *) bad "DKIM TXT record";; esac
D="$("${KM[@]}" dns:records example.com --ip=203.0.113.7)"
case "$D" in *"MX"*"10 mail.example.com."*"v=spf1 mx ip4:203.0.113.7 -all"*"_dmarc.example.com"*) ok "DNS records (MX, SPF, DMARC)";; *) bad "DNS records" "$D";; esac

echo "== apply (files, backup, rollback) below a fake root"
check "apply to fake root" "${KM[@]}" apply --root="$T/fakeroot"
check "files installed"    test -f "$T/fakeroot/etc/exim4/exim4.conf"
echo "# local edit" >> "$T/fakeroot/etc/exim4/exim4.conf"
check "apply again (idempotent)" "${KM[@]}" apply --root="$T/fakeroot"

echo "== purge safety"
mkdir -p "$T/vmail/example.com/carol/Maildir"
check "mailbox:del --purge removes maildir" "${KM[@]}" mailbox:del carol@example.com --purge
check "maildir gone" test ! -d "$T/vmail/example.com/carol"
check "other mailboxes intact" "${KM[@]}" mailbox:list example.com

echo "== check command"
check "kloxo-mail check" "${KM[@]}" check

echo
echo "RESULT: $PASS passed, $FAIL failed"
mysql -e "DROP DATABASE IF EXISTS kloxo_mail_test; DROP USER IF EXISTS 'kloxo_mail_test_rw'@'localhost'; DROP USER IF EXISTS 'kloxo_mail_test_ro'@'localhost';" 2>/dev/null
rm -rf "$T"
[ "$FAIL" -eq 0 ]
