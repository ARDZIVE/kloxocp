<?php 
include_once "lib/html/include.php";

restore_main();

function restore_main()
{
	global $argc, $argv;
	global $gbl, $login, $ghtml; 

	$gbl->__restore_flag = true;

	if ($argc === 1) {
	//	print("Usage: $argv[0] --class= --name= --restore/--list " .
		print("Usage: sh /script/restore --class= --name= --restore/--list " .
			"--accounts='domain:<domain1.com>,client:<client1>,domain:<domain2.com>' <backup-file> " .
			"[--switchserverlist='oldserver1:newserver1,oldserver2:newserver2']" .
			"\n- Use --accounts=all to restore everything.\n");
		exit;
	}


	initProgram("admin");
	$object = $login;

	$opt = parse_opt($argv);


	if (isset($opt['class']) && isset($opt['name'])) {
		$object = new $opt['class'](null, null, $opt['name']);
		$object->get();
		if ($object->dbaction === 'add') {
			log_error("{$opt['class']} doesnt exist");
			print("{$opt['class']} doesnt exist\n");
			exit;
		}
	}

	$class = $opt['class'];
	$name = $opt['name'];
	if (lx_core_lock("$class-$name.restore")) {
		print("Another Restore for the same class is happening..\n");
		exit;
	}

	$backup = $object->getObject('lxbackup');

	if (isset($opt['switchserverlist'])) {

		$sq = new Sqlite(null, "pserver");
		$serverlist = $sq->getTable();
		$serverlist = get_namelist_from_arraylist($serverlist);
		$server = $opt['switchserverlist'];

		$list = explode(",", $server);

		foreach($list as $l) {
			if (!$l) {
				continue;
			}
			$q = explode(":", $l);
			$rlist[$q[0]] = $q[1];
			if (!array_search_bool($q[1], $serverlist)) {
				print("The server {$q[1]} doesn't exist in the server system here\n");
				exit;
			}
		}
		$param['switchserverlist'] = $rlist;
		dprint("\n");
	} else {
		$param['switchserverlist'] = null;
	}


/*
	if (!testAllServersWithMessage()) {
		$backup->restorestage = "Failed due to: could not connect to slave servers";
		clearLxbackup($backup);
		exit;
	}
*/


	$file = $opt['final'];
	//$param = get_variable($opt);

	if (isset($opt['list'])) {
		$gbl->__var_list_flag = true;
		$param['_accountselect'] = null;
	}  else if (isset($opt['restore'])) {
		$gbl->__var_list_flag = false;
		if (!isset($opt['accounts'])) {
			print("Restore option needs accounts that are to be restored. --accounts='domain:domain.com,client:clientname'... Use --list to find out all the domain/clients in the backup archive.\n");
			clearLxbackup($backup);
			exit;
		}
		$account = $opt['accounts'];
		//$account = str_replace(":", "_s_vv_p_", $account);
		$account = str_replace(":", "-", $account);
		$accountlist = explode(",", $account);
		$param['_accountselect'] = $accountlist;
	} else {
		print("Usage: $argv[0] <--list/--restore --accounts=> <filename>\n");
		clearLxbackup($backup);
		exit;
	}

	if (isset($opt['priority']) && $opt['priority'] === 'low') {
		sleep(20);
	}

	dprintr($param);

	//dprint($file);

	log_log("restore", "*** Restore from '{$name}' user - BEGIN ***");

	try {
		$backup->doUpdateRestore($file, $param);
		$left = (is_object($gbl) && isset($gbl->__var_restore_leftover))
			? trim((string) $gbl->__var_restore_leftover) : '';
		$backup->restorestage = $left !== '' ? ('done. Still do by hand: ' . str_replace("\n", ' ', $left)) : 'done';
		if ($left !== '') {
			print("\nStill do by hand:\n{$left}\n");
		} else {
			print("\nRestore finished. No extra steps.\n");
		}
	} catch (Throwable $e) {
		$mess = isset($e->__full_message) ? (string) $e->__full_message : $e->getMessage();
		log_error("Restore Failed. Reason: {$mess} \n");
		print("Restore Failed. Reason: {$mess} \n");
		if (isset($object->contactemail) && is_string($object->contactemail) && $object->contactemail !== '') {
			@mail($object->contactemail, "Restore Failed..", "Restore Failed for {$object->nname} with the Message {$mess}");
		}
		$backup->restorestage = "Restore failed due to $mess";
	}

	clearLxbackup($backup);

	log_log("restore", "*** Restore from '{$name}' user - END ***");
}



