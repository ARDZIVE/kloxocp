<?php
/**
 * phpMyAdmin config written at install. Sign-on from the Kloxo panel session.
 * KLOXO_BLOWFISH_SECRET and KLOXO_PMA_TEMP are replaced by install-phpmyadmin.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

$cfg['blowfish_secret'] = 'KLOXO_BLOWFISH_SECRET';
$cfg['PmaAbsoluteUri'] = '/thirdparty/phpMyAdmin/';
$cfg['DefaultLang'] = 'en';
$cfg['AllowArbitraryServer'] = false;
$cfg['CheckConfigurationPermissions'] = false;
$cfg['TempDir'] = 'KLOXO_PMA_TEMP';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['AllowThirdPartyFraming'] = false;

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'KloxoPmaSignon';
$cfg['Servers'][$i]['SignonURL'] = 'kloxo-signon.php';
$cfg['Servers'][$i]['LogoutURL'] = '/display.php';
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = false;
