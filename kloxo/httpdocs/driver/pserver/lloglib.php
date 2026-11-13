<?php

class Llog extends Lxclass {

	static $__desc = array("", "",  "log_manager");

	// Data
	static $__desc_nname = array("", "",  "server_name", "a=show");
	static $__acdesc_show = array("", "",  "log_manager", "a=show");


	static $__desc_ffile_l = array('v', '', '', '');

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
	 * Stub objects for Switch To Another while a log file is open.
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

	function createShowSclist()
	{
		$sclist = array();
		$sclist['ffile'] = self::logCatalog();

		return $sclist;
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
