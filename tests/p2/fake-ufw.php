#!/usr/bin/env php
<?php
/**
 * Minimal fake `ufw` for tests: keeps numbered rules in a JSON state file
 * (FAKE_UFW_STATE, default next to this script). Mirrors the real CLI forms
 * KloxoUfw emits. Dual-stack: a rule without an IPv4 source gets a (v6) twin.
 * Adding port 9999 always fails (to test rollback).
 */
declare(strict_types=1);

$state = getenv('FAKE_UFW_STATE') ?: (__DIR__ . '/fake-ufw.state.json');
$rules = is_file($state) ? (json_decode((string) file_get_contents($state), true) ?: []) : [];
$args = array_slice($argv, 1);

function save(string $state, array $rules): void
{
	file_put_contents($state, json_encode(array_values($rules)));
}

if ($args === ['status', 'verbose']) {
	echo "Status: active\nLogging: on (low)\nDefault: deny (incoming), allow (outgoing), disabled (routed)\nNew profiles: skip\n";
	exit(0);
}
if ($args === ['status', 'numbered']) {
	echo "Status: active\n\n     To                         Action      From\n     --                         ------      ----\n";
	foreach (array_values($rules) as $i => $r) {
		$line = sprintf('[%2d] %-26s %-11s %s', $i + 1, $r['to'], $r['action'], $r['from']);
		if ($r['comment'] !== '') {
			$line .= '   # ' . $r['comment'];
		}
		echo $line . "\n";
	}
	exit(0);
}
if (count($args) === 3 && $args[0] === '--force' && $args[1] === 'delete') {
	$n = (int) $args[2];
	if ($n < 1 || $n > count($rules)) {
		fwrite(STDERR, "ERROR: Could not find rule '$n'\n");
		exit(1);
	}
	array_splice($rules, $n - 1, 1);
	save($state, $rules);
	exit(0);
}
if (in_array($args[0] ?? '', ['allow', 'deny', 'reject'], true)) {
	$act = strtoupper($args[0]) . ' IN';
	$rest = array_slice($args, 1);
	$comment = '';
	$ci = array_search('comment', $rest, true);
	if ($ci !== false) {
		$comment = (string) ($rest[$ci + 1] ?? '');
		$rest = array_slice($rest, 0, (int) $ci);
	}
	$from = 'Anywhere';
	if ($rest[0] === 'proto' && ($rest[1] ?? '') === 'icmp') {
		$to = 'icmp';
		if (($rest[2] ?? '') === 'from') {
			$from = $rest[3];
		}
	} elseif ($rest[0] === 'from') {
		$from = $rest[1];
		$port = $rest[5];
		$to = $port . '/' . $rest[7];
	} else {
		$to = $rest[0];
		$port = explode('/', $to)[0];
	}
	if (str_starts_with($to, '9999')) {
		fwrite(STDERR, "ERROR: simulated failure\n");
		exit(1);
	}
	$v6src = str_contains($from, ':');
	$v4src = $from !== 'Anywhere' && !$v6src;
	$new = [];
	if (!$v6src) {
		$new[] = ['to' => $to, 'action' => $act, 'from' => $from, 'comment' => $comment];
	}
	if (!$v4src) {
		$new[] = [
			'to' => $to . ' (v6)',
			'action' => $act,
			'from' => ($from === 'Anywhere' ? 'Anywhere (v6)' : $from),
			'comment' => $comment,
		];
	}
	foreach ($new as $nr) {
		$dup = false;
		foreach ($rules as $r) {
			if ($r === $nr) {
				$dup = true;
			}
		}
		if (!$dup) {
			$rules[] = $nr;
		}
	}
	save($state, $rules);
	echo "Rule added\n";
	exit(0);
}
fwrite(STDERR, "fake-ufw: unsupported: " . implode(' ', $args) . "\n");
exit(2);
