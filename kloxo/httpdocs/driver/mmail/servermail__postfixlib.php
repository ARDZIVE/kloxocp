<?php
declare(strict_types=1);

/**
 * Mail Server Settings for Postfix: the SMTP protection (the Spamdyke replacement)
 * is rendered by `kloxo-mail policy:apply --mta=postfix` (see lib/php/kloxo_mailpolicy.php).
 * Selected by Switch Program > SMTP (pg_servermail resolves to pg_smtp).
 */
class Servermail__Postfix extends lxDriverClass
{
	function dbactionAdd()
	{
	}

	function dbactionDelete()
	{
	}

	function dbactionUpdate($subaction)
	{
		if (in_array($subaction, KloxoMailPolicy::SUBACTIONS, true)) {
			KloxoMailPolicy::applyOrThrow($this->main, 'postfix');
		}
	}
}
