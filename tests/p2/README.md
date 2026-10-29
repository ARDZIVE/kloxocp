# P2 PHP 8.3 first-party parse checks

Needs `php-cli`. Does not start the panel or talk to MySQL.

```
bash tests/p2/run-tests.sh
```

Covers first-party `php -l` (excluding PEAR/WebDAV, formmail, urlrewrite examples),
replacement of `__autoload` with `spl_autoload_register`, boot-path `ereg*` removal in
`includecore.php`, the extra-argument fix on `char_search_beg`, characterization of
class loading and `DOCUMENT_ROOT` derivation, and `include.php` constructing `Sgbl` without
a Throwable (`test_boot.php`). Does not open a browser or connect to MariaDB.
