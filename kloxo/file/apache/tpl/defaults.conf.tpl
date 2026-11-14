<?php
/**
 * Catch-all Apache vhost. Does not overwrite /etc/apache2/apache2.conf.
 *
 * Do not use ServerAlias *: on Apache 2.4 a bare "*" matches every Host and
 * steals traffic from named domain vhosts (sites then hit this empty
 * DocumentRoot and return 403 with Options -Indexes). Unknown Hosts still
 * fall through to this first-listed VirtualHost.
 */

if (!isset($defaultdocroot) || $defaultdocroot === '') {
	$defaultdocroot = '/home/kloxo/httpd/default';
}
$indexorder = isset($indexorder) && $indexorder ? (is_array($indexorder) ? implode(' ', $indexorder) : $indexorder) : 'index.php index.html';
$httpPort = 80;
$httpsPort = 443;
$bind = '*';
if (isset($webcache) && $webcache && $webcache !== 'none') {
	// Web cache in front (install-webcache): loopback backends, TLS on 8444.
	$bind = '127.0.0.1';
	$httpPort = 8081;
	$httpsPort = 8444;
}

if (!is_dir($defaultdocroot)) {
	@mkdir($defaultdocroot, 0755, true);
}
@mkdir($defaultdocroot . '/.well-known/acme-challenge', 0755, true);
$defaultIndex = $defaultdocroot . '/index.html';
if (!is_file($defaultIndex)) {
	@file_put_contents($defaultIndex, "<!doctype html><html><body><h1>Kloxo CP</h1><p>Default site.</p></body></html>\n");
}

$certbase = '/home/kloxo/ssl/default';
if (!empty($certnamelist) && is_array($certnamelist)) {
	$first = reset($certnamelist);
	if (is_string($first) && $first !== '') {
		$certbase = (strpos($first, '/') === 0) ? $first : '/home/kloxo/ssl/' . $first;
	}
}

?>
### begin - web of initial - do not remove/modify this line

<VirtualHost <?=$bind;?>:<?=$httpPort;?>>
	ServerName _
	DocumentRoot "<?=$defaultdocroot;?>"
	<Directory "<?=$defaultdocroot;?>">
		Options -Indexes +FollowSymLinks
		AllowOverride All
		Require all granted
	</Directory>
	DirectoryIndex <?=$indexorder;?>

</VirtualHost>
<?php if (is_file($certbase . '.key') && (is_file($certbase . '.pem') || is_file($certbase . '.crt'))) {
	$certfile = is_file($certbase . '.pem') ? $certbase . '.pem' : $certbase . '.crt';
?>
<VirtualHost <?=$bind;?>:<?=$httpsPort;?>>
	ServerName _
	SSLEngine on
	SSLCertificateFile <?=$certfile;?>

	SSLCertificateKeyFile <?=$certbase;?>.key
	DocumentRoot "<?=$defaultdocroot;?>"
	<Directory "<?=$defaultdocroot;?>">
		Options -Indexes +FollowSymLinks
		AllowOverride All
		Require all granted
	</Directory>
	DirectoryIndex <?=$indexorder;?>

</VirtualHost>
<?php } ?>

### end - web of initial
