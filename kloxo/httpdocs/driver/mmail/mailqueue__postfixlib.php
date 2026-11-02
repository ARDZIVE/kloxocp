<?php

class mailqueue__postfix extends lxDriverClass
{
	static function postqueueBin()
	{
		foreach (array('/usr/sbin/postqueue', '/usr/bin/postqueue') as $p) {
			if (is_executable($p)) {
				return $p;
			}
		}

		return 'postqueue';
	}

	static function postsuperBin()
	{
		foreach (array('/usr/sbin/postsuper', '/usr/bin/postsuper') as $p) {
			if (is_executable($p)) {
				return $p;
			}
		}

		return 'postsuper';
	}

	static function postcatBin()
	{
		foreach (array('/usr/sbin/postcat', '/usr/bin/postcat') as $p) {
			if (is_executable($p)) {
				return $p;
			}
		}

		return 'postcat';
	}

	static function QueueFlush()
	{
		lxshell_return(self::postqueueBin(), '-f');
	}

	static function QueueDelete($list)
	{
		if (!is_array($list)) {
			return;
		}

		$bin = self::postsuperBin();
		foreach ($list as $id) {
			$id = (string) $id;
			if ($id === '' || !preg_match('/^[A-Za-z0-9]+$/', $id)) {
				continue;
			}
			lxshell_return($bin, '-d', $id);
		}
	}

	static function readSingleMail($name)
	{
		$name = (string) $name;
		$ret = array('message' => '', 'log' => null);
		if ($name === '' || !preg_match('/^[A-Za-z0-9]+$/', $name)) {
			return $ret;
		}

		$out = lxshell_output(self::postcatBin(), '-q', $name);
		$ret['message'] = is_string($out) ? $out : '';

		return $ret;
	}

	static function readMailqueue()
	{
		$json = lxshell_output(self::postqueueBin(), '-j');
		if (is_string($json) && trim($json) !== '') {
			$parsed = self::parseJsonQueue($json);
			if ($parsed !== null) {
				return $parsed;
			}
		}

		$text = lxshell_output(self::postqueueBin(), '-p');
		if (!is_string($text) || trim($text) === '') {
			return array();
		}

		return self::parseTextQueue($text);
	}

	static function parseJsonQueue($json)
	{
		$ret = array();
		foreach (preg_split("/\r\n|\n|\r/", $json) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$row = json_decode($line, true);
			if (!is_array($row) || !isset($row['queue_id'])) {
				return null;
			}
			$to = '';
			if (isset($row['recipients']) && is_array($row['recipients'])) {
				$addrs = array();
				foreach ($row['recipients'] as $r) {
					if (isset($r['address'])) {
						$addrs[] = $r['address'];
					}
				}
				$to = implode(', ', $addrs);
			}
			$ret[] = array(
				'nname' => (string) $row['queue_id'],
				'type' => (isset($row['queue_name']) && $row['queue_name'] === 'hold') ? 'local' : 'remote',
				'from' => isset($row['sender']) ? (string) $row['sender'] : '',
				'to' => $to,
				'subject' => '',
				'date' => isset($row['arrival_time']) ? date('c', (int) $row['arrival_time']) : '',
				'size' => isset($row['message_size']) ? (string) $row['message_size'] : '0',
			);
		}

		return $ret;
	}

	static function parseTextQueue($text)
	{
		$ret = array();
		$cur = null;
		foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
			if (preg_match('/^([A-F0-9]+)\s+(\d+)\s+(.+?)\s+(\S+)$/', $line, $m)) {
				if ($cur !== null) {
					$ret[] = $cur;
				}
				$cur = array(
					'nname' => $m[1],
					'type' => 'remote',
					'from' => $m[4],
					'to' => '',
					'subject' => '',
					'date' => $m[3],
					'size' => $m[2],
				);
				continue;
			}
			if ($cur !== null && preg_match('/^\s+(\S+@\S+)/', $line, $m)) {
				$cur['to'] = $cur['to'] === '' ? $m[1] : ($cur['to'] . ', ' . $m[1]);
			}
		}
		if ($cur !== null) {
			$ret[] = $cur;
		}

		return $ret;
	}
}
