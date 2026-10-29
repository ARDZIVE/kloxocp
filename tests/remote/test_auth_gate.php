#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Regression tests for audit C1: no unserialize of network input before auth.
 * Standalone: includes only remote_auth_gate.php, not the legacy panel.
 */

$root = dirname(__DIR__, 2);
$gate = $root . '/kloxo/httpdocs/lib/html/remote_auth_gate.php';
require $gate;

const REMOTECHAR = '_._';
$pass = 0;
$fail = 0;

function ok(string $d): void
{
    global $pass;
    $pass++;
    printf("  ok   %s\n", $d);
}

function bad(string $d, string $detail = ''): void
{
    global $fail;
    $fail++;
    printf("  FAIL %s\n", $d);
    if ($detail !== '') {
        printf("       %s\n", $detail);
    }
}

class C1Probe
{
    public static bool $hit = false;

    public function __wakeup(): void
    {
        self::$hit = true;
    }

    public function __unserialize(array $data): void
    {
        self::$hit = true;
    }
}

function gadget_b64(): string
{
    C1Probe::$hit = false;
    return base64_encode(serialize(new C1Probe()));
}

function envelope(string $user, string $pass, string $payload_b64): string
{
    return REMOTECHAR . "\n" . base64_encode($user) . "\n" . base64_encode($pass) . "\n" . $payload_b64;
}

echo "== envelope parse (no unserialize)\n";

C1Probe::$hit = false;
$env = kloxo_remote_parse_envelope(gadget_b64(), REMOTECHAR);
if (empty($env['ok']) && $env['reason'] === 'authentication_required' && C1Probe::$hit === false) {
    ok("bare serialized gadget is rejected without wakeup");
} else {
    bad("bare serialized gadget is rejected without wakeup", json_encode($env) . ' hit=' . (int)C1Probe::$hit);
}

C1Probe::$hit = false;
$env = kloxo_remote_parse_envelope(base64_encode(serialize(new C1Probe())), REMOTECHAR);
if (empty($env['ok']) && C1Probe::$hit === false) {
    ok("bare base64 gadget is rejected without wakeup");
} else {
    bad("bare base64 gadget is rejected without wakeup", 'hit=' . (int)C1Probe::$hit);
}

foreach (['', 'hello', "not-a-marker\nuser\npass\npayload", REMOTECHAR, REMOTECHAR . "\nonlyone", REMOTECHAR . "\n" . base64_encode('u') . "\n"] as $i => $raw) {
    C1Probe::$hit = false;
    $env = kloxo_remote_parse_envelope($raw, REMOTECHAR);
    if (empty($env['ok']) && C1Probe::$hit === false) {
        ok("malformed #$i rejected");
    } else {
        bad("malformed #$i rejected");
    }
}

$env = kloxo_remote_parse_envelope(envelope('slave', 'secret', 'not-empty'), REMOTECHAR);
if (!empty($env['ok']) && $env['user'] === 'slave' && $env['pass'] === 'secret' && $env['payload_b64'] === 'not-empty') {
    ok("well-formed envelope splits without decoding the payload");
} else {
    bad("well-formed envelope splits without decoding the payload", json_encode($env));
}

C1Probe::$hit = false;
$g = gadget_b64();
$env = kloxo_remote_parse_envelope(envelope('slave', 'secret', $g), REMOTECHAR);
if (!empty($env['ok']) && $env['payload_b64'] === $g && C1Probe::$hit === false) {
    ok("gadget in payload field is not unserialized by parse");
} else {
    bad("gadget in payload field is not unserialized by parse", 'hit=' . (int)C1Probe::$hit);
}

$env = kloxo_remote_parse_envelope(envelope('', 'secret', 'x'), REMOTECHAR);
if (empty($env['ok'])) {
    ok("empty username rejected");
} else {
    bad("empty username rejected");
}

$env = kloxo_remote_parse_envelope(envelope('slave', '', 'x'), REMOTECHAR);
if (empty($env['ok'])) {
    ok("empty password rejected");
} else {
    bad("empty password rejected");
}

$env = kloxo_remote_parse_envelope(REMOTECHAR . "\n@@@\n" . base64_encode('p') . "\nx", REMOTECHAR);
if (empty($env['ok'])) {
    ok("invalid base64 username rejected");
} else {
    bad("invalid base64 username rejected");
}

echo "== auth callback (still no unserialize of payload)\n";

$called = [];
$verify = static function (string $u, string $p) use (&$called): bool {
    $called[] = [$u, $p];
    return $u === 'slave' && $p === 'correct-horse';
};

C1Probe::$hit = false;
$called = [];
$env = kloxo_remote_authenticated_payload(gadget_b64(), REMOTECHAR, $verify);
if (empty($env['ok']) && $called === [] && C1Probe::$hit === false) {
    ok("unauthenticated gadget never reaches the password checker");
} else {
    bad("unauthenticated gadget never reaches the password checker");
}

C1Probe::$hit = false;
$g = gadget_b64();
$env = kloxo_remote_authenticated_payload(envelope('slave', 'wrong', $g), REMOTECHAR, $verify);
if (empty($env['ok']) && ($env['reason'] ?? '') === 'remote_authentication_failed' && C1Probe::$hit === false) {
    ok("wrong password does not unserialize the payload");
} else {
    bad("wrong password does not unserialize the payload", 'hit=' . (int)C1Probe::$hit);
}

C1Probe::$hit = false;
$g = gadget_b64();
$env = kloxo_remote_authenticated_payload(envelope('slave', 'correct-horse', $g), REMOTECHAR, $verify);
if (!empty($env['ok']) && $env['payload_b64'] === $g && C1Probe::$hit === false) {
    ok("good password returns payload still serialized");
} else {
    bad("good password returns payload still serialized", 'hit=' . (int)C1Probe::$hit);
}

echo "== process_server_input source invariant\n";

$src = file_get_contents($root . '/kloxo/httpdocs/lib/html/remotelib.php');
if ($src === false) {
    bad("read remotelib.php");
} else {
    if (!preg_match('/function process_server_input\s*\(\s*\$total\s*\)\s*\{(.*?)(?=\nfunction )/s', $src, $m)) {
        bad("found process_server_input in remotelib.php");
    } else {
        $body = $m[1];
        if (str_contains($body, 'kloxo_remote_parse_envelope') || str_contains($body, 'kloxo_remote_authenticated_payload')) {
            ok("process_server_input uses the auth gate");
        } else {
            bad("process_server_input uses the auth gate");
        }
        if (preg_match('/unserialize\s*\(\s*base64_decode\s*\(\s*\$total\s*\)/', $body)) {
            bad("process_server_input does not unserialize \$total");
        } else {
            ok("process_server_input does not unserialize \$total");
        }
        $unserializePos = strpos($body, 'unserialize');
        $verifyPos = strpos($body, 'kloxo_remote_verify_network_pass');
        $authPos = strpos($body, 'kloxo_remote_authenticated_payload');
        $parsePos = strpos($body, 'kloxo_remote_parse_envelope');
        $gatePos = false;
        foreach ([$verifyPos, $authPos, $parsePos] as $p) {
            if ($p !== false && ($gatePos === false || $p < $gatePos)) {
                $gatePos = $p;
            }
        }
        if ($unserializePos === false) {
            bad("process_server_input still unserializes payload after auth");
        } elseif ($gatePos === false || $gatePos > $unserializePos) {
            bad("unserialize in process_server_input is after the auth gate", "gate=$gatePos unserialize=$unserializePos");
        } else {
            ok("unserialize in process_server_input is after the auth gate");
        }
    }

    if (!str_contains($src, 'include_once') || !str_contains($src, 'remote_auth_gate.php')) {
        bad("remotelib.php includes remote_auth_gate.php");
    } else {
        ok("remotelib.php includes remote_auth_gate.php");
    }

    // Localhost must send the same envelope; the old skip is the C1 hole.
    if (!preg_match('/function do_remote_exec\s*\(.*?\nfunction /s', $src, $dm)) {
        bad("found do_remote_exec");
    } else {
        $db = $dm[0];
        if (preg_match('/if\s*\(\s*!isLocalhost\s*\(\s*\$rmt->machine\s*\)\s*\)/', $db)) {
            bad("do_remote_exec always attaches the credential envelope");
        } else {
            ok("do_remote_exec always attaches the credential envelope");
        }
        if (str_contains($db, '$remotechar') && str_contains($db, 'base64_encode($rmt->password)')) {
            ok("do_remote_exec puts user/password on the envelope");
        } else {
            bad("do_remote_exec puts user/password on the envelope");
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
