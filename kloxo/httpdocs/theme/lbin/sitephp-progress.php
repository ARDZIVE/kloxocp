<?php
declare(strict_types=1);

/**
 * Admin-only live log for Multiple PHP Install / Remove on Webserver Configure.
 * Read-only JSON. The apt job itself runs as root via kloxo-backend.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

chdir('../../');
include_once 'lib/html/displayinclude.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    initProgram();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
    exit;
}

global $login;
if (!is_object($login) || !method_exists($login, 'isAdmin') || !$login->isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(KloxoPhpver::liveProgress(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
