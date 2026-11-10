<?php

declare(strict_types=1);

// CentOS yum MySQL→MariaDB swap removed. Ubuntu installs already use MariaDB
// (install.sh enables mariadb-server).
fwrite(STDERR, "error: MySQL-to-MariaDB conversion is not needed on Ubuntu.\n");
fwrite(STDERR, "MariaDB is installed by default; use systemctl status mariadb.\n");
exit(1);
