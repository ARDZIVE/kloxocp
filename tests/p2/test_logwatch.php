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
file_put_contents($fresh, "LogWatch report body\n");
$n = KloxoLogwatch::pruneReports(14);
if ($n >= 1 && !is_file($old) && is_file($fresh)) {
	ok('pruneReports deletes files older than keep_days');
} else {
	bad('pruneReports deletes files older than keep_days', "n=$n");
}

// Force-email last report ignores mail_reports=false.
KloxoLogwatch::saveConfig(array(
	'enabled' => false,
	'mailto' => 'admin@example.com',
	'mailfrom' => 'logwatch@example.com',
	'mail_reports' => false,
	'save_reports' => true,
), $cfgRoot);
@mkdir($fs . '/usr/sbin', 0755, true);
$outbox = $fs . '/sendmail.out';
@unlink($outbox);
file_put_contents($fs . '/usr/sbin/sendmail', "#!/bin/sh\ncat > \"$outbox\"\nexit 0\n");
chmod($fs . '/usr/sbin/sendmail', 0755);
$mail = KloxoLogwatch::mailLastReport($cfgRoot);
$outboxBody = is_file($outbox) ? (string) file_get_contents($outbox) : '';
if (!empty($mail['ok'])
	&& str_contains($outboxBody, 'To: admin@example.com')
	&& str_contains($outboxBody, 'LogWatch report body')
	&& str_contains($outboxBody, 'logwatch-2099-01-01.txt')) {
	ok('mailLastReport emails newest report even when mail_reports is off');
} else {
	bad('mailLastReport emails newest report even when mail_reports is off',
		var_export($mail, true) . "\n" . $outboxBody);
}

$emptyDir = $fs . '/var/log/logwatch-empty';
@mkdir($emptyDir, 0755, true);
foreach (glob($fs . '/var/log/logwatch/logwatch-*') ?: array() as $f) {
	@unlink($f);
}
$noReport = KloxoLogwatch::mailLastReport($cfgRoot);
if (($noReport['error'] ?? '') === 'logwatch_no_report') {
	ok('mailLastReport refuses when no saved report exists');
} else {
	bad('mailLastReport refuses when no saved report exists', var_export($noReport, true));
}
file_put_contents($fresh, "LogWatch report body\n");

if (class_exists('logwatch')) {
	ok('logwatch panel class loads');
} else {
	bad('logwatch panel class loads');
}

$lwSrc = (string) file_get_contents($doc . '/driver/pserver/logwatchlib.php');
if (!str_contains($lwSrc, 'runnow')
	&& !str_contains($lwSrc, 'updateRunnow')
	&& str_contains($lwSrc, 'updateEmaillast')
	&& str_contains($lwSrc, 'emaillast')
	&& str_contains($lwSrc, 'mailLastReport')) {
	ok('LogWatch page has Email last report and no Run Now');
} else {
	bad('LogWatch page has Email last report and no Run Now');
}
// Format/frequency/hour use non-assoc 's' lists (value === entry) so the form
// never injects "--Select One--". Range keeps assoc 'A' for the long key label.
if (preg_match("/lw_format'\\]\\s*=\\s*array\\('s'\\s*,\\s*array\\('text'/", $lwSrc)
	&& preg_match("/lw_frequency'\\]\\s*=\\s*array\\('s'/", $lwSrc)
	&& preg_match("/lw_detail'\\]\\s*=\\s*array\\('s'/", $lwSrc)
	&& preg_match("/lw_range'\\]\\s*=\\s*array\\('A'/", $lwSrc)
	&& str_contains($lwSrc, 'rememberShowReferer')
	&& str_contains($lwSrc, "class]=logwatch")
	&& str_contains($lwSrc, '--Select One--')) {
	ok('LogWatch selects and redirect stay on LogWatch (no Select One / no Home)');
} else {
	bad('LogWatch selects and redirect stay on LogWatch (no Select One / no Home)');
}
$coreUpd = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
if (str_contains($coreUpd, "class === 'logwatch'")
	&& str_contains($coreUpd, 'frm_logwatch_c_')
	&& str_contains($coreUpd, 'lw_format')) {
	ok('do_update sanitizes LogWatch --Select One-- before validation');
} else {
	bad('do_update sanitizes LogWatch --Select One-- before validation');
}
if (preg_match('/function showRawPrint.*?\{(.*?)\}/s', $lwSrc, $m)
	&& !str_contains($m[1], 'echo')
	&& !str_contains($m[1], 'print')) {
	ok('LogWatch showRawPrint has no status wall of text');
} else {
	bad('LogWatch showRawPrint has no status wall of text');
}

$def = (string) file_get_contents($doc . '/lib/html/definecore.php');
$inc = (string) file_get_contents($doc . '/lib/html/include.php');
$ps = (string) file_get_contents($doc . '/driver/pserver/pserverlib.php');
$menu = (string) file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
$remote = (string) file_get_contents($doc . '/lib/html/remotelib.php');
if (str_contains($def, "['logwatch']")
	&& str_contains($ps, 'a=show&o=logwatch')
	&& str_contains($menu, "class]=logwatch")
	&& is_file($doc . '/theme/icon/collage/logwatch_show.gif')) {
	ok('LogWatch is registered (definecore, Security tile, menu, icon)');
} else {
	bad('LogWatch is registered (definecore, Security tile, menu, icon)');
}
// Update / Email last report go through rl_exec_get(array('KloxoLogwatch', …)).
// The backend must load that class (include.php and/or gl_class_array).
if (str_contains($inc, 'kloxo_logwatch.php')
	&& str_contains($def, "['kloxologwatch']")
	&& str_contains($def, 'kloxo_logwatch.php')
	&& str_contains($lwSrc, "array('KloxoLogwatch', \$method)")
	&& str_contains($remote, 'gl_class_array')) {
	ok('KloxoLogwatch is loadable for kloxo-backend rl_exec (Update / Email last report)');
} else {
	bad('KloxoLogwatch is loadable for kloxo-backend rl_exec (Update / Email last report)');
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
