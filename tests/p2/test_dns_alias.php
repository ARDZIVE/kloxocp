<?php
/**
 * DNS set to none must really disable BIND. On Debian/Ubuntu bind9.service is an alias of
 * named.service and systemd refuses enable/disable on an alias, so `disable --now bind9`
 * failed and BIND kept its green Sb and State icons. Fake systemctl that behaves like that.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $c, string $d, string $x = ''): void
{
	global $pass, $fail;
	if ($c) {
		$pass++;
		printf("  ok   %s\n", $d);
	} else {
		$fail++;
		printf("  FAIL %s\n", $d);
		if ($x !== '') {
			printf("       %s\n", $x);
		}
	}
}

$repo = dirname(__DIR__, 2);
require_once $repo . '/kloxo/httpdocs/lib/php/kloxo_dns.php';

$tmp = sys_get_temp_dir() . '/kloxo-dns-alias-' . getmypid();
mkdir($tmp . '/bin', 0755, true);
$log = $tmp . '/calls.log';
$script = <<<'SH'
#!/bin/sh
echo "systemctl $*" >> "__LOG__"
# systemctl show -p Id --value <unit>: the real unit behind an alias
if [ "$1" = show ]; then
  case "$5" in
    bind9|bind9.service) echo named.service ;;
    knot|knot.service) echo knot.service ;;
    *) echo "$5" ;;
  esac
  exit 0
fi
# enable/disable refuse alias names, as systemd does
for a in "$@"; do
  case "$a" in
    bind9|bind9.service)
      case "$1" in
        enable|disable) echo "Failed to $1 unit: Refusing to operate on alias name or linked unit file: $a" >&2; exit 1 ;;
      esac ;;
  esac
done
exit 0
SH;
file_put_contents($tmp . '/bin/systemctl', str_replace('__LOG__', $log, $script));
chmod($tmp . '/bin/systemctl', 0755);
putenv('KLOXO_DNS_PATH=' . $tmp . '/bin:/usr/bin:/bin');

$calls = static fn (): array => is_file($log) ? array_map('trim', file($log)) : array();
$reset = static function () use ($log): void { @unlink($log); };

check(kloxo_dns_canonical_unit('bind9') === 'named.service', 'alias bind9 resolves to its real unit named.service');
check(kloxo_dns_canonical_unit('knot') === 'knot.service', 'a real unit resolves to itself');

$reset();
$rc = kloxo_dns_systemctl('disable', 'bind9');
$c = $calls();
check($rc === 0, 'disable bind9 succeeds although bind9 is an alias', "rc=$rc");
check(in_array('systemctl disable --now named.service', $c, true), 'it runs `disable --now named.service`, not the alias', implode(' | ', $c));
check(in_array('systemctl stop bind9', $c, true), 'it stops the unit first (works through the alias too)');
check(!in_array('systemctl disable --now bind9', $c, true), 'no disable on the alias name');

$reset();
check(kloxo_dns_systemctl('enable', 'bind9') === 0 && in_array('systemctl enable --now named.service', $calls(), true), 'enable bind9 also goes to the real unit');

// DNS none: both servers are turned off
$reset();
kloxo_dns_switch('none');
$c = $calls();
check(in_array('systemctl disable --now knot.service', $c, true) && count(array_filter($c, static fn($l) => str_contains($l, 'disable --now'))) === 2,
	'switch to none disables both DNS servers', implode(' | ', $c));
// (kloxo_dns_unit() picks bind9/named from files that exist on the host, so the BIND
// half of this switch is covered by the explicit bind9 checks above)

check(kloxo_dns_systemctl('disable', '../x') === 1 && kloxo_dns_systemctl('frob', 'bind9') === 1, 'unsafe unit names and actions are refused');

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
