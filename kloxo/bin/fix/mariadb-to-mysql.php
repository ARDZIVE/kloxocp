<?php

declare(strict_types=1);

// CentOS yum MariaDB↔MySQL swap removed. Ubuntu installs use MariaDB only
// (Switch Program / apt). Do not convert the SQL engine with this script.
fwrite(STDERR, "error: MariaDB-to-MySQL conversion is not supported on Ubuntu.\n");
fwrite(STDERR, "MariaDB is the default SQL server; leave it installed.\n");
exit(1);
