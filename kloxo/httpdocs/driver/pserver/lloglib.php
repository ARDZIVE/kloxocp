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
		$alist['property'][] = 'a=show';
	}

	function createShowAlist(&$alist, $subaction = null)
	{
		return $alist;
	}

	function getFfileFromVirtualList($name)
	{
		if (substr($name, 0, 1) !== '/') {
			$name = '/var/log/' . $name;
		}

		$ffile = new Ffile($this->__masterserver, $this->__readserver, "/", $name, $this->getParentO()->username);

		$ffile->__parent_o = $this;
		$ffile->get();
		$ffile->readonly = 'on';

		return $ffile;
	}

	/**
	 * Applications shown in the first Log Manager dropdown.
	 * Each app lists one or more absolute path patterns (glob) for rotated files.
	 *
	 * @return array<string,array{label:string,patterns:list<string>,hint?:string}>
	 */
	static function logApps()
	{
		$apps = array(
			'syslog' => array(
				'label' => 'Syslog',
				'patterns' => array('/var/log/syslog*'),
			),
			'auth' => array(
				'label' => 'Auth (SSH)',
				'patterns' => array('/var/log/auth.log*'),
			),
			'dpkg' => array(
				'label' => 'Package (dpkg)',
				'patterns' => array('/var/log/dpkg.log*'),
			),
			'exim' => array(
				'label' => 'Exim',
				'patterns' => array('/var/log/exim4/mainlog*', '/var/log/exim4/rejectlog*', '/var/log/exim4/paniclog*'),
			),
			'mail' => array(
				'label' => 'Mail (syslog)',
				'patterns' => array('/var/log/mail.log*', '/var/log/mail.err*'),
			),
			'nginx' => array(
				'label' => 'Nginx',
				'patterns' => array('/var/log/nginx/access.log*', '/var/log/nginx/error.log*'),
			),
			'apache' => array(
				'label' => 'Apache',
				'patterns' => array('/var/log/apache2/access.log*', '/var/log/apache2/error.log*', '/var/log/apache2/other_vhosts_access.log*'),
			),
			'hiawatha' => array(
				'label' => 'Panel Hiawatha',
				'patterns' => array(
					'/opt/kloxo/log/hiawatha-system.log*',
					'/opt/kloxo/log/hiawatha-error.log*',
					'/opt/kloxo/log/hiawatha-access.log*',
					'/opt/kloxo/log/hiawatha-exploit.log*',
					'/opt/kloxo/log/hiawatha-garbage.log*',
				),
			),
			'mariadb' => array(
				'label' => 'MariaDB',
				'patterns' => array('/var/log/mysql/error.log*', '/var/log/mysql/mysql-slow.log*'),
			),
			'pureftpd' => array(
				'label' => 'Pure-FTPd',
				'patterns' => array('/var/log/pureftpd.log*'),
			),
			'fail2ban' => array(
				'label' => 'Fail2Ban',
				'patterns' => array('/var/log/fail2ban.log*'),
			),
			'letsencrypt' => array(
				'label' => "Let's Encrypt",
				'patterns' => array('/var/log/letsencrypt/letsencrypt.log*'),
			),
			'panel' => array(
				'label' => 'Panel (PHP / Watchdog)',
				'patterns' => array(
					'/opt/kloxo/log/php.err*',
					'/opt/kloxo/log/php-fpm-error.log*',
					'/opt/kloxo/log/php-fpm-slow.log*',
					'/opt/kloxo/log/watchdog.log*',
					'/opt/kloxo/log/auth-failures.log*',
				),
			),
			'webfront' => array(
				'label' => 'Web Cache / Proxy',
				'patterns' => array(
					'/var/log/kloxo/webcache.error.log*',
					'/var/log/kloxo/webcache.access.log*',
					'/var/log/kloxo/webproxy.error.log*',
					'/var/log/kloxo/webproxy.access.log*',
				),
			),
			'phpfpm' => array(
				'label' => 'PHP-FPM (sites)',
				'patterns' => array('/var/log/php*-fpm.log*', '/var/log/php/slow.log*'),
			),
			'logwatch' => array(
				'label' => 'LogWatch',
				'patterns' => array('/var/log/logwatch/logwatch-*'),
				'hint' => 'Reports written by Security → LogWatch. Configure schedule and retention there; files live in /var/log/logwatch/.',
			),
		);

		return $apps;
	}

	/**
	 * Flat catalog kept for tests / legacy absolute keys.
	 *
	 * @return array<string,string>
	 */
	static function logCatalog()
	{
		$out = array();
		foreach (self::logApps() as $app => $meta) {
			$out[$app] = (string) ($meta['label'] ?? $app);
		}

		return $out;
	}

	/**
	 * @param mixed $app
	 */
	static function isValidLogApp($app)
	{
		return is_string($app) && $app !== '' && array_key_exists($app, self::logApps());
	}

	/**
	 * @param mixed $path
	 */
	static function isSafeLogPath($path)
	{
		if (!is_string($path) || $path === '' || $path[0] !== '/') {
			return false;
		}
		if (strpos($path, "\0") !== false || strpos($path, '..') !== false) {
			return false;
		}
		foreach (self::logApps() as $meta) {
			foreach ((array) ($meta['patterns'] ?? array()) as $pat) {
				if (self::pathMatchesPattern($path, (string) $pat)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $path
	 * @param string $pattern glob with optional trailing *
	 */
	static function pathMatchesPattern($path, $pattern)
	{
		if ($pattern !== '' && substr($pattern, -1) === '*') {
			$prefix = substr($pattern, 0, -1);

			return strpos($path, $prefix) === 0;
		}

		return $path === $pattern;
	}

	/**
	 * List existing files for an application (root / backend).
	 *
	 * @param string $app
	 * @return list<array{path:string,label:string,mtime:int,size:int}>
	 */
	static function listAppFiles($app)
	{
		if (!self::isValidLogApp($app)) {
			return array();
		}
		$apps = self::logApps();
		$meta = $apps[$app];
		$files = array();
		$seen = array();
		foreach ((array) ($meta['patterns'] ?? array()) as $pat) {
			$pat = (string) $pat;
			foreach (glob($pat) ?: array() as $f) {
				if (!is_string($f) || isset($seen[$f]) || !is_file($f)) {
					continue;
				}
				if (!self::isSafeLogPath($f)) {
					continue;
				}
				$seen[$f] = true;
				$files[] = array(
					'path' => $f,
					'label' => basename($f),
					'mtime' => (int) (@filemtime($f) ?: 0),
					'size' => (int) (@filesize($f) ?: 0),
				);
			}
		}
		usort($files, static function ($a, $b) {
			return ($b['mtime'] <=> $a['mtime']);
		});

		return $files;
	}

	/**
	 * Currently selected application id.
	 *
	 * @return string
	 */
	static function selectedLogApp()
	{
		global $ghtml;

		$req = '';
		if (is_object($ghtml)) {
			$v = null;
			if (isset($ghtml->__http_vars) && is_array($ghtml->__http_vars)
				&& isset($ghtml->__http_vars['frm_llog_app'])) {
				$v = $ghtml->__http_vars['frm_llog_app'];
			} else {
				$v = $ghtml->frm_llog_app;
			}
			if (is_string($v)) {
				$req = trim($v);
			}
		}
		if (self::isValidLogApp($req)) {
			return $req;
		}

		return '';
	}

	/**
	 * Currently selected absolute log path (validated against the app).
	 *
	 * @return string
	 */
	static function selectedLogKey()
	{
		global $ghtml;

		$req = '';
		if (is_object($ghtml)) {
			$v = null;
			if (isset($ghtml->__http_vars) && is_array($ghtml->__http_vars)
				&& isset($ghtml->__http_vars['frm_llog_file'])) {
				$v = $ghtml->__http_vars['frm_llog_file'];
			} else {
				$v = $ghtml->frm_llog_file;
			}
			if (is_string($v)) {
				$req = trim($v);
			}
		}
		if ($req === '' || !self::isSafeLogPath($req)) {
			return '';
		}
		$app = self::selectedLogApp();
		if ($app === '') {
			return self::isSafeLogPath($req) ? $req : '';
		}
		foreach (self::listAppFiles($app) as $row) {
			if (($row['path'] ?? '') === $req) {
				return $req;
			}
		}
		// File may have been pruned; still allow if pattern matches the app.
		$apps = self::logApps();
		foreach ((array) ($apps[$app]['patterns'] ?? array()) as $pat) {
			if (self::pathMatchesPattern($req, (string) $pat)) {
				return $req;
			}
		}

		return '';
	}

	/** @deprecated */
	static function isValidLogKey($key)
	{
		return self::isSafeLogPath($key) || self::isValidLogApp($key);
	}

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

	static function catalogKeyFromPath($path)
	{
		$path = (string) $path;
		if (self::isSafeLogPath($path)) {
			return $path;
		}

		return '';
	}

	/**
	 * List files for an app via kloxo-backend when the panel is not root.
	 *
	 * @param string $app
	 * @return list<array{path:string,label:string,mtime:int,size:int}>
	 */
	function listAppFilesLive($app)
	{
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
		$panel = function_exists('php_sapi_name') ? php_sapi_name() : 'cli';
		$useBackend = $euid !== 0 && $panel !== 'cli' && function_exists('rl_exec_get');
		if (!$useBackend) {
			return self::listAppFiles($app);
		}
		try {
			$server = 'localhost';
			$parent = null;
			try {
				$parent = $this->getParentO();
			} catch (Throwable $e) {
				$parent = null;
			}
			if (is_object($parent) && isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
				$server = $parent->nname;
			}
			$got = rl_exec_get(null, $server, array('Llog', 'listAppFiles'), array($app), false);
			if (is_array($got)) {
				return $got;
			}
		} catch (Throwable $e) {
			log_log('error', 'llog listAppFiles: ' . $e->getMessage());
		}

		return array();
	}

	/**
	 * @param string $path absolute
	 * @return array{key:string,label:string,path:string,content:string,truncated:bool,missing:bool,error:string}
	 */
	function readLogView($path)
	{
		$out = array(
			'key' => (string) $path,
			'label' => basename((string) $path),
			'path' => (string) $path,
			'content' => '',
			'truncated' => false,
			'missing' => true,
			'error' => '',
		);
		if (!self::isSafeLogPath($path)) {
			$out['error'] = 'Invalid log file.';

			return $out;
		}

		try {
			$ff = $this->getFfileFromVirtualList($path);
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
