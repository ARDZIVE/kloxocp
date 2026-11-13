<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ssl.php';

class Certificate_b extends LxaClass
{
	static $__desc_private_key = array("", "", "private_key");
	static $__desc_certificate = array("", "", "certificate");
	static $__desc_ca_certificate = array("", "", "ca_certificates");
}

class Ssl_data_b extends LxaClass
{
	static $__desc_countryName_r = array("", "", "countryname");
	static $__desc_stateOrProvinceName_r = array("", "", "state");
	static $__desc_localityName_r = array("", "", "city");
	static $__desc_organizationName_r = array("", "", "organization");
	static $__desc_organizationalUnitName_r = array("", "", "department_name");
	static $__desc_commonName_r = array("n", "", "common_name");
	static $__desc_emailAddress_r = array("n", "", "email_address");
	static $__desc_subjectAltName_r = array("n", "", "subject_alt_name");
	static $__desc_key_bits_r = array("", "", "ssl_key_bits");
}

class SslCert extends Lxdb
{
	static $__table = 'sslcert';

	static $__desc = array("", "", "ssl_certificate");
	static $__acdesc_list = array("", "", "SSL Certificates");

	static $__desc_nname = array("n", "", "ssl_certificate_name", URL_SHOW);
	static $__desc_certname = array("n", "", "ssl_certificate_name", URL_SHOW);
	static $__desc_add_type = array("n", "", "ssl_add_type", URL_SHOW);
	static $__desc_parent_domain = array("n", "", "parent_domain", URL_SHOW);
	static $__desc_syncserver = array("", "", "");
	static $__desc_slave_id = array("", "", "slave_ID");
	static $__desc_text_csr_content = array("t", "", "CSR");
	static $__desc_text_key_content = array("t", "", "Key");
	static $__desc_text_crt_content = array("t", "", "Certificate");
	static $__desc_text_ca_content = array("t", "", "CACert");
	static $__desc_ssl_key_file_f = array("n:F", "", "key_file");
	static $__desc_ssl_crt_file_f = array("n:F", "", "certificate_file");
	static $__desc_ssl_ca_file_f = array("F", "", "certificate_CA_file");
//	static $__desc_ssl_ca_file_f =  array("F", "",  "authority_file");
	static $__desc_upload = array("", "", "data");
	static $__desc_username = array("", "", "user_name");

	static $__desc_ssl_action = array("", "", "ssl_action");
	static $__desc_upload_v_file = array("", "", "ssl_file");
	static $__desc_upload_v_text = array("", "", "ssl_text");
	static $__desc_upload_v_letsencrypt = array("", "", "ssl_letsencrypt");
//	static $__desc_upload_v_startapi = array("", "", "ssl_startapi");
	static $__desc_upload_v_link = array("", "", "ssl_link");

	static $__desc_warning = array("", "", "ssl_warning");


	static $__acdesc_update_update = array("", "", "certificate_info");
	static $__acdesc_update_ssl_kloxo = array("", "", "set_ssl_for_kloxo");
	static $__acdesc_update_ssl_hypervm = array("", "", "set_ssl_for_hypervm");

	static function sslParentIsWeb($parent)
	{
		return is_object($parent) && method_exists($parent, 'getClass')
			&& $parent->getClass() === 'web';
	}

	static function sslParentNname($parent)
	{
		if (is_object($parent) && isset($parent->nname) && is_string($parent->nname)) {
			return $parent->nname;
		}

		return '';
	}

	static function sslDttypeVal($typetd)
	{
		if (is_array($typetd) && isset($typetd['val']) && is_string($typetd['val'])) {
			return $typetd['val'];
		}

		return '';
	}

	/**
	 * Prefer installed /opt/configs templates; fall back to the tree under file/.
	 *
	 * @return string absolute path or ''
	 */
	static function sslTemplatePath($bundle, $file)
	{
		$bundle = preg_replace('/[^a-z0-9._-]/i', '', (string) $bundle);
		$file = preg_replace('/[^a-z0-9._-]/i', '', (string) $file);
		if ($bundle === '' || $file === '') {
			return '';
		}
		$candidates = array(
			"/opt/configs/{$bundle}/tpl/custom.{$file}",
			"/opt/configs/{$bundle}/tpl/{$file}",
		);
		$root = dirname(__DIR__, 3);
		$candidates[] = "{$root}/file/{$bundle}/tpl/custom.{$file}";
		$candidates[] = "{$root}/file/{$bundle}/tpl/{$file}";
		foreach ($candidates as $p) {
			if (is_file($p) && is_readable($p)) {
				return $p;
			}
		}

		return '';
	}

	static function sslOwnerName($parent)
	{
		if (!is_object($parent)) {
			return '';
		}
		try {
			if (self::sslParentIsWeb($parent)) {
				if (isset($parent->customer_name) && is_string($parent->customer_name)
					&& $parent->customer_name !== '') {
					return $parent->customer_name;
				}
				if (isset($parent->username) && is_string($parent->username)
					&& $parent->username !== '') {
					return $parent->username;
				}
				$domain = method_exists($parent, 'getParentO') ? $parent->getParentO() : null;
				if (is_object($domain)) {
					$client = null;
					if (isset($domain->__parent_o) && is_object($domain->__parent_o)) {
						$client = $domain->__parent_o;
					} elseif (method_exists($domain, 'getParentO')) {
						try {
							$client = $domain->getParentO();
						} catch (Throwable $e) {
							$client = null;
						}
					}
					if (is_object($client) && isset($client->nname)
						&& is_string($client->nname) && $client->nname !== '') {
						return $client->nname;
					}
					if (isset($domain->nname) && is_string($domain->nname)) {
						return $domain->nname;
					}
				}
			}
			if (isset($parent->nname) && is_string($parent->nname)) {
				return $parent->nname;
			}
		} catch (Throwable $e) {
		}

		return '';
	}

	static function sslPropertyTabs($parent = null, $class = 'sslcert')
	{
		$c = (is_string($class) && $class !== '') ? $class : 'sslcert';
		$tabs = array("a=list&c=$c");
		if (self::sslParentIsWeb($parent)) {
			$tabs[] = "a=addform&c=$c&dta[var]=upload&dta[val]=file";
			$tabs[] = "a=addform&c=$c&dta[var]=upload&dta[val]=text";
			$tabs[] = "a=addform&c=$c&dta[var]=upload&dta[val]=letsencrypt";
			$tabs[] = "a=addform&c=$c&dta[var]=upload&dta[val]=link";
		} else {
			$tabs[] = "a=addform&c=$c";
		}

		return $tabs;
	}

	function sslDataFields()
	{
		if (!isset($this->ssl_data_b) || $this->ssl_data_b === null) {
			return array();
		}
		if (is_array($this->ssl_data_b)) {
			return $this->ssl_data_b;
		}
		if (!is_object($this->ssl_data_b)) {
			return array();
		}
		$out = array();
		foreach (get_object_vars($this->ssl_data_b) as $k => $v) {
			if (is_string($k) && cse($k, '_r')) {
				$out[$k] = $v;
			}
		}
		$known = array(
			'countryName_r', 'stateOrProvinceName_r', 'localityName_r',
			'organizationName_r', 'organizationalUnitName_r', 'commonName_r',
			'emailAddress_r', 'subjectAltName_r', 'key_bits_r',
		);
		foreach ($known as $f) {
			if (array_key_exists($f, $out)) {
				continue;
			}
			try {
				if (isset($this->ssl_data_b->$f)) {
					$out[$f] = $this->ssl_data_b->$f;
				}
			} catch (Throwable $e) {
			}
		}

		return $out;
	}

	function updateform($subaction, $param)
	{
		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}

		if (csa($subaction, "ssl_")) {
			$this->slave_id = "localhost";
			$vlist['slave_id'] = array('s', get_all_pserver());

			return $vlist;
		}

		$vlist['nname'] = $this->certname;

		$this->convertToUnmodifiable($vlist);

		if ($this->add_type !== 'link') {
			$string = $this->getCRTParse();

			$vlist['upload'] = array('M', $string);
		}

		$vlist['username'] = array("s", $this->getUserList());
		$this->setDefaultValue('username', self::sslOwnerName($parent));

		if (self::sslParentIsWeb($parent)) {
		//	if ($this->isOn('upload_status')) {
			if (($this->add_type === 'text') || ($this->add_type === 'file')
					|| ($this->add_type === 'on')) {
			} elseif (($this->add_type === 'letsencrypt') || ($this->add_type === 'startapi')) {
				$vlist['ssl_data_b_s_key_bits_r'] = array("s", array("2048", "4096", "ec-256", "ec-384"));
				$vlist["ssl_data_b_s_subjectAltName_r"] = array("t", null);
			} elseif ($this->add_type === 'link') {
				$pname = (is_array($param) && isset($param['nname']))
					? $param['nname'] : (isset($this->nname) ? $this->nname : '');
				$ssllist = self::getSSLParentList($pname);
				if (is_array($ssllist) && $ssllist !== array()) {
					$vlist['parent_domain'] = array("s", $ssllist);
				}
			}
		} else {
			$vlist['ssl_data_b_s_key_bits_r'] = array("s", array("2048", "4096"));

			$vlist["ssl_data_b_s_commonName_r"] = null;

			$temp = self::getCountryList();
			$vlist["ssl_data_b_s_countryName_r"] = array("s", $temp);

			$vlist["ssl_data_b_s_stateOrProvinceName_r"] = null;
			$vlist["ssl_data_b_s_localityName_r"] = null;
			$vlist["ssl_data_b_s_organizationName_r"] = null;
			$vlist["ssl_data_b_s_organizationalUnitName_r"] = null;
			$vlist["ssl_data_b_s_emailAddress_r"] = null;
		//	$vlist["ssl_data_b_s_subjectAltName_r"] = null;
		}

		if ($this->add_type !== 'link') {
			if (($this->add_type === 'text') || ($this->add_type === 'file')
				|| ($this->add_type === 'on')) {
				$vlist['text_key_content'] = null;
				$vlist['text_crt_content'] = null;
				$vlist['text_ca_content'] = null;
			} else {
				$x['text_key_content'] = null;
				$x['text_crt_content'] = null;

				if ($this->add_type === 'self') {
					$x['text_csr_content'] = null;
				} else {
					$x['text_ca_content'] = null;
				}

				$this->convertToUnmodifiable($x);

				$vlist['text_key_content'] = $x['text_key_content'];
				$vlist['text_crt_content'] = $x['text_crt_content'];

				if ($this->add_type === 'self') {
					$vlist['text_csr_content'] = $x['text_csr_content'];
				} else {
					$vlist['text_ca_content'] = $x['text_ca_content'];
				}
			}
		}

		// MR -- disable it for enable 'update' button

		$vlist['__v_button'] = array();

		return $vlist;
	}

	function getUserList()
	{
		$sync = 'localhost';
		if (isset($this->syncserver) && is_string($this->syncserver) && $this->syncserver !== '') {
			$sync = $this->syncserver;
		} elseif (isset($this->__syncserver) && is_string($this->__syncserver) && $this->__syncserver !== '') {
			$sync = $this->__syncserver;
		}

		$clist = array();
		try {
			$got = rl_exec_get('localhost', $sync, 'getAllClientList', array($sync));
			if (is_array($got)) {
				$clist = $got;
			}
		} catch (Throwable $e) {
			$clist = array();
		}

		$users = array();
		foreach ($clist as $n) {
			if (!is_string($n) || $n === '') {
				continue;
			}
			$userinfo = function_exists('posix_getpwnam') ? @posix_getpwnam($n) : false;
			if (!is_array($userinfo) || !isset($userinfo['uid'])) {
				continue;
			}
			$fpmport = (50000 + (int)$userinfo['uid']);
			if ($fpmport === 50000) {
				continue;
			}
			$users[] = $n;
		}

		return $users;
	}

	function getCRTParse()
	{
		$crt = (isset($this->text_crt_content) && is_string($this->text_crt_content))
			? $this->text_crt_content : '';
		if ($crt === '') {
			return '-';
		}

		$res = @openssl_x509_read($crt);
		if ($res === false) {
			return '-';
		}
		$ar = @openssl_x509_parse($res);
		if (!is_array($ar)) {
			return '-';
		}

		$validfrom = isset($ar['validFrom_time_t']) ? date('Y-m-d', (int)$ar['validFrom_time_t']) : '-';
		$validto = isset($ar['validTo_time_t']) ? date('Y-m-d', (int)$ar['validTo_time_t']) : '-';
		$san = '-';
		if (isset($ar['extensions']['subjectAltName']) && is_string($ar['extensions']['subjectAltName'])) {
			$san = str_replace("DNS:", "", $ar['extensions']['subjectAltName']);
		}
		$issuer = '-';
		if (isset($ar['issuer']['CN']) && is_string($ar['issuer']['CN']) && $ar['issuer']['CN'] !== '') {
			$issuer = $ar['issuer']['CN'];
		}
		$cn = '-';
		if (isset($ar['subject']['CN']) && is_string($ar['subject']['CN']) && $ar['subject']['CN'] !== '') {
			$cn = $ar['subject']['CN'];
		}

		return "- Common Name: {$cn}\n" .
			"- Subject Alt Name: {$san}\n" .
			"- Issuer: {$issuer}\n" .
			"- Valid: {$validfrom} - {$validto}";
	}

	static function checkAndThrow($publickey, $privatekey, $throwname = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!self::checkKeyCert($publickey, $privatekey)) {
			if ($gbl->__restore_flag) {
				log_log("restore", "certificate_key_file_corrupted");
			} else {
				throw new lxException($login->getThrow("certificate_key_file_corrupted"), '', $throwname);
			}
		}
	}

	static function checkKeyCert($public_key, $privatekey)
	{
		if (!is_string($public_key) || trim($public_key) === ''
			|| !is_string($privatekey) || trim($privatekey) === '') {
			return false;
		}

		$pubkey_res = @openssl_get_publickey($public_key);
		$priv_key_res = @openssl_get_privatekey($privatekey, '');
		if ($pubkey_res === false || $priv_key_res === false) {
			return false;
		}

		$s = 'mystring';
		$encrypted_string = '';
		$decrypted_string = '';
		$okEnc = @openssl_private_encrypt($s, $encrypted_string, $priv_key_res);
		$okDec = $okEnc && @openssl_public_decrypt($encrypted_string, $decrypted_string, $public_key);

		return $okDec && ($decrypted_string === $s);
	}

	static function createListNlist($parent, $view)
	{
		$nlist['username'] = '15%';
		$nlist['nname'] = '40%';
		$nlist['add_type'] = '15%';
		$nlist['parent_domain'] = '15%';
		$nlist['syncserver'] = '15%';

		return $nlist;
	}

	function createShowUpdateform()
	{
		$uflist['update'] = null;

		return $uflist;
	}

	static function createListAlist($parent, $class)
	{
		return self::sslPropertyTabs($parent, $class);
	}

	static function createListAddForm($parent, $class)
	{
		return false;
	}

	function createShowPropertyList(&$alist)
	{
		global $sgbl, $login;

		$prgm = (isset($sgbl) && is_object($sgbl) && isset($sgbl->__var_program_name))
			? $sgbl->__var_program_name : 'kloxo';

		$alist['property'][] = 'a=show';

		if (is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin()) {
			$alist['property'][] = "a=updateform&sa=ssl_$prgm";
		}
	}

	function updateSetProgramSSL($param)
	{
		global $login;

		$contentscrt = $this->text_crt_content;
		$contentskey = $this->text_key_content;
		$contentsca = trim($this->text_ca_content);

		if (!$contentscrt || !$contentskey) {
			throw new lxException($login->getThrow("certificate_key_file_empty"));
		}

		self::checkAndThrow($contentscrt, $contentskey, null);

		// MR -- make the same as program.pem; like inside lighttpd.conf example inside
		$contentspem = "$contentskey\n$contentscrt";

		rl_exec_get(null, $param['slave_id'], array("sslcert", "setProgramSsl"),
			array($contentspem, $contentsca, $contentscrt, $contentskey));
	}

	static function setProgramSsl($contentspem, $contentsca, $contentscrt, $contentskey)
	{
		$list = array('key' => $contentskey, 'crt' => $contentscrt, 'pem' => $contentspem, 'ca' => $contentsca);

		foreach ($list as $k => $v) {
			if ($v) {
				lfile_put_contents("../etc/program.{$k}", $v);
				lxfile_unix_chown("../etc/program.{$k}", "lxlabs:lxlabs");
			}
		}
	}

	function updatessl_hypervm($param)
	{
		$this->updateSetProgramSSL($param);
	}

	function updatessl_kloxo($param)
	{
		global $login, $sgbl;

		$parent = $this->getParentO();

		if (self::sslParentIsWeb($parent)) {
			$spath = "/home/kloxo/ssl";
			$tpath = $sgbl->__path_program_etc;
			$dom = $this->nname;

			$list = array('key', 'crt', 'ca', 'pem');
			
			foreach ($list as $k => $v) {
				if (file_exists("{$spath}/{$dom}.{$v}")) {
					exec("ln -sf {$spath}/{$dom}.{$v} {$tpath}/program.{$v}");
				}
			}

			// MR -- make pure-ftp using the same ssl

			$ppath = "/etc/pki/pure-ftpd";

			if (!is_link("{$ppath}/pure-ftpd.pem")) {
				if (file_exists("{$ppath}/pure-ftpd.pem")) {
					exec("'mv' -f {$ppath}/pure-ftpd.pem {$ppath}/pure-ftpd.pem.old");
				}
				exec("'ln' -sf {$tpath}/program.pem {$ppath}/pure-ftpd.pem");
			}
		} else {
			$this->updateSetProgramSSL($param);
		}
	}

	function deleteSpecific()
	{
		global $gbl;

		$parent = $this->getParentO();

		if (self::sslParentIsWeb($parent)) {
			$name = self::sslParentNname($parent);
			$user = (isset($parent->customer_name) && is_string($parent->customer_name))
				? $parent->customer_name : '';

		//	$path = "/home/{$user}/ssl";
		//	$path = "/home/kloxo/client/{$user}/ssl";
			$spath = "/home/kloxo/ssl";

			$acpath = "/root/.acme.sh";
			$lepath = "/etc/letsencrypt";
			$stpath = "/root/.startapi.sh";

			exec("'rm' -rf {$acpath}/{$name}*");
			exec("'rm' -rf {$lepath}/{live,archive,renewal}/{$name}* {$spath}/{$name}*");
			exec("'rm' -rf {$stpath}/{$name}*");

			lxshell_return("sh", "/script/fixweb", "--domain={$name}");
			createRestartFile("restart-web");
		}
	}

	/**
	 * Keep failure redirects on this addform (not admin Home), and success on
	 * the SSL Certificates list for the same domain/web object path.
	 *
	 * @param object|null $parent
	 * @param array<string,mixed> $param
	 */
	static function rememberSslRedirects($parent, $param)
	{
		global $gbl, $ghtml;

		$kind = '';
		if (is_array($param) && isset($param['upload']) && is_string($param['upload'])) {
			$kind = $param['upload'];
		}
		$addUrl = '';
		$listUrl = '';
		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			$addUrl = ($kind !== '')
				? $ghtml->getFullUrl('a=addform&c=sslcert&dta[var]=upload&dta[val]=' . rawurlencode($kind))
				: $ghtml->getFullUrl('a=addform&c=sslcert');
			$listUrl = $ghtml->getFullUrl('a=list&c=sslcert');
		}
		// Rebuild from parent path if getFullUrl lost frm_o_o (would bounce Home).
		if ((!is_string($addUrl) || $addUrl === '' || !str_contains((string) $addUrl, 'frm_o_o'))
			&& is_object($parent)) {
			$built = self::sslUrlsFromParent($parent, $kind);
			if ($built['add'] !== '') {
				$addUrl = $built['add'];
			}
			if ($built['list'] !== '') {
				$listUrl = $built['list'];
			}
		}
		if (!is_string($addUrl) || $addUrl === '') {
			return;
		}
		if (is_object($gbl)) {
			// In-memory: print_redirect_back reads this even when c_session is missing.
			$gbl->__kloxo_redirect_back = $addUrl;
			if (method_exists($gbl, 'setSessionV')) {
				$gbl->setSessionV('lx_http_referer', $addUrl);
				$gbl->setSessionV('kloxo_ssl_addform_referer', $addUrl);
			}
			if (is_string($listUrl) && $listUrl !== '') {
				$gbl->__this_redirect = $listUrl;
			}
		}
	}

	/**
	 * Build SSL addform / list URLs from the parent object chain.
	 *
	 * @return array{add:string,list:string}
	 */
	static function sslUrlsFromParent($parent, $kind = '')
	{
		$chain = array();
		$cur = $parent;
		$guard = 0;
		while (is_object($cur) && $guard < 8) {
			$guard++;
			$class = method_exists($cur, 'getClass') ? (string) $cur->getClass() : '';
			$nname = (isset($cur->nname) && is_string($cur->nname)) ? $cur->nname : '';
			if ($class !== '' && $nname !== '') {
				array_unshift($chain, array('class' => $class, 'nname' => $nname));
			}
			try {
				$next = method_exists($cur, 'getParentO') ? $cur->getParentO() : null;
			} catch (Throwable $e) {
				$next = null;
			}
			if ($next === $cur || $next === null) {
				break;
			}
			$cur = $next;
		}
		$q = array();
		foreach ($chain as $i => $node) {
			$q[] = 'frm_o_o[' . $i . '][class]=' . rawurlencode($node['class']);
			$q[] = 'frm_o_o[' . $i . '][nname]=' . rawurlencode($node['nname']);
		}
		$base = '/display.php?' . implode('&', $q);
		$sep = ($q === array()) ? '' : '&';
		$list = $base . $sep . 'frm_action=list&frm_o_cname=sslcert';
		$add = $base . $sep . 'frm_action=addform&frm_o_cname=sslcert';
		if (is_string($kind) && $kind !== '') {
			$add .= '&frm_dttype[var]=upload&frm_dttype[val]=' . rawurlencode($kind);
		}

		return array('add' => $add, 'list' => $list);
	}

	/**
	 * Root SSL work via kloxo-backend (panel FPM cannot write /home/kloxo/ssl).
	 *
	 * @param list<mixed> $args
	 * @return mixed
	 */
	function execSsl($method, $args)
	{
		global $login;

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ssl.php';
		$server = 'localhost';
		if (isset($this->syncserver) && is_string($this->syncserver) && $this->syncserver !== '') {
			$server = $this->syncserver;
		} else {
			try {
				$parent = $this->getParentO();
				if (is_object($parent) && isset($parent->syncserver)
					&& is_string($parent->syncserver) && $parent->syncserver !== '') {
					$server = $parent->syncserver;
				}
			} catch (Throwable $e) {
			}
		}
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			return call_user_func_array(array('KloxoSsl', $method), $args);
		}
		try {
			$got = rl_exec_get(null, $server, array('KloxoSsl', $method), $args, false);
		} catch (Throwable $e) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				$e->getMessage());
		}
		if ($got instanceof Exception || $got instanceof Throwable) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				$got->getMessage());
		}

		return $got;
	}

	static function add($parent, $class, $param)
	{
		global $login;

		if (!is_array($param)) {
			$param = array();
		}

		self::rememberSslRedirects($parent, $param);

		foreach ($param as $k => $v) {
			if (!is_string($v)) {
				continue;
			}
			if (strtoupper(trim($v)) === 'N/A') {
			//	$param[$k] = '.';
			}
		}

		if (self::sslParentIsWeb($parent)) {
			$param['nname'] = self::sslParentNname($parent);
		}

		if (isset($param['upload'])) {
			if ($param['upload'] === 'file') {
				$param['add_type'] = 'file';

				$key_file = isset($_FILES['ssl_key_file_f']['tmp_name']) ? $_FILES['ssl_key_file_f']['tmp_name'] : '';
				$crt_file = isset($_FILES['ssl_crt_file_f']['tmp_name']) ? $_FILES['ssl_crt_file_f']['tmp_name'] : '';
				$ca_file = isset($_FILES['ssl_ca_file_f']['tmp_name']) ? $_FILES['ssl_ca_file_f']['tmp_name'] : '';

				if (!$key_file || !$crt_file) {
					throw new lxException($login->getThrow("key_crt_files_needed"));
				}

				$param['text_key_content'] = lfile_get_contents($key_file);
				$param['text_crt_content'] = lfile_get_contents($crt_file);

				if ($ca_file && lxfile_exists($ca_file)) {
					$param['text_ca_content'] = lfile_get_contents($ca_file);
				}
			} elseif ($param['upload'] === 'text') {
				$param['add_type'] = 'text';

				self::checkAndThrow(
					isset($param['text_crt_content']) ? $param['text_crt_content'] : '',
					isset($param['text_key_content']) ? $param['text_key_content'] : ''
				);
			} elseif ($param['upload'] === 'letsencrypt') {
				$param['add_type'] = 'letsencrypt';

				if (isset($param['ssl_data_b_s_subjectAltName_r'])) {
					$param['ssl_data_b_s_subjectAltName_r'] = replace_to_space($param['ssl_data_b_s_subjectAltName_r']);
				}
			} elseif ($param['upload'] === 'startapi') {
				$param['add_type'] = 'startapi';

				if (isset($param['ssl_data_b_s_subjectAltName_r'])) {
					$param['ssl_data_b_s_subjectAltName_r'] = replace_to_space($param['ssl_data_b_s_subjectAltName_r']);
				}
			} elseif ($param['upload'] === 'link') {
				$param['add_type'] = 'link';
			}
		} else {
			$param['add_type'] = 'self';

			if (isset($param['ssl_data_b_s_commonName_r'])) {
				$param['ssl_data_b_s_commonName_r'] = replace_to_space($param['ssl_data_b_s_commonName_r']);
			}
		}

		$param['certname'] = $param['nname'];

		return $param;
	}

	function postAdd()
	{
		switch ($this->add_type) {
			case 'self':
				$this->createSelfCertificate();
				break;
			case 'letsencrypt':
				$this->createLetsencrypt();
				break;
		//	case 'startapi':
		//		$this->createStartapi();
		//		break;
			case 'link':
				$this->createLink();
				break;
			default:
				$parent = $this->getParentO();

				if (self::sslParentIsWeb($parent)) {
					$this->createDomainSSL();
				}
		}
	}

	function createDomainSSL()
	{
		global $login;

		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}
		$name = self::sslParentNname($parent);

		$contentscrt = isset($this->text_crt_content) ? (string) $this->text_crt_content : '';
		$contentskey = isset($this->text_key_content) ? (string) $this->text_key_content : '';
		$contentscsr = isset($this->text_csr_content) ? (string) $this->text_csr_content : '';
		$contentsca = isset($this->text_ca_content) ? trim((string) $this->text_ca_content) : '';

		if ($contentscrt === '' || $contentskey === '') {
			throw new lxException($login->getThrow('certificate_key_file_empty'));
		}

		self::checkAndThrow($contentscrt, $contentskey, $name !== '' ? $name : null);

		if ($name === '') {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				'missing domain name for SSL install');
		}

		try {
			$this->execSsl('installDomainCert', array(
				$name, $contentskey, $contentscrt, $contentsca, $contentscsr, true,
			));
		} catch (lxException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				$e->getMessage());
		}
	}

	function isSelect()
	{
		return true;

	//	$db = new Sqlite($this->__masterserver, "sslipaddress");
	//	$res = $db->getRowsWhere("sslcert = '$this->certname'", array('nname'));

	//	return ($res ? false : true);
	}

	static function addform($parent, $class, $typetd = null)
	{
		global $sgbl;

		$vlist = array();
		$d = self::sslParentNname($parent);
		$kind = self::sslDttypeVal($typetd);

		if (self::sslParentIsWeb($parent)) {
			$nname = array('M', $d);
			$keybits = array("s", array("2048", "4096", "ec-256", "ec-384"));

			$vlist['username'] = array("h", self::sslOwnerName($parent));

			if ($kind === 'file') {
				$vlist['nname'] = $nname;
				$vlist['ssl_key_file_f'] = null;
				$vlist['ssl_crt_file_f'] = null;
				$vlist['ssl_ca_file_f'] = null;
				if (isset($sgbl) && is_object($sgbl)) {
					$sgbl->method = 'post';
				}
			} else if ($kind === 'text') {
				$vlist['nname'] = $nname;
				$vlist['text_key_content'] = null;
				$vlist['text_crt_content'] = null;
				$vlist['text_ca_content'] = null;
			} else if ($kind === 'letsencrypt') {
				$vlist['nname'] = $nname;
				$vlist['ssl_data_b_s_key_bits_r'] = $keybits;

				// Default: apex + www only. Extra names (webmail/mail/cp/stats) often
				// lack DNS or follow redirects and make Let's Encrypt return 404.
				$san = $d !== '' ? "{$d} www.{$d}" : '';

				$parked = array();
				try {
					$dom = (is_object($parent) && method_exists($parent, 'getParentO'))
						? $parent->getParentO() : null;
					if (is_object($dom) && method_exists($dom, 'getList')) {
						$got = $dom->getList('addondomain');
						if (is_array($got)) {
							$parked = $got;
						}
					}
				} catch (Throwable $e) {
					$parked = array();
				}
				foreach ($parked as $k => $v) {
					if (is_object($v) && isset($v->ttype) && $v->ttype === 'parked') {
						$san .= " {$k} www.{$k}";
					}
				}

				$vlist["ssl_data_b_s_subjectAltName_r"] = array('t', $san);
				$email = ($d !== '') ? "admin@{$d}" : '';
				$vlist["ssl_data_b_s_emailAddress_r"] = array("m", $email);
			} else if ($kind === 'link') {
				$vlist['nname'] = $nname;
				$ssllist = self::getSSLParentList($d);
				if (is_array($ssllist) && $ssllist !== array()) {
					$vlist['parent_domain'] = array("s", $ssllist);
				}
			}
		} else {
			$vlist['username'] = array("h", $d !== '' ? $d : self::sslOwnerName($parent));
			$vlist['nname'] = null;
			$vlist['ssl_data_b_s_key_bits_r'] = array("s", array("2048", "4096"));
			$vlist["ssl_data_b_s_commonName_r"] = null;
			$temp = self::getCountryList();
			$vlist["ssl_data_b_s_countryName_r"] = array("s", $temp);
			$vlist["ssl_data_b_s_stateOrProvinceName_r"] = array("m", "N/A");
			$vlist["ssl_data_b_s_localityName_r"] = array("m", "N/A");
			$vlist["ssl_data_b_s_organizationName_r"] = array("m", "N/A");
			$vlist["ssl_data_b_s_organizationalUnitName_r"] = array("m", "N/A");
			$vlist["ssl_data_b_s_emailAddress_r"] = null;
		}

		$ret['action'] = 'add';
		$ret['variable'] = $vlist;

		return $ret;
	}

	static function getSSLParentList($nname)
	{
		$ssllist = array();
		$found = glob("/home/kloxo/ssl/*.ca", GLOB_MARK);
		if (!is_array($found)) {
			return $ssllist;
		}
		$skip = is_string($nname) ? $nname : '';
		foreach ($found as $filename) {
			if (!is_string($filename) || is_link($filename)) {
				continue;
			}
			$x = str_replace(".ca", "", basename($filename));
			if ($x !== $skip && $x !== '') {
				$ssllist[] = $x;
			}
		}

		return $ssllist;
	}

	static function getCountryList()
	{
		include "lib/html/countrycode.inc";
		$a[] = "N/A";

		foreach ($gl_country_code as $key => $name) {
			$a[] = "$key:$name";
		}

		return $a;
	}

	function createSelfCertificate()
	{
		global $login;

		$temp = array();
		foreach ($this->sslDataFields() as $key => $value) {
			if (!cse($key, '_r')) {
				continue;
			}
			$nk = strtil($key, '_r');
			$temp[$nk] = is_string($value) ? $value : (string) $value;
		}

		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}

		if (self::sslParentIsWeb($parent)) {
			$name = self::sslParentNname($parent);
		} else {
			$name = (isset($this->nname) && is_string($this->nname) && $this->nname !== '')
				? $this->nname : 'openssl';
		}
		if ($name === '') {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				'missing certificate name');
		}

		$subj = array();
		foreach ($temp as $key => $t) {
			if ($key === 'countryName') {
				$l = explode(':', (string) $t);
				$subj[$key] = $l[0];
			} elseif ($key === 'commonName') {
				$subj[$key] = replace_to_space((string) $t);
			} elseif ($key !== 'key_bits' && $key !== 'subjectAltName' && $key !== 'name') {
				$subj[$key] = (string) $t;
			}
		}
		if (!isset($subj['commonName']) || $subj['commonName'] === '') {
			$subj['commonName'] = $name;
		}
		$bits = 2048;
		if (isset($temp['key_bits']) && (string) $temp['key_bits'] === '4096') {
			$bits = 4096;
		}

		try {
			$got = $this->execSsl('createSelfSigned', array($name, $subj, $bits, true));
		} catch (lxException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				$e->getMessage());
		}
		if (!is_array($got) || empty($got['key']) || empty($got['crt'])) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				'self-signed openssl returned no certificate');
		}
		$this->text_key_content = (string) $got['key'];
		$this->text_crt_content = (string) $got['crt'];
		$this->text_csr_content = isset($got['csr']) ? (string) $got['csr'] : '';

		if (self::sslParentIsWeb($parent)) {
			$this->createDomainSSL();
		}
	}

	function createLetsencrypt()
	{
		global $login;

		$temp = array();
		foreach ($this->sslDataFields() as $key => $value) {
			if (!cse($key, '_r')) {
				continue;
			}
			$nk = strtil($key, '_r');
			$temp[$nk] = is_string($value) ? $value : (string) $value;
		}

		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}
		$name = self::sslParentNname($parent);
		if ($name === '') {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				'missing domain name for Let\'s Encrypt');
		}

		$sanRaw = isset($temp['subjectAltName'])
			? replace_to_space((string) $temp['subjectAltName'])
			: $name;
		$sans = preg_split('/\s+/', trim($sanRaw)) ?: array();
		$sans = array_values(array_filter($sans, static function ($s) {
			return is_string($s) && $s !== '';
		}));
		$email = isset($temp['emailAddress']) ? (string) $temp['emailAddress'] : '';
		$keyBits = isset($temp['key_bits']) ? (string) $temp['key_bits'] : '2048';

		$docroot = '';
		if (self::sslParentIsWeb($parent) && method_exists($parent, 'getFullDocRoot')) {
			try {
				$gotRoot = $parent->getFullDocRoot();
				if (is_string($gotRoot) && $gotRoot !== '') {
					$docroot = $gotRoot;
				}
			} catch (Throwable $e) {
				$docroot = '';
			}
		}

		try {
			$got = $this->execSsl('issueLetsEncrypt', array($name, $email, $sans, $keyBits, $docroot));
		} catch (lxException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				$e->getMessage());
		}
		if (!is_array($got) || empty($got['key']) || empty($got['crt'])) {
			throw new lxException($login->getThrow('create_certificate_failed'), '',
				'Let\'s Encrypt returned no certificate');
		}
		$this->text_key_content = (string) $got['key'];
		$this->text_crt_content = (string) $got['crt'];
		$this->text_ca_content = isset($got['ca']) ? (string) $got['ca'] : '';
	}

	function createStartapi()
	{
		global $gbl, $sgbl, $login, $ghtml;

		foreach ($this->sslDataFields() as $key => $value) {
			if (!cse($key, "_r")) {
				continue;
			}

			$nk = strtil($key, "_r");
			$temp[$nk] = $value;
		}

		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}

		$name = $temp['name'] = self::sslParentNname($parent);

		$shpath = "/home/kloxo/ssl";

		foreach ($temp as $key => $t) {
			if ($key === 'subjectAltName') {
				$val = replace_to_space($t);
			} else {
				$val = $t;
			}

			$input[$key] = $val;
		}

		$input['docroot'] = $parent->getFullDocRoot();

		$tplsource = getLinkCustomfile("/opt/configs/startapi.sh/tpl", "startapi.sh.tpl");
		$tpltarget = "{$shpath}/{$name}_startapi.sh";

		$tpl = lfile_get_contents($tplsource);

		$tplparse = getParseInlinePhp($tpl, $input);

		if (!file_exists($shpath)) {
			mkdir($shpath);
		}

		if ($tplparse) {
			lfile_put_contents($tpltarget, $tplparse);
		}

		exec("cd {$shpath}; sh {$name}_startapi.sh", $out, $ret);

		if ($ret !== 0) {
			throw new lxException($login->getThrow("create_certificate_failed"), '', $parent->nname);
		}

		$stpath = "/root/.startapi.sh/{$name}";

		$this->text_key_content = lfile_get_contents("{$stpath}/{$name}.key");
		$this->text_crt_content = lfile_get_contents("{$stpath}/{$name}.cer");
		$this->text_ca_content = lfile_get_contents("{$stpath}/ca.cer");

		exec("sh /script/fixweb --domain={$name}");
		createRestartFile("restart-web");
	}

	function createLink()
	{
		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}
	//	$user = $parent->customer_name;

	//	$filesearch = "/home/kloxo/client/*/ssl/{$this->parent_domain}.ca";
		$filesearch = "/home/kloxo/ssl/{$this->parent_domain}.ca";

		$sslparent = '';
		$found = glob($filesearch, GLOB_MARK);
		if (is_array($found)) {
			foreach ($found as $filename) {
				$sslparent = str_replace(".ca", "", $filename);
			}
		}

	//	$targetpath = "/home/kloxo/client/{$user}/ssl";
		$targetpath = "/home/kloxo/ssl";

		if (!file_exists($targetpath)) {
			mkdir($targetpath);
		}

		$list = array('key', 'crt', 'ca', 'pem');
		$dom = self::sslParentNname($parent);

		foreach ($list as $k => $v) {
			if ($sslparent !== '' && $dom !== '' && file_exists("{$sslparent}.{$v}")) {
				exec("ln -sf {$sslparent}.{$v} {$targetpath}/{$dom}.{$v}");
			}
		}
	
		exec("sh /script/fixweb --domain={$this->parent_domain}");
	//	createRestartFile($gbl->getSyncClass(null, $this->syncserver, 'web'));
		createRestartFile("restart-web");
	}

	static function getSslCertnameFromIP($ipname)
	{
		return fix_nname_to_be_variable($ipname);
	}

	function isSync() { return false; }
}

class all_sslcert extends SslCert
{
	static $__desc = array("n", "",  "all_sslcert");
	static $__desc_parent_name_f =  array("n", "",  "owner");

	function isSelect()
	{
		return false;
	}

	static function initThisListRule($parent, $class)
	{
		global $login;

		if (!$parent->isAdmin()) {
			throw new lxException($login->getThrow("only_admin_can_access"));
		}

		return "__v_table";
	}

	static function createListSlist($parent)
	{
		$nlist['nname'] = null;

		return $nlist;
	}

	static function AddListForm($parent, $class)
	{
		return null;
	}

	static function createListAlist($parent, $class)
	{
		return all_domain::createListAlist($parent, $class);
	}

	static function createListNlist($parent, $view)
	{
		$nlist['nname'] = '50%';
		$nlist['parent_name_f'] = '50%';
		
		return $nlist;
	}

	static function createListUpdateForm($object, $class)
	{
		return null;
	}
}

