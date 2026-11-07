<?php

declare(strict_types=1);

// init-panel-db runs on include, so lift migrateServermailDriver() out of the source and test it alone.
$src = (string) file_get_contents(__DIR__ . '/../../kloxo/bin/install/init-panel-db');
if (!preg_match('/    public static function migrateServermailDriver\(.*?\n    }\n/s', $src, $m)) {
	fwrite(STDERR, "FAIL migrateServermailDriver not found\n");
	exit(1);
}
eval('final class MigrateProbe {' . $m[0] . '}');

$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail): void {
	echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
	$fail += $ok ? 0 : 1;
};

$old = 'a:2:{s:13:"pg_servermail";s:9:"__v_mmail";s:6:"pg_web";s:7:"__v_web";}';
$new = MigrateProbe::migrateServermailDriver($old);
$check($new === 'a:2:{s:13:"pg_servermail";s:8:"__v_smtp";s:6:"pg_web";s:7:"__v_web";}', 'old blob: pg_servermail follows pg_smtp');
$check(is_array(unserialize((string) $new)) && unserialize((string) $new)['pg_servermail'] === '__v_smtp', 'migrated blob still unserializes');
$check(MigrateProbe::migrateServermailDriver((string) $new) === null, 'already migrated: nothing to change');
$check(MigrateProbe::migrateServermailDriver('a:0:{}') === null, 'blob without pg_servermail: nothing to change');
$common = (string) file_get_contents(__DIR__ . '/../../kloxo/file/driver/common.inc');
$check(str_contains($common, "\$driver['servermail'] = '__v_smtp';"), 'fresh installs seed pg_servermail as __v_smtp');

exit($fail === 0 ? 0 : 1);
