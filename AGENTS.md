# AGENTS.md: Kloxo CP

**Read `docs/HANDOFF_BRIEFING.md` first**; it is the source of truth for decisions, state and the work plan.

## What this is
Kloxo CP: a hosting control panel forked from Kloxo-MR (AGPLv3), being modernised for Ubuntu 24.04
and Ubuntu 26.x,
Nginx + PHP-FPM default for sites (Apache selectable), Hiawatha for the panel itself, no billing for now, existing UI kept,
mail via Exim + Dovecot + Rspamd + MariaDB (templates done; re-run mail tests on Ubuntu), easy install/update with automatic
rollback (designed, not built).

## Status
10.1-2026110403. NOT safe to run. The legacy PHP does not run on PHP 8.3. Audit C1–C5 for phase 1a
(`process_server_input` auth, daemon bind/TLS/size, committed keys, setuid helpers, easyinstaller
HTTP) are closed. P1 web/DNS shrink is done (Nginx + panel Hiawatha; BIND/PowerDNS; qmail still
present). P2 first-party `php -l` is clean and `include.php` loads on PHP 8 (`tests/p2/test_boot.php`).
P3 CLI skeleton is in (`kloxo/bin/kloxocp`: status, doctor, snapshot, rollback).
`install.sh` bootstraps Ubuntu 24.04 or 26.x from https://github.com/ARDZIVE/kloxocp.git
(Hiawatha 12.4 source build + `init-panel-db`; MariaDB 11.8 row-size rewrite;
`ufw`, `pure-ftpd`, `redis-server`, and `imagemagick` are packaged (`ufw`
**enabled by default**; Redis and Pure-FTPd enabled; ImageMagick for File Manager Thumbnail);
install defaults to `--profile=full`. File Manager Thumbnail no longer probes
with CentOS `rpm -q ImageMagick` (checks `/usr/bin/convert` / `magick`). The
README one-liner downloads `install.sh` from
`raw.githubusercontent.com/.../main/install.sh` (Exim, Dovecot, Rspamd;
Postfix not co-installed with Exim (Switch Program apt-swaps);
Ubuntu universe enabled when needed;
no bogofilter/SpamAssassin/sqlite), then that script
fetches `origin/main` and hard-resets when the clone already exists;
`kloxocp update` and signed apt packaging are not). Hiawatha is not an apt package. Default panel login is admin/admin; the UI requires a password
change after first login (required Change Password modal with the form
inside; Esc/Close cannot skip it). Simplicity Status toast auto-opens for
alerts/info and content shows matching inline alerts (Status peek does not
consume the message). Admin Servers list seeds `localhost`. The Update
screen shows **10.1-2026110403** and note **Kloxo fork by Hampik TALATINIAN** (`kloxocp update` still exits 2).
Switch Program DNS default is BIND (`pg_dns=bind`); PowerDNS is packaged and
prepared (`install-pdns`) but the `pdns` unit stays disabled until selected
(apt postinst is blocked from starting it).
Web default is Nginx (`pg_web=nginx`); Apache is packaged and prepared
(`install-apache`, PHP-FPM via proxy_fcgi, no mod_php) but `apache2` stays
disabled until selected. Admin SQL Manager opens phpMyAdmin in a new tab
(`/thirdparty/phpMyAdmin/kloxo-signon.php`, `target="_blank"`): a live
panel-admin session is signed in as MariaDB `root`@`127.0.0.1` and
redirected; otherwise the MySQL logon form is shown (panel admin may
leave the password blank; signed CSRF, sign-on cookie path `/`). Not mywebsql, not a second Domain tile. Component Info
lists Ubuntu packages (nginx, apache2, bind9, pdns-server, mariadb-server,
pure-ftpd, redis-server, exim4, postfix, dovecot, rspamd, phpX.Y-cli/fpm) plus source-built Hiawatha, with
on/off from dpkg. SMTP `none` / `exim` (default, `pg_smtp=exim`) / `postfix`
(apt-swaps with Exim on select; hosted mail is Exim via `kloxo-mail`;
qmail-toaster is no longer the selectable MTA). PHP Module Status
reads Ubuntu `mods-available`. Empty allowedip/blockedip lists no longer
`count(null)` after login. Admin reboot/poweroff uses `systemctl --no-block`
(or `/sbin/reboot`) instead of `system("reboot")`. `getAdminDbPass` throws `lxException` (not generic
`Exception`); Sqlite/do_remote_exec/import/superadmin wrap a missing `kloxo.pass`. Install seeds a `localhost` driver row and
`generalmisc_b` so the home page does not fatal on a missing driver or
null `used`. General Settings tab is labelled **General Settings**;
Client Processing Logs (`domaindefault`) beige tab is **Client Processing Logs**;
install seeds Login Message
`<p> Welcome to <%programname%>  </p><p>Use a valid username and password to gain access to the console. </p>`
and `etc/flag/enablecronforall.flg` so Enable Cron For All Users is checked
by default. Webmail System Default lists installed dirs under
`httpd/webmail` plus available Afterlogic/Rainloop/Roundcube/Squirrelmail/Telaen
(`Mmail::getWebmailProgList`). Panel FPM hides PHP errors (`log/php.err`). The middle column
is no longer dropped by splash `flush()`/`ob_start()` or an
uncaught PHP 8 `Error` after the header. Dashboard tiles and the
simplicity top-bar items after Server (Security, Basic, Task,
Help, Logout) no longer vanish on a missing action description.
Status, Show/Hide and the clock stay on the top right in one row.
The green menu strip fills the middle of the red bar (Home…Task on
the left of the green, Help/Logout on the right of the green, still
immediately left of Status). Content tabs sit on the beige header
like Kloxo-MR; the Kloxo CP logo sits on the right of the screen.
The content white panel fills to the bottom of the page.
Customer sites default to the newest installed PHP-FPM; 5.6.40 and 7.4.33
are installed beside it (`install-site-php`, ppa:ondrej/php) and can be
chosen on Webserver Configure (PHP Used / PHP Branch list php8.3, php7.4,
php5.6 — not php52m). Webserver Configure section forms (PHP Used/Branch,
Multiple PHP, PHP Type, Apache optimize, MySQL convert, fix ownership,
Pagespeed) load without the panel DB; Multiple PHP Install queues
`install-site-php` for the selected `phpX.Y` ids (job under `$ROOT/log/`,
not `/tmp`, because `kloxo-backend` uses `PrivateTmp`) so they appear on
Multiple PHP Remove; Apache optimize writes
`kloxo-optimize.conf`; PHP Type on Ubuntu is proxy_fcgi + mpm_event/worker;
fix-ownership is `--select=chown`. The panel itself stays on distro PHP. PHP Configure and Advanced PHP Configure share exactly two beige tabs
(**PHP Configure** / **Advanced PHP Configure**; `phpiniPropertyTabs`, forced; no parent Home/Domain
tabs on either page; different links `a=show` and `a=updateform&sa=extraedit`).
`kloxo_menu_title` must not assign `$ghtml->frm_o_o`; `getFullUrl` falls back to
`__http_vars` so tabs keep the object path (no bare Home tab). Nested `phpini_flag_b-*` form
fields resolve without a TypeError when the flag class has no `$__desc`.
Heading uses the phpini nname (`client-admin`). Output Compression is a text
value (`off`), not a checkbox; defaults match Kloxo-MR (execution 600, input
180, post/upload 1024M, allow_url_include on, PHP-fpm Type static, extensions
`.php .php3 .php4 .php5`). Advanced fields match the screenshot (Sendmail From,
Enable Dl, Output Buffering, Allow URL FOpen/Include, Register Argc Argv,
Disable Functions, times/limits, File Uploads, CGI Force Redirect). Parent/login
guards keep both forms loadable when the parent or `$login` is missing. Security has a **UFW Firewall** button (`a=show&o=ufwfirewall`; font icon WebHostingHub
`firewall`/`f021`, collage `ufwfirewall_*.gif`) with exactly one beige tab **UFW Firewall** (UFW **enabled by default** at install): Show/Hide centered add form (mail-account look: pale green/lavender rows, grey Add footer); no Enable/Disable/defaults UI and no Managed/Backend status line; **Port Rules** pink toolbar, Advanced Search, **Actions** (edit → separate `updateform&sa=editrule` page; delete icon); Family IPv4/IPv6; `KloxoUfw` via kloxo-backend; deny/reject of panel ports and deleting the last panel allow are refused. Live UFW after deploy is UNVERIFIED. Watchdog under localhost lists the Kloxo-MR eight services
(add form + Switch To Another; mail uses `__driver_mail`; webcache 8080
and webproxy 30080 are nginx frontends started by `install-webfront`). SSH Configure beige tab is **SSH Configure** (not Home). Log/driver/php/backup/webserver/SSH Configure/LxGuard/SSH Terminal/File Manager/HelpDesk ticket/Notification/Update/Manage DNS/PHPinfo show pages
omit the left Find box. Manage DNS beige tabs are Manage DNS, Rebuild, NS, A, AAAA, CNAME, FCNAME, MX, TXT, CAA, SRV, General Settings; show lists `dns_record_a` without `write()`/`setUpdateSubaction` (template records often lack `parent_clname`; dirtying the zone made `login->was()` sync DNS and bounce Home). Live Manage DNS after deploy is UNVERIFIED. Client PHPinfo is web `a=show&sa=phpinfo` (site `php -i` via argument-array `proc_open`); `getTrueParentO`/`getParentO` treat `client-admin` as root (`isRootClient`) and never print `no parent_clname`. Live PHPinfo after deploy is UNVERIFIED. Change Owner (`a=updateform&sa=changeowner`) builds a client select without calling methods on a missing parent; `Lxclient::updateform` does not load `general` first; help text does not explode a null string. Live Change Owner after deploy is UNVERIFIED. SSL Certificates under a domain has beige tabs SSL Certificates, Add SSL File, Add SSL Text, Add Let's Encrypt, Add SSL Link, Switch To Another of sibling domains, and columns User Name, Certificate Name, Add Type, Parent Domain, Syncserver (list toolbar search stays). HelpDesk ticket show has Switch To Another
(sibling ticket ids), Ticket / Add Reply / Escalate, inline Add Reply
(State, Text Reason), and Replies Under N (Changed By, Text Reason,
Date, State); the replies toolbar search stays. Escalate is Switch To
Another plus a confirmation Update (no extra fields). File Manager keeps the list toolbar search (T Hidden,
New dir, Copy/Cut/Paste, columns Size/Name/Modified/Owner/Permissions) like
Kloxo-MR; listing uses kloxo-backend on loopback 7776 when the panel is not
root, with in-process fallback. File Manager beige tabs are File Manager,
Permissions, Ownership, Disk Usage, Upload, Thumbnail. On show the first
tab is **File Manager** (selected; not Home) with the list toolbar and
columns (T/Size/File Name/Modified/Owner/Permissions/Rename/Download).
Clicking **Permissions** keeps the ffile path, selects that tab, and shows
the Kloxo-MR Change Permissions form (User/Group/Others matrix, Totals,
Target All, Change Permssions Recursively, Change). Clicking **Ownership**
keeps the path, selects that tab, and shows Change Ownership (Current,
User/Group selects, Change Ownership Recursively, Change; web user is
`www-data` on Ubuntu). Clicking **Disk Usage** goes to `a=show&sa=diskspace`
(selected) and lists files with Size plus **% Of Tot**. Clicking **Upload**
keeps the path, selects that tab, and shows three forms (local Upload with
overwrite warning + progress, Upload (http), Upload (ftp)). Clicking
**Thumbnail** uses lowercase `a=updateform&sa=thumbnail` (selected) with
Width/Height default `20` and Update. Live File Manager after deploy needs a
tree with the getFullUrl path fallback. Backup / Restore has exactly five beige tabs
(**Backup / Restore** selected on show, **FTP Configuration**, **Schedule Configuration**,
**File Manager**, **Upload**): show has Backup Now / Restore From File /
Restore From FTP; clicking **FTP Configuration** keeps the lxbackup path and
selects that tab (FTP Server, Username, Password, Directory, Upload Files To
Remote Server, Upload Type `ftp`, Dont Keep A Local Copy, Dont Verify FTP
Credentials, Update / Updateall). Clicking **Schedule Configuration** keeps the
path and selects that tab (Schedule Backup select default `disabled`, Time To
Backup default `6` for admin, Keep This Many Backups On The Server default `5`,
Update / Updateall; schedule privilege uses `checkButton`, tab always listed).
File Manager under backup keeps those five tabs with **File Manager** selected
(`goback=1` so Backup / Restore is not also selected): Address
`(Backup / Restore: …) /`, toolbar T Hidden / Remove / Copy (+ Refresh/search/
Showall), columns T / Size / File Name / Modified / Owner / Permissions / Rename /
Download. Clicking **Upload** keeps lxbackup+ffile path with **Upload** selected
(`goback=1`): three sections Upload / Upload (http) / Upload (ftp) (local file
with overwrite warning + progress upload, URL + overwrite, FTP Server/Username/
Password/File Name + overwrite). Bare Home tab label maps to **Backup / Restore**
when current object is lxbackup (same frm_o_o path fix as PHP Configure). Live
Backup FTP/Schedule/File Manager/Upload after deploy is UNVERIFIED. LxGuard first beige tab is **LxGuard**. LxGuard show has Switch To Another (pserver list, at least localhost)
and the Connections / Raw Connections / White List tabs; those list tabs
also omit the Find/search input. SSH Terminal is admin-only (under the server, not client
home) and shows a WebConsole-like black terminal as root via kloxo-backend
(no Java applet, no NOPASSWD sudoers). Install seeds a Welcome HelpDesk ticket (Created By Kloxo). HelpDesk ticket show skips Find and lists Switch To Another. List/driver calls guard a missing
Ubuntu driver so the page does not TypeError. Re Read IPaddress
uses `ipaddress::fixstatus` and live `ip -4 addr` on Ubuntu.
Directory Index list does not `count()` a missing `dirindexlist_a`.
Traceroute parses both `traceroute` and `tracepath` output; response times
show three decimal places and unreachable hops (`no reply` / `*`) show as `*`
(not `no`). Live Traceroute after deploy is UNVERIFIED.
Cron Tasks Mail To does not need the C daemon on TCP 7776.
DNS Template links list `dnstemplate`, not Backup/Restore.
The DNS Templates list has the add form on that tab; there is no separate
Add this template tab. The add form leaves DNS Template Name, Primary DNS
and Secondary DNS empty. Adding a DNS template does not throw `no_ip_address` on an empty IP table
(live `ip -4 addr` is used; IPs are typed when that list is empty), does not run `fixIpAddress.php`,
qualifies a blank or short nameserver to `ns1.<template>.dnst`, and writes default NS/A/MX records into
`dns_record_a`. Secondary NS is validated separately from primary.
FTP Configure is `serverftp` under localhost, not Backup FTP Configuration;
the beige tab is labelled **FTP Configure**.
The Services list shows only installed units (`install_state=on`); running
and boot status come from systemd `ActiveState` / `UnitFileState`
(aliases: bind9/named, mariadb/mysqld, phpX.Y-fpm). Duplicate RHEL/Debian
names collapse to one row (`apache2` not `httpd`, `bind9` not `named`,
`mariadb` not `mysqld`). Service start/stop/restart/enable/disable uses
absolute `systemctl --no-block` via argument-array `proc_open`
(`Service__Linux::systemctlRun`); driver includes use `__DIR__`. Stop/disable/restart
of panel-critical units is refused on click (`mariadb`/`mysql`/`mysqld`/`mariadbd`,
`kloxo-php`, `kloxo-web`, `kloxo-backend`, `kloxo-wrap`, `hiawatha`) — stopping
MariaDB blanks every page with `No such file or directory` (missing DB socket).
Start/Stop/Restart icons stay visible for every row (headers Start / Stop / Restart);
Sb and State are view-only for those critical units. Icon clicks call
`runServiceControl` (systemctl via the service driver) immediately so list
children are not skipped by `login->was()`. Start/enable of critical units remains
allowed. Live Services actions after deploy are UNVERIFIED.
`httpd` and `apache2` are the same Apache daemon (RHEL vs Debian unit name);
`named` and `bind9` are the same BIND daemon. Default SQL is **MariaDB**
(`systemctl enable --now mariadb`); `mysql`/`mysqld` alias rows are not listed
as a second installed service while MariaDB is present. A distinct MySQL
package unit (different FragmentPath) stays disabled until started from Services.
FTP Sessions lists `ftpsession` under localhost `pserver`, not a parentless client list.
Mails Per Client lists under the selected pserver and shows Switch To Another.
Admin Servers can add a remote host (hostname or IPv4 plus the slave password)
on the list page; there is no separate Add Server tab. Adding a client does
not implode() a string (contact email / coma_* lists), does not TypeError
on a missing plan priv/listpriv or parent quota, and chowns the home to
www-data on Ubuntu. Adding a Resource Plan
does not need a DNS template, does not TypeError on a missing listpriv, and
accepts a display name with spaces (sanitized to a plan id).
`install.sh --slave` writes `slave-db.db` and starts `kloxo-backend` on TLS 7779
(all interfaces); plaintext 7776 stays on localhost. Live two-host add is unverified.
Home keeps
Find / Information / Resource on the left and the dashboard tiles
on the right.
First-party `ereg*`/`split`
and live theme `escape`/`document.all` are gone. Browser login as
admin/admin against the live panel succeeded; every page still died
in the Server menu at the SSH Client title until `kloxo_menu_title`
and `install.sh` git-pull/rsync. Re-run install after pulling this
tree, then re-check Home.
Mail Server Settings under localhost has exactly three beige tabs (**Mail Server
Settings** selected on `sa=update`, **Spamdyke**, **Whitelist IPs**), Switch To
Another (pserver list), and the Kloxo-MR update form (My Name, Enable Spamdyke,
Enable Domainkey/DKIM, Enable Virus Scan, Max Mail Attachment Size default
41943040, Queue Life Time 604800, Max SMTP Instances 50, SMTP Relay, Send Limit
30000, Update). Clicking **Spamdyke** keeps the servermail path and selects that
tab (Greet Delay, max recipients 150, graylisting, RDNS/MX reject flags, DNS RBL
Servers textarea, Blacklist Headers, Update). Clicking **Whitelist IPs** selects
that tab and lists `mail_graylist_wlist_a` (add Whitelist IP + list column). Live
Mail Server Settings after deploy is UNVERIFIED.
Next: remaining P2 runtime, Debian packaging, or P4 exim UI. See briefing
section 7.

## Commands
```
php -l <file>                         # lint with PHP 8.3
bash tests/tls/run-tests.sh           # generate-tls-keys checks (php-cli + openssl; safe locally)
php kloxo/bin/install/generate-tls-keys --help
bash tests/remote/run-tests.sh        # C1 auth-before-unserialize checks (php-cli only)
bash tests/security/run-tests.sh      # P0.4/P0.5 source invariants (php-cli only)
bash tests/p1/run-tests.sh            # P1 stack-shrink source invariants (php-cli only)
bash tests/p2/run-tests.sh            # P2 first-party PHP 8 parse + autoload + UFW (php-cli only)
bash tests/p3/run-tests.sh            # P3 kloxocp CLI + snapshot/rollback (php-cli only)
bash tests/install/run-tests.sh       # Ubuntu schema + Hiawatha installer invariants (php-cli only)
php kloxo/bin/install/init-panel-db --help
php kloxo/bin/install/init-slave --help
php kloxo/bin/install/install-pdns --help
php kloxo/bin/install/install-apache --help
php kloxo/bin/install/install-webfront --help
bash kloxo/bin/install/install-hiawatha --help
bash tests/mail/run-tests.sh          # mail checks (needs MariaDB root socket; disposable machine only)
python3 tests/mail/e2e.py             # 25 live mail checks (needs running Exim/Dovecot/Rspamd; see tests/mail/README.md)
php kloxo/bin/mail/kloxo-mail help
php kloxo/bin/kloxocp help            # status, doctor, snapshot, rollback; update exits 2
```

## Hard rules
- Never weaken the security defaults in `docs/MAIL_DESIGN.md` section 3 or `docs/AUDIT_REPORT.md`.
- Do not mass-rename `lx*` identifiers, DB columns or the `lxlabs` service account.
- Keep AGPLv3 notices and `NOTICE`; no secrets or private keys in the repo.
- New code: PHP 8.3, `declare(strict_types=1)`, prepared statements, no shell strings, validated input, tests.
- Service config changes: render, validate with the service's own checker, atomic install, roll back on failure.
- Say exactly what you tested and what you did not. Update `docs/` as you go.
