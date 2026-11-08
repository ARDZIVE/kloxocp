<?php

declare(strict_types=1);

/**
 * Load the storage layer. Safe to include more than once.
 */

require_once __DIR__ . '/KloxoStore.php';
require_once __DIR__ . '/KloxoMigrate.php';
require_once __DIR__ . '/KloxoPanelDb.php';
require_once __DIR__ . '/KloxoRegen.php';
require_once __DIR__ . '/KloxoStatsAgg.php';
require_once __DIR__ . '/KloxoSqliteBackup.php';
