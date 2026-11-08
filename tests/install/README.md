# Ubuntu panel-schema and Hiawatha installer checks

php-cli only. Does **not** start MariaDB, systemd, or a Hiawatha build.

```
bash tests/install/run-tests.sh
php kloxo/bin/install/init-panel-db --help
php kloxo/bin/install/init-panel-db --print-schema-stats
bash kloxo/bin/install/install-hiawatha --help
bash kloxo/bin/install/install-hiawatha --dry-run
php kloxo/bin/install/install-knot --help
php kloxo/bin/install/install-knot --dry-run --root=/opt/kloxo
php kloxo/bin/install/install-apache --help
php kloxo/bin/install/install-apache --dry-run --root=/opt/kloxo
```
