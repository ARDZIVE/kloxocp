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
| Target OS | **Ubuntu 24.04 LTS and Ubuntu 26.x**. AlmaLinux/Rocky 9 later, through an OS abstraction layer |
| Customer websites | **Nginx + PHP-FPM default** (multiple PHP versions side by side). Apache 2.4 + PHP-FPM is packaged and selectable. Remove Lighttpd/Varnish/Squid/ATS |
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
    bin/kloxocp                  NEW operator CLI (PHP 8.3: status, doctor, snapshot, rollback)
    bin/mail/kloxo-mail          NEW mail tool (PHP 8.3, standalone)
    bin/install/generate-tls-keys  NEW: unique TLS keys per install (PHP 8.3)
    bin/install/init-panel-db      NEW: Ubuntu MariaDB panel schema (PHP 8.3)
    bin/install/install-hiawatha   NEW: pinned Hiawatha 12.4 source build
    bin/install/install-pdns       NEW: PowerDNS MariaDB + gmysql snippet; unit stays disabled
    bin/install/install-apache     NEW: Apache 2.4 PHP-FPM vhosts; unit stays disabled
    cexe/                        C sources: backend daemon (kloxo.c) only; no setuid helpers
    init/                        panel service units, Hiawatha base config, php-fpm templates
    file/                        service config templates (nginx, apache, bind, pdns, qmail, NEW file/mail/; Lighttpd/cache/unused-DNS trees removed)
    install/                     LEGACY RPM/CentOS installer (reference only, to be replaced)
  docs/
    HANDOFF_BRIEFING.md          this file
    AUDIT_REPORT.md              security + PHP 8 audit and roadmap
    INSTALL_AND_UPDATE_DESIGN.md install/update/stability design (CLI skeleton exists; apt not built)
    MAIL_DESIGN.md               mail stack design, tests, gaps
    REBRAND_CHANGELOG.md         exactly what the rebrand changed / did not change
    legacy/                      obsolete RHEL install notes
  tests/mail/                    run-tests.sh (73 checks), e2e.py (25 live checks), README.md
  tests/tls/                     generate-tls-keys checks (php-cli + openssl)
  tests/remote/                  C1 auth-before-unserialize checks (php-cli only)
  tests/security/                P0.4/P0.5 source invariants (php-cli only)
  tests/p1/                      P1 stack-shrink source invariants (php-cli only)
  tests/p2/                      P2 first-party PHP 8 parse + autoload characterization (php-cli only)
  tests/p3/                      P3 kloxocp CLI + snapshot/rollback (php-cli only)
  install.sh                     Ubuntu 24.04 / 26.x bootstrap from https://github.com/ARDZIVE/kloxocp.git
  tests/install/                 init-panel-db + install-hiawatha + install-pdns + install-apache source invariants (php-cli)
  tools/rebrand_to_kloxocp.py    script of the second rebrand step
```

Size: about 555 PHP files after P1 deletions (was ~597). Roughly 165k lines remain in the rest of the tree (about 45k are the bundled PEAR/WebDAV in `httpdocs/webdisk`).
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
   helper. Remaining `chmod 4755` on qmail `vchkpw` in `pscript/fixmail-all` waits until qmail is removed.
8. **P0.3 (C1) closed** for `process_server_input()`: auth envelope required before unserialize;
   `do_remote_exec` always sends it (including localhost). `reload_slave_password()` no longer
   writes through a null `$login` (C daemon / `process_single.php`). Tests: `tests/remote/`.
   JSON replacement is planned, not built. Other unserialize-on-untrusted-input sites remain.
9. **P0.4 closed:** web-console stub (no unserialize); Easy Installer disabled (no HTTP
   download/unpack); `set-kloxo-php` no longer writes NOPASSWD sudoers. Tests: `tests/security/`.
10. **P0.5 closed for defaults; multi-server bind is now explicit.** Daemon and PHP SSL
    listener bind localhost on a master; TLS 1.2+; 1 MiB input cap; missing keys fail closed
    (`generate-tls-keys`). A **slave** TLS listener on 7779 binds all interfaces; plaintext
    7776 stays loopback. C1 envelope still required. Long-term allow-listed agent is not built.
11. **P1 (web/DNS shrink) closed:** customer web is Nginx (default), Apache, or none; webcache is none; DNS is
    none/BIND/PowerDNS. BIND is the **default** (`pg_dns=bind`, bind9 enabled). PowerDNS is
    apt-installed and prepared (`install-pdns`: MariaDB schema, `/etc/powerdns/pdns.d/zz-kloxo-gmysql.conf`)
    with the `pdns` unit **disabled** until Switch Program selects it (stops bind9, starts pdns;
    only one listener on port 53). Nginx is the **default** web (`pg_web=nginx`, nginx enabled).
    Apache 2.4 is apt-installed and prepared (`install-apache`: PHP-FPM via `proxy_fcgi`,
    `/etc/apache2/conf-available/zz-kloxo.conf`) with the `apache2` unit **disabled** until
    Switch Program selects it (stops nginx, starts apache2; only one listener on 80/443).
    Lighttpd, Varnish, Squid, ATS, Hiawatha-as-site-server, Monkey,
    OpenLiteSpeed, and unused DNS (djbdns/nsd/mydns/yadifa/maradns) drivers and `file/` templates
    deleted. Panel Hiawatha stays under `init/hiawatha.conf.base`.
    qmail/courier/ezmlm/spamassassin remain until postfix UI drivers exist. Tests: `tests/p1/`.
    Live pdns/apache2 start on Ubuntu is **UNVERIFIED**.
12. **P2 first slice:** first-party `php -l` is clean (499 files; PEAR/WebDAV, formmail and
    urlrewrite examples still fail). `__autoload` replaced by `spl_autoload_register`
    (`kloxo_autoload`). Boot-path `ereg*` in `includecore.php` replaced. `csb()` no longer
    throws `ArgumentCountError`. Core `Sqlite` class already used mysqli/PDO (upstream).
    Tests: `tests/p2/`.
13. **P2 boot slice:** `include.php` loads on PHP 8 without throwing (`tests/p2/test_boot.php`).
    Program root is derived from the tree / `KLOXO_ROOT` (not a missing `/opt/kloxo`).
    PHP binary is distro `php` (`kloxo_php_binary()`, `init/php-cli.sh`). Login page files
    are shipped under `httpdocs/login/`. Hiawatha 12 drops TLS 1.0/1.1 (no
    `MinTLSversion` directive). PHP-FPM unit runs `init/kloxo-php-fpm.sh`
    (php-fpm8.3 on 24.04, php-fpm8.5 on 26.04). Browser login
    against MariaDB is **UNVERIFIED**.
14. **P3 CLI skeleton:** `kloxo/bin/kloxocp` (`help`, `version`, `status`, `doctor`,
    `snapshot`, `rollback`, `component list`). `update` / `component upgrade` /
    `install` after a clean doctor still exits **2**. `install.sh` bootstraps
    Ubuntu 24.04 from `https://github.com/ARDZIVE/kloxocp.git` (public; apt packages,
    `/opt/kloxo` symlink, TLS keys, `kloxo-php` unit, pinned Hiawatha 12.4 build,
    `init-panel-db`). Default panel login is **admin / admin**;
    `password_contact_check()` blocks the UI until that password is changed
    (`check_raw_password('client', 'admin', 'admin')`). Apt repo, Debian
    packages, and package-level update/rollback are **not built**. Tests:
    `tests/p3/` and `tests/install/` (php-cli; do not run the Ubuntu installer
    or a Hiawatha compile).
15. **Ubuntu panel schema + Hiawatha:** `kloxo/bin/install/init-panel-db` loads
    `file/sql/db-structure-base.sql` synchronously (MariaDB 10.11 `CREATE USER` +
    `GRANT`; no background `mysql … &`). `kloxo/bin/install/install-hiawatha` pins
    Hiawatha 12.4. `create.php` calls `init-panel-db` and skips CentOS
    `tmpupdatecleanup`. Default admin UI password is `admin` (override with
    `KLOXO_ADMIN_PASSWORD`, min 8). Admin `syncserver` is seeded as `localhost`
    (empty value made PHP-settings alerts look up `pserver-`). IP list on
    Ubuntu is read from `ip -4 addr` (`lib/php/kloxo_ipaddr.php`); the panel
    does not write ifcfg or `/etc/network/interfaces`. `phpini` sync queries
    `php_type` (not `php-type`). PHP Used lists apt php-fpm (`kloxo_phpver.php`,
    including 5.6 / 7.4 / latest; default is newest). `install-site-php` adds
    those versions via distro apt then `ppa:ondrej/php`. The old scanner only
    saw `/opt/*m`. phpMyAdmin is not in the tree; `install-phpmyadmin`
    installs a self-contained 5.2.3 tarball under
    `httpdocs/thirdparty/phpMyAdmin` (Hiawatha 405 otherwise). Admin SQL
    Manager uses panel sign-on (`kloxo_pma` ALL ON *.*, `pma.pass`, no
    password in the URL). Live phpMyAdmin login after this change is
    unverified; re-run `install.sh` after pull.
    Component Info uses Ubuntu package names (`kloxo_component.php`) instead
    of CentOS `set.*.lst` RPM names; Hiawatha is the panel binary. Live
    Component Info list is **UNVERIFIED**.
    The admin Servers list always has a `localhost` pserver (`init-panel-db`
    seed + `pserver::ensureLocalhostServer()`); empty `ostype` no longer blanks
    the hostname cell.     The Update screen (`lxupdate`) reads the local
    `kloxoversion` (no `yum`); `exec_class_method` no longer TypeErrors on a
    missing `initThisObject`. The Update Now path does not run
    `bin/update.php` (`kloxocp update` is still not built). Autoload loads
    `*_none` drivers (`dns__none`, `web__none`); Switch Program no longer
    TypeErrors on those classes. Client mail counts read
    `/var/log/mail.log` (Ubuntu) or `/var/log/maillog` and do not call
    `feof(false)` when the file is missing. PHP config forms apply the
    usual defaults (120s / 128M / 32M / disable_functions) when the
    stored `phpini` blob only has a session path. Admin Resource Plan
    add form uses unlimited quotas and flags on (Easy Installer stays
    off). PHP Module Status lists Ubuntu
    `/etc/php/<ver>/mods-available` (plus leftover `/opt/*m` and
    `/etc/php.d`); enable/disable uses `phpenmod`/`phpdismod` on those
    paths, not `.nonini` renames.     Traceroute no longer throws
    `traceroute_failed` when the Ubuntu binary is missing; it uses
    `traceroute`/`tracepath` with a validated host and an empty list on
    failure.     Localhost File Manager / Switch Program / Traceroute / Cron Tasks
    no longer require the C daemon on TCP 7776 (`No socket connect to
    server localhost`); `remote_exec` always runs `do_local_action` for
    localhost (the C daemon is not started on Ubuntu). Mail To on Cron
    Tasks does not sync the client/pserver through that socket; crontab
    rewrite uses argument-array `/usr/bin/crontab -u` and skips a
    missing job list. `slave_get_driver` is null-safe
    when `slavedb/driver` is missing. Re-check those pages after
    deploy. Server Information prefills description and FQDN
    (`localhost` / hostname). `init-panel-db` rewrites the 2014 dump
    for MariaDB 10.6+/11.8 (error 1118 row size > 8126 on
    `client`/`domain`/`web`): quota columns `varchar(32)`, other
    nullable `varchar(255)` become TEXT, `ROW_FORMAT=DYNAMIC`. Hiawatha
    is not in Ubuntu apt; `install.sh` builds pinned 12.4 **before**
    the schema (so a MariaDB error cannot skip the panel server) with
    static bundled mbed TLS, then starts `kloxo-web` on 7777/7778.
    `kloxo-php` uses a PHP 8-safe FPM pool (no removed
    `allow_url_include` / `magic_quotes_gpc` / `mysql.allow_persistent`),
    `Type=notify` (falls back to `Type=simple`), and `php-fpm -t`
    before enable. A successful install prints a boxed login message
    (URL, admin/admin, change password, firewall 7777). The README
    one-liner clones if `/usr/local/src/kloxo-cp` is missing, or
    fetches `origin/main` when that clone already exists.
    Hiawatha 12.4 needs a writable work dir (`/opt/kloxo/var/hiawatha`
    for new builds; also `/usr/local/var/lib/hiawatha/upload` for the
    current binary) or `kloxo-web` exits 1. Panel PHP is FastCGI on
    `127.0.0.1:39000` (a unix socket plus restarting FPM without
    bouncing Hiawatha produced HTTP 503). `kloxo-php` is Type=simple
    (notify + the wrapper left FPM dead). The FPM pool user is
    `lxlabs` (PHP 8.5 exits 78 if the pool is `root`). `kloxo.pass` is
    `0640` `lxlabs` so FPM can read the DB password (0600 root produced
    `Access denied for user 'kloxo'@'localhost' (using password: NO)`).
    Empty `allowedip`/`blockedip` lists are arrays (PHP 8 `count(null)`
    after login). `createQueryStringAdd()` closes the column list
    (` ) values(`) so login `utmp` inserts parse on MariaDB 11; mysqli
    syntax errors are caught instead of a white-screen
    `mysqli_sql_exception`. Empty SQL results are arrays (`kcount()`);
    admin appearance is seeded as `simplicity/default` (a missing
    `sp_specialplay` row used skin `default`, which has no CSS).
    `db_get_value()` returns empty on a missing row; `strftime()` is
    wrapped (`kloxo_strftime`) for PHP 8.5; dashboard HTML uses
    `kloxo_html()` so `htmlspecialchars(null)` does not spray warnings.
    Panel FPM `display_errors` is off; PHP errors go to `log/php.err`
    (writable by `lxlabs`). Hostname/arch use `kloxo_hostname()` /
    `kloxo_machine()` (PHP 8.5 deprecated backticks). First-party
    `curl_close()` is wrapped (`kloxo_curl_close`); yum/up2date
    package drivers no longer use backticks; panel FPM keeps
    `register_argc_argv` off so PHP 8.5 does not derive argv from
    the query string. First-login password form no longer `exit`s
    before the home action buttons; `createShowAlist` /
    `createShowRlist` use `checkButton()` so a missing `priv` object
    does not blank the dashboard. First-party `ereg*` / `dl()` /
    `htmlspecialchars(null)` leftovers and PHPMailer
    `(boolean)`/`(integer)`, `openssl_pkey_free()`, and
    `get_magic_quotes_runtime()` are PHP 8.5-safe. First-party
    `ereg*` / `split()` (search-engine submit, site preview) are
    preg/explode; `kloxo_strftime` no longer calls `strftime`;
    `E_STRICT` is unused. Live theme JS no longer calls `escape` /
    `unescape` / `document.all` / `with`. Bundled PEAR/WebDAV and
    formmail still contain removed PHP APIs and are not on the
    panel path. Feather/xpmenu still uses parse-time
    `document.write`. The simplicity
    main column (`.div_content` / `#mmm`) no longer uses
    `overflow:hidden` plus `height:100%`, which clipped the middle
    of every page.     The leftover splash `flush()` + `ob_start()`
    that dropped the body on FastCGI is gone; `display_exec`
    catches PHP 8 `Error` so a later TypeError cannot blank the
    middle column. Dashboard tile sections use `overflow:visible`
    and stay open unless explicitly collapsed. `getActionDetails`
    / `getTitleOnly` / `get_classvar_description` no longer
    TypeError on a missing `$desc[2]`, which cut the simplicity
    top bar after Server (Security, Basic, Task, Help, Logout)
    and left the home tiles empty. Status, Show/Hide and the clock sit on the
    top-right of the simplicity bar (flex, white-on-blue; the
    clock has a PHP time fallback). A missing `priv` object
    no longer aborts the Resource table before the tiles.
    Live HTTPS login as admin/admin on 37.59.173.71:7777 succeeded.
    Every page died in the Server menu at SSH Client because
    `sshclient::initThisObjectRule` was not compatible with
    `Lxclass` (PHP 8 fatal on autoload). Menu titles now go through
    `kloxo_menu_title`; Status/Show-Hide/time print before the left
    menu; `install.sh` fetches origin and rsyncs a non-symlink
    `/opt/kloxo`. Home is a two-column `table-layout:fixed` table
    (Find/Information/Resource left, tiles right) even when a
    `__v_message` banner is present.     Help/Logout print inside the green `#menu_div` (not a second
    green island) at the right of that strip, immediately left of
    Status. Status, Show/Hide and the clock sit in one horizontal
    row on the red bar. The green bar fills the middle of the red
    top (`flex: 1 1 auto`) so Home…Task and Help/Logout join as one
    strip. Content tabs use `.div_tab_block` folder tabs on the
    beige `{Admin Home}` header; `#mmm` is transparent, `.div_content`
    fills to the bottom of the viewport (`min-height: calc(100vh - 80px)`), and the
    Kloxo logo sits on the right of the screen (`position: fixed; right: 10px`).
    getLastLogin writes Last Login IP (if a previous session exists),
    then Current Login IP, then Last Login Time. The Kloxo logo is
    inside `#mmm` (not `z-index:-9999`). The first-login password
    gate is a required `#kloxo-password-force` modal that contains
    the warning and the change-password form. Esc and Close do not
    dismiss it; the form is inside the dialog so an Error Box cannot
    cover the fields. The dashboard still renders behind a backdrop.
    Alert popups (`kloxo-error-box`) no longer share `id="showimage"`
    with Generate Password, so Esc and Close hide the visible box.
    `convert_message` does not index a missing `frm_o_o[0]`.
    Theme CSS links include a filemtime query so a browser
    cannot keep the old simplicity sheet after deploy.
    Log Manager, Driver Configuration, PHP Configuration,
    Backup/Restore, Webserver Configure, SSH Configure, LxGuard,
    SSH Terminal and File Manager no longer show the
    left-column Find box. LxGuard list tabs (connections, raw hits,
    whitelist) omit the Find/search input too. SSH Terminal is admin-only (pserver
    child, not client home) and shows a copyable `ssh -p`
    command (no Java applet). Adding a client no longer TypeErrors
    on `implode()` with a string (`contactemail`, `coma_*_list`,
    `kloxo_implode` / `kloxo_list_from`). Live client-create after
    deploy is **UNVERIFIED**. Adding a Resource Plan does not throw
    `no_dns_template`, creates `listpriv` when missing, sanitizes the
    display name before `validate_plan_name`, and does not consume
    parent quota like a client. Live plan-create after deploy is
    **UNVERIFIED**. Command Center / Timezone / Switch
    Program / Cron Tasks / File Manager / HelpDesk forms no longer
    TypeError on empty lists. `init-panel-db` seeds HelpDesk
    category Welcome and ticket 1 (Created By Kloxo, Subject
    Welcome to Kloxo, date = install time). SSH authorized keys no longer call
    `key()` on a missing list. Ubuntu Services uses
    `Service__Debian::getServiceDetails`; Component Info has a
    dpkg driver; Mail Queue reads Postfix `postqueue` when
    present.     Switch Program loads debian.inc on Ubuntu and does
    not index an empty yum version. SMTP choices are `none` and
    `exim` (the old qmail-toaster label is gone). `smtp__exim` is a
    thin systemd enable/start stub (`exim4` on Ubuntu); it does not
    replace the Postfix + Dovecot + Rspamd stack in `kloxo-mail`.     List and show pages share
    `kloxo_rl_driver` / `setListFromArray` array guards and
    `initThisList` / `initThisObject` Throwable catches so a
    missing Ubuntu driver no longer TypeErrors the whole page.
    Debian package driver is `aptget` (`package__aptget`).
    Re Read IPaddress on Ubuntu uses `ipaddress::fixstatus` and
    `KloxoIpaddr::currentIpv4()`; it no longer calls the missing
    `ipaddress::listSystemIps`. Directory Index list does not
    `count()` a missing `dirindexlist_a`.     Cron Tasks Mail To no
    longer throws `No socket connect to server [localhost]`.
    DNS Template menu and dashboard tiles list `dnstemplate` (not
    `lxbackup`); opening the list no longer throws `no_ip_address`
    when the IP table is empty.
    FTP Configure (`serverftp`) is seeded as `pserver-localhost` and
    does not sync through the C daemon on first show.
    Re-check the live dashboard after pushing this tree and
    re-running install. The live host still served the previous
    CSS (`position:fixed` Message/Ticket overlaying Home, logo
    `z-index:-9999`) until that deploy.

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
* **C2** **CLOSED for P0.5 defaults; slave TLS 7779 is the multi-server exception.** Daemon binds `INADDR_LOOPBACK` except TLS 7779 on a slave (`INADDR_ANY`). `TLS_server_method` with TLS 1.2 minimum, 1 MiB message cap, no payload logging. PHP `kloxo_ssl_listen_address()` is `127.0.0.1` on a master and `0.0.0.0` on a slave. Plaintext 7776 stays loopback. Remaining: it still runs PHP on received data (replace with an allow-listed agent, P0.5 long-term); `verify_client` is still off (self-signed per-install keys).
* **C3** **CLOSED.** Committed private keys and matching certs deleted. Unique keys are generated at install by `kloxo/bin/install/generate-tls-keys`. Old keys remain compromised anywhere they were copied.
* **C4** **CLOSED.** Setuid helpers and sources deleted; Makefile and installer no longer set the setuid bit on `cexe/`. Qmail `vchkpw` setuid in `pscript/fixmail-all` remains until qmail is removed.
* **C5** **CLOSED.** Easy Installer will not download or unpack software. `download_file()` refuses `http://`. Version and wordlist HTTP fetches are disabled.

High: 787 shell-execution call sites (+379 `lxshell_*`), 55 `eval()`, 25 `extract()`, MD5/crypt password hashing (admin password via MD5-crypt), TLS verification disabled in 12 places, outdated bundles (PHPMailer 5.2.19, PEAR/WebDAV, ExtJS 2.x, formmail.php 2001-2004). **H3** (web-console) and **H6** (sudoers NOPASSWD) are closed.

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
* Lint the legacy tree with `php -l` per file (a loop; parallel xargs output interleaves). Current baseline: 50 failures (section 4). First-party files are clean (`tests/p2/`).
* Operator CLI: `php kloxo/bin/kloxocp help`. Checks: `bash tests/p3/run-tests.sh` (php-cli only; uses a temp `--root`).

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
2. **DONE.** Setuid helper sources, Makefile `chmod ug+s`, installer `chmod -R ug+s` on `cexe/`, and callers removed. *Accept:* no `chmod ug+s` commands in the tree (qmail `vchkpw` 4755 remains until qmail is removed).
3. **DONE.** `process_server_input()` requires the credential envelope and verifies the password before `unserialize`. JSON replacement is documented in `remote_auth_gate.php` and `AUDIT_REPORT.md` (not built). *Accept:* gadget payloads without a valid envelope do not run `__wakeup`.
4. **DONE.** web-console removed; Easy Installer disabled; sudoers NOPASSWD removed. *Accept:* no in-browser shell, no HTTP app fetch, no `NOPASSWD:/opt/kloxo`.
5. **DONE for defaults; slave TLS 7779 bind is in.** Bind localhost on a master, TLS 1.2+, 1 MiB cap, missing keys fail closed. Slave `install.sh --slave` writes `slave-db.db` and starts `kloxo-backend` with TLS 7779 on all interfaces. Long term: replace with an allow-listed privileged agent (Unix socket, JSON, audit log).

### P1: shrink the tree (phase 1b)
1. **DONE for web/DNS.** Lighttpd, Varnish, Squid, ATS, Hiawatha-as-site-server, Monkey, OpenLiteSpeed drivers/templates removed. Selectable web: `none`, `nginx`, `apache` (Nginx default; Apache 2.4 + PHP-FPM packaged, disabled). Webcache: `none`. DNS: `none`, `bind`, `pdns` (BIND default; PowerDNS packaged, disabled). Panel Hiawatha kept in `init/`. Tests: `tests/p1/`.
2. **NOT DONE.** qmail/courier/ezmlm/bogofilter/spamassassin drivers stay until P4 postfix UI drivers exist.
3. **PARTIAL.** No Ubuntu package; `install-hiawatha` builds pinned 12.4
   (sha256 in the script). Config check is `hiawatha -k -c <init>`. **UNVERIFIED**
   on a real 24.04 VM. `lib.php` still contains dead Apache install/chown
   branches (legacy installer; P3 CLI does not replace it yet).

### P2: PHP 8.3 port (phase 1c)
1. **DONE: first-party parse.** First-party `.php`/`.inc` files `php -l` clean. `__autoload` → `kloxo_autoload` + `spl_autoload_register`. `includecore.php` no longer calls `ereg*`. `char_search_beg` accepts the extra `csb()` argument. Tests: `tests/p2/`.
2. **DONE: library boot.** `include.php` constructs `Sgbl` on PHP 8 (`test_boot.php`). Paths follow the tree / `KLOXO_ROOT`. Distro PHP via `kloxo_php_binary` and `init/php-cli.sh` (php 8.3 / 8.4 / 8.5). `#[AllowDynamicProperties]` on `Lxclass`, `Sgbl`, `HtmlLib`. Login files shipped. systemd PHP-FPM unit uses `kloxo-php-fpm.sh`.
3. **PARTIAL.** `init-panel-db` and `install-hiawatha` exist (php-cli tests in
   `tests/install/`). Admin `syncserver` is `localhost`; Ubuntu IPs come from
   `ip -4 addr` (`kloxo_ipaddr.php`); `php-type` SQL is `php_type`. Admin
   Servers list seeds `localhost` (`ensureLocalhostServer`). Install also
   seeds a `driver` row for `localhost` (`dns` = `bind`, `web` = `nginx`) and
   `generalmisc_b`. Home show no longer fatals on a null `used` object,
   missing driver row, or admin `getParentO()`. Browser login against
   MariaDB on a real Ubuntu VM is **UNVERIFIED**. Admin SQL Manager
   sign-on (`kloxo_pma` ALL ON *.*, `auth_type=signon`) is in tree;
   live phpMyAdmin login after re-install is **UNVERIFIED**. Remaining
   compile failures are third-party (`webdisk/PEAR*`, formmail,
   urlrewrite examples). Runtime `split`/`ereg*`/`each` in those trees.

### P3: installer, packaging, updater (phase 1d)
1. **DONE: CLI skeleton.** `kloxo/bin/kloxocp` implements `help`, `version`, `status`,
   `doctor` (optional `--install` host checks: root, Ubuntu 24.04 or 26.x, no cPanel/Plesk/ISPConfig),
   `snapshot` / `rollback` of allow-listed paths (`bin/kloxoversion`, `init`, `etc`,
   `file/ssl`, `httpdocs/login`) under `--root` / `--snap-dir`. `component list` works;
   `update` and `component upgrade` exit 2. PHP 8.3, `declare(strict_types=1)`,
   argument-array `proc_open`, no shell strings. Tests: `tests/p3/` (20 checks, php-cli only).
2. **DONE: install.sh Ubuntu bootstrap.** Repo-root `install.sh` clones
   `https://github.com/ARDZIVE/kloxocp.git` when needed, installs Ubuntu 24.04
   or 26.x packages, links `/opt/kloxo`, generates TLS keys, enables `kloxo-php`
   and `kloxo-backend`. `--slave` writes `etc/conf/slave-db.db` (`init-slave`)
   and skips the panel UI. Default login is admin/admin with a forced password
   change after first login. One-liner is in `README.md`. **UNVERIFIED on a
   real Ubuntu VM.** Does not add a signed apt repo. Live two-host add is
   **UNVERIFIED**.
3. **NOT DONE.** Signed apt repo, `debian/` packaging, `kloxocp update` (pre-flight →
   snapshot → apt → migrate → validate → health-check → auto-rollback), LVM/ZFS snapshots,
   panel-DB dump in the snapshot. `init-panel-db` / `install-hiawatha` exist
   (not verified on a real VM). Reuse `kloxo-mail apply` for service config once
   packages exist.

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

1. **Answered:** multi-server (master/slave) is required in v1. Slave TLS 7779 binds all interfaces; plaintext 7776 stays loopback; C1 envelope stays. Live two-machine add-server is **UNVERIFIED** until a second Ubuntu host is available.
2. Repo/org name, domain, logo, and trademark check for "Kloxo CP".
3. A **test server**: fresh Ubuntu 24.04 VM with root and a domain with controllable DNS (needed to verify install, updates and mail deliverability).
4. **Answered:** customer-site PHP is 5.6 (5.6.40), 7.4 (7.4.33), and the
   newest `phpX.Y-fpm` apt can see (default). `install-site-php` uses the
   distro first, then `ppa:ondrej/php`. The panel FPM stays `kloxo-php`.
   Webserver Configure keeps the Kloxo-MR section layout (PHP Used, PHP
   Branch, Multiple PHP already-installed/enable/install/remove, PHP Type
   for Apache, Apache optimize, MySQL convert, fix ownership, Pagespeed)
   with Ubuntu `phpX.Y` ids, not `php52m`. **PHP Used** switches the
   default; a domain can still pick another installed version. 5.6 / 7.4
   are EOL and only for legacy sites. Live Webserver Configure after
   deploy is **UNVERIFIED**.
5. FTP server choice (Pure-FTPd vs ProFTPd). **Answered:** DNS is BIND by default;
   PowerDNS is packaged and prepared, started only when Switch Program selects `pdns`.
   Live switch on Ubuntu is **UNVERIFIED**.

---

## 10. Suggested first prompts for Cursor

* "Start Debian packaging (`debian/`) and a private apt repo so `kloxocp update` has packages to orchestrate."
* "Add characterization tests, then continue the PHP 8.3 port of remaining runtime (`split`/`ereg*` in third-party trees, `create.php` Ubuntu)."
* "Start P4 mail UI drivers: thin `*__postfix` wrappers around `kloxo-mail`, then drop qmail."
