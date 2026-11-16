<?php
/**
 * Legacy public signup removed (security F5).
 * Client creation stays in the authenticated panel UI.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: unauthenticated signup removed\n");
exit;
