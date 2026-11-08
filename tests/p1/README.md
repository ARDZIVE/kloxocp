# P1 stack-shrink source checks

Needs `php-cli`. Does not start the panel.

```
bash tests/p1/run-tests.sh
```

Covers selectable drivers (Nginx default + Apache + none for sites, webcache none, BIND/Knot DNS), class-map
registration, deleted Lighttpd/Varnish/Squid/ATS/qmail/Hiawatha-site/unused-DNS templates,
kept Nginx/Apache/BIND/Knot DNS/mail templates, panel Hiawatha under `init/`, and the default web
driver being nginx (Apache packaged, unit disabled).
