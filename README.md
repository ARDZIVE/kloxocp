# Kloxo CP

A web hosting control panel, derived from **Kloxo-MR** (itself a fork of **Kloxo** by LxLabs / LxCenter).

> **Status: 0.1.0-dev, NOT FOR PRODUCTION.**
> This is the rebranded starting point of the fork. It does not install or run on
> Ubuntu 24.04 yet. Read `docs/AUDIT_REPORT.md` (security and PHP 8 audit),
> `docs/INSTALL_AND_UPDATE_DESIGN.md` (installation, updates, stability) and
> `docs/MAIL_DESIGN.md` (the new mail stack, which already works and is tested).

## Goals

| Item | Decision |
|---|---|
| Install | One command, under 10 minutes on a clean server |
| Updates | One command or one button for the panel and every managed component, with automatic backup, health check and rollback |
| Target OS | Ubuntu 24.04 LTS first; AlmaLinux/Rocky 9 later via an OS abstraction layer |
| Customer websites | Nginx + PHP-FPM (multiple PHP versions side by side) |
| Mail | Postfix + Dovecot + Rspamd, mailboxes in MariaDB (see `docs/MAIL_DESIGN.md`) |
| Panel's own web server | Hiawatha (kept, as in Kloxo-MR) |
| Billing integrations | None for now (extension point later) |
| UI | Existing UI kept |
| License | GNU AGPL v3 (unchanged) |

## Layout

```
kloxo/            panel source; installs to /opt/kloxo
  httpdocs/       web UI, libraries, drivers
  bin/ sbin/ pscript/   backend and maintenance scripts
  cexe/           C backend daemon (source only; setuid helpers removed)
  init/           panel service / Hiawatha / PHP-FPM templates
  file/           service config templates (web, dns, mail, ftp...)
  install/        LEGACY RPM/CentOS installer (to be replaced)
  bin/mail/kloxo-mail    NEW: mail provisioning tool (PHP 8.3, standalone)
  bin/install/generate-tls-keys  NEW: unique TLS keys per install (PHP 8.3)
  file/mail/             NEW: Postfix, Dovecot, Rspamd templates and the mail DB schema
tests/mail/       NEW: 73 static + 25 live end-to-end mail tests
tests/tls/        NEW: generate-tls-keys checks
docs/             audit, design, mail stack, changelog, legacy notes
tools/            rebrand_to_kloxocp.py (reproducible rebrand step)
NOTICE            origin and attribution
```

## Licensing and naming

Free software under the **GNU Affero General Public License v3** (`LICENSE.txt`).
If you run a modified version as a network service you must offer its source to its users.
Upstream copyright notices are preserved; see `NOTICE`. "Kloxo" is the name of the upstream
projects: this fork is **not affiliated with or endorsed by** LxCenter or MRatWork.
