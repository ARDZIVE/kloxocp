<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
if (!class_exists('PhpModule', false)) {
	include_once __DIR__ . '/phpmodulelib.php';
}

class PhpModule__linux extends lxDriverClass
{
	static function getListDetail($syncserver)
	{
		$t = array();
		if (class_exists('KloxoPhpver', false)) {
			$t = array_merge($t, KloxoPhpver::distroModuleRows((string)$syncserver));
		}

		$files = glob("/opt/php*m/etc/php.d/*ini", GLOB_MARK);
		foreach ((array)$files as $fv) {
			if (!is_string($fv) || $fv === '') {
				continue;
			}
			$row = self::legacyIniRow($fv, (string)$syncserver, 'multiple-php');
			if ($row) {
				$t[] = $row;
			}
		}

		$active = '../init/kloxo_php_active';
		if (is_file($active)) {
			$kloxophp = trim((string)file_get_contents($active));
			if ($kloxophp !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $kloxophp)) {
				$files = glob("/opt/{$kloxophp}/etc/php.d/*ini", GLOB_MARK);
				foreach ((array)$files as $fv) {
					if (!is_string($fv) || $fv === '') {
						continue;
					}
					$row = self::legacyIniRow($fv, (string)$syncserver, 'kloxo-php');
					if ($row) {
						$t[] = $row;
					}
				}
			}
		}

		$files = glob("/etc/php.d/*ini", GLOB_MARK);
		foreach ((array)$files as $fv) {
			if (!is_string($fv) || $fv === '') {
				continue;
			}
			$row = self::legacyIniRow($fv, (string)$syncserver, 'php-branch', 'php');
			if ($row) {
				$t[] = $row;
			}
		}

		return $t;
	}

	static function legacyIniRow($fv, $syncserver, $type, $target = null)
	{
		$b = basename($fv);
		$n = preg_replace('/(_used|_unused|\.ini|\.nonini)$/', '', $b);
		if ($n === '' || $n === null) {
			return null;
		}
		if ($target === null) {
			$p = preg_replace('#/opt/|/etc/php\.d/' . preg_quote($b, '#') . '#', '', $fv);
			$target = $p;
		}
		$status = (strpos($b, '.ini') !== false && strpos($b, '.nonini') === false) ? 'on' : 'off';

		return array(
			'nname' => "{$target}-{$n}___{$syncserver}",
			'modulename' => $n,
			'target' => $target,
			'type' => $type,
			'status' => $status,
			'fullfile' => $fv,
			'syncserver' => $syncserver,
		);
	}

	/**
	 * Enable / disable / restart a PHP module as root (via kloxo-backend).
	 *
	 * @return array{ok:bool,error?:string}
	 */
	static function applyAction($subaction, $fullfile, $status)
	{
		$sub = is_string($subaction) ? strtolower($subaction) : '';
		$file = is_string($fullfile) ? $fullfile : '';
		$st = is_string($status) ? $status : '';

		if ($sub === 'restart') {
			if (function_exists('createRestartFile')) {
				createRestartFile('restart-php-fpm');
				createRestartFile('kloxo-php');
			}

			return array('ok' => true);
		}

		if ($sub !== 'enable' && $sub !== 'disable') {
			return array('ok' => false, 'error' => 'unknown action');
		}

		$enable = ($sub === 'enable');
		if ($enable && $st === 'on') {
			return array('ok' => true);
		}
		if (!$enable && $st === 'off') {
			return array('ok' => true);
		}

		if (class_exists('PhpModule', false) && is_callable(array('PhpModule', 'toggleDistroMod'))) {
			if (PhpModule::toggleDistroMod($file, $enable)) {
				return array('ok' => true);
			}
		}

		// Legacy CentOS-style rename under /opt/*/etc/php.d or /etc/php.d.
		if ($file === '' || str_contains($file, '..') || !is_file($file)) {
			return array('ok' => false, 'error' => 'module file not found');
		}
		if (!preg_match('#^/(opt/|etc/php\.d/)#', $file)) {
			return array('ok' => false, 'error' => 'refused unsafe module path');
		}

		if ($enable) {
			if (str_contains($file, '_unused.nonini')) {
				$t = str_replace('_unused.nonini', '.ini', $file);
			} else {
				$t = str_replace('.nonini', '_used.ini', $file);
			}
		} else {
			if (str_contains($file, '_used.ini')) {
				$t = str_replace('_used.ini', '.nonini', $file);
			} else {
				$t = str_replace('.ini', '_unused.nonini', $file);
			}
		}
		if ($t === $file || str_contains($t, '..')) {
			return array('ok' => false, 'error' => 'could not map module file');
		}
		if (!@rename($file, $t)) {
			return array('ok' => false, 'error' => 'could not rename module file');
		}

		return array('ok' => true);
	}
}
