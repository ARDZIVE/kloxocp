# Ubuntu panel-schema and Hiawatha installer checks

php-cli only. Does **not** start MariaDB, systemd, or a Hiawatha build.

```
bash tests/install/run-tests.sh
php kloxo/bin/install/init-panel-db --help
php kloxo/bin/install/init-panel-db --print-schema-stats
bash kloxo/bin/install/install-hiawatha --help
bash kloxo/bin/install/install-hiawatha --dry-run
```
