<?php
/**
 * dns__knot::createConfFile maps a panel dns object (records, SOA fields, addon
 * domains) to zone files, using the real driver class and KloxoKnot against FAKE
 * kzonecheck / knotc. NOT covered: syncCreateConf (needs the panel's rl_exec_get
 * round trip to the daemon), a real Knot, a live panel DB.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $cond, string $d, string $x = ''): void
{
	global $pass, $fail;
	if ($cond) {
		$pass++;
		printf("  ok   %s\n", $d);
		return;
	}
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';
putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
include_once $doc . '/lib/html/include.php';

$tmp = sys_get_temp_dir() . '/kloxo-knot-drv-' . getmypid();
@mkdir($tmp . '/bin', 0755, true);
@mkdir($tmp . '/var/lib/knot/zones', 0755, true);
file_put_contents($tmp . '/bin/kzonecheck', "#!/bin/sh\nfor last; do :; done\ngrep -q BADZONE \"\$last\" && exit 1\nexit 0\n");
chmod($tmp . '/bin/kzonecheck', 0755);
dns__knot::$opt = ['base' => $tmp, 'bindirs' => [$tmp . '/bin']];

function rec(string $type, string $host, string $param, array $more = []): object
{
	$o = new stdClass();
	$o->ttype = $type;
	$o->hostname = $host;
	$o->param = $param;
	foreach ($more as $k => $v) {
		$o->$k = $v;
	}
	$o->__parent_o = 'must not be read';

	return $o;
}

$ref = new ReflectionClass('dns__knot');
check($ref->getParentClass() !== false && $ref->getParentClass()->getName() === 'dns__', 'dns__knot extends dns__');
$drv = $ref->newInstanceWithoutConstructor();
$main = new stdClass();
$main->nname = 'example.com';
$main->ttl = '3600';
$main->soanameserver = 'ns1.example.com';
$main->hostmaster = '';
$main->__var_ddate = 2026110701;
$main->__var_addonlist = [(object) ['nname' => 'example.org']];
$main->dns_record_a = [
	rec('ns', '__base__', 'ns1.example.com'),
	rec('a', 'ns1', '192.0.2.1'),
	rec('a', 'www', '192.0.2.2'),
	rec('mx', '__base__', 'mail', ['priority' => '10']),
	rec('a', 'mail', '192.0.2.3'),
];
$drv->main = $main;

try {
	$drv->createConfFile();
	check(true, 'createConfFile runs');
} catch (Throwable $e) {
	check(false, 'createConfFile runs', get_class($e) . ': ' . $e->getMessage());
}
$z1 = $tmp . '/var/lib/knot/zones/example.com.zone';
$z2 = $tmp . '/var/lib/knot/zones/example.org.zone';
check(is_file($z1) && is_file($z2), 'a zone file for the domain and one for the addon domain');
$t1 = is_file($z1) ? (string) file_get_contents($z1) : '';
$t2 = is_file($z2) ? (string) file_get_contents($z2) : '';
check(str_contains($t1, 'www.example.com. IN A 192.0.2.2') && str_contains($t1, 'example.com. IN MX 10 mail.example.com.'), 'records of the dns object land in the zone');
check(str_contains($t1, 'example.com. IN SOA ns1.example.com. admin.example.com. ( 2026110701 '), 'SOA from the object, hostmaster defaults to admin@domain', $t1);
check(str_contains($t2, 'www.example.org. IN A 192.0.2.2') && str_contains($t2, 'example.org. IN MX 10 mail.example.org.'), 'the addon domain gets the same records under its own name');
check(!str_contains($t1, 'must not be read'), 'only known record fields are read from the objects');

$main->dns_record_a[] = rec('txt', '__base__', 'BADZONE');
try {
	$drv->createConfFile();
	check(false, 'a zone rejected by kzonecheck raises lxException');
} catch (lxException $e) {
	check(str_contains($e->getMessage(), 'Knot DNS'), 'a zone rejected by kzonecheck raises lxException', $e->getMessage());
} catch (Throwable $e) {
	check(false, 'a zone rejected by kzonecheck raises lxException', get_class($e));
}
check((string) file_get_contents($z1) === $t1, 'the live zone is untouched after the rejection');

$rm = static function (string $d) use (&$rm): void {
	foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
		if (in_array(basename($f), ['.', '..'], true)) {
			continue;
		}
		is_dir($f) && !is_link($f) ? $rm($f) : @unlink($f);
	}
	@rmdir($d);
};
$rm($tmp);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
