# AGENTS.md: Kloxo CP

**Read `docs/HANDOFF_BRIEFING.md` first**; it is the source of truth for decisions, state and the work plan.

## What this is
Kloxo CP: a hosting control panel forked from Kloxo-MR (AGPLv3), being modernised for Ubuntu 24.04
and Ubuntu 26.x,
Nginx + PHP-FPM default for sites (Apache selectable), Hiawatha for the panel itself, no billing for now, existing UI kept,
mail via Exim + Dovecot + Rspamd + MariaDB (templates done; re-run mail tests on Ubuntu), easy install/update with automatic
rollback (designed, not built).

## Status
10.1-2026110601. NOT safe to run. The legacy PHP does not run on PHP 8.3. Audit C1–C5 for phase 1a
(`process_server_input` auth, daemon bind/TLS/size, committed keys, setuid helpers, easyinstaller
HTTP) are closed. P1 web/DNS shrink is done (Nginx + panel Hiawatha; BIND/PowerDNS; qmail, courier-imap and
ezmlm code deleted, mail dirs under `/var/vmail`). P2 first-party `php -l` is clean and `include.php` loads on PHP 8 (`tests/p2/test_boot.php`).
P3 CLI skeleton is in (`kloxo/bin/kloxocp`: status, doctor, snapshot, rollback).
`install.sh` bootstraps Ubuntu 24.04 or 26.x from https://github.com/ARDZIVE/kloxocp.git
(Hiawatha 12.4 source build + `init-panel-db`; MariaDB 11.8 row-size rewrite;
`ufw`, `pure-ftpd`, `redis-server`, and `imagemagick` are packaged (`ufw`
**enabled by default**; Redis and Pure-FTPd enabled; ImageMagick for File Manager Thumbnail);
install defaults to `--profile=full`. File Manager Thumbnail no longer probes
with CentOS `rpm -q ImageMagick` (checks `/usr/bin/convert` / `magick`). The
README one-liner downloads `install.sh` from
`raw.githubusercontent.com/.../main/install.sh?nocache=$(date +%s)` (cache-busting; Exim, Dovecot, Rspamd;
Postfix not co-installed with Exim (Switch Program apt-swaps);
Ubuntu universe enabled when needed;
no SpamAssassin/sqlite; bogofilter is not offered), then that script
fetches `origin/main` and hard-resets when the clone already exists;
`kloxocp update` and signed apt packaging are not). Hiawatha is not an apt package. Default panel login is admin/admin; the UI requires a password
change after first login (required Change Password modal with the form
inside; Esc/Close cannot skip it). Simplicity Status toast auto-opens for
alerts/info and content shows matching inline alerts (Status peek does not
consume the message). Admin Servers list seeds `localhost`. The Update
screen shows **10.1-2026110601** and note **Kloxo fork by Hampik TALATINIAN** (`kloxocp update` still exits 2).
Switch Program DNS default is BIND (`pg_dns=bind`); PowerDNS is packaged and
prepared (`install-pdns`) but the `pdns` unit stays disabled until selected
(apt postinst is blocked from starting it).
Web default is Nginx (`pg_web=nginx`); Apache is packaged and prepared
(`install-apache`, PHP-FPM via proxy_fcgi, no mod_php) but `apache2` stays
disabled until selected. Admin SQL Manager opens phpMyAdmin in a new tab
(`/thirdparty/phpMyAdmin/kloxo-signon.php`, `target="_blank"`): a live
panel-admin session is signed in as MariaDB `kloxo_pma`@`127.0.0.1` (not `root`: root@localhost is unix_socket, error 1698 over TCP) and
redirected; otherwise the MySQL logon form is shown (panel admin may
leave the password blank; signed CSRF, sign-on cookie path `/`). Not mywebsql, not a second Domain tile. Component Info
lists Ubuntu packages (nginx, apache2, bind9, pdns-server, mariadb-server,
pure-ftpd, redis-server, exim4, postfix, dovecot, rspamd, phpX.Y-cli/fpm) plus source-built Hiawatha, with
on/off from dpkg. SMTP `none` / `exim` (default, `pg_smtp=exim`) / `postfix`
(apt-swaps with Exim on select; POP3/IMAP default is **Dovecot**
(`pg_pop3=dovecot`; Switch Program lists none/dovecot; `pop3__dovecot` uses
apt + systemctl, not qmail supervise; live switch UNVERIFIED); hosted mail is Exim via `kloxo-mail`;
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
by default. Switch Program Spam none/spamassassin install through `KloxoApt` (bogofilter was removed: driver, option and package entry are gone) (`lib/php/kloxo_apt.php`: non-interactive `apt-get`, one retry after `apt-get update`, error text names the package and the apt `E:` line; the old `yum`/`rpm` calls were why it said Install failed); SpamAssassin also enables `spamd` and writes per-mailbox `user_prefs` without qmail/vpopmail (validated path, no directive injection) but is not wired into Exim (Rspamd is); live switch UNVERIFIED. Switch Program Web Cache offers **none** (default; the Nginx side cache on 8080 -> 80 stays a Watchdog service) and **nginx**: an Nginx proxy cache in front of the Nginx or Apache site server (`bin/install/install-webcache --mode=front|none`, `KloxoWebcache`, `webcache__nginx`; varnish/squid/ATS stay gone). Selecting nginx: Pserver::updateSwitchProgram saves the driver, runs `fixweb` so the site templates listen on 127.0.0.1:8081 (http) and 127.0.0.1:8444 (https, TLS; nginx `listen_*_back*.conf`, Apache `$bind/$httpPort/$httpsPort`), then `install-webcache` renders the cache config (cache on 80/443/8080; HTTPS terminated with the certificate each domain already uses, from `etc/webfront/domains/<domain>.json` written next to the site config), validates with `nginx -t`, moves Apache `ports.conf` to loopback and trusts `X-Forwarded-For` from 127.0.0.1 (nginx `real_ip`, Apache `remoteip`), reloads the site servers, restarts `kloxo-webcache`, checks the ports, and rolls files back on failure; if it fails the driver is reverted and `fixweb` re-run. The cache never stores requests with session/login cookies, an Authorization header, `/wp-admin`, `/wp-login.php`, `/administrator` or ACME challenges; default TTL 60 s for 200/301 when the site sends no cache headers; `install-webfront` keeps the front config while `etc/flag/webcache_front.flg` exists. Tested only against fake nginx/systemctl/apache2 binaries (`tests/install/test-webcache.php`, `tests/p2/test_webcache.php`); the rendered cache config has NOT been through a real `nginx -t`, and a live switch (80/443 hand-over, Apache restart, certificates, per-domain on/off, purge) is UNVERIFIED. Webmail System Default lists installed dirs under
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
`firewall`/`f021`, collage `ufwfirewall_*.gif`) with beige tab **UFW Firewall** (UFW **enabled by default** at install): list-only show (no inline add form); toolbar **Refresh / Add Rule / Delete**; multi-select checkboxes; **Port Rules** pink toolbar, Advanced Search; columns #/Action/Protocol/Port/From/Comment/Actions (no Family — IPv4+IPv6 pairs collapsed to one row); Actions icons are Font Awesome Free SVGs (`theme/fontawesome/`, CC BY 4.0, listed in `NOTICE`): edit (pen-to-square) / pause (suspend: rule is removed from the UFW tables but stays in the list) or play (unsuspend: re-added to UFW) / trash; suspended rows show a red ● beside Action; Add/Edit on separate `updateform` pages (`sa=addrule` / `sa=editrule`; no rule list on those pages; Edit has beige tabs **UFW Firewall** / **Edit**, and the rule number is a hidden field) with Action DROP (default, ufw `deny`) / ACCEPT / REJECT, Protocol TCP (default) / UDP / ICMP and optional Comment; every field (Action, Protocol, Port incl. lists/ranges, From IPv4/IPv6/CIDR, Comment) is editable, live rules are re-applied to UFW immediately (delete + add, original restored if the add fails) and suspended rules can be edited in the store; the edit logic is tested against `tests/p2/fake-ufw.php` (not real ufw); default install rules (21, 22, 25, 53 tcp/udp, 80, 110, 143, 443, 465, 587, 993, 995, 7777, 7778, 8080, 30080) carry their purpose as the UFW comment (`KloxoUfw::defaultComments`; older uncommented default rules show the same text in the list and edit form, saved on first edit); no Enable/Disable/defaults UI; `KloxoUfw` via kloxo-backend; deny/reject of panel ports and deleting the last panel allow are refused. Live UFW after deploy is UNVERIFIED. Watchdog under localhost lists the Kloxo-MR eight services
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
allowed. Start/Stop/Restart/Sb/State on a local unit call `Service::runDirect`: `Service__Linux::controlUnit` sends `systemctlRunDetail` to kloxo-backend as root (the panel user cannot stop units; the generic object sync fell back to it silently), waits for the unit's `ActiveState`, and throws `Could not <act> service <x>: <reason>` instead of doing nothing (reason names a non-root `systemctl` run when the backend did not answer). The State and Sb icons now read the real `ActiveState`/`UnitFileState` first (`Service::refreshLiveState`, also in `initThisObject`): the `service` table has no state columns, so a freshly loaded object defaulted to `off` and clicking a running unit sent `start`, an enabled one `enable` (nothing visible happened). `tests/p2/test_service_toggle.php` (6 checks); also exercised in a local panel copy with a fake systemctl and a stand-in for the C daemon (Sb click on an enabled unit sent `disable`, State click on a running unit sent `stop`, Start/Stop/Restart ran through the backend round trip). Service icon clicks (Sb, State, Start, Stop, Restart) open a modal with a simple animated GIF spinner (`theme/img/kloxo-spinner.gif`, generated for this project, no third-party asset; `theme/js/kloxo-busy.js`, `KloxoBusy.show/hide`, included from `print_include_jscript`; any link/form can opt in with `data-kloxo-busy`) that stays until the request finishes and the next page replaces it (no time-out; hidden again on `pageshow`); `tests/p2/test_busy_spinner.php` (source, GIF and URL-pattern checks only; an earlier SVG version was seen in a browser, the GIF version was not re-run in one). Live Services actions after deploy are UNVERIFIED.
`httpd` and `apache2` are the same Apache daemon (RHEL vs Debian unit name);
`named` and `bind9` are the same BIND daemon. Default SQL is **MariaDB**
(`systemctl enable --now mariadb`); `mysql`/`mysqld` alias rows are not listed
as a second installed service while MariaDB is present. A distinct MySQL
package unit (different FragmentPath) stays disabled until started from Services.
FTP Sessions lists `ftpsession` under localhost `pserver`, not a parentless client list.
Mails Per Client lists under the selected pserver and shows Switch To Another.
Admin Servers can add a remote host (hostname or IPv4 plus the slave password)
on the list page; there is no separate Add Server tab. Adding a domain no longer fails with
`Unsupported operand types: string + int`: quota counters (`priv`/`used`) are strings (`''`, `Unlimited`), and
`changeUsedFromParent`, `check_priv`, `getEffectivePriv`, parent-quota checks and `delete` now do arithmetic through
`kloxo_quota_num()` (`lib/php/lxlib.php`; `tests/p2/test_quota_math.php`). The rest of the domain-add path (DB writes,
web/DNS/mail sync) was not run without a live panel DB; live Add Domain after deploy is UNVERIFIED. Mail Account > Set Auto Responder with no autoresponders shows "First add some autoresponders" as a green info message, not an error (`lxInfoException` in `lib/php/lxlib.php`, caught in `theme/coredisplaylib.php` and printed with `print_inline_alert(..., 'info')`; the Set / Enable / Disable Auto Responder pages show the same five beige tabs as the Auto Responders list (`Mailaccount::createShowPropertyList`; which tab is highlighted in the browser is UNVERIFIED); other `lxException` text from `do_updateform` is printed plain instead of "The requested form could not be loaded."; `tests/p2/test_autoresponder_form.php`; live UNVERIFIED). Mail Account dashboard tiles (Password, Limit, Mail Forwards, Filter Configure, Configure, Spam Training, Auto Responders, Webmail) use local Font Awesome Free SVGs (`HtmlLib::mailTileFaName`/`faTileIcon`, `theme/fontawesome/svgs/solid/`; `tests/p2/test_mail_tile_icons.php`; browser look UNVERIFIED). Adding a client does
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
Settings** selected on `sa=update`, **SMTP Protection**, **Whitelist IPs**), Switch To
Another (pserver list), and the Kloxo-MR update form (My Name, Enable SMTP Protection,
Enable Domainkey/DKIM, Enable Virus Scan, Max Mail Attachment Size default
41943040, Queue Life Time 604800, Max SMTP Instances 50, SMTP Relay, Send Limit
30000, Update). Clicking **SMTP Protection** keeps the servermail path and selects that
tab (Greet Delay, max recipients 150, graylisting, RDNS/MX reject flags, DNS RBL
Servers textarea, Blacklist Headers, Update). Clicking **Whitelist IPs** selects
that tab and lists `mail_graylist_wlist_a` (add Whitelist IP + list column). Live
Mail Server Settings after deploy is UNVERIFIED.
SMTP Protection replaces Spamdyke (the Mail Server Settings tab that was called Spamdyke; Spamdyke itself is
not installed or used). The settings are saved as `/etc/kloxo/mail-policy.json` (`KloxoMailPolicy`,
`lib/php/kloxo_mailpolicy.php`) and installed by `kloxo-mail policy:apply --mta=exim|postfix` for the SMTP server
chosen in Switch Program (`pg_servermail` follows `pg_smtp`; drivers `servermail__exim`/`__postfix`/`__none`;
`migrateServermailDriver` fixes older installs; selecting another SMTP re-applies the saved policy). Exim: the
settings render into `exim4.conf` (connect `delay`, `$recipients_count`, rDNS/RBL/`verify = sender` rules, `$h_` header
rules, whitelist hostlist; no policy file = old behaviour). Postfix: `postconf`/`postconf -M` edits with state file, backup,
`postfix check` and rollback (postscreen greet wait, `smtpd_recipient_limit`, `reject_unknown_*`, `reject_rbl_client`,
`header_checks`, `check_client_access cidr:`; open-relay guard). Graylisting on both is Postgrey on 127.0.0.1:10023
(`/etc/default/postgrey` `--delay`/`--max-age`; a dead Postgrey defers). Approximations: Postfix "RDNS contains the IP"
is a regexp table, Postfix header rules also apply to locally submitted mail (`header_checks`). Saving the form
with the prefilled RBL list activates DNSBL. The first-tab fields (My Name, Domainkey, Virus Scan, Max Mail Attachment
Size, Queue Life, Max SMTP Instances, SMTP Relay, Send Limit) are still not wired to a service. Tested:
`tests/p2/test_mailpolicy.php` (69 checks: renderer, rendered Exim text, Postfix plan, the Postfix tool against Apple
postconf 3.2.2 in a throw-away directory, panel hand-off with fakes), `tests/install/test_migrate_servermail.php`,
`tests/p2/test_server_pages.php`. NOT verified: any real Exim (`exim -bV` not run), a real Postfix `postfix check`/reload/
postscreen, a live Postgrey, a real SMTP session through the rules, the panel UI flow on Ubuntu. Also fixed: the
template renderer's `@@NAME@@` pattern did not match digits, so `@@EXIM_DISABLE_IPV6@@` was never substituted in
`exim4.conf` (it would have failed `exim -bV` and rolled the apply back).
`init-panel-db` seeds the `dbadmin` row `mysql___localhost` (MariaDB account
`kloxo_pma`, password from `pma.pass`) so Add Database no longer fails with
"no Database administrator entries"; live database creation is UNVERIFIED.
phpMyAdmin admin SSO reads the panel session JSON in `<root>/session/<id>`
(the `ssession` table is unused; before this fix SQL Manager always showed the
MySQL logon form on a live server); live SSO after deploy is UNVERIFIED.
`install.sh` re-runs keep an existing admin password
(`init-panel-db --keep-admin-password`) unless `KLOXO_ADMIN_PASSWORD` is set.
`install.sh` waits for the dpkg/apt lock (fresh servers run
unattended-upgrades) via a temporary `apt.conf.d/99kloxo-install`
(`DPkg::Lock::Timeout`) plus `wait_for_apt_locks`; the one-liner uses
`-o DPkg::Lock::Timeout=600`. Tested only for syntax and the wait helper on a
non-Ubuntu host; a live run on Ubuntu 26.04 is UNVERIFIED.
Switch Program audit: `updateSwitchProgram` now only treats the six driver keys (web/webcache/dns/spam/pop3/smtp) as programs (before, `use_pagespeed` reached `switchDriver` as class `use`), rejects names not on the offered list, no longer reinstalls the web server on every Update, calls `/script/fixmail` (there is no `/script/fixmmail`) after spam/pop3/smtp switches, and Exim/Postfix/Dovecot installs go through `KloxoApt` (non-interactive, errors shown, old choice kept). `tests/p2/test_switchprogram.php` runs this in a copy of the tree with fake apt-get/systemctl; web driver switch, real apt and a live server are UNVERIFIED.
Recovery console (`https://<host>:7777/recovery/`, `httpdocs/recovery/index.php`, `lib/php/kloxo_recovery.php`, `lib/php/kloxo_recovery_page.php`): works with MariaDB, Nginx and Apache down. Own login (Argon2id hash in `etc/conf/recovery.json`, 0640 root:lxlabs; `install.sh` generates it once and saves the password to `/root/kloxo-recovery-password`; `kloxocp recovery-password --generate|--password-env=VAR`), HTTPS only, CSRF token, own session under `var/recovery`, 5 failures per address locks 15 min, idle 15 min. Whitelisted units only; panel units (kloxo-web/php/backend) start/enable only. Root work (systemctl, journalctl) goes through `rl_exec_get` to kloxo-backend (`KloxoRecovery::serverControl/serverJournal`); the legacy client is included only for a logged-in request. `kloxo-backend` master mode now survives a missing database (`db_degraded`, retries in `do_server_stuff`), `lx_exception_handler` shows a 503 with a link when mysqli reports 2002/2003/2006/2013, `kloxo-web.service` and a MariaDB drop-in (`mariadb.service.d/kloxo-restart.conf`) restart on failure. `tests/p2/test_recovery.php` (52 checks, fake systemctl/journalctl) plus a manual run of a real backend in a temp tree (round trip while the DB was unreachable). NOT verified: real systemd as root, Hiawatha/FPM serving the page, the MariaDB-down page flow on Ubuntu, a reboot with MariaDB failing.

Watchdog (`kloxo-watchdog` systemd service, `bin/kloxo-watchdog`, `lib/php/kloxo_watchdog.php`, `init/kloxo-watchdog.service`; replaces the RHEL-era `watchdog__sync::watchRun`, now a no-op): standalone root loop (cPanel chkservd model), no panel, no MariaDB. Every 60 s it checks the enabled units (web nginx/apache2 by driver, webcache, webproxy, dns bind9/pdns, mail exim4/postfix, pop3 dovecot, ftp, mariadb, every phpX.Y-fpm, rspamd, redis, kloxo-php/web/backend): unit not `active`, or active but its port/socket not answering (3 tries), -> `reset-failed` + `restart`, then waits for it to answer. Skips: boot grace 300 s, not installed, not enabled, masked, activating, **held**, switched off on the panel's Watchdog screen (`etc/watchdog.conf`) or in `etc/conf/watchdog.json` (`interval`, `max_restarts` 3, `window` 1800, `grace`, `notify`, `disabled`). A unit stopped/disabled on purpose from the recovery console or the panel Services page is held (`var/watchdog/hold/<unit>`) and released on start/restart. After `max_restarts` in the window it gives up (one "GAVE UP" mail, shown in the recovery console) and tries again after the window; one mail per unit per window to `notify` or the admin contact email from `etc/slavedb/contactemail` (written by `save_admin_contactemail` when the admin saves the profile via `Lxclient::postUpdate`, and by `init-panel-db` `syncAdminContactEmail` for existing installs; with no address the watchdog logs `no admin email set` and the recovery console shows a warning; `kloxo-watchdog --test-mail` sends a test). Log `log/watchdog.log`; `kloxo-watchdog --status`; the recovery console has a Watchdog column. `tests/p2/test_watchdog.php` (35 checks: fake systemd, sockets, clock, mailer). NOT verified: a real systemd run as root, real sockets, real sendmail, a live crash/restart on Ubuntu, `install.sh` enabling the unit.
Fail2Ban (Security > **Fail2Ban**, admin only; `lib/php/kloxo_fail2ban.php`, `driver/pserver/fail2banlib.php`, `bin/install/install-fail2ban`, `install.sh prepare_fail2ban`): packages `fail2ban python3-systemd nftables` are installed by default; the installer's SSH client is whitelisted. The page lists 16 jails (SSH, recidive, panel + recovery logins via `log/auth-failures.log`, Exim, Dovecot, Postfix, Nginx, Apache, Pure-FTPd, MariaDB) with availability, state, per-jail Max Retry/Find Time/Ban Time, failed/banned counters, enable/disable and edit icons, a Banned IPs list with Unban, Ban IP / Unban All / Reload / Settings (defaults + whitelist) and Recent Activity from `/var/log/fail2ban.log`. `/etc/fail2ban/jail.d/kloxo-cp.local` is generated from `etc/conf/fail2ban.json` (render, `fail2ban-client -t`, atomic install, reload, rollback of file and settings); bans use the nftables action, not UFW; loopback is always whitelisted, ban refuses whitelisted/own addresses. Watchdog keeps `fail2ban` running. `tests/p2/test_fail2ban.php` (72 checks, fake `fail2ban-client`/systemctl) and `test_server_pages.php`; a real Fail2Ban, nftables, journal matches, live bans and a live install are UNVERIFIED.
`install.sh --fresh` (`fresh_wipe`; asks for YES, `--yes` skips, refuses without a terminal) removes the previous install (units, program root and default clone, panel DB `kloxo` plus accounts `kloxo`/`kloxo_pma`, saved passwords, Kloxo Fail2Ban jails) and then installs normally; customer sites, mail, customer DBs, UFW rules and apt packages are kept. Tested only as a sandboxed function run (fake systemctl/mysql, `tests/install/run-tests.sh`); a live run is UNVERIFIED.
Site PHP on a release the ondrej PPA does not serve (Ubuntu 26.04 resolute answers 404): `install-site-php` checks `dists/<codename>/Release` first, removes a leftover dead `ondrej-*php*` source (it broke every `apt-get update`, and the one-liner), skips versions apt cannot supply, continues past failures, and writes `log/install-site-php.status` (`epoch|running|ok|partial|failed|message`). Multiple PHP Install (`KloxoPhpver::availableToInstall`, cached PPA probe `etc/conf/sitephp-ppa.json`) lists only installable versions and shows an Install Status note. Tested with probes/fakes (`tests/install/test_phpver.php`, `run-tests.sh`); live extra-version install is UNVERIFIED.
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
php kloxo/bin/install/install-webcache --help
php kloxo/bin/install/install-fail2ban --help
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
