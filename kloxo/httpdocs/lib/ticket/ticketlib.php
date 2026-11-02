<?php

class Ticket extends Lxclient 
{

	static $__desc = array("", "",  "ticket");

	// Core

	// Data
	static $__desc_nname =  array("", "",  "id", "a=show");
	static $__desc_state =   array("e", "",  "s:state");
	static $__desc_state_v_open =   array("", "",  "Open");
	static $__desc_state_v_fixed =   array("", "",  "fixed");
	static $__desc_escalate =   array("e", "",  "E:escalate");
	static $__desc_escalate_v_ =   array("", "",  "Off");
	static $__desc_escalate_v_on =   array("", "",  "On");
	static $__desc_escalate_v_off =   array("", "",  "Off");
	static $__desc_escalate_v_dull =   array("", "",  "Off");
	static $__desc_state_v_analyzed =   array("", "",  "analyzed");
	static $__desc_state_v_closed =   array("", "",  "closed");
	static $__desc_priority =   array("e", "",  "p:priority");
	static $__desc_priority_v_low =   array("", "",  "low");
	static $__desc_priority_v_medium =   array("", "",  "medium");
	static $__desc_priority_v_high =   array("", "",  "high");
	static $__desc_priority_v_urgent =   array("", "",  "urgent");
	static $__desc_responsible =   array("", "",  "responsible");
	static $__desc_category =   array("", "",  "category");
	static $__desc_subcategory =   array("", "",  "sub_category");
	static $__desc_made_by =   array("", "",  "created_by");
	static $__desc_history_num =   array("", "",  "replies");
	static $__desc_sent_to =   array("", "",  "sent_to:send_to");
	static $__desc_subject =   array("n", "",  "subject", "a=show");
	static $__desc_ddate =   array("", "",  "date");
	static $__desc_date_modified =   array("", "",  "modified_on");
	static $__desc_unread_flag =   array("e", "",  "unread");
	static $__desc_unread_flag_v_dull =   array("e", "",  "read");
	static $__desc_unread_flag_v_on =   array("e", "",  "unread");

	static $__desc_descr_f =   array("t", "",  "description");

	static $__acdesc_update_close =   array("", "",  "close");
	static $__acdesc_list =   array("", "",  "help_desk");
	static $__acdesc_update_escalate =   array("", "",  "escalate");

	static $__desc_filter_show_nonclosed = array("", "",  "show_nonclosed");
	static $__desc_filter_show_open = array("", "",  "show_open");
	static $__desc_filter_show_all = array("", "",  "show_all");
	static $__desc_filter_show_mine = array("", "",  "show_mine");

	// Objects

	// Lists
	static $__desc_tickethistory_l =   array("db", "",  "history");

	static $__hpfilter_show_all = '';
	static $__hpfilter_show_nonclosed = array('state', '!=', '"closed"');
	static $__hpfilter_show_open = array('state', '=', '"open"');
	static $__hpfilter_show_mine = array('sent_to', '=', '"client-admin"');

	static function createListAlist($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist[] = "a=list&c=$class";
	//	$alist[] = "a=addform&c=$class";
		
		if ($login->isAdmin()) {
			$alist[] = "o=ticketconfig&a=updateform&sa=ticketconfig";
			$alist[] = "o=general&c=helpdeskcategory_a&a=list";
		}
		
		return $alist;
	}

	function close()
	{
		$this->state = "closed";
		$this->dbaction = "update";
	}

	static function searchVar() { return "subject"; }
	
	function isSync() { return false; }
	
	function isSelect()
	{
		global $gbl, $sgbl, $login, $ghtml;

		try {
			$p = $this->getParentO();
			if (is_object($p) && method_exists($p, 'isAdmin')) {
				return $p->isAdmin();
			}
		} catch (Throwable $e) {
		}

		return is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin();
	}

	static function canReadPanelDb()
	{
		$pass = lfile_get_contents("__path_admin_pass");

		return is_string($pass) && trim($pass) !== '';
	}

	function getSelfList()
	{
		global $gbl, $sgbl, $login, $ghtml;

		$out = array();
		$parent = null;
		if (isset($gbl->__self_list_parent) && is_object($gbl->__self_list_parent)) {
			$parent = $gbl->__self_list_parent;
		}
		if (!is_object($parent)) {
			try {
				$parent = $this->getParentO();
			} catch (Throwable $e) {
				$parent = null;
			}
		}
		if (!is_object($parent) && is_object($login) && method_exists($login, 'getList')) {
			$parent = $login;
		}

		$list = array();
		$canDb = self::canReadPanelDb();
		if ($canDb && is_object($parent) && method_exists($parent, 'getList')) {
			try {
				$got = $parent->getList('ticket');
				if (is_array($got)) {
					$list = $got;
				}
			} catch (Throwable $e) {
				$list = array();
			}
		}
		if ($canDb && $list === array() && is_object($login) && method_exists($login, 'isAdmin')
			&& $login->isAdmin() && method_exists($login, 'getList') && $parent !== $login) {
			try {
				$got = $login->getList('ticket');
				if (is_array($got)) {
					$list = $got;
				}
			} catch (Throwable $e) {
				$list = array();
			}
		}

		foreach ($list as $k => $t) {
			if (is_object($t) && method_exists($t, 'getId')) {
				$out[(string)$k] = $t;
			}
		}

		if ($out === array()) {
			$n = isset($this->nname) ? (string)$this->nname : '1';
			$out[$n] = $this;
		}

		return $out;
	}

	static function defaultSort() { return "ddate"; }
	
	static function defaultSortDir() { return "desc"; }

	function isAction($var)
	{
		global $gbl, $sgbl, $login;

		if ($var === "made_by" || $var === "sent_to") {
			if ($this->$var === $login->nname) {
				return false;
			}
		}
		
		return true;
	}

	function createShowAddform()
	{
		$aflist['tickethistory'] = null;

		return $aflist;
	}

	function createShowPropertyList(&$alist)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist['property'][] = "a=show";
		$alist['property'][] = "a=addform&c=tickethistory";

		$is_admin = is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin();
		$is_debug = is_object($sgbl) && method_exists($sgbl, 'isdebug') && $sgbl->isdebug();
		if ($is_admin || $is_debug) {
			$alist['property'][] = "a=updateform&sa=escalate";
		}

		return $alist;
	}

	function updateform($subaction, $param)
	{
		if ($subaction === 'escalate') {
			return array();
		}

		try {
			$ret = parent::updateform($subaction, $param);
		} catch (Throwable $e) {
			log_log("error", "ticket updateform $subaction: " . $e->getMessage());
			$ret = array();
		}

		return is_array($ret) ? $ret : array();
	}

	static function createListNlist($parent, $view)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$nlist['nname'] = "1%";
	//	$nlist['priority'] = '3%';
		
	//	if ($login->isAdmin() && ($sgbl->isLxlabsClient() || $sgbl->isdebug())) {
		if ($login->isAdmin() || $sgbl->isdebug()) {
			$nlist['escalate'] = '3%';
		}
		
		$nlist['unread_flag'] = '3%';
		$nlist['made_by'] = '10%';
		$nlist['subject'] = '60%';
		$nlist['state'] = '3%';

		if ($sgbl->__var_ticket_subcategory) {
			$nlist['subcategory'] = '3%';
		}

		$nlist['category'] = '3%';
		$nlist['date_modified'] = '3%';
		$nlist['history_num'] = '3%';
	//	$nlist['responsible'] = '10%';
		$nlist['sent_to'] = '25%';
		
		return $nlist;
	}

	static function createListSlist($parent)
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		$nlist['nname'] = null;

		$cat = array();
		try {
			$gen = $login->getObject('general');
			if (is_object($gen)) {
				$cat = get_namelist_from_objectlist($gen->helpdeskcategory_a);
			}
		} catch (Throwable $e) {
			$cat = array();
		}
		$cat = lx_merge_good('--any--', $cat);

		$nlist['category'] = array('s', $cat);
		
		return $nlist;
	}

	function updateEscalate($param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_array($param)) {
			$param = array();
		}

		$list = array();
		if (self::canReadPanelDb()) {
			try {
				$got = $this->getList('tickethistory');
				if (is_array($got)) {
					$list = $got;
				}
			} catch (Throwable $e) {
				$list = array();
			}
		}
		$message = null;

		foreach($list as $l) {
			$dd = @ date('Y-M-d h:m');
			$message .= "----------$dd-------\n";
			$message .= is_object($l) ? (string)$l->text_reason : '';
			$message .= "\n";
		}

		try {
			$this->history_num = (int)($this->history_num ?? 0) + 1;
			$hist = new TicketHistory($this->__masterserver, $this->__readserver, $this->nname . "___" . $this->history_num);
			$rhis['text_reason'] = "\nEscalated\n\n";
			$rhis['state_from'] = "";
			$rhis['ddate'] = time();
			$rhis['parent_clname'] = $this->getClName();
			$rhis['state'] = "open";
			$rhis['made_by'] = $this->made_by;
			$hist->create($rhis);
			$this->addToList("tickethistory", $hist);
		} catch (Throwable $e) {
			log_log("error", "ticket escalate history: " . $e->getMessage());
		}

		$em['text_reason'] = "Escalating the ticket: Past messages:\n\n";
		$em['text_reason'] .= $message;
		$em['state']  = 'escalate';
		$em['made_by']  = (is_object($login) && method_exists($login, 'getClName'))
			? $login->getClName() : 'client-admin';
		$this->escalate = 'on';
		try {
			ticketHistory::getObjectsTosend($this, $em, "ticketchange");
		} catch (Throwable $e) {
			log_log("error", "ticket escalate notify: " . $e->getMessage());
		}
		$param['nothing'] = 'h';

		return $param;
	}

	function createShowClist($subaction)
	{
		$clist['tickethistory'] = null;
		
		return $clist;
	}

	function updateClose($param)
	{
		$this->history_num = (int)($this->history_num ?? 0) + 1;
		$hist = new TicketHistory($this->__masterserver, $this->__readserver, $this->nname . "___" . $this->history_num);
		$rhis['text_reason'] = "Default Close";
		$rhis['state_from'] = "";
		$rhis['ddate'] = time();
		$rhis['parent_clname'] = $this->getClName();
		$rhis['state'] = "closed";
		$rhis['made_by'] = $this->made_by;
		$hist->create($rhis);
		$this->addToList("tickethistory", $hist);
		$param['state'] = 'closed';
		ticketHistory::getObjectsTosend($this, $rhis, "ticketchange");
		
		return $param;
	}

	function deleteSpecific()
	{
		$rhis['state'] = "delete";
		$rhis['made_by'] = $this->getParentO()->nname;
		$rhis['text_reason'] = 'delete';
		ticketHistory::getObjectsTosend($this, $rhis, "ticketchange");
	}

	function postAdd()
	{
		$hist = new TicketHistory($this->__masterserver, $this->__readserver, $this->nname . "___" . '0');
		$rhis['text_reason'] = $this->descr_f;
		$rhis['state_from'] = "";
		$rhis['ddate'] = time();
		$rhis['parent_clname'] = $this->getClName();
		$rhis['state'] = "open";
		$rhis['made_by'] = $this->made_by;
		$hist->create($rhis);
		$this->addToList("tickethistory", $hist);

		$this->realpass = randomString(6);
		$this->password = crypt($this->realpass, '$1$'.randomString(8).'$');
		$this->status = 'on';
		$this->cpstatus = 'on';
		$this->escalate = 'dull';
		
		list($sec, $usec) = explode(" ", microtime());
		
	//	$this->mail_messageid = "<$sec$usec.GA8614@mratwork.com>";

		$this->mail_messageid = "<$sec$usec.GA8614@localhost>";

		ticketHistory::getObjectsTosend($this, $rhis, "ticketadd");
	}

	static function add($parent, $class, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if ($parent->get__table() !== 'client') {
			$param['sent_to'] = $parent->getParentO()->getClName();
		}

		if ($param['sent_to'] === '--all_children--') {
			throw new lxException($login->getThrow('select_one_child'), '', $param['sent_to']);
		}

		$param['parent_clname'] = "client-admin";
		$param['nname'] = getIncrementedValueFromTable("ticket", "nname");
		$param['made_by'] = $parent->getClName();
		$param['unread_flag'] = 'on';
		$param['state'] = 'open';
		$param['ddate'] = time();
		$param['date_modified'] = time();

		$param['history_num'] = 0;
		
		return $param;
	}

	static function getStateList()
	{
		$slist = array('open' => 'open', 'closed' => 'closed', 'fixed' => 'fixed', 'analyzed' => 'analyzed');
		
		return $slist;
	}

	static function createListBlist($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$blist[] = array("a=delete&c=$class");
	//	$blist[] = array("a=update&sa=close&c=$class");
		$blist[] = array("a=list&c=$class&frm_filter[show]=nonclosed", 1);
		$blist[] = array("a=list&c=$class&frm_filter[show]=open", 1);
		$blist[] = array("a=list&c=$class&frm_filter[show]=all", 1);

		return $blist;
	}

	static function createWelcomeTicket()
	{
		global $gbl, $sgbl, $login, $ghtml;

		try {
			$sq = new Sqlite(null, 'ticket');
			$have = $sq->getRowsWhere("subject = 'Welcome to Kloxo'");
			if (is_array($have) && count($have) > 0) {
				return;
			}
		} catch (Throwable $e) {
		}

		$param['parent_clname'] = createParentName('client', 'admin');
		$param['made_by'] = createParentName('pserver', 'Kloxo');
		$param['sent_to'] = createParentName('client', 'admin');
		$param['subject'] = "Welcome to Kloxo";
		$param['category'] = "Welcome";
		$param['descr_f'] = "Welcome to Kloxo";
		$param['ddate'] = time();
		$param['date_modified'] = time();
		$param['nname'] = '1';
		$param['unread_flag'] = 'on';
		$param['state'] = 'open';
		$param['history_num'] = 0;
		$param['escalate'] = 'dull';
		$param['status'] = 'on';
		$param['cpstatus'] = 'on';

		try {
			if (is_object($login) && method_exists($login, 'getObject')) {
				$ticketconfig = $login->getObject('ticketconfig');
				if (is_object($ticketconfig) && method_exists($ticketconfig, 'getAndIncrementTicket')) {
					$param['nname'] = (string)$ticketconfig->getAndIncrementTicket();
					$ticketconfig->write();
				}
			}
		} catch (Throwable $e) {
		}

		$ticket = new Ticket(null, null, $param['nname']);
		$ticket->create($param);
		try {
			$ticket->postAdd();
		} catch (Throwable $e) {
			$hist = new TicketHistory($ticket->__masterserver, $ticket->__readserver, $ticket->nname . "___0");
			$rhis = array(
				'text_reason' => $ticket->descr_f,
				'state_from' => '',
				'ddate' => time(),
				'parent_clname' => $ticket->getClName(),
				'state' => 'open',
				'made_by' => $ticket->made_by,
			);
			$hist->create($rhis);
			$ticket->addToList("tickethistory", $hist);
		}
		$ticket->was();
	}

	static function AddListForm($parent, $class)
	{
		return self::addform($parent, $class);
	}

	static function addform($parent, $class, $typetd = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$list = array();
		if (is_object($parent) && $parent->get__table() === 'client') {
			$tag['key'] = "--all_children--" ;
			$tag['val'] = "-----Children";
			try {
				$got = $parent->getAllChildrenAndParents($tag);
				$list = is_array($got) ? $got : array($tag['key'] => $tag['val']);
			} catch (Throwable $e) {
				$list = array($tag['key'] => $tag['val']);
			}
		}

		$cat = array();
		try {
			if (is_object($login) && method_exists($login, 'getObject')) {
				$gen = $login->getObject('general');
				if (is_object($gen)) {
					$cat = get_namelist_from_objectlist($gen->helpdeskcategory_a);
				}
			}
		} catch (Throwable $e) {
			$cat = array();
		}

		$vlist['made_by'] = array('M', is_object($parent) ? $parent->nname : '');

		if (is_object($parent) && $parent->get__table() === 'client') {
			$vlist['sent_to'] = array('A', $list);
		} else {
			$sent = '';
			if (is_object($parent)) {
				$pp = $parent->getParentO();
				$sent = is_object($pp) ? $pp->nname : '';
			}
			$vlist['sent_to'] = array('M', $sent);
		}

		if (!$cat) {
			$cat = array("Welcome", "Billing", "Complaint", "Other");
		}
		
		$vlist['category'] = array('s', $cat);
		$vlist['subject'] = null;
		$vlist['priority'] = array('s', array('low', 'medium', 'high', 'urgent'));
		$vlist['descr_f'] = null;
	
		$ret['variable'] = $vlist;
		$ret['action'] = 'add';
		
		return $ret;
	}

	function display($var)
	{
		if ($var === 'made_by' || $var === 'sent_to') {
			if (!isset($this->$var) || $this->$var === null || $this->$var === '') {
				return '';
			}
			try {
				return $this->getParentName($var);
			} catch (Throwable $e) {
				return (string)$this->$var;
			}
		}

		return parent::display($var);
	}

	function postRead()
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		if ($login->getClName() === $this->sent_to) {
			$this->unread_flag = 'dull';
			$this->setUpdateSubaction('');
		}
	}

	function dontDelete()
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		return !$login->isAdmin();
	}

	static function initThisListRule($parent, $class)
	{
		if ($parent->isAdmin()) {
			return "__v_table";
		}
		
		$res[] = array("made_by", '=', "'" . $parent->getClName() . "'");
		$res[] = 'OR';
		$res[] = array("sent_to", '=', "'" . $parent->getClName() ."'");
		
		return $res;
	}
}

