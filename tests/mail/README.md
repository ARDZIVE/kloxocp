# Mail stack tests

Run on a **disposable** Ubuntu 24.04 machine or container as root. The tests create a system user
`vmail`, and the live test rewrites `/etc/postfix`, `/etc/dovecot` and `/etc/rspamd/local.d`.

```
apt install php-cli php-mysql mariadb-server redis-server postfix postfix-mysql postfix-lmdb \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql dovecot-sieve \
    dovecot-managesieved rspamd
```

## 1. Static and database tests (no services need to run, MariaDB must)

```
bash tests/mail/run-tests.sh          # 73 checks; uses its own database and DB accounts
```

## 2. Live end-to-end test (real Postfix + Dovecot + Rspamd + Redis + MariaDB)

```
export KLOXO_MAIL_TEMPLATES=$PWD/kloxo/file/mail
KM="php kloxo/bin/mail/kloxo-mail"
# a certificate for the mail hostname (self-signed is fine for the test)
mkdir -p /etc/kloxo/tls /etc/kloxo/mail.d
openssl req -x509 -newkey rsa:2048 -nodes -keyout /etc/kloxo/tls/mail.key -out /etc/kloxo/tls/mail.pem \
    -subj "/CN=mail.example.com" -days 30 && chmod 640 /etc/kloxo/tls/mail.key
$KM init --hostname=mail.example.com --tls-cert=/etc/kloxo/tls/mail.pem --tls-key=/etc/kloxo/tls/mail.key
$KM domain:add example.com
echo 'correct horse battery' | $KM mailbox:add alice@example.com --password-stdin
echo 'correct horse battery' | $KM mailbox:add bob@example.com   --password-stdin
$KM alias:add info@example.com alice@example.com
$KM apply                      # on a systemd host this also reloads the services
# start the services (systemctl enable --now postfix dovecot rspamd redis-server mariadb), then:
python3 tests/mail/e2e.py      # 25 checks
```

In a container without systemd or syslog, start the services by hand and add log files through the
custom snippets, e.g. `maillog_file = /var/log/postfix.log` in `/etc/kloxo/mail.d/postfix-main.cf` and
`log_path = /var/log/dovecot.log` in `/etc/kloxo/mail.d/dovecot.conf`, then run `$KM apply --no-reload`.
