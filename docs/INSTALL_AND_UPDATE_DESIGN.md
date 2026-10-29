# Kloxo CP: Install, Update and Stability Design

Status: design for the work that follows the audit. The `kloxocp` CLI skeleton
(`status`, `doctor`, `snapshot`, `rollback`, `component list`) is in
`kloxo/bin/kloxocp` with tests in `tests/p3/`. `install.sh` is pre-flight only
(`kloxocp install` → `doctor --install`, then exit 2). Apt packages, signed repo,
and `kloxocp update` are **not built**. Unique TLS key generation
(`kloxo/bin/install/generate-tls-keys`) exists and is tested; the installer should
call it on first run.

## 0. Honest framing

"Most stable" and "most viable" are results, not features. They come from tests, small scope,
disciplined releases and years of real use. This document turns them into things we can
build and measure. Updates can be made very easy and safe for patch and minor versions.
Major jumps (MariaDB major version, Ubuntu release upgrade, a PHP major with breaking code)
can be made guided and reversible, but never risk-free.

Core principle: **do not reinvent package management.** Use signed `apt` packages for the
panel and for every managed component, and let the panel orchestrate them with backups,
validation and rollback.

## 1. Installation (target: one command, under 10 minutes)

```
wget https://<project-domain>/install.sh        # small, readable script
sha256sum -c install.sh.sha256                   # published checksum / signature
sudo bash install.sh                             # or: --profile minimal|full|no-mail
```

The script only does five things:
1. **Pre-flight checks:** Ubuntu 24.04, root, 2 GB RAM and 10 GB disk minimum, FQDN hostname,
   ports 80/443/8443 free, no other panel (cPanel, Plesk, ISPConfig...) installed.
2. Add the **signed Kloxo CP apt repository** (GPG key pinned).
3. `apt install kloxo-cp` (meta-package that depends on the core plus the chosen profile:
   nginx, php-fpm, mariadb-server, bind9, an FTP server, postfix/dovecot/rspamd, certbot, ufw/fail2ban).
4. First-run setup: unique TLS keys via `kloxo/bin/install/generate-tls-keys` (never shipped in the package), Let's Encrypt for the panel
   hostname, firewall rules, a one-time admin login link.
5. Print the panel URL and the result of the **self-check** (every service up, test vhost renders and validates).

Requirements: idempotent (safe to re-run), non-interactive mode for automation (`--yes`, config file),
full log in `/var/log/kloxo-install.log`, and a failed install must leave the server clean or resumable.

## 2. Updating (one command or one button)

```
kloxocp update              # panel + all managed components, with safety steps
kloxocp update --check      # show what would change
kloxocp rollback            # back to the last snapshot
kloxocp component list
kloxocp component upgrade mariadb
kloxocp component install php 8.4
```

The UI "Update" button calls exactly the same code path as the CLI.

### 2.1 What every update does

1. **Pre-flight:** free disk, no running backup/restore, panel DB healthy, target version compatible.
2. **Snapshot:** dump of the panel DB, copy of generated configs and of `/opt/kloxo`; optional LVM/ZFS/Btrfs
   snapshot when the host supports it. Keep the last N.
3. **Upgrade packages** from the chosen channel (`stable` default, `testing` opt-in).
4. **Run migrations:** versioned, idempotent scripts for the panel DB schema and for config templates.
5. **Regenerate and validate** every managed config *before* reload: `nginx -t`, `php-fpm -t`,
   `named-checkconf` / `named-checkzone`, `postfix check`, `doveconf -n`, FTP config test.
6. **Reload gracefully**, then **health check** (HTTP probe of the panel and a test site, service status, test mailbox login).
7. **Automatic rollback** if any check fails: restore previous package versions and the snapshot; report why.

### 2.2 Customisations survive updates

Panel-generated files live in clearly marked directories; user changes go in `custom.d/` includes
(`nginx`, `php`, `named`...) that the panel never overwrites. Templates are versioned, and a diff is shown when a
template changes under a customised site.

### 2.3 Component manager (SQL, FTP, BIND, PHP, web, mail)

| Component | Source | Minor/security updates | Major upgrades |
|---|---|---|---|
| Kloxo CP | Kloxo CP apt repo | `kloxocp update` | semver, migrations, rollback |
| PHP (several versions side by side, per-site) | Ubuntu + a maintained PHP repository (to be chosen and pinned) | automatic security updates optional | install new version beside old; per-site switch; the old one is removed only when no site uses it; a compatibility scan runs first |
| MariaDB | Ubuntu or the vendor repo | `apt` plus `mariadb-upgrade` handled by the tool | guided: full dump, upgrade, verify, rollback path |
| BIND | Ubuntu | `apt` | rare; zones verified with `named-checkzone` before reload |
| FTP (Pure-FTPd or ProFTPd) | Ubuntu | `apt` | config regenerated and tested |
| Nginx | Ubuntu or nginx.org | `apt` | templates validated with `nginx -t` |
| Mail (Postfix, Dovecot, Rspamd) | Ubuntu | `apt`, then `kloxo-mail apply` (validate, reload, roll back) | guided, with mail-queue and mailbox backups; see `MAIL_DESIGN.md` |

Rule: the panel never edits a service config it cannot validate, and never reloads a service whose test failed.

Out of scope for v1: automatic Ubuntu release upgrades (e.g. 24.04 to the next LTS). A documented, tested migration path will come later.

### 2.4 What the current `kloxocp` implements

The CLI in `kloxo/bin/kloxocp` is PHP 8.3, `declare(strict_types=1)`, argument-array
`proc_open`, validated `--root` / `--snap-dir` (reject NUL and `..`).

| Command | Behaviour today |
|---|---|
| `status` | Program root, `kloxoversion`, PHP, TLS file presence, login page, `systemctl is-active` when available |
| `doctor` | Layout, PHP ≥ 8.3, login page, TLS mode, Ubuntu 24.04 (warn unless `--install`), required binaries. `--install` also requires root and rejects cPanel/Plesk/ISPConfig |
| `snapshot` | Copies `bin/kloxoversion`, `init`, `etc`, `file/ssl`, `httpdocs/login` into `<snap-dir>/<UTC stamp>/` plus `MANIFEST.json`. Keeps last `--keep` (default 5) |
| `rollback` | Restores those paths from `--stamp` or the latest stamp |
| `component list` | Names from the design table |
| `update`, `component upgrade`, apt `install` | Exit 2 |

Snapshot does **not** dump the panel DB, generated service configs, or take LVM/ZFS snapshots. Treat `--snap-dir` as secret (it can hold copies of `file/ssl`).

## 3. Stability plan

* **Small scope first.** Ubuntu 24.04 only, Nginx only, one DNS, one FTP, one mail stack. Remove Apache, Lighttpd, Varnish, Squid, ATS and extra DNS drivers.
* **No privileged network daemon.** Replace `cexe/kloxo.c`-style backend with a small local privileged agent (Unix socket, fixed allow-list of actions, JSON, audit log).
* **Tests before features.** Characterization tests around the existing PHP before porting; then CI on every commit.
* **CI matrix (GitHub Actions + real VMs/containers):** fresh install; update from N-1 and N-2; forced failure then rollback; create site, DB, mailbox, DNS zone, FTP user; renew certificate; backup and restore.
* **Static analysis in CI:** PHPStan/Psalm, `php -l` on 8.3, shellcheck, semgrep security rules, dependency checks for bundled code.
* **Release discipline:** semver; `testing` channel soaks before `stable`; signed packages; changelog; security-advisory process; a supported-versions policy.
* **Observability:** `kloxocp status` and `kloxocp doctor`, structured logs, optional anonymised error reports (opt-in).

## 4. Measurable targets (how we know it is "stable")

| Target | Measure |
|---|---|
| Install works | at least 99% success on fresh supported images in CI, every release |
| Update works | 100% of N-1 to N updates pass in CI; failures roll back automatically |
| Security | zero known critical/high findings at release; audit repeated each minor release |
| Recovery | restore from snapshot tested in CI |
| Upgrade effort | one command; no manual config edits for patch/minor releases |

## 5. Build order

1. Security clean-up from the audit (section 6 of `AUDIT_REPORT.md`, phase 1a).
2. Remove unused stacks and legacy installer parts.
3. `kloxocp` CLI skeleton (status, doctor, update, rollback) and the snapshot/validate/rollback engine, which can be built and tested independent of the PHP port. **Partial:** status/doctor/snapshot/rollback of allow-listed panel paths exist; update and package-level rollback do not.
4. Debian packaging (`debian/` directory) and a private apt repo.
5. PHP 8.3 port with characterization tests.
6. New installer on top of the packages; CI matrix on real Ubuntu 24.04 VMs.
7. Component manager, then mail stack, then AlmaLinux/Rocky support.

Steps 3 and 4 are good candidates to start next because they are largely new code and do not depend on the legacy PHP.
