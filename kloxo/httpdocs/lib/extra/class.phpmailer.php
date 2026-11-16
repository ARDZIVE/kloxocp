<?php
/**
 * PHPMailer stub (security W7).
 *
 * The bundled PHPMailer 5.2.19 was removed: it is EOL and unused. Panel mail
 * goes through lx_mail() → local sendmail/Exim (argument-array proc_open).
 * Instantiating PHPMailer is refused so stale includes cannot send mail via
 * the vulnerable class.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

class PHPMailer
{
	public function __construct($exceptions = null)
	{
		throw new RuntimeException(
			'Bundled PHPMailer was removed (security W7). Use lx_mail() / local sendmail.'
		);
	}
}
