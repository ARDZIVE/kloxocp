<?php

if (!class_exists('KloxoBackup', false)) {
	include_once dirname(__DIR__) . '/php/kloxo_backup.php';
}

class lxbackup extends Lxdb
{
	static $__desc = array("", "", "backup");
	static $__desc_nname = array("n", "", "backup");
	static $__desc_ftp_server = array("n", "", "ftp_server");
	static $__desc_ssh_server = array("n", "", "ssh_server");
	static $__desc_rm_username = array("n", "", "username");
	static $__desc_rm_password = array("n", "", "password");
	static $__desc_rm_directory = array("", "", "directory");
	static $__desc_upload_type = array("", "", "upload_type");
	static $__desc_backupschedule_flag = array("q", "", "allow_schedule_backup");
	static $__desc_backupschedule_type = array("", "", "schedule_backup");
	static $__desc_backupschedule_time = array("", "", "schedule_backup_time");
	static $__desc_backup_from_file_f = array("n", "", "backup_from_file");
	static $__desc_restore_from_file_f = array("n", "", "restore_from_file");
	static $__desc_backup_ftp_file_f = array("n", "", "filename_on_the_ftp_server");
	static $__desc_restore_ftp_file_f = array("n", "", "filename_on_the_ftp_server");
	static $__desc_backup_to_file_f = array("n", "", "backup_file_initial_string");
	static $__desc_send_email = array("f", "", "send_email_after_backup");
	static $__desc_backupstage = array("", "", "last_backup_status");
	static $__desc_restorestage = array("", "", "last_restore_status");
	static $__desc_rm_last_number = array("", "", "keep_this_many_backups_on_the_server");
	static $__desc_rm_last_number_v_5 = array("", "", "keep_this_many_backups_on_the_server");
	static $__desc_dont_verify_ftp_f = array("f", "", "dont_verify_ftp_credentials");
	static $__desc_temp_f = array("", "", "");
	static $__desc_upload_to_ftp = array("f", "", "upload_files_to_remote_server");
	static $__desc_no_local_copy_flag = array("f", "", "dont_keep_a_local_copy");
	static $__desc_backupextra_stopvpsflag = array('f', "", "stop_vps_while_backup_snapshot", "");
	static $__desc_prebackup_note_f = array("", "", "before_you_backup");
	static $__desc_prerestore_note_f = array("", "", "after_you_restore");

	static $__acdesc_show = array("", "", "Backup / Restore");
	static $__acdesc_update_backup = array("", "", "Backup Now");
	static $__acdesc_update_restore = array("", "", "Restore");
	static $__acdesc_update_ftp_conf = array("", "", "FTP Configuration");
	static $__acdesc_update_schedule_conf = array("", "", "Schedule Configuration");
	static $__acdesc_update_restore_from_file = array("", "", "Restore From File");
	static $__acdesc_update_restore_from_ftp = array("", "", "Restore From FTP");

	function fileManagerDir()
	{
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__) . '/php/kloxo_backup.php';
		}
		$parent = $this->backupParent();
		$table = (is_object($parent) && method_exists($parent, 'get__table')) ? $parent->get__table() : 'client';
		$pname = (is_object($parent) && isset($parent->nname) && $parent->nname !== '')
			? (string) $parent->nname : 'admin';

		return KloxoBackup::ensureDir($table, $pname);
	}

	function getFfileFromVirtualList($name)
	{
		$parent = $this->backupParent();

		$name = coreFfile::getRealpath($name);
		$name = "/$name";

		$root = $this->fileManagerDir() . '/';

		$syncserver = "localhost";
		if (is_object($parent) && method_exists($parent, 'isClient') && !$parent->isClient()
			&& isset($parent->syncserver) && is_string($parent->syncserver) && $parent->syncserver !== '') {
			$syncserver = $parent->syncserver;
		}

		try {
			rl_exec_get(null, $syncserver, array("lxbackup", "MakeSureDirectoryExists"), array($root));
		} catch (Throwable $e) {
			self::MakeSureDirectoryExists($root);
		}

		$ffile = new Ffile(null, $syncserver, $root, $name, 'lxlabs');
		$ffile->__parent_o = $this;
		try {
			$ffile->get();
		} catch (Throwable $e) {
			$ffile->ttype = 'directory';
			$ffile->readonly = 'off';
			$ffile->__flag_showheader = true;
			$ffile->setFileType();
		}
		if (!isset($ffile->ttype) || $ffile->ttype === '' || $ffile->nname === '/') {
			$ffile->ttype = 'directory';
			$ffile->readonly = 'off';
			$ffile->__flag_showheader = true;
			$ffile->setFileType();
		}

		return $ffile;
	}

	static function MakeSureDirectoryExists($name)
	{
		if (!lxfile_exists($name)) {
			lxfile_mkdir($name);
			lxfile_generic_chown($name, "lxlabs");
		}
	}

	function createShowShowlist()
	{
		$alist = null;
	//	$alist['ffile'] = null;

		return $alist;
	}

	function isSync() { return false; }

	function update($subaction, $param)
	{
		global $login;

		$parent = $this->backupParent();
		if (is_object($parent) && isset($parent->priv) && is_object($parent->priv)
			&& method_exists($parent->priv, 'isOn') && !$parent->priv->isOn('backup_flag')) {
			throw new lxException($login->getThrow('no_permission_to_backup'));
		}

		return $param;
	}

	function postUpdate()
	{
		// We need to write because the fixphpini reads everything from the database.
		$this->write();

		if ($this->subaction === 'schedule_conf') {
			if (is_file('/script/fix-cron-backup')) {
				lxshell_background('sh', '/script/fix-cron-backup');
			}
		}
	}

	static function getMetaData($file)
	{
		global $sgbl, $login;

		$progname = $sgbl->__var_program_name;

		if (!lxfile_exists($file)) {
			throw new lxException($login->getThrow('could_not_find_file'), '', $file);
		}

		$tmpdir = lxbackup::createTmpDirIfitDoesntExist($file, false);
		print_time("create_tmp_dir", "Creating Tmp Directory");
		$filename = recursively_get_file($tmpdir, "$progname.file");

		// KLoxo has to recognize lxadmin's backup file.
		if (!$filename && $sgbl->isKloxoForRestore()) {
			$filename = recursively_get_file($tmpdir, "lxadmin.file");
		}

		if (!is_string($filename) || $filename === '' || !is_file($filename)) {
			lxfile_tmp_rm_rec($tmpdir);
			throw new lxException($login->getThrow('backup_file_corrupted'), '', $file);
		}

		$raw = file_get_contents($filename);
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__) . '/php/kloxo_backup.php';
		}
		try {
			$rem = is_string($raw) && $raw !== '' ? KloxoBackup::unserializeMetadata($raw) : false;
		} catch (Throwable $e) {
			lxfile_tmp_rm_rec($tmpdir);
			throw new lxException($login->getThrow('backup_file_corrupted'), '', $filename);
		}
		lxfile_tmp_rm_rec($tmpdir);

		if (!is_object($rem)) {
			throw new lxException($login->getThrow('backup_file_corrupted'), '', $filename);
		}

		return $rem;
	}

	function backupcheckForConsistency($tree, $param)
	{
		global $login;

		$parent = $this->backupParent();
		if (!is_object($parent)) {
			throw new lxException($login->getThrow('no_parent'), '', 'restore');
		}

		print_time("create_tmp_dir");
		$file = $this->getFtpOrLocal($param);
		dprint($file);

		if ($parent->isClient() || $parent->isLocalhost('syncserver')) {
			$rem = self::getMetaData($file);
		} else {
			$rem = rl_exec_get($parent->__masterserver, $parent->syncserver, 
				array("lxbackup", "getMetaData"), array($file));
		}

		print_time("check_cons");
		$trulist = null;

		if (isset($param['_accountselect']) && $param['_accountselect']) {
			$trulist = $param['_accountselect'];
		}

		$this->__var_backup = $rem->bobject;
		$this->__var_backup->checkForConsistency($tree, $trulist, false);
		print_time("check_cons", "Checking Consistency");

		if (csa($file, "__lx_temperoryftp_file")) {
			lunlink($file);
		}
	}

	function updateFtp_conf($param)
	{
		global $login;

		$param['rm_username'] = fix_meta_character($param['rm_username'] ?? '');
		$param['rm_password'] = $param['rm_password'] ?? '';
		$param['ftp_server'] = $param['ftp_server'] ?? '';
		$param['upload_to_ftp'] = $param['upload_to_ftp'] ?? '';
		$param['dont_verify_ftp_f'] = $param['dont_verify_ftp_f'] ?? '';

		if (is_star_password($param['rm_password'])) {
			$param['rm_password'] = $this->rm_password;
		}

		if (isOn($param['upload_to_ftp']) && !isOn($param['dont_verify_ftp_f'])) {
			$fn = lxftp_connect($param['ftp_server']);
			$mylogin = ftp_login($fn, $param['rm_username'], $param['rm_password']);

			if (!$mylogin) {
				$p = error_get_last();
				throw new lxException($login->getThrow('could_not_connect_to_ftp_server'), '', $p);
			}

			ftp_pasv($fn, true);
		}

		return $param;
	}

	function updateform($subaction, $param)
	{
		global $gbl, $sgbl, $login;

		$parent = $this->backupParent();

		$tree = createTreeObject('name', null, null, null, null, null, null);
		if (is_object($gbl)) {
			$gbl->__var_restore_tree = $tree;
		}

		if (!empty($this->rm_username)) {
			$this->rm_username = fix_meta_character($this->rm_username);
		}

		$vlist = array();
		switch ($subaction) {
			case "backup":
				$vlist['prebackup_note_f'] = array('M', KloxoBackup::preBackupNotes());
				$ftp = isset($this->ftp_server) ? trim((string) $this->ftp_server) : '';
				if ($ftp !== '') {
					$vlist['ftp_server'] = array('M', null);
					$vlist['rm_username'] = array('M', null);
				//	$vlist['rm_password'] = array('M', '***');
					$vlist['rm_password'] = null;
					$vlist['upload_to_ftp'] = array('M', null);
				} else {
					$vlist['upload_to_ftp'] = array('M', "Ftp Server Not Set");
				}
				$stage = isset($this->backupstage) ? (string) $this->backupstage : '';
				if ($stage === 'doing') {
					$locked = false;
					if (is_object($parent) && method_exists($parent, 'checkIfLockedForAction')) {
						try {
							$locked = (bool) $parent->checkIfLockedForAction('backup');
						} catch (Throwable $e) {
							$locked = false;
						}
					}
					if (!$locked) {
						$stage = 'program_interrupted';
						$this->backupstage = $stage;
					}
				}
				// Only tokenise short status words; keep full failure text readable.
				if (in_array($stage, array('done', 'doing', 'program_interrupted'), true)) {
					$this->backupstage = fix_nname_to_be_variable($stage);
				}
				$vlist['backupstage'] = array('M', null);
				$vlist['backup_to_file_f'] = null;

				if (is_object($parent) && method_exists($parent, 'backupExtraVar')) {
					$parent->backupExtraVar($vlist);
				}

				$vlist['__v_button'] = 'Backup Now';

				break;

			case "restore_confirm":
				$gbl->__var_tmp_disabled_flag = false;

				if ($param) {
					$this->backupcheckForConsistency($tree, $param);
				}

				$vlist['__v_childheir'] = '__var_backup';
				$vlist['__v_showcheckboxflag'] = true;

				if ($sgbl->isDebug()) {
					$vlist['__v_resourcefunc'] = "getBackupChildList";
				} else {
					$vlist['__v_resourcefunc'] = "getDisplayBackupChildList";
				}

			//	$vlist['__v_resourcefunc'] = "getDisplayBackupChildList";

				$vlist['__v_param'] = $param;
				$vlist['__v_button'] = 'Restore Now';
				print_time("restore_process", "Restore Processing Took");

				break;

			case "restore_confirm_confirm":
				$gbl->__var_tmp_disabled_flag = true;
				print_time("restore_process");

				if ($param) {
					$this->backupcheckForConsistency($tree, $param);
				}

				$vlist['__v_childheir'] = '__var_backup';

				if ($sgbl->isDebug()) {
					$vlist['__v_resourcefunc'] = "getBackupChildList";
				} else {
					$vlist['__v_resourcefunc'] = "getDisplayBackupChildList";
				}

			//	$vlist['__v_resourcefunc'] = "getDisplayBackupChildList";
				$vlist['__v_showcheckboxflag'] = true;
				$vlist['__v_param'] = $param;
				$vlist['__v_button'] = 'Restore Now';
				print_time("restore_process", "Restore Processing Took");

				break;

			case "schedule_conf":
				// Kloxo-MR: Schedule Backup select, Time To Backup, keep-count, Updateall.
				$simple = is_object($parent) && method_exists($parent, 'isSimpleBackup')
					&& $parent->isSimpleBackup();
				$sched = $simple
					? array('disabled', 'weekly', 'monthly')
					: array('disabled', 'daily', 'weekly', 'monthly');

				if (!$this->backupschedule_type) {
					$this->backupschedule_type = 'disabled';
				}

				// Privilege lives on the parent client; missing priv allows (same as checkButton).
				$canSched = true;
				try {
					$who = is_object($parent) ? $parent : $this;
					$canSched = method_exists($who, 'checkButton') ? $who->checkButton('backupschedule_flag') : true;
				} catch (Throwable $e) {
					$canSched = true;
				}
				if ($canSched) {
					$vlist['backupschedule_type'] = array('s', $sched);
				} else {
					$vlist['backupschedule_type'] = array('M', 'Disabled');
				}

				$time = range(0, 23);
				$isAdmin = is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin();
				if ($isAdmin) {
					$vlist['backupschedule_time'] = array('s', $time);
				} else {
					$vlist['backupschedule_time'] = array('M', null);
				}

				$parentName = (is_object($parent) && isset($parent->nname))
					? (string) $parent->nname : '';
				if ($parentName === 'admin' || $parentName === '') {
					$this->setDefaultValue('backupschedule_time', 6);
				} else {
					$this->setDefaultValue('backupschedule_time', 18);
				}
				if (!isset($this->rm_last_number) || $this->rm_last_number === '' || $this->rm_last_number === null) {
					$this->setDefaultValue('rm_last_number', 5);
				}

				$vlist['rm_last_number'] = null;
				$vlist['__v_updateall_button'] = array();

				break;

			case "ftp_conf":
				$vlist['ftp_server'] = null;
			//	$vlist['ssh_server'] = null;
				$vlist['rm_username'] = null;
			//	$vlist['rm_password'] = array('m', get_star_password());
				$vlist['rm_password'] = null;
				$vlist['rm_directory'] = null;
				$vlist['upload_to_ftp'] = null;
				$vlist['upload_type'] = array('M', 'ftp');
				$vlist['no_local_copy_flag'] = null;
				$vlist['dont_verify_ftp_f'] = null;
				$vlist['__v_updateall_button'] = array();

				break;

			case "restore_from_ftp":
				$vlist['prerestore_note_f'] = array('M', KloxoBackup::postRestoreNotes(array()));
				$ftp = isset($this->ftp_server) ? trim((string) $this->ftp_server) : '';
				if ($ftp === '') {
					$vlist['ftp_server'] = array("M", "Ftp Server is Not Set");
					$vlist['__v_button'] = 'Update';
					break;
				}
			/*
				if (!$parent->checkIfLockedForAction('restore')) {
					if ($this->restorestage === 'doing') {
						$this->restorestage = 'program_interrupted';
					}
				}
			*/
				$vlist['ftp_server'] = array('M', null);
				$vlist['rm_username'] = array('M', null);
			//	$vlist['rm_password'] = array('M', "****");
				$vlist['rm_password'] = null;
				$vlist['restorestage'] = array('M', null);
				$vlist['backup_ftp_file_f'] = null;
				$vlist['__v_next'] = 'restore_confirm';
				$vlist['__v_button'] = 'Start Restore Process';

				break;

			case "restore_from_file":
			/*
				if (!$parent->checkIfLockedForAction('restore')) {
					if ($this->restorestage === 'doing') {
						$this->restorestage = 'program_interrupted';
					}
				}
			*/
				$vlist['prerestore_note_f'] = array('M', KloxoBackup::postRestoreNotes(array()));
				$vlist['restorestage'] = array('M', null);
				$vlist['backup_from_file_f'] = array('L', "/");
				$vlist['__v_next'] = 'restore_confirm';
				$vlist['__v_button'] = 'Start Restore Process';

				break;
		}

		return $vlist;
	}

	static function backupPropertyTabs()
	{
		// Exactly five beige tabs (Kloxo-MR Backup / Restore page).
		// Schedule is always listed (privilege gates the Schedule Backup select, not the tab).
		return array(
			'a=show',
			'a=updateform&sa=ftp_conf',
			'a=updateform&sa=schedule_conf',
			'a=show&l[class]=ffile&l[nname]=/',
			'l[class]=ffile&l[nname]=/&a=updateform&sa=upload',
		);
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::backupPropertyTabs();

		return $alist;
	}


	function createShowAlist(&$alist, $subaction = null)
	{
	//	global $login;

	//	$alist['__title_main'] = $login->getKeywordUc('actions');
	//	$alist[] = 'a=updateform&sa=restore_from_file';
	//	$alist[] = 'a=updateform&sa=restore_from_ftp';

		return $alist;
	}

	function createShowUpdateform()
	{
		$uflist['backup'] = null;
		$uflist['restore_from_file'] = null;
		$uflist['restore_from_ftp'] = null;

		return $uflist;
	}

	function getId()
	{
		$n = isset($this->nname) ? (string) $this->nname : '';
		if ($n === '') {
			return '';
		}
		$parts = getClassAndName($n);

		return (is_array($parts) && isset($parts[1]) && $parts[1] !== '') ? $parts[1] : $n;
	}

	function backupParent()
	{
		try {
			$parent = $this->getParentO();
			if (is_object($parent)) {
				return $parent;
			}
		} catch (Throwable $e) {
		}

		$n = isset($this->nname) ? (string) $this->nname : '';
		if ($n !== '' && function_exists('getParentNameAndClass')) {
			$parts = getParentNameAndClass($n);
			if (is_array($parts) && isset($parts[0], $parts[1]) && $parts[0] !== '' && $parts[1] !== ''
				&& class_exists($parts[0])) {
				try {
					$parent = new $parts[0]($this->__masterserver, $this->__readserver, $parts[1]);
					$parent->get();
					$this->__parent_o = $parent;

					return $parent;
				} catch (Throwable $e) {
				}
			}
		}

		return null;
	}

	function updateBackupRestore($param, $type)
	{
		global $gbl, $login, $ghtml;

		$parent = $this->backupParent();
		if (!is_object($parent)) {
			throw new lxException($login->getThrow('no_parent'), '', $type);
		}

		$stagevar = $type . "stage";

		if (method_exists($parent, 'checkIfLockedForAction') && $parent->checkIfLockedForAction($type)) {
			throw new lxException($login->getThrow('going_on'), '', $type);
		}

		$bpath = KloxoBackup::ensureDir($parent->get__table(), (string) $parent->nname);

		if ($type === 'backup') {
			if ($parent->get__table() === 'vps') {
				$num = rl_exec_get(null, $parent->syncserver, "get_total_files_in_directory", array($bpath));
				if (isQuotaGreaterThanOrEq($num, $parent->priv->backup_num)) {
					throw new lxException($login->getThrow("backup_number_exceeded"), '', "$num > {$parent->priv->backup_num}");
				}
			}
		}

		// Write the stage first; backup.php must see the saved object.
		if (is_array($param)) {
			foreach ($param as $k => $v) {
				$this->$k = $v;
			}
		}

		$this->$stagevar = 'doing';
		$this->backuptype = $type;
		$this->metadbaction = 'writeonly';
		$this->dbaction = 'update';
		$this->write();

		$pclass = $parent->get__table();
		$pname = (string) $parent->nname;
		if (!empty($this->parent_clname) && method_exists($this, 'getParentClass')) {
			$pc = $this->getParentClass();
			$pn = $this->getParentName();
			if (is_string($pc) && $pc !== '') {
				$pclass = $pc;
			}
			if (is_string($pn) && $pn !== '') {
				$pname = $pn;
			}
		}

		if ($this->backuptype === 'backup') {
			@set_time_limit(0);
			@ignore_user_abort(true);
			$stage = '';
			try {
				$got = rl_exec_get(null, 'localhost', array("lxbackup", "execbackupphp"),
					array($pclass, $pname, $param), false);
				$stage = is_string($got) ? $got : '';
			} catch (Throwable $e) {
				$stage = 'Failed due to: ' . self::backendVersionSkewHint($e->getMessage());
			}
			if ($stage === '' || $stage === 'doing') {
				$stage = 'Failed due to: backup did not finish';
			} elseif (self::isBackendVersionSkew($stage)) {
				$stage = 'Failed due to: ' . self::backendVersionSkewHint($stage);
			}
			$this->backupstage = $stage;
			$this->dbaction = 'update';
			$this->write();
			$this->dbaction = 'clean';
			if (trim($stage) === 'done' || str_starts_with(trim($stage), 'done')) {
				$url = self::backupShowUrl();
				$continue = $url . (str_contains($url, '?') ? '&' : '?')
					. 'frm_smessage=backup_completed';
				// Exit here: a later head-only redirect after backup prints is a blank page.
				if (function_exists('kloxo_print_backup_finished_page')) {
					try {
						if (function_exists('save_login')) {
							save_login();
						}
					} catch (Throwable $e) {
						log_log('error', 'save_login after backup: ' . $e->getMessage());
					}
					kloxo_print_backup_finished_page(
						'Backup completed',
						'The backup finished successfully. The archive is in File Manager under Backup / Restore.',
						$continue,
						'success'
					);
				}
				if (is_object($gbl)) {
					$gbl->__no_debug_redirect = true;
					$gbl->__this_redirect = $continue;
				}

				return $param;
			}
			throw new lxException($login->getThrow('backup_failed'), '', $stage);
		} else {
			$fromFile = isset($param['backup_from_file_f']) ? (string) $param['backup_from_file_f'] : '';
			$fromFtp = isset($param['backup_ftp_file_f']) ? (string) $param['backup_ftp_file_f'] : '';
			$fname = ($fromFile !== '') ? $fromFile : '_RESTORE_' . basename($fromFtp);
			$fname = str_replace(array("/", ";", " "), "", $fname);
			$file = "{$bpath}/{$fname}";

			@set_time_limit(0);
			@ignore_user_abort(true);
			$stage = '';
			try {
				$got = rl_exec_get(null, 'localhost', array("lxbackup", "execrestorephp"),
					array($pclass, $pname, $file, $param), false);
				$stage = is_string($got) ? $got : '';
			} catch (Throwable $e) {
				$stage = 'Restore failed due to ' . self::backendVersionSkewHint($e->getMessage());
			}
			if ($stage === '' || $stage === 'doing') {
				$stage = 'Restore failed due to: restore did not finish';
			} elseif (self::isBackendVersionSkew($stage)) {
				$stage = 'Restore failed due to ' . self::backendVersionSkewHint($stage);
			}
			$this->restorestage = $stage;
			$this->dbaction = 'update';
			$this->write();
			$this->dbaction = 'clean';
			$url = self::backupShowUrl();
			if (str_starts_with(trim($stage), 'done')) {
				$continue = $url . (str_contains($url, '?') ? '&' : '?')
					. 'frm_smessage=restore_completed';
				// Exit here: restore progress prints make Location fail → blank page.
				if (function_exists('kloxo_print_backup_finished_page')) {
					try {
						if (function_exists('save_login')) {
							save_login();
						}
					} catch (Throwable $e) {
						log_log('error', 'save_login after restore: ' . $e->getMessage());
					}
					$extra = '';
					if (is_string($stage) && strlen(trim($stage)) > 4) {
						$tail = trim(substr(trim($stage), 4));
						if ($tail !== '' && $tail !== '.') {
							$extra = ' ' . $tail;
						}
					}
					kloxo_print_backup_finished_page(
						'Restore completed',
						'The restore finished successfully.' . $extra
							. ' Open Backup / Restore for Last Restore Status and anything still left to do by hand.',
						$continue,
						'success'
					);
				}
				if (is_object($gbl)) {
					$gbl->__no_debug_redirect = true;
					$gbl->__this_redirect = $continue;
				}

				return $param;
			}
			// Pass the messagelib key so Status toast uses "Restore failed: [%s]".
			throw new lxException('restore_failed', '', $stage);
		}
	}

	function updateBackup($param)
	{
		global $sgbl, $login;

		$parent = $this->backupParent();
		if (!is_object($parent)) {
			throw new lxException($login->getThrow('no_parent'), '', 'backup');
		}

		if (!is_array($param)) {
			$param = array();
		}
		if (!isset($param['backup_to_file_f']) || trim((string) $param['backup_to_file_f']) === '') {
			$param['backup_to_file_f'] = 'kloxo';
		}

		$bpath = $this->fileManagerDir();
		$bfile = "{$bpath}/{$this->createBackupFileName($param['backup_to_file_f'])}.tgz";

		if (lxfile_exists($bfile)) {
			throw new lxException($login->getThrow("file_already_exists"), '', $param['backup_to_file_f'] . ".tgz");
		}

		$this->updateBackupRestore($param, "backup");
	}

	function updateRestore_confirm_confirm($param)
	{
	//	global $gbl, $sgbl, $login, $ghtml;

		dprintr($param);

		$this->updateBackupRestore($param, "restore");

	//	if (csa($file, "__lx_temperoryftp_file")) { unlink($file); }
	}

	function construct_tarfilename($name)
	{
		return "backup-$name";
	}

	static function execrestorephp($class, $name, $file, $param)
	{
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__) . '/php/kloxo_backup.php';
		}
		@set_time_limit(0);
		@ignore_user_abort(true);
		// kloxo-backend is root: restore in-process when pdo_sqlite is loaded.
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
		$hasSqlite = extension_loaded('pdo_sqlite');
		if ($euid === 0 && $hasSqlite) {
			return self::restoreAccountInProcess((string) $class, (string) $name, (string) $file, $param);
		}
		if (!$hasSqlite && function_exists('kloxo_php_binary')) {
			kloxo_php_binary();
		}
		$val = (isset($param['_accountselect']) && is_array($param['_accountselect']))
			? $param['_accountselect'] : array();
		$r = KloxoBackup::runRestore((string) $class, (string) $name, (string) $file, $val);
		$stage = self::readRestoreStage((string) $class, (string) $name);
		if ($stage === '' || $stage === 'doing') {
			$out = (string) ($r['out'] ?? '');
			if (str_contains($out, 'Restore finished') || str_contains($out, 'Still do by hand')) {
				$stage = 'done';
				if (preg_match('/Still do by hand:\s*(.*)/s', $out, $m)) {
					$stage = 'done. Still do by hand: ' . trim(preg_replace('/\s+/', ' ', $m[1]));
				}
			} elseif (preg_match('/Restore Failed\. Reason:\s*(.*)/', $out, $m)) {
				$stage = 'Restore failed due to ' . trim($m[1]);
			} elseif (preg_match('/Restore failed due to:\s*(.*)/', $out, $m)) {
				$stage = 'Restore failed due to: ' . trim($m[1]);
			} else {
				$code = (int) ($r['code'] ?? 1);
				if ($code === 0) {
					$stage = 'done';
				} else {
					$detail = self::shortProcDetail($out, $code);
					$stage = 'Restore failed due to: restore did not finish'
						. ($detail !== '' ? ' (' . $detail . ')' : '');
				}
			}
		}

		return $stage;
	}

	static function readRestoreStage($class, $name)
	{
		$class = (string) $class;
		$name = (string) $name;
		if ($class === '' || $name === '' || !class_exists($class)) {
			return '';
		}
		try {
			$object = new $class(null, 'localhost', $name);
			$object->get();
			if (!method_exists($object, 'getObject')) {
				return '';
			}
			$backup = $object->getObject('lxbackup');
			if (is_object($backup) && isset($backup->restorestage)) {
				return (string) $backup->restorestage;
			}
		} catch (Throwable $e) {
		}

		return '';
	}

	static function execbackupphp($class, $name, $param)
	{
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__) . '/php/kloxo_backup.php';
		}
		if (!is_array($param)) {
			$param = array();
		}
		@set_time_limit(0);
		@ignore_user_abort(true);
		// kloxo-backend is root: run in-process when this PHP has pdo_sqlite.
		// If PHP Used pointed /usr/bin/php at a series without sqlite, fall
		// back to subprocess via kloxo_php_binary() (pinned etc/path/php).
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
		$hasSqlite = extension_loaded('pdo_sqlite');
		if ($euid === 0 && $hasSqlite) {
			return self::backupAccountInProcess((string) $class, (string) $name, $param);
		}
		if (!$hasSqlite && function_exists('kloxo_php_binary')) {
			// Discover + pin a SQLite-capable CLI before spawning backup.php.
			kloxo_php_binary();
		}
		$fname = isset($param['backup_to_file_f']) ? (string) $param['backup_to_file_f'] : 'kloxo';
		$r = KloxoBackup::runBackup((string) $class, (string) $name, $fname, $param);
		$stage = self::readBackupStage((string) $class, (string) $name);
		if ($stage === '' || $stage === 'doing') {
			$out = (string) ($r['out'] ?? '');
			$code = (int) ($r['code'] ?? 1);
			if (str_contains($out, 'Backup has been saved')) {
				$stage = 'done';
			} elseif (preg_match('/Backup failed due to:\s*(.*)/', $out, $m)) {
				$stage = 'Failed due to: ' . trim($m[1]);
			} else {
				$detail = self::shortProcDetail($out, $code);
				if (str_contains($out, 'could not find driver') || str_contains($detail, 'could not find driver')) {
					$stage = 'Failed due to: PHP pdo_sqlite missing'
						. ' (apt install php-sqlite3; systemctl restart kloxo-backend)';
				} else {
					$stage = 'Failed due to: backup did not finish' . ($detail !== '' ? ' (' . $detail . ')' : '');
				}
			}
		}

		return $stage;
	}

	/**
	 * Backup Now body used by kloxo-backend (root). Same work as bin/common/backup.php.
	 *
	 * @param array<string,mixed> $param
	 */
	static function backupAccountInProcess($class, $name, $param)
	{
		global $login;

		$class = (string) $class;
		$name = (string) $name;
		if ($class === '' || $name === '' || !class_exists($class)) {
			return 'Failed due to: invalid backup class or name';
		}
		if (!is_array($param)) {
			$param = array();
		}
		if (!isset($param['backup_to_file_f']) || trim((string) $param['backup_to_file_f']) === '') {
			$param['backup_to_file_f'] = 'kloxo';
		}
		if (!is_object($login) || (method_exists($login, 'isAdmin') && !$login->isAdmin())) {
			if (function_exists('initProgram')) {
				initProgram('admin');
			}
		}

		$object = new $class(null, 'localhost', $name);
		$object->get();
		if ($object->dbaction === 'add') {
			return "Failed due to: {$class} {$name} does not exist";
		}
		$backup = $object->getObject('lxbackup');
		if (!is_object($backup)) {
			return "Failed due to: no lxbackup object for {$name}";
		}
		$lock = "{$class}-{$name}.backup";
		if (lx_core_lock($lock)) {
			$backup->backupstage = 'Failed due to: another backup for this account is already running';
			clearLxbackup($backup);

			return (string) $backup->backupstage;
		}

		try {
			$backup->doUpdateBackup($param);
			$backup->backupstage = 'done';
		} catch (Throwable $e) {
			$mess = isset($e->__full_message) ? (string) $e->__full_message : $e->getMessage();
			if ($mess === '') {
				$mess = 'unknown backup error';
			}
			$backup->backupstage = "Failed due to: {$mess}";
		}
		clearLxbackup($backup);
		if (function_exists('kloxo_release_core_lock')) {
			kloxo_release_core_lock($lock);
		}

		return (string) $backup->backupstage;
	}

	/**
	 * Restore From File body used by kloxo-backend (root). Same work as restore.php.
	 *
	 * @param array<string,mixed> $param
	 */
	static function restoreAccountInProcess($class, $name, $file, $param)
	{
		global $gbl, $login;

		$class = (string) $class;
		$name = (string) $name;
		$file = (string) $file;
		if ($class === '' || $name === '' || $file === '' || !class_exists($class)) {
			return 'Restore failed due to: invalid restore class, name or file';
		}
		if (!is_array($param)) {
			$param = array();
		}
		if (!isset($param['_accountselect']) || !is_array($param['_accountselect'])
			|| $param['_accountselect'] === array()) {
			$param['_accountselect'] = array('all');
		}
		if (!is_object($login) || (method_exists($login, 'isAdmin') && !$login->isAdmin())) {
			if (function_exists('initProgram')) {
				initProgram('admin');
			}
		}
		if (is_object($gbl)) {
			$gbl->__var_list_flag = false;
			$gbl->__restore_flag = true;
		}

		$object = new $class(null, 'localhost', $name);
		$object->get();
		if ($object->dbaction === 'add') {
			return "Restore failed due to: {$class} {$name} does not exist";
		}
		$backup = $object->getObject('lxbackup');
		if (!is_object($backup)) {
			return "Restore failed due to: no lxbackup object for {$name}";
		}
		$lock = "{$class}-{$name}.restore";
		if (lx_core_lock($lock)) {
			$backup->restorestage = 'Failed due to: another restore for this account is already running';
			clearLxbackup($backup);

			return (string) $backup->restorestage;
		}

		try {
			$backup->doUpdateRestore($file, $param);
			$left = (is_object($gbl) && isset($gbl->__var_restore_leftover))
				? trim((string) $gbl->__var_restore_leftover) : '';
			$backup->restorestage = $left !== ''
				? ('done. Still do by hand: ' . str_replace("\n", ' ', $left))
				: 'done';
		} catch (Throwable $e) {
			$mess = isset($e->__full_message) ? (string) $e->__full_message : $e->getMessage();
			if ($mess === '') {
				$mess = 'unknown restore error';
			}
			$backup->restorestage = "Restore failed due to {$mess}";
		}
		clearLxbackup($backup);
		if (function_exists('kloxo_release_core_lock')) {
			kloxo_release_core_lock($lock);
		}

		return (string) $backup->restorestage;
	}

	/** Backup / Restore show URL (keeps client/admin object path when possible). */
	static function backupShowUrl()
	{
		global $ghtml;

		$url = '';
		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$got = $ghtml->getFullUrl('a=show');
				if (is_string($got) && $got !== '') {
					$url = $got;
				}
			} catch (Throwable $e) {
				$url = '';
			}
		}
		if ($url === '') {
			$url = 'display.php?frm_action=show';
		}

		return $url;
	}

	/** True when kloxo-backend still has a pre-upcp version stamp in memory. */
	static function isBackendVersionSkew($msg)
	{
		$msg = strtolower((string) $msg);

		return str_contains($msg, 'slave_upgrading')
			|| str_contains($msg, 'backend_server_restarting')
			|| str_contains($msg, 'please_try_after_a_few_minutes');
	}

	/** Clear operator hint: deploy remotelib + restart clears slave_upgrading. */
	static function backendVersionSkewHint($msg)
	{
		$msg = trim((string) $msg);
		if (!self::isBackendVersionSkew($msg)) {
			return $msg !== '' ? $msg : 'unknown backup/restore error';
		}

		return 'kloxo-backend is stale after an update (slave_upgrading). '
			. 'As root run: systemctl restart kloxo-backend kloxo-php';
	}

	/** One-line stderr/stdout for Status toast when stage stayed "doing". */
	static function shortProcDetail($out, $code)
	{
		$out = preg_replace('/\s+/', ' ', trim((string) $out)) ?? '';
		$out = substr($out, 0, 180);
		$bits = array();
		if ($code !== 0) {
			$bits[] = 'exit ' . (int) $code;
		}
		if ($out !== '') {
			$bits[] = $out;
		}

		return implode(': ', $bits);
	}

	static function readBackupStage($class, $name)
	{
		$class = (string) $class;
		$name = (string) $name;
		if ($class === '' || $name === '' || !class_exists($class)) {
			return '';
		}
		try {
			$object = new $class(null, 'localhost', $name);
			$object->get();
			if (!method_exists($object, 'getObject')) {
				return '';
			}
			$backup = $object->getObject('lxbackup');
			if (is_object($backup) && isset($backup->backupstage)) {
				return (string) $backup->backupstage;
			}
		} catch (Throwable $e) {
		}

		return '';
	}

	function createBackupFileName($name)
	{
		$parent = $this->backupParent();
		if (!is_object($parent)) {
			$parent = (object) array('nname' => 'admin');
		}

		$name = str_replace("/", "", $name);
		$name = str_replace(";", "", $name);
	//	$date = @ date('Y-M-d');
		$date = @ date('Y-m-d');
		$time = time();
		$bfile = "{$name}-{$parent->nname}-{$date}-{$time}";

		return $bfile;
	}

	function doUpdateBackup($param)
	{
		global $sgbl;

		$progname = $sgbl->__var_program_name;
		$cprogname = ucfirst($progname);

		$parent = $this->backupParent();
		if (!is_object($parent)) {
			throw new lxException('no_parent', '', 'backup');
		}

		$table = $parent->get__table();
		$pname = (string) $parent->nname;
		$bpath = KloxoBackup::ensureDir($table, $pname);
		$bfile = $this->createBackupFileName($param['backup_to_file_f']);
		$zip = method_exists($parent, 'getZiptype') ? $parent->getZiptype() : 'tgz';
		$fbfile = "{$bpath}/{$bfile}.{$zip}";

		if ($parent->isSimpleBackup()) {
			$parent->doSimpleBackup($fbfile, $param);
		} else {
			$parent->doCoreBackup($fbfile, $param);
		}

		$fbfile = KloxoBackup::installFile($fbfile, $table, $pname);
		if (!is_file($fbfile) || @filesize($fbfile) < 1) {
			throw new Exception('backup archive was not created (' . basename($fbfile) . ')');
		}

		$object = clone($this);
		lxclass::clearChildrenAndParent($object);

	//	if ($object->isOn('upload_to_ftp')) {
		if ($object->upload_to_ftp === 'on') {
			try {
				if ($parent->isClient() || $parent->isLocalhost()) {
					self::upload_to_server($fbfile, basename($fbfile), $object);
				} else {
					rl_exec_get(null, $parent->syncserver, array('lxbackup', 'upload_to_server'), array($fbfile, basename($fbfile), $object));
				}
			} catch (Exception $e) {
			//	$text1 = "$cprogname Backup Upload Failed on " . date('Y-M-d') . " at " . date('H') . " Hours";
				$text1 = "$cprogname Backup Upload Failed on " . date('Y-m-d') . " at " . date('H') . " Hours";
				$text2 = "$cprogname Backup upload Failed for '{$parent->nname}' due to '{$e->getMessage()}'";

				lx_mail(null, $parent->contactemail, $text1, $text2 . "\n");
				log_log("backup", "* " . $text1 . " - " . $text2);
			}
		}

		if ($parent->isClient() || $parent->isLocalhost()) {
			self::clear_extra_backups($parent->get__table(), $parent->nname, $object);
		} else {
			rl_exec_get(null, $parent->syncserver, array('lxbackup', 'clear_extra_backups'), array($parent->get__table(), $parent->nname, $object));
		}

	//	if ($object->isOn('upload_to_ftp')) {
		if ($object->upload_to_ftp === 'on') {
			$tobackup = $object->ftp_server;
		} else {
			$tobackup = 'local backup';
		}

		// MR -- change format. Use '2019-07-20' instead '2019-jul-20'
	//	$text1 = "$cprogname Backup on " . date('Y-M-d') . " at " . date('H') . " Hours";
		$text1 = "$cprogname Backup on " . date('Y-m-d') . " at " . date('H') . " Hours";

		$text2 = "$cprogname Backup Succeeded for '{$parent->nname}' to '{$tobackup}'";

		lx_mail(null, $parent->contactemail, $text1, $text2 . "\n");
		log_log("backup", "* " . $text1 . " - " . $text2);
	}

	static function clear_extra_backups($class, $name, $object)
	{
		global $login;

		$bpath = KloxoBackup::ensureDir((string) $class, (string) $name);
		$list = lscandir_without_dot($bpath);
		$dellist = self::getDeleteList($object, $list);

		dprint("Delete list\n");
		dprintr($dellist);

		$num = $object->rm_last_number ? $object->rm_last_number : 5;
		print("Deleting Old backups.... Will retain $num.\n");

		if (!empty($dellist)) {
			foreach ($dellist as $k => $v) {
				print("deleting $v\n");
				lunlink("$bpath/$v");
			}
		}

		print("deleting old backups from ftp server\n");

		if (!$object->ftp_server) {
			return;
		}

		$fn = lxftp_connect($object->ftp_server);
		$mylogin = ftp_login($fn, $object->rm_username, $object->rm_password);

		if (!$mylogin) {
			$p = error_get_last();
			throw new lxException($login->getThrow('could_not_connect_to_ftp_server'), '', $p);
		}
	/*
		if (!$fn) {
			return;
		}
	*/
		# Issue 366
		ftp_pasv($fn, true);

		$list = ftp_nlist($fn, $object->rm_directory);

		print("Total list of files in ftp server\n");
		print_r($list);

		$dellist = self::getDeleteList($object, $list);

		print("Deleting these files on remote server.\n");
		print_r($dellist);

		foreach ((array)$dellist as $k => $v) {

			$v = basename($v);

			if ($object->rm_directory) {
				$v = "{$object->rm_directory}/$v";
			}

			ftp_delete($fn, $v);
		}

		ftp_close($fn);
	}

	static function getDeleteList($object, $list)
	{
		global $sgbl;

		$progname = $sgbl->__var_program_name;
	//	$progname = "kloxo70";
		dprint("$object->nname\n");
		$aname = strfrom($object->nname, "-");
		$aname = "-$aname";

		if (!is_array($list)) {
			return array();
		}

		foreach ($list as $k => &$__l) {
			$__l = basename($__l);
		//	if (csb($__l, "$progname-scheduled") && csa($__l, $aname)) {
			if (csb($__l, $progname) && csa($__l, "-scheduled-") && csa($__l, $aname)) {
				// MR -- no action
			} else {
				unset($list[$k]);
			}
		}

		$newlist = null;

		foreach ($list as $k => $l) {
			$v = explode('-', $l);
			$ti = getLastFromList($v);
			list($time,) = explode(".", $ti);
			$newlist[$time] = $l;
		}

		if (!$newlist) {
			return array();
		}

		ksort($newlist);

		$num = $object->rm_last_number ? $object->rm_last_number : 5;
		$total = count($newlist);
		$i = 0;

		$retlist = array();

		foreach ($newlist as $k => $v) {
			$i++;

			if ($i > $total - $num) {
				break;
			}

			$retlist[$k] = $v;
		}

		return $retlist;
	}

	function getFtpOrLocal($param)
	{
		$bpath = $this->fileManagerDir();

		if (!empty($param['backup_ftp_file_f'])) {
		/*
			$file = tempnam("/tmp/", "__lx_temperoryftp_file");
			lunlink($file);
			$file .= ".zip";
			$this->download_from_server($param['backup_ftp_file_f'], $file);
			$ftp = true;
		*/
			$file = '_RESTORE_' . basename($param['backup_ftp_file_f']);
			$file = "{$bpath}/{$file}";
			$this->download_from_server($param['backup_ftp_file_f'], $file);
		} else {
			$file = isset($param['backup_from_file_f']) ? (string) $param['backup_from_file_f'] : '';
			$file = str_replace(";", "", $file);
			$file = str_replace("/", "", $file);
			$file = str_replace(" ", "", $file);
			$file = "{$bpath}/{$file}";
		}

		return $file;
	}

	static function createTmpDirIfitDoesntExist($file, $real)
	{
		global $sgbl, $login;

		$progname = $sgbl->__var_program_name;
		$vd = tempnam("/tmp", "backup");

		if (!$vd) {
			throw new lxException($login->getThrow('could_not_create_tmp_dir'));
		}

		lunlink($vd);
		mkdir($vd);
		lxfile_generic_chmod($vd, "0700");

		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__) . '/php/kloxo_backup.php';
		}

		try {
			if ($real) {
				KloxoBackup::extractArchive($file, $vd);
			} else {
				$members = array("{$progname}.file", "{$progname}.metadata");
				try {
					KloxoBackup::extractArchive($file, $vd, $members);
				} catch (Throwable $e) {
					if ($sgbl->isKloxoForRestore()) {
						KloxoBackup::extractArchive($file, $vd, array("lxadmin.file", "lxadmin.metadata"));
					} else {
						throw $e;
					}
				}
			}
		} catch (Throwable $e) {
			lxfile_tmp_rm_rec($vd);
			throw new lxException($login->getThrow('could_not_unzip_file'), '', "dir: {$vd}; file: {$file}");
		}

		return $vd;
	}

	function doUpdateRestore($file, $param)
	{
		global $gbl, $sgbl;

		$progname = $sgbl->__var_program_name;
		$cprogname = ucfirst($progname);

		$parent = $this->backupParent();
		if (!is_object($parent)) {
			throw new lxException('no_parent', '', 'restore');
		}

		if ($parent->isSimpleBackup()) {
			$parent->doSimpleRestore($file, $param);
		} else {
			$parent->doCoreRestore($file, $param);
		}

		if (!$gbl->__var_list_flag) {
			$text1 = "$cprogname Restore on " . date('Y-m-d') . " at " . date('H') . " Hours";
			$text2 = "$cprogname Restore Succeeded for '{$parent->nname}' on '$parent->syncserver'";
			$to = (isset($parent->contactemail) && is_string($parent->contactemail))
				? trim($parent->contactemail) : '';
			if ($to !== '' && function_exists('lx_mail')) {
				try {
					lx_mail(null, $to, $text1, $text2 . "\n");
				} catch (Throwable $e) {
					log_log('restore', 'mail after restore: ' . $e->getMessage());
				}
			}
			log_log("restore", "* " . $text1 . " - " . $text2);
		}

		if ($sgbl->isKloxo()) {
			try {
				$php = function_exists('kloxo_php_binary') ? kloxo_php_binary()
					: (isset($sgbl->__path_php_path) ? (string) $sgbl->__path_php_path : '/usr/bin/php');
				$cq = (function_exists('kloxo_program_root') ? kloxo_program_root() : dirname(__DIR__, 3))
					. '/bin/collectquota.php';
				if (is_file($cq)) {
					lxshell_return($php, $cq, '--just-db=yes');
				}
			} catch (Throwable $e) {
				log_log('restore', 'collectquota after restore: ' . $e->getMessage());
			}
		}
	}

	function download_from_server($file, $localfile)
	{
		global $login;


		$fn = lxftp_connect($this->ftp_server);
		$mylogin = ftp_login($fn, $this->rm_username, $this->rm_password);

		if (!$mylogin) {
			$p = error_get_last();
			throw new lxException($login->getThrow('could_not_connect_to_ftp_server'), '', $p);
		}

		// using a PASV connection is more likely to succeed
		ftp_pasv($fn, true);

		$fp = lfopen($localfile, "w");

		if (!ftp_fget($fn, $fp, $file, FTP_BINARY)) {
			throw new lxException($login->getThrow('file_download_failed'), '', $file);
		}

		fclose($fp);
	}

	static function upload_to_server($file, $uploadfilename, $object)
	{
		global $login;

		$fn = lxftp_connect($object->ftp_server);
		$mylogin = ftp_login($fn, $object->rm_username, $object->rm_password);

		if (!$mylogin) {
			$p = error_get_last();
			throw new lxException($login->getThrow('could_not_connect_to_ftp_server'), '', $p);
		}

		ftp_pasv($fn, true);
		$fp = lfopen($file, "r");

		if ($object->rm_directory) {
			ftp_mkdir($fn, $object->rm_directory);
			ftp_chdir($fn, $object->rm_directory);
		}

		$ret = ftp_fput($fn, $uploadfilename, $fp, FTP_BINARY);

		if (!$ret) {
			$p = error_get_last();
			log_log("ftp_error", $p);

			throw new lxException($login->getThrow('could_not_upload_file'), '', $object->ftp_server);
		}

		fclose($fp);

		if ($object->isOn('no_local_copy_flag')) {
			lunlink($file);
		}

	}

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return $parent->getClName();
	}
}
