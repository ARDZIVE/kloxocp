<?php
/**
 * Legacy self-update HTTP endpoint — disabled (security N10).
 * Use `kloxocp upcp` / panel Update with a logged-in session instead.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: use kloxocp upcp\n");
exit;
