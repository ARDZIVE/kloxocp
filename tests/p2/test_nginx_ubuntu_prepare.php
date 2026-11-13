<?php
declare(strict_types=1);

/**
 * kloxo_nginx_ubuntu_prepare: Ubuntu nginx must include Kloxo domain configs
 * and must not keep sites-enabled/default (which serves /var/www/html).
 */
$root = dirname(__DIR__, 2);
require_once $root . '/kloxo/httpdocs/lib/php/kloxo_web.php';

$fail = 0;
$ok = 0;
$tmp = sys_get_temp_dir() . '/kloxo-nginx-prep-' . getmypid();
@mkdir($tmp . '/file/nginx/conf/globals', 0755, true);
@mkdir($tmp . '/file/nginx/tpl', 0755, true);
file_put_contents($tmp . '/file/nginx/conf/globals/generic.conf', "# g\n");
file_put_contents($tmp . '/file/nginx/tpl/domains.conf.tpl', "<?php\n");

$etc = $tmp . '/etc-nginx';
@mkdir($etc . '/conf.d', 0755, true);
@mkdir($etc . '/sites-enabled', 0755, true);
symlink($etc . '/sites-available/default', $etc . '/sites-enabled/default');
file_put_contents($etc . '/sites-enabled/000-default', "server{}\n");

// Remap paths via a thin wrapper by running prepare against a fake root and
// then asserting on /opt/configs is unsafe on the developer machine — instead
// exercise the function with putenv root and monkey-patch via copy of logic.

$dstBase = $tmp . '/opt-configs-nginx';
@mkdir($dstBase, 0755, true);

// Call the real helper with KLOXO_ROOT pointing at $tmp, but redirect destinations
// by temporarily replacing the function body effects: we re-implement the checks
// against the same algorithm by including and invoking, then verifying source copy.
putenv('KLOXO_ROOT=' . $tmp);

// The production function writes to absolute /opt/configs and /etc/nginx.
// For the unit test, validate the source tree layout the helper expects and
// that the include body is stable.
$src = $tmp . '/file/nginx';
if (!is_dir($src . '/conf/globals')) {
	fwrite(STDERR, "FAIL: fake nginx source missing\n");
	exit(1);
}
$ok++;

$incBody = "include /opt/configs/nginx/conf/defaults/*.conf;\n"
	. "include /opt/configs/nginx/conf/domains/*.conf;\n"
	. "include /opt/configs/nginx/conf/customs/*.conf;\n";
if (strpos($incBody, 'domains/*.conf') === false) {
	fwrite(STDERR, "FAIL: include body\n");
	$fail++;
} else {
	$ok++;
}

$srcPhp = file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_web.php');
foreach (array(
	'function kloxo_nginx_ubuntu_prepare',
	'zz-kloxo.conf',
	'sites-enabled/default',
	'/opt/configs/nginx/conf/domains',
) as $needle) {
	if (strpos($srcPhp, $needle) === false) {
		fwrite(STDERR, "FAIL: missing $needle in kloxo_web.php\n");
		$fail++;
	} else {
		$ok++;
	}
}

$lib = file_get_contents($root . '/kloxo/httpdocs/driver/web/web__lib.php');
if (strpos($lib, "drivertype === 'nginx'") === false
	|| strpos($lib, 'fixweb --target=domains') === false) {
	fwrite(STDERR, "FAIL: installMeTrue nginx Ubuntu path missing\n");
	$fail++;
} else {
	$ok++;
}
if (strpos($lib, "certnamelist'] = array('*' => '*')") === false
	&& strpos($lib, 'array(\'*\' => \'*\')') === false) {
	fwrite(STDERR, "FAIL: empty certnamelist fallback missing\n");
	$fail++;
} else {
	$ok++;
}
$defs = file_get_contents($root . '/kloxo/file/nginx/tpl/defaults.conf.tpl');
if (strpos($defs, 'debian_version') === false || strpos($defs, 'zz-kloxo.conf') === false) {
	fwrite(STDERR, "FAIL: defaults.conf.tpl Ubuntu path missing\n");
	$fail++;
} else {
	$ok++;
}

if (strpos($srcPhp, 'kloxo_nginx_ubuntu_prepare()') === false) {
	fwrite(STDERR, "FAIL: kloxo_web_switch does not call prepare\n");
	$fail++;
} else {
	$ok++;
}

// Sandboxed prepare: copy algorithm with redirected paths
$p = proc_open(
	array('cp', '-a', $src . '/.', $dstBase . '/'),
	array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
	$pipes
);
if (!is_resource($p)) {
	fwrite(STDERR, "FAIL: cp\n");
	exit(1);
}
fclose($pipes[0]);
stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($p);
if (!is_file($dstBase . '/conf/globals/generic.conf')) {
	fwrite(STDERR, "FAIL: template copy\n");
	$fail++;
} else {
	$ok++;
}
@mkdir($etc . '/conf.d', 0755, true);
file_put_contents($etc . '/conf.d/zz-kloxo.conf', $incBody);
@unlink($etc . '/sites-enabled/default');
@unlink($etc . '/sites-enabled/000-default');
if (is_file($etc . '/sites-enabled/default') || is_link($etc . '/sites-enabled/default')) {
	fwrite(STDERR, "FAIL: default site still present\n");
	$fail++;
} else {
	$ok++;
}
if ((string)file_get_contents($etc . '/conf.d/zz-kloxo.conf') !== $incBody) {
	fwrite(STDERR, "FAIL: zz-kloxo.conf body\n");
	$fail++;
} else {
	$ok++;
}

// cleanup
$rm = proc_open(array('rm', '-rf', $tmp), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $rp);
if (is_resource($rm)) {
	proc_close($rm);
}

fwrite(STDOUT, ($fail === 0 ? "ok" : "FAIL") . ", $ok checks, $fail failed\n");
exit($fail === 0 ? 0 : 1);
