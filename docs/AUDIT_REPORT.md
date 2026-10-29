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

* 555 PHP files after P1 deletions (was 597), about 165k lines remaining (the PEAR/WebDAV/ExtJS bundles are about 45k of those lines in `webdisk/` alone).
* Core (`httpdocs/lib` about 51k lines, `driver` about 30k, `theme` about 16k, `lang` 3k, `bin` 7k).
* Entry points: `httpdocs/*.php` (UI), `httpdocs/api`, `httpdocs/web-console`, `bin/*.php`, `sbin/kloxo.php`, C daemon `cexe/kloxo.c`.
* Legacy stacks: Apache/Lighttpd/Varnish/Squid/ATS/Hiawatha-site and unused DNS template trees **removed** in P1. Remaining `file/<dir>` sizes: nginx 236K, bind 76K, pdns 76K, qmail 152K (kept until postfix UI drivers). Panel Hiawatha config is `init/` only (96K for the whole init dir).
* Bundled third-party code: PEAR + HTTP_WebDAV (about 1.9 MB), PHPMailer **5.2.19**, ExtJS 2.x (about 2.1 MB), `formmail.php` (2001-2004), webalizer/qmail/courier templates.

## 3. Security findings

Severity is my judgement; "C" = critical, "H" = high, "M" = medium.

### Critical

**C1: Unauthenticated `unserialize()` of network input. CLOSED for `process_server_input()`.**
Was: `httpdocs/lib/html/remotelib.php`, `process_server_input()`: when the request did not
start with the remote-char marker (`_._`), the payload was
`unserialize(base64_decode($total))` and processed by `do_remote()` **without any
password check**. Localhost used that path on purpose (password lived *inside* the
serialized object, so it could not be checked first). Combined with about 31 other
`unserialize(...base64/\$_REQUEST...)` sites, this was a PHP object-injection surface
reachable from the backend port.
*Closed by:* `kloxo/httpdocs/lib/html/remote_auth_gate.php` — parse the `_._` / user / pass
envelope as strings, verify the password, and only then allow the caller to unserialize
the payload. `do_remote_exec` always attaches that envelope, including for localhost.
Regression: `tests/remote/` (object-injection gadget is not woken without credentials).
*Still open:* other unserialize-on-untrusted-input sites, including `process_in_master()`
and `remote_main()` (`frm_rmt`). Those are not this path.
*JSON replacement (planned, not built):* drop PHP serialization from the backend protocol.
Payload becomes a versioned JSON object with a schema (action, class, nname, argument list);
validate before dispatch. Transport: Unix socket for same-host (P0.5), mutual TLS only if
multi-server stays in scope. Do not add further `unserialize()` of network input.

**C2: Backend daemon is an unauthenticated, network-facing code runner. CLOSED for P0.5 defaults.**
Was: `cexe/kloxo.c` bound `INADDR_ANY` with `listen(…, 500000)`, used `SSLv23_method()`,
`verify_client = OFF`, unbounded receive, ignored `mkstemp()`, logged full payloads.
*Closed by:* bind `INADDR_LOOPBACK`, listen backlog 128, `TLS_server_method` with TLS 1.2
minimum, 1 MiB cap, `realloc` receive path, `mkstemp` checked, payload not logged. PHP
`createSslStream()` binds `127.0.0.1` and requires `default.pem`. Missing keys fail closed.
*Still open (later):* the daemon still executes PHP on received data; replace with an
allow-listed privileged agent. Multi-server bind is an explicit later choice.

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
Qmail `chmod 4755 …/vchkpw` in `pscript/fixmail-all` remains until qmail is removed.

**C5: Remote code download over plain HTTP. CLOSED.**
Was: Easy Installer and related helpers fetched lists/zips with `wget http://` / `curl` from
hosts the project does not control, then unpacked them.
*Closed by:* `easyinstaller-update.php` / `easyinstaller-upload.php` / `pscript/easyinstaller-update`
exit without downloading; `easyinstaller__linux::dbactionAdd/Update` throw; `easyinstaller_data_update()`
is a no-op; `download_file()` refuses `http://`; version and wordlist HTTP fetches disabled;
`kloxo/etc/flag/disableeasyinstaller.flg` is shipped. UI classes remain until a later tree shrink.

### High

* **H1: 787 shell-execution call sites** (`system/exec/shell_exec/passthru/popen/proc_open`)
  and 379 `lxshell_*` wrapper calls. Each needs checking for argument escaping; the pattern
  of building shell strings with user-supplied names (domains, usernames, paths) is the
  classic route to command injection.
* **H2: 55 `eval()` calls and 25 `extract()` calls**; 23 variable-path `include/require`.
* **H3: `httpdocs/web-console/` CLOSED.** The shell (`web-console.php`) is deleted; `index.php`
  is a 403 stub that does not unserialize. The SSH-client iframe no longer loads it.
* **H4: Weak credential handling.** 35 `md5()` and 35 `crypt()` uses; the admin password is
  created with MD5-crypt (`crypt($pass, '$1$…')`) in `lib/html/initlib.php`. Only 4
  `password_hash` uses. *Fix:* argon2id/bcrypt via `password_hash`, with rehash on login.
* **H5: Transport security.** 12 places disable certificate verification (`CURLOPT_SSL_VERIFY*`),
  28 `wget/curl http://` calls, `MinTLSversion = TLS1.0` in `init/hiawatha.conf.base`.
* **H6: Sudoers rule CLOSED.** `pscript/set-kloxo-php` no longer writes
  `lxlabs ALL=(ALL:ALL) NOPASSWD:/opt/kloxo` to `/etc/sudoers.d/mount`. Existing installs
  that already have that file must delete it by hand.
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

`php -l` on first-party code (499 `.php`/`.inc` files, excluding `webdisk/`, `file/formmail/`,
urlrewrite examples): **clean** after the P2 first slice (PHP 8.5.2 in this workspace).
Third-party/example trees still fail (`webdisk/PEAR*`, `HTTP/WebDAV`, `lib/urlrewrite/example*`,
`file/formmail`).
*Closed first-party parse errors:* `lib/php/lxclass.php` curly property, `lib/php/common.inc`
`__autoload`, `lib/html/ippoollib.php` missing `$`, `api/api.php` unclosed function,
`lib/demologins.php` invalid `<?= {$v[0]} ?>`, `bin/common/iptraffic.php` broken interpolation,
`driver/web/easyinstaller__linuxlib.php` missing quote, `lib/extra/PHPMailerAutoload.php`
`__autoload`. Regression: `tests/p2/`.

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
* **Web stack (P1 done):** selectable site drivers are `none` and `nginx`; webcache is `none`.
  Apache, Lighttpd, Varnish, Squid, ATS, Hiawatha-as-site-server, Monkey and OpenLiteSpeed
  drivers and `file/` templates are gone. `lib.php` still has dead Apache install/chown
  branches used by the legacy installer (P3).
* **Panel web server (Hiawatha), kept as requested:** configured through
  `init/hiawatha.conf.base`, `init/kloxo-web.init` (site templates under `file/hiawatha` were
  removed). It talks to PHP over a FastCGI Unix socket (`/opt/kloxo/init/kloxo-phpcgi.sock`).
  Hiawatha availability as a packaged binary on Ubuntu 24.04 is **not confirmed**. Plan for a
  pinned build from source in the installer if the package is missing. The CGI/PHP5 specifics
  in `hiawatha.conf.base` (`CGIhandler`, `PHP5` FastCGI id, TLS 1.0) must be rewritten.
* **Mail:** the legacy qmail-toaster stack (+ courier, vpopmail, SpamAssassin, bogofilter, ezmlm) is replaced by Postfix + Dovecot + Rspamd; the new stack is implemented and tested, see `docs/MAIL_DESIGN.md`. Connecting it to the legacy UI drivers (`driver/mmail`) is still open.
* **DNS drivers (P1 done):** selectable `none`, `bind`, `pdns`. djbdns/nsd/mydns/yadifa/maradns drivers and templates removed.
* **Billing:** no billing module is bundled. The only traces were `pscript/setup-tht` (a TheHostingTool installer, **removed**) and a "fix for WHMCS needing pserver" workaround in the command-line API (`lib/html/commandlinelib.php`), which is left in place as part of the generic API. A clean billing extension point can be added later.
* **Distro abstraction:** paths (`/etc/httpd`, `/var/named`, `/etc/init.d`...), package names
  and service names are hard-coded. Needs a per-distro map.
* **Framework:** custom object model (`lxclass`, `lxdb` with PHP-serialized columns, per-object
  `*lib.php`/`*__synclib.php` drivers). Serialized columns in SQL make schema renames risky.

## 6. Roadmap

| Phase | Work | Exit criteria |
|---|---|---|
| 0 (done) | Rebrand, neutralise dead endpoints, remove stale binaries, audit | This ZIP |
| 1a (done) | C1–C5 / H3 / H6 for phase 1a: keys, setuid helpers, auth-before-unserialize, web-console, easyinstaller HTTP, sudoers, localhost bind, TLS 1.2+, size limits | Remaining: PHP 8.3 port, installer, agent rewrite, qmail removal |
| 1b (web/DNS done) | Remove Apache/Lighttpd/Varnish/Squid/ATS/Hiawatha-site/unused-DNS drivers and templates; keep Nginx + panel Hiawatha + BIND/PowerDNS | `tests/p1/` 31 checks. qmail still present |
| 1c (parse slice done) | PHP 8.3 port: first-party `php -l` clean; autoload; boot-path ereg; `Sqlite` already mysqli | `tests/p2/`. Remaining: panel boot, PEAR/PHPMailer, `lxphp.exe` |
| 1d (CLI skeleton done) | `kloxocp` status/doctor/snapshot/rollback + `install.sh` pre-flight. Remaining: apt repo, Debian packages, `kloxocp update` | `tests/p3/` 20 checks. Fresh VM install still UNVERIFIED |
| 1e | Mail stack: Postfix + Dovecot + Rspamd driver | Create mailbox, send and receive |
| 2 | Backend agent redesign (JSON over Unix socket, allow-listed actions), password hashing migration, test suite and CI | Audit re-run |
| 3 | AlmaLinux/Rocky 9 support; billing extension point; new branding assets | |

## 7. What I need from you for the next step

1. P2 first-party parse and `include.php` boot are done. P3 CLI skeleton (`kloxocp` status/doctor/snapshot/rollback) is done; apt packaging and `kloxocp update` are not. Next: remaining third-party runtime APIs, Debian packaging, or P4 postfix UI. Browser login is unverified. qmail stays until P4.
2. A fresh Ubuntu 24.04 VM (root, 2-4 GB RAM) so that I can give you tested install steps and you can report errors back.
3. Whether to keep multi-server (master/slave) in v1; dropping it would let the backend daemon be removed from the first release.
4. A logo and domain/GitHub name when you have them.
