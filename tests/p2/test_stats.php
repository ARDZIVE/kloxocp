<?php
/**
 * Modern visitor stats: richer parse, detail tables, dashboard HTML.
 * Temp dirs + fake logs only. Live nginx/timer UNVERIFIED.
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
require_once $repo . '/kloxo/httpdocs/lib/php/storage/bootstrap.php';
require_once $repo . '/kloxo/httpdocs/lib/php/kloxo_stats.php';

$tmp = sys_get_temp_dir() . '/kloxo-stats-ui-' . getmypid();
@mkdir($tmp, 0700, true);
$data = $tmp . '/var';
$log = $tmp . '/log';
@mkdir($data, 0700, true);
@mkdir($log . '/domains/shop.example', 0700, true);
putenv('KLOXO_VAR_LIB=' . $data);
putenv('KLOXO_LOG_DIR=' . $log);
putenv('KLOXO_ROOT=' . $repo . '/kloxo');
KloxoStore::resetPool();

KloxoMigrate::apply(KloxoStore::panel(true), KloxoMigrate::panelDir($repo . '/kloxo'));
KloxoMigrate::apply(KloxoStore::stats(true), KloxoMigrate::statsDir($repo . '/kloxo'));
check(KloxoMigrate::userVersion(KloxoStore::stats()) === 2, 'stats schema at v2');

$db = new KloxoPanelDb(KloxoStore::panel());
$uid = $db->addUser('bob', 'hash', 'client');
$domId = $db->addDomain($uid, 'shop.example', $tmp . '/doc');
@mkdir($tmp . '/doc', 0700, true);
file_put_contents($tmp . '/doc/index.php', "ok\n");

$line = implode("\n", [
	'203.0.113.9 - - [13/Nov/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 500 "https://google.com/q" "Mozilla/5.0 Chrome/120.0"',
	'203.0.113.9 - - [13/Nov/2026:10:00:01 +0000] "GET /pricing HTTP/1.1" 200 800 "https://google.com/q" "Mozilla/5.0 Chrome/120.0"',
	'198.51.100.2 - - [13/Nov/2026:10:00:02 +0000] "GET /robots.txt HTTP/1.1" 404 20 "-" "Googlebot/2.1"',
	'203.0.113.10 - - [13/Nov/2026:10:00:03 +0000] "GET /pricing?x=1 HTTP/1.1" 200 800 "https://news.ycombinator.com/" "Mozilla/5.0 Firefox/121.0"',
	'',
]);
file_put_contents($log . '/domains/shop.example/access.log', $line);

$parsed = KloxoStatsAgg::parseCombined(
	'203.0.113.9 - - [13/Nov/2026:10:00:00 +0000] "GET /pricing?x=1 HTTP/1.1" 200 42 "https://Google.com/a" "Mozilla/5.0 Chrome/120.0"'
);
check(is_array($parsed) && $parsed['day'] === '2026-11-13' && $parsed['bytes'] === 42
	&& $parsed['path'] === '/pricing' && $parsed['status'] === 200
	&& $parsed['ref_host'] === 'google.com' && $parsed['ua_family'] === 'chrome'
	&& $parsed['visitor'] !== '',
	'parseCombined extracts path/status/ref/ua/visitor', json_encode($parsed) ?: '');

check(KloxoStatsAgg::uaFamily('Googlebot/2.1') === 'bot', 'Googlebot → bot');
check(KloxoStatsAgg::uaFamily('Mozilla/5.0 Firefox/121.0') === 'firefox', 'Firefox family');

$now = strtotime('2026-11-13 12:00:00 UTC') ?: time();
$agg = new KloxoStatsAgg(KloxoStore::stats(), KloxoStore::panel(), $log . '/domains', $now);
$r = $agg->run();
check($r['requests'] === 4 && $r['bytes'] === 2120, 'ingest 4 lines', json_encode($r) ?: '');
$r2 = $agg->run();
check($r2['requests'] === 0, 'second ingest is a no-op');

$dash = KloxoStats::dashboard('shop.example', 30);
check($dash['totals']['requests'] === 4 && $dash['totals']['bytes'] === 2120,
	'dashboard totals', json_encode($dash['totals']) ?: '');
check($dash['totals']['visitors'] >= 2, 'at least 2 hashed visitors', (string) $dash['totals']['visitors']);
check(count($dash['paths']) >= 2, 'top paths populated');
$refHosts = array_column($dash['referrers'], 'host');
check(in_array('google.com', $refHosts, true),
	'referrers include google.com', json_encode($dash['referrers']) ?: '');
$families = array_column($dash['ua'], 'family');
check(in_array('chrome', $families, true) && in_array('bot', $families, true),
	'UA families include chrome and bot', json_encode($dash['ua']) ?: '');
$codes = array_column($dash['status'], 'status');
check(in_array(200, $codes, true) && in_array(404, $codes, true),
	'status codes include 200 and 404', json_encode($dash['status']) ?: '');

$html = KloxoStats::renderHtml($dash, 'display.php?frm_action=show&frm_subaction=stats');
check(str_contains($html, 'Visitor Stats') && str_contains($html, 'shop.example')
	&& str_contains($html, 'Top pages') && str_contains($html, '<svg'),
	'dashboard HTML has title, tables and chart');
check(str_contains($html, 'frm_stats_days=7') && str_contains($html, 'kloxo-stats-range'),
	'range links present');
check(str_contains($html, 'width:910px') && str_contains($html, 'margin:10px auto'),
	'stats zone uses panel 910px centered column');
check(str_contains($html, 'Tahoma,Verdana,Arial,Helvetica,sans-serif')
	&& !str_contains($html, 'Georgia'),
	'stats uses panel Tahoma font stack');

// Domains list stub points in-panel
$domSrc = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/domain/domainlib.php');
check(str_contains($domSrc, 'n=web&a=show&sa=stats'), 'domain stats icon opens panel stats');
$webSrc = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/web/weblib.php');
check(str_contains($webSrc, 'printVisitorStats') && str_contains($webSrc, "subaction === 'stats'"),
	'web showRawPrint handles sa=stats');
$ngx = (string) file_get_contents($repo . '/kloxo/file/nginx/tpl/domains.conf.tpl');
check(str_contains($ngx, '/var/log/kloxo/domains/') && str_contains($ngx, 'access.log'),
	'nginx template writes modern access.log path');
check(is_file($repo . '/kloxo/init/kloxo-stats.timer')
	&& is_file($repo . '/kloxo/init/kloxo-stats.service'),
	'systemd timer + service shipped');

KloxoStore::resetPool();
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
