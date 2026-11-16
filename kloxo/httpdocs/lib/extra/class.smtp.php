<?php
/**
 * PHPMailer SMTP transport stub (security MM3; security W7; security JJ5).
 *
 * The full EOL SMTP class (HMAC-MD5 AUTH helpers, etc.) was removed with
 * PHPMailer 5.2.19. Panel mail uses lx_mail() → local sendmail/Exim.
 */
declare(strict_types=1);

class SMTP
{
	public function __construct()
	{
		throw new RuntimeException(
			'Bundled SMTP/PHPMailer was removed (security MM3). Use lx_mail() / local sendmail.'
		);
	}
}
