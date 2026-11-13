<?php
/**
 * LogWatch backend + panel wiring invariants.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void
{
	global $fail;
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
include_once $doc . '/lib/php/kloxo_logwatch.php';

if (!class_exists('KloxoLogwatch')) {
	bad('KloxoLogwatch loads');
	printf("\n%d ok, %d FAIL\n", $pass, $fail);
	exit(1);
}
ok('KloxoLogwatch loads');

$fs = sys_get_temp_dir() . '/kloxo-lw-' . getmypid();
@mkdir($fs . '/etc/logwatch/conf', 0755, true);
@mkdir($fs . '/etc/cron.d', 0755, true);
@mkdir($fs . '/etc/cron.daily', 0755, true);
@mkdir($fs . '/usr/sbin', 0755, true);
@mkdir($fs . '/var/log/logwatch', 0755, true);
file_put_contents($fs . '/usr/sbin/logwatch', "#!/bin/sh\nexit 0\n");
chmod($fs . '/usr/sbin/logwatch', 0755);
file_put_contents($fs . '/etc/cron.daily/00logwatch', "#!/bin/sh\n");
KloxoLogwatch::$fsPrefix = $fs;

$cfgRoot = $fs . '/kloxo-root';
@mkdir($cfgRoot . '/etc/conf', 0755, true);
@mkdir($cfgRoot . '/bin/logwatch', 0755, true);
file_put_contents($cfgRoot . '/bin/logwatch/kloxo-logwatch', "#!/bin/sh\n");

$def = KloxoLogwatch::defaults();
if (empty($def['enabled'])) {
	ok('defaults leave LogWatch disabled until the admin enables it');
} else {
	bad('defaults leave LogWatch disabled until the admin enables it');
}

$cfg = KloxoLogwatch::normalizeConfig(array(
	'enabled' => true,
	'mailto' => 'admin@example.com',
	'mailfrom' => 'logwatch@example.com',
	'detail' => 'Med',
	'range' => 'Yesterday',
	'format' => 'html',
	'archives' => true,
	'frequency' => 'weekly',
	'hour' => 7,
	'minute' => 30,
	'keep_days' => 10,
	'save_reports' => true,
	'mail_reports' => true,
));
if ($cfg['mailto'] === 'admin@example.com' && $cfg['detail'] === 'Med'
	&& $cfg['frequency'] === 'weekly' && $cfg['keep_days'] === 10) {
	ok('normalizeConfig accepts panel settings');
} else {
	bad('normalizeConfig accepts panel settings', var_export($cfg, true));
}

$badMail = KloxoLogwatch::normalizeConfig(array('mailto' => 'bad;rm -rf /'));
if ($badMail['mailto'] === 'root') {
	ok('normalizeConfig rejects unsafe mailto');
} else {
	bad('normalizeConfig rejects unsafe mailto', (string) $badMail['mailto']);
}

$conf = KloxoLogwatch::renderConf($cfg);
if (str_contains($conf, 'MailTo = admin@example.com')
	&& str_contains($conf, 'Detail = Med')
	&& str_contains($conf, 'Format = html')
	&& str_contains($conf, 'Archives = Yes')) {
	ok('renderConf writes MailTo Detail Format Archives');
} else {
	bad('renderConf writes MailTo Detail Format Archives', $conf);
}

$cron = KloxoLogwatch::renderCron($cfg, $cfgRoot);
if (str_contains($cron, '30 7 * * 0')
	&& str_contains($cron, 'kloxo-logwatch run')
	&& str_contains($cron, '--root=' . $cfgRoot)) {
	ok('renderCron weekly schedule at hour:minute');
} else {
	bad('renderCron weekly schedule at hour:minute', $cron);
}

$off = KloxoLogwatch::renderCron(array_merge($cfg, array('enabled' => false)), $cfgRoot);
if (str_contains($off, 'disabled')) {
	ok('renderCron disabled when LogWatch off');
} else {
	bad('renderCron disabled when LogWatch off', $off);
}

$ret = KloxoLogwatch::apply($cfg, $cfgRoot);
if (!empty($ret['ok']) && is_file($fs . '/etc/logwatch/conf/logwatch.conf')
	&& is_file($fs . '/etc/cron.d/kloxo-logwatch')
	&& is_file($fs . '/etc/cron.daily/00logwatch.kloxo-disabled')
	&& !is_file($fs . '/etc/cron.daily/00logwatch')) {
	ok('apply writes conf+cron and mutes stock daily cron');
} else {
	bad('apply writes conf+cron and mutes stock daily cron', var_export($ret, true));
}

// Disabled install still mutes stock daily so LogWatch does not run until enabled.
file_put_contents($fs . '/etc/cron.daily/00logwatch', "#!/bin/sh\n");
@unlink($fs . '/etc/cron.daily/00logwatch.kloxo-disabled');
$dis = KloxoLogwatch::apply(array_merge($cfg, array('enabled' => false)), $cfgRoot);
$cronDis = (string) file_get_contents($fs . '/etc/cron.d/kloxo-logwatch');
if (!empty($dis['ok']) && str_contains($cronDis, 'disabled')
	&& is_file($fs . '/etc/cron.daily/00logwatch.kloxo-disabled')
	&& !is_file($fs . '/etc/cron.daily/00logwatch')) {
	ok('disabled apply keeps stock daily muted and cron.d off');
} else {
	bad('disabled apply keeps stock daily muted and cron.d off', $cronDis);
}

$old = $fs . '/var/log/logwatch/logwatch-2000-01-01.txt';
file_put_contents($old, 'old');
touch($old, time() - (40 * 86400));
$fresh = $fs . '/var/log/logwatch/logwatch-2099-01-01.txt';
file_put_contents($fresh, 'new');
$n = KloxoLogwatch::pruneReports(14);
if ($n >= 1 && !is_file($old) && is_file($fresh)) {
	ok('pruneReports deletes files older than keep_days');
} else {
	bad('pruneReports deletes files older than keep_days', "n=$n");
}

if (class_exists('logwatch')) {
	ok('logwatch panel class loads');
} else {
	bad('logwatch panel class loads');
}

$def = (string) file_get_contents($doc . '/lib/html/definecore.php');
$ps = (string) file_get_contents($doc . '/driver/pserver/pserverlib.php');
$menu = (string) file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
if (str_contains($def, "['logwatch']")
	&& str_contains($ps, 'a=show&o=logwatch')
	&& str_contains($menu, "class]=logwatch")
	&& is_file($doc . '/theme/icon/collage/logwatch_show.gif')) {
	ok('LogWatch is registered (definecore, Security tile, menu, icon)');
} else {
	bad('LogWatch is registered (definecore, Security tile, menu, icon)');
}

$inst = (string) file_get_contents(dirname($root) . '/install.sh');
if (str_contains($inst, 'prepare_logwatch') && str_contains($inst, 'logwatch \\')) {
	ok('install.sh packages and prepares LogWatch');
} else {
	bad('install.sh packages and prepares LogWatch');
}

// cleanup
KloxoLogwatch::$fsPrefix = '';
$rm = static function ($d) use (&$rm) {
	if (!is_dir($d)) {
		return;
	}
	foreach (scandir($d) ?: array() as $f) {
		if ($f === '.' || $f === '..') {
			continue;
		}
		$p = $d . '/' . $f;
		is_dir($p) ? $rm($p) : @unlink($p);
	}
	@rmdir($d);
};
$rm($fs);

printf("\n%d ok, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
