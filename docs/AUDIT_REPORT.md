# Kloxo CP: Phase 1 Audit Report

Scope: the rebranded tree derived from Kloxo-MR `dev` (last upstream commit 22 July 2019).
Method: static review only (grep/pattern scans, `php -l` on PHP 8.3.6, reading of the C
backend and request handler). **Nothing was executed on a real server**, so findings are
"confirmed in code" unless marked otherwise. This is a first pass, not a full penetration
test; absence of a finding is not proof of safety.

## 1. Executive summary

| Question | Answer |
|---|---|
| Is the code salvageable? | Yes, as a base for the UI, object model and service templates. |
| Is it safe to run as-is? | **No.** Several critical, remotely reachable flaws (section 3). |
| Does it run on PHP 8.3 / Ubuntu 24.04? | **No.** It targets PHP 5.2/5.3 and CentOS 5-7. |
| Biggest risk | The root-level backend daemon (`cexe/kloxo.c` + `process_server_input`). |
| Biggest effort | Porting ~165k lines of PHP 5-era code, plus replacing the mail stack and installer. |
| Recommended order | Fix/replace the backend and setuid helpers first, port second, features last. |

## 2. Codebase inventory

* 597 PHP files, about 165k lines (the PEAR/WebDAV/ExtJS bundles are about 45k of those lines in `webdisk/` alone).
* Core (`httpdocs/lib` about 51k lines, `driver` about 30k, `theme` about 16k, `lang` 3k, `bin` 7k).
* Entry points: `httpdocs/*.php` (UI), `httpdocs/api`, `httpdocs/web-console`, `bin/*.php`, `sbin/kloxo.php`, C daemon `cexe/kloxo.c`.
* Legacy stacks to remove in phase 1 (size of `file/<dir>`): apache 472K, nginx 276K (keep), lighttpd 160K, hiawatha 96K (keep, panel only), trafficserver 156K, squid 132K, varnish 68K, qmail 284K, maradns 64K, djbdns 40K, nsd 72K, mydns 60K; bind 100K and pdns 96K are candidates to keep.
* Bundled third-party code: PEAR + HTTP_WebDAV (about 1.9 MB), PHPMailer **5.2.19**, ExtJS 2.x (about 2.1 MB), `formmail.php` (2001-2004), webalizer/qmail/courier templates.

## 3. Security findings

Severity is my judgement; "C" = critical, "H" = high, "M" = medium.

### Critical

**C1: Unauthenticated `unserialize()` of network input.**
`httpdocs/lib/html/remotelib.php`, `process_server_input()` (about line 798): when the
request does not start with the "remote char" marker, the payload is
`unserialize(base64_decode($total))` and processed by `do_remote()` **without any
password check**. Combined with the 31 other `unserialize(...base64/\$_REQUEST...)` sites, this
is a PHP object-injection surface reachable from the backend port.
*Fix:* remove PHP serialization from all network protocols; replace with JSON + schema
validation + mandatory authentication before any parsing.

**C2: Backend daemon is an unauthenticated, network-facing code runner.**
`cexe/kloxo.c` binds `INADDR_ANY` with `listen(…, 500000)`, uses `SSLv23_method()`
(legacy protocols allowed), and has `verify_client = OFF` hard-coded (lines 166, 255),
so no client certificate is required. It writes the received bytes to a temp file and
executes `lxphp.exe bin/common/process_single.php` with it. Memory-safety issues: unbounded
`strcpy/sprintf/strlen` on `data`, unchecked `malloc`, no maximum message size
(memory DoS), uninitialised `totaln`, `mkstemp()` result ignored (fd leak and a file name
race), and `printf("Input %d %s")` logging full payloads (which include passwords).
*Fix:* bind to localhost or a Unix socket by default; enforce mutual TLS (TLS 1.2+) for
multi-server mode; add size limits; rewrite the daemon in a memory-safe language or as a
small, audited privileged agent with a fixed command allow-list.

**C3: Private keys committed to the repository. CLOSED.**
Were: `init/backend.key`, `file/ssl/rootCA.key`, `file/ssl/default.key` (and matching certs),
plus unused `theme/filecore/p.c` (a key) / `p.k` (its cert). Every installation would have
shared the same keys, so TLS to the backend can be impersonated by anyone who has a copy.
*Closed by:* deleting those files; `kloxo/bin/install/generate-tls-keys` writes unique
self-signed RSA-2048 material per install; `.gitignore` covers the generated paths.
Treat any copy of the old keys as compromised. Weak `dhparam_512`/`1024` templates were
also deleted; 2048/4096 DH parameters (public, not secrets) remain.

**C4: Setuid helpers executing attacker-controlled commands. CLOSED.**
Were: `cexe/_Makefile` `chmod ug+s` on `closeinput`, `closeallinput` and `kloxo.exe`.
`closeinput.c` did `system(argv[1])`; `closeallinput.c` concatenated `argv` into
`char cmd[500]` with `strcat` (stack overflow) and called `system()`. The legacy installer
also ran `chmod -R ug+s` on `cexe/`. `lxmisc.c` was an unused setuid-style wrapper
(comment in the file from 2010; `do_exec_system` never actually invoked it).
*Closed by:* deleting the three sources; Makefile builds `kloxo.exe` mode 0755 only;
installer no longer sets the setuid bit; `exec_with_all_closed*` runs without a helper.
Qmail `chmod 4755 …/vchkpw` in `pscript/fixmail-all` remains until P1 removes qmail.

**C5: Remote code download over plain HTTP.**
`lib/programlib.php`, `bin/easyinstaller-update.php`, `lib/php/lxlib.php`, `lib/html/lib.php`
fetch lists/zips with `wget http://…` / `curl` from `download.lxcenter.org` and unpack/execute them.
The domain is no longer under project control, so this is a supply-chain risk.
*Mitigation already applied:* all those hosts were replaced by `*.kloxocp.invalid`
(non-resolvable). *Fix:* remove the "easyinstaller" feature or re-implement it with HTTPS and
signature verification.

### High

* **H1: 787 shell-execution call sites** (`system/exec/shell_exec/passthru/popen/proc_open`)
  and 379 `lxshell_*` wrapper calls. Each needs checking for argument escaping; the pattern
  of building shell strings with user-supplied names (domains, usernames, paths) is the
  classic route to command injection.
* **H2: 55 `eval()` calls and 25 `extract()` calls**; 23 variable-path `include/require`.
* **H3: `httpdocs/web-console/`**: an in-browser command shell with its own account list
  and hashing. Remove, or gate behind admin-only, TLS-only, audited access.
* **H4: Weak credential handling.** 35 `md5()` and 35 `crypt()` uses; the admin password is
  created with MD5-crypt (`crypt($pass, '$1$…')`) in `lib/html/initlib.php`. Only 4
  `password_hash` uses. *Fix:* argon2id/bcrypt via `password_hash`, with rehash on login.
* **H5: Transport security.** 12 places disable certificate verification (`CURLOPT_SSL_VERIFY*`),
  28 `wget/curl http://` calls, `MinTLSversion = TLS1.0` in `init/hiawatha.conf.base`.
* **H6: Sudoers rule** written by `pscript/set-kloxo-php`:
  `lxlabs ALL=(ALL:ALL) NOPASSWD:/opt/kloxo`. Written to `/etc/sudoers.d/mount`. The
  command spec is a directory, which makes the intent unclear; any passwordless sudo for the
  web-facing account is dangerous. Remove and replace with the agent design.
* **H7: Outdated bundled code.** PHPMailer 5.2.19 (EOL, known CVEs fixed in later
  releases), PEAR/WebDAV (do not even parse on PHP 8), `formmail.php` (2001-2004, a historic spam/injection
  target), ExtJS 2.x. Replace or drop each.

### Medium

* **M1:** 9 `chmod 777/a+rwx` occurrences.
* **M2:** 262 direct `\$_GET/_POST/_REQUEST/_COOKIE/_SERVER/_FILES` uses; no central
  input validation layer observed.
* **M3:** debug mode (`\$sgbl->dbg > 0`) sets the default admin password to the literal
  `lxlabs` and a default contact address of the old project (`initlib.php`). Make sure
  debug mode can never be enabled on a release build; remove the literal.
* **M4:** The setup relies on MySQL `root` credentials stored in files under the program
  directory and on `/home/kloxo` ownership assumptions; needs a least-privilege review.
* **M5:** Hiawatha settings for the panel (`BanOnGarbage`, `ChallengeClient`) are reasonable
  but `ConnectionsTotal = 2048` / `ConnectionsPerIP = 64` and TLS minimums should be revisited.

## 4. PHP 8.3 compatibility

`php -l` (PHP 8.3.6) on 621 PHP files: **50 files fail to compile**.
* 42 are third-party/example code (`webdisk/PEAR*`, `HTTP/WebDAV`, `lib/urlrewrite/example*`, `file/formmail`).
* 8 are first-party or bundled: `lib/php/lxclass.php:2425` (curly-brace string offset),
  `lib/php/common.inc:8` (`__autoload`), `lib/html/ippoollib.php:116`, `api/api.php:36`,
  `lib/demologins.php:38`, `bin/common/iptraffic.php:74`, `driver/web/easyinstaller__linuxlib.php:82`,
  `lib/extra/PHPMailerAutoload.php:45`.

`php -l` only finds compile-time errors. Pattern scan of removed or deprecated features
(runtime failures on 8.x):

| Feature | Occurrences | Status in PHP 8 |
|---|---|---|
| `mysql_*()` functions | 59 | removed in 7.0 |
| `split()` | 59 | removed in 7.0 |
| `ereg*()` / `eregi_replace()` | 29 | removed in 7.0 |
| `each()` | 27 | removed in 8.0 |
| `create_function()` | 6 | removed in 8.0 |
| `get_magic_quotes_*`, `set_magic_quotes_*`, `magic_quotes` | about 80 | removed |
| `$HTTP_*_VARS` | 10 | removed |
| `preg_replace(.../e)` | 2 | removed in 7.0 |
| `strftime()` | 9 | deprecated in 8.1 |
| `utf8_encode/decode` | 4 | deprecated in 8.2 |
| `ioncube` references | 42 | loader dependency, none for PHP 8.3 build here |
| `safe_mode` checks | 23 | removed in 5.4 |

The panel runs its own PHP through a wrapper (`lxphp.exe`, `init/php-cgi.sh.base`, PHP
5.2/5.3 dual-PHP logic). That layer must be rebuilt for the distro PHP 8.3 and PHP-FPM.
Expect also silent behaviour changes (string/number comparison, undefined variable and
array-key notices becoming warnings/errors, `count()` on non-countables). The code base has no tests, so a
characterization-test harness is the first port step.

## 5. Platform and architecture findings

* **OS coupling:** `install/`, `pscript/`, `file/driver/linux.inc` are RPM/yum/chkconfig/`service`
  based; init scripts are SysV (`init/*.init`) with systemd units as an afterthought.
* **Web stack:** Apache, Lighttpd, Hiawatha (as site server), Varnish, Squid, ATS drivers and
  their templates make up a large share of `driver/web` and `file/`. Decision: customer sites
  Nginx only; remove the rest.
* **Panel web server (Hiawatha), kept as requested:** configured through
  `init/hiawatha.conf.base`, `init/kloxo-web.init`, `file/hiawatha/etc/conf/hiawatha_*.conf`; it
  talks to PHP over a FastCGI Unix socket (`/opt/kloxo/init/kloxo-phpcgi.sock`). Hiawatha is
  actively used by this design but its availability as a packaged binary on Ubuntu 24.04 is
  **not confirmed**; I could not verify it in my sandbox. Plan for a pinned build from source in the
  installer if the package is missing. Panel-only use keeps the migration small, but the
  CGI/PHP5 specifics in `hiawatha.conf.base` (`CGIhandler`, `PHP5` FastCGI id, TLS 1.0) must be rewritten.
* **Mail:** the legacy qmail-toaster stack (+ courier, vpopmail, SpamAssassin, bogofilter, ezmlm) is replaced by Postfix + Dovecot + Rspamd; the new stack is implemented and tested, see `docs/MAIL_DESIGN.md`. Connecting it to the legacy UI drivers (`driver/mmail`) is still open.
* **DNS drivers:** bind, djbdns, nsd, maradns, mydns, pdns, yadifa. Keep bind (and optionally pdns).
* **Billing:** no billing module is bundled. The only traces were `pscript/setup-tht` (a TheHostingTool installer, **removed**) and a "fix for WHMCS needing pserver" workaround in the command-line API (`lib/html/commandlinelib.php`), which is left in place as part of the generic API. A clean billing extension point can be added later.
* **Distro abstraction:** paths (`/etc/httpd`, `/var/named`, `/etc/init.d`...), package names
  and service names are hard-coded. Needs a per-distro map.
* **Framework:** custom object model (`lxclass`, `lxdb` with PHP-serialized columns, per-object
  `*lib.php`/`*__synclib.php` drivers). Serialized columns in SQL make schema renames risky.

## 6. Roadmap

| Phase | Work | Exit criteria |
|---|---|---|
| 0 (done) | Rebrand, neutralise dead endpoints, remove stale binaries, audit | This ZIP |
| 1a | Delete `closeinput`/`closeallinput` (C4 done); remove committed keys (C3 done); remaining: sudoers rule; `web-console`, easyinstaller; unauthenticated unserialize path; bind backend to localhost | C1, C2, C5 still open |
| 1b | Remove Apache/Lighttpd/Varnish/Squid/ATS/Hiawatha-site drivers and templates; keep Nginx + panel Hiawatha | Smaller tree, tests for Nginx templates |
| 1c | PHP 8.3 port (core first: `lxlib`, `lxclass`, `sgbl`, DB layer to PDO/mysqli), replace PEAR/WebDAV/PHPMailer | `php -l` clean, smoke tests pass |
| 1d | New Ubuntu 24.04 installer (apt, systemd, ufw), OS abstraction layer, unique keys per install | Fresh VM install boots the panel on HTTPS |
| 1e | Mail stack: Postfix + Dovecot + Rspamd driver | Create mailbox, send and receive |
| 2 | Backend agent redesign (JSON over Unix socket, allow-listed actions), password hashing migration, test suite and CI | Audit re-run |
| 3 | AlmaLinux/Rocky 9 support; billing extension point; new branding assets | |

## 7. What I need from you for the next step

1. Confirm priority: security clean-up (1a) first, as recommended.
2. A fresh Ubuntu 24.04 VM (root, 2-4 GB RAM) so that I can give you tested install steps and you can report errors back.
3. Whether to keep multi-server (master/slave) in v1; dropping it would let the backend daemon be removed from the first release.
4. A logo and domain/GitHub name when you have them.
