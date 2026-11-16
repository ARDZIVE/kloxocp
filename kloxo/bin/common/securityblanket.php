<?php
/**
 * Legacy async "security blanket" worker (security T1).
 *
 * Unused by the panel (securityBlanketExec has no callers). Kept hardened so a
 * leftover job file cannot drive bare unserialize / unrestricted call_user_func
 * / string SQL. Job path must be under {program_root}/pid/ (security T2).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

$httpdocs = dirname(__DIR__, 2) . '/httpdocs';
$include = $httpdocs . '/lib/html/include.php';
if (!is_file($include)) {
	fwrite(STDERR, "securityblanket: missing {$include}\n");
	exit(1);
}
if (is_dir($httpdocs)) {
	@chdir($httpdocs);
}
include_once $include;

security_blanket_main();

/**
 * Empty allow-list: this worker must not run arbitrary callables (T1).
 *
 * @return list<string>
 */
function kloxo_securityblanket_allowed_functions(): array
{
	return [];
}

/**
 * @return list<array{0:string,1:string}>
 */
function kloxo_securityblanket_allowed_methods(): array
{
	return [];
}

/**
 * True when $func is on the (currently empty) allow-list.
 *
 * @param mixed $func
 */
function kloxo_securityblanket_callable_allowed($func): bool
{
	if (is_string($func)) {
		return in_array($func, kloxo_securityblanket_allowed_functions(), true)
			&& function_exists($func);
	}
	if (is_array($func) && count($func) === 2 && is_string($func[0]) && is_string($func[1])) {
		foreach (kloxo_securityblanket_allowed_methods() as $pair) {
			if ($pair[0] === $func[0] && $pair[1] === $func[1]) {
				return class_exists($func[0]) && method_exists($func[0], $func[1]);
			}
		}
	}

	return false;
}

/**
 * SQL identifier: letters, digits, underscore only.
 */
function kloxo_securityblanket_ident(string $name): ?string
{
	if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
		return null;
	}

	return $name;
}

function security_blanket_main(): void
{
	global $argv;

	$path = isset($argv[1]) ? (string) $argv[1] : '';
	if ($path === '' || !is_file($path)) {
		exit(1);
	}

	$real = realpath($path);
	$root = dirname(__DIR__, 2);
	$pidDir = realpath($root . '/pid');
	if ($real === false || $pidDir === false
		|| !str_starts_with($real, $pidDir . DIRECTORY_SEPARATOR)) {
		@unlink($path);
		exit(1);
	}

	$raw = @file_get_contents($real);
	@unlink($real);
	if ($raw === false || $raw === '') {
		exit(1);
	}

	if (function_exists('kloxo_unserialize_deny_gadgets')) {
		$rem = kloxo_unserialize_deny_gadgets($raw, ['allowed_classes' => ['Remote', 'stdClass']]);
	} else {
		$rem = @unserialize($raw, ['allowed_classes' => ['Remote', 'stdClass']]);
	}
	if (!is_object($rem)) {
		exit(1);
	}

	$func = $rem->func ?? null;
	$arglist = isset($rem->arglist) && is_array($rem->arglist) ? $rem->arglist : [];
	if (!kloxo_securityblanket_callable_allowed($func) || !is_callable($func)) {
		exit(1);
	}
	call_user_func_array($func, $arglist);

	$table = isset($rem->table) ? kloxo_securityblanket_ident((string) $rem->table) : null;
	$nname = isset($rem->nname) ? (string) $rem->nname : '';
	$flag = isset($rem->flagvariable) ? kloxo_securityblanket_ident((string) $rem->flagvariable) : null;
	if ($table === null || $flag === null || $nname === '' || !class_exists('Sqlite')) {
		return;
	}

	$sq = new Sqlite(null, $table);
	$esc = str_replace("'", "''", $nname);
	$res = $sq->getRowsWhere("nname = '{$esc}'", [$flag]);
	if (!is_array($res) || !isset($res[0][$flag]) || $res[0][$flag] !== 'doing') {
		return;
	}

	$msg = str_replace("'", "''", 'Program Got aborted in the midst. Please try again.');
	// Identifiers validated above; values single-quote escaped (no raw user SQL).
	$sq->rawQuery("update {$table} set {$flag} = '{$msg}' where nname = '{$esc}'");
}
