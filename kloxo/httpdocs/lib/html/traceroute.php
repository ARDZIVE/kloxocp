<?php 

class traceroute extends lxclass
{

	static $__desc = array("n", "",  "traceroute");
	static $__desc_nname	 = array("n", "",  "");
	static $__desc_ip	 = array("n", "",  "IP");
	static $__desc_responsetimes	 = array("n", "",  "response_time");
	static $__acdesc_list = array("n", "",  "traceroute");

	function write() {}
	function get() {}

	function display($var)
	{
		if ($var === 'ip') {
			return self::formatHopIp(isset($this->ip) ? $this->ip : null);
		}

		if ($var === 'responsetimes') {
			$ip = self::formatHopIp(isset($this->ip) ? $this->ip : null);
			if ($ip === '*') {
				return '*';
			}

			return self::formatHopTime(isset($this->responsetimes) ? $this->responsetimes : null);
		}

		return parent::display($var);
	}

	/**
	 * Unreachable hops (traceroute "*", tracepath "no reply") show as "*".
	 *
	 * @param mixed $ip
	 */
	static function formatHopIp($ip)
	{
		if ($ip === null || $ip === false) {
			return '*';
		}
		$s = trim((string) $ip);
		if ($s === '' || $s === '*' || strcasecmp($s, 'no') === 0
			|| preg_match('/^no\s+reply$/i', $s) === 1) {
			return '*';
		}

		return $s;
	}

	/**
	 * Response time as three decimal places, or "*" when missing/unreachable.
	 *
	 * @param mixed $time
	 */
	static function formatHopTime($time)
	{
		if ($time === null || $time === false || $time === '' || $time === '*' || $time === -1
			|| (is_string($time) && strcasecmp(trim($time), 'no') === 0)) {
			return '*';
		}
		if (!is_numeric($time)) {
			return '*';
		}

		return number_format((float) $time, 3, '.', '');
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}

	static function createListNlist($parent, $view)
	{
		$nlist['ip'] = '10%';
		$nlist['responsetimes'] = '100%';

		return $nlist;
	}

	function isSelect() { return false; }

	function hasDriverClass()
	{
		return false;
	}

	static function perPage() { return 5000; }
	static function initThisListRule($parent, $class) { return null; }

	static function initThisList($parent, $class)
	{
		include_once dirname(__DIR__) . '/php/kloxo_traceroute.php';

		$host = class_exists('KloxoTraceroute', false)
			? KloxoTraceroute::clientHost()
			: '127.0.0.1';

		$_result = rl_exec_get(null, "localhost", array("traceroute", "exec_traceroute"), array($host));

		if (!is_array($_result) || count($_result) === 0) {
			return array();
		}

		try {
			$object = new Traceroute(null, null, '__name__');
			$hops = $object->Net_Traceroute_Result($_result, "linux");
		} catch (Throwable $e) {
			return array();
		}

		return is_array($hops) ? $hops : array();
	}

	static function exec_traceroute($host)
	{
		include_once dirname(__DIR__) . '/php/kloxo_traceroute.php';

		if (!class_exists('KloxoTraceroute', false)) {
			return array();
		}

		return KloxoTraceroute::run(is_string($host) ? $host : '');
	}

	function Net_Traceroute_Result($result, $sysname)
	{
		$this->_raw_data = is_array($result) ? $result : array();
		$this->_sysname  = $sysname;

		$this->_parseResultLinux();
		return $this->_hops;
	}

	function _initArgRelation()
	{
		$this->_argRelation = array(
			"linux" => array (
				"numeric"   => "-n",
				"ttl"       => "-m",
				"deadline"  => "-w"
			),
			"windows" => array (
				"numeric"   => "-d",
				"ttl"       => "-h",
				"deadline"  => "-w"
			)
		);
	}

	function _parseResultlinux()
	{
		$hops = array();
		$n = 0;
		$this->_target_ip = '';
		$this->_ttl = 0;

		foreach ((array) $this->_raw_data as $rawLine) {
			if (!is_string($rawLine)) {
				continue;
			}
			$line = trim($rawLine);
			if ($line === '') {
				continue;
			}

			if (stripos($line, 'traceroute to ') === 0) {
				if (preg_match('/\(([^)]+)\)/', $line, $tm)) {
					$this->_target_ip = $tm[1];
				}
				if (preg_match('/(\d+)\s+hops?\s+max/i', $line, $tm)) {
					$this->_ttl = (int) $tm[1];
				}
				continue;
			}

			if (stripos($line, 'tracepath') === 0
				|| strpos($line, 'pmtu') !== false
				|| strpos($line, '[LOCALHOST]') !== false) {
				continue;
			}

			// tracepath: "1: 1.2.3.4  0.456ms" / "2: no reply"
			if (preg_match('/^(\d+)\??:\s+(.+)$/', $line, $m) === 1) {
				$rest = trim($m[2]);
				$n++;
				if (preg_match('/^no\s+reply/i', $rest) === 1 || $rest === '*') {
					$hops[$n] = array(
						'ip' => '*',
						'responsetimes' => -1,
						'nname' => (string) $n,
					);
					continue;
				}
				if (preg_match('/^(\S+)\s+([\d.]+)\s*ms/i', $rest, $tm) === 1) {
					$hops[$n] = array(
						'ip' => $tm[1],
						'responsetimes' => (float) $tm[2],
						'nname' => (string) $n,
					);
					continue;
				}
				$hops[$n] = array(
					'ip' => '*',
					'responsetimes' => -1,
					'nname' => (string) $n,
				);
				continue;
			}

			// classic traceroute: "1  1.2.3.4  0.456 ms" / "2  *"
			if (preg_match('/^(\d+)\s+(.+)$/', $line, $m) !== 1) {
				continue;
			}
			$rest = trim($m[2]);
			$n++;
			if ($rest === '*' || preg_match('/^\*(\s|$)/', $rest) === 1
				|| preg_match('/^no\s+reply/i', $rest) === 1) {
				$hops[$n] = array(
					'ip' => '*',
					'responsetimes' => -1,
					'nname' => (string) $n,
				);
				continue;
			}

			$ip = '';
			$time = null;
			if (preg_match('/^(?:(\S+)\s+)?\(?([\d.a-fA-F:]+)\)?\s+([\d.]+)\s*ms/i', $rest, $tm) === 1) {
				$ip = $tm[2];
				$time = (float) $tm[3];
			} elseif (preg_match('/^([\d.a-fA-F:]+)\s+([\d.]+)\s*ms/i', $rest, $tm) === 1) {
				$ip = $tm[1];
				$time = (float) $tm[2];
			}

			if ($ip === '') {
				$hops[$n] = array(
					'ip' => '*',
					'responsetimes' => -1,
					'nname' => (string) $n,
				);
				continue;
			}

			$hops[$n] = array(
				'ip' => $ip,
				'responsetimes' => $time === null ? -1 : $time,
				'nname' => (string) $n,
			);
		}

		$this->_hops = $hops;
	}

	/**
	* Parses the output of Windows' traceroute command
	*
	* @see    _parseResult()
	* @access private
	*/
	function _parseResultwindows()
	{
		$raw_data_len = kcount($this->_raw_data);
		$dataRow = 0;

		while (empty($this->_raw_data[$dataRow]) && ($dataRow<$raw_data_len)) {
			$dataRow++;
		}

		$tempparts = explode(' ', $this->_raw_data[$dataRow]);
		$searchIdx = 0;

		while (($searchIdx < count($tempparts)) && (substr($tempparts[$searchIdx], 0, 1) != '[')) {
			$searchIdx++;
		}

		$this->_target_ip = trim($tempparts[$searchIdx], ' [],');

		while (($searchIdx < count($tempparts)) && ((int) $tempparts[$searchIdx] <= 0)) {
			$searchIdx++;
		}

		if ((int) $tempparts[$searchIdx] > 0) {
			$this->_ttl = (int) $tempparts[$searchIdx]; // TTL might be written in next line; e.g. on Windows 98
		} elseif (!empty($this->_raw_data[$dataRow+1])) {
			$dataRow++;
			$tempparts  = explode(' ', $this->_raw_data[$dataRow]);
			$searchIdx = 0;

			while (($searchIdx < count($tempparts)) && ((int) $tempparts[$searchIdx] <= 0)) {
				$searchIdx++;
			}

			if ((int) $tempparts[$searchIdx] > 0) {
				$this->_ttl       = (int) $tempparts[$searchIdx]; // TTL might be written in next line; e.g. on Windows 98
			}
		}

		while (!empty($this->_raw_data[$dataRow]) && ($dataRow<$raw_data_len)) {
			$dataRow++;
		}

		while (empty($this->_raw_data[$dataRow]) && ($dataRow<$raw_data_len)) {
			$dataRow++;
		}

		$hops=array();

		/* loop from second elment to the fifths last */
		while (($dataRow < $raw_data_len) && !empty($this->_raw_data[$dataRow])) {
			$hop=array();

			$responsetimes = array();

			for($timeidx = 0; $timeidx < 3; $timeidx++) {
				$temppart=trim(str_replace(' ms','',substr($this->_raw_data[$dataRow], 3+($timeidx*9), 9)));

				if ($temppart == '*') {
					$responsetimes[] = -1; // unreachable
				} else {
					$responsetimes[] = (float) str_replace('<', '', $temppart);
				}
			}

			$hop['responsetimes'] = $responsetimes;

			$machineparts = explode(' ', rtrim(substr($this->_raw_data[$dataRow], 32)));

			// if we can find a next hop it's name/ip will be here
			if (count($machineparts) == 1) {
				$hop['ip'] = trim($machineparts[0], ' ()[]');
			} elseif (count($machineparts) == 2) {
				$hop['machine'] = $machineparts[0];
				$hop['ip']      = trim($machineparts[1], ' ()[]');
			}

			// otherwise we've got an errormessage or something here ... like "time limit exceeded"

			$hops[$host['ip']] = $hop;
			$dataRow++;
		}

		$this->_hops = $hops;
	}

	/**
	* Returns a Traceroute_Result property
	*
	* @param  string $name    property name
	* @return mixed           property value
	* @access public
	*/
	function getValue($name)
	{
		return isset($this->$name) ? $this->$name : '';
	}

	/**
	* Returns the target IP from parsed result
	*
	* @return string          IP address
	* @see    _target_ip
	* @access public
	*/
	function getTargetIp()
	{
		return $this->_target_ip;
	}

	/**
	* Returns hops from parsed result
	*
	* @return array           Hops
	* @see    _hops
	* @access public
	*/
	function getHops()
	{
		return $this->_hops;
	}

	/**
	* Returns TTL from parsed result
	*
	* @return int             TTL
	* @see    _ttl
	* @access public
	*/
	function getTTL()
	{
		return $this->_ttl;
	}

	/**
	* Returns raw data that was returned by traceroute
	*
	* @return array           raw data
	* @see    _raw_data
	* @access public
	*/
	function getRawData()
	{
		return $this->_raw_data;
	}

	/**
	* Returns sysname that was "guessed" (OS on which class is running)
	*
	* @return string          OS_Guess::sysname
	* @see    _sysname
	* @access public
	*/
	function getSystemName()
	{
		return $this->_sysname;
	}
}

