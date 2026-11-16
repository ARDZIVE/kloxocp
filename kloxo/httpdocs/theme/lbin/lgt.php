<?php
/**
 * Legacy license update via web — disabled (security N27).
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled\n");
exit;
