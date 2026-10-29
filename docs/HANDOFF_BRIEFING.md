# Kloxo CP: Handoff Briefing (for the next developer / AI agent)

Read this first. It tells you what the project is, what has been decided, what exists, what is
verified, what is broken or missing, and what to do next. Facts below come from the work done in
the previous session; anything not verified is marked **UNVERIFIED**.

---

## 1. Mission and constraints

Build **Kloxo CP**, a modern, stable, easy-to-install and easy-to-update web hosting control panel,
by forking **Kloxo-MR** (abandoned since July 2019) and modernising it.

Owner's requirements (non-negotiable unless the owner changes them):

| Topic | Decision |
|---|---|
| Name | **Kloxo CP** (display), `kloxo` for identifiers/paths/services |
| Base | https://github.com/mustafaramadhan/kloxo, branch `dev`, commit `e0dfc28a15663fc3c682ee55c35d41f4032633f4` (version 7.0.0.c-2019072201) |
| License | **GNU AGPL v3**, must stay AGPL; keep all upstream copyright/authorship notices |
| Target OS | **Ubuntu 24.04 LTS first**. AlmaLinux/Rocky 9 later, through an OS abstraction layer |
| Customer websites | **Nginx + PHP-FPM only** (multiple PHP versions side by side). Remove Apache/Lighttpd/Varnish/Squid/ATS |
| The panel's own web server | **Hiawatha is kept** (as in Kloxo-MR), used only for the panel UI |
| Billing | **None for now.** Owner will add it later; leave a clean extension point, do not integrate |
| UI | **Keep the existing UI** |
| Install | One command, under ~10 minutes on a clean server |
| Updates | One command or button for the panel and every managed component (SQL, FTP, BIND, PHP versions, web, mail), with snapshot, validation, health check and **automatic rollback** |
| Quality bar | "Most stable and viable": earned through tests, small scope, CI and release discipline (see `INSTALL_AND_UPDATE_DESIGN.md`) |
| Mail | Postfix + Dovecot + Rspamd + MariaDB (implemented, see section 5) |

**Naming caveat for the owner:** "Kloxo" is the upstream project's name. `NOTICE` and `README.md` say this
fork is independent. The owner should check trademark/confusion risk and secure a domain and GitHub
name before public release. Not legal advice.

---

## 2. Repository map

```
kloxo-cp/
  README.md, NOTICE, LICENSE.txt, AGENTS.md
  .cursor/rules/kloxo-cp.mdc     standing rules for Cursor
  kloxo/                         panel source; installs to /opt/kloxo (was upstream "kloxo/")
    httpdocs/                    web UI, libs (lib/, driver/, theme/, lang/), PEAR/WebDAV bundle (webdisk/)
    bin/ sbin/ pscript/          backend and maintenance scripts (PHP and shell)
    bin/mail/kloxo-mail          NEW mail tool (PHP 8.3, standalone)
    bin/install/generate-tls-keys  NEW: unique TLS keys per install (PHP 8.3)
    cexe/                        C sources: backend daemon (kloxo.c) only; no setuid helpers
    init/                        panel service units, Hiawatha base config, php-fpm templates
    file/                        service config templates (apache, nginx, hiawatha, bind, qmail... and NEW file/mail/)
    install/                     LEGACY RPM/CentOS installer (reference only, to be replaced)
  docs/
    HANDOFF_BRIEFING.md          this file
    AUDIT_REPORT.md              security + PHP 8 audit and roadmap
    INSTALL_AND_UPDATE_DESIGN.md install/update/stability design (nothing implemented yet)
    MAIL_DESIGN.md               mail stack design, tests, gaps
    REBRAND_CHANGELOG.md         exactly what the rebrand changed / did not change
    legacy/                      obsolete RHEL install notes
  tests/mail/                    run-tests.sh (73 checks), e2e.py (25 live checks), README.md
  tests/tls/                     generate-tls-keys checks (php-cli + openssl)
  tests/remote/                  C1 auth-before-unserialize checks (php-cli only)
  tools/rebrand_to_kloxocp.py    script of the second rebrand step
```

Size: about 597 PHP files, roughly 165k lines (about 45k are the bundled PEAR/WebDAV in `httpdocs/webdisk`).
Core: `httpdocs/lib` about 51k lines, `driver` about 30k, `theme` about 16k, `bin` about 7k.

---

## 3. What has been done

1. **Rebrand** Kloxo-MR to Kloxo CP (scripted, reproducible; see `REBRAND_CHANGELOG.md`).
   * Paths: program root `/opt/kloxo` (was `/usr/local/lxlabs/kloxo`), data `/home/kloxo`, mail home `/home/kloxomail`.
   * Display text "Kloxo CP"; Hiawatha ServerString `Hiawatha_for_KloxoCP`.
   * Dead upstream URLs (`download.lxcenter.org`, lxcenter.org, mratwork.com) replaced by `kloxocp.invalid` so the panel cannot call hosts the project does not control.
   * Path-layout fixes in `install/installer.php`, `install/step2.inc`, `httpdocs/lib/sgbl.php`.
   * SQL/schema files only received same-length edits (PHP-serialized strings inside).
2. **Removed from the tree:** prebuilt ELF binaries `cexe/closeinput`, `closeallinput`, `lxmisc`; `pscript/setup-tht` (billing); upstream logo.
3. **Security and PHP 8 audit** (static only): `docs/AUDIT_REPORT.md`.
4. **Design docs** for install/update/stability and for mail.
5. **Mail stack implemented and tested** (section 5).
6. **P0.1 (C3) closed:** committed private keys and matching certs deleted (`init/backend.*`,
   `file/ssl/default.*`, `file/ssl/rootCA.*`, unused `theme/filecore/p.c`/`p.k`, weak
   `dhparam_512`/`1024`). `kloxo/bin/install/generate-tls-keys` writes unique self-signed
   RSA-2048 material at install (`--root`, `--force`). `.gitignore` covers those paths.
   Treat every copy of the old keys as compromised.
7. **P0.2 (C4) closed:** deleted `cexe/closeinput.c`, `closeallinput.c`, `lxmisc.c`; Makefile
   and the legacy installer no longer `chmod ug+s`; `exec_with_all_closed*` no longer calls a
   helper. Remaining `chmod 4755` on qmail `vchkpw` in `pscript/fixmail-all` waits for P1.
8. **P0.3 (C1) closed** for `process_server_input()`: auth envelope required before unserialize;
   `do_remote_exec` always sends it (including localhost). Tests: `tests/remote/`. JSON
   replacement is planned, not built. Other unserialize-on-untrusted-input sites remain.

### Deliberately NOT changed (do not "fix" these blindly)

* Internal `lx*` identifiers (`lxclass`, `lxlib`, `lxshell_*`, DB tables `lxguard*`, column `ser_lxadminconfig_b`, program name `lxlabsclient`). The DB stores PHP-serialized data; renames need tests first.
* The service account **`lxlabs`** (system user/group) and DB replication user `lxlabsslave`. Some code uses the literal as a user name; a blind rename broke things in an early attempt. Plan: introduce one constant for the service account, then rename.
* `kloxo/bin/kloxoversion` (`7.0.0.c-2019072201`): version parsers expect that format.
* Legacy installer and RPM/yum logic in `install/` and parts of `pscript/`.
* Copyright/authorship lines (AGPL requirement).

---

## 4. Audit summary (details and file locations in `AUDIT_REPORT.md`)

**The code is NOT safe to run as-is and does NOT run on PHP 8.3.**

Critical (fix first):

* **C1** **CLOSED** for `process_server_input()`: the unauthenticated `unserialize(base64_decode($total))` path is gone. Credentials are required in the `_._` envelope **before** the payload is unserialized (`remote_auth_gate.php`). Localhost uses the same envelope. Other unserialize-on-untrusted-input sites (~31, plus `process_in_master` / `remote_main`) remain until the JSON replacement.
* **C2** `kloxo/cexe/kloxo.c` (backend daemon): binds all interfaces, `SSLv23_method`, `verify_client = OFF` hard-coded, unbounded `strcpy/sprintf`, no message-size limit, executes PHP on received data.
* **C3** **CLOSED.** Committed private keys and matching certs deleted. Unique keys are generated at install by `kloxo/bin/install/generate-tls-keys`. Old keys remain compromised anywhere they were copied.
* **C4** **CLOSED.** Setuid helpers and sources deleted; Makefile and installer no longer set the setuid bit on `cexe/`. Qmail `vchkpw` setuid in `pscript/fixmail-all` remains until P1 removes qmail.
* **C5** Code downloads and unpacks code over plain HTTP (`programlib.php`, `bin/easyinstaller-update.php`, ...). Neutralised by the `.invalid` hosts, but the feature still exists.

High: 787 shell-execution call sites (+379 `lxshell_*`), 55 `eval()`, 25 `extract()`, `web-console/` (browser shell), MD5/crypt password hashing (admin password via MD5-crypt), TLS verification disabled in 12 places, `pscript/set-kloxo-php` writes sudoers `lxlabs ALL=(ALL:ALL) NOPASSWD:/opt/kloxo`, outdated bundles (PHPMailer 5.2.19, PEAR/WebDAV, ExtJS 2.x, formmail.php 2001-2004).

PHP 8.3: `php -l` fails on 50 of 621 files: 42 third-party/example code, and these first-party/bundled: `lib/php/lxclass.php:2425`, `lib/php/common.inc:8` (`__autoload`), `lib/html/ippoollib.php:116`, `api/api.php:36`, `lib/demologins.php:38`, `bin/common/iptraffic.php:74`, `driver/web/easyinstaller__linuxlib.php:82`, `lib/extra/PHPMailerAutoload.php:45`. Runtime breakage is far larger: `mysql_*` (59 uses), `split` (59), `ereg*` (29), `each` (27), `create_function` (6), magic-quotes (about 80), `$HTTP_*_VARS`, `/e` regexes, ioncube references. There are **no tests** for the legacy code.

---

## 5. Mail stack (done, tested, not yet connected to the legacy UI)

Full detail: `docs/MAIL_DESIGN.md`. Essentials:

* **Components:** Postfix 3.8.6, Dovecot 2.3.21, Rspamd 3.8.1 (+Redis), MariaDB 10.11 (Ubuntu 24.04 packages). Maildir under `/var/vmail/<domain>/<user>/Maildir`, one system user `vmail` (uid/gid 5000).
* **Data:** dedicated DB (default `kloxo_mail`): `domains`, `mailboxes` (Argon2id `{ARGON2ID}` hashes), `aliases` + `alias_targets` (forwarders, catch-all `@domain`). Schema: `kloxo/file/mail/schema.sql`.
* **Tool:** `kloxo/bin/mail/kloxo-mail` (commands: `init`, `domain:*`, `mailbox:*`, `alias:*`, `dkim:*`, `dns:records`, `render`, `apply`, `check`). Config in `/etc/kloxo/mail.ini` (0600; override with `KLOXO_MAIL_CONF`). Templates located via `KLOXO_MAIL_TEMPLATES` or `kloxo/file/mail`.
* **Templates:** `kloxo/file/mail/{postfix,dovecot,rspamd,sieve,fail2ban}`. Placeholders `@@NAME@@`. Generated files must not be edited by hand; user customisations go in `/etc/kloxo/mail.d/{postfix-main.cf,postfix-master.cf,dovecot.conf}` (appended).
* **`apply`:** render to temp, back up (`/var/backups/kloxo-mail/<stamp>`), install, validate with `postfix check`, `doveconf -n`, `rspamadm configtest`, `sievec`, and a `postmap -q` probe of each SQL map, reload via `systemctl reload-or-restart`, **roll back** on any failure. `--root=DIR` installs below a fake root (tests); `--no-reload` for containers.
* **Design rules that must not regress:** TLS required for logins; Argon2id; submission requires SASL and `reject_authenticated_sender_login_mismatch`; unknown recipients rejected at SMTP time; quota check (`quota-status`) placed **before** `permit_mynetworks`; real mailbox maps to itself so a catch-all cannot swallow it; `milter_default_action = tempfail`; DKIM driven by generated maps (`/etc/rspamd/kloxo/dkim_{selectors,paths}.map`, this Rspamd version signs nothing without them); Dovecot auth cache flushed by the tool on every password/quota/enable change; `listen = *` when the kernel has no IPv6.

### Verified (in a scratch container, localhost only, no systemd, no IPv6, no internet)

* `tests/mail/run-tests.sh`: **73 checks pass** (validation/injection, all four SQL maps through real `postmap`, config syntax for all three services, Argon2id verified by Dovecot, DKIM/DNS output, apply, rollback, purge safety).
* `tests/mail/e2e.py`: **25 live checks pass** against running Postfix, Dovecot, Rspamd, Redis, MariaDB (inbound delivery, aliases, 550 for unknown recipients, submission on 587/465, DKIM signing, sender-spoof block, wrong password / no-TLS / no-auth refused, IMAPS/POP3S, quota 452 at SMTP, immediate effect of password change and disable).
* Deliberately broken template: `apply` rejected it and restored the live file byte-for-byte.

### NOT verified / not built

Real server with systemd; real DNS/PTR/certificate; outbound delivery and deliverability to Gmail/Outlook; greylisting; postscreen from external clients; ClamAV; fail2ban in action; load/performance; backups. Not built: Roundcube, per-domain TLS (SNI), autoresponders in UI (Sieve vacation available), mailing lists (ezmlm replacement), traffic stats, queue UI, qmail data import, DNS auto-publishing, MTA-STS/TLS-RPT/DANE, AlmaLinux support.

---

## 6. Environment and how to run things

* Development target: Ubuntu 24.04, PHP 8.3 (`php-cli php-mysql`), MariaDB with root via unix socket (Ubuntu default), Postfix + `postfix-mysql` + `postfix-lmdb`, Dovecot (`-imapd -pop3d -lmtpd -mysql -sieve -managesieved`), Rspamd, Redis.
* Tests: see `tests/mail/README.md`. **Run only on a disposable machine/container**: tests create the `vmail` user and the live test rewrites `/etc/postfix`, `/etc/dovecot`, `/etc/rspamd/local.d`.
* The static tests use their own DB (`kloxo_mail_test`) and accounts (`kloxo_mail_test_{rw,ro}`); DB account names follow the DB name so instances never share accounts.
* Lint the legacy tree with `php -l` per file (a loop; parallel xargs output interleaves). Current baseline: 50 failures (section 4).

### Environment lessons from the previous session (avoid repeating)

* Postfix prints a harmless `inet_protocols: disabling IPv6` warning on hosts without IPv6; do not treat it as an error (the tool already filters it).
* Dovecot treats connections from the machine itself as "secure", so plaintext-login refusal cannot be tested locally; it is verified through `doveconf` (`ssl=required`, `disable_plaintext_auth=yes`).
* `pkill -f '<pattern>'` can kill the shell that contains the pattern; use `pgrep -o -x rspamd` style exact matching. Daemons started in a tool/CI shell need `setsid ... </dev/null >log 2>&1` or the step hangs.
* Rspamd file maps are watched; no reload is needed after DKIM map changes.

---

## 7. Prioritised work plan for the next agent

Work in this order. Keep every change small, tested and committed separately. Do not start the PHP port before the security items marked **P0** are done.

### P0: security clean-up (phase 1a), no PHP port needed
1. **DONE.** Committed private keys/certs deleted; `generate-tls-keys` writes unique material. *Accept:* no PEM private-key blocks in the tree; remaining "PRIVATE KEY" strings are UI labels (`desclib.php`) and tests/docs.
2. **DONE.** Setuid helper sources, Makefile `chmod ug+s`, installer `chmod -R ug+s` on `cexe/`, and callers removed. *Accept:* no `chmod ug+s` commands in the tree (qmail `vchkpw` 4755 remains until P1).
3. **DONE.** `process_server_input()` requires the credential envelope and verifies the password before `unserialize`. JSON replacement is documented in `remote_auth_gate.php` and `AUDIT_REPORT.md` (not built). *Accept:* gadget payloads without a valid envelope do not run `__wakeup`.
4. Remove/disable `web-console/`, the easyinstaller feature, and the sudoers rule in `pscript/set-kloxo-php`.
5. Bind the backend daemon to localhost/Unix socket by default; enforce TLS 1.2+; add input size limits. Long term: replace with a small allow-listed privileged agent (Unix socket, JSON, audit log).

### P1: shrink the tree (phase 1b)
Remove Apache, Lighttpd, Varnish, Squid, Apache Traffic Server drivers/templates, unused DNS drivers (keep BIND, optionally PowerDNS), qmail/courier/ezmlm/bogofilter/spamassassin drivers *after* the new mail drivers exist. Keep Nginx and the panel's Hiawatha. Confirm Hiawatha availability on Ubuntu 24.04 (**UNVERIFIED**); if no package, build a pinned binary in the installer.

### P2: PHP 8.3 port (phase 1c)
Add characterization tests first. Port core (`lib/php/lxlib.php`, `lxclass.php`, `lib/sgbl.php`, DB layer to PDO/mysqli), then drivers. Replace PEAR/WebDAV/PHPMailer or drop features. Target: `php -l` clean, then smoke tests. Replace the custom PHP 5.2/5.3 wrapper layer (`lxphp.exe`, `init/php-cgi.sh.base`) with distro PHP-FPM.

### P3: installer, packaging, updater (phase 1d)
Implement `docs/INSTALL_AND_UPDATE_DESIGN.md`: `install.sh` (pre-flight, signed apt repo), Debian packaging (`debian/`), `kloxocp` CLI (`status`, `doctor`, `update`, `rollback`, `component ...`) with snapshot/validate/health-check/rollback. Much of this is new code independent of the legacy PHP, so it can start in parallel with P2. Reuse the pattern already proven in `kloxo-mail apply`.

### P4: connect mail to the panel (phase 1e)
Add thin `*__postfix` drivers (`Mmail__Postfix`, `Mailaccount__Postfix`, `Mailforward__Postfix`, `Servermail__Postfix`...) in `httpdocs/driver/mmail/` that call `kloxo-mail` (via the agent). Keep the existing UI screens. Then add Roundcube, Sieve vacation autoresponder, queue view, per-domain TLS, qmail import. Requires P2.

### P5: later
AlmaLinux/Rocky support via distro map (paths, package and service names); billing extension point; CI matrix on real VMs (fresh install, update from N-1/N-2, forced failure + rollback); logo and branding assets; real project URLs replacing `kloxocp.invalid`; replace the legacy `lxlabs` service account name.

---

## 8. Working rules for the agent

1. **Never weaken security defaults** listed in section 5 or the audit; if a test fails, fix the cause, not the test, unless the test is wrong (explain why).
2. **Do not mass-rename** `lx*` identifiers, DB columns or the `lxlabs` account without tests and an explicit decision.
3. **AGPLv3:** keep license headers and `NOTICE`; add headers to new files; no code with incompatible licenses.
4. **No secrets in the repo** (keys, passwords, tokens). Generate at install time.
5. **New code** is PHP 8.3 with `declare(strict_types=1)`, prepared statements, no shell strings (argument arrays), validated input, tests included.
6. **Config changes to services** must go through render, validate with the service's own checker, atomic install, rollback on failure.
7. **Never claim something works** without running it. Say what was and was not tested. The sandbox had no systemd/IPv6/internet; real-server verification is still owed.
8. Keep `docs/` current; update `REBRAND_CHANGELOG.md` for rebrand-level changes and `AUDIT_REPORT.md` as findings are closed.
9. Prefer deleting dead code over porting it.

---

## 9. Open questions for the owner

1. Is **multi-server** (master/slave) required in v1? Dropping it lets the legacy backend daemon be removed entirely from the first release.
2. Repo/org name, domain, logo, and trademark check for "Kloxo CP".
3. A **test server**: fresh Ubuntu 24.04 VM with root and a domain with controllable DNS (needed to verify install, updates and mail deliverability).
4. Which PHP repository to use for additional PHP versions (Ubuntu ships 8.3 only); to be chosen and pinned.
5. FTP server choice (Pure-FTPd vs ProFTPd) and DNS choice (BIND only, or also PowerDNS).

---

## 10. Suggested first prompts for Cursor

* "Do P0 item 4 only: remove/disable `web-console/`, easyinstaller, and the sudoers rule in `pscript/set-kloxo-php`."
* "Do P0 item 5 only: bind the backend daemon to localhost/Unix socket by default; enforce TLS 1.2+; add input size limits."
* "Start P3: create the `kloxocp` CLI skeleton (status, doctor) with tests, following the pattern of `kloxo/bin/mail/kloxo-mail`."
