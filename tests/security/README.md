# P0 security source checks

Needs `php-cli`. Does not start the panel.

```
bash tests/security/run-tests.sh
```

Covers web-console removal, Easy Installer disablement, sudoers, backend bind/TLS/size limit, and missing-key fail-closed behaviour.
