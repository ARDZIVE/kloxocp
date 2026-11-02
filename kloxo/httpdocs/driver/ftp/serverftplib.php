<?php 

class serverftp extends lxdb
{
	static $__desc = array("", "",  "FTP Configure");
	static $__acdesc_show = array("", "",  "FTP Configure");

	static $__desc_enable_anon_ftp = array("f", "", "enable_anonymous_ftp");
	static $__desc_highport = array("", "", "high_port_for_passive_ftp");
	static $__desc_lowport = array("", "", "low_port_for_passive_ftp");
	static $__desc_maxclient = array("", "", "maximum_number_of_clients");
	static $__desc_defaultport = array("", "", "default_port_ftp");
	static $__desc_enable_tls = array("f", "", "enable_tls");

	static $__acdesc_update = array("f", "", "update");
	static $__acdesc_update_update = array("", "", "FTP Configure");

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return $parent->getClName();
	}

	function isSync()
	{
		return $this->dbaction === 'update';
	}

	static function ftpPropertyTabs()
	{
		return array(
			'a=show',
		);
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::ftpPropertyTabs();

		return $alist;
	}

	function getSelfList()
	{
		try {
			$list = parent::getSelfList();
			if (is_array($list) && $list !== array()) {
				return $list;
			}
		} catch (Throwable $e) {
			log_log("error", "serverftp getSelfList parent: " . $e->getMessage());
		}

		if (class_exists('pserver') && is_callable(array('pserver', 'adminServerSwitchList'))) {
			return pserver::adminServerSwitchList($this);
		}

		return null;
	}

	function createExtraVariables() 
	{ 
		$this->setDefault();
	}

	function createShowUpdateform()
	{
		$uflist['update'] = null;

		return $uflist;
	}

	function updateform($subaction, $param)
	{
		$this->setDefault();
		$vlist['enable_anon_ftp'] = null;
		$vlist['maxclient'] = null;
		$vlist['lowport'] = null;
		$vlist['highport'] = null;
		$vlist['defaultport'] = null;
		$vlist['enable_tls'] = null;
		return $vlist;
	}

	function setDefault()
	{
		$this->setDefaultValue('lowport', "45000");
		$this->setDefaultValue('highport', "65000");
		$this->setDefaultValue('maxclient', "5000");
		$this->setDefaultValue('enable_anon_ftp', "on");
		$this->setDefaultValue('defaultport', "21");
		$this->setDefaultValue('enable_tls', "off");
	}
}
