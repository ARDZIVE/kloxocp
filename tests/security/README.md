# P0 security source checks

Needs `php-cli`. Does not start the panel.

```
bash tests/security/run-tests.sh
```

Covers web-console removal, Easy Installer disablement, sudoers, backend bind/TLS/size limit, and missing-key fail-closed behaviour.

## Open findings checklist (validate before fixing)

Lists critical/high/medium issues that are still open in source. Each item is
**OPEN** until the secure pattern is present, then **CLOSED**. Exit 0 only when
all selected findings are CLOSED. Safe locally (no network, no panel start).

```
php tests/security/check-open-findings.php
php tests/security/check-open-findings.php --severity=critical
php tests/security/check-open-findings.php --json
bash tests/security/run-tests.sh   # also runs the checklist (reports open count; does not fail the suite)
```

This is a static checklist, not a live pentest. Re-run after each fix to confirm
which IDs flipped to CLOSED.
