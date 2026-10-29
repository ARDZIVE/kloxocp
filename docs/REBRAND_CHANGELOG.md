# Rebrand changelog: Kloxo-MR 7.0.0.c to Kloxo CP 0.1.0-dev

Base: mustafaramadhan/kloxo, branch `dev`, commit `e0dfc28a15663fc3c682ee55c35d41f4032633f4`
(22 July 2019). Applied in two scripted steps (an intermediate name was tried and dropped).
`tools/rebrand_to_kloxocp.py` is the second step; the end state is what you see in this tree.

## What changed versus upstream Kloxo-MR

| Upstream | Kloxo CP | Notes |
|---|---|---|
| `Kloxo-MR`, `Kloxo MR` (display text) | `Kloxo CP` | |
| `/usr/local/lxlabs/kloxo` | `/opt/kloxo` | program root |
| `/usr/local/lxlabs/ext` | `/opt/kloxo/ext` | |
| `/home/lxadmin` | `/home/kloxomail` | mail home |
| `/tmp/lxlabs_backend*` | `/tmp/kloxo_backend*` | |
| `~lxcenter.conf` | `~kloxo.conf` | custom web-server include |
| `download/www/wiki/forum.lxcenter.org`, `mratwork.com` URLs | `kloxocp.invalid` | dead upstream endpoints replaced by a non-resolvable placeholder (RFC 2606); real project URLs to be set later |
| Hiawatha `ServerString` | `Hiawatha_for_KloxoCP` | |

Identifiers that were already `kloxo` (services, DB name, `/home/kloxo`, file names) stay
`kloxo`. Path layout was flattened (`<base>/kloxo` became `/opt/kloxo`) and the matching
fixes were made in `install/installer.php`, `install/step2.inc` and `lib/sgbl.php`.

## Deliberately NOT changed (tracked for later)

1. Internal identifiers: `lx*` functions/classes, DB tables/columns (`lxguard*`, `ser_lxadminconfig_b`).
2. The service account `lxlabs` / `lxlabsslave`: rename via one constant once tests exist.
3. Version string `bin/kloxoversion` (`7.0.0.c-2019072201`); a Kloxo CP version scheme comes with packaging.
4. The legacy RPM/yum installer (`install/`, parts of `pscript/`): reference only, to be replaced.
5. All copyright and authorship lines (LxLabs, LxCenter, Mustafa Ramadhan, MRatWork).

## Removed from the tree

* `cexe/closeinput`, `cexe/closeallinput`, `cexe/lxmisc`: prebuilt 32-bit ELF binaries from 2019
  (two are setuid helpers, see audit C4). Sources remain.
* `pscript/setup-tht`: TheHostingTool billing installer (billing is out of scope for now).
* `kloxo-mr_big.png`: upstream logo. A new Kloxo CP logo is needed.
* `how-to-install.txt` moved to `docs/legacy/` (RHEL/CentOS steps, obsolete).

## Added after the rebrand (new code, not from upstream)

* `kloxo/bin/mail/kloxo-mail`: mail provisioning tool (PHP 8.3).
* `kloxo/file/mail/`: Postfix, Dovecot, Rspamd templates, Sieve rule, fail2ban jails, mail DB schema.
* `tests/mail/`: 73 static checks and 25 live end-to-end checks.
* `docs/INSTALL_AND_UPDATE_DESIGN.md`, `docs/MAIL_DESIGN.md`, `docs/AUDIT_REPORT.md`.
* `kloxo/bin/install/generate-tls-keys`: unique TLS keys per install (PHP 8.3); `tests/tls/`.
* `kloxo/httpdocs/lib/html/remote_auth_gate.php`: auth before unserialize of backend payloads (audit C1); `tests/remote/`.
* web-console shell deleted; Easy Installer disabled; sudoers NOPASSWD removed; backend binds localhost with TLS 1.2+ and a 1 MiB cap; `tests/security/`.
* Committed private keys/certs removed (`init/backend.*`, `file/ssl/default.*`, `file/ssl/rootCA.*`,
  `theme/filecore/p.c`/`p.k`); setuid helper sources `cexe/closeinput.c`, `closeallinput.c`,
  `lxmisc.c` deleted.
