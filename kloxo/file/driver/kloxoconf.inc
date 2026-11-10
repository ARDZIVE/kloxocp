<?php

// Ubuntu defaults (panel targets Ubuntu 24.04 / 26.x only).
$conf['path']['apache_path'] = '/etc/apache2/';
$conf['path']['named_chroot'] = "";
$conf['path']['named_path'] = "/var/cache/bind";
$conf['path']['named_conf'] = '/etc/bind/named.conf';
$conf['var_driver']['ipaddress'] = 'debian';
$conf['var_driver']['service'] = 'debian';
$conf['var_drivername']['web'] = 'apache2';
$conf['var_drivername']['dns'] = 'named';
$conf['var_drivername']['mysql'] = 'mariadb';
$conf['var_driveruser']['dns'] = 'bind';
