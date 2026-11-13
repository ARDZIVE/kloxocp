<?php
declare(strict_types=1);

/**
 * Switch Program spam filter: Rspamd (Exim/Postfix talk to it on 11333).
 * Enable/disable only through Switch Program — not Services or per-mailbox UI.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */
class Spam__rspamd extends lxDriverClass
{
	static function installMe()
	{
		// Redis is the Bayes backend; install.sh already enables it on Ubuntu.
		KloxoApt::installOrThrow(array('rspamd'));
		// Stop any leftover SpamAssassin from older Switch Program choices.
		if (class_exists('KloxoMta')) {
			try {
				KloxoMta::turnOff(array('spamd', 'spamassassin'), false);
			} catch (Throwable $e) {
				// best-effort; Rspamd still starts
			}
		}
		lxshell_return('systemctl', 'enable', '--now', 'rspamd');
	}

	static function uninstallMe()
	{
		// Keep the package; stop and take it off boot (Services cannot do this).
		KloxoMta::turnOff(array('rspamd'));
	}
}
