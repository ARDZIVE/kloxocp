<?php 

function lxshell_expect($strtype, $cmd)
{
	global $gbl, $sgbl, $login, $ghtml;

	// Fixed expect script + argv expect — never shell-string expect (security EE1).
	$cmd = is_string($cmd) ? $cmd : '';
	if ($cmd === '' || str_contains($cmd, "\0") || preg_match('/[\r\n]/', $cmd)) {
		log_shell('expect refused unsafe command');

		return;
	}
	// Quote for Tcl spawn: wrap in {...} so metacharacters are literal.
	if (str_contains($cmd, '}')) {
		log_shell('expect refused command with }');

		return;
	}
	$a = array('ntfsresize' => "expect \"Are you sure you want to proceed (y/[n])? \"\nsend \"y\\r\"\n\n");
	$t = lx_tmp_file('expect');
	$string = isset($a[$strtype]) ? $a[$strtype] : $a['ntfsresize'];
	lfile_put_contents($t, "spawn {{$cmd}}\n{$string}");
	log_shell("expect {$t}");
	$expect = is_executable('/usr/bin/expect') ? '/usr/bin/expect'
		: (is_executable('/bin/expect') ? '/bin/expect' : 'expect');
	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$p = @proc_open(array($expect, '-f', $t), $desc, $pipes);
	if (is_resource($p)) {
		proc_close($p);
	}
	@unlink($t);
}

function lxshell_getzipcontent($path)
{
	$type = os_getZipType($path);

	switch ($type) {
		case 'zip':
			return lxshell_output("unzip", "-l", $path);
		case 'tgz':
			return lxshell_output("tar", "-tzf", $path);
		case 'tbz2':
			return lxshell_output("tar", "-tjf", $path);
		case 'txz':
			return lxshell_output("tar", "-tJf", $path);
		case 'p7z':
			return lxshell_output("7za", "l", $path);
		case 'rar':
			return lxshell_output("unrar", "l", $path);
		case 'tar':
		default:
			return lxshell_output("tar", "-tf", $path);
	}
}

function lxshell_exists_in_zip($archive, $file)
{
	$dir = createTempDir("/tmp", "testzip");
	$ret = lxshell_unzip("__system__", $dir, $archive, array($file));
	lxfile_tmp_rm_rec($dir);

	if (!$ret) { return true; }

	return false;
}

// Normally the value is returned in MBs, but if you want to, you can force it to be bytes.
function lxfile_dirsize($path, $byteflag = false)
{
	global $global_dontlogshell;

	// This actually has to return the in Mega byts. The calculation is not actually correct now, 
	// and I need to find out how to properly do it.

	$old = $global_dontlogshell;
	$global_dontlogshell = true;

	$path = expand_real_root($path);

	if (!lxfile_exists($path)) {
		return 0;
	}
/*
	$rt = lxshell_output("du", "-sc", $path);
	$os  = preg_replace("/\s+/", ":", $rt);
	$ret = explode(":", $os);
	$t = $ret[2];
*/

	// argv du — never shell with {$path} (security HH1).
	$out = array();
	$line = '';
	if (function_exists('lxshell_output')) {
		$line = trim((string) lxshell_output('nice', '-n', '+10', 'ionice', '-c3', 'du', '-s', '--', $path));
		if ($line === '') {
			$line = trim((string) lxshell_output('du', '-s', '--', $path));
		}
	}
	$out[0] = $line;

	$os  = preg_replace("/\s+/", ":", $out[0]);
	$t = str_replace(":{$path}", "", $os);

	$global_dontlogshell = $old;

	if ($byteflag) {
		return round($t * (1024), 1);
	} else {
		return round($t / (1024), 1);
	}
}

function lxfile_symlink($src, $dst)
{
	$src = expand_real_root($src);
	$dst = expand_real_root($dst);

	if (is_dir($dst)) {
		$dst = "$dst/" . basename($src);
	}

	log_filesys("Linking $src to $dst");
	symlink($src, $dst);
}

function kpart_remove($disk)
{
	lxshell_return("kpartx", "-d", $disk);
}

function get_partition($disk, $root)
{
	$lv = basename($disk);
	$root = fix_vgname($root);
	$path = "/dev/mapper/$root-$lv";
	lxshell_return("kpartx", "-a", $path);
	$out = lxshell_output("kpartx", "-l", $path);
	$o = explode("\n", $out);
	$o = trimSpaces($o[0]);
	$o = explode(" ", $o);
	$partition = trimSpaces($o[0]);
	$partition = "/dev/mapper/$partition";
//	lxshell_return("ntfsfix", $partition);

	return $partition;
}

function get_free_loop()
{
	global $login, $global_shell_error, $global_shell_ret, $global_shell_out;

	lxfile_unix_chmod("__path_program_root/sbin/findfreeloop", "0755");

	$ret = lxshell_return("__path_program_root/sbin/findfreeloop");

	$loop = trim($global_shell_out);

	if (!$ret) {
		return $loop;
	}

	$num = strfrom($loop, "/dev/loop");

	if ($num >= 128) {
		throw new lxException($login->getThrow("could_not_find_free_loop"), '', $num);
	}

	lxshell_return("mknod", "-m660", $loop, "b", "7", $num);

	return $loop;
}

function lxfile_get_ntfs_disk_usage($file, $root)
{
	$file = expand_real_root($file);
	$root = fix_vgname($root);
	$ldevice = get_partition($file, $root);
	$res = lxshell_output("ntfscluster", "-f", $ldevice);
	$base = basename($file);
	kpart_remove("/dev/mapper/$root-$base");
	$res = explode("\n", $res);

	foreach($res as $r) {
		$r = trim($r);

		if (!csa($r, ":")) {
			continue;
		}

		list($var, $val) = explode(":", $r);
		$var = trim($var);
		$val = trim($val);

		if ($var === "bytes per volume") {
			$total = round($val / (1024 * 1024), 1);
		}

		if ($var === "bytes of user data") {
			$used = round($val / (1024 * 1024), 1);
		}
	}

	$ret['total'] = $total;
	$ret['used'] = $used;

	return $ret;
}

function lxfile_get_disk_usage($file)
{
	$file = expand_real_root($file);
	$res = lxshell_output("dumpe2fs", "-h", $file);

	$res = explode("\n", $res);

	foreach($res as $r) {
		if (csb($r, "Block size:")) {
			$blocksize = trim(strfrom($r, "Block size:")) /1024; 
		}
	}

	foreach($res as $r) {
		if (csb($r, "Block count:")) {
			$total = trim(strfrom($r, "Block count:")) * $blocksize; 
		}

		if (csb($r, "Free blocks:")) {
			$free = trim(strfrom($r, "Free blocks:")) * $blocksize; 
		}
	}

	$ret['total'] = round($total/1024, 2);
	$ret['used'] = round(($total - $free)/1024, 2);

	return $ret;
}

function lxshell_zip_add($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("zipadd", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_zip($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("zip", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_tgz($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("tgz", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_tbz2($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("tbz2", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_txz($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("txz", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_p7z($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("p7z", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_rar($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("rar", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_tar($dir, $zipname, $filelist)
{
	$ret = lxshell_zip_core("tar", $dir, $zipname, $filelist);

	return $ret;
}

function lxshell_zip_core($updateflag, $dir, $zipname, $filelist)
{
	$dir = expand_real_root($dir);

	$members = array();
	if (is_array($filelist)) {
		foreach ($filelist as $__f) {
			$__f = expand_real_root($__f);
			if (!is_string($__f) || $__f === '' || str_contains($__f, "\0")) {
				continue;
			}
			$members[] = $__f;
		}
	}

	$zipname = expand_real_root($zipname);

	// MR -- Use `--ignore-failed-read' to prevents tar from exitting with non-zero status on unreadable files
	// http://fvue.nl/wiki/Tar:_file_changed_as_we_read_it
	// Argument arrays only (security DD3).
	switch ($updateflag) {
		case 'zipadd':
			$base = array('zip', '-y', '-rq', '-u');
			$extra = array();
			break;
		case 'zip':
			$base = array('zip', '-y', '-rq');
			$extra = array();
			break;
		case 'tar':
			$base = array('tar', '-cf');
			$extra = array('--ignore-failed-read');
			break;
		case 'p7z':
			$base = array('7za', 'a', '-y');
			$extra = array();
			break;
		case 'rar':
			$base = array('rar', 'a', '-y');
			$extra = array();
			break;
		case 'tbz2':
		case 'tar.bz2':
			$base = array('tar', '-cjf');
			$extra = array('--ignore-failed-read');
			break;
		case 'txz':
		case 'tar.xz':
			$base = array('tar', '-cJf');
			$extra = array('--ignore-failed-read');
			break;
		case 'tgz':
		case 'tar.gz':
		default:
			$base = array('tar', '-czf');
			$extra = array('--ignore-failed-read');
			break;
	}

	if ($zipname[0] !== '/') {
		$fullpath = getcwd() . "/$zipname";
	} else {
		$fullpath = $zipname;
	}

	if ($members === array()) {
		lxfile_touch("$dir/lxblank_file");
		$members = array('lxblank_file');
	}

	$argv = array_merge(
		array('nice', '-n', '+10', 'ionice', '-c3'),
		$base,
		array($fullpath),
		$members,
		$extra
	);

	print_time("zipfile");
	$out = null;
	$err = null;
	$ret = null;
	do_exec_system("__system__", $dir, $argv, $out, $err, $ret, null);
	print_time("zipfile", "Ziptook");

	return $ret;
}

/**
 * Build argv for archive extract (security DD3) — never a shell string.
 *
 * @param list<string>|null $filelist
 * @return list<string>
 */
function kloxo_archive_extract_argv(string $fullpath, $filelist = null): array
{
	$ztype = os_getZipType($fullpath);
	switch ($ztype) {
		case 'tgz':
			$base = array('tar', '-xzf', $fullpath);
			break;
		case 'tbz2':
			$base = array('tar', '-xjf', $fullpath);
			break;
		case 'txz':
			$base = array('tar', '-xJf', $fullpath);
			break;
		case 'tar':
			$base = array('tar', '-xf', $fullpath);
			break;
		case 'p7z':
			$base = array('7za', 'e', '-y', $fullpath);
			break;
		case 'rar':
			$base = array('unrar', 'e', '-y', $fullpath);
			break;
		case 'zip':
		default:
			$base = array('unzip', '-oq', $fullpath);
			break;
	}
	$argv = array_merge(array('nice', '-n', '+10', 'ionice', '-c3'), $base);
	if (is_array($filelist)) {
		foreach ($filelist as $f) {
			if (!is_string($f) || $f === '' || str_contains($f, "\0") || str_contains($f, '..')) {
				continue;
			}
			$argv[] = $f;
		}
	}

	return $argv;
}

function lxshell_unzip($username, $dir, $file, $filelist = null)
{
	$dir = expand_real_root($dir);
	$file = expand_real_root($file);

	if ($file[0] !== '/') {
		$fullpath = getcwd() . "/$file";
	} else {
		$fullpath = $file;
	}

	// Argument array only (security DD3).
	return new_process_cmd($username, $dir, kloxo_archive_extract_argv($fullpath, $filelist));
}

function lxshell_unzip_numeric($dir, $file, $filelist = null)
{
	$dir = expand_real_root($dir);
	$file = expand_real_root($file);

	if ($file[0] !== '/') {
		$fullpath = getcwd() . "/$file";
	} else {
		$fullpath = $file;
	}

	// Argument array only (security DD3).
	$out = null;
	$err = null;
	$ret = null;
	do_exec_system("__system__", $dir, kloxo_archive_extract_argv($fullpath, $filelist), $out, $err, $ret, null);

	return $ret;
}

function os_getZipType($file)
{
	$out = lxshell_output("file", "-b", $file);

	switch (true) {
		case csa($out, "gzip"):
			$out2 = lxshell_output("file", "-b", '-z', $file);

			if (csa($out2, "POSIX tar")) {
				return "tgz";
			} else {
				return "gz";
			}
		case csa($out, "bzip2"):
			$out2 = lxshell_output("file", "-b", '-z', $file);

			if (csa($out2, "POSIX tar")) {
				return "tbz2";
			} else {
				return "bz2";
			}
		case csa($out, "xz"):
			$out2 = lxshell_output("file", "-b", '-z', $file);

			if (csa($out2, "POSIX tar")) {
				return "txz";
			} else {
				return "xz";
			}
		case csa($out, "tar"):
			return "tar";
		case csa($out, "7z"):
			return "p7z";
		case csa($out, "rar"):
			return "rar";
		case csa($out, "Zip"):
			return "zip";
		default:
			return null;
	}
}

function lxfile_get_uncompressed_size($file)
{
	global $global_dontlogshell;

	$tmp = $global_dontlogshell;

	$global_dontlogshell = true;

	$out = lxshell_output("gzip", "-l", $file);

	if (csa($out, "not in")) {
		return 0;
	}

	$out = trim($out);

	if (!$out) {
		return 0;
	}

	$out = explode("\n", $out);
	$out = trimSpaces($out[1]);
	$out = explode(" ", $out);

	$global_dontlogshell = $tmp;

	return $out[1];
}

function lxfile_tmp_rm_rec($file)
{
	$file = expand_real_root($file);
	$file = remove_extra_slash($file);

	if ($file == '/') {
		return;
	}

//	lxshell_return("rm", "-rf", $file);
//	exec("'rm' -rf {$file}");

	$username = "__system__";
	// Argv only (security JJ1).
	$argv = function_exists('kloxo_shell_argv')
		? kloxo_shell_argv('rm', array('-rf', '--', $file))
		: array('rm', '-rf', '--', $file);

	return do_exec_system($username, null, $argv, $out, $err, $ret, null);
}

function lxfile_rm_content($dir)
{
	$username = "__system__";
	$dir = expand_real_root($dir);

	$list = lscandir_without_dot($dir);

	foreach($list as $l) {
		lxfile_rm("$dir/$l");
	}
}

function lxfile_rm_rec_content($file)
{
	global $login;

	$file = expand_real_root($file);
	$file = remove_extra_slash($file);

	$list = explode("/", $file);

	if (count($list) <= 2) {
		return;

	//	throw new lxException($login->getThrow("recursive_removal_low_level_directories_not_allowed"), '', $file);
	}

	if (preg_match("/\*/", $file)) {
		throw new lxException($login->getThrow('no_stars_allowed'), '', $file);
	}

	$list = lscandir_without_dot($file);

	foreach($list as $l) {
		if (!$l) { continue; }

	//	lxshell_return("rm", "-rf", "$file/$l");
	//	exec("'rm' -rf {$file}/{$l}");

		$username = "__system__";
		// Argv only (security JJ1).
		$argv = function_exists('kloxo_shell_argv')
			? kloxo_shell_argv('rm', array('-rf', '--', "{$file}/{$l}"))
			: array('rm', '-rf', '--', "{$file}/{$l}");

		do_exec_system($username, null, $argv, $out, $err, $ret, null);
	}
}

function lxfile_rm_rec($file)
{
	global $login;

	$file = expand_real_root($file);
	$file = remove_extra_slash($file);
	$list = explode("/", $file);

	if (count($list) <= 2) {
		return;

	//	throw new lxException($login->getThrow("recursive_removal_low_level_directories_not_allowed"), '', $file);
	}

	if (preg_match("/\*/", $file)) {
		throw new lxException($login->getThrow('no_stars_allowed'), '', $file);
	}

//	lxshell_return("rm", "-rf", $file);
//	exec("su; 'rm' -rf {$file}");

	$username = "__system__";
	// Argv only (security JJ1).
	$argv = function_exists('kloxo_shell_argv')
		? kloxo_shell_argv('rm', array('-rf', '--', $file))
		: array('rm', '-rf', '--', $file);

	return do_exec_system($username, null, $argv, $out, $err, $ret, null);
}

function lxfile_generic_chmod($file, $mod)
{
	lxfile_unix_chmod($file, $mod);
}

function lxfile_generic_chmod_rec($file, $mod)
{
	if (!$file) { return; }

	lxfile_unix_chmod_rec($file, $mod);
}

function lxfile_generic_chown($file, $mod)
{
	lxfile_unix_chown($file, $mod);
}

function lxfile_generic_chown_rec($file, $mod)
{
	if (!$file) { return; }

	lxfile_unix_chown_rec($file, $mod);
}

function lxfile_is_symlink($file)
{
	return lis_link($file);

}
function lxfile_unix_chown_rec($file, $mod)
{
	if (!$file) { return; }

	$file = expand_real_root($file);

	if (lxfile_is_symlink($file)) {
		lxshell_return("chown", $mod, $file);
	} else {
		lxshell_return("chown", "-R", $mod, $file);
	}
}

function lxfile_unix_chmod_rec($file, $mod)
{
	if (!$file) { return; }

	$file = expand_real_root($file);

	if (lxfile_is_symlink($file)) {
		lxshell_return("chmod",  $mod, $file);
	} else {
		lxshell_return("chmod", "-R", $mod, $file);
	}
}

function lxfile_mv_rec($dirsource, $dirdest)
{
	$username = "__system__";
	$dirdest = expand_real_root($dirdest);
	$dirsource = expand_real_root($dirsource);
	// Argv only (security JJ1).
	$argv = function_exists('kloxo_shell_argv')
		? kloxo_shell_argv('mv', array('-f', '--', $dirsource, $dirdest))
		: array('mv', '-f', '--', $dirsource, $dirdest);

	return do_exec_system($username, null, $argv, $out, $err, $ret, null);
}

function lxfile_cp_content_file($dirsource, $dirdest)
{
	$username = "__system__";
	$dirdest = expand_real_root($dirdest);
	$dirsource = expand_real_root($dirsource);

	if (!lxfile_exists($dirdest)) {
		lxfile_mkdir($dirdest);
	}

	$list = lscandir_without_dot($dirsource);

	foreach($list as $l) {
		if (!is_dir("$dirsource/$l")) {
			lxfile_cp("$dirsource/$l", "$dirdest/$l");
		}
	}
}

function lxfile_cp_content($dirsource, $dirdest)
{
	$username = "__system__";
	$dirdest = expand_real_root($dirdest);
	$dirsource = expand_real_root($dirsource);

	if (!lxfile_exists($dirdest)) {
		lxfile_mkdir($dirdest);
	}

	$list = lscandir_without_dot($dirsource);

	foreach($list as $l) {
		lxfile_cp_rec("$dirsource/$l", "$dirdest/$l");
	}
}

// MR -- taken from (with mod)
// http://stackoverflow.com/questions/2050859/copy-entire-contents-of-a-directory-to-another-using-php
function xcopy($src, $dest)
{
	foreach (scandir($src) as $file) {
		if (!is_readable($src.'/'.$file)) { continue; }

		if (is_dir($file) && ($file!='.') && ($file!='..') ) {
			mkdir($dest . '/' . $file);
			xcopy($src.'/'.$file, $dest.'/'.$file);
		} else {
			copy($src.'/'.$file, $dest.'/'.$file);
		}
	}
}

function lxfile_cp_rec($dirsource, $dirdest)
{ 
	$username = "__system__";
	$dirdest = expand_real_root($dirdest);
	$dirsource = expand_real_root($dirsource);
	// Argv only (security JJ1).
	$argv = function_exists('kloxo_shell_argv')
		? kloxo_shell_argv('cp', array('-a', '--', $dirsource, $dirdest))
		: array('cp', '-a', '--', $dirsource, $dirdest);

	return do_exec_system($username, null, $argv, $out, $err, $ret, null);
} 


function lxfile_size($file)
{
	if (!lxfile_exists($file)) {
		return 0;
	}

	$file = expand_real_root($file);
	// PHP filesize / argv stat — never shell string (security HH1).
	$sz = @filesize($file);
	if ($sz !== false) {
		return (float) $sz;
	}
	if (function_exists('lxshell_output')) {
		return (float) trim((string) lxshell_output('stat', '-c', '%s', $file));
	}

	return 0.0;
}

function lxfile_unix_chmod($file, $mod) 
{
	global $login;

	$file = expand_real_root($file);
/*
	if ($mod & S_ISUID || $mod & S_ISGID) {
		throw new lxException($login->getThrow('setuid_not_allowed_in_chmod'), '', $mod);
	}
*/
	$ret =  lxshell_return("chmod", $mod, $file);

	// There is difference in the return values betweent eh internal and the external chmod
	if ($ret) {
		dprint("Chmod Error in file $file\n");
	}
}

function lxfile_unix_chown($file, $mod)
{
	if (!lxfile_exists($file)) {
		return 0;
	}

	$file = expand_real_root($file);
	log_filesys("Chown $file to $mod");

	$stat = stat($file);

	//dprintr($stat);
	if (!is_dir($file) && $stat['nlink'] > 1) {
		log_log("link_error", "$file is a hard link not chowning");

		return;
	}

	if(lis_link($file)) {
		log_log("link_error", "$file is link so no chown to $mod");
		return;
	}

	$group = null;

	if (csa($mod, ':')) {
		list($user, $group) = explode(':', $mod);
	} else {
		$user = $mod;
	}

	if (is_numeric($group)) {
		$group = (int) $group;
	}

	if ($group) {
		chgrp($file, $group);
	}

	if (is_numeric($user)) {
		$user = (int) $user;
	}

	$ret = chown($file, $user);

	if (!$ret) {
		dprint("Chown Error in file $file\n");
	}

	return $ret;
}

function lxshell_background($cmd)
{
	global $gbl, $sgbl, $login, $ghtml;
	global $global_dontlogshell;

	$arglist = array();
	for ($i = 1; $i < func_num_args(); $i++) {
		$arglist[] = func_get_arg($i);
	}

	// Argv + fork/exec when possible — never shell-string background runner (security II5 / HH1).
	// Do not wrap with ionice — a missing/privileged ionice left FPM waiting
	// on the worker (Add Client busy spinner) while useradd/mail ran.
	$bin = expand_real_root((string) $cmd);
	$argv = array($bin);
	foreach ($arglist as $a) {
		if ($a === '' || $a === null) {
			continue;
		}
		if (is_array($a)) {
			foreach ($a as $aa) {
				if ($aa === '' || $aa === null) {
					continue;
				}
				$argv[] = expand_real_root((string) $aa);
			}
		} else {
			$argv[] = expand_real_root((string) $a);
		}
	}
	foreach ($argv as $a) {
		if (!is_string($a) || str_contains($a, chr(0))) {
			return false;
		}
	}

	$logLine = 'Background: ' . implode(' ', array_map('escapeshellarg', $argv));
	if (!$global_dontlogshell) {
		log_shell($logLine);
	} else {
		log_log("other_cmd", $logLine);
	}

	// Never fork from panel FPM: Add Domain / Domain Home kicks used to
	// double memory in the same worker and help OOM the host. Drop-queue
	// drains must run from kloxo-backend / watchdog / CLI instead.
	$sapi = PHP_SAPI;
	if ($sapi === 'fpm-fcgi' || $sapi === 'cgi-fcgi' || $sapi === 'cgi') {
		if (function_exists('log_log')) {
			log_log('other_cmd', 'lxshell_background refused under ' . $sapi . ': ' . $logLine);
		}

		return false;
	}

	if (function_exists('pcntl_fork') && function_exists('pcntl_exec')) {
		$pid = @pcntl_fork();
		if ($pid === -1) {
			return false;
		}
		if ($pid === 0) {
			if (function_exists('posix_setsid')) {
				@posix_setsid();
			}
			@fclose(STDIN);
			@fclose(STDOUT);
			@fclose(STDERR);
			$bin0 = $argv[0];
			$args = array_slice($argv, 1);
			pcntl_exec($bin0, $args);
			exit(127);
		}

		return true;
	}

	// Fallback without shell metacharacters: proc_open argv and detach best-effort.
	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$run = $argv;
	if (is_executable('/usr/bin/setsid')) {
		array_unshift($run, '/usr/bin/setsid');
	}
	$p = @proc_open($run, $desc, $pipes, null, null, array('bypass_shell' => true));
	if (is_resource($p)) {
		// Do not wait; mark running so PHP does not reap immediately as a zombie on some SAPIs.
		@proc_get_status($p);

		return true;
	}

	return false;
}

function do_exec_system($username, $dir, $cmd, &$out, &$err, &$ret, $input)
{
	global $gbl, $sgbl, $login, $ghtml;
	global $global_shell_out, $global_shell_error, $global_shell_ret;
	global $global_dontlogshell;

	// $username: lxmisc (setuid wrapper) was never actually invoked here and is gone (audit C4).
	$fename = tempnam($sgbl->__path_tmp, "system_errr");

	$oldpath = null;

	if ($dir) {
		lxfile_mkdir($dir);
		$oldpath = getcwd();
		chdir($dir);
	}

	$descriptorspec = array( 0 => array("pipe", "r"), 1 => array("pipe", "w"), 2 => array("file", $fename, "a"));

	os_set_path();
	// Prefer argv array (security DD3 / JJ1); refuse legacy shell strings.
	if (is_array($cmd)) {
		foreach ($cmd as $a) {
			if (!is_string($a) || str_contains($a, chr(0))) {
				$ret = 127;
				$out = '';
				$err = 'refused unsafe argv';
				if ($oldpath) {
					chdir($oldpath);
				}

				return;
			}
		}
		$process = @proc_open($cmd, $descriptorspec, $pipes);
	} else {
		$ret = 127;
		$out = '';
		$err = 'refused shell-string cmd (use argv)';
		if ($oldpath) {
			chdir($oldpath);
		}

		return;
	}
	$out = null;

	if (is_resource($process)) {
		// $pipes now looks like this:
		// 0 => writeable handle connected to child stdin
		// 1 => readable handle connected to child stdout
		// Any error output will be appended to $fename

		if ($input) {
			fwrite($pipes[0], $input);
		}

		fclose($pipes[0]);

		if (isset($pipes[1]) && is_resource($pipes[1])) {
			while (!feof($pipes[1])) {
				$out .= fgets($pipes[1], 1024);
			}
			fclose($pipes[1]);
		}

		// It is important that you close any pipes before calling
		// proc_close in order to avoid a deadlock
		$ret = proc_close($process);
	}

	$err = str_replace("\n", "", lfile_get_contents($fename));

	if ($err === '') {
		$err = "(No message)";
	}

	unlink($fename);

	// $tcwd = ':' . getcwd();
	 $tcwd = '';

	$cmd = str_replace("\n", ";", $cmd);

	if ($ret) {
		log_shell_error("$err: [($username$tcwd) $cmd]");
	}

	if ($global_dontlogshell) {
		log_log("other_cmd", "$ret: $err [($username$tcwd) $cmd]");
	} else {
		log_shell("$ret: $err [($username$tcwd) $cmd]");
	}

	$global_shell_ret = $ret;
	$global_shell_out = $out;
	$global_shell_error = $err;

	if ($oldpath) {
		chdir($oldpath);
	}
}

// MR -- new function for handle ftp connection
function lxftp_connect($ftp_server)
{
/*
	list($ftp_scheme, $ftp_rest) = explode("://", $ftp_server);

	// Remark - if not use 'ftp://' or 'ftps://', ftp_scheme=ftp_server and ftp_rest=null
	if (!$ftp_rest) {
		$ftp_rest = $ftp_scheme;
		$ftp_scheme = "ftp";
	}

	list($ftp_domain, $ftp_port) = explode(":", $ftp_rest);

	if (!$ftp_port) {
		$ftp_port = "21";
	}

	if ($ftp_scheme === 'ftps') {
		return ftp_ssl_connect($ftp_domain, $ftp_port);
	} elseif ($ftp_scheme === 'ftpes') {
		// MR -- still unfinished
	} else {
		return ftp_connect($ftp_domain, $ftp_port);
	}
*/
	if (strpos($ftp_server, '://') !== false) {
		// no action
	} else {
		$ftp_server = 'ftp://' . $ftp_server;
	}

	$list = parse_url($ftp_server);

	$scheme = $list['scheme'];
	$host = $list['host'];
	$port = (isset($list['port'])) ? $list['port'] : '21';

	if ($scheme === 'ftps') {
		return ftp_ssl_connect($host, $port);
	} elseif ($scheme === 'ftpes') {
		// MR -- still unfinished
	} else {
		return ftp_connect($host, $port);
	}
}

