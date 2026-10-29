<?php
/**
 * The in-browser command shell was removed (audit H3).
 * This stub does not parse session input and does not run commands.
 */
header('HTTP/1.1 403 Forbidden');
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo "The in-browser web console was removed from Kloxo CP.\n";
exit;
