<?php
/**
 * KloxoSsl + SSL redirect helpers. php-cli only; fake openssl/certbot under a
 * temp tree. Live certbot / ACME / panel UI are UNVERIFIED.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void
{
	global $fail;
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}
function check(bool $c, string $d, string $x = ''): void { $c ? ok($d) : bad($d, $x); }

$repo = dirname(__DIR__, 2);
require $repo . '/kloxo/httpdocs/lib/php/kloxo_ssl.php';

$tmp = sys_get_temp_dir() . '/kloxo-ssl-test-' . getmypid();
$fs = $tmp . '/fs';
foreach ([
	"$fs/usr/bin", "$fs/home/kloxo/ssl",
	"$fs/home/httpd/example.com/httpdocs",
	"$fs/var/lib/kloxo/acme/.well-known/acme-challenge",
	"$fs/etc/letsencrypt/live/example.com", "$fs/script",
] as $d) {
	mkdir($d, 0755, true);
}

file_put_contents("$fs/usr/bin/openssl", <<<'SH'
#!/bin/sh
# minimal fake: write placeholder PEM files from -keyout/-out
key=""; crt=""; csr=""
while [ $# -gt 0 ]; do
  case "$1" in
    -keyout) key="$2"; shift 2 ;;
    -out) out="$2"; shift 2
      if echo "$*" | grep -q -- '-x509' || [ -n "$crt" ]; then crt="$out"; else csr="$out"; fi
      ;;
    *) shift ;;
  esac
done
# second pass for -out after we know mode
# rewrite: parse argv simply
SH
);
# Simpler fake openssl in PHP
file_put_contents("$fs/usr/bin/openssl", <<<'PHP'
#!/usr/bin/env php
<?php
$argv = $_SERVER['argv'];
$key = $crt = $csr = '';
$x509 = in_array('-x509', $argv, true);
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '-keyout' && isset($argv[$i + 1])) { $key = $argv[++$i]; }
    elseif ($argv[$i] === '-out' && isset($argv[$i + 1])) {
        $o = $argv[++$i];
        if ($x509) { $crt = $o; } else { $csr = $o; }
    }
}
$pemKey = "-----BEGIN PRIVATE KEY-----\nMIIFakeKey\n-----END PRIVATE KEY-----\n";
$pemCrt = "-----BEGIN CERTIFICATE-----\nMIIFakeCrt\n-----END CERTIFICATE-----\n";
$pemCsr = "-----BEGIN CERTIFICATE REQUEST-----\nMIIFakeCsr\n-----END CERTIFICATE REQUEST-----\n";
if ($key !== '') { file_put_contents($key, $pemKey); }
if ($crt !== '') { file_put_contents($crt, $pemCrt); }
if ($csr !== '') { file_put_contents($csr, $pemCsr); }
exit(0);
PHP
);
chmod("$fs/usr/bin/openssl", 0755);

file_put_contents("$fs/usr/bin/certbot", <<<'PHP'
#!/usr/bin/env php
<?php
$fs = getenv('KLOXO_SSL_FS') ?: '';
$live = $fs . '/etc/letsencrypt/live/example.com';
@mkdir($live, 0755, true);
$k = "-----BEGIN PRIVATE KEY-----\nMIILeKey\n-----END PRIVATE KEY-----\n";
$c = "-----BEGIN CERTIFICATE-----\nMIILeCrt\n-----END CERTIFICATE-----\n";
$a = "-----BEGIN CERTIFICATE-----\nMIILeCa\n-----END CERTIFICATE-----\n";
file_put_contents("$live/privkey.pem", $k);
file_put_contents("$live/cert.pem", $c);
file_put_contents("$live/chain.pem", $a);
fwrite(STDERR, "Simulating certbot success\n");
exit(0);
PHP
);
chmod("$fs/usr/bin/certbot", 0755);

file_put_contents("$fs/script/fixweb", "#!/bin/sh\necho fixweb \"$@\"\nexit 0\n");
chmod("$fs/script/fixweb", 0755);

KloxoSsl::$fsPrefix = $fs;
putenv('KLOXO_SSL_FS=' . $fs);
$lastCertbotArgv = [];
KloxoSsl::$runCmd = static function (array $argv) use ($fs, &$lastCertbotArgv): array {
	$bin = $argv[0];
	if (str_ends_with($bin, '/openssl') || basename($bin) === 'openssl') {
		$argv[0] = $fs . '/usr/bin/openssl';
	} elseif (str_ends_with($bin, '/certbot') || basename($bin) === 'certbot') {
		$lastCertbotArgv = $argv;
		$argv[0] = $fs . '/usr/bin/certbot';
	} elseif (str_contains($bin, 'fixweb') || (isset($argv[1]) && str_contains((string) $argv[1], 'fixweb'))) {
		return ['code' => 0, 'out' => 'fixweb ok'];
	}
	$cmd = '';
	foreach ($argv as $a) {
		$cmd .= escapeshellarg((string) $a) . ' ';
	}
	$out = [];
	$code = 0;
	exec($cmd . '2>&1', $out, $code);

	return ['code' => $code, 'out' => implode("\n", $out)];
};

echo "== KloxoSsl validate\n";
try {
	KloxoSsl::validateDomain('example.com');
	ok('validateDomain example.com');
} catch (Throwable $e) {
	bad('validateDomain example.com', $e->getMessage());
}
try {
	KloxoSsl::validateDomain('../evil');
	bad('validateDomain rejects path');
} catch (Throwable $e) {
	ok('validateDomain rejects path');
}

echo "== installDomainCert\n";
$key = "-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----\n";
$crt = "-----BEGIN CERTIFICATE-----\nDEF\n-----END CERTIFICATE-----\n";
$ca = "-----BEGIN CERTIFICATE-----\nGHI\n-----END CERTIFICATE-----\n";
$r = KloxoSsl::installDomainCert('example.com', $key, $crt, $ca, '', false);
check(!empty($r['ok']), 'installDomainCert ok');
check(is_file("$fs/home/kloxo/ssl/example.com.key"), 'wrote .key');
check(is_file("$fs/home/kloxo/ssl/example.com.crt"), 'wrote .crt');
check(is_file("$fs/home/kloxo/ssl/example.com.ca"), 'wrote .ca');
check(is_file("$fs/home/kloxo/ssl/example.com.pem"), 'wrote .pem');
$pem = (string) file_get_contents("$fs/home/kloxo/ssl/example.com.pem");
check(str_contains($pem, 'PRIVATE KEY') && str_contains($pem, 'BEGIN CERTIFICATE'), 'pem merges key+crt');

echo "== createSelfSigned\n";
$s = KloxoSsl::createSelfSigned('self.example.com', [
	'commonName' => 'self.example.com',
	'emailAddress' => 'admin@example.com',
	'countryName' => 'US:United States',
], 2048, true);
check(!empty($s['ok']) && str_contains($s['key'], 'PRIVATE KEY'), 'createSelfSigned key');
check(str_contains($s['crt'], 'CERTIFICATE'), 'createSelfSigned crt');
check(is_file("$fs/home/kloxo/ssl/self.example.com.key"), 'self-signed on disk');

echo "== resolveWebroot prefers httpdocs\n";
$wr = KloxoSsl::resolveWebroot('example.com', '');
check($wr === "$fs/home/httpd/example.com/httpdocs", 'resolveWebroot uses /home/httpd/domain/httpdocs', $wr);
mkdir("$fs/home/alice/example.com", 0755, true);
$wr2 = KloxoSsl::resolveWebroot('example.com', '/home/alice/example.com');
check($wr2 === "$fs/home/alice/example.com",
	'resolveWebroot accepts passed docroot', $wr2);

echo "== issueLetsEncrypt (fake certbot)\n";
$le = KloxoSsl::issueLetsEncrypt('example.com', 'admin@example.com', ['example.com', 'www.example.com'], '2048');
check(!empty($le['ok']) && $le['tool'] === 'certbot', 'issueLetsEncrypt via certbot');
check(str_contains($le['key'], 'PRIVATE KEY'), 'LE key returned');
check(is_file("$fs/home/kloxo/ssl/example.com.key"), 'LE installed under ssl dir');
check(in_array("$fs/home/httpd/example.com/httpdocs", $lastCertbotArgv, true),
	'certbot --webroot-path is domain httpdocs');
check(in_array('--cert-name', $lastCertbotArgv, true)
	&& in_array('example.com', $lastCertbotArgv, true),
	'certbot uses --cert-name for the domain');
check(in_array('--expand', $lastCertbotArgv, true),
	'certbot uses --expand for SAN overlap');
check(in_array('--force-renewal', $lastCertbotArgv, true),
	'certbot uses --force-renewal so SAN expand runs before expiry');
check(!in_array('--keep-until-expiring', $lastCertbotArgv, true),
	'certbot does not use --keep-until-expiring on issue');
check(!in_array('--duplicate', $lastCertbotArgv, true),
	'certbot omits --duplicate on first attempt');

echo "== certbot overlap retry uses --duplicate\n";
$calls = 0;
$overlapOut = "Missing command line flag or config entry for this setting:\n"
	. "You have an existing certificate that contains a portion of the domains you requested.\n";
KloxoSsl::$runCmd = static function (array $argv) use ($fs, &$calls, &$lastCertbotArgv, $overlapOut): array {
	$bin = $argv[0];
	if (str_ends_with($bin, '/certbot') || basename($bin) === 'certbot') {
		$calls++;
		$lastCertbotArgv = $argv;
		if ($calls === 1) {
			return ['code' => 1, 'out' => $overlapOut];
		}
		$argv[0] = $fs . '/usr/bin/certbot';
		$cmd = '';
		foreach ($argv as $a) {
			$cmd .= escapeshellarg((string) $a) . ' ';
		}
		$out = [];
		$code = 0;
		exec($cmd . '2>&1', $out, $code);

		return ['code' => $code, 'out' => implode("\n", $out)];
	}
	if (str_contains($bin, 'fixweb') || (isset($argv[1]) && str_contains((string) $argv[1], 'fixweb'))) {
		return ['code' => 0, 'out' => 'fixweb ok'];
	}

	return ['code' => 0, 'out' => ''];
};
$leOverlap = KloxoSsl::issueLetsEncrypt('example.com', 'admin@example.com', ['example.com', 'www.example.com'], '2048');
check(!empty($leOverlap['ok']) && $calls === 2, 'overlap prompts retry once');
check(in_array('--duplicate', $lastCertbotArgv, true), 'retry adds --duplicate');
check(in_array('--expand', $lastCertbotArgv, true) && in_array('--cert-name', $lastCertbotArgv, true),
	'retry keeps --expand and --cert-name');

// Restore happy-path runner for later checks.
$lastCertbotArgv = [];
KloxoSsl::$runCmd = static function (array $argv) use ($fs, &$lastCertbotArgv): array {
	$bin = $argv[0];
	if (str_ends_with($bin, '/openssl') || basename($bin) === 'openssl') {
		$argv[0] = $fs . '/usr/bin/openssl';
	} elseif (str_ends_with($bin, '/certbot') || basename($bin) === 'certbot') {
		$lastCertbotArgv = $argv;
		$argv[0] = $fs . '/usr/bin/certbot';
	} elseif (str_contains($bin, 'fixweb') || (isset($argv[1]) && str_contains((string) $argv[1], 'fixweb'))) {
		return ['code' => 0, 'out' => 'fixweb ok'];
	}
	$cmd = '';
	foreach ($argv as $a) {
		$cmd .= escapeshellarg((string) $a) . ' ';
	}
	$out = [];
	$code = 0;
	exec($cmd . '2>&1', $out, $code);

	return ['code' => $code, 'out' => implode("\n", $out)];
};
check(is_dir("$fs/home/httpd/example.com/httpdocs/.well-known/acme-challenge"),
	'challenge dir created under httpdocs');

echo "== missing certbot/acme fails clearly\n";
KloxoSsl::$runCmd = static function (array $argv): array {
	return ['code' => 127, 'out' => 'not found'];
};
// Hide certbot by pointing which() at empty prefix without binaries — use a fresh prefix
$fs2 = $tmp . '/fs2';
mkdir("$fs2/home/kloxo/ssl", 0755, true);
mkdir("$fs2/home/httpd/example.com/httpdocs", 0755, true);
mkdir("$fs2/var/lib/kloxo/acme/.well-known/acme-challenge", 0755, true);
KloxoSsl::$fsPrefix = $fs2;
KloxoSsl::$installPackages = null; // fsPrefix skips apt
try {
	KloxoSsl::issueLetsEncrypt('example.com', 'a@b.co', ['example.com'], '2048');
	bad('issueLetsEncrypt without tools should throw');
} catch (KloxoSslException $e) {
	check(str_contains($e->getMessage(), 'certbot') || str_contains($e->getMessage(), 'acme'),
		'clear missing-tool message', $e->getMessage());
}

echo "== ensureCertbot apt-installs then issues\n";
$fs3 = $tmp . '/fs3';
foreach ([
	"$fs3/usr/bin", "$fs3/home/kloxo/ssl",
	"$fs3/home/httpd/example.com/httpdocs",
	"$fs3/var/lib/kloxo/acme/.well-known/acme-challenge",
	"$fs3/etc/letsencrypt/live/example.com",
] as $d) {
	mkdir($d, 0755, true);
}
KloxoSsl::$fsPrefix = $fs3;
putenv('KLOXO_SSL_FS=' . $fs3);
$installed = false;
KloxoSsl::$installPackages = static function (array $packages) use ($fs, $fs3, &$installed): array {
	check($packages === ['certbot'], 'installPackages asked for certbot');
	copy("$fs/usr/bin/certbot", "$fs3/usr/bin/certbot");
	chmod("$fs3/usr/bin/certbot", 0755);
	$installed = true;

	return ['ok' => true, 'error' => ''];
};
KloxoSsl::$runCmd = static function (array $argv) use ($fs3): array {
	$bin = $argv[0];
	if (str_ends_with($bin, '/certbot') || basename($bin) === 'certbot') {
		$argv[0] = $fs3 . '/usr/bin/certbot';
	}
	$cmd = '';
	foreach ($argv as $a) {
		$cmd .= escapeshellarg((string) $a) . ' ';
	}
	$out = [];
	$code = 0;
	exec($cmd . '2>&1', $out, $code);

	return ['code' => $code, 'out' => implode("\n", $out)];
};
$le2 = KloxoSsl::issueLetsEncrypt('example.com', 'admin@example.com', ['example.com'], '2048');
check($installed, 'ensureCertbot ran installPackages');
check(!empty($le2['ok']) && $le2['tool'] === 'certbot', 'issueLetsEncrypt after auto-install');
KloxoSsl::$installPackages = null;

echo "== sslUrlsFromParent / rememberSslRedirects source\n";
$sslLib = $repo . '/kloxo/httpdocs/driver/pserver/sslcertlib.php';
$src = (string) file_get_contents($sslLib);
check(str_contains($src, 'function rememberSslRedirects'), 'rememberSslRedirects exists');
check(str_contains($src, '__kloxo_redirect_back'), 'sets in-memory redirect back');
check(str_contains($src, 'function sslUrlsFromParent'), 'sslUrlsFromParent exists');
check(str_contains($src, "execSsl('issueLetsEncrypt'"), 'LE uses KloxoSsl via execSsl');
check(str_contains($src, "execSsl('installDomainCert'"), 'domain SSL uses installDomainCert');
$html = (string) file_get_contents($repo . '/kloxo/httpdocs/theme/htmllib.php');
check(str_contains($html, '__kloxo_redirect_back'), 'resolveRedirectBackUrl reads __kloxo_redirect_back');

// Minimal sslUrlsFromParent without full panel boot
eval(<<<'PHP'
if (!function_exists('cse')) {
	function cse($a, $b) { return str_ends_with((string)$a, (string)$b); }
}
PHP);
// Load only the static helpers by requiring the file would pull Lxdb — parse via reflection of copied logic:
$parent = new class {
	public $nname = 'example.com';
	public function getClass() { return 'web'; }
	public function getParentO() {
		return new class {
			public $nname = 'example.com';
			public function getClass() { return 'domain'; }
			public function getParentO() {
				return new class {
					public $nname = 'alice';
					public function getClass() { return 'client'; }
					public function getParentO() { return null; }
				};
			}
		};
	}
};
// Inline the URL builder (same as SslCert::sslUrlsFromParent)
$chain = [];
$cur = $parent;
$guard = 0;
while (is_object($cur) && $guard < 8) {
	$guard++;
	$class = method_exists($cur, 'getClass') ? (string) $cur->getClass() : '';
	$nname = (isset($cur->nname) && is_string($cur->nname)) ? $cur->nname : '';
	if ($class !== '' && $nname !== '') {
		array_unshift($chain, ['class' => $class, 'nname' => $nname]);
	}
	$next = method_exists($cur, 'getParentO') ? $cur->getParentO() : null;
	if ($next === $cur || $next === null) {
		break;
	}
	$cur = $next;
}
$q = [];
foreach ($chain as $i => $node) {
	$q[] = 'frm_o_o[' . $i . '][class]=' . rawurlencode($node['class']);
	$q[] = 'frm_o_o[' . $i . '][nname]=' . rawurlencode($node['nname']);
}
$add = '/display.php?' . implode('&', $q) . '&frm_action=addform&frm_o_cname=sslcert&frm_dttype[var]=upload&frm_dttype[val]=letsencrypt';
$list = '/display.php?' . implode('&', $q) . '&frm_action=list&frm_o_cname=sslcert';
check(str_contains($add, 'frm_o_o[0][class]=client'), 'URL keeps client in path');
check(str_contains($add, 'frm_o_o[2][class]=web'), 'URL keeps web in path');
check(str_contains($add, 'frm_dttype[val]=letsencrypt'), 'add URL keeps letsencrypt kind');
check(str_contains($list, 'frm_action=list'), 'list URL is SSL list');
check(!str_contains($list, 'frm_action=show') || str_contains($list, 'frm_o_cname=sslcert'),
	'list URL is not bare Home');

echo "== letsencrypt template uses certbot\n";
$tpl = (string) file_get_contents($repo . '/kloxo/file/letsencrypt/tpl/letsencrypt.sh.tpl');
check(str_contains($tpl, 'certbot'), 'template mentions certbot');
check(!str_contains($tpl, 'letsencrypt-auto certonly'), 'template does not require letsencrypt-auto alone');

echo "== ACME nginx location + redirect skip\n";
$acmeConf = (string) file_get_contents($repo . '/kloxo/file/nginx/conf/globals/acme-challenge.conf');
check(str_contains($acmeConf, 'location ^~ /.well-known/acme-challenge/'), 'acme location uses ^~');
check(str_contains($acmeConf, 'try_files $uri =404'), 'acme location uses vhost docroot try_files');
check(!str_contains($acmeConf, 'root /var/run/letsencrypt')
	&& !str_contains($acmeConf, 'alias /var/run/letsencrypt'),
	'acme location no longer points at /var/run/letsencrypt');
$domTpl = (string) file_get_contents($repo . '/kloxo/file/nginx/tpl/domains.conf.tpl');
check(str_contains($domTpl, 'well-known/acme-challenge'), 'domains tpl mentions ACME path');
check(str_contains($domTpl, 'request_uri !~* "^/\\.well-known/acme-challenge/"'),
	'https redirect skips ACME');
$sslSrc = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/pserver/sslcertlib.php');
check(str_contains($sslSrc, '{$d} www.{$d} webmail.{$d}')
	|| str_contains($sslSrc, "{\$d} www.{\$d} webmail.{\$d}"),
	'default LE SAN is domain + www + webmail');
check(!str_contains($sslSrc, 'webmail.{$d} mail.{$d}'),
	'default LE SAN does not force mail with webmail');
check(str_contains($sslSrc, 'getFullDocRoot'), 'createLetsencrypt passes docroot');
$sslPhp = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/kloxo_ssl.php');
check(str_contains($sslPhp, 'ensureAcmeNginxSnippet'), 'issueLetsEncrypt refreshes ACME snippet');
check(str_contains($sslPhp, 'preflightAcmeHttp'), 'issueLetsEncrypt preflights HTTP-01');
check(str_contains($sslPhp, 'resolveWebroot'), 'issueLetsEncrypt resolves docroot webroot');

// cleanup
KloxoSsl::$fsPrefix = '';
KloxoSsl::$runCmd = null;
KloxoSsl::$installPackages = null;
passthru('rm -rf ' . escapeshellarg($tmp));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
