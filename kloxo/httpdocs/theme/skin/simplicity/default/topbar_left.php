<?php
			$mess_url = "/display.php?frm_action=list&frm_o_cname=smessage";

			$loginas = $login->nname;
			// Prefer clname; quote via helpers (security WW1).
			$cl = (is_object($login) && method_exists($login, 'getClName'))
				? (string) $login->getClName()
				: ('client-' . (string) $loginas);
			$msgRecvWhere = function_exists('kloxo_sql_like')
				? kloxo_sql_like('text_sent_to_cmlist', $cl, 'csv')
				: ("text_sent_to_cmlist LIKE '%," . str_replace("'", "''", $cl) . ",%'");
			$msgReadWhere = function_exists('kloxo_sql_like')
				? kloxo_sql_like('text_readby_cmlist', $cl, 'csv')
				: ("text_readby_cmlist LIKE '%," . str_replace("'", "''", $cl) . ",%'");
			$ticketWhere = function_exists('kloxo_sql_eq')
				? kloxo_sql_eq('sent_to', $cl)
				: ("sent_to = '" . str_replace("'", "''", $cl) . "'");
			$ticketOpenWhere = function_exists('kloxo_sql_and_eq')
				? kloxo_sql_and_eq(array('sent_to' => $cl, 'state' => 'open'))
				: ($ticketWhere . " AND state = 'open'");

			$total_message_received = db_get_count("smessage", $msgRecvWhere);
			$total_message_readby = db_get_count("smessage", $msgReadWhere);
			$total_message_unreadby = $total_message_received - $total_message_readby;

			$message_text = $login->getKeywordUc('message') . ": {$total_message_unreadby}/{$total_message_received}";

			$total_ticket_received = db_get_count("ticket", $ticketWhere);
			$total_ticket_open = db_get_count("ticket", $ticketOpenWhere);

			$ticket_text = $login->getKeywordUc('ticket') . ": {$total_ticket_open}/{$total_ticket_received}";
			$ticket_unread = 0;
			if (is_object($login) && method_exists($login, 'checkTicketUnread')) {
				try {
					$ticket_unread = (int) $login->checkTicketUnread();
				} catch (Throwable $e) {
					$ticket_unread = 0;
				}
			}
			$ticket_unread_class = ($ticket_unread > 0) ? ' kloxo-has-unread' : '';
			$ticket_unread_dot = ($ticket_unread > 0)
				? '<span class="kloxo-unread-dot" aria-hidden="true"></span>'
				: '';
			$ticket_title = $login->getKeywordUc('ticket_title');
			if ($ticket_unread > 0) {
				$ticket_title .= ' (' . $ticket_unread . ' unread)';
			}
			$message_text_esc = htmlspecialchars((string) $message_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$ticket_text_esc = htmlspecialchars((string) $ticket_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

				<div class="div_fixed_left">
						<a href="<?="/display.php?frm_action=list&frm_o_cname=smessage";?>">
							<div id="div_smessage" class="div_header_message" onMouseOver="this.style.backgroundColor='#fff'; this.style.color='#000';" onMouseOut="this.style.backgroundColor='#3498db'; this.style.color='#fff';" title="<?= htmlspecialchars((string) $login->getKeywordUc('message_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">&nbsp;<?=$message_text_esc;?>&nbsp;</div>
						</a>
						<a href="<?="/display.php?frm_action=list&frm_o_cname=ticket";?>">
							<div id="div_ticket" class="div_header_message<?= $ticket_unread_class ?>" onMouseOver="this.style.backgroundColor='#fff'; this.style.color='#000';" onMouseOut="this.style.backgroundColor='#3498db'; this.style.color='#fff';" title="<?= htmlspecialchars((string) $ticket_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&nbsp;<?=$ticket_text_esc;?>&nbsp;<?= $ticket_unread_dot ?></div>
						</a>
				</div>
