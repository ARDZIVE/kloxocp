<?php
declare(strict_types=1);

/**
 * Kloxo CP recovery console. Does not use the panel database, Nginx or Apache:
 * it is served by the panel's own Hiawatha + PHP-FPM, and logs in with the
 * recovery password (etc/conf/recovery.json). See lib/php/kloxo_recovery.php.
 */

ini_set('display_errors', '0');

$kloxoRecoveryHttpdocs = dirname(__DIR__);
require_once $kloxoRecoveryHttpdocs . '/lib/php/kloxo_recovery_page.php';

$kloxoRecoveryDir = KloxoRecovery::stateDir();
if (!is_dir($kloxoRecoveryDir)) {
    @mkdir($kloxoRecoveryDir, 0700, true);
}

session_name('kloxo_recovery');
if (is_dir($kloxoRecoveryDir) && is_writable($kloxoRecoveryDir)) {
    session_save_path($kloxoRecoveryDir);
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/recovery',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Root-only actions (start/stop/restart, logs) go through kloxo-backend with the
// legacy RPC client. It must be included at global scope, and only for a
// request that already carries a logged-in session, so the login page and the
// state list never depend on the legacy code.
$kloxoRecoveryNeedsRoot = !empty($_SESSION['auth'])
    && (isset($_GET['journal']) || (($_POST['do'] ?? '') === 'control'));
if ($kloxoRecoveryNeedsRoot && !function_exists('rl_exec_get')) {
    $kloxoRecoveryCwd = getcwd();
    chdir($kloxoRecoveryHttpdocs);
    ob_start();
    try {
        include_once $kloxoRecoveryHttpdocs . '/lib/html/include.php';
        if (!isset($login) || !is_object($login)) {
            $login = KloxoRecovery::backendLoginStub();
        }
    } catch (Throwable $e) {
        // viaBackend() reports "no answer" when the client is missing
    }
    ob_end_clean();
    if ($kloxoRecoveryCwd !== false) {
        chdir($kloxoRecoveryCwd);
    }
}

$kloxoRecoverySession = $_SESSION;
$kloxoRecoveryRes = KloxoRecoveryPage::handle(
    $_SERVER,
    $_GET,
    $_POST,
    $kloxoRecoverySession,
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    time()
);

if ($kloxoRecoveryRes['destroy']) {
    $_SESSION = [];
    session_destroy();
} else {
    if ($kloxoRecoveryRes['regenerate']) {
        session_regenerate_id(true);
    }
    $_SESSION = $kloxoRecoverySession;
}

http_response_code($kloxoRecoveryRes['status']);
foreach ($kloxoRecoveryRes['headers'] as $kloxoRecoveryHeader) {
    header($kloxoRecoveryHeader);
}
echo $kloxoRecoveryRes['body'];
