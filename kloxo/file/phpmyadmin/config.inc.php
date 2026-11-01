<?php
/**
 * phpMyAdmin config written at install. Cookie auth only.
 * KLOXO_BLOWFISH_SECRET and KLOXO_PMA_TEMP are replaced by install-phpmyadmin.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

$cfg['blowfish_secret'] = 'KLOXO_BLOWFISH_SECRET';
$cfg['DefaultLang'] = 'en';
$cfg['AllowArbitraryServer'] = false;
$cfg['CheckConfigurationPermissions'] = false;
$cfg['TempDir'] = 'KLOXO_PMA_TEMP';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'cookie';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
