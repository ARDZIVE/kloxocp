<?php
	$req = '';
	$dom = '';

	// Prefer certbot (Ubuntu); fall back to letsencrypt-auto only if present.
	$req .= "--rsa-key-size {$key_bits} ";

	if ($emailAddress === 'N/A' || $emailAddress === '') {
		$req .= '--register-unsafely-without-email ';
	} else {
		$req .= "--email {$emailAddress} ";
	}

	$doms = explode(" ", $subjectAltName);

	$count = 1;

	foreach ($doms as $k => $v) {
		$v = trim($v);
		if ($v === '') {
			continue;
		}
		if ($count === 1) {
			$basedom = $v;
		}

		$dom .= "\t-d $v  \\\n";

		$count++;
	}
?>
#!/bin/sh

logdir="/var/log/letsencrypt"
lepath="/etc/letsencrypt/live"
sslpath="/home/kloxo/ssl"
maindom="<?php echo $basedom; ?>"
<?php
	// Prefer the domain document root so Nginx/Apache serve the token as a static file.
	$wr = (isset($docroot) && is_string($docroot) && $docroot !== '' && $docroot[0] === '/')
		? $docroot
		: '/var/lib/kloxo/acme';
?>
webroot="<?php echo $wr; ?>"

mkdir -p "${webroot}/.well-known/acme-challenge" "${sslpath}" "${logdir}"

if command -v certbot >/dev/null 2>&1 ; then
	LEBIN="certbot"
elif [ -x /usr/bin/certbot ] ; then
	LEBIN="/usr/bin/certbot"
elif [ -x /usr/bin/letsencrypt-auto ] ; then
	LEBIN="/usr/bin/letsencrypt-auto"
else
	echo "certbot not found" >&2
	exit 127
fi

${LEBIN} certonly --non-interactive --agree-tos --keep-until-expiring \
	--webroot --webroot-path "${webroot}" \
	<?php echo $req; ?> \
<?php echo $dom; ?> 2>&1 | tee -a ${logdir}/letsencrypt.log

RETVAL=${PIPESTATUS[0]}
if [ -z "${PIPESTATUS[0]}" ] ; then
	RETVAL=$?
fi

if [ -f ${lepath}/${maindom}/chain.pem ] ; then
	cd ${lepath}/${maindom}

	STAMP=$(date +%Y-%m-%d:%H:%M:%S)
	cat privkey.pem cert.pem chain.pem > all.pem
	merge="cat privkey.pem cert.pem chain.pem > all.pem"
	echo "${STAMP}:Merge with '${merge}'" >> ${logdir}/letsencrypt.log

	cp -f ${lepath}/${maindom}/privkey.pem ${sslpath}/${maindom}.key
	cp -f ${lepath}/${maindom}/cert.pem ${sslpath}/${maindom}.crt
	cp -f ${lepath}/${maindom}/chain.pem ${sslpath}/${maindom}.ca
	cp -f ${lepath}/${maindom}/all.pem ${sslpath}/${maindom}.pem
	chmod 600 ${sslpath}/${maindom}.key
fi

exit $RETVAL
