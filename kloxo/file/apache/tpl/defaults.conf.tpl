<?php
/**
 * Catch-all Apache vhost. Does not overwrite /etc/apache2/apache2.conf.
 */

$defaultdocroot = '/home/kloxo/httpd/default';
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

$acme = '/var/run/letsencrypt/.well-known/acme-challenge';
if (!is_dir($acme)) {
	@mkdir($acme, 0755, true);
}
if (!is_dir($defaultdocroot)) {
	@mkdir($defaultdocroot, 0755, true);
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
	ServerAlias *
	DocumentRoot "<?=$defaultdocroot;?>"
	<Directory "<?=$defaultdocroot;?>">
		Options -Indexes +FollowSymLinks
		AllowOverride All
		Require all granted
	</Directory>
	DirectoryIndex <?=$indexorder;?>

	Alias /.well-known/acme-challenge <?=$acme;?>

</VirtualHost>
<?php if (is_file($certbase . '.key') && (is_file($certbase . '.pem') || is_file($certbase . '.crt'))) {
	$certfile = is_file($certbase . '.pem') ? $certbase . '.pem' : $certbase . '.crt';
?>
<VirtualHost <?=$bind;?>:<?=$httpsPort;?>>
	ServerName _
	ServerAlias *
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

	Alias /.well-known/acme-challenge <?=$acme;?>

</VirtualHost>
<?php } ?>

### end - web of initial
