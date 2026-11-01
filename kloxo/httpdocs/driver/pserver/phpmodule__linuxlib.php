<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';

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
		);
	}
}
