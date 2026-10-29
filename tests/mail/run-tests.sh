#!/bin/bash
# Integration tests for kloxo-mail + the Postfix/Dovecot/Rspamd templates.
# Needs: php-cli, a running MariaDB (root via unix socket), postfix + postfix-mysql,
# dovecot-core + dovecot-mysql, rspamd. Run as root on a DISPOSABLE machine/container:
# it creates a system user "vmail" and (in the live-apply test) rewrites /etc/postfix,
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
pm()   { postmap -c "$T/out/etc/postfix" -q "$2" "mysql:$T/out/etc/postfix/sql/$1.cf" 2>/dev/null; }
pmerr(){ postmap -c "$T/out/etc/postfix" -q "$2" "mysql:$T/out/etc/postfix/sql/$1.cf" 2>&1 >/dev/null | grep -v 'inet_protocols'; }   # lookup in rendered map
sql()  { mysql -N -e "$1" kloxo_mail_test; }
PW='correct horse battery'

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
eq "SQL map is 0640" "$(stat -c %a "$T/out/etc/postfix/sql/virtual-domains.cf")" "640"
if grep -rq '@@' "$T/out"; then bad "no unreplaced placeholders" "$(grep -rn '@@' "$T/out" | head -3)"; else ok "no unreplaced placeholders"; fi

check "DKIM map lists only domains with a key (example.com)" grep -q '^example.com kloxo$' "$T/out/etc/rspamd/kloxo/dkim_selectors.map"
if grep -q 'example.org' "$T/out/etc/rspamd/kloxo/dkim_selectors.map"; then bad "domain created with --no-dkim is not in the DKIM map"; else ok "domain created with --no-dkim is not in the DKIM map"; fi
check "dkim_signing.conf points to the maps" grep -q "map = \"$T/rspamd/kloxo/dkim_selectors.map\"" "$T/out/etc/rspamd/local.d/dkim_signing.conf"

echo "== Postfix: configuration syntax"
check "main.cf parses"   postconf -c "$T/out/etc/postfix" -n
check "master.cf parses" postconf -c "$T/out/etc/postfix" -M
eq "virtual_transport" "$(postconf -c "$T/out/etc/postfix" -h virtual_transport 2>/dev/null)" "lmtp:unix:private/dovecot-lmtp"
eq "submission enforces TLS" "$(postconf -c "$T/out/etc/postfix" -P 2>/dev/null | grep -c 'submission/inet/smtpd_tls_security_level = encrypt')" "1"
eq "smtpd_tls_protocols"  "$(postconf -c "$T/out/etc/postfix" -h smtpd_tls_protocols 2>/dev/null)" ">=TLSv1.2"

echo "== Postfix: SQL lookups (what Postfix really asks the database)"
for m in virtual-domains virtual-mailboxes virtual-aliases sender-login; do
  if [ -z "$(pmerr $m probe@example.com)" ]; then ok "map $m runs without errors/warnings"; else bad "map $m runs without errors" "$(pmerr $m probe@example.com)"; fi
done
eq "domain exists"                 "$(pm virtual-domains example.com)" "1"
eq "unknown domain"                "$(pm virtual-domains nope.com)" ""
eq "mailbox exists"                "$(pm virtual-mailboxes alice@example.com)" "1"
eq "unknown mailbox"               "$(pm virtual-mailboxes ghost@example.com)" ""
eq "alias expands"                 "$(pm virtual-aliases info@example.com)" "alice@example.com"
eq "catch-all key expands"         "$(pm virtual-aliases @example.com)" "alice@example.com"
eq "real mailbox maps to itself (catch-all cannot swallow it)" "$(pm virtual-aliases bob.nonalias@example.com)" ""
eq "mailbox without alias -> itself" "$(pm virtual-aliases alice@example.com)" "alice@example.com"
eq "forward-only mailbox -> external only" "$(pm virtual-aliases carol@example.com)" "carol.private@example.net"
eq "forward+copy -> both"          "$(pm virtual-aliases bob@example.com | tr ',' '\n' | sort | tr '\n' ' ')" "bob.mobile@example.net bob@example.com "
eq "sender login: own address"     "$(pm sender-login alice@example.com)" "alice@example.com"
eq "sender login: alias owner"     "$(pm sender-login info@example.com)" "alice@example.com"
eq "sender login: stranger"        "$(pm sender-login stranger@example.com)" ""
eq "SQL injection string in key"   "$(pm virtual-mailboxes "x' OR '1'='1")" ""
"${KM[@]}" domain:disable example.org >/dev/null
eq "disabled domain not accepted"  "$(pm virtual-domains example.org)" ""
"${KM[@]}" domain:enable example.org >/dev/null
"${KM[@]}" mailbox:disable bob@example.com >/dev/null
eq "disabled mailbox not accepted" "$(pm virtual-mailboxes bob@example.com)" ""
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
check "files installed"    test -f "$T/fakeroot/etc/postfix/main.cf"
echo "# local edit" >> "$T/fakeroot/etc/postfix/main.cf"
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
