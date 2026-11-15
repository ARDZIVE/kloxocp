<?php
declare(strict_types=1);

/**
 * Subdomain/domain cron pins bare php to Web Features php_selected.
 * php-cli only; no live crontab.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/kloxo/httpdocs/lib/php/kloxo_phpver.php';
require_once $root . '/kloxo/httpdocs/lib/php/kloxo_phpfpm.php';

$pass = 0;
$fail = 0;
$ok = static function (string $m) use (&$pass): void {
	$pass++;
	fwrite(STDOUT, "  ok   $m\n");
};
$bad = static function (string $m, string $extra = '') use (&$fail): void {
	$fail++;
	fwrite(STDOUT, "  FAIL $m\n");
	if ($extra !== '') {
		fwrite(STDOUT, '       ' . str_replace("\n", "\n       ", $extra) . "\n");
	}
};

$tmp = sys_get_temp_dir() . '/kloxo-cron-php-' . getmypid();
@mkdir($tmp . '/etc/php/8.2/fpm/pool.d', 0755, true);
@mkdir($tmp . '/etc/php/8.4/fpm/pool.d', 0755, true);
file_put_contents($tmp . '/etc/php/8.2/fpm/pool.d/kloxo-mesrob.com.conf', "user = mesrob\n");
file_put_contents($tmp . '/etc/php/8.4/fpm/pool.d/kloxo-blog.mesrob.com.conf', "user = mesrob\n");

KloxoPhpfpm::$fsPrefix = $tmp;

echo "== domainFromCronParent\n";
if (KloxoPhpfpm::domainFromCronParent('web-blog.mesrob.com') === 'blog.mesrob.com') {
	$ok('web-FQDN → FQDN');
} else {
	$bad('web-FQDN → FQDN', KloxoPhpfpm::domainFromCronParent('web-blog.mesrob.com'));
}
if (KloxoPhpfpm::domainFromCronParent('domain-mesrob.com') === 'mesrob.com') {
	$ok('domain-FQDN → FQDN');
} else {
	$bad('domain-FQDN → FQDN');
}
if (KloxoPhpfpm::domainFromCronParent('subdomain-blog.mesrob.com') === 'blog.mesrob.com') {
	$ok('subdomain-FQDN → FQDN');
} else {
	$bad('subdomain-FQDN → FQDN');
}
if (KloxoPhpfpm::domainFromCronParent('client-mesrob') === '') {
	$ok('client parent has no domain');
} else {
	$bad('client parent has no domain');
}

echo "== phpIdForDomainCron\n";
if (KloxoPhpfpm::phpIdForDomainCron('blog.mesrob.com', 'php8.4') === 'php8.4') {
	$ok('subdomain php_selected php8.4 wins');
} else {
	$bad('subdomain php_selected php8.4 wins', KloxoPhpfpm::phpIdForDomainCron('blog.mesrob.com', 'php8.4'));
}
if (KloxoPhpfpm::phpIdForDomainCron('blog.mesrob.com', '') === 'php8.4') {
	$ok('empty selected uses exact subdomain pool series');
} else {
	$bad('empty selected uses exact subdomain pool series', KloxoPhpfpm::phpIdForDomainCron('blog.mesrob.com', ''));
}
if (KloxoPhpfpm::phpIdForDomainCron('mesrob.com', 'php8.2') === 'php8.2') {
	$ok('main domain php_selected php8.2');
} else {
	$bad('main domain php_selected php8.2', KloxoPhpfpm::phpIdForDomainCron('mesrob.com', 'php8.2'));
}
if (KloxoPhpfpm::phpIdForDomainCron('mesrob.com', '') === 'php8.2') {
	$ok('empty selected uses exact main-domain pool series');
} else {
	$bad('empty selected uses exact main-domain pool series', KloxoPhpfpm::phpIdForDomainCron('mesrob.com', ''));
}
if (KloxoPhpfpm::normalizePhpSelected('--PHP Used-- (use \'php8.3\')') !== ''
	&& str_starts_with(KloxoPhpfpm::normalizePhpSelected('php8.4'), 'php')) {
	$ok('normalizePhpSelected handles PHP Used and php8.4');
} else {
	$bad('normalizePhpSelected handles PHP Used and php8.4');
}

echo "== rewrite with selected CLIs\n";
$rewSub = KloxoPhpfpm::rewriteCronPhp('php /home/mesrob/blog/cron.php', '/usr/bin/php8.4');
$rewMain = KloxoPhpfpm::rewriteCronPhp('php /home/mesrob/main/cron.php', '/usr/bin/php8.2');
if ($rewSub === '/usr/bin/php8.4 /home/mesrob/blog/cron.php'
	&& $rewMain === '/usr/bin/php8.2 /home/mesrob/main/cron.php') {
	$ok('sibling sites keep distinct cron PHP versions');
} else {
	$bad('sibling sites keep distinct cron PHP versions', "$rewSub | $rewMain");
}

echo "== driver source\n";
$drv = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/cron__linuxlib.php');
$lib = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/cronlib.php');
$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
if (str_contains($drv, 'cliForDomainCron') && str_contains($drv, 'pinCronPhp($command, $username, $domain')) {
	$ok('cron driver pins per-domain CLI');
} else {
	$bad('cron driver pins per-domain CLI');
}
if (str_contains($lib, '__var_web_php') && str_contains($lib, '__kloxo_php_selected')) {
	$ok('createExtraVariables maps web php_selected onto cron rows');
} else {
	$bad('createExtraVariables maps web php_selected onto cron rows');
}
if (str_contains($web, 'rewriteCrontabForParent')) {
	$ok('Web Features postUpdate refreshes crontab');
} else {
	$bad('Web Features postUpdate refreshes crontab');
}

KloxoPhpfpm::$fsPrefix = '';
passthru('rm -rf ' . escapeshellarg($tmp));

fwrite(STDOUT, "\n$pass passed, $fail failed\n");
exit($fail > 0 ? 1 : 0);
