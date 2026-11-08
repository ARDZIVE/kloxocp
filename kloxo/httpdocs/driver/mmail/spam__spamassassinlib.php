<?php 

class Spam__Spamassassin extends lxDriverClass
{
	static function installMe()
	{
		// Ubuntu apt packages (universe); the CentOS *-toaster rpms and simscan do not exist.
		KloxoApt::installOrThrow(array('spamassassin', 'spamc', 'ripmime'));

		// spamd is the unit name on Ubuntu 24.04+; older images use spamassassin.
		if (lxshell_return('systemctl', 'enable', '--now', 'spamd') !== 0) {
			lxshell_return('systemctl', 'enable', '--now', 'spamassassin');
		}
	}

	/**
	 * Per-mailbox user_prefs path, or '' when domain/user are not plain names
	 * (they come from object names; never allow path tricks).
	 */
	static function prefsPath($domain, $user)
	{
		$domain = strtolower((string) $domain);
		$user = strtolower((string) $user);
		if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $domain) !== 1
			|| str_contains($domain, '..')
			|| preg_match('/^[a-z0-9][a-z0-9._+-]{0,63}$/', $user) !== 1
			|| str_contains($user, '..')) {
			return '';
		}

		return "/home/kloxomail/mail/spamassassin/{$domain}/{$user}/.spamassassin/user_prefs";
	}

	/** Owner of the prefs tree: the Ubuntu spamd account when present. */
	static function prefsOwner()
	{
		if (function_exists('posix_getpwnam') && posix_getpwnam('debian-spamd') !== false) {
			return 'debian-spamd:debian-spamd';
		}

		return 'lxlabs:lxlabs';
	}

	static function uninstallMe()
	{
		// Keep the packages; stop the daemon AND take it off boot (unit is spamd on 24.04+,
		// spamassassin earlier; before, it was only stopped and kept a green Sb icon).
		KloxoMta::turnOff(array('spamd', 'spamassassin'));
	}

	function dbactionAdd()
	{
		//
		$this->syncSpamUserPref();
	}

	function dbactionDelete()
	{
		//
	}

	function syncSpamUserPref()
	{
		global $gbl, $sgbl, $ghtml;
		// The parent can be either a domain or a user. CHeck for the @ sign.
		if (csa($this->main->nname, "@")) {
			list($user, $domain) = explode("@", $this->main->nname);
		} else {
			$domain = $this->main->nname;
			$user = null;
		}

		if (!$user) {
			return;
		}

		// qmail/vpopmail are gone on Ubuntu: no mmail__qmail::getDir, no vpopmail:vchkpw.
		$prefpath = self::prefsPath($domain, $user);
		if ($prefpath === '') {
			return;
		}
		$owner = self::prefsOwner();

		if (!lxfile_exists(dirname($prefpath))) {
			lxfile_mkdir(dirname($prefpath));
			lxfile_generic_chown(dirname($prefpath), $owner);
		}

		// One directive per line: strip control characters so a value cannot add directives.
		$clean = function ($v) {
			return trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $v));
		};
		$score = is_numeric($this->main->spam_hit) ? (string) $this->main->spam_hit : '5';
		$fdata = null;
		$fdata .= "required_score  " . $score . "\n";
		$fdata .= "ok_locales   all\n";
		$fdata .= "rewrite_header Subject  " . $clean($this->main->subject_tag) . "\n";
		foreach ((array) $this->main->wlist_a as $wlist) $fdata .= "whitelist_from   " . $clean($wlist->nname) . "\n";
		$fdata .= "#***********************************\n";
		foreach ((array) $this->main->blist_a as $blist) $fdata .= "blocklist_from   " . $clean($blist->nname) . "\n";

		lxfile_rm($prefpath);
		lfile_write_content($prefpath, $fdata, $owner);
	}

	function dbactionUpdate($subaction)
	{

		switch ($subaction) {
			case "full_update":
				$this->syncSpamUserPref();
				break;
			case "update":
			case "add_wlist_a" :
			case "add_blist_a" :
			case "delete_blist_a" :
			case "delete_wlist_a" :
				$this->syncSpamUserPref();
				break;
		}
	}
}
