<?php

include_once "lib/html/linuxlib.php";
include_once "lib/linuxproglib.php";

// Don't remove this. This is used for slave upgrade.
function remotetestfunc()
{
}

define('S_IFDIR', 00040000);
define('S_ISUID', 00004000);
define('S_ISGID', 00002000);

// This is the only function that exectues during the initialization... 
// The rest of the whle library exists as functions that can be called... 
// Nothing gets executed on their own... Execept this.. 
// So it makes this sort of special... very special..

init_global();

function init_global()
{
	global $gbl, $sgbl, $ghtml;
	global $g_demo;

	$sgbl = new Sgbl();
	$gbl = new Gbl();
	$gbl->get();

//
// Turn on demo version by putting a empty file called demo in the etc dir
//
	if (lfile_exists("__path_program_etc/demo")) {
		$g_demo = 1;
	}
//
// ### LxCenter
//

// Check for Development/Debug version
// If file not exists, Production mode (-1)
// If file exists it can have the following numbers to enable
// 1  = Debug mode 1
// 2  = Debug mode 2
// 3  = Debug mode 3
// 4  = Debug mode 4
// 5  = Debug mode 5
// -1 = Turn Off and go to production mode

	check_for_debug("/commands.php");

// Disabled by LxCenter, we are not at PHP version with number 1 at postition N ( x.N.x )
//  $v = explode(".", PHP_VERSION);
//  if ($v[1] == "1" && $sgbl->isDebug()) {
//      date_default_timezone_set("UTC");
//  }

	$sgbl->method = ($sgbl->dbg >= 1) ? "get" : "post";
}

function debug_for_backend()
{
	global $gbl, $sgbl, $login, $ghtml;
	check_for_debug("/commands.php");
	if ($sgbl->isDebug()) {
		return;
	}
	check_for_debug("/backend.php");
}

function check_for_debug($file)
{
	global $gbl, $sgbl, $login, $ghtml;
	if (file_exists(getreal($file))) {
		// MR -- fix if file have LF
		$content = file_get_contents(getreal($file));
		$sgbl->dbg = $content[0];
		if ($sgbl->dbg != "1" && $sgbl->dbg != "2" && $sgbl->dbg != "3" && $sgbl->dbg != "4" && $sgbl->dbg != "5") {
			$sgbl->dbg = -1;
		}
	} else {
		$sgbl->dbg = -1;
	}
	if ($sgbl->dbg > 0) {
		ini_set("error_reporting", (string) E_ALL);
		ini_set("display_errors", "On");
		ini_set("log_errors", "On");
	} else {
		ini_set("error_reporting", E_ERROR);
		ini_set("display_errors", "Off");
		ini_set("log_errors", "On");
	}
}

function isUpdating()
{
	return lx_core_lock_check_only("update.php");
}

class lxException extends Exception
{
	public $syncserver;
	public $class;
	public $variable;
	public $error;
	public $message;

	function getClass()
	{
		return lget_class($this);
	}

	function __construct($message, $variable = 'nname', $value = null)
	{
		// MR - as array if more then 1 declare in language files (example: for throw)
		if (is_array($message)) {
			$message = $message[0];
		}
		if (!is_string($message)) {
			$message = $message === null ? '' : (string)$message;
		}

		parent::__construct($message);

		$this->message = $message;
		$this->variable = $variable;
		$this->value = $value;
		$this->__full_message = "$message: $variable: $value";
		//log_log("exception", "$message: $variable: $value");
	}

	function getlMessage()
	{
		return "$this->message: $this->variable: $this->value";
	}
}

/**
 * Not a failure: a plain notice for the user ("First add some autoresponders").
 * Pages that catch lxException print it as a normal info message, not an error.
 */
class lxInfoException extends lxException
{
}

function getAllOperatingSystemDetails()
{
	$ret = findOperatingSystem();
	$ret['loadavg'] = os_getLoadAvg();
	dprintr($ret);
	return $ret;
}

function findOperatingSystem($type = null)
{
	if (file_exists("/etc/fedora-release")) {
		$ret['os'] = 'fedora';
		$ret['version'] = file_get_contents("/etc/fedora-release");
		$ret['pointversion'] = find_os_pointversion();
	} else if (file_exists("/etc/redhat-release")) {
		$ret['os'] = 'rhel';
		$ret['version'] = file_get_contents("/etc/redhat-release");
		$ret['pointversion'] = find_os_pointversion();
	}

/* -- not work because no driver for centos inside /opt/kloxo/file/conf

	$ret['os'] = find_os_distro();
	$ret['version'] = find_os_release();
	$ret['pointversion'] = find_os_pointversion();
*/
	if (lxfile_exists("__path_program_etc/install_xen") || lxfile_exists("/proc/xen")) {
		$ret['vpstype'] = "xen";
		$ret['xenlocation'] = vg_complete();
	}

	if ($type) {
		return $ret[$type];
	}

	return $ret;
}

function find_os_distro()
{
	return find_os_selecttype('distro');
}

function find_os_release()
{
	return find_os_selecttype('release');
}

function find_os_pointversion()
{
/*
	if (file_exists("/etc/fedora-release")) {
		$release = trim(file_get_contents("/etc/fedora-release"));
		$osv = explode(" ", $release);
		if (strtolower($osv[1]) === 'core') {
			$osversion = "fedora-" . $osv[3];
		} else {
			$osversion = "fedora-" . $osv[2];
		}
		return $osversion;
	}

	if (file_exists("/etc/redhat-release")) {
		$release = trim(file_get_contents("/etc/redhat-release"));
		$osv = explode(" ", $release);
		if (isset($osv[6])) {
			$osversion = "rhel-" . $osv[6];
		} else {
			$oss = explode(".", $osv[2]);
			$osversion = "centos-" . $oss[0];
		}
		return $osversion;
	}
*/
	return find_os_selecttype('pointversion');
}

function find_os_selecttype($select)
{
	// list os support
	$ossup = array('redhat' => 'rhel', 'fedora' => 'fedora', 'centos' => 'centos');
	
	foreach(array_keys($ossup) as $k) {
		$osrel = file_get_contents("/etc/{$k}-release");
		
		if ($osrel) {
				if ($select === 'release') {
					return $osrel;
				}
				
				$osrel = strtolower(trim($osrel));
				
				break;
		}
	}
	
	// specific for 'red hat'
	$osrel = str_replace('red hat', 'redhat', $osrel);

	$osver = explode(" ", $osrel);

	$verpos = sizeof($osver) - 2;

	if (array_key_exists($osver[0], $ossup)) {
		// specific for 'red hat'
		if ($osrel === 'redhat') {
			$oss = $osver[$verpos];
		}
		else {
			$mapos = explode(".", $osver[$verpos]);
			$oss = $mapos[0];
		}

		if ($select === 'distro') {
			return $ossup[$osver[0]];
		}
		else if ($select === 'pointversion') {
			return $ossup[$osver[0]]."-".$oss;
		}
	}
}

function lscandir_without_dot($arg, $dotflag = false)
{
	$list = lscandir($arg);

	if (!$list) {
		return array();
	}

	foreach ($list as $k => $v) {
		if ($v === ".." || $v === "." || $v === '.svn') {
			unset($list[$k]);
		}
		if ($dotflag && csb($v, '.')) {
			unset($list[$k]);
		}
	}
	return $list;
}

function lscandir_without_dot_or_underscore($arg, $dotflag = false)
{
	$list = lscandir($arg);

	if (!$list) {
		return array();
	}

	foreach ($list as $k => $v) {
		if ($v === ".." || $v === "." || $v === '.svn') {
			unset($list[$k]);
		}
		if ($dotflag && csb($v, '.')) {
			unset($list[$k]);
		}
		if (csb($v, "__")) {
			unset($list[$k]);
		}
	}
	return $list;
}

function lscandir($arg)
{
	return lx_redefine_func("scandir", $arg);
}

function lunlink($arg)
{
	return lx_redefine_func("unlink", $arg);
}

function ltouch($arg)
{
	return lx_redefine_func("touch", $arg);
}

function lchdir($arg)
{
	return lx_redefine_func("chdir", $arg);
}

function takeToStartOfLine($fp)
{
	while (fgetc($fp) == "\n" && ftell($fp) != 1 && ftell($fp) != 0) {
		fseek($fp, -2, SEEK_CUR);
	}

	while (($c = fgetc($fp)) != "\n" && ftell($fp) != 1 && ftell($fp) != 0) {
		fseek($fp, -2, SEEK_CUR);
	}
}

function tail_func($file, $lines)
{
	$fp = fopen($file, "r");

	if (!$fp) {
		return null;
	}

	dprint("in Tail Func\n");

	fseek($fp, 0, SEEK_END);

	// Go back onece and read the line.
	takeToStartOfLine($fp);
	$arr[] = fgets($fp);

	$n = 0;
	while ($n < $lines && ftell($fp) !== 1) {
		$n++;
		//dprint($n . "\n");
		// You have to go back twice.
		print(' Before: ' . ftell($fp) . "\n");
		fseek($fp, -3, SEEK_CUR);
		takeToStartOfLine($fp);
		fseek($fp, -3, SEEK_CUR);
		print(' aaFter: ' . ftell($fp) . "\n");
		takeToStartOfLine($fp);
		print(' aaaaFter: ' . ftell($fp) . "\n");
		$arr[] = fgets($fp);
	}
	return implode("", array_reverse($arr));
}

function lfile_get_json_unserialize($file)
{
	if (!lxfile_exists($file)) {
		return null;
	}
	return json_decode(lfile_get_contents($file), true);
}

function lfile_put_json_serialize($file, $var)
{
	return lfile_put_contents($file, json_encode($var));
}

function lfile_get_unserialize($file)
{
	if (!lxfile_exists($file)) {
		return null;
	}
	return unserialize(lfile_get_contents($file));
}

function lfile_put_serialize($file, $var)
{
	return lfile_put_contents($file, serialize($var));
}

function lfile_get_contents($arg)
{
	return lx_redefine_func("file_get_contents", $arg);
}

function lrename($arg1, $arg2)
{
	return lx_redefine_func("rename", $arg1, $arg2);
}

function lfopen($arg1, $arg2)
{
	return lx_redefine_func("fopen", $arg1, $arg2);
}

function lfilesize($arg)
{
	return lx_redefine_func("filesize", $arg);
}

function ltempnam($arg1, $arg2)
{
	return lx_redefine_func("tempnam", $arg1, $arg2);
}

function lfile_write_content($file, $data, $user)
{
	if (csa($user, ":")) {
		$realuser = strtil($user, ":");
	} else {
		$realuser = $user;
	}

	if (!check_file_if_owned_by($file, $realuser)) {
		return;
	}
	lfile_put_contents($file, $data);
	lxfile_unix_chown($file, $user);
}

function check_file_if_owned_by_and_throw($filename, $username)
{
	global $login;

	if (!check_file_if_owned_by($filename, $username)) {
		throw new lxException($login->getThrow('file_exists_not_owned'), '', $filename);
	}
}

function lis_hardlink($file)
{
	$file = expand_real_root($file);
	if (is_dir($file)) {
		return false;
	}
	$stat = stat($file);
	if ($stat['nlink'] >= 2) {
		return true;
	}
	return false;
}

function is_soft_or_hardlink($file)
{
	if (!lxfile_exists($file)) {
		return false;
	}

	if (lis_link($file) || lis_hardlink($file)) {
		return true;
	}
	return false;
}

function new_process_mv_rec($user, $src, $dst)
{
	$src = expand_real_root($src);
	$dst = expand_real_root($dst);
	new_process_cmd($user, null, "'mv' -f \"$src\" \"$dst\"");
}

function new_process_chmod_rec($user, $file, $perm)
{
	$file = expand_real_root($file);
	$cmd = "chmod -R $perm \"$file\"";
	new_process_cmd($user, null, $cmd);
}

function new_process_cp_rec($user, $src, $dst)
{
	$src = expand_real_root($src);
	$dst = expand_real_root($dst);
	$cmd = "'cp' -a \"$src\" \"$dst\"";
	new_process_cmd($user, null, $cmd);
}

function new_process_cmd($user, $dir, $cmd)
{
	global $sgbl;

	if (csa($user, ':')) {
		list($user, $group) = explode(':', $user);
	} else {
		$group = $user;
	}

	if ($user === 'root') {
		$user = '__system__';
	}

	if ($dir) {
		$olddir = getcwd();
		chdir($dir);
	}
	if ($user !== '__system__') {
		$uid = is_numeric($user) ? (int) $user : os_get_uid_from_user($user);
		$gid = is_numeric($group) ? (int) $group : os_get_gid_from_user($user);
		exec("{$sgbl->__path_php_path} {$sgbl->__path_program_root}/bin/phpexec.php $uid $gid $cmd 2>&1", $output, $retval);
	} else {
		exec("$cmd 2>&1", $output, $retval);
	}

	if ($dir) {
		chdir($olddir);
	}

	$output = implode("\n", $output);
	log_log('user_cmd', "($dir) $user $cmd $output");

	return $retval;
}

function kloxo_php_error_log_path(): string
{
	global $sgbl;

	$root = (isset($sgbl) && !empty($sgbl->__path_program_root))
		? $sgbl->__path_program_root
		: dirname(__DIR__, 3);

	return rtrim((string) $root, '/') . '/log/php.err';
}

function lfile_put_contents($file, $data, $flag = 0)
{
	$flags = is_int($flag) ? $flag : 0;
	$file = expand_real_root($file);
	$norm = str_replace('\\', '/', (string) $file);
	if (preg_match('#(?:^|/)httpdocs/\.php\.err$#', $norm) || basename($norm) === '.php.err') {
		$file = kloxo_php_error_log_path();
	}

	if (is_soft_or_hardlink($file)) {
		log_log("link_error", "$file is hard or symlink. Not writing\n");

		return false;
	}

	if (char_search_a($data, "__path_")) {
		dprint("Warning : Trying to write __path into a file $file: $data <br> \n", 3);
	}

	$dir = dirname($file);
	if (!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}

	if (file_exists($file)) {
		if (!is_writable($file)) {
			return false;
		}
	} elseif (!is_dir($dir) || !is_writable($dir)) {
		return false;
	}

	$ok = @file_put_contents($file, $data, $flags);
	if ($ok === false) {
		return false;
	}

	return $ok;
}

/**
 * @return void
 * @param unknown
 * @param unknown
 * @desc Redefining php functions ... sort of.. Stupid php doesn't allow that. So we do the next best thing.. We add an 'l' to all system functions and then use these functions instead of the php ones... In a way, is a better idea too, since, there might always be some cases where we might want to override this crap. :-)
 */
function lmkdir($dir)
{
	return lx_redefine_func("mkdir", $dir);
}

function lis_executable($file)
{
	return lx_redefine_func("is_executable", $file);
}

function lis_readable($file)
{
	return lx_redefine_func("is_readable", $file);
}

function lreadlink($file)
{
	return lx_redefine_func("readlink", $file);
}

function lis_link($file)
{
	return lx_redefine_func("is_link", $file);
}

function lis_dir($file)
{
	return lx_redefine_func("is_dir", $file);
}

function cp_if_not_exists($src, $dst)
{
	if (lxfile_exists($dst)) {
		return;
	}
	if (!lxfile_exists($src)) {
		return;
	}
	lxfile_cp($src, $dst);
}

function cp_rec_if_not_exists($src, $dst)
{
	if (lxfile_exists($dst)) {
		return;
	}
	if (!lxfile_exists($src)) {
		return;
	}
	lxfile_cp_rec($src, $dst);
}

function mv_rec_if_not_exists($src, $dst)
{
	if (lxfile_exists($dst)) {
		return;
	}
	if (!lxfile_exists($src)) {
		return;
	}
	lxfile_mv_rec($src, $dst);
}

function llstat($file)
{
	return lx_redefine_func("lstat", $file);
}

function lx_merge_good($arg)
{
	global $gbl, $sgbl, $login, $ghtml;

	$start = 0;
	$transforming_func = null;

	if (version_compare(PHP_VERSION, '5.3.0', '<')) {
		eval($sgbl->arg_getting_string);
	} else {
	//	$arglist = get_function_arglist($start, $transforming_func);

		$arglist = array();

		for ($i = $start; $i < func_num_args(); $i++) {
			$arglist[] = func_get_arg($i);
		}

	}

	//dprintr($arglist);

	$list = $arglist;

	foreach ($list as &$l) {
		if (!$l) {
			$l = array();
		}
	}

	$ret = array();
	foreach ($list as $nl) {
		//dprintr($nl);
		if (is_array($nl)) {
			$ret = array_merge($ret, $nl);
		} else {
			$ret[] = $nl;
		}
	}
	return $ret;
}

function lx_array_merge($list)
{
	foreach ($list as &$l) {
		if (!$l) {
			$l = array();
		}
	}
	$ret = array();
	foreach ($list as $nl) {
		//dprintr($nl);
		if (is_array($nl)) {
			$ret = array_merge($ret, $nl);
		} else {
			$ret[] = $nl;
		}
	}
	return $ret;
}

function log_switch($mess, $id = 1)
{
	log_log('switch', $mess, $id);
}

function log_error($mess, $id = 1)
{
	log_log('error', $mess, $id);
}

function log_bdatabase($mess, $id = 1)
{
	log_log('bdatabase', $mess, $id);
}

function log_restore($mess, $id = 1)
{
	log_log('restore', $mess, $id);
}

function log_database($mess, $id = 1)
{
	log_log('database', $mess, $id);
}

function myPcntl_reaper()
{
	pcntl_wait($status, WNOHANG);
}

function myPcntl_wait()
{
	pcntl_wait($status);
}

function myPcntl_fork()
{
	global $gbl, $sgbl, $login, $ghtml;

	$pid = pcntl_fork();

	return $pid;
}

function log_log($file, $mess, $id = null)
{
	if (!is_string($mess)) {
		$mess = var_export($mess, true);
	}

	$mess = trim($mess);

	$rf = "__path_program_root/log/$file";

	lfile_put_contents($rf, @ date("H:i M/d/Y") . ": $mess" . PHP_EOL, FILE_APPEND);
}

function log_cleanup($mess, $nolog = null)
{
	// Function used in cleanup/upcp process
	//
	// logs to the file update and print to screen

	if (!is_string($mess)) {
		$mess = var_export($mess, true);
	}
	$mess = trim($mess);

	print( $mess . "\n" );

	if (!$nolog) {
		$rf = "__path_program_root/log/update";
		lfile_put_contents($rf, @ date("H:i M/d/Y") . ": $mess" . PHP_EOL, FILE_APPEND);
	}
}

function log_ajax($mess, $id = 1)
{
	log_log('ajax', $mess, $id);
}

function log_redirect($mess, $id = 1)
{
	log_log('redirect_error', $mess, $id);
}

function log_message($mess, $id = 1)
{
	log_log('message', $mess, $id);
}

function log_security($mess, $id = 1)
{
    // get IP
	if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
	    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	}
    else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

	$user_agent = $_SERVER["HTTP_USER_AGENT"];
	
	if (empty($_SERVER["HTTP_USER_AGENT"])) {
		$user_agent = "Not a browser";
	}

	log_log('security', $mess . " IP: $ip; User agent: $user_agent", $id);
}

function log_filesys_err($mess, $id = 1)
{
	log_log('filesyserr', $mess, $id);
}

function log_filesys($mess, $id = 1)
{
	global $global_dontlogshell;
	if ($global_dontlogshell) {
		log_log('nonfilesys', $mess, $id);
	} else {
		log_log('filesys', $mess, $id);
	}
}

function log_shell($mess, $id = 1)
{
	log_log('shell_exec', $mess, $id);
}

function log_shell_error($mess, $id = 1)
{
	log_log('shell_error', $mess, $id);
}

function lfile_trim($arg)
{
	$list = lfile($arg);

	foreach ($list as &$s) {
		$s = trim($s);
	}

	return $list;
}

function lcopy($src, $dst)
{
	return lx_redefine_func("copy", $src, $dst);
}

function lreadfile($file)
{
	return lx_redefine_func("readfile", $file);
}

function lmd5_file($file)
{
	return lx_redefine_func("md5_file", $file);
}

function lfile_exists($file)
{
	return lx_redefine_func("file_exists", $file);
}

function lsqlite_open($file)
{
	return lx_redefine_func("sqlite_open", $file);
}

/**
 * @return void
 * @param unknown
 * @param unknown
 * @desc This function is the core of the the path abstraction. It converts the paths of the form '__path.../dir' to '$sgbl->__path.../dir'. This is used in all the redefined functions to convert their arguments.
 */
function expand_real_root($root)
{
	global $gbl, $sgbl, $login, $ghtml;
	if (char_search_beg($root, "__path")) {
		if (char_search_a($root, "/")) {
			$var = substr($root, 0, strpos($root, "/"));
			$root = $sgbl->$var . "/" . substr($root, strpos($root, "/") + 1);
		} else {
			$root = $sgbl->$root;
		}
	}
	$root = remove_extra_slash($root);
	return $root;
}

/**
 * @return void
 * @param unknown
 * @param unknown
 * @desc the function that does the redefining of fundamental file access functions. Note the double use of 'eval'. hey hey, nothing is impossible in php. Never ever repeat the code; Make it unreadable instead. :-)
 */
function kill_and_save_pid($name)
{
	kill_pid($name);
	usleep(100);
	save_pid($name);
}

function save_pid($name)
{
	lfile_put_contents("__path_program_root/pid/$name.pid", os_getpid());
}

function kill_pid($name)
{
	$pid = lfile_get_contents("__path_program_root/pid/$name.pid");
	os_killpid($pid);
}

/************************************
 * HOW-TO GENERATE KEYPAIRS
 * //Passphrase is helloworld
 * $ openssl req -x509 -newkey rsa:1024 -keyout mykey.key -out mycert.crt
 */

function licenseDecrypt($license_content)
{
	global $gbl, $sgbl, $login, $ghtml;

	$fp = lfopen("__path_program_root/file/lprogram.crt", "r");
	$public_key = fread($fp, 8192);
	fclose($fp);

	openssl_get_publickey($public_key);

	$list = explode("\n", $license_content);

	// decrypt
	print_time('decrypt');
	$fullstring = null;
	foreach ($list as $l) {
		$l = trim($l);
		if (!$l) {
			continue;
		}
		$decrypted_string = null;
		openssl_public_decrypt(base64_decode($l), $decrypted_string, $public_key);
		//dprintr($decrypted_string . " $l<br> \n");
		$fullstring .= $decrypted_string;
	}
	print_time('decrypt', "Time Taken For decrypt:", 3);
	return $fullstring;
}

function lx_redefine_func($func)
{
	global $gbl, $sgbl, $login, $ghtml;

	$start = 1;
	$transforming_func = "expand_real_root";

	if (version_compare(PHP_VERSION, '5.3.0', '<')) {
		eval($sgbl->arg_getting_string);
	} else {
	//	$arglist = get_function_arglist($start, $transforming_func);

		$arglist = array();

		for ($i = $start; $i < func_num_args(); $i++) {
				$arglist[] = $transforming_func(func_get_arg($i));
		}
	}

	return call_user_func_array($func, $arglist);
}

function licenseEncrypt($string)
{
	global $gbl, $sgbl, $login, $ghtml;
	$file = "$sgbl->__path_program_root/file/license_privatekey.key";
	// encrypt
	$pass = "helloworld";
	$result = openssl_get_privatekey(array("file://" . $file, $pass));
	$ar = str_split($string, 100);
	$fullstring = null;
	foreach ($ar as $s) {
		openssl_private_encrypt($s, $encrypted_string, $result);
		$fullstring .= base64_encode($encrypted_string) . "\n";
	}
	print(openssl_error_string());

	return $fullstring;
}

function remove_unnecessary_stat(&$stat)
{
	foreach ($stat as $k => $v) {
		if (is_numeric($k)) {
			unset($stat[$k]);
		}
	}
}

function getShellCommand($cmd, $arglist)
{
	global $gbl, $sgbl, $login, $ghtml;
	$args = null;
	$q = $sgbl->__var_quote_char;
	$cmd = expand_real_root($cmd);
	$cmd = str_replace(";", "", $cmd);
	$cmd = "{$q}$cmd{$q}";

	foreach ($arglist as $a) {
		if ($a === "") {
			continue;
		}
		if (is_array($a)) {
			foreach ($a as $aa) {
				$aa = str_replace(";", "", $aa);
				$args .= " $q" . expand_real_root($aa) . "$q";
			}
		} else {
			$a = str_replace(";", "", $a);
			$args .= " $q" . expand_real_root($a) . "$q";
		}
	}
	$cmd .= " " . $args;
	return $cmd;
}

#[AllowDynamicProperties]
class Remote
{
	/*
 public $ddata;
 public $message;
 public $exception;
 */
}

function dprintoa($var, $type = 0)
{
	global $sgbl, $login, $ghtml;
	if ($type > $sgbl->dbg) {
		return;
	}
	if (!is_array($var)) {
		return;
	}
	foreach ($var as $k => $v) {
		dprint("$k =>");
		dprinto($var, $type);
		dprint("\n");
	}
}

function dprinto($var, $type = 0)
{
	global $sgbl;

	if ($type > $sgbl->dbg) {
		return;
	}

	if (!is_object($var)) {
		return;
	}

	$newob = clone($var);
	$newob->__parent_o = null;
	dprintr($newob);
}

function dprintr($var, $type = 0)
{
	global $sgbl;

	if ($type > $sgbl->dbg) {
		return;
	}

	if (is_object($var) && method_exists($var, "clearChildrenAndParent")) {
		$newvar = myclone($var);
		lxclass::clearChildrenAndParent($newvar);
		$newvar->driverApp = 'unset for printing';
		$newvar->__parent_o = 'unset for printing';

		$class = $newvar->get__table();
		
		if (csb($class, "sp_")) {
			$bclass = strfrom($class, "sp_") . "_b";
			$newvar->$bclass->__parent_o = 'unset for printing';
		}
	} else {
		$newvar = $var;
	}

	if ($sgbl->__running_in_cli) {
		print("\n");
	} else {
		print("<pre>");
	}

	print_r($newvar);

	if ($sgbl->__running_in_cli) {
		print("\n");
	} else {
		print("</pre>");
	}
}

function dprint($var, $type = 0)
{
	global $sgbl;

	$isCliMode = is_cli();
	
	if ($type <= $sgbl->dbg) {
		if (is_string($var)) {
			if ($sgbl->__running_in_cli) {
				print("\n");
			} else {
				print("<pre>");
			}

			print($var);
			
			if ($sgbl->__running_in_cli) {
				print("\n");
			} else {
				print("</pre>");
			}
		}
	}
}

function dprint_r($var, $type = 0)
{
	global $sgbl;
	
	if ($type <= $sgbl->dbg) {
		if ($sgbl->__running_in_cli) {
			print("\n");
		} else {
			print("<pre>");
		}

		print_r($var);

		if ($sgbl->__running_in_cli) {
			print("\n");
		} else {
			print("</pre>");
		}
	}
}

function debug_print($var, $note = null)
{
	global $sgbl;

	if ($type <= $sgbl->dbg) {
		if ($sgbl->__running_in_cli) {
			print("\n---begin xprint---\n");
			if ($note) {
				print("* Note: {$note}");
			}
			print("\n");
		} else {
			print("<pre>---begin xprint---\n");
			if ($note) {
				print("* Note: {$note}");
			}
			print("<pre>");
		}

	//	if (is_object($var) && method_exists($var, "clearChildrenAndParent")) {
		if (is_object($var)) {
			$newvar = myclone($var);

			if (method_exists($var, "clearChildrenAndParent")) {
				lxclass::clearChildrenAndParent($newvar);
				$newvar->driverApp = 'unset for printing';
				$newvar->__parent_o = 'unset for printing';

				$class = $newvar->get__table();

				if (csb($class, "sp_")) {
					$bclass = strfrom($class, "sp_") . "_b";
					$newvar->$bclass->__parent_o = 'unset for printing';
				}
			}

			print_r($newvar);
		} else {
			if (is_array($var)) {
				print_r($var);
			} else {
				print($var);
			}
		}

		if ($sgbl->__running_in_cli) {
			print("\n---end xprint---\n");
			print("\n");
		} else {
			print("\n---end xprint---</pre>");
			print("</pre>");
		}
	}
}

function get_debug_print($var, $note = null)
{
	ob_start();
	eval(debug_print($var));
	$x = ob_get_contents();
	ob_end_clean();

	return $x;
}

function lx_local_socket_read($socket)
{
	return @ socket_read($socket, 2048);
	//$res=socket_recv($MsgSock,$buffer,1024,0);
}

function csa($haystack, $needle, $insensitive = 0)
{
	return char_search_a($haystack, $needle, $insensitive);
}

function char_search_a($haystack, $needle, $insensitive = 1)
{

	if (is_array($haystack)) {
		//dprint("Got array in Char Search ");
		//dprintr($haystack);
	}

	if (is_object($haystack)) {
		$v = debugBacktrace(true);
		log_log("error", $v);
		return false;
	}
	if (is_array($haystack)) {
		return false;
	}
	$haystack = (string) $haystack;
	$needle = (string) $needle;
	if ($insensitive) {
		return (false !== stristr($haystack, $needle)) ? true : false;
	} else {
		return (false !== strpos($haystack, $needle)) ? true : false;
	}
}

function strtil($string, $needle)
{
	if (strrpos($string, $needle) !== false) {
		return substr($string, 0, strrpos($string, $needle));
	} else {
		return $string;
	}
}

function strtilfirst($string, $needle)
{
	if (strpos($string, $needle)) {
		return substr($string, 0, strpos($string, $needle));
	} else {
		return $string;
	}
}

function strfrom($string, $needle)
{
	if (!csa($string, $needle)) {
		return $string;
	}
	return substr($string, strpos($string, $needle) + strlen($needle));
}

function array_push_unique($array, $value)
{
	if (!$array) {
		$array = array();
	}
	foreach ($array as $var) {
		if ($var === $value) {
			return $array;
		}
	}
	$array[] = $value;
	return $array;
}

function array_remove($array, $element)
{
	$ret = null;
	foreach ($array as $value) {
		if ($value !== $element) {
			$ret[] = $value;
		}
	}
	return $ret;
}

function csb($haystack, $needle, $insensitive = 1)
{ # Char Search Begin
	return char_search_beg($haystack, $needle, $insensitive);
}

function char_search_beg($haystack, $needle, $insensitive = 1)
{
	if (is_array($haystack) || is_object($haystack) || $haystack === null) {
		return false;
	}
	$haystack = (string) $haystack;
	$needle = (string) $needle;
	if ($needle === '') {
		return true;
	}
	if (strpos($haystack, $needle) === 0) {
		return true;
	}
	return false;
}

function cse($haystack, $needle, $insensitive = 1)
{ # Char Search End
	return char_search_end($haystack, $needle, $insensitive);
}

function char_search_end($haystack, $needle, $insensitive = 1)
{
	if (is_array($haystack) || is_object($haystack) || $haystack === null) {
		return false;
	}
	$haystack = (string) $haystack;
	$needle = (string) $needle;
	if ($needle === '') {
		return true;
	}
	if (strpos($haystack, $needle) === false) {
		return false;
	}

	if ((strrpos($haystack, $needle) + strlen($needle)) === strlen($haystack)) {
		return true;
	} else {
		return false;
	}
}

function array_search_bool($needle, $haystack, $str=false)
{
	if (!$haystack) {
		return false;
	}

	if(!is_array($haystack)) {
		return false;
	}

	if (array_search($needle, $haystack, $str) !== false) {
		return true;
	}

	return false;
}

function isLicensed($var)
{
	global $gbl, $sgbl, $login, $ghtml;

	if ($var == 'lic_client') {
		return true;
	}

	$lic = $login->getObject('license')->licensecom_b;
	if (!isset($lic->$var)) {
		return false;
	}
	return isOn($lic->$var);
}

function is_composite($class)
{
	return false;
	return csa($class, "__");
}

function get_composite($class)
{
	return array(null, null, $class);

	$list = explode("__", $class);

	if (count($list) === 2) {
		return array($list[0], null, $list[1]);
	}

	return array($list[0], $list[1], $list[2]);
}

function setLicenseTodefault()
{
	global $gbl, $sgbl, $login, $ghtml;
	$license = $login->getObject('license');
	$license->parent_clname = $login->getClName();
	$lic = $license->licensecom_b;
	$def = array("maindomain_num" => "Unlimited", "vps_num" => "Unlimited", "pserver_num" => "Unlimited", "client_num" => "Unlimited");
	$list = get_license_resource();
	foreach ($list as $l) {
		$licv = "lic_$l";
		$lic->$licv = $def[$l];
	}
	$license->setUpdateSubaction();
	$license->write();
}

function decodeAndStoreLicense($ip, $license_content)
{
	global $gbl, $sgbl, $login, $ghtml;

	$license = $login->getObject('license');
	$license->parent_clname = $login->getClName();
	$lic = $license->licensecom_b;
	$get = licenseDecrypt($license_content);
	$license->text_license_content = $license_content;

	if (!$get) {
		throw new lxException($login->getThrow("could_not_decrypt_license"));
	}
	$get = 'licence.php?' . $get;
	$ghtml->get_post_from_get($get, $path, $post);

	if (!isset($post['maindomain_num'])) {
		$post['maindomain_num'] = $post['domain_num'];
	}

	if ($sgbl->isDebug()) {
		$post['maindomain_num'] = '1000';
	}

	foreach ($post as $k => $v) {
		$var = "lic_" . $k;
		$lic->$var = $v;
	}

	$lic->lic_ipaddress .= " ($ip)";

	$prilist = $login->getQuotaVariableList();

	foreach ($prilist as $k => $v) {
		if (cse($k, "_flag")) {
			$login->priv->$k = 'On';
		} else if (cse($k, "_usage")) {
			$login->priv->$k = 'Unlimited';
		} else if (cse($k, "_num")) {
			$login->priv->$k = 'Unlimited';
		}
	}

	$login->priv->client_num = $post['client_num'];

	if (isset($post['maindomain_num'])) {
		$login->priv->maindomain_num = $post['maindomain_num'];
	}

	if (isset($post['vps_num'])) {
		$login->priv->vps_num = $post['vps_num'];
	}

	$login->priv->pserver_num = $post['pserver_num'];
	$login->setUpdateSubaction();
	$login->write();

	$license->setUpdateSubaction();
	$license->write();
}

function remove_dot_dot($list)
{
	foreach ($list as $k => $v) {
		if ($v === "." || $v === "..") {
			unset($list[$k]);
		}
	}
	return $list;
}

function lx_tmp_file($file)
{
	global $gbl, $sgbl, $login, $ghtml;
	$file = expand_real_root($file);
	$n = preg_replace("+/+i", "_", $file);
	return tempnam("$sgbl->__path_tmp/", "lxtmp_$n");
	//return "/tmp/" . $n;
}

function lx_array_keys($list)
{
	if (!$list) {
		$list = array();
	}
	return array_keys($list);
}

function array_filter_key($full, $need)
{
	if (!$need) {
		return $full;
	}

	foreach ($full as $key => $value) {
		if (array_search_bool($key, $need)) {
			$ret[$key] = $value;
		}
	}
	return $ret;
}

function gethtmllibversion()
{
}

function isOn($var)
{
	return (($var === 'on') || ($var === 'On')) ? true : false;
}

// Function that is called to test whther the remote server is working fine or not. Used while adding.
function test_remote_func()
{
	return true;
}

function log_clicks($mess, $id = 1)
{
	global $gbl, $sgbl, $login, $ghtml;

	$ip = $gbl->c_session->ip_address;
	$mess = trim($mess);
	$file = "__path_program_root/log/clicks";
	lfile_put_contents($file, "$id: $ip: " . @date("H:i:s M/d/Y") . ": $mess\n", FILE_APPEND);
}

# Version Comparison Returns 1 if version1 is greater, and -1 if version2 is greater.
function version_cmp($version1, $version2)
{
	$l1 = explode(".", $version1);
	$l2 = explode(".", $version2);

	for ($i = 0; $i < 3; $i++) {
		if ($l2[$i] === $l1[$i]) {
			continue;
		}
		if ($l1[$i] > $l2[$i]) {
			return 1;
		}
		if ($l1[$i] < $l2[$i]) {
			return -1;
		}
	}
}

function app_version_cmp($version1, $version2)
{
	$l1 = explode(".", $version1);
	$l2 = explode(".", $version2);

	for ($i = 0; $i < 4; $i++) {
		if (!isset($l2[$i])) {
			$l2[$i] = 0;
		}
		if (!isset($l1[$i])) {
			$l1[$i] = 0;
		}
	}

	//dprintr($l1);
	//dprintr($l2);

	for ($i = 0; $i < 4; $i++) {
		if ($l2[$i] === $l1[$i]) {
			continue;
		}
		if ($l1[$i] > $l2[$i]) {
			return 1;
		}
		if ($l1[$i] < $l2[$i]) {
			return -1;
		}
	}
}

function fput_content_with_lock($file, $string)
{
	lfile_put_contents($file, $string);
}

function filter_object_list($list, $rule)
{
	$nlist = null;
	foreach ((array) $list as $o) {
		if ($o->eeval($rule)) {
			$nlist[$o->nname] = $o;
		}
	}
	return $nlist;
}

function is_assoc_array($var)
{
	if (!is_array($var)) {
		return false;
	}
	return array_keys($var) !== range(0, sizeof($var) - 1);
}

function kloxo_skip_find_class($class)
{
	$c = strtolower((string)$class);
	static $skip = array(
		'llog', 'driver', 'phpini', 'lxbackup', 'serverweb', 'serverftp',
		'sshconfig', 'lxguard', 'ufwfirewall', 'fail2ban', 'sshclient', 'ffile', 'ticket', 'notification',
		'lxupdate', 'dns', 'dns_record_a', 'web', 'servermail', 'mail_graylist_wlist_a',
		'lxguardhitdisplay', 'rawlxguardhit', 'lxguardwhitelist', 'lxguardhit',
		'dbadmin',
	);

	return in_array($c, $skip, true);
}

/**
 * Pages that drop the left Find box on their own show page only (their list
 * pages and child lists keep the search toolbar, unlike kloxo_skip_find_class).
 */
function kloxo_skip_find_show_class($class)
{
	static $skip = array('mailaccount');

	return in_array(strtolower((string)$class), $skip, true);
}

function kloxo_string($value)
{
	if (is_string($value)) {
		return $value;
	}
	if ($value === null || $value === false) {
		return '';
	}
	if (is_scalar($value)) {
		return (string)$value;
	}

	return '';
}

function kloxo_implode($separator, $list)
{
	$sep = is_string($separator) ? $separator : ',';
	if (is_array($list)) {
		return implode($sep, $list);
	}
	if ($list === null || $list === false) {
		return '';
	}

	return (string)$list;
}

function kloxo_list_from($v)
{
	if (is_array($v)) {
		$out = array();
		foreach ($v as $p) {
			if (is_string($p) || is_numeric($p)) {
				$p = trim((string)$p);
				if ($p !== '') {
					$out[] = $p;
				}
			}
		}

		return $out;
	}
	if (!is_string($v) || trim($v) === '') {
		return array();
	}
	$out = array();
	foreach (explode(',', str_replace(' ', '', $v)) as $p) {
		$p = trim($p);
		if ($p !== '') {
			$out[] = $p;
		}
	}

	return $out;
}

function get_namelist_from_objectlist($ol, $key = null, $val = null)
{
	if (!is_array($ol) && !($ol instanceof Traversable)) {
		return array();
	}

	$name = array();
	if (!$key) {
		$key = "nname";
	}

	if ($val === null) {
		$val = $key;
	}

	foreach ($ol as $o) {
		if (!is_object($o)) {
			continue;
		}
		$name[$o->$key] = $o->display($val);
	}
	return $name;
}

function convert_to_associate($ar)
{
	foreach ($ar as $k => $v)  $ret[$v] = $v;
	return $ret;
}

function get_namelist_from_arraylist($ol, $key = null, $val = null)
{
	$name = array();
	if (!$key) {
		$key = "nname";
	}

	if ($val === null) {
		$val = $key;
	}

	foreach ((array) $ol as $o) $name[$o[$key]] = $o[$val];

	return $name;
}

function getNumericValue($var)
{
	// MR -- back to calculate

	$list = array("GB" => 1048576, "MB" => 1024, "KB" => 1, "%" => 1);

	foreach ($list as $k => $v) {
		if (strpos($var, " {$k}") !== false) {
			$var = (float)$var * $v;
			return $var;
		}
	}

	return $var;
}

/**
 * Quota counters (priv/used) are stored as strings: '', 'Unlimited', '10', '1024 MB'.
 * PHP 8 throws "Unsupported operand types: string + int" for '' + 1, so every
 * arithmetic on a quota value goes through this. Non-numeric text counts as 0.
 *
 * @param mixed $v
 * @return int|float
 */
function kloxo_quota_num($v)
{
	if (is_int($v) || is_float($v)) {
		return $v;
	}

	if (is_bool($v)) {
		return $v ? 1 : 0;
	}

	if (!is_string($v)) {
		return 0;
	}

	$v = trim($v);

	if ($v === '') {
		return 0;
	}

	if (!is_numeric($v)) {
		$v = getNumericValue($v);
		if (!is_int($v) && !is_float($v) && !is_numeric($v)) {
			return 0;
		}
	}

	$n = $v + 0;

	return $n;
}

function isQuotaGreaterThanOrEq($used, $priv)
{
	if (is_unlimited($priv)) {
		return false;
	}
	if (is_unlimited($used)) {
		return true;
	}
	if (isOn($priv)) {
		return false;
	}
	if (isOn($used)) {
		return true;
	}

	$used = getNumericValue($used);
	$priv = getNumericValue($priv);

	return ((float)$used >= (float)$priv) ? true : false;
}

function isQuotaGreaterThan($used, $priv)
{
	if (is_unlimited($priv)) {
		return false;
	}
	if (is_unlimited($used)) {
		return true;
	}
	if (isOn($priv)) {
		return false;
	}
	if (isOn($used)) {
		return true;
	}

	$used = getNumericValue($used);
	$priv = getNumericValue($priv);

	return ((float)$used > (float)$priv) ? true : false;
}

function is_unlimited($val)
{
	if (strtolower($val) === 'unlimited' || strtolower($val) === 'na') {
		return true;
	}
	return false;
}

function if_demo_throw()
{
	global $login;

	if (if_demo()) {
		throw new lxException ($login->getThrow("demo"));
	}
}

function if_demo()
{
	global $gbl, $sgbl, $g_demo;
	return $g_demo;
}

function lx_phpdebug()
{
	global $gbl, $sgbl;

	if ($sgbl->dbg <= 0) {
		return;
	}
	if (!lfile_exists("/tmp/.php_debug")) {
		return;
	}

	$fp = lfopen("/tmp/.php_debug", "r");
	$s = fgets($fp, 1024);
	fclose($fp);

	$s = preg_replace("/--noraise /i", "", $s);
	$arr = parse_url($s);
	parse_str($arr['query'], $out);

	$ghtml->print_input("hidden", "start_debug", "1");
	$ghtml->print_input("hidden", "debug_port", $out['debug_port']);
	$ghtml->print_input("hidden", "debug_text_mode", "1");
	$ghtml->print_input("hidden", "debug_no_cache", "1095197145");
}

function create_simpleObject($arglist)
{
	$obj = new Remote();

	foreach ($arglist as $k => $v) {
		$obj->$k = $v;
	}
	return $obj;
}

function lx_sync()
{
	global $gbl, $sgbl, $login;
	$login->was();
}

function get_current_file()
{
	$n = basename(dirname($_SERVER['PHP_SELF']));
	return $n;
}

function array_flatten($a, $pref = null)
{
	$ret = array();
	foreach ($a as $i => $j) {
		if (is_array($j)) {
			$ret = array_merge($ret, array_flatten($j, "$pref$i"));
		} else {
			$ret["$pref$i"] = $j;
		}
	}
	return $ret;
}

function get_general_image_path($v = null)
{

	return add_http_host("/theme/general/$v");
}

/**
 * @return void
 * @param
 * @param
 * @desc  part of the getting-image-through-http (to enable caching) madness.
 */
function add_http_host($elem)
{
	global $gbl, $sgbl, $login, $ghtml;
	return $elem;

	$host = $_SERVER['SERVER_NAME'];
	//$port = $sgbl->__var_prog_port;
	//$host = "http://" . $host . ":" .  "$port";
	//$host = "https://" . $host . ":" .  "$port";
	return $host . $elem;
}

function get_image_path($path = null)
{
	global $login;

	$icon = 'collage';
	try {
		if (is_object($login) && method_exists($login, 'getSpecialObject')) {
			$sp = $login->getSpecialObject('sp_specialplay');
			if (is_object($sp) && !empty($sp->icon_name)) {
				$icon = (string) $sp->icon_name;
			}
		}
	} catch (Throwable $e) {
	}
	$path = is_string($path) ? $path : '';

	return "/theme/icon/{$icon}/{$path}";
}

function randomString($length)
{
/*
	$randstr = '';

	$chars = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C',
		'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P',
		'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z');

	for ($rand = 0; $rand <= $length; $rand++) {
		$random = rand(0, count($chars) - 1);
		$randstr .= $chars[$random];
	}

	return $randstr;
*/
	$key = '';

	$keys = array_merge(range(0, 9), range('a', 'z'), range('A', 'Z'));

	for ($i = 0; $i < $length; $i++) {
			$key .= $keys[array_rand($keys)];
	}

	return $key;
}

function DBG_GetBacktrace($traceArr)
{
	if ($sgbl->dbg < 0) {
		return;
	}

	$s = '';
	$MAXSTRLEN = 64;

	$s = '<pre align=left>';
	array_shift($traceArr);
	$tabs = sizeof($traceArr) - 1;
	foreach ($traceArr as $arr) {
		for ($i = 0; $i < $tabs; $i++) {
			$s .= ' &nbsp; ';
		}

		$tabs -= 1;
		if (isset($arr['class'])) {
			$s .= $arr['class'] . '.';
		}
		$args = array();
		foreach ((array) $arr['args'] as $v) {
			if (is_null($v)) {
				$args[] = 'null';
			} else {
				if (is_array($v)) {
					$args[] = 'Array[' . sizeof($v) . ']';
				} else {
					if (is_object($v)) {
						$args[] = 'Object:' . get_class($v);
					} else {
						if (is_bool($v)) {
							$args[] = $v ? 'true' : 'false';
						} else {
							$v = (string) @$v;
							$str = kloxo_html(substr($v, 0, $MAXSTRLEN));
							if (strlen($v) > $MAXSTRLEN) $str .= '...';
							$args[] = "\"" . $str . "\"";
						}
					}
				}
			}
		}
		$s .= $arr['function'] . '(' . implode(',', $args) . ')';
		$Line = (isset($arr['line']) ? $arr['line'] : "unknown");
		$File = (isset($arr['file']) ? $arr['file'] : "unknown");
		$s .= sprintf("# line %4d, file: <a href=\"file:/%s\">%s</a>", $Line, $File, $File);
		$s .= "\n";
	}
	$s .= '</pre>';
	return $s;
}

function getInfo($b)
{
	if (is_object($b) && is_subclass_of($b, 'lxclass')) {
		return $b->get__table() . ':' . $b->nname;
	} else {
		return $b;
	}
}

function backtrace_once()
{
	global $gbl, $sgbl, $login, $ghtml;
	if ($sgbl->dbg < 2) {
		return;
	}

	$v = debug_backtrace();
	$count = 0;
	$string = null;
	foreach ($v as $q) {
		$count++;
		if ($count === 1) {
			continue;
		}

		if ($count > 2) break;
		if ($count === 2 && (basename($q['file']) === 'sqlitelib.php')) {
			return null;
			continue;
		}
		$string .= $q['file'] . ":" . $q['line'] . ": " . $q['function'] . '(';
		if (isset($q['args'])) {
			foreach ($q['args'] as $a) {
				if (is_array($a)) {
					foreach ($a as $b) {
						if (is_array($b)) {
							foreach ($b as $c) {
								$string .= ', ' . getInfo($c);
							}
						} else {
							$string .= ', ' . getInfo($b);
						}
					}
				} else {
					$string .= $a;
				}
			}
		}
		$string .= ")<br>\n";
	}

	return $string;
}

function debugBacktrace($flag = false)
{
	global $gbl, $sgbl, $login, $ghtml;
	$string = null;

	if ($sgbl->dbg < 2) {
		return;
	}
	$v = debug_backtrace();
	foreach ($v as $q) {
		$string .= $q['file'] . ":" . $q['line'] . ": " . $q['function'] . '(';
		if (isset($q['args'])) {
			foreach ($q['args'] as $a) {
				if (is_array($a)) {
					foreach ($a as $b) {
						if (is_array($b)) {
							foreach ($b as $c) {
								$string .= ', ' . getInfo($c);
							}
						} else {
							$string .= ', ' . getInfo($b);
						}
					}
				} else {
					if (is_string($a)) {
						$string .= $a;
					}
				}
			}
		}
		$string .= ")<br>\n";
	}
	if ($flag) {
		return $string;
	}

	dprintr($string);
}

function lx_strip_tags($str)
{
	$nstr = strip_tags($str);

	$nstr = preg_replace("/\s+/", " ", $nstr);
	return $nstr;
}

class Language_Mes
{

}

function get_language()
{
	global $gbl, $sgbl, $login, $ghtml;
	if (is_object($login) && isset($login->getSpecialObject('sp_specialplay')->language)) {
		$lan = $login->getSpecialObject('sp_specialplay')->language;
	} else {
		$lan = 'en';
	}

	// MR -- change en to en-us
	if ($lan === 'en') { $lan = 'en-us'; }

	return $lan;
}

function get_charset()
{
	$lang = get_language();
	$charset = @ lfile_get_contents("lang/$lang/charset");
	$charset = trim($charset);
	return $charset;
}

function print_meta_lan()
{
	global $gbl, $sgbl, $login, $ghtml;
	$lan = get_language();
	$charset = @ lfile_get_contents("lang/$lan/charset");
	$charset = trim($charset);
//	print("<head>");
	if ($charset) {
		print("<meta http-equiv=\"Content-Type\" content=\"text/html; charset=$charset\" />");
	} else {
		print("<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />");
	}
}

function init_language()
{
	global $gbl, $sgbl, $login, $ghtml;
	global $g_language_mes, $g_language_desc;

	$language = get_language();

	if (lxfile_exists("lang/$language/messagelib.php")) {
		include_once("lang/$language/messagelib.php");
	} else {
		include_once("lang/en-us/messagelib.php");
	}

	if (lxfile_exists("lang/$language/langfunctionlib.php")) {
		include_once("lang/$language/langfunctionlib.php");
	} else {
		include_once("lang/en-us/langfunctionlib.php");
	}

	if (lxfile_exists("lang/$language/langkeywordlib.php")) {
		include_once("lang/$language/langkeywordlib.php");
	} else {
		include_once("lang/en-us/langkeywordlib.php");
	}

	if (lxfile_exists("lang/$language/desclib.php")) {
		include_once("lang/$language/desclib.php");
	} else {
		include_once("lang/en-us/desclib.php");
	}

	$g_language_mes = new Language_Mes();
	$g_language_mes->__information = $__information;
	$g_language_mes->__emessage = $__emessage;
	$g_language_mes->__keyword = $__keyword;

/*
	$g_language_mes->__help = $__help;
	$g_language_mes->__helpvar = $__helpvar;
*/
	// __help and __helpvar until 6.1.6 doesn't exist, so make choice
	$g_language_mes->__help = (isset($__help)) ? $__help : '';
	$g_language_mes->__helpvar = (isset($__helpvar)) ? $__helpvar : '';

	$g_language_mes->__throw = $__throw;

	$g_language_desc = new Remote();
	$g_language_desc->__description = $__description;
}

/*
	MR - this function trouble if running on php 7.0+
	TODO: still using til Kloxo CP using php70s+
*/
function lx_error_handler($errno, $errstr, $file, $line)
{
	global $gbl, $sgbl, $login, $ghtml;
	global $last_error;

	$last_error = $errstr;

	if ($sgbl->dbg < 0) {
		return;
	}

	if (error_reporting() === 0) {
		return;
	}

	static $error = "";
	$pos = "$file:$line: $errstr";
	$error .= $pos . "\n";
	lfile_put_contents(kloxo_php_error_log_path(), $error);

	dprint("\n### PHP Error detected\n");
	dprint("### Notice: $errstr\n");
       dprint("### File:$file\n");
       dprint("### Line number: $line\n");
	dprint("### End PHP Error information\n\n");
}

/**
 * @return void
 * @param
 * @param
 * @desc Truly random and insane way to encrypt the image/form names so that hackers won't easily understand teh program structure, (which is clearly visible in these names...)
 */
function createEncName($name)
{
	global $gbl;

	return $name;

	if ($sgbl->dbg > 0) {
		return $name;
	}

	$name = str_replace("_", "", $name);
	$name = str_replace("php", "", $name);
	$name = str_replace("a", "r", $name);
	$name = str_replace("e", "z", $name);
	$name = str_replace("i", "q", $name);
	$name = str_replace("o", "j", $name);
	$name = str_replace("t", "y", $name);
	$name = str_replace("s", "x", $name);
	$name = str_replace("r", "p", $name);

	return $name;
}

function kloxo_password_hash($plain)
{
	if (!is_string($plain) || $plain === '') {
		return '';
	}
	if (defined('PASSWORD_ARGON2ID')) {
		$h = password_hash($plain, PASSWORD_ARGON2ID);
		if (is_string($h) && $h !== '') {
			return $h;
		}
	}
	$h = password_hash($plain, PASSWORD_DEFAULT);
	if (is_string($h) && $h !== '') {
		return $h;
	}
	return crypt($plain, '$1$'.randomString(8).'$');
}

function check_password($unenc, $enc)
{
	if (!is_string($unenc) || !is_string($enc) || $unenc === '' || $enc === '') {
		return false;
	}
	$info = function_exists('password_get_info') ? password_get_info($enc) : null;
	if (is_array($info) && isset($info['algo']) && $info['algo'] !== 0 && $info['algo'] !== null) {
		return password_verify($unenc, $enc);
	}
	return hash_equals($enc, (string)crypt($unenc, $enc));
}
/*
	MR - this function trouble if running on php 7.0+
	TODO: still using til Kloxo CP using php70s+
*/
function lx_exception_handler($e)
{
	global $gbl, $sgbl, $login, $ghtml;

	// MariaDB down: point at the recovery console (it needs no database)
	// instead of a bare "No such file or directory".
	if (class_exists('KloxoRecovery', false) && KloxoRecovery::isDatabaseDown($e)
		&& PHP_SAPI !== 'cli' && !headers_sent()) {
		http_response_code(503);
		header('Cache-Control: no-store');
		print(KloxoRecovery::databaseDownHtml());
		return;
	}

	$var = (is_object($e) && isset($e->variable)) ? $e->variable : '';
	$val = (is_object($e) && isset($e->value)) ? $e->value : '';
	print("Error message: {$e->getMessage()} {$var} {$val}\n");

	if ($sgbl->dbg <= 0) {
		return;
	}
	$tr = $e->getTrace();
	$trace = "";
	foreach ($tr as $a) {
		$trace .= $a["file"] . ":" . $a["line"] . ":";
		if (isset($a["class"])) {
			$trace .= $a["class"] . ":";
		}
		$trace .= $a["function"] . "(";
		$trace .= ")\n";
	}
	lfile_put_contents(kloxo_php_error_log_path(), $trace);
}

function check_raw_password($class, $client, $pass)
{
	//return true;

	if (!$class || !$client || !$pass) {
		return false;
	}

	// MR -- sanitize input
	if ((stripos($class, "'") !== false) || 
			(stripos($client, "'") !== false) || 
			(stripos($pass, "'") !== false)) {
		return false;
	}

	// MR -- sanitize input
	if ((stripos($class, "\"") !== false) || 
			(stripos($client, "\"") !== false) || 
			(stripos($pass, "\"") !== false)) {
		return false;
	}

	$rawdb = new Sqlite(null, $class);
	$password = $rawdb->rawquery("select password from $class where nname = '$client'");
	if (!is_array($password) || !isset($password[0]['password'])) {
		return false;
	}
	$enp = $password[0]['password'];

	if ($enp && check_password($pass, $enp)) {
		return true;
	}
	return false;
	//$rawdb->close();
}

/**
 * @return void
 * @param
 * @param
 * @desc  Checks if the client is disabled and exits immedeiately showing a message.
 */
function check_if_disabled_and_exit()
{
	global $gbl, $sgbl, $login, $ghtml;

	$contact = "administrator";

	if ($login->isAdmin()) { return; }

	if (!$login->isOn('cpstatus')) {

		Utmp::updateUtmp($gbl->c_session->nname, $login, 'disabled');
		$ghtml->print_css_source("/theme/css/common.css");

		if ($sgbl->isLxlabsClient()) {
			$ghtml->__http_vars['frm_emessage'] = "This login has been Disabled due to non-payment. Please pay the invoice below, and your account will automatically get enabled.";
			$ghtml->print_message();
			$login->print_invoice();
		} else {
			$ghtml->__http_vars['frm_emessage'] = "This login has been Disabled. Please contact the $contact";
			$ghtml->print_message();
		}

		$gbl->c_session->delete();
		$gbl->c_session->was();
		exit(0);

	}

}

function delete_expired_ssessions()
{
	global $gbl, $sgbl, $login, $ghtml;

	$s_l = $login->getList("ssessionlist");

	if(!empty($s_l)){
		foreach ($s_l as $s) {
			if (!is_object($s)) {
				continue;
			}
			$timeout = $s->last_access + $login->getSpecialObject('sp_specialplay')->ssession_timeout;
			dprint($s->nname);
			if ($timeout < time()) {
				$s->delete();
				Utmp::updateUtmp($s->nname, $login, "Session Expired");
			}
		}
	}
}

function createTreeObject($name, $img, $imgstr, $url, $open, $help, $alt)
{
	static $val;

	$imgstr = str_replace("'", "\'", $imgstr);
	$help = str_replace("'", "\'", $help);
	$alt = str_replace("'", "\'", $alt);
	$img = str_replace("'", "\'", $img);
	$val++;
	$name = $name . $val;
	$tobj = new Tree(null, null, $name);
	$tobj->img = $img;
	$tobj->imgstr = $imgstr;
	$tobj->url = $url;
	$tobj->open = $open;
	$tobj->help = $help;
	$tobj->alt = $alt;
	return $tobj;
}

/**
 * @return void
 * @param
 * @param
 * @desc A generic function, that can be used by all programs. Does all the basic login stuff.
 */
function initProgramlib($ctype = null)
{
	global $gbl, $sgbl, $login, $ghtml;

// /*
	// --- solution for some scripts running well in slave
	// REVERT - back to original
	if ($sgbl->is_this_slave()) {
		print("This is a Slave Server. Operate it at the Master server.\n");
		exit;
	}
// */
	static $var = 0;
	$var++;

	$progname = $sgbl->__var_program_name;
	// MR -- disable because trouble in php7
	set_exception_handler("lx_exception_handler");
//	xdebug_disable();
	// MR -- disable because trouble in php7
	set_error_handler("lx_error_handler");

//	setcookie("XDEBUG_SESSION", "sess");

	if ($var >= 2) {
		dprint("initProgramlib called twice \n <br> ");
	}

	if ($ctype === 'superadmin') {
		$sgbl->__var_dbf = $sgbl->__path_supernode_db;
		$sgbl->__path_admin_pass = $sgbl->__path_super_pass;
		$sgbl->__var_admin_user = $sgbl->__var_super_user;
		$login = new SuperClient(null, null, 'superadmin', 'login', 'forced');
		$login->get();
		return;
	} else if ($ctype === "guest") {
		$login = new Client(null, null, "____________", "guest");
		$login->get();
		return;
	} else if ($ctype != "") {
		$login = new Client(null, null, $ctype, "login", "forced");
		$login->get();
		return;
	}

	$sessobj = null;
	if ($ghtml->frm_consumedlogin === 'true') {
		$clientname = $_COOKIE["$progname-consumed-clientname"];
		$classname = $_COOKIE["$progname-consumed-classname"];
		$session_id = $_COOKIE["$progname-consumed-session-id"];
		get_login($classname, $clientname);
		$login->__session_id = $session_id;
		$sessobj = $login->getObject('ssession');
	} else {
		if (isset($_COOKIE["$progname-session-id"])) {
			$clientname = $_COOKIE["$progname-clientname"];
			$classname = $_COOKIE["$progname-classname"];
			$session_id = $_COOKIE["$progname-session-id"];
			if ($classname === 'superclient') {
				$sgbl->__var_dbf = $sgbl->__path_supernode_db;
				$sgbl->__path_admin_pass = $sgbl->__path_super_pass;
				$sgbl->__var_admin_user = $sgbl->__var_super_user;
			}

			if ($classname === 'slave') {
				$sgbl->__var_dbf = $sgbl->__path_slave_db;
			}
			if ($classname) {
				get_login($classname, $clientname);
				$login->__session_id = $session_id;
				$sessobj = $login->getObject('ssession');
			}
		}
	}

	if (!$sessobj || $sessobj->dbaction === 'add') {
		if ($ghtml->frm_ssl) {
			$ssl = unserialize(base64_decode($ghtml->frm_ssl));
			$string = $ssl['string'];
			$ssl_param = $ssl['ssl_param'];
			$encrypted_string = base64_decode($ssl['encrypted_string']);
			if (!$string || !checkPublicKey($string, $encrypted_string)) {
				print("SSL Connection Failed <br> \n");
				exit;
			}
			$class = 'client';
			$clientname = 'admin';
			get_login($class, $clientname);
			do_login($class, $clientname, $ssl_param);
			$sessobj = $gbl->c_session;
			$sessobj->write();
			$sessobj->dbaction = 'clean';
		}
	}

	//get_savedlogin($classname, $clientname);
	//print_time('login_get', "Login Get");
	//dprintr($login);

   //avoid some php warnings
   if (isset($login)) {
      $gbl->client = $login->nname;
      $gbl->client_ttype = $login->cttype;
   }

	//dprintr($login->hpfilter);

	// This means the session object got created fresh.
	if (!$sessobj || $sessobj->dbaction === 'add') {
		dprint("Session id is empty. Clearing cookies and redirect to login.");
		clear_all_cookie();
		$ghtml->print_redirect_self("/login/");
	}

	$gbl->c_session = $sessobj;

	if ($login->getClName() !== $sessobj->parent_clname) {
		dprint_r($login->ssession_l);
		dprint(" <br> $session_id <br> <br> <br> ");
		print("Session error! Login again.");
		clear_all_cookie();
		$ghtml->print_redirect_self("/login/?frm_emessage=sessionname_not_client");
	}

	$gen = $login->getObject('general')->generalmisc_b;

	if (!$gen->isOn('disableipcheck') && $_SERVER['REMOTE_ADDR'] != $sessobj->ip_address) {
		$hostname = gethostbyaddr($_SERVER['REMOTE_ADDR']);
		log_message("An attempt to hack from $hostname (" . $_SERVER['REMOTE_ADDR'] . ") with sess $sessobj->nname, session ip: $sessobj->ip_address");

		if ($gen->isOn('disableipcheck')) {
		} else {
			clear_all_cookie();
			if ($gbl->c_session->ssl_param) {
				$sessobj->delete();
				$sessobj->write();
				$ghtml->print_redirect("{$gbl->c_session->ssl_param['backurl']}&frm_emessage=ipaddress_changed_amidst_session");
			} else {
				$ghtml->print_redirect_self("/login/?frm_emessage=ipaddress_changed_amidst_session");
			}
		}
	}

	if (intval($login->getSpecialObject('sp_specialplay')->ssession_timeout) <= 100) {
		$login->getSpecialObject('sp_specialplay')->ssession_timeout = 100;
		$login->setUpdateSubaction();
	}

	$timeout = $sessobj->last_access + $login->getSpecialObject('sp_specialplay')->ssession_timeout;
	$sessobj->timeout = $timeout;
	//$timeout  =  $sessobj->last_access + 4;
	$sessobj->last_access = time();
	$sessobj->setUpdateSubaction();

	if ($sessobj->auxiliary_id) {
		$aux = new Auxiliary(null, null, $sessobj->auxiliary_id);
		$aux->get();
		$login->__auxiliary_object = $aux;
	}

	if (time() > $timeout) {
		$sessobj->delete();
		$sessobj->write();
		//print("session error timeout");
		if ($gbl->c_session->ssl_param) {
			$ghtml->print_redirect("{$gbl->c_session->ssl_param['backurl']}&frm_emessage=session_timeout");
		} else {
			$ghtml->print_redirect_self("/login/?frm_emessage=session_timeout");
		}
	}

	addToUtmp($sessobj, 'update');
}

function clear_all_cookie()
{
	global $gbl, $sgbl, $login, $ghtml;
	$progname = $sgbl->__var_program_name;

	$search = $progname;
	if ($ghtml->frm_consumedlogin === 'true') {
		$search .= "-consumed";
	}

	foreach ($_COOKIE as $k => $v) {
		if (csb($k, $search)) {
			setcookie($k, "", time() - 360000000);
		}
	}
}

function checkPublicKey($string, $encrypted_string)
{
	$res = lfile_get_contents("__path_program_root/etc/authorized_keys");
	$public_key = $res;
	$pubkey_res = openssl_get_publickey($public_key);
	openssl_public_decrypt($encrypted_string, $decrypted_string, $public_key);
	if ($decrypted_string === $string) {
		return true;
	}

	return false;
}

function initSession($object, $ssl_param, $consuming_parent)
{
	global $gbl, $sgbl, $login, $ghtml;
	$progname = $sgbl->__var_program_name;

	$session = randomString(50);

	//clear_all_cookie();
	// Making cookie persistent, otherwise IE will not pass it to new windows. Needed in the file selector. Set the expiration to 10 years in the future. Needed for brain damaged ie, which cannot recognize server time.
	$cookietime = time() + 24 * 60 * 60 * 365 * 80;
	//$cookietime = 0;
	header('P3P: CP="CAO PSA OUR"');

	$ckstart = $progname;

	if ($consuming_parent) {
		$ckstart .= "-consumed";
	}

	if ($object->isAuxiliary()) {
		$name = $object->__auxiliary_object->nname;
		$class = $object->__auxiliary_object->getClass();
	} else {
		$name = $object->nname;
		$class = $object->getClass();
	}

	setcookie("$ckstart-clientname", $name, $cookietime, '/');
	setcookie("$ckstart-classname", $class, $cookietime, '/');
	setcookie("$ckstart-session-id", $session, $cookietime, '/');

	dprint("Set cookies<br/>");

	$hostname = $_SERVER['REMOTE_ADDR'];

	$sessobj = new Ssession(null, null, $session);
	$sessa['nname'] = $session;
	$sessa['ip_address'] = $_SERVER['REMOTE_ADDR'];
	$sessa['cttype'] = $object->getLoginType();
	$sessa['hostname'] = $hostname;
	$sessa['tsessionid'] = randomString(30);
	// Login time is set to null. This is set to the correct time inside display.php. This allows us to figure out the first run of display.php.
	$sessa['logintime'] = time();
	$sessa['ssession_vars'] = array();
	$sessa['http_vars'] = array();
	$sessa['parent_clname'] = $object->getClName();
	$sessa['consuming_parent'] = $consuming_parent;
	$sessa['auxiliary_id'] = $object->getAuxiliaryId();
	$sessa['ssl_param'] = $ssl_param;

	if (intval($object->getSpecialObject('sp_specialplay')->ssession_timeout) <= 100) {
		$timeout = 100;
	} else {
		$timeout = $object->getSpecialObject('sp_specialplay')->ssession_timeout;
	}

	$sessa['timeout'] = time() + $timeout;
	$sessa['last_access'] = time();
	$sessobj->create($sessa);
	return $sessobj;
}

function do_login($classname, $cgi_clientname, $ssl_param = null)
{
	global $gbl, $sgbl, $login, $ghtml;
	$url = "/display.php?frm_action=show";

	$progname = $sgbl->__var_program_name;

	if (!$classname) {
		$classname = 'client';
	}

	$sessobj = initSession($login, $ssl_param, null);

	$gbl->c_session = $sessobj;
	$login->addToList("ssession", $sessobj);
	$login->createSessionProperties();
	addToUtmp($sessobj, 'add');

	if ($ghtml->frm_extra_var) {
		$extra = unserialize(base64_decode($ghtml->frm_extra_var));
		$gbl->setSessionV('extra_var', $extra);
	}
	//This is not the way. You have to periodically scan the utmp and delete eveyrthing that had expired.
	delete_expired_ssessions();
}

function ifSplashScreen()
{
	global $gbl, $sgbl, $login, $ghtml;
	if (array_search_bool(strtolower($ghtml->frm_action), array('show', 'updateform', 'addform', 'continue'))) {
		return true;
	}
	return false;
}

function isModifyAction()
{
	global $gbl, $sgbl, $login, $ghtml;
	if (array_search_bool(strtolower($ghtml->frm_action), array('show', 'updateform', 'addform', 'continue', 'list')) || array_search_bool($ghtml->frm_subaction, array("download"))) {
		return false;
	}
	return true;
}

function kloxo_is_file_download(): bool
{
	global $ghtml;
	if (!is_object($ghtml)) {
		return false;
	}
	$action = strtolower((string) ($ghtml->frm_action ?? ''));
	$sub = strtolower((string) ($ghtml->frm_subaction ?? ''));

	return $action === 'update' && $sub === 'download';
}

function kloxo_download_filename(string $name): string
{
	$base = basename(str_replace("\0", '', $name));
	$base = str_replace(array("\r", "\n", '"', '\\'), '', $base);
	$base = trim($base);

	return $base !== '' ? $base : 'download';
}

function kloxo_send_download_headers(string $filename, int $size = 0): void
{
	while (ob_get_level() > 0) {
		@ob_end_clean();
	}
	$filename = kloxo_download_filename($filename);
	header('Content-Type: application/octet-stream');
	header('X-Content-Type-Options: nosniff');
	header('Content-Transfer-Encoding: binary');
	header('Cache-Control: no-store');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	if ($size > 0) {
		header('Content-Length: ' . $size);
	}
}

function lget_class($object)
{
	if (!is_object($object)) {
		return '';
	}

	return strtolower(get_class($object));
}

function kcount($value): int
{
	return is_countable($value) ? count($value) : 0;
}

function kloxo_hostname(): string
{
	$h = function_exists('gethostname') ? gethostname() : php_uname('n');

	return (is_string($h) && $h !== '') ? $h : 'localhost';
}

function kloxo_machine(): string
{
	$m = php_uname('m');

	return is_string($m) ? $m : '';
}

function kloxo_curl_close($ch): void
{
	if ($ch === null) {
		return;
	}
	if (PHP_VERSION_ID < 80500 && function_exists('curl_close')) {
		curl_close($ch);
	}
}

function kloxo_html($value): string
{
	if (is_array($value) || is_object($value)) {
		$value = '';
	}

	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kloxo_strftime(string $format, $timestamp = null): string
{
	$ts = is_numeric($timestamp) ? (int) $timestamp : time();

	$map = array(
		'%c' => 'D M j H:i:s Y',
		'%m' => 'm',
		'%d' => 'd',
		'%Y' => 'Y',
		'%H' => 'H',
		'%M' => 'i',
		'%S' => 's',
		'%Y-%m-%d %H:%M:%S' => 'Y-m-d H:i:s',
	);
	if (isset($map[$format])) {
		return date($map[$format], $ts);
	}

	$php = strtr($format, array(
		'%Y' => 'Y',
		'%m' => 'm',
		'%d' => 'd',
		'%H' => 'H',
		'%M' => 'i',
		'%S' => 's',
		'%c' => 'D M j H:i:s Y',
	));

	return date($php, $ts);
}

function exit_programlib()
{
	global $gbl, $sgbl, $login, $ghtml;

	$refer = $gbl->getSessionV("lx_http_referer");
	$current_query_string = $ghtml->get_get_from_post(array('frm_ev_list'), $ghtml->__http_vars);
	$cur_url = $_SERVER['PHP_SELF'] . "?" . $current_query_string;

	if ($refer != $cur_url) {
		$gbl->setSessionV("lx_http_referer_parent", $refer);
		$gbl->setSessionV("lx_http_referer", $cur_url);
	}

	if ($ghtml->frm_hpfilter) {
		$gbl->setSessionV("lx_hpfilter", $ghtml->frm_hpfilter);
	}

	$gbl->setHistory();
	$gbl->c_session->was();
}

function getAllclients($cttype = null)
{
	$db = new Sqlite(null, 'client');

	$list = $db->getTable(array('nname', 'cttype'));

	return $list;
}

function delete_login()
{
	global $gbl, $sgbl, $login, $ghtml;
	$class = lget_class($login);
}

/**
 * @return void
 * @param
 * @param
 * @desc  Saves teh whole login info. Login is 'Was'ed; (Write and synced). Any exception is caught and the script is returned to the previous web page, with the error message string returned by the exception. Works EXceptionally well.. :-)
 */
function save_login()
{
	// Function removed by Ligesh earlier. (was saving login info)
	// Deleted the code after the first return.
	return;
}

function isLocalhost($var)
{
	if ($var && $var !== "localhost") {
		return false;
	}
	return true;
}

function get_savedlogin($classname, $clientname)
{
	global $gbl, $sgbl, $login, $ghtml;

	$path = "$sgbl->__path_program_etc/$classname:$clientname";

	if (file_exists($path)) {
		$login = unserialize(lfile_get_contents($path));
		if (!$login) {
			return 0;
		}

		$login->__parent_o = null;

		return 1;
	}
	return 0;
}

function get_login($classname, $clientname)
{
	global $gbl, $sgbl, $login, $ghtml;

	if (!$classname) {
		print("There is no class. Login Error\n");
		exit;
	}

	$object = new $classname(null, null, $clientname, 'login');

	$ret = $object->get();

	if ($classname === 'auxiliary') {
		$login = $object->getParentO();
		$login->__auxiliary_object = $object;
	} else {
		$login = $object;
	}

	$login->__parent_o = null;
	return $ret;
}

function create_name($word)
{
	$word = str_replace("_", $word);
	return $word;
}

/**
 * @return void
 * @param
 * @param
 * @desc  Remove unsavoury characters from a string so that it can be used as a variable.
 */
function fix_nname_to_be_variable($var)
{
	if (!$var) {
		return;
	}
	if (is_numeric($var[0])) {
		$var[0] = 'd';
	}
	$var = strtolower($var);
	return preg_replace("/[-\W ]/i", "_", $var);
}

function fix_nname_to_be_variable_without_lowercase($var)
{
	if (!$var) {
		return;
	}
	if (is_numeric($var[0])) {
		$var[0] = 'd';
	}
	return preg_replace("/[-\W ]/i", "_", $var);
}

function get_description($stuff)
{
	if (is_object($stuff)) {
		$class = lget_class($stuff);
	} else {
		$class = $stuff;
	}

	$descr = get_classvar_description($class);
	return $descr[2];
}

function get_classvar_description($class, $var = null)
{
	global $gbl, $sgbl, $login, $ghtml;

	global $g_language_desc;
	global $g_language_mes;

	//$var = fix_nname_to_be_variable($var);
	if (!is_string($var) || $var === '') {
		$dvar = "__desc";
	} elseif (csb($var, "__")) {
		$dvar = $var;
	} else {
		$dvar = "__desc_$var";
	}

	$rvar = strfrom($dvar, "__desc_");
	$rvar = strfrom($rvar, "__acdesc_");

	$class = strtolower((string) $class);
	$ret = get_real_class_variable($class, $dvar);

	if (!is_array($ret)) {
		return null;
	}
	if (!isset($ret[2]) || $ret[2] === null || $ret[2] === '') {
		// Icon/button columns (type "b") with no label must stay blank. Filling
		// the class name made four Domains stubs print "domain" before Domain Name.
		if (isset($ret[0]) && is_string($ret[0]) && csa($ret[0], 'b')) {
			$ret[2] = '';
		} else {
			$ret[2] = $class !== '' ? $class : $rvar;
		}
	}
	$ret['help'] = $ret[2];
	if (cse($dvar, "_o") || cse($dvar, "_l")) {
		return $ret;
	}
	if (!$g_language_desc) {
		return $ret;
	}

/*
	 if ($login->getSpecialObject('sp_specialplay')->isCoreLanguage()) {
		 return $ret;
	 }
*/

	$k = trim((string) $ret[2], "_\n ");
	if ($k !== '' && isset($g_language_desc->__description[$k])) {
		$ret[2] = $g_language_desc->__description[$k][0];
		if (isset($g_language_mes->__helpvar[$rvar])) {
			$ret['help'] = $g_language_mes->__helpvar[$rvar];
		} else if (isset($g_language_mes->__help[$dvar])) {
			$ret['help'] = $g_language_desc->__help[$k];
		} else {
			$ret['help'] = $ret[2];
		}
	}
	return $ret;
}

function get_var_help($var)
{
	global $g_language_mes, $g_language_desc;
	$cvar = strtolower($var);
	if (isset($g_language_mes->__help[$cvar])) {
		return $g_language_mes->__help[$cvar];
	}
	return $var;
}

function get_real_class_variable($class, $var)
{
	//$var = fix_nname_to_be_variable($var);
	//list($iclass, $mclass, $rclass) = get_composite($class);
	$rclass = is_string($class) ? strtolower($class) : '';
	if ($rclass === '' || !is_string($var) || $var === '') {
		return null;
	}
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $rclass)
		|| !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var)) {
		return null;
	}

	try {
		if (!class_exists($rclass)) {
			dprint("$rclass doesn't exist\n");
			return null;
		}
		$ref = new ReflectionClass($rclass);
		if (!$ref->hasProperty($var)) {
			return null;
		}
		$prop = $ref->getProperty($var);
		if (!$prop->isStatic()) {
			return null;
		}
		return $prop->getValue();
	} catch (Throwable $e) {
		return null;
	}
}

function kloxo_url_subaction($url)
{
	if (!is_string($url) || $url === '') {
		return '';
	}
	$q = $url;
	$qpos = strpos($q, '?');
	if ($qpos !== false) {
		$q = substr($q, $qpos + 1);
	}
	$p = array();
	parse_str($q, $p);
	if (isset($p['frm_subaction']) && is_string($p['frm_subaction']) && $p['frm_subaction'] !== '') {
		return $p['frm_subaction'];
	}
	if (isset($p['sa']) && is_string($p['sa']) && $p['sa'] !== '') {
		return $p['sa'];
	}

	return '';
}

function kloxo_url_action($url)
{
	if (!is_string($url) || $url === '') {
		return '';
	}
	$q = $url;
	$qpos = strpos($q, '?');
	if ($qpos !== false) {
		$q = substr($q, $qpos + 1);
	}
	$p = array();
	parse_str($q, $p);
	if (isset($p['frm_action']) && is_string($p['frm_action']) && $p['frm_action'] !== '') {
		return strtolower($p['frm_action']);
	}
	if (isset($p['a']) && is_string($p['a']) && $p['a'] !== '') {
		return strtolower($p['a']);
	}

	return '';
}

function kloxo_url_dttype_val($url)
{
	if (!is_string($url) || $url === '') {
		return '';
	}
	$q = $url;
	$qpos = strpos($q, '?');
	if ($qpos !== false) {
		$q = substr($q, $qpos + 1);
	}
	$p = array();
	parse_str($q, $p);
	if (isset($p['frm_dttype']['val']) && is_string($p['frm_dttype']['val']) && $p['frm_dttype']['val'] !== '') {
		return strtolower($p['frm_dttype']['val']);
	}
	if (isset($p['dta']['val']) && is_string($p['dta']['val']) && $p['dta']['val'] !== '') {
		return strtolower($p['dta']['val']);
	}

	return '';
}

function kloxo_url_has_object_class($url, $class)
{
	if (!is_string($url) || $url === '' || !is_string($class) || $class === '') {
		return false;
	}
	// Decode so frm_o_o%5B1%5D%5Bclass%5D=foo matches frm_o_o[1][class]=foo.
	$url = urldecode($url);
	$q = preg_quote($class, '/');

	return preg_match('/(?:frm_o_o\[\d+\]\[class\]|l\[class\]|frm_o_cname|cname|\[class\]|\bo|\bc)='
		. $q . '(?:&|$)/i', $url) === 1;
}

function kloxo_url_has_dns($url)
{
	return kloxo_url_has_object_class($url, 'dns');
}

function kloxo_url_has_sslcert($url)
{
	return kloxo_url_has_object_class($url, 'sslcert');
}

function kloxo_ssl_upload_label($val)
{
	static $map = array(
		'file' => 'Add SSL File',
		'text' => 'Add SSL Text',
		'letsencrypt' => "Add Let's Encrypt",
		'startapi' => 'Add StartAPI',
		'link' => 'Add SSL Link',
	);
	$v = strtolower(trim((string)$val));

	return isset($map[$v]) ? $map[$v] : '';
}

function kloxo_dns_record_type_label($val)
{
	static $map = array(
		'ns' => 'NS',
		'a' => 'A',
		'aaaa' => 'AAAA',
		'cname' => 'CNAME',
		'fcname' => 'FCNAME',
		'mx' => 'MX',
		'txt' => 'TXT',
		'caa' => 'CAA',
		'srv' => 'SRV',
	);
	$v = strtolower(trim((string)$val));

	return isset($map[$v]) ? $map[$v] : '';
}

function kloxo_url_has_ffile($url)
{
	return kloxo_url_has_object_class($url, 'ffile');
}

function kloxo_url_has_lxguard($url)
{
	return kloxo_url_has_object_class($url, 'lxguard');
}

function kloxo_url_has_sshconfig($url)
{
	return kloxo_url_has_object_class($url, 'sshconfig');
}

function kloxo_url_has_serverftp($url)
{
	return kloxo_url_has_object_class($url, 'serverftp');
}

function kloxo_url_has_phpini($url)
{
	return kloxo_url_has_object_class($url, 'phpini');
}

function kloxo_url_has_ufwfirewall($url)
{
	return kloxo_url_has_object_class($url, 'ufwfirewall');
}

function kloxo_url_has_fail2ban($url)
{
	return kloxo_url_has_object_class($url, 'fail2ban');
}

function kloxo_fail2ban_action_label($sa)
{
	static $map = array(
		'ban' => 'Ban IP',
		'settings' => 'Settings',
		'editjail' => 'Edit Jail',
	);
	$s = strtolower(trim((string) $sa));

	return isset($map[$s]) ? $map[$s] : '';
}

function kloxo_url_has_servermail($url)
{
	return kloxo_url_has_object_class($url, 'servermail')
		|| kloxo_url_has_object_class($url, 'mail_graylist_wlist_a');
}

function kloxo_servermail_page_tab_label($str, $url)
{
	$url = is_string($url) ? urldecode($url) : '';
	$str = is_string($str) ? $str : '';
	$sa = kloxo_url_subaction($url);
	$act = kloxo_url_action($url);
	$isMail = kloxo_url_has_servermail($url);
	$isGray = strpos($url, 'mail_graylist_wlist_a') !== false;
	static $mailStr = array(
		'server_mail_settings' => true,
		'Mail Server Settings' => true,
		'servermail' => true,
		'Servermail' => true,
		'spamdyke' => true,
		'Spamdyke' => true,
		'whitelist_ip' => true,
		'whitelist_ips' => true,
		'Whitelist IPs' => true,
		'Whitelist IP' => true,
		'mail_graylist_wlist_a' => true,
		'Update_spamdyke' => true,
		'Update _spamdyke' => true,
	);
	if (!$isMail && !$isGray && !isset($mailStr[$str])) {
		return '';
	}
	if ($isGray || $str === 'whitelist_ip' || $str === 'whitelist_ips'
		|| $str === 'Whitelist IPs' || $str === 'Whitelist IP'
		|| $str === 'mail_graylist_wlist_a') {
		return 'Whitelist IPs';
	}
	if ($sa === 'spamdyke' || $str === 'spamdyke' || $str === 'Spamdyke'
		|| $str === 'Update_spamdyke' || $str === 'Update _spamdyke') {
		return 'SMTP Protection';
	}
	if ($isMail || $str === 'server_mail_settings' || $str === 'Mail Server Settings'
		|| $str === 'servermail' || $str === 'Servermail') {
		return 'Mail Server Settings';
	}

	return '';
}

function kloxo_ufw_action_label($sa)
{
	// Form section titles on the single UFW Firewall show page (not beige tabs).
	static $map = array(
		'ufwenable' => 'Enable',
		'ufwdisable' => 'Disable',
		'ufwdefaults' => 'Default Policies',
		'addrule' => 'Add Rule',
		'editrule' => 'Edit',
		'deleterule' => 'Delete Rule',
	);
	$s = strtolower(trim((string) $sa));

	return isset($map[$s]) ? $map[$s] : '';
}

function kloxo_url_has_lxbackup($url)
{
	return kloxo_url_has_object_class($url, 'lxbackup');
}

function kloxo_ffile_is_backup_list($parent)
{
	if (!is_object($parent)) {
		return false;
	}
	$base = isset($parent->base) ? (string) $parent->base : '';
	$root = isset($parent->root) ? (string) $parent->root : '';
	if ($base === '__backup' || basename(rtrim($root, '/')) === '__backup') {
		return true;
	}
	try {
		$po = method_exists($parent, 'getParentO') ? $parent->getParentO() : null;
		if (is_object($po) && method_exists($po, 'isClass') && $po->isClass('lxbackup')) {
			return true;
		}
	} catch (Throwable $e) {
	}

	return function_exists('kloxo_object_is_backup_page') && kloxo_object_is_backup_page($parent);
}

function kloxo_object_is_backup_page($object)
{
	if (!is_object($object)) {
		return false;
	}
	$c = '';
	try {
		$c = strtolower((string) $object->getClass());
	} catch (Throwable $e) {
		return false;
	}
	if ($c === 'lxbackup') {
		return true;
	}
	if ($c !== 'ffile') {
		return false;
	}
	try {
		$p = method_exists($object, 'getParentO') ? $object->getParentO() : null;
		if (is_object($p) && method_exists($p, 'getClass')
			&& strtolower((string) $p->getClass()) === 'lxbackup') {
			return true;
		}
	} catch (Throwable $e) {
	}
	$root = isset($object->root) ? (string) $object->root : '';
	$base = isset($object->base) ? (string) $object->base : '';

	return ($base === '__backup' || basename(rtrim($root, '/')) === '__backup');
}

function kloxo_backup_action_label($sa)
{
	static $map = array(
		'ftp_conf' => 'FTP Configuration',
		'schedule_conf' => 'Schedule Configuration',
		'backup' => 'Backup Now',
		'restore' => 'Restore',
		'restore_from_file' => 'Restore From File',
		'restore_from_ftp' => 'Restore From FTP',
	);

	return (is_string($sa) && isset($map[$sa])) ? $map[$sa] : '';
}

function kloxo_backup_page_tab_label($str, $url)
{
	$url = is_string($url) ? urldecode($url) : '';
	$str = is_string($str) ? $str : '';
	$sa = kloxo_url_subaction($url);
	$act = kloxo_url_action($url);
	$isBackupUrl = kloxo_url_has_lxbackup($url);
	static $backupStr = array(
		'backup' => true,
		'Backup' => true,
		'Backup / Restore' => true,
		'lxbackup' => true,
		'Lxbackup' => true,
		'ftp_conf' => true,
		'ftp_configuration' => true,
		'FTP Configuration' => true,
		'Update_ftp_conf' => true,
		'Update _ftp_conf' => true,
		'schedule_conf' => true,
		'schedule_configuration' => true,
		'Schedule Configuration' => true,
		'Update_schedule_conf' => true,
		'Update _schedule_conf' => true,
	);
	if (!$isBackupUrl && !isset($backupStr[$str]) && $sa !== 'ftp_conf' && $sa !== 'schedule_conf') {
		return '';
	}
	$isFfile = kloxo_url_has_ffile($url);
	if ($isFfile && ($sa === 'upload' || $sa === 'upload_s')) {
		return 'Upload';
	}
	if ($isFfile && ($act === 'show' || $act === '' || $sa === '')) {
		return 'File Manager';
	}
	if ($sa === 'ftp_conf' || $str === 'ftp_conf' || $str === 'ftp_configuration'
		|| $str === 'Update_ftp_conf' || $str === 'Update _ftp_conf' || $str === 'FTP_config') {
		return 'FTP Configuration';
	}
	if ($sa === 'schedule_conf' || $str === 'schedule_conf' || $str === 'schedule_configuration'
		|| $str === 'Update_schedule_conf' || $str === 'Update _schedule_conf') {
		return 'Schedule Configuration';
	}
	if ($sa === 'restore_from_file' || $str === 'restore_from_file') {
		return 'Restore From File';
	}
	if ($sa === 'restore_from_ftp' || $str === 'restore_from_ftp') {
		return 'Restore From FTP';
	}
	if ($sa === 'backup' && $act !== 'show' && $act !== '') {
		return 'Backup Now';
	}
	if ($isBackupUrl && !$isFfile && ($act === 'show' || $act === '') && ($sa === '' || $sa === 'show')) {
		return 'Backup / Restore';
	}
	if (isset($backupStr[$str]) && ($sa === '' || $act === 'show' || $act === '')
		&& $sa !== 'ftp_conf' && $sa !== 'schedule_conf') {
		return 'Backup / Restore';
	}

	return kloxo_backup_action_label($sa);
}

function kloxo_ffile_action_label($sa)
{
	static $map = array(
		'perm' => 'Permissions',
		'own' => 'Ownership',
		'diskspace' => 'Disk Usage',
		'upload' => 'Upload',
		'upload_s' => 'Upload',
		'thumbnail' => 'Thumbnail',
		'rename' => 'Rename',
		'toggle_dot' => 'T Hidden',
		'newdir' => 'New dir',
		'newfile' => 'New file',
		'copy' => 'Copy',
		'cut' => 'Cut',
		'paste' => 'Paste',
		'paste_inactive' => 'Paste',
		'filedelete' => 'Trash',
		'filerealdelete' => 'Remove',
		'zip_file' => 'Zip',
		'unzip' => 'Unzip',
		'download' => 'Download',
		'convert_image' => 'Convert Format',
		'content' => 'Contents',
		'archive' => 'Browse Archive',
		'zipextract' => 'Extract Archive',
		'go_home' => 'Home',
		'go_up' => 'Up',
		'restore_trash' => 'Restore',
		'clear_trash' => 'Clear',
		'download_from_url' => 'Upload From Url',
		'download_from_http' => 'Upload (http)',
		'download_from_ftp' => 'Upload (ftp)',
		'backupftpupload' => 'FTP Upload',
		'fancyedit' => 'Html Edit',
	);

	return (is_string($sa) && isset($map[$sa])) ? $map[$sa] : '';
}

function kloxo_content_tab_label($str, $url = '')
{
	$url = is_string($url) ? urldecode($url) : '';
	$mailPage = kloxo_servermail_page_tab_label(is_string($str) ? $str : '', $url);
	if ($mailPage !== '') {
		return $mailPage;
	}
	$bkPage = kloxo_backup_page_tab_label(is_string($str) ? $str : '', $url);
	if ($bkPage !== '') {
		return $bkPage;
	}
	$sa = kloxo_url_subaction($url);
	$ff = kloxo_ffile_action_label($sa);
	if ($ff !== '') {
		return $ff;
	}
	$bk = kloxo_backup_action_label($sa);
	if ($bk !== '') {
		return $bk;
	}
	$urlStr = $url;
	$dt = kloxo_url_dttype_val($urlStr);
	$dnsDt = kloxo_dns_record_type_label($dt);
	if ($dnsDt !== '') {
		return $dnsDt;
	}
	$sslTab = kloxo_ssl_upload_label($dt);
	if ($sslTab !== '' && kloxo_url_has_sslcert($urlStr)) {
		return $sslTab;
	}
	$isFfile = kloxo_url_has_ffile($urlStr);
	if ($isFfile && $sa === 'edit') {
		return 'Edit';
	}
	$act = kloxo_url_action($urlStr);
	if ($isFfile && $sa === 'diskspace') {
		return 'Disk Usage';
	}
	if ($isFfile && ($act === 'show' || $act === '')) {
		return 'File Manager';
	}
	if (kloxo_url_has_lxbackup($urlStr) && !$isFfile
		&& ($act === 'show' || $act === '')) {
		return 'Backup / Restore';
	}
	if (kloxo_url_has_dns($urlStr) && !$isFfile
		&& ($act === 'show' || $act === '')) {
		return 'Manage DNS';
	}
	if (kloxo_url_has_sslcert($urlStr) && !$isFfile
		&& ($act === 'list' || $act === 'show' || $act === '')) {
		return 'SSL Certificates';
	}
	if (kloxo_url_has_serverftp($urlStr) && !$isFfile
		&& ($act === 'show' || $act === 'list' || $act === '' || $act === 'updateform' || $act === 'update')) {
		return 'FTP Configure';
	}
	if (kloxo_url_has_sshconfig($urlStr) && !$isFfile
		&& ($act === 'show' || $act === 'list' || $act === '' || $act === 'updateform' || $act === 'update')) {
		return 'SSH Configure';
	}
	if (kloxo_url_has_lxguard($urlStr) && !$isFfile
		&& ($act === 'show' || $act === '' || $act === 'updateform' || $act === 'update')) {
		return 'LxGuard';
	}
	if (kloxo_url_has_phpini($urlStr) && !$isFfile) {
		if ($sa === 'extraedit') {
			return 'Advanced PHP Configure';
		}
		// show / edit / Home / plain updateform: never leave a parent "Home" label
		return 'PHP Configure';
	}
	if (kloxo_url_has_ufwfirewall($urlStr) && !$isFfile) {
		$ufwTab = kloxo_ufw_action_label($sa);
		if ($ufwTab !== '') {
			return $ufwTab;
		}
		if ($act === 'show' || $act === '' || $act === 'updateform' || $act === 'update'
			|| $str === 'Home') {
			return 'UFW Firewall';
		}
	}
	if (kloxo_url_has_fail2ban($urlStr) && !$isFfile) {
		$f2bTab = kloxo_fail2ban_action_label($sa);
		if ($f2bTab !== '') {
			return $f2bTab;
		}
		if ($act === 'show' || $act === '' || $act === 'updateform' || $act === 'update'
			|| $str === 'Home') {
			return 'Fail2Ban';
		}
	}
	if ($sa === 'rebuild') {
		return 'Rebuild';
	}
	if ($sa === 'parameter' || $sa === 'generalsetting') {
		return 'General Settings';
	}
	if ($str === 'Update_generalsetting' || $str === 'Update _generalsetting'
		|| $str === 'generalsetting' || $str === 'Generalsetting') {
		return 'General Settings';
	}
	if ($act === 'refresh' || $act === 'hardrefresh') {
		return 'Refresh';
	}
	if ($sa === 'switchprogram') {
		return 'Switch Program';
	}
	if ($sa === 'reboot') {
		return 'Reboot';
	}
	if ($sa === 'poweroff') {
		return 'Poweroff';
	}
	if ($sa === 'extraedit') {
		return 'Advanced PHP Configure';
	}
	if ($sa === 'phpinfo') {
		return 'PHPinfo';
	}
	if ($sa === 'changeowner') {
		return 'Change Owner';
	}
	if (!is_string($str) || $str === '') {
		if ($sa === 'edit') {
			return 'PHP Configure';
		}

		return $sa !== '' ? ucfirst($sa) : '';
	}
	if ($str === 'UFW Firewall' || $str === 'ufwfirewall' || $str === 'ufw_firewall'
		|| strcasecmp($str, 'ufw firewall') === 0) {
		return 'UFW Firewall';
	}
	if ($str === 'Update_ufwenable' || $str === 'Update _ufwenable' || $str === 'ufwenable') {
		return 'Enable';
	}
	if ($str === 'Update_ufwdisable' || $str === 'Update _ufwdisable' || $str === 'ufwdisable') {
		return 'Disable';
	}
	if ($str === 'Update_addrule' || $str === 'Update _addrule') {
		return 'Add Rule';
	}
	if ($str === 'Update_deleterule' || $str === 'Update _deleterule') {
		return 'Delete Rule';
	}
	if ($str === 'reboot') {
		return 'Reboot';
	}
	if ($str === 'poweroff') {
		return 'Poweroff';
	}
	if ($str === 'switch_program' || $str === 'switchprogram' || $str === 'Switchprogram'
		|| $str === 'Update _switchprogram' || strcasecmp($str, 'switch program') === 0) {
		return 'Switch Program';
	}
	if ($str === 'FTP_config' || $str === 'ftp_config' || $str === 'FTP_Config'
		|| $str === 'ftpconfigure' || $str === 'Ftp_config'
		|| strcasecmp($str, 'ftp configure') === 0) {
		return 'FTP Configure';
	}
	static $ffileKeys = array(
		'change_permissions' => 'Permissions',
		'change_ownership' => 'Ownership',
		'disk_usage' => 'Disk Usage',
		't_hidden' => 'T Hidden',
		'newdir' => 'New dir',
		'newfile' => 'New file',
		'file_manager' => 'File Manager',
		'Update_perm' => 'Permissions',
		'Update_own' => 'Ownership',
		'Update_diskspace' => 'Disk Usage',
		'Update_upload' => 'Upload',
		'Update_thumbnail' => 'Thumbnail',
		'Update_toggle_dot' => 'T Hidden',
		'Update_newdir' => 'New dir',
		'Update_newfile' => 'New file',
		'Update_copy' => 'Copy',
		'Update_cut' => 'Cut',
		'Update_paste' => 'Paste',
		'Update_paste_inactive' => 'Paste',
		'Update_filedelete' => 'Trash',
		'Update_filerealdelete' => 'Remove',
		'Update_zip_file' => 'Zip',
		'Update _perm' => 'Permissions',
		'Update _own' => 'Ownership',
		'Update _diskspace' => 'Disk Usage',
		'Update _upload' => 'Upload',
		'Update _thumbnail' => 'Thumbnail',
		'Update _toggle_dot' => 'T Hidden',
		'Update _newdir' => 'New dir',
		'Update _newfile' => 'New file',
		'Update _copy' => 'Copy',
		'Update _cut' => 'Cut',
		'Update _paste' => 'Paste',
		'Update _paste_inactive' => 'Paste',
		'Update _filedelete' => 'Trash',
		'Update _filerealdelete' => 'Remove',
		'Update _zip_file' => 'Zip',
	);
	if (isset($ffileKeys[$str])) {
		return $ffileKeys[$str];
	}
	static $backupKeys = array(
		'backup' => 'Backup / Restore',
		'backup_now' => 'Backup Now',
		'ftp_configuration' => 'FTP Configuration',
		'schedule_configuration' => 'Schedule Configuration',
		'restore_from_file' => 'Restore From File',
		'restore_from_ftp' => 'Restore From FTP',
		'Update_ftp_conf' => 'FTP Configuration',
		'Update_schedule_conf' => 'Schedule Configuration',
		'Update_backup' => 'Backup Now',
		'Update_restore' => 'Restore',
		'Update_restore_from_file' => 'Restore From File',
		'Update_restore_from_ftp' => 'Restore From FTP',
		'Update _ftp_conf' => 'FTP Configuration',
		'Update _schedule_conf' => 'Schedule Configuration',
		'Update _backup' => 'Backup Now',
		'Update _restore' => 'Restore',
		'Update _restore_from_file' => 'Restore From File',
		'Update _restore_from_ftp' => 'Restore From FTP',
	);
	if (isset($backupKeys[$str])) {
		return $backupKeys[$str];
	}
	if (preg_match('/^add\s+(ns|a|aaaa|cname|fcname|mx|txt|caa|srv)$/i', $str, $m) === 1) {
		$typed = kloxo_dns_record_type_label($m[1]);
		if ($typed !== '') {
			return $typed;
		}
	}
	static $dnsKeys = array(
		'manage_dns' => 'Manage DNS',
		'Manage DNS' => 'Manage DNS',
		'general_settings' => 'General Settings',
		'General Settings' => 'General Settings',
		'rebuild' => 'Rebuild',
		'Rebuild' => 'Rebuild',
		'Update_rebuild' => 'Rebuild',
		'Update_parameter' => 'General Settings',
		'Update _rebuild' => 'Rebuild',
		'Update _parameter' => 'General Settings',
	);
	if (isset($dnsKeys[$str])) {
		return $dnsKeys[$str];
	}
	if ($str === 'SSH_config' || $str === 'ssh_config' || $str === 'SSH Configure'
		|| $str === 'sshconfig' || $str === 'Sshconfig') {
		return 'SSH Configure';
	}
	if ($str === 'Lxguard' || $str === 'lxguard' || $str === 'LxGuard') {
		return 'LxGuard';
	}
	if ($str === 'domain_defaults' || $str === 'Client Processed Logs'
		|| $str === 'Client Processing Logs'
		|| strcasecmp($str, 'client processed logs') === 0
		|| strcasecmp($str, 'client processing logs') === 0) {
		return 'Client Processing Logs';
	}
	if (kloxo_url_has_object_class($urlStr, 'domaindefault') && !$isFfile
		&& ($act === 'updateform' || $act === 'update' || $act === 'show' || $act === ''
			|| $str === 'Home' || $str === 'Show')) {
		return 'Client Processing Logs';
	}
	if ($str === 'PHP_config' || $str === 'php_configuration' || $str === 'php_config'
		|| $str === 'PHP_configuration' || $str === 'configure_php' || $str === 'PHP Configure') {
		return 'PHP Configure';
	}
	if ($str === 'advanced_PHP_config' || $str === 'Extraedit' || $str === 'extraedit'
		|| $str === 'Update_extraedit' || $str === 'Update _extraedit'
		|| $str === 'Advanced PHP Configure') {
		return 'Advanced PHP Configure';
	}
	// Bare a=show after a bad menu_title left no object in the tab URL: use current object.
	if (($str === 'Home' || $str === 'Show') && ($act === 'show' || $act === '') && $sa === ''
		&& $urlStr !== '' && !preg_match('/\[class\]=/', $urlStr)
		&& isset($GLOBALS['gbl']) && is_object($GLOBALS['gbl'])
		&& isset($GLOBALS['gbl']->__c_object) && is_object($GLOBALS['gbl']->__c_object)
		&& method_exists($GLOBALS['gbl']->__c_object, 'getClass')) {
		try {
			$cur = strtolower((string) $GLOBALS['gbl']->__c_object->getClass());
			if ($cur === 'phpini') {
				return 'PHP Configure';
			}
			if ($cur === 'lxbackup') {
				return 'Backup / Restore';
			}
			if ($cur === 'servermail') {
				return 'Mail Server Settings';
			}
			if ($cur === 'domaindefault') {
				return 'Client Processing Logs';
			}
			if ($cur === 'ffile') {
				return 'File Manager';
			}
		} catch (Throwable $e) {
		}
	}
	static $sslKeys = array(
		'ssl_certificate' => 'SSL Certificates',
		'ssl_certificates' => 'SSL Certificates',
		'SSL Certificate' => 'SSL Certificates',
		'SSL Certificates' => 'SSL Certificates',
		'ssl_file' => 'Add SSL File',
		'Add ssl_file' => 'Add SSL File',
		'Add SSL File' => 'Add SSL File',
		'ssl_text' => 'Add SSL Text',
		'Add ssl_text' => 'Add SSL Text',
		'Add SSL Text' => 'Add SSL Text',
		'ssl_letsencrypt' => "Add Let's Encrypt",
		'Add ssl_letsencrypt' => "Add Let's Encrypt",
		"Add Let's Encrypt" => "Add Let's Encrypt",
		'ssl_link' => 'Add SSL Link',
		'Add ssl_link' => 'Add SSL Link',
		'Add SSL Link' => 'Add SSL Link',
	);
	if (isset($sslKeys[$str])) {
		return $sslKeys[$str];
	}

	return $str;
}

function kloxo_menu_title($url)
{
	global $ghtml;

	$fallback = is_string($url) ? $url : 'Item';
	try {
		if (isset($ghtml) && is_object($ghtml) && method_exists($ghtml, 'getTitleOnly')) {
			// Do not assign $ghtml->frm_o_o: that creates a real property and
			// permanently shadows __get/__http_vars, so content tabs lose the
			// object path (PHP Configure becomes "Home" with a bare a=show URL).
			$savedHttp = null;
			$clearedHttp = false;
			if (isset($ghtml->__http_vars) && is_array($ghtml->__http_vars)
				&& array_key_exists('frm_o_o', $ghtml->__http_vars)) {
				$savedHttp = $ghtml->__http_vars['frm_o_o'];
				$ghtml->__http_vars['frm_o_o'] = array();
				$clearedHttp = true;
			}
			$hadProp = array_key_exists('frm_o_o', get_object_vars($ghtml));
			$savedProp = $hadProp ? $ghtml->frm_o_o : null;
			if ($hadProp) {
				unset($ghtml->frm_o_o);
			}
			try {
				$t = $ghtml->getTitleOnly($url);
			} finally {
				if ($clearedHttp) {
					$ghtml->__http_vars['frm_o_o'] = $savedHttp;
				}
				if ($hadProp) {
					$ghtml->frm_o_o = $savedProp;
				} elseif (array_key_exists('frm_o_o', get_object_vars($ghtml))) {
					unset($ghtml->frm_o_o);
				}
			}
			if (is_string($t) && $t !== '') {
				return $t;
			}
		}
	} catch (Throwable $e) {
		log_log('error', 'menu title: ' . $e->getMessage());
	}
	return $fallback;
}

function get_class_variable($class, $var)
{
	//list($iclass, $mclass, $rclass) = get_composite($class);
	$rclass = $class;

	$var = fix_nname_to_be_variable($var);
	$class = ucfirst($class);
	/*
	 if (csa($class, '-')) {
		 debugBacktrace();
		 exit;
	 }
 */

	$variable = "$class::\$" . $var;
	return eval(" if (isset($variable)) { return $variable ; }  ");
}

function set_class_variable($class, $var, $val)
{
	$var = fix_nname_to_be_variable($var);
	$class = ucfirst($class);
	$variable = "$class::\$" . $var;
	return eval(" $variable = \$val ; ");
}

function createZeroString($n)
{
	$string = "";
	for ($i = 0; $i < $n; $i++) {
		$string .= "0";
	}
	return $string;
}

/**
 * @return void
 * @param
 * @param
 * @desc  Execs a method inside a class. Passes all the variables to it. See the use of 2 evals.. Check documentation for lx_redefine_func;
 */
function exec_class_method($class, $func)
{
	global $gbl, $sgbl, $login, $ghtml;

	//list($iclass, $mclass, $rclass) = get_composite($class);
	$rclass = $class;

	$class = strtolower($class);

	//Arg getting string is a function that needs $start to be set.
	$start = 2;
	$transforming_func = null;

	if (version_compare(PHP_VERSION, '5.3.0', '<')) {
		eval($sgbl->arg_getting_string);
	} else {
	//	$arglist = get_function_arglist($start, $transforming_func);

		$arglist = array();

		for ($i = $start; $i < func_num_args(); $i++) {
			$arglist[] = func_get_arg($i);
		}

	}

	// workaround for the following php bug:
	//   http://bugs.php.net/bug.php?id=47948
	//   http://bugs.php.net/bug.php?id=51329
	class_exists($class);
	// ---
	// PHP 8: call_user_func_array TypeErrors if the class or method is missing
	// (dns__none was skipped by autoload; Lxupdate had no initThisObject).
	if (!class_exists($class, false)) {
		global $gl_class_array;
		if (isset($gl_class_array[$class])) {
			$path = function_exists('getreal') ? getreal($gl_class_array[$class]) : $gl_class_array[$class];
			if (is_string($path) && $path !== '' && file_exists($path)) {
				include_once $path;
			}
		}
	}
	if (!is_callable(array($class, $func))) {
		return null;
	}

	return call_user_func_array(array($class, $func), $arglist);
}

function lxgettimewithoutyear($time)
{
	$curd = @ getdate(time());
	$date = @ getdate($time);

	//$month = ($date['month'] === $curd['month'])? "this Month": $date['month'];
	$month = substr($date['month'], 0, 3);

	if ($date['hours'] > 12) {
		$sess = "pm";
		//$hour = $date['hours'] - 12;
		$hour = $date['hours'];
	} else {
		$sess = "am";
		$hour = $date['hours'];
	}

	$minutes = $date['minutes'];

	if ($minutes < 10) {
		$minutes = "0" . $minutes;
	}

	$string = "$hour:$minutes {$date['mday']} $month";

	return $string;
}

function lxgettime($time)
{
	$curd = @ getdate(time());
	$date = @ getdate($time);
/*
	$year = ($date['year'] === $curd['year']) ? "" : $date['year'];
	$year = $date['year'];
	//$month = ($date['month'] === $curd['month'])? "this Month": $date['month'];
	$month = substr($date['month'], 0, 3);

	if ($date['hours'] > 12) {
		$sess = "pm";
		//$hour = $date['hours'] - 12;
		$hour = $date['hours'];
	} else {
		$sess = "am";
		$hour = $date['hours'];
	}

	$minutes = $date['minutes'];

	if ($minutes < 10) {
		$minutes = "0" . $minutes;
	}

	$string = $hour . ":" . $minutes . " " . $date['mday'] . " " . $month . " " . $year;
*/
	$year = $date['year'];
	$month = ($date['mon'] < 10) ? "0" . $date['mon'] : $date['mon'];
	$day = ($date['mday'] < 10) ? "0" . $date['mday'] : $date['mday'];

	$hour = ($date['hours'] < 10) ? "0" . $date['hours'] : $date['hours'];
	$minute = ($date['minutes'] < 10) ? "0" . $date['minutes'] : $date['minutes'];
	$second = ($date['seconds'] < 10) ? "0" . $date['seconds'] : $date['seconds'];

	$string = "{$year}/{$month}/{$day} {$hour}:{$minute}:{$second}";

	return $string;
}

function if_search_continue($obj)
{
	global $gbl, $sgbl, $ghtml;
	if ($ghtml->iset("frm_searchstring") && !stristr($obj->{$obj->searchVar()}, $ghtml->frm_searchstring)) {
		return 1;
	} else {
		return 0;
	}
}

function add_select_one($list)
{
	$newlist[] = "--Select One--";
	$newlist = lx_array_merge(array($newlist, $list));
	return $newlist;
}

function add_disabled($list)
{
	$newlist[] = "--Disabled--";
	$newlist = lx_merge_good($newlist, $list);
	return $newlist;
}

function fix_disabled($value, $disabled_val)
{
	if ($value === "--Disabled--") {
		return $disabled_val;
	} else {
		return $value;
	}
}

function array_remove_assoc(&$array, $element)
{
	foreach ($array as $key => $value) {
		if ($value === $element)
			unset($array[$key]);
	}
}

function array_remove_object($objectlist, $variable, $value)
{
	foreach ($objectlist as $object) {
		if ($object->$variable != $value) {
			$ret[$object->nname] = $object;
		}
	}
	return $ret;
}

function add_superadmin($pass)
{
	global $gbl, $sgbl, $login, $ghtml;

	$client = new SuperClient(null, null, 'superadmin');
	$client->initThisDef();

	$ddb = new Sqlite(null, "superclient");
	if (!$ddb->existInTable("nname", 'superclient')) {
		$res['password'] = crypt($pass, '$1$'.randomString(8).'$');
		$res['cttype'] = 'superadmin';
		$res['cpstatus'] = 'on';
		if (if_demo()) {
			$res['email'] = "admin@example.org";
		}
		$client->create($res);
		$client->write();
	}
}

function add_slave($pass)
{
	global $gbl, $sgbl, $login, $ghtml;

	$client = new Slave(null, null, 'slave');
	$client->initThisDef();

	$ddb = new Sqlite(null, "slave");
	if (!$ddb->existInTable("nname", 'slave')) {
		$res['password'] = $pass;
		$res['cttype'] = 'slave';
		if (if_demo()) {
			$res['email'] = "admin@example.org";
		}
		$client->create($res);
		$client->write();
	}
}

function init_supernode($pass)
{
	global $gbl, $sgbl, $login, $ghtml;

	sql_main();
	add_superadmin($pass);
}

function init_slave($pass)
{
	global $gbl, $sgbl, $login, $ghtml;

	$rm = new Remote();
	$rm->password = kloxo_password_hash($pass);
	lfile_put_contents($sgbl->__path_slave_db, serialize($rm));
	if (function_exists('lxfile_generic_chmod')) {
		lxfile_generic_chmod($sgbl->__path_slave_db, '0600');
	}
}

function lx_socket_read($socket)
{
	//$res=socket_recv($MsgSock,$buffer,1024,0);
}

function pad_to_length($var, $len)
{
	$times = round(strlen($var) / $len) + 1;
	$in = str_pad($var, 2048 * $times - strlen($var));
	return $in;
}

function remote_http_exec($server, $port, $rmt)
{
	$var = base64_encode(serialize($rmt));
	$res = send_to_some_http_server($server, "", $port, $var);

	$res = unserialize(base64_decode($res));

	if ($res->exception) {
		throw $res->exception;
	}
	return $res->ddata;
}

function send_to_some_http_server($raddress, $socket_type, $port, $var)
{
	global $gbl, $sgbl, $login, $ghtml;

	print_time('server');

	$ch = curl_init("http://$raddress:$port/lbin/remote.php");
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "frm_rmt=$var&");

	// MR -- possible fix download/upload issue in php 5.3
	curl_setopt($ch, CURLOPT_SSLVERSION, 3);
	curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'SSLv3');

	$totalout = curl_exec($ch);
	dprint(curl_error($ch));
	$totalout = trim($totalout);
	return $totalout;
}

function check_remote_pass($user, $pass)
{
	global $gbl, $sgbl, $login, $ghtml;
	return check_password($pass, $login->password);
}

function lfile($arg)
{
	return lx_redefine_func("file", $arg);
}

function getNthToken($string, $num, $delim = ':')
{
	if (csa($string, $delim)) {
		$_tlist = explode($delim, $string);
		return $_tlist[$num];
	}
	return $string;
}

/**
 * @return void
 * @param unknown
 * @param unknown
 * @desc Recurses a dir tree and execs the '$func' on all the files AND the directories.
 */
function recurse_dir($dir, $func, $arglist = null)
{
	$list = lscandir($dir);
	if (!$list) {
		return;
	}
	foreach ($list as $file) {
		if ($file === "." || $file === "..") {
			continue;
		}
		$path = $dir . "/" . $file;
		if (lis_dir($path)) {
			recurse_dir($path, $func, $arglist);
		}
		/// After a successfuul recursion, you have to call the $func on the directory itself. So $func is called whether $path is both directory OR a file.
		$narglist[] = $path;
		foreach ((array) $arglist as $a) {
			$narglist[] = $a;
		}
		call_user_func_array($func, $narglist);
	}
}

function do_recurse_dir($dir, $func)
{
	$list = lscandir($dir);
	if (!$list) {
		return;
	}
	foreach ($list as $file) {
		if ($file === "." || $file === "..") {
			continue;
		}
		$path = $dir . "/" . $file;
		if (lis_dir($path)) {
			do_recurse_dir($path, $func);
		}
		$func($path);
	}
}

function getFromAny($list, $class, $name)
{
	$v = null;
	foreach ($list as $ob) {
		if (!$ob) {
			continue;
		}
		try {
			$v = $ob->getFromList($class, $name);
			return $v;
		} catch (Throwable $e) {
		}
	}
	return $v;
}

function arrayGetFirstObject($list)
{
	foreach ($list as $k => $v) break;
	return $list[$k];
}

function array_get_object($objectlist, $variable, $value)
{
	foreach ($objectlist as $object)
		if ($object->$variable === $value) {
			return $object;
		}

	return NULL;
}

function getBasicServiceList()
{
	global $gbl, $sgbl, $login, $ghtml;
	$serv[] = $sgbl->__var_programname_web;
	$descr[$sgbl->__var_programname_web] = "Web Server";
	$serv[] = $sgbl->__var_programname_dns;
	$descr[$sgbl->__var_programname_dns] = "Name Server";
	$serv[] = "lxmail";
	$descr['lxmail'] = "Mail Server";
	return array($serv, $descr);
}

function getDbvariable($listvar, $mainvar)
{
	static $var;

	if (!$var) {
	//	$var = unserialize(lfile_get_contents("__path_dbschema"));
		include_once "../file/sql/db_schema.php";
	}

	if (isset($var[$listvar][$mainvar])) {
		return $var[$listvar][$mainvar];
	} else {
		dprint("NO schema for $listvar $mainvar <br> \n");
		return null;
	}

}

function check_file_if_owned_by($file, $user)
{
	if (!lxfile_exists($file)) {
		return true;
	}

	if (csa($user, ":")) {
		$ruser = strtil($user, ":");
	} else {
		$ruser = $user;
	}
	$stat = lxfile_stat($file, false);
	$uid = $stat['uid'];
	$name = os_get_user_from_uid($uid);
	if ($name === $ruser) {
		return true;
	}
	log_log("file_check", "$file not owned by $ruser");
	return false;
}

function critical_change_db_pass()
{
	$c = trim(lfile_get_contents("__path_admin_pass"));
	if ($c == "21232f297a") {
		try {
			change_db_pass();
		} catch (exception $e) {
		}
	}
}

function change_db_pass()
{
	global $gbl, $sgbl, $login, $ghtml;

	$pass = randomString(10);
	$newp = client::createDbPass($pass);
	$oldpass = lfile_get_contents("__path_admin_pass");
	$username = $sgbl->__var_program_name;

	exec("echo 'set Password=Password(\"$newp\")' | mysql -u $username -p$oldpass 2>&1", $out, $return);

	if ($return) {
		$out = implode(" ", $out);
		log_log("admin_error", "mysql change password Failed $out");
		throw new lxException ($login->getThrow("could_not_change_admin_pass"), '', $out);
	}

	$return = lfile_put_contents("__path_admin_pass", $newp);

	if (!$return) {
		log_log("admin_error", "Admin pass change failed  $last_error");
		throw new lxException ($login->getThrow("could_not_change_admin_pass"), '', $last_error);
	}
}

function kloxo_split_sql_statements($sql)
{
	$sql = preg_replace('/^\s*--.*$/m', '', $sql);
	$out = array();

	foreach (explode(';', (string)$sql) as $chunk) {
		$stmt = trim($chunk);
		if ($stmt === '') {
			continue;
		}
		if (preg_match('/^(CREATE\s+DATABASE|USE\s+|SET\s+|\/\*!)/i', $stmt)) {
			continue;
		}
		$out[] = $stmt;
	}

	return $out;
}

function kloxo_import_sql_file($path)
{
	global $gbl, $sgbl, $login, $ghtml;

	if (!is_file($path)) {
		print("SQL file missing: {$path}\n");
		return false;
	}

	$sql = (string) file_get_contents($path);
	if ($sgbl->__var_database_type === 'sqlite') {
		if (!class_exists('KloxoSqlite', false)) {
			include_once __DIR__ . '/kloxo_sqlite.php';
		}
		try {
			$pdo = KloxoSqlite::open((string) $sgbl->__var_dbf, false);
			$errors = KloxoSqlite::applyScript($pdo, $sql);
		} catch (Throwable $e) {
			print($e->getMessage() . "\n");
			return false;
		}
		foreach ($errors as $err) {
			print("SQL warning ({$path}): {$err}\n");
		}

		return $errors === array();
	}

	$user = $sgbl->__var_admin_user;
	$db = $sgbl->__var_dbf;
	try {
		$pass = getAdminDbPass();
	} catch (Throwable $e) {
		print($e->getMessage() . "\n");
		return false;
	}
	$mysqli = new mysqli('localhost', $user, $pass, $db);

	if ($mysqli->connect_error) {
		print("Could not connect as {$user} to {$db}: {$mysqli->connect_error}\n");
		return false;
	}

	$ok = true;

	foreach (kloxo_split_sql_statements($sql) as $stmt) {
		if (!$mysqli->query($stmt)) {
			print("SQL warning ({$path}): {$mysqli->error}\n");
			$ok = false;
		}
	}

	return $ok;
}

function create_database()
{
	global $gbl, $sgbl, $login, $ghtml;

	// Synchronous import (the old background mysql import raced create_general
	// / add_admin and left a half-empty schema on Ubuntu). Prefer the panel
	// DB user created by create_mysql_db. The slavedb file is empty on a
	// first install, so do not read a slave DB password here.
	$base = "{$sgbl->__path_program_root}/file/sql/db-structure-base.sql";
	if (!is_file($base)) {
		$base = '../file/sql/db-structure-base.sql';
	}

	return kloxo_import_sql_file($base);
}

function update_database()
{
	global $gbl, $sgbl, $login, $ghtml;

	$upd = "{$sgbl->__path_program_root}/file/sql/db-structure-update.sql";
	if (!is_file($upd)) {
		$upd = '../file/sql/db-structure-update.sql';
	}

	if (!is_file($upd)) {
		return true;
	}

	return kloxo_import_sql_file($upd);
}

function get_default_fields()
{
	$fields = array("nname", "parent_clname", "parent_cmlist");
	return $fields;
}

function create_table_with_drop($tbl_name, $list)
{
	global $gbl, $sgbl, $login, $ghtml;
	$__db = new Sqlite(null, 'sqlite');
	$__db->rawQuery("drop table $tbl_name");
	create_table($__db, $tbl_name, $list);
}

function create_table($__db, $tbl_name, $list)
{
	global $gbl, $sgbl, $login, $ghtml;
	$__db = new Sqlite(null, 'sqlite');

	// For mssql you can talk of primary key initially itself nad not wait till the end of the fields.
	if ($sgbl->__var_database_type === 'mysql') {
		$primary_key = "not null";
	} else {
		$primary_key = "PRIMARY KEY";
	}

	foreach ($list as &$__tl) {
		if (csb($__tl, "nname")) {
			$__tl = "$__tl varchar(255) $primary_key";
		} else if (csb($__tl, "ser_")) {
			$__tl = "$__tl longtext";
		} else if (csb($__tl, "coma_")) {
			$__tl = "$__tl text";
		} else if (csb($__tl, "text_")) {
			$__tl = "$__tl longtext";
		} else if (csb($__tl, "parent_cmlist")) {
			$__tl = "$__tl text";
		} else {
			// Why this madness... How can the general type be varchar. What will happen to all the text messages?
			$__tl = "$__tl varchar(255)";
		}
	}

	if ($sgbl->__var_database_type === 'mysql') {
		mysql_do_create_table($__db, $tbl_name, $list);
	} else {
		sqlite_do_create_table($__db, $tbl_name, $list);
	}
}

function sqlite_do_create_table($__db, $tbl_name, $fields)
{
	$fields = implode(', ', $fields);

	$query = "create table $tbl_name ($fields);";
	print("Creating table $tbl_name....\n");
	$ret = $__db->rawQuery($query);

	$query = "insert into $tbl_name (nname) values ('__dummy__dummy__')";
	$__db->rawQuery($query);
}

function mysql_do_create_table($__db, $tbl_name, $fields)
{
	$fields = implode(', ', $fields);
	$query = "create table $tbl_name ($fields, primary key (nname (255)));";
	print("Creating table $tbl_name....\n");
	$ret = $__db->rawQuery($query);

	if (!$ret) {
		//print("\nerror: " . mysql_error() . "\n\n");
	}

	$query = "create index parent_clname_$tbl_name on $tbl_name (parent_clname (255));";
	$__db->rawQuery($query);
}

// string_main();

function getQuotaListForClass($stuff, $res = null)
{
	if (is_object($stuff)) {
		$ob = $stuff;
	} else {
		$class = $stuff;
		$name = '__dummy__dummy__';
		$ob = new $class(null, null, $name);
		if (is_array($res)) {
			if (isset($res['cttype']) && $res['cttype'] !== '') {
				$ob->cttype = $res['cttype'];
			}
			if (isset($res['nname']) && $res['nname'] !== '') {
				$ob->nname = $res['nname'];
			}
		}
		$ob->priv = new Priv(null, null, $name);
		$ob->priv->__parent_o = $ob;
	}
	try {
		$list = $ob->getQuotaVariableList();
	} catch (Throwable $e) {
		$list = array();
	}
	return is_array($list) ? $list : array();
}

function lx_xdebug_break()
{
	if (function_exists('xdebug_break')) {
		xdebug_break();
	}
}

function remove_extra_slash($file)
{
	return preg_replace("/\/+/", "/", $file);
}

function if_demo_throw_exception($where = null)
{
	global $gbl, $sgbl, $login, $ghtml;
	
	if (!$where) {
		$where = $login->nname;
	}

	if ($sgbl->dbg < 0) {
		$where = null;
	}

	if (if_demo()) {
		throw new lxException($login->getThrow("not_allowed_in_demo_version"), '', $where);
	}
}

function get_package_version($name)
{
	return '';
}

function getClassFromName($cgi_clientname)
{
	$classname = "client";

	if (csa($cgi_clientname, "@")) {
		$classname = "mailaccount";
	} elseif (csa($cgi_clientname, ".vps")) {
		$classname = "vps";
	} elseif (csa($cgi_clientname, ".vm")) {
		$classname = "vps";
	} elseif (csa($cgi_clientname, ".aux")) {
		$classname = "auxiliary";
	}

	return $classname;
}
