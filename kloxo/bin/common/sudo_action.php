<?php
/**
 * Legacy sudo helper — disabled (security N4).
 * callWithSudo is only referenced from commented code in do_local_action.
 * Unrestricted unserialize + call_user_func_array is refused.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

fwrite(STDERR, "sudo_action: disabled\n");
exit(1);
