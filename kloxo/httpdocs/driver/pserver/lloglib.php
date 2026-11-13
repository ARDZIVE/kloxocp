<?php

class Llog extends Lxclass {

	static $__desc = array("", "",  "log_manager");

	// Data
	static $__desc_nname = array("", "",  "server_name", "a=show");
	static $__acdesc_show = array("", "",  "log_manager", "a=show");


	static $__desc_ffile_l = array('v', '', '', '');

	/** Tail size in KB for the inline viewer. */
	const VIEW_TAIL_KB = 50;

	function get() {}
	function write() {}

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return $parent->getClName();
	}

	function getId()
	{
		return $this->getSpecialname();
	}

	function createShowPropertyList(&$alist)
	{
		// Log Manager is a picker + viewer; no File Manager root tab.
		$alist['property'][] = 'a=show';
	}

	function createShowAlist(&$alist, $subaction = null)
	{
		return $alist;
	}

	function getFfileFromVirtualList($name)
	{
		global $sgbl;

		if (substr($name, 0, 1) !== '/') {
			$name = '/var/log/' . $name;
		}

		$ffile= new Ffile($this->__masterserver, $this->__readserver, "/", $name, $this->getParentO()->username);

		$ffile->__parent_o = $this;
		$ffile->get();
		$ffile->readonly = 'on';

		return $ffile;
	}

	/**
	 * Ubuntu log files the panel may show. Relative keys are under /var/log/.
	 * Dead CentOS / removed-stack paths (httpd, lighttpd, yum, qmail, …) stay out.
	 *
	 * @return array<string,string> path key => label
	 */
	static function logCatalog()
	{
		$list = array(
			'syslog' => 'Syslog',
			'auth.log' => 'Auth (SSH)',
			'dpkg.log' => 'Package (dpkg)',

			'exim4/mainlog' => 'Exim Main',
			'exim4/rejectlog' => 'Exim Reject',
			'exim4/paniclog' => 'Exim Panic',
			'mail.log' => 'Mail (syslog)',

			'nginx/access.log' => 'Nginx Access',
			'nginx/error.log' => 'Nginx Error',
			'apache2/access.log' => 'Apache Access',
			'apache2/error.log' => 'Apache Error',

			'/opt/kloxo/log/hiawatha-system.log' => 'Panel Hiawatha System',
			'/opt/kloxo/log/hiawatha-error.log' => 'Panel Hiawatha Error',
			'/opt/kloxo/log/hiawatha-access.log' => 'Panel Hiawatha Access',
			'/opt/kloxo/log/hiawatha-exploit.log' => 'Panel Hiawatha Exploit',
			'/opt/kloxo/log/hiawatha-garbage.log' => 'Panel Hiawatha Garbage',

			'mysql/error.log' => 'MariaDB Error',

			'pureftpd.log' => 'Pure-FTPd',

			'fail2ban.log' => 'Fail2Ban',
			'letsencrypt/letsencrypt.log' => "Let's Encrypt (certbot)",

			'/opt/kloxo/log/php.err' => 'Panel PHP',
			'/opt/kloxo/log/php-fpm-error.log' => 'Panel PHP-FPM',
			'/opt/kloxo/log/php-fpm-slow.log' => 'Panel PHP-FPM Slow',
			'/opt/kloxo/log/watchdog.log' => 'Watchdog',
			'/opt/kloxo/log/auth-failures.log' => 'Panel Auth Failures',

			'/var/log/kloxo/webcache.error.log' => 'Web Cache Error',
			'/var/log/kloxo/webproxy.error.log' => 'Web Proxy Error',

			'php/slow.log' => 'PHP-FPM Slow (sites)',
		);

		$phplist = array();
		if (function_exists('getMultiplePhpList')) {
			try {
				$phplist = getMultiplePhpList();
			} catch (Throwable $e) {
				$phplist = array();
			}
		}
		if (is_array($phplist)) {
			foreach ($phplist as $id) {
				if (!is_string($id)) {
					continue;
				}
				if (preg_match('/^php(\d+\.\d+)$/', $id, $m) !== 1) {
					continue;
				}
				$ver = $m[1];
				$list["php{$ver}-fpm.log"] = "PHP-FPM {$ver}";
			}
		}

		return $list;
	}

	/**
	 * @param mixed $key
	 */
	static function isValidLogKey($key)
	{
		if (!is_string($key) || $key === '') {
			return false;
		}
		if (strpos($key, "\0") !== false || strpos($key, '..') !== false) {
			return false;
		}

		return array_key_exists($key, self::logCatalog());
	}

	/**
	 * Absolute filesystem path for a catalog key.
	 *
	 * @param string $key
	 * @return string
	 */
	static function resolveLogPath($key)
	{
		if (!is_string($key) || $key === '') {
			return '';
		}
		if (substr($key, 0, 1) === '/') {
			return $key;
		}

		return '/var/log/' . $key;
	}

	/**
	 * Currently selected catalog key from the request (validated).
	 *
	 * @return string
	 */
	static function selectedLogKey()
	{
		global $ghtml;

		$req = '';
		if (is_object($ghtml)) {
			$v = $ghtml->frm_llog_file;
			if (is_string($v)) {
				$req = trim($v);
			}
		}
		if (self::isValidLogKey($req)) {
			return $req;
		}

		return '';
	}

	/**
	 * Map an absolute or relative path back to a catalog key (legacy ffile URLs).
	 *
	 * @param string $path
	 * @return string
	 */
	static function catalogKeyFromPath($path)
	{
		$path = (string) $path;
		$cat = self::logCatalog();
		if ($path !== '' && isset($cat[$path])) {
			return $path;
		}
		if (strpos($path, '/var/log/') === 0) {
			$rel = substr($path, strlen('/var/log/'));
			if (isset($cat[$rel])) {
				return $rel;
			}
		}

		return '';
	}

	/**
	 * Stub objects for Switch To Another on a legacy ffile log URL.
	 *
	 * @return array<string,object>
	 */
	static function logSwitchList()
	{
		$out = array();
		foreach (self::logCatalog() as $path => $label) {
			$out[$path] = new llog_switch_stub($label);
		}

		return $out;
	}

	/**
	 * Read the tail of a catalog log via kloxo-backend (root).
	 *
	 * @param string $key
	 * @return array{key:string,label:string,path:string,content:string,truncated:bool,missing:bool,error:string}
	 */
	function readLogView($key)
	{
		$cat = self::logCatalog();
		$out = array(
			'key' => (string) $key,
			'label' => isset($cat[$key]) ? (string) $cat[$key] : (string) $key,
			'path' => self::resolveLogPath($key),
			'content' => '',
			'truncated' => false,
			'missing' => true,
			'error' => '',
		);
		if (!self::isValidLogKey($key)) {
			$out['error'] = 'Invalid log file.';

			return $out;
		}

		try {
			$ff = $this->getFfileFromVirtualList($key);
			$ff->numlines = self::VIEW_TAIL_KB;
			$ff->getContent();
			$out['content'] = isset($ff->content) && is_string($ff->content) ? $ff->content : '';
			$out['truncated'] = method_exists($ff, 'isOn') && $ff->isOn('not_full_size');
			$out['missing'] = ($out['content'] === '' && (!isset($ff->ttype) || $ff->ttype === '' || $ff->ttype === 'directory'));
			if (isset($ff->ttype) && $ff->ttype === 'file') {
				$out['missing'] = false;
			}
			if (isset($ff->size) && (int) $ff->size === 0 && $out['content'] === '') {
				$out['missing'] = false;
			}
		} catch (Throwable $e) {
			$out['error'] = $e->getMessage();
		}

		return $out;
	}

	function createShowSclist()
	{
		// Triggers the on-page picker in do_select_list (does not navigate away).
		return array('ffile' => self::logCatalog());
	}
}

/**
 * Display stub for Log Manager Switch To Another (label only).
 */
class llog_switch_stub
{
	/** @var string */
	private $id;

	/** @param string $id */
	function __construct($id)
	{
		$this->id = (string) $id;
	}

	function getId()
	{
		return $this->id;
	}
}
