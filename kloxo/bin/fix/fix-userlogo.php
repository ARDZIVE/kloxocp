<?php

// by mustafa@bigraf.com for Kloxo-MR

include_once "lib/html/include.php";

initProgram('admin');

$list = parse_opt($argv);

$select = (isset($list['select'])) ? $list['select'] : 'all';
$nolog  = (isset($list['nolog'])) ? $list['nolog'] : null;

setFixUserlogo($select);

/** Argument-array cp (security AA6). */
function kloxo_fix_cp(string $src, string $dst): bool
{
	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$p = @proc_open(array('cp', '-rf', '--', $src, $dst), $desc, $pipes);
	if (!is_resource($p)) {
		return false;
	}

	return proc_close($p) === 0;
}

/* ****** BEGIN - setFixUserlogo ***** */

function setFixUserlogo($select)
{
	global $gbl, $sgbl, $login, $ghtml, $nolog;

	log_cleanup("Fix user logo", $nolog);

	if (file_exists("/opt/kloxo/httpdocs/user-logo.png")) {
		kloxo_fix_cp('/opt/kloxo/httpdocs/user-logo.png', '/home/kloxo/httpd/user-logo.png');
		log_cleanup("- User logo copy from -> /opt/kloxo/user-logo.png", $nolog);
		log_cleanup("- User logo copy to -> /home/kloxo/httpd/user-logo.png", $nolog);
	}
	else {
		log_cleanup("- Cleaned user logo source at /opt/kloxo/file/user-logo.png", $nolog);
		exit;
	}

	if ($select === 'defaults') {
		setFixUserlogoDefaultPages();
	}
	else if ($select === 'domains') {
		setFixUserlogoDomainPages();
	}
	else if ($select === 'all') {
		setFixUserlogoDefaultPages();
		setFixUserlogoDomainPages();
	}
	else {
		log_cleanup("- Wrong --select= entry");
	}
}

function setFixUserlogoDefaultPages()
{
	global $nolog;

	$list = array('cp', 'default', 'disable', 'webmail');

	foreach($list as $k => $l) {
		if (!preg_match('/^[a-z]+$/', $l)) {
			continue;
		}
		kloxo_fix_cp('/home/kloxo/httpd/user-logo.png', "/home/kloxo/httpd/{$l}/images/user-logo.png");
		kloxo_fix_cp('/home/kloxo/httpd/user-logo.png', "/home/kloxo/httpd/{$l}/images/logo.png");
		log_cleanup("- User logo for default pages copy to -> /home/kloxo/httpd/{$l}/images/logo.png", $nolog);
	}

	kloxo_fix_cp('/home/kloxo/httpd/user-logo.png', '/opt/kloxo/httpdocs/login/images/user-logo.png');
	kloxo_fix_cp('/home/kloxo/httpd/user-logo.png', '/opt/kloxo/httpdocs/login/images/logo.png');
	log_cleanup("- User logo copy to -> /opt/kloxo/httpdocs/login/images/user-logo.png", $nolog);
}

function setFixUserlogoDomainPages()
{
	global $gbl, $sgbl, $login, $ghtml;

	$login->loadAllObjects('client');
	$list = $login->getList('client');

	foreach($list as $c) {
		$cinfo = posix_getpwnam($c->nname);

		if (!$cinfo) { continue; }

		$clname = $c->getPathFromName('nname');
		$cdir = "/home/{$clname}";
		$dlist = $c->getList('domaina');

		foreach((array) $dlist as $l) {
			$web = $l->getObject('web');
			$docroot = $web->getFullDocRoot();

			if (file_exists("$docroot/images")) {
				kloxo_fix_cp('/home/kloxo/httpd/user-logo.png', "{$docroot}/images/user-logo.png");
				kloxo_fix_cp('/home/kloxo/httpd/user-logo.png', "{$docroot}/images/logo.png");
			}
		}
	}
}

/* ****** END - setFixUserlogo ***** */
