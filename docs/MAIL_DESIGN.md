# Kloxo CP: Mail Stack

Status: first working implementation, tested on Ubuntu 24.04 package versions in a scratch container
(see "What was tested" and "What is NOT done yet" at the end).

## 1. Decision

| Role | Choice | Version on Ubuntu 24.04 |
|---|---|---|
| SMTP (MTA) | **Postfix** | 3.8.6 |
| IMAP / POP3 / LMTP / Sieve / SASL | **Dovecot** | 2.3.21 |
| Spam, DKIM signing, SPF/DKIM/DMARC checks | **Rspamd** (+ Redis) | 3.8.1 |
| Mailbox, alias and domain data | **MariaDB**, dedicated database | 10.11 |
| Storage | Maildir, one system user (`vmail`) | |
| Antivirus (optional "full" profile) | ClamAV through Rspamd | |
| Webmail (not included yet) | Roundcube | |

### Why this stack

* **Postfix** has the best security track record and operational maturity of any MTA, is
  heavily documented, and is what most large hosts and distributions standardise on.
* **Dovecot** is the de-facto IMAP server: Maildir, quota, Sieve/ManageSieve, LMTP delivery,
  SASL for Postfix, and `doveadm` for administration.
* **Rspamd** replaces four older tools (SpamAssassin, OpenDKIM, OpenDMARC, policyd-spf) with one
  fast, actively developed engine. One component fewer to patch and keep in sync.
* **Mailboxes in a dedicated MariaDB database** gives instant changes without reloads and a
  single source of truth. The mail database is *separate from the panel database* on purpose.

### Alternatives considered

| Option | Verdict |
|---|---|
| qmail-toaster / vpopmail (current Kloxo) | Unmaintained, awkward to secure and extend. Replaced. |
| Exim | Good and flexible (cPanel uses it) but configuration is harder to generate and validate safely; fewer ready-made pieces than the Postfix ecosystem. |
| Stalwart (all-in-one, Rust) | Promising, but much younger; I would revisit it once it has a long production record. |
| Docker suites (mailcow, Mailu) | Complete products, but they want to own the whole server and cannot be driven by a hosting panel. |

## 2. Architecture

```
                       Internet
                          |
      25 (postscreen)   587 / 465 (submission, TLS + SASL)   993 / 995 / 143 / 110 / 4190
            |                     |                                   |
        +---v---------------------v---+                      +--------v--------+
        |           POSTFIX           |<----- SASL --------->|     DOVECOT     |
        |  recipient checks (SQL),    |   (private socket)   |  auth (SQL),    |
        |  quota check, sender/login  |                      |  IMAP/POP3,     |
        |  match, rate limits         |------- LMTP -------->|  Sieve, quota   |
        +---+-------------------+-----+   (private socket)   +--------+--------+
            | milter                                                  |
        +---v------------+      +---------------+             +-------v--------+
        |    RSPAMD      |----->|     REDIS     |             |  /var/vmail/   |
        | spam, DKIM,    |      | (bayes, rate) |             |  domain/user/  |
        | DMARC, SPF     |      +---------------+             |  Maildir       |
        +----------------+                                    +----------------+

   Postfix and Dovecot read the mail database (MariaDB, unix socket, SELECT-only account).
   `kloxo-mail` (the panel's only writer) uses a separate read-write account.
```

### Data model (`kloxo/file/mail/schema.sql`)

`domains`, `mailboxes` (Argon2id hash, quota, active flag), `aliases` + `alias_targets`
(forwarders and catch-all as `@domain`). Disabling a domain or mailbox takes effect for
SMTP and IMAP immediately (SQL conditions + cache flush).

### The tool: `kloxo-mail`

A standalone PHP 8.3 command-line tool (`kloxo/bin/mail/kloxo-mail`), independent of the legacy
panel code. The panel (UI and later the privileged agent) calls it; people can also use it directly.

```
kloxo-mail init --hostname=mail.example.com
kloxo-mail domain:add example.com            # also creates a 2048-bit DKIM key
echo 'secret-password' | kloxo-mail mailbox:add alice@example.com --password-stdin --quota=2048
kloxo-mail alias:add info@example.com alice@example.com
kloxo-mail alias:add @example.com alice@example.com   # catch-all
kloxo-mail dns:records example.com --ip=203.0.113.7   # MX, SPF, DKIM, DMARC to publish
kloxo-mail apply                                       # render, back up, validate, reload, roll back on failure
```

Safety properties (all covered by tests):

* every input is validated (domains, addresses, selectors); SQL uses prepared statements only;
* passwords are never accepted on the command line (stdin or environment variable only);
* commands run as argument arrays, never through a shell;
* `apply` renders to a temporary directory, backs up the current files, installs, runs the
  services' **own validators** (`postfix check`, `doveconf -n`, `rspamadm configtest`, `sievec`,
  and a probe of every SQL map), and **restores the previous files if anything fails**;
* your customisations live in `/etc/kloxo/mail.d/` (`postfix-main.cf`, `postfix-master.cf`,
  `dovecot.conf`) and are appended to the generated files, so updates never overwrite them;
* purge operations refuse to delete anything outside the mail store.

## 3. Security and robustness choices

* **TLS everywhere:** TLS 1.2+, login only over TLS (Dovecot `ssl = required`, Postfix
  `smtpd_tls_auth_only`); implicit TLS (465, 993, 995) and STARTTLS (587, 143, 110) offered.
* **Passwords:** Argon2id in the database (Dovecot verifies the PHP-generated hash; tested).
* **Submission only for authenticated users**, and **the sender must belong to the login**
  (`reject_authenticated_sender_login_mismatch`): users cannot forge other users' addresses;
  an alias owner may send as the alias.
* **No backscatter:** unknown recipients are refused during the SMTP dialogue (SQL lookup);
  full mailboxes are refused with a temporary 452 through Dovecot's `quota-status` check.
* **Catch-all cannot swallow real mailboxes** (a classic bug): the alias lookup maps an existing
  mailbox to itself first.
* **Fail-safe filtering:** if Rspamd is down, Postfix defers mail (`milter_default_action =
  tempfail`) instead of delivering it unfiltered; the sender retries.
* **Mail database failure** causes temporary (4xx) errors, never bounces.
* **Spam handling:** Rspamd marks spam; a global Sieve rule files it into `Junk`; thresholds are
  conservative (reject only at 15).
* **Outbound DKIM:** signing is driven by maps generated from the database; only domains that
  have a key are signed; keys are created per domain, mode 0640, readable by Rspamd only.
* **Dovecot auth cache:** 2 minutes, flushed by `kloxo-mail` on every password, quota or
  enable/disable change, so changes act at once.
* **fail2ban jails** for Postfix and Dovecot are shipped.
* **IPv6-less servers** are handled (Dovecot listens on `*` only when the kernel has no IPv6).
* **postscreen** protects port 25 (greet check). DNSBLs are deliberately off by default because
  they require a local recursive resolver (public resolvers get blocked and cause false positives).

## 4. What the panel still has to do (outside the mail stack itself)

* Publish DNS records: `kloxo-mail dns:records` prints MX, SPF, DKIM and DMARC. Reverse DNS (PTR)
  of the server IP must match the mail hostname, or big providers will treat the mail as spam.
* Issue a real TLS certificate for the mail hostname (Let's Encrypt) and set `tls_cert` / `tls_key`.
  The first install can start with a self-signed certificate.
* Local caching resolver (e.g. `unbound`) is recommended for Rspamd and any DNSBL use.
* Open ports 25, 465, 587, 993, 995 (and 143, 110, 4190 if wanted) in the firewall.

## 5. Moving from qmail / vpopmail

* Maildir is shared: mail can be copied or synced (`doveadm sync` / `rsync`) into the new layout
  (`/var/vmail/<domain>/<user>/Maildir`).
* Old password hashes (vpopmail uses crypt formats) can be imported with their Dovecot scheme prefix,
  e.g. `{MD5-CRYPT}$1$...`, and upgraded to Argon2id at the next password change. An import command
  is not written yet.
* The ezmlm mailing lists and the qmail autoresponder have no equivalent here yet (see below).

## 6. Integration with the existing Kloxo panel code

The legacy mail layer is a set of swappable drivers (`Mmail__Qmail`, `Mailaccount__Qmail`,
`Mailforward__Qmail`, `Servermail__Qmail`, `Imap4__dovecot`, `Spam__spamassassin`...). Plan:

1. Add thin `*__postfix` drivers whose `dbactionAdd/Update/Delete` methods call `kloxo-mail`
   through the privileged agent (no mail logic in the legacy PHP).
2. Keep the existing UI screens (accounts, forwards, quotas, spam settings).
3. Remove the qmail, courier, ezmlm, bogofilter and SpamAssassin drivers once the new ones work.

This step needs the legacy PHP running on PHP 8.3, so it follows the port.

## 7. What was tested

Environment: a scratch container with the Ubuntu 24.04 packages listed above, no systemd, no IPv6,
no internet, everything on localhost.

* **73 automated checks** (`tests/mail/run-tests.sh`): input validation and injection attempts,
  database, all four Postfix SQL maps against the real database through Postfix's own `postmap`,
  Postfix / Dovecot / Rspamd configuration syntax, Argon2id verification by Dovecot, DKIM and DNS
  output, apply and rollback, purge safety.
* **25 live end-to-end checks** (`tests/mail/e2e.py`) against running Postfix, Dovecot, Rspamd,
  Redis and MariaDB: delivery over SMTP to a mailbox and through an alias, rejection of unknown
  recipients, authenticated submission on 587 and 465, DKIM signing of outgoing mail, sender
  spoofing blocked, wrong password / no TLS / no authentication refused, IMAPS and POP3S logins,
  quota refusal during SMTP, and immediate effect of password change and mailbox disable.
* A deliberately broken Dovecot template was rejected by `apply` and the live configuration was
  restored byte-for-byte.

Bugs found by these tests and fixed: Dovecot refusing to start without IPv6, quota check being
bypassable from localhost, DKIM maps required by this Rspamd version, stale auth cache after
password/quota changes, and test cross-talk through shared DB accounts.

## 8. What is NOT done yet (be aware before relying on it)

* Not tested on a real server with systemd, a real domain, real DNS, a real certificate or the
  public internet. **Deliverability (reputation, PTR, SPF/DKIM/DMARC alignment at Gmail/Outlook)
  must be tested on a real VM.**
* Not tested: outbound delivery to external servers, greylisting, postscreen from external clients,
  ClamAV, fail2ban rules in action, load and performance, backups and restores.
* Not implemented: per-domain TLS certificates (SNI), Roundcube webmail, autoresponders (Sieve
  vacation via ManageSieve is available but not wired to the UI), mailing lists, mail traffic
  statistics, queue management screens (`postqueue -j` / `postsuper`), qmail data import,
  DNS auto-publishing, MTA-STS / TLS-RPT / DANE, AlmaLinux support.
* The legacy panel drivers are not connected yet (section 6).
* Secrets: DB passwords for Postfix/Dovecot live in root-owned files (0640 with the service group);
  this is the normal pattern but should be reviewed with the privileged-agent design.
