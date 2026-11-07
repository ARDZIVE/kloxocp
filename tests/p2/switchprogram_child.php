<?php
/**
 * Child of test_switchprogram.php. Runs inside a COPY of the kloxo tree
 * (KLOXO_ROOT) with fake apt-get/systemctl first in PATH. Prints one JSON
 * object per scenario. Never run against the real tree.
 */
declare(strict_types=1);

$root = (string) getenv('KLOXO_ROOT');
if ($root === '' || !is_dir($root . '/httpdocs') || strpos($root, 'kloxo-switchprogram-') === false) {
	fwrite(STDERR, "refusing to run outside the test copy\n");
	exit(2);
}
$doc = $root . '/httpdocs';
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
define('KLOXO_APT_TEST_BIN', (string) getenv('KLOXO_APT_TEST_BIN'));
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
include_once $doc . '/lib/html/include.php';
global $gbl, $sgbl, $login, $ghtml;

$base = array('web' => 'nginx', 'webcache' => 'none', 'dns' => 'bind', 'spam' => 'none', 'pop3' => 'dovecot', 'smtp' => 'exim');
@mkdir('../etc/slavedb', 0755, true);

function sp_make(array $cur): pserver
{
	slave_save_db('driver', $cur);
	$ps = new pserver(null, 'localhost', 'localhost');
	$ps->nname = 'localhost';
	$ps->syncserver = 'localhost';
	$ps->dbaction = 'screwed'; // no panel DB here: skip was()
	foreach ($cur as $c => $v) {
		$k = $c . '_driver';
		$ps->$k = $v;
	}

	return $ps;
}

function sp_params(array $cur, array $over = array()): array
{
	$p = array();
	foreach ($cur as $c => $v) {
		$p[$c . '_driver'] = $v;
	}
	$p['use_pagespeed'] = 'off';

	return array_merge($p, $over);
}

function sp_slave(): array
{
	$r = lfile_get_unserialize('../etc/slavedb/driver');

	return (is_object($r) && is_array($r->data)) ? $r->data : array();
}

function sp_run(string $name, array $cur, array $over): void
{
	$err = '';
	try {
		sp_make($cur)->updateSwitchProgram(sp_params($cur, $over));
	} catch (Throwable $e) {
		$err = get_class($e) . ': ' . $e->getMessage();
	}
	echo json_encode(array('scenario' => $name, 'error' => $err, 'slave' => sp_slave())), "\n";
}

$scenario = (string) ($argv[1] ?? '');
switch ($scenario) {
	case 'form':
		$ps = sp_make($base);
		$v = $ps->updateform('switchprogram', array());
		echo json_encode(array('scenario' => 'form', 'vlist' => $v)), "\n";
		break;
	case 'same':
		sp_run('same', $base, array());
		break;
	case 'smtp':
		sp_run('smtp', $base, array('smtp_driver' => 'postfix'));
		break;
	case 'pop3':
		sp_run('pop3', array_merge($base, array('pop3' => 'none')), array('pop3_driver' => 'dovecot'));
		break;
	case 'spam_spamassassin':
		sp_run('spam_spamassassin', $base, array('spam_driver' => 'spamassassin'));
		break;
	case 'dns':
		sp_run('dns', $base, array('dns_driver' => 'pdns'));
		break;
	case 'cache_nginx':
		sp_run('cache_nginx', $base, array('webcache_driver' => 'nginx'));
		break;
	case 'bad':
		sp_run('bad', $base, array('smtp_driver' => '../evil'));
		break;
	case 'classes':
		// every offered program has a driver class with install + uninstall
		$ps = sp_make($base);
		$missing = array();
		foreach ($ps->switchProgramChoices() as $class => $list) {
			foreach ($list as $name) {
				$c = kloxo_driver_class($class, $name);
				if ($c === null || !class_exists($c)
					|| !method_exists($c, 'installMe') || !method_exists($c, 'uninstallMe')) {
					$missing[] = $class . '/' . $name;
				}
			}
		}
		echo json_encode(array('scenario' => 'classes', 'missing' => $missing)), "\n";
		break;
	default:
		fwrite(STDERR, "unknown scenario\n");
		exit(2);
}
