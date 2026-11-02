<?php

class TicketHistory extends Lxdb 
{
	static $__desc = array("", "",  "reply");

	static $__desc_nname = array("S", "",  "ticket_name");
	static $__desc_state_from = array("", "",  "ticket_state_from");
	static $__desc_state = array("eS", "",  "state");
	static $__desc_state_v_open =   array("", "",  "Open");
	static $__desc_state_v_fixed =   array("", "",  "fixed");
	static $__desc_state_v_analyzed =   array("", "",  "analyzed");
	static $__desc_state_v_closed =   array("", "",  "closed");
	static $__desc_ddate = array("", "",  "date");
	static $__desc_made_by = array("S", "",  "changed_by");
	static $__desc_text_reason = array("StW", "",  "text_reason");

	static $__desc_subject_f = array("St", "",  "subject");
	static $__acdesc_add = array("", "",  "reply");

	static function searchVar()
	{
		return "text_reason";
	}

	static function ticketSendNotification($from, $id, $category, $object, $action, $actxt, $made_by, $subject, $message, $extra = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_object($object) || !is_object($login) || !method_exists($login, 'getObject')) {
			return;
		}

		try {
			$o = $login->getObject('ticketconfig');
		} catch (Throwable $e) {
			return;
		}

		$name = is_object($sgbl) && isset($sgbl->__var_program_name)
			? ucfirst((string)$sgbl->__var_program_name) : 'Kloxo';

		try {
			$notf = $object->getObject('notification');
		} catch (Throwable $e) {
			return;
		}

		$val = null;
		$flag = $action . "_flag";

		if (!$object->contactemail) {
			return;
		}
		$flagOn = false;
		try {
			$flagOn = is_object($notf) && isset($notf->notflag_b)
				&& is_object($notf->notflag_b) && method_exists($notf->notflag_b, 'isOn')
				&& $notf->notflag_b->isOn($flag);
		} catch (Throwable $e) {
			$flagOn = false;
		}
		if (!$flagOn) {
			return;
		}

		log_message("Sending Notification $subject to $object->nname $object->contactemail \n");
		$subject = "[ticket:$object->nname:$id] $subject";
		
		list($parentclass, $parentname) = getParentNameAndClass($made_by);
		
		$mail = null;

		if (is_object($o) && method_exists($o, 'isOn') && $o->isOn('mail_enable')) {
			$mail  .= "When replying please leave the subject intact for the helpdesk to parse...\n-----\n";
		}
		
		$mail .= "A ticket {$actxt} by {$parentclass}:{$parentname} at the {$name} ticketing system\n";
		$mail .= "Message:\n";
		$mail .= "--------------------------\n";
		$mail .= "". $message . "\n";
		$mail .= "--------------------------\n";

		$reply_to = (is_object($o) && isset($o->mail_account)) ? $o->mail_account : '';

		if (!$reply_to) {
			$reply_to = "helpdesk";
		}

		$extra .= "Reply-To: {$reply_to}\n";
		$extra .= "X-Category: {$category}\n";

		$contactemail = $object->getAllContactEmail();

	//	lx_mail($from, $contactemail, $subject, $mail, $extra);
		callInBackground('lx_mail', array($from, $contactemail, $subject, $mail, $extra));
	}

	static function createListAlist($parent, $class)
	{
		return null;
	}

	static function createAddformList($parent, $class) { return true; }

	function isSync() { return false; }

	static function createListNlist($parent, $view)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$nlist['made_by'] = '10%';
		$nlist['text_reason'] = '100%';
		$nlist['ddate'] = '3%';
		$nlist['state'] = '3%';
		
		return $nlist;
	}

	function display($var)
	{
		if ($var === 'made_by') {
			if (!isset($this->made_by) || $this->made_by === null || $this->made_by === '') {
				return '';
			}
			try {
				return $this->getParentName('made_by');
			} catch (Throwable $e) {
				return (string)$this->made_by;
			}
		}

		return parent::display($var);
	}

	static function defaultSort(){ return 'ddate'; }

	static function defaultSortDir() {return "desc"; }

	static function escalateSend($parent, $param, $fullmess)
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		$action = 'escalate';
		$obj = new client(null, null, 'admin');
		$obj->get();

		$extra = null;

		$from = "helpdesk";

	/*
		if ($sgbl->__var_program_name === 'lxlabsclient') {
			$ip = "lxlabs.com";

			if ($parent->isOn('escalate')) {
				$extra .= "X-escalate: Escalated\n";
			}
		} else {
			$ip = getFQDNforServer('localhost');
		}
	*/

		if ($parent->isOn('escalate')) {
			$extra .= "X-escalate: Escalated\n";
		}

		$ip = getFQDNforServer('localhost');

		$pass = $parent->realpass;
		$ticktid = $parent->nname;

		$extram = base64_encode(serialize(array('ticket_c' => $obj->getClName())));
		$urllink = "Click here to login to the Ticket: http://$ip:{$sgbl->__var_prog_port}/lib/php/?frm_clientname=$ticktid&frm_class=ticket&frm_password=$pass";

		$actxt = "has been escalated";
		$extra = "Message-ID: $parent->mail_messageid\n";

		$message = $fullmess;

		ticketHistory::ticketSendNotification($from, $parent->nname, $category, $obj, $action, $actxt, $param['made_by'], $parent->subject, $message, $extra);
	}

	static function getObjectsTosend($parent, $param, $action)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_object($parent) || !is_array($param)) {
			return;
		}
		if (class_exists('Ticket') && method_exists('Ticket', 'canReadPanelDb')
			&& !Ticket::canReadPanelDb()) {
			return;
		}

		try {
			self::getObjectsTosendDo($parent, $param, $action);
		} catch (Throwable $e) {
			log_log("error", "ticket getObjectsTosend: " . $e->getMessage());
		}
	}

	static function getObjectsTosendDo($parent, $param, $action)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$pclass = $parent->getParentClass('made_by');
		$pname = $parent->getParentName('made_by');
		if (!is_string($pclass) || $pclass === '' || !class_exists($pclass)) {
			return;
		}
		$obj = new $pclass($parent->__masterserver, null, $pname);
		$obj->get();
		$extra = null;

		if (isset($param['from_ad'])) {
			$from = $param['from_ad'];
		} else if (isset($param['pobject'])) {
			$from = $param['pobject']->contactemail;
		} else if ($obj->contactemail) {
			$from = "$obj->nname <$obj->contactemail>";
		} else {
			$from = "helpdesk";
		}

		$subject = $parent->subject;

		if ($parent->isOn('escalate')) {
			$extra .= "X-escalate: Escalated\n";
		}

		$ip = getFQDNforServer('localhost');

		if ($obj->isClient() && !$obj->isAdmin()) {
			$sq = new Sqlite(null, "ticket");
			$tlist = $sq->getRowsWhere("made_by = 'client-$obj->nname' AND category LIKE '%TechnicalSupport%'");
			$nticket = is_array($tlist) ? count($tlist) : 0;
		}

		$pass = $parent->realpass;
		$ticktid = $parent->nname;
		$category = $parent->category;

		$extram = base64_encode(serialize(array('ticket_c' => $obj->getClName())));
		$urllink = "Click here to login to the Ticket: http://$ip:{$sgbl->__var_prog_port}/lib/php/?frm_clientname=$ticktid&frm_class=ticket&frm_password=$pass";

		$otherclass = $parent->getParentClass('sent_to');
		$othername = $parent->getParentName('sent_to');

		if (!$otherclass || !is_string($otherclass) || !class_exists($otherclass)) {
			return;
		}

		$otherobj = new $otherclass($parent->__masterserver, null, $othername);
		$otherobj->get();

		$extras =  base64_encode(serialize(array('ticket_c' => $otherobj->getClName())));

		if ($action === 'ticketadd') {
			$actxt = "has been opened";
			$extra .= "Message-ID: $parent->mail_messageid\n";
		} else {
			$actxt = "state has been changed from '$parent->state' to '{$param['state']}'";
			$extra .= "In-Reply-To: $parent->mail_messageid\n";
		}

		$message = "{$param['text_reason']}\n";

		ticketHistory::ticketSendNotification($from, $parent->nname, $category, $obj, $action, $actxt, $param['made_by'], $subject, $message, $extra);

		$message = $param['text_reason'];

		ticketHistory::ticketSendNotification($from, $parent->nname, $category, $otherobj, $action, $actxt, $param['made_by'], $subject, $message, $extra);
	}

	static function add($parent, $class, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_array($param)) {
			$param = array();
		}

		$parent->history_num = (int)($parent->history_num ?? 0) + 1;
		$param['nname'] = $parent->nname . "___" . $parent->history_num;

		if (!isset($param['made_by']) || $param['made_by'] === '' || $param['made_by'] === null) {
			$made = '';
			if (is_object($parent) && method_exists($parent, 'isLogin') && $parent->isLogin()) {
				$ret = is_object($gbl) && method_exists($gbl, 'getSessionV')
					? $gbl->getSessionV('extra_var') : null;
				if (is_array($ret) && isset($ret['ticket_c'])) {
					$made = (string)$ret['ticket_c'];
				}
			}
			if ($made === '' && is_object($parent) && method_exists($parent, 'getParentO')) {
				try {
					$gp = $parent->getParentO();
					if (is_object($gp) && method_exists($gp, 'getClName')) {
						$made = $gp->getClName();
					}
				} catch (Throwable $e) {
				}
			}
			if ($made === '' && is_object($login) && method_exists($login, 'getClName')) {
				$made = $login->getClName();
			}
			$param['made_by'] = $made !== '' ? $made : 'client-admin';
		}

		if (!isset($param['state']) || $param['state'] === '' || $param['state'] === null) {
			$param['state'] = (is_object($parent) && isset($parent->state) && $parent->state !== '')
				? (string)$parent->state : 'open';
		}
		if (!isset($param['text_reason'])) {
			$param['text_reason'] = '';
		}

		$parent->dbaction = 'update';

		try {
			self::getObjectsTosend($parent, $param, "ticketchange");
		} catch (Throwable $e) {
			log_log("error", "ticket history notify: " . $e->getMessage());
		}

		$parent->state = $param['state'];
		$parent->date_modified = time();
		$parent->setUpdateSubaction();

		return $param;
	}

	static function addform($parent, $class, $typetd = null)
	{
		$slist = Ticket::getStateList();
		$cur = (is_object($parent) && isset($parent->state)) ? (string)$parent->state : '';
		if ($cur !== '' && isset($slist[$cur])) {
			unset($slist[$cur]);
		}
		if ($slist === array()) {
			$slist = Ticket::getStateList();
		}
		$vlist['state'] = array('s', $slist);
		$vlist['text_reason'] = null;

		$ret['variable'] = $vlist;
		$ret['action'] = 'add';

		return $ret;
	}
}
