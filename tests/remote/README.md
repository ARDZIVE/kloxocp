# Backend protocol auth tests (audit C1)

Needs `php-cli` only. Does not start the panel or talk to a network.

```
bash tests/remote/run-tests.sh
```

These tests load `kloxo/httpdocs/lib/html/remote_auth_gate.php` and check that a
PHP object-injection gadget is never unserialized unless the credential envelope
is present and the password callback accepts it.
