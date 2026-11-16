<?php
/**
 * Legacy PayPal IPN — disabled (security N20). Billing is not shipped.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: billing not enabled\n");
exit;
