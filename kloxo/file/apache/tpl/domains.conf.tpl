<?php
/**
 * Ubuntu Apache 2.4 vhost. PHP via proxy_fcgi to the same PHP-FPM sockets
 * Nginx uses. No in-process PHP module.
 */

$altconf = "/opt/configs/apache/conf/customs/{$domainname}.conf";
if (file_exists($altconf)) {
	print("## Kloxo CP - use {$altconf} instead of this file\n");
	return;
}

if (!function_exists('kloxo_apache_token')) {
	$__webphp = '/opt/kloxo/httpdocs/lib/php/kloxo_web.php';
	if (is_readable($__webphp)) {
		include_once $__webphp;
	}
}

$domain = function_exists('kloxo_apache_token') ? kloxo_apache_token((string)$domainname) : preg_replace('/[^A-Za-z0-9._-]+/', '', (string)$domainname);
$user = isset($user) ? (string)$user : '';
if ($domain === '' || $user === '') {
	return;
}

if (!isset($phpselected) || !$phpselected) {
	$phpselected = 'php';
}
if (!isset($timeout)) {
	$timeout = '300';
}
$indexorder = isset($indexorder) && $indexorder ? (is_array($indexorder) ? implode(' ', $indexorder) : $indexorder) : 'index.php index.html';
$root = isset($rootpath) ? (string)$rootpath : "/home/{$user}/{$domain}";
$enablephp = !isset($enablephp) || $enablephp;
$enablessl = !isset($enablessl) || $enablessl;
$disabled = !empty($disabled);
if ($disabled) {
	$root = '/home/kloxo/httpd/disable';
}

$httpPort = 80;
$httpsPort = 443;
if (isset($webcache) && $webcache && $webcache !== 'none') {
	$httpPort = 8080;
	$httpsPort = 8443;
}

$aliases = array("www.{$domain}");
if (!empty($wildcards)) {
	$aliases[] = "*.{$domain}";
}
if (!empty($serveraliases) && is_array($serveraliases)) {
	foreach ($serveraliases as $sa) {
		$aliases[] = $sa;
	}
}
if (!empty($parkdomains) && is_array($parkdomains)) {
	foreach ($parkdomains as $pk) {
		$pa = is_array($pk) ? ($pk['parkdomain'] ?? '') : (is_object($pk) ? ($pk['parkdomain'] ?? '') : '');
		if ($pa !== '') {
			$aliases[] = $pa;
			$aliases[] = "www.{$pa}";
		}
	}
}
$aliasLine = '';
foreach (array_unique($aliases) as $a) {
	$tok = function_exists('kloxo_apache_token') ? kloxo_apache_token((string)$a) : preg_replace('/[^A-Za-z0-9._*-]+/', '', (string)$a);
	if ($tok !== '') {
		$aliasLine .= ' ' . $tok;
	}
}

$sock = function_exists('kloxo_apache_sock')
	? kloxo_apache_sock((string)$phpselected, $user)
	: '/opt/configs/php-fpm/sock/' . preg_replace('/[^A-Za-z0-9._-]+/', '', (string)$phpselected) . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '', $user) . '.sock';

$certbase = '/home/kloxo/ssl/' . $domain;
if (!empty($certnamelist) && is_array($certnamelist)) {
	$first = reset($certnamelist);
	if (is_string($first) && $first !== '') {
		$certbase = (strpos($first, '/') === 0) ? $first : '/home/kloxo/ssl/' . $first;
	}
}
if (is_file($certbase . '.key') === false && is_file('/home/kloxo/ssl/' . $domain . '.key')) {
	$certbase = '/home/kloxo/ssl/' . $domain;
}

$block = '';
if (!empty($blockips) && is_array($blockips)) {
	foreach ($blockips as $bip) {
		$bip = trim((string)$bip);
		if ($bip === '' || !preg_match('/^[0-9a-fA-F.:\/]+$/', $bip)) {
			continue;
		}
		$block .= "\tRequire not ip {$bip}\n";
	}
}

$extra = isset($apacheextratext) ? trim((string)$apacheextratext) : '';

$phpHandler = '';
if ($enablephp) {
	$phpHandler = "\t<FilesMatch \\.php$>\n"
		. "\t\tSetHandler \"proxy:unix:{$sock}|fcgi://localhost\"\n"
		. "\t</FilesMatch>\n"
		. "\tProxyTimeout {$timeout}\n";
}

$dir = "\tDocumentRoot \"{$root}\"\n"
	. "\t<Directory \"{$root}\">\n"
	. "\t\tOptions -Indexes +FollowSymLinks\n"
	. "\t\tAllowOverride All\n"
	. "\t\tRequire all granted\n"
	. $block
	. "\t</Directory>\n"
	. "\tDirectoryIndex {$indexorder}\n"
	. "\tAlias /.well-known/acme-challenge /var/run/letsencrypt/.well-known/acme-challenge\n"
	. $phpHandler
	. ($extra !== '' ? "\t# apache extra\n{$extra}\n" : '');

?>
### begin - web of '<?=$domain;?>' - do not remove/modify this line

<VirtualHost *:<?=$httpPort;?>>
	ServerName <?=$domain;?>

	ServerAlias<?=$aliasLine;?>

<?=$dir;?>
</VirtualHost>

<?php if ($enablessl && is_file($certbase . '.key') && (is_file($certbase . '.pem') || is_file($certbase . '.crt'))) {
	$certfile = is_file($certbase . '.pem') ? $certbase . '.pem' : $certbase . '.crt';
?>
<VirtualHost *:<?=$httpsPort;?>>
	ServerName <?=$domain;?>

	ServerAlias<?=$aliasLine;?>

	SSLEngine on
	SSLCertificateFile <?=$certfile;?>

	SSLCertificateKeyFile <?=$certbase;?>.key
<?php if (is_file($certbase . '.ca')) { ?>
	SSLCertificateChainFile <?=$certbase;?>.ca
<?php } ?>
<?=$dir;?>
</VirtualHost>
<?php } ?>

### end - web of '<?=$domain;?>'
