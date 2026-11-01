<?php 

function create_mysql_db($type, $opt, $admin_pass)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$progname = $sgbl->__var_program_name;

	if (!isset($opt['db-rootuser'])) {
		print("Need --db-rootuser (Ubuntu socket auth: pass --db-rootpassword= empty)\n");
		exit(1);
	}

	if ($sgbl->__var_database_type === 'mysql') {
		$rootpass = isset($opt['db-rootpassword']) ? $opt['db-rootpassword'] : '';
		$req = new mysqli('localhost', $opt['db-rootuser'], $rootpass);

	} else {
		$req = new PDO("sqlite:$sgbl->__var_dbf");
	}


	if (!$req || ($req instanceof mysqli && $req->connect_error)) {
		$err = ($req instanceof mysqli) ? $req->connect_error : 'connect failed';
		print("Could not Connect to Database on localhost using root user: {$err}\n");
		exit(1);
	}

	$dp = randomString(9);
	$dbadminpass = client::createDbPass($dp);

	$dbname = $sgbl->__var_dbf;
	$pguser = $sgbl->__var_admin_user;
	if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,47}$/', (string)$dbname)
		|| !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,47}$/', (string)$pguser)) {
		print("Invalid database name or user\n");
		exit(1);
	}

	if ($sgbl->__var_database_type === 'mysql') {
		// MariaDB 10.4+ removed GRANT ... IDENTIFIED BY. Use CREATE USER + GRANT.
		if (is_file($sgbl->__path_admin_pass)) {
			$existing = trim((string)file_get_contents($sgbl->__path_admin_pass));
			if ($existing !== '') {
				$dbadminpass = $existing;
			}
		}
		$req->query("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET latin1");
		$stmt = $req->prepare("CREATE USER IF NOT EXISTS '{$pguser}'@'localhost' IDENTIFIED BY ?");
		$stmt->bind_param('s', $dbadminpass);
		$stmt->execute();
		$stmt->close();
		$stmt = $req->prepare("ALTER USER '{$pguser}'@'localhost' IDENTIFIED BY ?");
		$stmt->bind_param('s', $dbadminpass);
		$stmt->execute();
		$stmt->close();
		$req->query("GRANT ALL PRIVILEGES ON `{$dbname}`.* TO '{$pguser}'@'localhost'");
		$req->query("FLUSH PRIVILEGES");
	}

	lfile_put_contents($sgbl->__path_admin_pass, $dbadminpass);
	lxfile_generic_chown($sgbl->__path_admin_pass, "lxlabs");
}

function add_admin($pass)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$client = new Client(null, null, 'admin');
	$login = $client;
	$client->initThisDef();
	$client->priv->pserver_num = 'Unlimited';
	$client->priv->maindomain_num = 'Unlimited';
	$client->priv->vps_num = 'Unlimited';
	$client->priv->client_num = 'Unlimited';
	$client->ddate = time();

	$ddb = new Sqlite(null, "client");

	if (!$ddb->existInTable("nname", 'admin')) {
		if ($sgbl->dbg > 0) {
			$pass = 'lxlabs';
			$res['contacemail'] = 'admin@mratwork.com';
		}

		$res['password'] = crypt($pass, '$1$'.randomString(8).'$');
		$res['cttype'] = 'admin';
		$res['cpstatus'] = 'on';

		if(if_demo()){
			$res['email'] = "admin@mratwork.com";
		}

		$client->create($res);
		$client->driverApp = new client__sync(null, null, 'admin');
		$client->was();
		lxfile_mkdir("__path_client_root/$client->nname");
		lxfile_generic_chown("__path_client_root/$client->nname", "lxlabs");
	}

	$notif = new Notification(null, null, $client->getClName());
	$notif->initThisDef();
	$notif->dbaction = 'add';
//	$notif->text_newaccountmessage = lfile_get_contents("{$sgbl->__path_program_root}/file/welcome.txt");
	// MR -- use .tpl model (php parse)
	$notif->text_newaccountmessage = lfile_get_contents(getLinkCustomfile("{$sgbl->__path_program_root}/file", "welcome.txt.tpl"));
	$notif->parent_clname = $client->getClName();
	$notif->write();

	$display = new sp_SpecialPlay(null, null, $client->getClName());
	$display->initThisDef();
	$display->parent_clname = $client->getClName();
	$display->dbaction = 'add';
	$display->write();
}

function create_general()
{
	global $sgbl, $login, $ghtml; 

	$ddb = new Sqlite(null, "general");
	if ($ddb->existInTable("nname", 'admin')) {
		print(">>> general already exists, skip <<<\n");
		return;
	}

	$gen = new General(null, null, 'admin');
	$gen->initThisDef();
	$g = $gen->generalmisc_b;

	$g->npercentage = 90;
	$g->dpercentage = 110;
	$g->attempts = 3;
	$g->loginhistory_time = 2;
	$g->traffichistory_time = 6;

	$list = array("Billing", "Complaint", "Other");
	$h = null;

	foreach($list as $l) {
		$cat = new helpdeskcategory_a(null, null, $l);
		$h[$l] = $cat;
	}

	$gen->helpdeskcategory_a = $h;

	$gen->dbaction = 'add';
	$gen->write();
}

function create_servername()
{
	$pserver = new pserver(null, 'localhost', "localhost");
	$pserver->initThisDef();
	$pserver->rolelist = array("web", "dns");

	if (if_demo()) {
		$pserver->realpass = 'admin';
		$pserver->password = crypt("admin", '$1$'.randomString(8).'$');
		$pserver->cpstatus = 'on';
	}

	$pserver->dbaction = "add";
	$pserver->postAdd();
	$pserver->superPostAdd();
	$pserver->was();

	return;
}
