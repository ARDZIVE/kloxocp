# P3 kloxocp CLI checks

Needs `php-cli`. Does not start the panel, apt, or systemd. Snapshot/rollback run
inside a temp directory. `install.sh --help` / `--dry-run` are checked; the Ubuntu
bootstrap is not executed here.

```
bash tests/p3/run-tests.sh
php kloxo/bin/kloxocp help
bash install.sh --help
```
