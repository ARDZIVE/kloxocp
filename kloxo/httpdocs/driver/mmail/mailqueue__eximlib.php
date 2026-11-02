<?php
declare(strict_types=1);

class mailqueue__exim extends lxDriverClass
{
	static function eximBin()
	{
		foreach (array('/usr/sbin/exim4', '/usr/sbin/exim', '/usr/bin/exim4', '/usr/bin/exim') as $p) {
			if (is_executable($p)) {
				return $p;
			}
		}

		return 'exim4';
	}

	static function QueueFlush()
	{
		lxshell_return(self::eximBin(), '-qff');
	}

	static function QueueDelete($list)
	{
		if (!is_array($list)) {
			return;
		}

		$bin = self::eximBin();
		foreach ($list as $id) {
			$id = (string) $id;
			if ($id === '' || !preg_match('/^[A-Za-z0-9-]+$/', $id)) {
				continue;
			}
			lxshell_return($bin, '-Mrm', $id);
		}
	}

	static function readSingleMail($name)
	{
		$name = (string) $name;
		$ret = array('message' => '', 'log' => null);
		if ($name === '' || !preg_match('/^[A-Za-z0-9-]+$/', $name)) {
			return $ret;
		}

		$out = lxshell_output(self::eximBin(), '-Mvc', $name);
		$ret['message'] = is_string($out) ? $out : '';

		return $ret;
	}

	static function readMailqueue()
	{
		$text = lxshell_output(self::eximBin(), '-bp');
		if (!is_string($text) || trim($text) === '') {
			return array();
		}

		return self::parseTextQueue($text);
	}

	static function parseTextQueue($text)
	{
		$ret = array();
		$cur = null;
		foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
			if (preg_match('/^\s*(\S+)\s+(\S+)\s+([0-9A-Za-z-]+)\s+<(.*)>/', $line, $m)) {
				if ($cur !== null) {
					$ret[] = $cur;
				}
				$cur = array(
					'nname' => $m[3],
					'type' => (strpos($line, 'frozen') !== false) ? 'local' : 'remote',
					'from' => $m[4],
					'to' => '',
					'subject' => '',
					'date' => $m[1],
					'size' => $m[2],
				);
				continue;
			}
			if ($cur !== null && preg_match('/^\s+(?:D\s+)?(\S+@\S+)/', $line, $m)) {
				$cur['to'] = $cur['to'] === '' ? $m[1] : ($cur['to'] . ', ' . $m[1]);
			}
		}
		if ($cur !== null) {
			$ret[] = $cur;
		}

		return $ret;
	}
}
