<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_component.php';

class Component__deb extends lxDriverClass
{
	static function getDetailedInfo($name)
	{
		if (class_exists('KloxoComponent', false)) {
			return KloxoComponent::detailedInfo(is_string($name) ? $name : '');
		}

		return '';
	}

	static function getVersion($list, $name)
	{
		$ret = array();
		if (!is_array($list)) {
			return '';
		}

		foreach ($list as $v) {
			if (csb($v, $name) || csa($v, " $name ")) {
				$ret[] = $v;
			}
		}

		return implode(", ", $ret);
	}

	static function getListVersion($syncserver, $list)
	{
		if (!class_exists('KloxoComponent', false)) {
			return array();
		}

		$sync = is_string($syncserver) && $syncserver !== '' ? $syncserver : 'localhost';

		return KloxoComponent::listVersion($sync);
	}
}
