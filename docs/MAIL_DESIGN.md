# Kloxo CP: Mail Stack

Status: first working implementation was Postfix (tested on Ubuntu 24.04 in a scratch
container). The MTA is now **Exim**; Dovecot + Rspamd + MariaDB are unchanged. Re-run
`tests/mail/run-tests.sh` and `tests/mail/e2e.py` on a disposable Ubuntu host before
treating the Exim path as live-verified.

## 1. Decision

| Role | Choice | Version on Ubuntu 24.04 |
|---|---|---|
| SMTP (MTA) | **Exim** (`exim4-daemon-heavy`) | 4.97 |
| IMAP / POP3 / LMTP / Sieve / SASL | **Dovecot** | 2.3.21 |
| Spam, DKIM signing, SPF/DKIM/DMARC checks | **Rspamd** (+ Redis) | 3.8.1 |
| Mailbox, alias and domain data | **MariaDB**, dedicated database | 10.11 |
| Storage | Maildir, one system user (`vmail`) | |
| Antivirus (optional "full" profile) | ClamAV through Rspamd | |
| Webmail (not included yet) | Roundcube | |

Switch Program SMTP is `none` / `exim` (default) / `postfix`. `smtp__exim`
enables the `exim4` unit and stops Postfix. `smtp__postfix` enables Postfix
and stops Exim. The Postfix unit stays disabled until selected. Hosted-mail
templates stay Exim (`kloxo-mail`); there is no `file/mail/postfix` tree.

### Why this stack

* **Exim** is the owner's MTA choice (cPanel-style flexibility, Ubuntu `exim4-daemon-heavy`
  with MySQL, Dovecot SASL, LMTP and the Rspamd protocol).
* **Dovecot** is the de-facto IMAP server: Maildir, quota, Sieve/ManageSieve, LMTP delivery,
  SASL for Exim, and `doveadm` for administration.
* **Rspamd** replaces four older tools (SpamAssassin, OpenDKIM, OpenDMARC, policyd-spf) with one
  fast, actively developed engine. One component fewer to patch and keep in sync.
* **Mailboxes in a dedicated MariaDB database** gives instant changes without reloads and a
  single source of truth. The mail database is *separate from the panel database* on purpose.

### Alternatives considered

| Option | Verdict |
|---|---|
| qmail-toaster / vpopmail (current Kloxo) | Unmaintained, awkward to secure and extend. Replaced. |
| Postfix | Selectable in Switch Program; unit disabled until chosen. Hosted-mail templates stay Exim. |
| Stalwart (all-in-one, Rust) | Promising, but much younger; I would revisit it once it has a long production record. |
| Docker suites (mailcow, Mailu) | Complete products, but they want to own the whole server and cannot be driven by a hosting panel. |

## 2. Architecture

```
                       Internet
                          |
      25                587 / 465 (submission, TLS + SASL)   993 / 995 / 143 / 110 / 4190
            |                     |                                   |
        +---v---------------------v---+                      +--------v--------+
        |            EXIM             |<----- SASL --------->|     DOVECOT     |
        |  recipient checks (SQL),    |   (auth-client)      |  auth (SQL),    |
        |  quota check, sender/login  |                      |  IMAP/POP3,     |
        |  match, rate limits         |------- LMTP -------->|  Sieve, quota   |
        +---+-------------------+-----+   (/run/dovecot/lmtp)+--------+--------+
            | rspamd protocol 11333                                   |
        +---v------------+      +---------------+             +-------v--------+
        |    RSPAMD      |----->|     REDIS     |             |  /var/vmail/   |
        | spam, DKIM,    |      | (bayes, rate) |             |  domain/user/  |
        | DMARC, SPF     |      +---------------+             |  Maildir       |
        +----------------+                                    +----------------+

   Exim and Dovecot read the mail database (MariaDB, unix socket, SELECT-only account).
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
  services' **own validators** (`exim -C /etc/exim4/exim4.conf -bV`, `doveconf -n`,
  `rspamadm configtest`, `sievec`, and a mail-database probe), and **restores the previous
  files if anything fails**. A missing Exim/Dovecot/Rspamd checker binary is a
  validation failure (no silent skip);
* your customisations live in `/etc/kloxo/mail.d/` (`exim4.conf` is inserted into the generated
  main section; `dovecot.conf` is appended) so updates never overwrite them;
* **Dovecot series:** Ubuntu 24.04 uses the 2.3 templates (`dovecot/dovecot.conf` +
  `dovecot-sql.conf.ext`). Ubuntu 26.x (Dovecot 2.4+) uses `dovecot/dovecot24.conf` (SQL inline;
  `dovecot_config_version` first). `kloxo-mail` picks the series from `dovecot --version`
  (override with `KLOXO_DOVECOT_VERSION=2.3|2.4`);
* purge operations refuse to delete anything outside the mail store.

## 3. Security and robustness choices

* **TLS everywhere:** TLS 1.2+, login only over TLS (Dovecot `ssl = required`, Exim
  `auth_advertise_hosts` only when TLS is up on 587/465); implicit TLS (465, 993, 995) and
  STARTTLS (587, 143, 110) offered. Port 25 never advertises AUTH. Ubuntu Exim is
  **GnuTLS**-built: the template uses a GnuTLS `tls_require_ciphers` priority string and must
  not set `openssl_options` (Exim rejects that with "we're using GnuTLS").
* **Passwords:** Argon2id in the database (Dovecot verifies the PHP-generated hash; tested).
* **Submission only for authenticated users**, and **the sender must belong to the login**
  (MAIL ACL compares `$sender_address` to `$authenticated_id` via mailbox UNION alias-owner):
  users cannot forge other users' addresses; an alias owner may send as the alias.
* **No backscatter:** unknown recipients are refused during the SMTP dialogue (`verify = recipient`
  plus the `kloxo_unknown` `:fail:` router); full mailboxes are refused with a temporary 452
  through Dovecot's `quota-status` policy socket.
* **Catch-all cannot swallow real mailboxes** (a classic bug): routers run exact alias, then
  mailbox, then `@domain` catch-all.
* **Fail-safe filtering:** if Rspamd is down, Exim defers mail (`spam = nobody:true` with no
  `defer_ok`) instead of delivering it unfiltered; the sender retries.
* **Mail database failure** causes temporary (4xx) errors, never bounces (Exim MySQL lookup
  failure is a forced defer).
* **Spam handling:** Rspamd marks spam; a global Sieve rule files it into `Junk`; thresholds are
  conservative (reject only at 15).
* **Outbound DKIM:** signing is driven by maps generated from the database; only domains that
  have a key are signed; keys are created per domain, mode 0640, readable by Rspamd only.
* **Dovecot auth cache:** 2 minutes, flushed by `kloxo-mail` on every password, quota or
  enable/disable change, so changes act at once.
* **fail2ban jails** for Exim and Dovecot are shipped.
* **IPv6-less servers** are handled (Dovecot listens on `*` and Exim `disable_ipv6` when the
  kernel has no IPv6).
* **Quota check runs before the local-network permit** so senders on 127.0.0.0/8 cannot bypass
  a full mailbox. DNSBLs are deliberately off by default because they require a local recursive
  resolver (public resolvers get blocked and cause false positives).

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
`Mailforward__Qmail`, `Servermail__Qmail`, `Imap4__dovecot`, `Spam__spamassassin`...). SpamAssassin is gone; Switch Program Spam is none/rspamd only. Plan:

1. Add thin `*__exim` drivers whose `dbactionAdd/Update/Delete` methods call `kloxo-mail`
   through the privileged agent (no mail logic in the legacy PHP).
2. Keep the existing UI screens (accounts, forwards, quotas, spam settings).
3. Remove the qmail, courier, ezmlm, bogofilter and SpamAssassin drivers once the new ones work. (Done for those drivers; Switch Program uses `Spam__rspamd`.)

This step needs the legacy PHP running on PHP 8.3, so it follows the port.

## 6a. SMTP protection (replaces Spamdyke)

The panel's Mail Server Settings > SMTP Protection tab (formerly Spamdyke) is implemented by the SMTP server
selected in Switch Program. The panel validates the form (`KloxoMailPolicy::normalize`), saves
`/etc/kloxo/mail-policy.json` and runs `kloxo-mail policy:apply --mta=exim|postfix --policy=<file>`
(`policy:show` prints the saved policy; policy off restores the original configuration).

| Setting | Exim (rendered into `exim4.conf`) | Postfix (`postconf`/`postconf -M`) |
|---|---|---|
| greet delay | connect ACL `delay` for unauthenticated port-25 clients; premature input is refused by `smtp_enforce_sync` | `postscreen_greet_wait` + `postscreen_greet_action = enforce` |
| max recipients | `$recipients_count` check in the RCPT ACL | `smtpd_recipient_limit` |
| graylisting | `readsocket` to Postgrey after the unknown-user checks; Postgrey down = defer | `check_policy_service inet:127.0.0.1:10023` |
| reject empty / unresolvable rDNS | `$sender_host_name` and `$host_lookup_deferred` | `reject_unknown_reverse_client_hostname` / `reject_unknown_client_hostname` |
| rDNS contains the IP | regex on the host name (`dnsdb ptr`) | `check_reverse_client_hostname_access regexp:` (approximation) |
| sender domain has no MX/A | `verify = sender` | `reject_unknown_sender_domain` |
| DNS RBL servers | `dnslists`, ignoring 127.255.255.x error answers | `reject_rbl_client zone=127.0.0.[2..255]` |
| blacklist headers | DATA ACL on `$h_<name>:` (case-insensitive, `*` wildcard, all else escaped) | `header_checks regexp:` (also applies to local submission) |
| whitelist IPs | named hostlist `kloxo_policy_whitelist` exempting every rule | `check_client_access cidr:` and the postscreen access list |

First-visit form defaults (empty lists): greet delay 3 s, max recipients 150, graylisting on
(300 / 1814400 s), reject missing sender MX on, rDNS rejects off, RBL `zen.spamhaus.org` and
`bl.spamcop.net`, header rules `X-Mailer: *Mass Mailer*`, `X-Mailer: *Flooder*`, `Subject: *ADV:`.

Authenticated, local and whitelisted clients skip the rDNS, RBL and greet rules. Postgrey is installed through
`KloxoApt` when graylisting is on (`/etc/default/postgrey`: `--delay`, `--max-age` rounded up to days). Postfix
apply keeps a state file and backups, validates with `postfix check`, and refuses to install when
`smtpd_relay_restrictions` would leave an open relay. Not verified on a real server: Exim `-bV`, Postfix
`check`/reload/postscreen, Postgrey, and live SMTP sessions through these rules (see section 8).

## 7. What was tested

Environment for the original Postfix implementation: a scratch container with Ubuntu 24.04
packages, no systemd, no IPv6, no internet, everything on localhost.

* **`tests/mail/run-tests.sh`:** input validation and injection attempts, database, SQL lookups
  matching the Exim routers/ACLs, Exim / Dovecot / Rspamd configuration syntax, Argon2id
  verification by Dovecot, DKIM and DNS output, apply and rollback, purge safety.
* **`tests/mail/e2e.py`:** 25 live checks against running Exim, Dovecot, Rspamd, Redis and
  MariaDB (same SMTP ports 25/587/465): delivery, aliases, unknown-recipient 550, authenticated
  submission, DKIM, sender spoofing blocked, wrong password / no TLS / no authentication refused,
  IMAPS and POP3S, quota 452 at SMTP, immediate effect of password change and mailbox disable.

The Postfix-era run of those suites passed. After the Exim swap they have **not** been re-run
on Ubuntu in this change (no MariaDB/Exim on the development Mac). Do not treat Exim as
live-verified until that re-run.

SMTP protection: `tests/p2/test_mailpolicy.php` (renderer, rendered Exim text, Postfix tool against a throw-away
config directory with the host's `postconf`, panel hand-off with fakes). No real Exim, Postfix daemon or Postgrey ran.

Bugs found by the original tests and fixed: Dovecot refusing to start without IPv6, quota check
being bypassable from localhost, DKIM maps required by this Rspamd version, stale auth cache after
password/quota changes, and test cross-talk through shared DB accounts.

## 8. What is NOT done yet (be aware before relying on it)

* Not tested on a real server with systemd, a real domain, real DNS, a real certificate or the
  public internet. **Deliverability (reputation, PTR, SPF/DKIM/DMARC alignment at Gmail/Outlook)
  must be tested on a real VM.**
* Not tested: outbound delivery to external servers, greylisting, ClamAV, fail2ban rules in action,
  load and performance, backups and restores.
* Not implemented: per-domain TLS certificates (SNI), Roundcube webmail, autoresponders (Sieve
  vacation via ManageSieve is available but not wired to the UI), mailing lists, mail traffic
  statistics, qmail data import, DNS auto-publishing, MTA-STS / TLS-RPT / DANE, AlmaLinux support.
* The legacy panel drivers are not connected yet (section 6). Mail Queue can list/delete via
  `exim -bp` / `exim -Mrm` (`mailqueue__exim`).
* Secrets: DB passwords for Exim/Dovecot live in root-owned files (0640 with the service group);
  this is the normal pattern but should be reviewed with the privileged-agent design.
