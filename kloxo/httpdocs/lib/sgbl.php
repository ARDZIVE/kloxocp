<?php

function kloxo_program_root()
{
	$env = getenv('KLOXO_ROOT');
	if (is_string($env) && $env !== '' && is_dir($env . '/httpdocs')) {
		return rtrim($env, '/');
	}

	return dirname(__DIR__, 2);
}

function kloxo_php_binary()
{
	$env = getenv('KLOXO_PHP');
	if (is_string($env) && $env !== '' && is_executable($env)) {
		return $env;
	}

	if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)
		&& strpos(PHP_BINARY, 'php-fpm') === false
		&& strpos(PHP_BINARY, 'php-cgi') === false) {
		return PHP_BINARY;
	}

	foreach (array('/usr/bin/php', '/usr/bin/php8.5', '/usr/bin/php8.4', '/usr/bin/php8.3') as $p) {
		if (is_executable($p)) {
			return $p;
		}
	}

	return '/usr/bin/php';
}

#[AllowDynamicProperties]
class Sgbl
{
	function __construct()
	{
		$this->arg_getting_string = '
		$arglist = array();
		for ($i = $start; $i < func_num_args(); $i++) {
			if (isset($transforming_func)) {
				$arglist[] = $transforming_func(func_get_arg($i));
			} else {
				$arglist[] = func_get_arg($i);
			}
		}';

		if (!is_cli()) {
			$this->__running_in_cli = false;
		} else {
			$this->__running_in_cli = true;
		}

		$this->__var_program_name = 'kloxo';

		$root = kloxo_program_root();
		$this->__path_lxlabs_base = $root;
		$this->__path_program_root = $root;
		$this->__path_program_etc = $root . "/etc/";
		$this->__path_program_htmlbase = $root . "/httpdocs";
		$this->__path_php_path = kloxo_php_binary();

		$this->initDeviceDescriptions();
		$this->initLanguages();
		$this->initLTypes();
		$this->initCtTypes();

		$verfile = $root . '/bin/kloxoversion';
		$t = is_file($verfile) ? trim((string) file_get_contents($verfile)) : '';
		$a = ($t !== '') ? explode('.', $t) : array();

		$this->__ver_name = 'Kloxo CP';
		$this->__ver_note = 'Kloxo fork by Mustafa Ramadhan';

		$this->__ver_major = isset($a[0]) ? $a[0] : '[unknown]';
		$this->__ver_minor = isset($a[1]) ? $a[1] : '[unknown]';
		$this->__ver_release = isset($a[2]) ? $a[2] : '[unknown]';

		$s = isset($a[3]) ? $a[3] : '[unknown]';
		$b = explode('-', $s);

		$this->__ver_extra = isset($b[0]) ? $b[0] : '[unknown]';

		$this->__ver_step = $this->__ver_extra;

		$this->__ver_stamp = isset($b[1]) ? $b[1] : '[unknown]';

		$this->__ver_enterprise = "Single Server Edition";
		$this->__ver_type = "production";
	//	$this->__ver_extra = "Beta";
		$this->__ver_major_minor = $this->__ver_major . "." . $this->__ver_minor;
		$this->__ver_major_minor_release = $this->__ver_major_minor . "." . $this->__ver_release;
		$this->__ver_full = $t;
		$this->__var_nname_impstr = "___";

		$this->__var_lxlabs_marker = "__lxlabs_marker";
		$this->__var_lpanelwidth = "220";

		$this->__var_quote_char = "'";
		$this->__path_perl_path = "/usr/bin/perl";
		$this->__path_kloxo_back_phpini = "/etc/kloxo-backup-php.ini";
		$this->__var_database_type = "mysql";
		$this->__path_mysqlclient_path = "mysql";
		$this->__path_mysqldump_path = "mysqldump";
		$this->__var_noaccess_shell = '/sbin/nologin';
//		$this->__path_named_path = "/var/named";
		$this->__path_customer_root = "/home";
		$this->__path_mysql_datadir = "/var/lib/mysql/";

		$this->__path_slash = "/";
		$this->__path_tmp = "/tmp";
		$this->__path_user_root = "/usr";
		$this->__path_var_root = "/var";
		$this->__path_real_etc_root = "/etc";
		$this->__path_log = "/var/log";
		$this->__path_root_base = "/";

		$this->__path_mara_path = "";
//		$this->__path_mara_chroot = "/etc/maradns/";
//		$this->__path_mara_conf = "/etc/mararc";

		$this->__path_program_home = "/home/kloxo";
		$this->__path_home_dir = "/home";
//		$this->__path_named_conf = "/etc/kloxo.named.conf";
//		$this->__path_named_chroot = "";
		$this->__path_home_root = "/home/kloxo";
		$this->__path_apache_path = "/etc/httpd/conf/";
		$this->__path_lighty_path = "/etc/lighttpd/";
		$this->__path_cron_root = '/var/spool/cron/';
		$this->__path_real_etc_root = "/etc/";

		$this->__path_httpd_root = "/home/httpd";
		$this->__path_client_root = "/home/kloxo/client";

		// MR -- don't use isRpmInstalled for identified!!!
	//	exec("rpm -qa qmail-toaster", $out);
	//	$this->__path_mail_root = (count($out) > 0) ? "/home/vpopmail" : "/home/kloxomail/mail";
		$this->__path_mail_root = "/home/vpopmail";
		// MR -- still using old kloxo mail path
		$this->__path_mail_data = "/home/kloxomail/mail";

		$this->__path_kloxo_httpd_root = "/home/kloxo/httpd";
		$this->__path_kloxo_root = "/home/kloxo";

		$this->__path_serverfile = $this->__path_lxlabs_base . "/serverfile";
		$this->__path_download_dir = $this->__path_lxlabs_base . "/download";

		$this->__path_program_start_vps_flag = $this->__path_program_root . "/etc/flag/start_vps.flg";

		$this->__path_easyinstaller_servervar = $this->__path_kloxo_httpd_root . "/easyinstallerdata/lx_template.servervars.phps";

		// Default Values that will be overrriden in the kloxoconf file.
	//	$this->__path_named_chroot = "/var/named/chroot/";
		$this->__var_progservice_apache = 'httpd';
		$this->__var_programname_ftp = 'pure-ftpd';
		$this->__var_programname_syslog = 'syslog';
	//	$this->__var_programname_mysql = 'mysqld';
		$this->__var_progservice_bind = 'named';
		$this->__var_programname_mmail = 'qmail';
	//	$this->__var_programname_imap = 'courier-imap';
		$this->__var_programname_imap = 'dovecot';

		$this->__var_programuser_dns = 'named';

		$this->__var_no_sync = false;

	//	$this->__path_ssl_root = $this->__path_kloxo_httpd_root . "/ssl";
		$this->__path_ssl_root = $this->__path_kloxo_root . "/ssl";
	//	$this->__path_named_realpath = "$this->__path_named_chroot/$this->__path_named_path";

		// MR -- something wrong under CentOS 7 if change port but after reboot back to use default port
		// so, use this trick.
		if (file_exists($this->__path_program_root . '/init/port-nonssl')) {
			$this->__var_prog_port = file_get_contents(trim($this->__path_program_root . '/init/port-nonssl'));
		} else {
			$this->__var_prog_port = "7778";
		}

		// MR -- something wrong under CentOS 7 if change port but after reboot back to use default port
		// so, use this trick.
		if (file_exists($this->__path_program_root . '/init/port-ssl')) {
			$this->__var_prog_ssl_port = file_get_contents(trim($this->__path_program_root . '/init/port-ssl'));
		} else {
			$this->__var_prog_ssl_port = "7777";
		}

		$this->__var_local_port = '7776';
		$this->__var_remote_port = '7779';

		$this->__var_exit_char = "___...___";
		$this->__var_remote_char = "_._";

		$this->__var_connection_type = "tcp";

		$this->__path_dbschema = "$this->__path_program_root/file/sql/.db_schema";

		if ($this->__var_database_type === "sqlite") {
			$this->__var_dbf = "{$this->__path_program_etc}/conf/db.db";
		} else {
			$this->__var_dbf = "kloxo";
		}

		$this->__path_super_pass = $this->__path_program_etc . "/conf/superadmin.pass";
		$this->__path_admin_pass = $this->__path_program_etc . "/conf/kloxo.pass";
		$this->__path_master_pass = $this->__path_program_etc . "/conf/kloxo.pass";

		$this->__var_super_user = "lxasuper";
		$this->__var_admin_user = "kloxo";

		$this->__path_slave_db = $this->__path_program_etc . "/conf/slave-db.db";
		$this->__path_supernode_db = "lxasuper";

		$this->__path_sql_file_supernode  = "$this->__path_program_htmlbase/sql/supernode";
		$this->__path_sql_file  = "$this->__path_program_htmlbase/sql/full";
		$this->__path_sql_file_common  = "$this->__path_program_htmlbase/sql/common";

		$this->__path_updating_file = $this->__path_program_etc . "/.updating";
		$this->__path_httpd_conf_file = $this->__path_apache_path . "/httpd.conf";
		$this->__path_mail_log = $this->__path_log . "/maillog";
		$this->__path_boot_log = $this->__path_log . "/boot.log";
		$this->__path_cron_log = $this->__path_log . "/cron";
		$this->__path_mysql_log = $this->__path_log . "/mysqld.log";
		$this->__path_vsftpd_log = $this->__path_log . "/vsftpd.log";

		$this->__var_rolelist = array("web", "mail", "dns", "secondary_master");

		$this->__var_dblist = array("mysql");

		$this->__var_error_file = "__path_program_root/httpdocs/.php.err";

		$this->__var_ticket_subcategory = null;
	}

	private function initLanguages()
	{
		// MR -- change reading language list
		$path = $this->__path_program_htmlbase . "/lang";
		if (!is_dir($path)) {
			return;
		}

		$dirs = scandir($path);
		if (!is_array($dirs)) {
			return;
		}

		foreach ($dirs as &$dir) {
			$file = "{$path}/{$dir}/language";

			if (file_exists($file)) {
				$this->__var_language[$dir] = trim(file_get_contents($file));
			}
		}
	}

	private function initDeviceDescriptions()
	{
		$this->__var_service_desc['httpd'] = "Apache Web Server";
		$this->__var_service_desc['apache2'] = "Apache Web Server";

		$this->__var_service_desc['lighttpd'] = "Lighttpd Web Server";
		$this->__var_service_desc['nginx'] = "Nginx Web Server";

		$this->__var_service_desc['php-fpm'] = "Php Fastcgi Process Manager";

		$this->__var_service_desc['qmail'] = "Qmail-toaster Mail Server";
		$this->__var_service_desc['dovecot'] = "Dovecot Pop3/IMAP Server";

		$this->__var_service_desc['named'] = "Bind Dns Server";
		$this->__var_service_desc['bind9'] = "Bind Dns Server";
		$this->__var_service_desc['bind'] = "Bind Dns Server";

		$this->__var_service_desc['pure-ftpd'] = "Pureftp Ftp Server";

		$this->__var_service_desc['djbdns'] = "Djbdns Dns Server";
		$this->__var_service_desc['iptables'] = "IPTables Firewall";
	}

	private function initLTypes()
	{
		$this->__var_ltype['kloxoaccount'] = 'client';
		$this->__var_ltype['serveradmin'] = 'pserver';
		$this->__var_ltype['domainowner'] = 'domain';
		$this->__var_ltype['sysuser'] = 'uuser';
		$this->__var_ltype['mailuser'] = 'mailaccount';
		$this->__var_ltype['ftpuser'] = 'ftpuser';
		$this->__var_ltype['superclient'] = 'superclient';
	}

	private function initCtTypes()
	{
		$this->__var_cttype['superadmin'] = 4;
		$this->__var_cttype['superclient'] = 5;
		$this->__var_cttype['node'] = 7;
		$this->__var_cttype['admin'] = 11;
		$this->__var_cttype['master'] = 15;
		$this->__var_cttype['wholesale'] = 20;
		$this->__var_cttype['reseller'] = 30;
		$this->__var_cttype['customer'] = 40;
		$this->__var_cttype['pserver'] = 50;
		$this->__var_cttype['domain'] = 60;
		$this->__var_cttype['uuser'] = 70;
		$this->__var_cttype['ftpuser'] = 80;
		$this->__var_cttype['mailaccount'] = 90;
	}

	function getlType($classname)
	{
		return array_search($classname, $this->__var_ltype);
	}

	function isDebug()
	{
		return ($this->dbg > 0);
	}

	function isLxlabsClient()
	{
		return ($this->__var_program_name === 'lxlabsclient');
	}

	function isBlackBackground()
	{
		return false;
		return $this->isDebug();
	}

	function isKloxo()
	{
		return ($this->__var_program_name === 'kloxo');
	}

	function isKloxoForRestore()
	{
		return $this->isKloxo();
	}

	function isLive()
	{
		return false;
	}

	function isHyperVm()
	{
		return ($this->__var_program_name === 'hypervm');
	}

	function is_this_master()
	{
		return !$this->is_this_slave();
	}

	function is_this_slave()
	{
		return lxfile_exists("__path_slave_db");
	}
}
