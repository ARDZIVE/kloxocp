#!/bin/bash
# Tests for generate-tls-keys. Needs php-cli and openssl. Safe to run anywhere:
# all files go under a temp directory.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
TOOL=(php "$ROOT/kloxo/bin/install/generate-tls-keys")
T="$(mktemp -d "${TMPDIR:-/tmp}/kloxo-tls.XXXXXX")"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d" "command failed: $*"; fi; }
fails(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d" "expected failure: $*"; else ok "$d"; fi; }
eq()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
mode_of() {
  if stat -c %a "$1" >/dev/null 2>&1; then
    stat -c %a "$1"
  else
    stat -f %OLp "$1"
  fi
}
cleanup() { rm -rf "$T"; }
trap cleanup EXIT

echo "== generate-tls-keys"

check "php -l generate-tls-keys" php -l "$ROOT/kloxo/bin/install/generate-tls-keys"
check "help exits 0" "${TOOL[@]}" --help
check "version exits 0" "${TOOL[@]}" --version

fails "bad hostname rejected" "${TOOL[@]}" --root="$T" --hostname='bad host'
fails "slash in hostname rejected" "${TOOL[@]}" --root="$T" --hostname='../evil'
fails "days=0 rejected" "${TOOL[@]}" --root="$T" --hostname=panel.example.com --days=0
fails "days=9999 rejected" "${TOOL[@]}" --root="$T" --hostname=panel.example.com --days=9999

OUT="$T/first.out"
if "${TOOL[@]}" --root="$T" --hostname=panel.example.com --days=2 >"$OUT" 2>"$T/first.err"; then
  ok "generate into empty root"
else
  bad "generate into empty root" "$(cat "$T/first.err")"
fi
if grep -q 'BEGIN ' "$OUT" && grep -q 'PRIVATE KEY' "$OUT"; then
  bad "stdout does not contain key material"
else
  ok "stdout does not contain key material"
fi
if grep -q 'BEGIN ' "$T/first.err" && grep -q 'PRIVATE KEY' "$T/first.err"; then
  bad "stderr does not contain key material"
else
  ok "stderr does not contain key material"
fi

for f in init/backend.key file/ssl/default.key file/ssl/default.pem; do
  eq "$f is 0600" "$(mode_of "$T/$f")" "600"
done
for f in init/backend.crt file/ssl/default.crt; do
  eq "$f is 0644" "$(mode_of "$T/$f")" "644"
done

check "backend cert parses" openssl x509 -in "$T/init/backend.crt" -noout -subject
check "default cert parses"  openssl x509 -in "$T/file/ssl/default.crt" -noout -subject
check "backend key parses"  openssl pkey -in "$T/init/backend.key" -noout -check
check "default key parses"   openssl pkey -in "$T/file/ssl/default.key" -noout -check
check "pem contains a cert"  grep -q 'BEGIN CERTIFICATE' "$T/file/ssl/default.pem"
if grep -q 'BEGIN ' "$T/file/ssl/default.pem" && grep -q 'PRIVATE KEY' "$T/file/ssl/default.pem"; then
  ok "pem contains a key"
else
  bad "pem contains a key"
fi
subj_b="$(openssl x509 -in "$T/init/backend.crt" -noout -subject 2>/dev/null || true)"
subj_d="$(openssl x509 -in "$T/file/ssl/default.crt" -noout -subject 2>/dev/null || true)"
if echo "$subj_b" | grep -q 'panel.example.com'; then ok "backend CN"; else bad "backend CN" "$subj_b"; fi
if echo "$subj_d" | grep -q 'panel.example.com'; then ok "default CN"; else bad "default CN" "$subj_d"; fi

BK1="$(openssl pkey -in "$T/init/backend.key" -pubout 2>/dev/null | openssl md5)"
DK1="$(openssl pkey -in "$T/file/ssl/default.key" -pubout 2>/dev/null | openssl md5)"
if [ "$BK1" != "$DK1" ]; then
  ok "backend and default keys are independent"
else
  bad "backend and default keys are independent" "same public key"
fi

cp "$T/init/backend.key" "$T/backend.key.before"
fails "second run without --force refuses" "${TOOL[@]}" --root="$T" --hostname=panel.example.com --days=2
check "refused run left backend.key unchanged" cmp -s "$T/init/backend.key" "$T/backend.key.before"

if "${TOOL[@]}" --root="$T" --hostname=other.example.com --days=2 --force >"$T/force.out" 2>"$T/force.err"; then
  ok " --force regenerates"
else
  bad " --force regenerates" "$(cat "$T/force.err")"
fi
BK2="$(openssl pkey -in "$T/init/backend.key" -pubout 2>/dev/null | openssl md5)"
if [ "$BK1" != "$BK2" ]; then
  ok "--force wrote a new backend key"
else
  bad "--force wrote a new backend key" "key did not change"
fi
subj_f="$(openssl x509 -in "$T/init/backend.crt" -noout -subject 2>/dev/null || true)"
if echo "$subj_f" | grep -q 'other.example.com'; then ok "forced cert uses new hostname"; else bad "forced cert uses new hostname" "$subj_f"; fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
