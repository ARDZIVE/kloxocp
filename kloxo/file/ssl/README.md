# TLS files for this installation

Private keys and certificates are **generated per install**. They are not shipped
in the source tree (audit C3: shared keys are treated as compromised).

```
php kloxo/bin/install/generate-tls-keys --hostname=panel.example.com
```

That writes:

* `init/backend.key` and `init/backend.crt` — C backend daemon
* `file/ssl/default.key`, `default.crt`, `default.pem` — panel and per-IP fallback

Kept in git (not secrets):

* `letsencryptauthorityx3.pem` — public Let's Encrypt / ISRG CA certificate
* `../openssl/tpl/dhparam_2048.pem` and `dhparam_4096.pem` — public DH parameters
  (512- and 1024-bit parameters were removed; they are too weak)
