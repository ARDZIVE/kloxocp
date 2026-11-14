# AGENTS.md: Kloxo CP

**Read `docs/HANDOFF_BRIEFING.md` first**; it is the source of truth for decisions, state and the work plan.

## What this is
Kloxo CP: a hosting control panel forked from Kloxo-MR (AGPLv3), being modernised for Ubuntu 24.04
and Ubuntu 26.x,
Nginx + PHP-FPM default for sites (Apache selectable), Hiawatha for the panel itself, no billing for now, existing UI kept,
mail via Exim + Dovecot + Rspamd + MariaDB (templates done; re-run mail tests on Ubuntu), easy install/update with automatic
rollback (`kloxocp upcp` / `update`: snapshot then auto-rollback on failure).

## Status
10.1-2026111406. NOT safe to run. The legacy PHP does not run on PHP 8.3. Audit C1–C5 for phase 1a
(`process_server_input` auth, daemon bind/TLS/size, committed keys, setuid helpers, easyinstaller
HTTP) are closed. CentOS/RHEL installer (`kloxo/install/`), yum/rpm drivers and CentOS-only pscripts are removed (Ubuntu apt/systemd only). P1 web/DNS shrink is done (Nginx + panel Hiawatha; BIND/Knot DNS; qmail, courier-imap and
ezmlm code deleted, mail dirs under `/var/vmail`). P2 first-party `php -l` is clean and `include.php` loads on PHP 8 (`tests/p2/test_boot.php`).
P3 CLI skeleton is in (`kloxo/bin/kloxocp`: status, doctor, snapshot, rollback).
`get` / `install.sh` bootstraps Ubuntu 24.04 or 26.x from https://github.com/ARDZIVE/kloxocp.git (one-liner: `curl …/get | sudo bash`)
(Hiawatha 12.4 source build + `init-panel-db`; MariaDB 11.8 row-size rewrite;
`ufw`, `pure-ftpd`, `redis-server`, and `imagemagick` are packaged (`ufw`
**enabled by default**; Redis and Pure-FTPd enabled; ImageMagick for File Manager Thumbnail);
install defaults to `--profile=full`. File Manager Thumbnail no longer probes
(ImageMagick via `/usr/bin/convert` / `magick`; CentOS rpm/yum paths removed). The README one-liner is `curl -fsSL …/get | sudo bash` (`get` fetches `install.sh`;
install is pipe-safe via `exec </dev/null`, prints `[1/8]…[8/8]` steps, auto-sets a
localhost hostname from the primary IP; Exim, Dovecot, Rspamd;
Postfix not co-installed with Exim (Switch Program apt-swaps);
Ubuntu universe enabled when needed;
no SpamAssassin/sqlite; bogofilter is not offered). `install.sh` then
fetches `origin/main` and hard-resets when the clone already exists
(`kloxocp update`/`upcp` is built with snapshot + auto-rollback; signed apt packaging is not). Hiawatha is not an apt package. Default panel login is admin/admin; the UI requires a password
change after first login (required Change Password modal with the form
inside; Esc/Close cannot skip it; the form posts to the logged-in client and
does not inherit the page underneath, which made Update throw unsettable
`old_password_f` on Services). Right after that, a required **Mail Setup**
modal asks for any missing fields so the panel can send mail to users:
admin **Email Address** (real inbox, not example.com; used as From for
welcome/notify and for watchdog alerts) and a dotted **Mail Server Hostname**
FQDN (saved as localhost `realhostname`, written to `/etc/mailname` when the
backend can; not an IP or short name like `srv`). Esc/Close cannot skip it
(`kloxo-mail-setup-force`; `ClientCore::updateMailsetup`). Live first-login
mail setup on Ubuntu is UNVERIFIED. Simplicity Status toast auto-opens for
alerts/info (`#div_cfstatus` and `print_inline_alert` share `.kloxo-toast`:
top-centre, white, light shadow, typed icon info/success/warning/error;
`kloxo-toast.js` hides after 15s). Status peek does not consume the message;
content does not print a second toast on simplicity. Unread HelpDesk tickets do not set `you_have_unread_ticket`
(no Status toast or inline text); a red dot sits on the top-left Ticket pill
(`#div_ticket .kloxo-unread-dot`) when `unread_flag` is on. Admin Servers list seeds `localhost`. The Update
screen shows **10.1-2026111406** and note **Kloxo fork by Hampik TALATINIAN** (`kloxocp upcp` updates with auto-rollback).
Switch Program DNS default is BIND (`pg_dns=bind`); **Knot DNS replaces PowerDNS** (all pdns code,
templates, `install-pdns` and the MariaDB `powerdns` user are gone): `knot knot-dnsutils knot-dnssecutils` are packaged and
prepared (`install-knot`) but the `knot` unit stays disabled until selected (apt postinst is blocked from
starting it; a re-run of `install.sh` leaves an enabled Knot/Apache alone). Details in the Knot DNS note below.
Web default is Nginx (`pg_web=nginx`); Apache is packaged and prepared
(`install-apache`, PHP-FPM via proxy_fcgi, no mod_php) but `apache2` stays
disabled until selected. Apache catch-all (`file/apache/tpl/defaults.conf.tpl`)
must not use `ServerAlias *`: on Apache 2.4 that wildcard steals every Host
from named domain vhosts so sites hit the empty `/home/kloxo/httpd/default`
and return 403 (`Options -Indexes`); unknown Hosts still use the first-listed
vhost. Live mesrob.com after Switch Program → Apache was fixed by removing that
alias and seeding a default `index.html` (`tests/p2/test_apache_defaults.php`,
`tests/p1` invariant); a re-run of fixweb before `upcp` can rewrite `init.conf`
from an old template copy under `/opt/configs/apache/tpl`. Admin SQL Manager opens phpMyAdmin in a new tab
(`/thirdparty/phpMyAdmin/kloxo-signon.php`, `target="_blank"`): a live
panel-admin session is signed in as MariaDB `kloxo_pma`@`127.0.0.1` (not `root`: root@localhost is unix_socket, error 1698 over TCP) and
redirected; otherwise the MySQL logon form is shown (panel admin may
leave the password blank; signed CSRF, sign-on cookie path `/`). Not mywebsql. SQL Manager **tile** (phpMyAdmin, new tab) is **admin-only**: pserver Basic (`webmailanddb`), not home Domain. A client click on a database opens SQL Manager as that mysqldb's own MariaDB user with `only_db` (never `kloxo_pma`). Component Info
lists Ubuntu packages (nginx, apache2, bind9, knot, mariadb-server,
pure-ftpd, redis-server, exim4, postfix, dovecot, rspamd, phpX.Y-cli/fpm) plus source-built Hiawatha, with
on/off from dpkg. SMTP `none` / `exim` (default, `pg_smtp=exim`) / `postfix`
(apt-swaps with Exim on select; POP3/IMAP default is **Dovecot**
(`pg_pop3=dovecot`; Switch Program lists none/dovecot; `pop3__dovecot` uses
apt + systemctl, not qmail supervise; live switch UNVERIFIED); hosted mail is Exim via `kloxo-mail`; DNS `none` (and the Services page Sb icon, and `KloxoMta::turnOff`) now disable the REAL unit: on Debian/Ubuntu `bind9.service` is an alias of `named.service` and systemd refuses `enable`/`disable` on an alias ("Refusing to operate on alias name or linked unit file"), so `systemctl disable --now bind9` did nothing and BIND kept green Sb and State; `kloxo_dns_canonical_unit` / `systemctl show -p Id` name the real unit and the stop is issued separately (`tests/p2/test_dns_alias.php`, fake systemctl modelling the alias refusal; real Ubuntu UNVERIFIED, cause inferred from systemd behaviour). Switching Dovecot, Rspamd or an MTA to `none` goes through `KloxoMta::turnOff` (`lib/php/kloxo_mta.php`): stop + disable the unit and its `.socket`, read the boot state back, `update-rc.d -f <unit> remove` if still on, then (Dovecot, Rspamd) raise `Could not turn off at boot: <unit> (UnitFileState=..., SourcePath=...)` instead of leaving a green Sb; it also refuses with "Switch Program must run as root" when the backend did not answer and the call would run as the panel user. Switch Program Spam is **none/rspamd** only (SpamAssassin driver removed); Rspamd may be switched to `none` only when POP3/IMAP and SMTP are both `none` in the same submit (otherwise a clear refuse message); Services cannot start/stop/enable/disable Rspamd (`switchProgramOnlyUnits`); per-mailbox spam enable/disable UI is gone. Cause of a green Dovecot Sb on a live server NOT established (all code paths read: `disable` was already issued), so this is a guard + diagnostic; UNVERIFIED on Ubuntu. an Exim<->Postfix switch retires the old MTA with `KloxoMta` (`lib/php/kloxo_mta.php`): stop + disable, apt remove, then `daemon-reload` and disable again (apt keeps `/etc/init.d/<mta>` and its rc links, which systemd turns into an enabled generated unit, so the old MTA kept a green Sb icon; `update-rc.d -f <unit> remove` if it is still on). Second cause: the leftover SysV script makes systemd generate a unit whose `UnitFileState` is always `generated`, which `Service__Linux::bootStateOn` counted as enabled (green Sb whatever `disable` did); `generated` from `/etc/init.d/<unit>` is now decided by the `/etc/rc[2-5].d/S??<unit>` links, and a SysV script with no native unit file whose dpkg package is not installed (`rc`) is a removed leftover (`isRemovedSysvLeftover`): its row STAYS on the Services list after Switch Program replaces it (Exim after choosing Postfix, and the reverse) with State and Sb both off (red), and its icons refuse with "X is not installed (it was replaced in Switch Program)" (`tests/p2/test_mta_leftover.php`, fake probes). The row stays only while the script (a dpkg conffile that `apt remove` keeps) exists; a purged package drops off the list. Call order checked with fake systemctl/apt-get in `tests/p2/test_switchprogram.php`; the cause is inferred from Debian packaging, not reproduced, and the real Ubuntu behaviour is UNVERIFIED;
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
by default. Switch Program Spam none/rspamd install through `KloxoApt` (bogofilter and SpamAssassin were removed: drivers, options and package entries are gone) (`lib/php/kloxo_apt.php`: non-interactive `apt-get`, one retry after `apt-get update`, error text names the package and the apt `E:` line; the old `yum`/`rpm` calls were why it said Install failed); selecting rspamd enables the `rspamd` unit (and stops leftover `spamd`/`spamassassin`); Exim/Postfix already talk to Rspamd on 11333 via `kloxo-mail`; live switch UNVERIFIED. The Switch Program form no longer shows the **Use Pagespeed** checkbox or the **Cache** (web cache) list (removed on request; the Web Cache driver stays `none`, and the Pagespeed flag is no longer written by that form). The back end still accepts `webcache_driver=nginx` (used by the tests) but no UI sets it. Web Cache offers **none** (default; the Nginx side cache on 8080 -> 80 stays a Watchdog service) and **nginx**: an Nginx proxy cache in front of the Nginx or Apache site server (`bin/install/install-webcache --mode=front|none`, `KloxoWebcache`, `webcache__nginx`; varnish/squid/ATS stay gone). Selecting nginx: Pserver::updateSwitchProgram saves the driver, runs `fixweb` so the site templates listen on 127.0.0.1:8081 (http) and 127.0.0.1:8444 (https, TLS; nginx `listen_*_back*.conf`, Apache `$bind/$httpPort/$httpsPort`), then `install-webcache` renders the cache config (cache on 80/443/8080; HTTPS terminated with the certificate each domain already uses, from `etc/webfront/domains/<domain>.json` written next to the site config), validates with `nginx -t`, moves Apache `ports.conf` to loopback and trusts `X-Forwarded-For` from 127.0.0.1 (nginx `real_ip`, Apache `remoteip`), reloads the site servers, restarts `kloxo-webcache`, checks the ports, and rolls files back on failure; if it fails the driver is reverted and `fixweb` re-run. The cache never stores requests with session/login cookies, an Authorization header, `/wp-admin`, `/wp-login.php`, `/administrator` or ACME challenges; default TTL 60 s for 200/301 when the site sends no cache headers; `install-webfront` keeps the front config while `etc/flag/webcache_front.flg` exists. Tested only against fake nginx/systemctl/apache2 binaries (`tests/install/test-webcache.php`, `tests/p2/test_webcache.php`); the rendered cache config has NOT been through a real `nginx -t`, and a live switch (80/443 hand-over, Apache restart, certificates, per-domain on/off, purge) is UNVERIFIED. Webmail System Default is **Roundcube** only (`Mmail::getWebmailProgList`;
Afterlogic/Rainloop/Squirrelmail/Telaen/chooser dropped; empty/legacy chooser
maps to roundcube). Panel FPM hides PHP errors (`log/php.err`). The middle column
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
Customer sites default to the newest installed PHP-FPM; extra series
(8.1–8.4, 7.4, 5.6) come from `https://packages.sury.org/php/`
(`install-site-php`, deb822 Signed-By; not ppa:ondrej/php) and can be
chosen on Webserver Configure (PHP Used / PHP Branch list phpX.Y — not php52m).
Each domain stores `php_selected` and gets a pool in `/etc/php/X.Y/fpm/pool.d/`
with socket `/run/php/<domain>-phpX.Y.sock`. Web Features PHP Selected sends `KloxoPhpfpm::applyDomain` to
kloxo-backend (`rl_exec_get`; a pool failure is the real reason);
nginx `-t` does not run Apache configtest.
Webserver Configure section forms (PHP Used/Branch,
Multiple PHP, PHP Type, Apache optimize, MariaDB convert, fix ownership,
Pagespeed) load without the panel DB; Multiple PHP Install drops
`log/sitephp.request` and sends `KloxoPhpver::runDroppedRequest` to
kloxo-backend (`rl_exec_get`; the panel user cannot systemd-run or apt);
if 7776 does not answer, the backend 30s loop and kloxo-watchdog run the
job; that queues `install-site-php` for the selected `phpX.Y` ids (job
under `$ROOT/log/`, not `/tmp`, because `kloxo-backend` uses `PrivateTmp`);
the Webserver Configure page stays open and shows a progress bar per
phpX.Y (`theme/js/kloxo-sitephp.js` polls `theme/lbin/sitephp-progress.php`,
admin-only JSON of `log/sitephp.jobs`); when a job finishes the version
moves between Multiple PHP Install and Multiple PHP Remove; a leftover
queued bar after apt is done is completed to 100% (`completeIdleJobs`)
instead of staying at “Waiting to start removal”; the bars animate until
the lock is gone, then the lists refresh and the progress box is hidden;
Multiple PHP Remove sends
`KloxoPhpver::queueRemove` (`apt-get purge --auto-remove` of the `phpX.Y`
metapackage, `phpX.Y-fpm`/`cli`/`common` and the other series packages as
root, after stopping `phpX.Y-fpm`; no lxlabs fallback) so they
leave Remove and reappear on Install; PHP Used stays off the Remove list
so Update does not abort extras; the job only marks ok when the cli/fpm
binaries are gone; the same progress bars show the purge; Apache optimize writes
`kloxo-optimize.conf` when Web is Apache; when Web is Nginx it rewrites
`worker_processes` / `worker_connections` / `keepalive_timeout` in `nginx.conf`
(with `nginx -t` rollback). PHP Type / Memory Optimize labels follow Switch
Program Web (Nginx vs Apache); on Nginx, PHP Type explains PHP-FPM and keeps
Multiple PHP secondary; on Apache, PHP Type is proxy_fcgi + mpm_event/worker;
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
`firewall`/`f021`, collage `ufwfirewall_*.gif`) with beige tab **UFW Firewall** (UFW **enabled by default** at install): list-only show (no inline add form); toolbar **Refresh / Add Rule / Delete**; multi-select checkboxes; **Port Rules** pink toolbar, Advanced Search; columns #/Action/Protocol/Port/From/Comment/Actions (no Family — IPv4+IPv6 pairs collapsed to one row); Actions icons are Font Awesome Free SVGs (`theme/fontawesome/`, CC BY 4.0, listed in `NOTICE`): edit (pen-to-square) / pause (suspend: rule is removed from the UFW tables but stays in the list) or play (unsuspend: re-added to UFW) / trash; suspended rows show a red ● beside Action; Add/Edit on separate `updateform` pages (`sa=addrule` / `sa=editrule`; no rule list on those pages; Edit has beige tabs **UFW Firewall** / **Edit**, and the rule number is a hidden field) with Action DENY (default, ufw `deny`) / ALLOW / REJECT, Protocol TCP (default) / UDP / ICMP and optional Comment; every field (Action, Protocol, Port incl. lists/ranges, From IPv4/IPv6/CIDR, Comment) is editable, live rules are re-applied to UFW immediately (delete + add, original restored if the add fails) and suspended rules can be edited in the store; the edit logic is tested against `tests/p2/fake-ufw.php` (not real ufw); default install rules (21, 22, 25, 53 tcp/udp, 80, 110, 143, 443, 465, 587, 993, 995, 7777, 7778, 8080, 30080) carry their purpose as the UFW comment (`KloxoUfw::defaultComments`; older uncommented default rules show the same text in the list and edit form, saved on first edit); no Disable/defaults UI (Enable UFW button when inactive; `updateUfwenable` → `KloxoUfw::enable`); `KloxoUfw` via kloxo-backend; deny/reject of panel ports and deleting the last panel allow are refused. Live UFW after deploy is UNVERIFIED. Watchdog under localhost lists the Kloxo-MR eight services
(add form + Switch To Another; mail uses `__driver_mail`; webcache 8080
and webproxy 30080 are nginx frontends started by `install-webfront`). SSH Configure beige tab is **SSH Configure** (not Home). Log/driver/php/backup/webserver/SSH Configure/LxGuard/SSH Terminal/File Manager/HelpDesk ticket/Notification/Message/Update/Manage DNS/Mail Queue/PHPinfo show pages
omit the left Find box (Message and Auxiliary Login lists keep the search toolbar). Auxiliary Login show (`a=show` of `name.aux`) and SSL Certificate show (`sslcert`) also omit Find (`kloxo_skip_find_show_class`; SSL list toolbar search stays). Manage DNS beige tabs are Manage DNS, Rebuild, NS, A, AAAA, CNAME, FCNAME, MX, TXT, CAA, SRV, General Settings; show lists `dns_record_a` without `write()`/`setUpdateSubaction` (template records often lack `parent_clname`; dirtying the zone made `login->was()` sync DNS and bounce Home). Live Manage DNS after deploy is UNVERIFIED. Client PHPinfo is web `a=show&sa=phpinfo` (native phpinfo tables/CSS of the site PHP; Domain Home tile **PHPinfo**); `getTrueParentO`/`getParentO` treat `client-admin` as root (`isRootClient`) and never print `no parent_clname`. Live PHPinfo after deploy is UNVERIFIED. Change Owner (`a=updateform&sa=changeowner`) builds a client select without calling methods on a missing parent; `Lxclient::updateform` does not load `general` first; help text does not explode a null string. Live Change Owner after deploy is UNVERIFIED. SSL Certificates under a domain has beige tabs SSL Certificates, Add SSL File, Add SSL Text, Add Let's Encrypt, Add SSL Link, Switch To Another of sibling domains, and columns User Name, Certificate Name, Add Type, Parent Domain, Syncserver (list toolbar search stays). Domain SSL install / Let's Encrypt / self-signed run as root via kloxo-backend (`KloxoSsl`, certbot or acme.sh, `/home/kloxo/ssl`); failure stays on the addform (`__kloxo_redirect_back`), success opens the SSL list (`tests/p2/test_ssl.php`; live UNVERIFIED). HelpDesk ticket show has Switch To Another
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
Download sends `Content-Disposition: attachment` with no panel HTML around the
bytes (`kloxo_send_download_headers`; `do_display_init` skips the page chrome).
Clicking **Permissions** keeps the ffile path, selects that tab, and shows
the Kloxo-MR Change Permissions form (User/Group/Others matrix, Totals,
Target All, Change Permssions Recursively, Change). Clicking **Ownership**
keeps the path, selects that tab, and shows Change Ownership (Current,
User/Group selects, Change Ownership Recursively, Change; web user is
`www-data` on Ubuntu). Clicking **Disk Usage** goes to `a=show&sa=diskspace`
(selected) and lists files with Size plus **% Of Tot** (`16,384.00 (13%) / 0`;
column is on that URL, not a one-shot session `duflag`). Clicking **Upload**
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
when current object is lxbackup (same frm_o_o path fix as PHP Configure). File Manager
and Upload under Backup create `__backup/` locally if kloxo-backend is down, keep
`ttype=directory` for `/`, and the address bar is `(Backup / Restore: …) /`.
Schedule privilege is read from the parent client (`checkButton`). Backup Now /
Restore for admin and client go through `KloxoBackup` (absolute backup.php /
restore.php, argument-array mysqldump, `/var/vmail` mail     packs, Ubuntu
`www-data` on restore; PHP 8 `unbackup` constant and null fileserv crashes
fixed). Restore lists archive members and refuses `../` / absolute paths
(`KloxoBackup::extractArchive`); `kloxo.file` is unserialized with an
allow-list (`unserializeMetadata`), not a raw `unserialize`. Backup Now writes the archive into the same `__backup/` directory the
File Manager tab lists (`KloxoBackup::ensureDir` / `installFile`, owned by
`lxlabs`); Restore From File picks from that folder. Backup Now on kloxo-backend runs `lxbackup::backupAccountInProcess` in-process
(sets Last Backup Status in the same request; `backup.php` stays for CLI/cron).
`clearLxbackup` forces `dbaction=update`; pid locks are absolute and released.
UI Restore skips the scheduled `--priority=low` sleep. Restore From File waits
(`restoreAccountInProcess` / `runRestore`), applies web/DNS/mail/DKIM/certificates/DNS IPs,
and lists leftover human steps on Last Restore Status. Help and the Backup Now / Restore forms show what is
saved and what you still do by hand. Client Restore From File: unselected domains/clients set `dbaction = clean`
    (was `==`, a no-op that still restored them); empty account select means
    all; `restoreAccountInProcess` clears `__var_list_flag` and defaults
    `_accountselect` to `all`; `doCoreRestore` falls back `kloxo.file`→`lxadmin.file`
    on Throwable and skips notify mail when `contactemail` is empty.
    `tests/p2/test_backup.php` (81 checks). Live Backup Now / Restore From File /
    File Manager listing after deploy is UNVERIFIED. LxGuard first beige tab is **LxGuard**. LxGuard show has Switch To Another (pserver list, at least localhost)
and the Connections / Raw Connections / White List tabs; those list tabs
also omit the Find/search input. SSH Terminal is admin-only (under the server, not client
home) and shows a WebConsole-like black terminal as root via kloxo-backend
(no Java applet, no NOPASSWD sudoers). Install seeds a Welcome HelpDesk ticket (Created By Kloxo). HelpDesk ticket show skips Find and lists Switch To Another. SSH Authorized Keys under localhost lists `/root/.ssh/authorized_keys` (via kloxo-backend); Full Key is prefilled with this server's public key (`id_ed25519.pub`, else rsa/ecdsa, else `/etc/ssh/ssh_host_*_key.pub`; generates `id_ed25519` as root only if none exist); Add appends; private key is not shown; `~/.ssh/config` is not written; Unix usernames are restricted (`^[A-Za-z_][A-Za-z0-9_-]{0,31}$`, no `/` or `..`) (`tests/p2/test_sshauthorizedkey.php`; live UNVERIFIED). List/driver calls guard a missing
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
The Services list shows the catalog (installed or not), same idea as recovery: a **Status** column with systemd `ActiveState` only (for example `active` / `inactive` / `not installed`, no SubState in parentheses). Missing packages stay dull. Ubuntu `postfix.service` is often an alias of `postfix@-.service`; `hiawatha` resolves to `kloxo-web`. `systemctl --system` is used so a user bus does not hide system units. The catalog includes **fail2ban**, **ufw**, **redis-server**, kloxo-webcache/webproxy/backend/watchdog as well as nginx/apache2/bind9/knot/exim4/postfix/dovecot/rspamd/mariadb/php-fpm/kloxo-php/hiawatha/pure-ftpd; running
and boot status come from systemd `ActiveState` / `UnitFileState`
(aliases: bind9/named, mariadb/mysqld, phpX.Y-fpm). Duplicate RHEL/Debian
names collapse to one row (`apache2` not `httpd`, `bind9` not `named`,
`mariadb` not `mysqld`). Service start/stop/restart/enable/disable uses
absolute `systemctl --no-block` via argument-array `proc_open`
(`Service__Linux::systemctlRun`); driver includes use `__DIR__`. Stop/disable/restart
of panel-critical units is refused on click (`kloxo-php`, `kloxo-web`, `kloxo-backend`, `kloxo-wrap`, `hiawatha`, `sqlite`/`sqlite3`)
— Hiawatha (`kloxo-web`) and the panel SQLite file must stay up; stopping them is
refused. MariaDB (`mariadb`/`mysql`/`mysqld`/`mariadbd`) is not critical: the panel
database is SQLite, so Stop/Sb/State on the MariaDB row work. The panel's own database is `/var/lib/kloxo/panel.sqlite` (`Sgbl::__var_database_type=sqlite`,
`KloxoStore` WAL factory, `KloxoSqlite`, `init-panel-db --sqlite-only`): login and the UI work with MariaDB
and every other service stopped. Stats live in `/var/lib/kloxo/stats.sqlite` (daily/monthly plus top pages,
referrers, status, UA, hashed visitors — migration `002_visitor_details`);
raw domain logs stay under `/var/log/kloxo/domains/` (Nginx dual-writes
`access.log` there and the legacy custom_log; logrotate; never in SQLite).
Domain list stats icon and Domain Home open in-panel **Visitor Stats**
(`n=web&a=show&sa=stats`, `KloxoStats` / `Web::printVisitorStats`);
`kloxo-stats.timer` runs the aggregator every 5 minutes (`install_stats_timer`).
Tested: `tests/p2/test_stats.php`, `test_storage.php`. Live traffic on Ubuntu is UNVERIFIED. Hiawatha reads only rendered files
(`kloxo-regen`: flock, `hiawatha -k -c`, rename or keep old). Nightly `VACUUM INTO` keeps 7
(`kloxo-backup-sqlite`). `tests/p2/test_sqlite_panel.php`, `tests/p2/test_storage.php`. Live panel
on SQLite after deploy is UNVERIFIED.
Stopping MariaDB used to blank every page (`No such file or directory` missing DB socket);
that path is the SQLite file now.
Start/Stop/Restart icons stay visible for every row (headers Start / Stop / Restart);
Sb and State are view-only for those critical units. Icon clicks call
`runServiceControl` (systemctl via the service driver) immediately so list
children are not skipped by `login->was()`. Start/enable of critical units remains
allowed. Start/Stop/Restart/Sb/State on a local unit call `Service::runDirect`: `Service__Linux::controlUnit` sends `systemctlRunDetail` to kloxo-backend as root (the panel user cannot stop units; the generic object sync fell back to it silently), waits for the unit's `ActiveState`, and throws `Could not <act> service <x>: <reason>` instead of doing nothing (reason names a non-root `systemctl` run when the backend did not answer). The State and Sb icons now read the real `ActiveState`/`UnitFileState` first (`Service::refreshLiveState`, also in `initThisObject`): the `service` table has no state columns, so a freshly loaded object defaulted to `off` and clicking a running unit sent `start`, an enabled one `enable` (nothing visible happened). `tests/p2/test_service_toggle.php` (12 checks); redirect alerts (`print_redirect_back`/`_success`) now URL-encode the message (`HtmlLib::redirectQueryValue`): `kloxo_html` turned `'` into `&#039;` and the unencoded `#` cut every alert at its first quote or `&` (a pdns journal line was shown only up to "controlsocket in"), and the failure text lists error-looking journal lines before the last ones; Start/Restart no longer fail silently: `systemctl --no-block start` returns 0 even when the daemon then dies, so `Service__Linux::systemctlRunDetail` (root backend) now waits for the unit and, if it is `failed`/`inactive`/restart-looping, returns the journal tail (`describeStartFailure`, shown as `Could not start service X: X is failed after start: ...`; checked in a local panel copy with a fake systemctl/journalctl, not on real systemd); also exercised in a local panel copy with a fake systemctl and a stand-in for the C daemon (Sb click on an enabled unit sent `disable`, State click on a running unit sent `stop`, Start/Stop/Restart ran through the backend round trip). Busy modal on every panel page (simple animated GIF spinner `theme/img/kloxo-spinner.gif`, generated for this project, no third-party asset; `theme/js/kloxo-busy.js`, `KloxoBusy.show/hide`, included from `print_include_jscript` and on the login page): any navigation away from a page (link, form submit, `location=`, `form.submit()`) starts it on `beforeunload`, but the modal only appears when the navigation has taken longer than `DELAY` (500 ms, counted from the click across the page change; quick pages never build it). The delay is a JS timer in the live document (old page while the server has not answered, then the new page), not a CSS fade; once shown it carries over to the next page at once (`shown` in the flag), so redirect hops have no gap; the busy flag is kept in `sessionStorage` so the next page shows the modal again from its `<head>` (before `<body>` exists) and hides it at `DOMContentLoaded` (= the streamed response is complete; no time-out). A page that ends in `<meta http-equiv=Refresh content="0;URL=...">` (the redirect after an action) keeps it up until the target page has loaded. Not shown for navigations that leave the page on screen: downloads (`frm_subaction=download`, `filedownload.php`), `mailto:`/`tel:`/`#`, `download`/`target` links or forms, forms with a chosen file (`input[type=file]` with a value: own progress; the panel renders every form as `multipart/form-data`, so the encoding is not used), anything marked `data-kloxo-nobusy`. The modal is opaque again (a 70% transparent version was tried and removed on request): dark 45% backdrop, solid white box; the spinner GIF keeps its transparent background. Service icons (Sb, State, Start, Stop, Restart) still run in the background via `fetch` and then follow the redirect. `data-kloxo-busy="text"` sets the text. Tested: `tests/p2/test_busy_spinner.php` (source checks) and a throw-away page set in the Cursor browser (quick page and a 250 ms page: overlay never built; 4 s streamed page: on at ~515 ms, off at DOMContentLoaded; page with no bytes for 3 s: on at ~510 ms in the old page; 2 s page redirecting to a 4 s page: on at ~518 ms, kept across the hop, off at the end; earlier, before the delay: form submit, `location=`, `form.submit()`: modal on until complete; download, mailto, `target=_blank`: no modal; the flag is cleared at the end); NOT tested: the real panel pages, the left-frame/`mainframe` targets (links with a `target` are skipped), a visual check that the new document paints the modal while streaming (in the iframe harness the browser kept showing the old frame until the response ended; only the DOM state was observed), Hiawatha in front. Live Services actions after deploy are UNVERIFIED.
`httpd` and `apache2` are the same Apache daemon (RHEL vs Debian unit name);
`named` and `bind9` are the same BIND daemon. Customer SQL is **MariaDB**
(`systemctl enable --now mariadb`); `mysql`/`mysqld` alias rows are not listed
as a second installed service while MariaDB is present. A distinct MySQL
package unit (different FragmentPath) stays disabled until started from Services.
FTP Sessions lists `ftpsession` under localhost `pserver`, not a parentless client list.
Mails Per Client lists under the selected pserver and shows Switch To Another.
It reads `/var/log/exim4/mainlog` (then `mail.log` / `maillog`) and counts Exim
authenticated and local submissions plus Postfix `from=` for the last two days
(real `user@domain.tld` only; Unix-user@hostname such as lxlabs@srv is skipped).
Auxiliary Login is `name.aux` (customer staff `client_staff.aux`): login becomes
the parent client with `__auxiliary_object`; action log/session store
`auxiliary_id`; Disable Admin Login needs at least one auxiliary and only
blocks the name `admin`. `delete_flag` is enforced on delete; `pserver_flag`
gates the Server menu and pserver list (`canSeePserver`). New passwords use
Argon2id. `tests/p2/test_auxiliary.php`; live login UNVERIFIED.
Admin Servers can add a remote host (hostname or IPv4 plus the slave password)
on the list page; there is no separate Add Server tab. Adding a domain no longer fails with
`Unsupported operand types: string + int`: quota counters (`priv`/`used`) are strings (`''`, `Unlimited`), and
`changeUsedFromParent`, `check_priv`, `getEffectivePriv`, parent-quota checks and `delete` now do arithmetic through
`kloxo_quota_num()` (`lib/php/lxlib.php`; `tests/p2/test_quota_math.php`). Disable Policy auto-disables an
account when usage reaches the percentage: General Disable Percentage
(`dpercentage`, default 110) unless the account/plan sets Disable When Usage
Reaches (`disable_per`, including `off`); Notify Policy (`npercentage`, default
90) is the warning. Admin Home → Disable Policy is that panel-wide percentage (exactly one beige tab **Disable Policy**)
(plus Disable notify), not `can_not_set_own_limit`; a customer opening it on
themselves still cannot set their own tripwire. `collectquota` uses
`kloxo_quota_policy_check` (PHP 8-safe; `tests/p2/test_disable_policy.php`). Live
collectquota / admin Disable Policy form after deploy is UNVERIFIED.
The rest of the domain-add path (DB writes,
web/DNS/mail sync) was not run without a live panel DB; live Add Domain after deploy is UNVERIFIED. Mail Account > Set Auto Responder with no autoresponders shows "First add some autoresponders" as a green info message, not an error (`lxInfoException` in `lib/php/lxlib.php`, caught in `theme/coredisplaylib.php` and printed with `print_inline_alert(..., 'info')`; the Set / Enable / Disable Auto Responder pages show the same five beige tabs as the Auto Responders list (`Mailaccount::createShowPropertyList`; which tab is highlighted in the browser is UNVERIFIED); other `lxException` text from `do_updateform` is printed plain instead of "The requested form could not be loaded."; `tests/p2/test_autoresponder_form.php`; live UNVERIFIED). Mail Account dashboard tiles (Password, Limit, Mail Forwards, Filter Configure, Configure, Spam Training, Auto Responders, Webmail) use local Font Awesome Free SVGs (`HtmlLib::mailTileFaName`/`faTileIcon`, `theme/fontawesome/svgs/solid/`; `tests/p2/test_mail_tile_icons.php`; browser look UNVERIFIED). Adding a client does
not implode() a string (contact email / coma_* lists), does not TypeError
on a missing plan priv/listpriv or parent quota, and chowns the home to
www-data on Ubuntu. Add Client runs `KloxoClientUnix::ensure` through
kloxo-backend before the redirect stub so the Unix account exists before
Add Domain / PHP-FPM (`php-fpm -t` no longer hits `cannot get uid for user`);
`KloxoPhpfpm::applyDomain` ensures the pool user again; full sync stays on
`client-sync.request` (`tests/p2/test_client_unix.php`; live Add Client →
Add Domain confirmed). Adding a Resource Plan
does not need a DNS template, does not TypeError on a missing listpriv, and
accepts a display name with spaces (sanitized to a plan id). Fresh install
seeds Resource Plan `default_plan` (`default_plan___client-admin`, Unlimited
quotas, localhost server pools) so Add Client has a plan; re-run does not
overwrite an existing row (`init-panel-db` `seedResourceplan`).
`install.sh --slave` writes `slave-db.db` and starts `kloxo-backend` on TLS 7779
(all interfaces); plaintext 7776 stays on localhost. Live two-host add is unverified.
Home keeps
Find / Information / Resource on the left and the dashboard tiles
on the right. The beige navigation crumb does not repeat the signed-in
client or its parent (`admin — {Clients}` / `mesrob — {Domains}`,
not `… — Clients — admin — {…}`). Client Domains (`a=list&c=domain`) keeps the
Kloxo-MR columns (S, T, DNS/traffic/addon/phpinfo/view/DNS-check/preview/webmail/stats,
Domain Name, Date, Document Root, Traffic) even when `priv` or `general`
is missing; the icon stubs immediately before Domain Name (`pvview_f`,
`check_dns_f`, `dnvview_f`, `webmail_f`, `awstats_f`) have blank titles
(type-b empty labels no longer fall back to the class name `domain`).
Live client Domains after deploy is UNVERIFIED.
SQL Manager tile is admin-only (pserver Basic; not home Domain or domain show). Clients open a selected database in SQL Manager as that database's own MariaDB user (`only_db`), not `kloxo_pma`. Live SQL Manager after deploy is UNVERIFIED.
Client Administration (`__title_administer`) has no Login As tile (`sa=dologin`). **Hostname** is on the server page and Server menu only (`pserver` `sa=hostname`; not Administration): dotted FQDN, explanation text, `KloxoHostname` via kloxo-backend (`hostnamectl`, `/etc/hostname`, `/etc/mailname`, `/etc/hosts`); `tests/p2/test_hostname.php`; live UNVERIFIED. Live client home after deploy is UNVERIFIED.
First-party `ereg*`/`split`
and live theme `escape`/`document.all` are gone. First login after
install no longer fatals with `Attempt to assign property "__session_id" on null`
(`kloxo_restore_cookie_login` only assigns when `$login` is an object;
unknown/auxiliary cookies clear through to `/login/`; `tests/p2/test_login_session.php`).
Live first login after this fix is UNVERIFIED. Browser login as
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
that tab (same three tabs, first tab still **Mail Server Settings**; list `createListAlist`
does not `goback=1`, which would drop servermail and relabel the first tab) and lists
`mail_graylist_wlist_a` (add Whitelist IP + list column). Live
Mail Server Settings after deploy is UNVERIFIED.
SMTP Protection replaces Spamdyke (the Mail Server Settings tab that was called Spamdyke; Spamdyke itself is
not installed or used). The settings are saved as `/etc/kloxo/mail-policy.json` (`KloxoMailPolicy`,
`lib/php/kloxo_mailpolicy.php`) and installed by `kloxo-mail policy:apply --mta=exim|postfix` as root via kloxo-backend (php-cli, not php-fpm). Exim without a completed `mail.ini` uses a Debian `conf.d` overlay (`acl_kloxo_*` then `acl_check_rcpt`); a full `kloxo-mail apply` still runs when hostname and Rspamd are configured. Chosen
in Switch Program (`pg_servermail` follows `pg_smtp`; drivers `servermail__exim`/`__postfix`/`__none`;
`migrateServermailDriver` fixes older installs; selecting another SMTP re-applies the saved policy). Exim: the
settings render into `exim4.conf` (connect `delay`, `$recipients_count`, rDNS/RBL/`verify = sender` rules, `$h_` header
rules, whitelist hostlist; no policy file = old behaviour). Postfix: `postconf`/`postconf -M` edits with state file, backup,
`postfix check` and rollback (postscreen greet wait, `smtpd_recipient_limit`, `reject_unknown_*`, `reject_rbl_client`,
`header_checks`, `check_client_access cidr:`; open-relay guard). Graylisting on both is Postgrey on 127.0.0.1:10023
(`/etc/default/postgrey` `--delay`/`--max-age`; a dead Postgrey defers). Approximations: Postfix "RDNS contains the IP"
is a regexp table, Postfix header rules also apply to locally submitted mail (`header_checks`). First visit (empty lists):
greet delay 3, graylisting on, missing-MX on, rDNS rejects off, DNS RBL prefilled `zen.spamhaus.org` + `bl.spamcop.net`
(no UCEPROTECT), header rules for Mass Mailer / Flooder / Subject ADV:, both Enable Default checkboxes on so Update
installs them. Saving the form with the prefilled RBL list activates DNSBL. The first-tab fields (My Name, Domainkey, Virus Scan, Max Mail Attachment
Size, Queue Life, Max SMTP Instances, SMTP Relay, Send Limit) are still not wired to a service. Tested:
`tests/p2/test_mailpolicy.php` (75 checks: renderer, rendered Exim text, Postfix plan, the Postfix tool against Apple
postconf 3.2.2 in a throw-away directory, panel hand-off with fakes), `tests/install/test_migrate_servermail.php`,
`tests/p2/test_server_pages.php`. NOT verified: any real Exim (`exim -bV` not run), a real Postfix `postfix check`/reload/
postscreen, a live Postgrey, a real SMTP session through the rules, the panel UI flow on Ubuntu. Also fixed: the
template renderer's `@@NAME@@` pattern did not match digits, so `@@EXIM_DISABLE_IPV6@@` was never substituted in
`exim4.conf` (it would have failed `exim -bV` and rolled the apply back).
`init-panel-db` seeds the `dbadmin` row `mysql___localhost` (MariaDB account
`kloxo_pma`, password from `pma.pass`) so Add Database no longer fails with
"no Database administrator entries". Add Database creates a real MariaDB
database (`KloxoMariadb`: CREATE DATABASE utf8mb4, CREATE USER + ALTER USER +
GRANT on localhost / 127.0.0.1 / `%`; IDENTIFIED BY quotes the password,
native `?` is MariaDB 1064; the old `mysql` extension `exit` and
`GRANT ... IDENTIFIED BY` are gone). Live database creation on Ubuntu is UNVERIFIED.
phpMyAdmin admin SSO reads the panel session JSON in `<root>/session/<id>`
(the `ssession` table is unused; before this fix SQL Manager always showed the
MySQL logon form on a live server); live SSO after deploy is UNVERIFIED.
`install.sh` re-runs keep an existing admin password
(`init-panel-db --keep-admin-password` when `/var/lib/kloxo/panel.sqlite` already
exists; not MariaDB `kloxo.client`) unless `KLOXO_ADMIN_PASSWORD` is set.
`install.sh` waits for the dpkg/apt lock (fresh servers run
unattended-upgrades) via a temporary `apt.conf.d/99kloxo-install`
(`DPkg::Lock::Timeout`) plus `wait_for_apt_locks`, and `apt_update` /
`repair_apt_cache` drop a half-written `pkgcache.bin` / `srcpkgcache.bin`
(needrestart rename ENOENT / "package cache file is corrupted") then retry;
`get` does the same when it must apt-install curl. The one-liner uses
`-o DPkg::Lock::Timeout=600`. Tested for bash -n and source invariants on a
non-Ubuntu host; a live run on Ubuntu 26.04 is UNVERIFIED.
Switch Program audit: `updateSwitchProgram` now only treats the six driver keys (web/webcache/dns/spam/pop3/smtp) as programs (before, `use_pagespeed` reached `switchDriver` as class `use`), rejects names not on the offered list, no longer reinstalls the web server on every Update, calls `/script/fixmail` (there is no `/script/fixmmail`) after spam/pop3/smtp switches, and Exim/Postfix/Dovecot installs go through `KloxoApt` (non-interactive, errors shown, old choice kept). `tests/p2/test_switchprogram.php` runs this in a copy of the tree with fake apt-get/systemctl; web driver switch, real apt and a live server are UNVERIFIED.
Recovery console (`https://<host>:7777/recovery/`, `httpdocs/recovery/index.php`, `lib/php/kloxo_recovery.php`, `lib/php/kloxo_recovery_page.php`): works with MariaDB, Nginx and Apache down. Own login (Argon2id hash in `etc/conf/recovery.json`, 0640 root:lxlabs; `install.sh` generates it once and saves the password to `/root/kloxo-recovery-password`; `kloxocp recovery-password --generate|--password-env=VAR`), HTTPS only, CSRF token, own session under `var/recovery`, 5 failures per address locks 15 min, idle 15 min. Whitelisted units only (including postfix); panel units (kloxo-web/php/backend) start/enable only. Starting, restarting or enabling nginx/apache2, bind9/knot or exim4/postfix stops and disables the other of the pair so both cannot run. Installed units that are not enabled at boot get an **Enable at boot** button (`systemctl enable` on the canonical unit). Root work (systemctl, journalctl) goes through `rl_exec_get` to kloxo-backend (`KloxoRecovery::serverControl/serverJournal`); the legacy client is included only for a logged-in request. `kloxo-backend` master mode now survives a missing database (`db_degraded`, retries in `do_server_stuff`), `lx_exception_handler` shows a 503 with a link when mysqli reports 2002/2003/2006/2013, `kloxo-web.service` and a MariaDB drop-in (`mariadb.service.d/kloxo-restart.conf`) restart on failure. Debian `bind9.service` is an alias of `named.service` and `postfix.service` of `postfix@-.service`: recovery asks for those real units too (a multi-unit `systemctl show` can omit the alias names, which listed bind9 and postfix as not installed), retries each missing unit on its own, and treats a unit file / cgroup as installed when systemd still says not-found. `systemctl --system` is used so a user bus does not hide system units. Start/stop/journal use the canonical unit. Overview shows exact systemd status `ActiveState (SubState)` (for example `active (running)`). `tests/p2/test_recovery.php` (76 checks, fake systemctl/journalctl, bind9→named and postfix→postfix@- aliases, unit-file fallback, phpX.Y-fpm) plus a manual run of a real backend in a temp tree (round trip while the DB was unreachable). Login, overview and journal use the panel red/beige/green (inline CSS only; CSP stays `style-src 'unsafe-inline'`): Actions sit next to State so the buttons stay on screen, installed rows list first. Tested against HTML dumps in a local browser. NOT verified: real systemd as root, Hiawatha/FPM serving the page, the MariaDB-down page flow on Ubuntu, a reboot with MariaDB failing, live bind9 row on Ubuntu.

Watchdog (`kloxo-watchdog` systemd service, `bin/kloxo-watchdog`, `lib/php/kloxo_watchdog.php`, `init/kloxo-watchdog.service`; replaces the RHEL-era `watchdog__sync::watchRun`, now a no-op): standalone root loop (cPanel chkservd model), no panel, no MariaDB. Every 60 s it checks the enabled units (web nginx/apache2 by driver, webcache, webproxy, dns bind9/knot, mail exim4/postfix, pop3 dovecot, ftp, mariadb, every phpX.Y-fpm, rspamd, redis, kloxo-php/web/backend): unit not `active`, or active but its port/socket not answering (3 tries), -> `reset-failed` + `restart`, then waits for it to answer. Skips: boot grace 300 s, not installed, not enabled, masked, activating, **held**, switched off on the panel's Watchdog screen (`etc/watchdog.conf`) or in `etc/conf/watchdog.json` (`interval`, `max_restarts` 3, `window` 1800, `grace`, `notify`, `disabled`). A unit stopped/disabled on purpose from the recovery console or the panel Services page is held (`var/watchdog/hold/<unit>`) and released on start/restart. After `max_restarts` in the window it gives up (one "GAVE UP" mail, shown in the recovery console) and tries again after the window; one mail per unit per window to `notify` or the admin contact email from `etc/slavedb/contactemail` (written by `save_admin_contactemail` to the program root, not a cwd-relative path, when the admin saves the profile via `Lxclient::postUpdate`, and by `init-panel-db` `syncAdminContactEmail` for existing installs; `KloxoWatchdog::contactEmail` also reads `client.contactemail` from panel.sqlite if that file is missing, so the recovery console does not warn after the profile was saved; with no address the watchdog logs `no admin email set` and the recovery console shows a warning; `kloxo-watchdog --test-mail` sends a test). Log `log/watchdog.log`; `kloxo-watchdog --status`; the recovery console has a Watchdog column. `tests/p2/test_watchdog.php` (fake systemd, sockets, clock, mailer, sqlite fallback). NOT verified: a real systemd run as root, real sockets, real sendmail, a live crash/restart on Ubuntu, `install.sh` enabling the unit.
Fail2Ban (Security > **Fail2Ban**, admin only; `lib/php/kloxo_fail2ban.php`, `driver/pserver/fail2banlib.php`, `bin/install/install-fail2ban`, `install.sh prepare_fail2ban`): packages `fail2ban python3-systemd nftables` are installed by default; the installer's SSH client is whitelisted. The page lists 16 jails (SSH, recidive, panel + recovery logins via `log/auth-failures.log`, Exim, Dovecot, Postfix, Nginx, Apache, Pure-FTPd, MariaDB) with availability, state, per-jail Max Retry/Find Time/Ban Time, failed/banned counters, enable/disable and edit icons, a Banned IPs list with Unban, Ban IP / Unban All / Reload / Settings (defaults + whitelist) and Recent Activity from `/var/log/fail2ban.log`. `/etc/fail2ban/jail.d/kloxo-cp.local` is generated from `etc/conf/fail2ban.json` (render, `fail2ban-client -t`, atomic install, reload, rollback of file and settings); bans use the nftables action, not UFW; loopback is always whitelisted, ban refuses whitelisted/own addresses. Watchdog keeps `fail2ban` running. Panel FPM must use kloxo-backend with **no local fallback** (`rl_exec_get`…`false`): falling back as `lxlabs` hits `Permission denied … fail2ban.sock (you must be root)`. Same for UFW `execUfw`. Localhost RPC skips the slave version gate (`slave_upgrading_please_try_after_a_few_minutes` after upcp when the long-running backend still had the old stamp); `kloxocp upcp` `try-restart`s `kloxo-backend` and `kloxo-php`. `tests/p2/test_fail2ban.php` (74 checks, fake `fail2ban-client`/systemctl) and `test_server_pages.php`; a real Fail2Ban, nftables, journal matches, live bans and a live install are UNVERIFIED.
`install.sh --fresh` (`fresh_wipe`; asks for YES, `--yes` skips, refuses without a terminal) removes the previous install (units, program root and default clone, panel DB `kloxo` plus accounts `kloxo`/`kloxo_pma`, saved passwords, Kloxo Fail2Ban jails) and then installs normally; customer sites, mail, customer DBs, UFW rules and apt packages are kept. Tested only as a sandboxed function run (fake systemctl/mysql, `tests/install/run-tests.sh`); a live run is UNVERIFIED.
Knot DNS (Switch Program DNS `knot`; `lib/php/kloxo_knot.php` `KloxoKnot`, `driver/dns/dns__knotlib.php`, `lib/php/kloxo_dns.php` for the unit/switch helpers, `bin/install/install-knot`): zones are generated, not templated. Each domain (and addon domain) becomes `/var/lib/knot/zones/<domain>.zone` (absolute names, SOA serial raised above the live file's, unchanged zones skipped; ns/mx/a/aaaa/cname/fcname/txt/caa/srv, invalid or conflicting records skipped with a warning in `log/dns_knot`; a CNAME is dropped if other records share its name) and checked with `kzonecheck -o <domain>` before an atomic install (0640 root:knot). `/etc/knot/knot.conf` is rendered from the zone list, the slave/master lists and the other name servers (transfer ACL + notify; our own IPs excluded), checked with `knotc -c <tmp> conf-check`, installed atomically (`.kloxo-bak`), then `knotc reload` when the unit is active; a failed reload puts the previous conf back and raises. `listen:` is always explicit (this host's global IPs + 127.0.0.1, never the wildcard: systemd-resolved owns 127.0.0.53/54:53 on Ubuntu, which is what broke a wildcard bind before), so a changed IP needs `/script/fixdns` or a Manage DNS rebuild. Zone files of deleted domains are removed; zones whose file is missing are not listed. `install.sh` masks `knot` during apt (`policy-rc.d` + runtime mask, only for units not already enabled) because Debian's postinst would start it on 127.0.0.1:53; `init-panel-db` moves an install that had `pg_dns=pdns` back to `bind` and swaps the `pdns___localhost` Services row for `knot___localhost`. After switching to Knot, run `/script/fixdns` (or Manage DNS > Rebuild) once so the zone files exist. A failed unit start whose journal says "already in use" appends `port N is held by: <addr (process)>` from `ss` (root backend; fake `ss`/`ip` only). Reverse zones are not generated. Tested: `tests/p2/test_knot.php` (95 checks: renderers, injection attempts, kzonecheck/conf-check failure paths, reload rollback, `install-knot`, `knot-dnssecutils`) and `tests/p2/test_knot_driver.php` (9 checks), both against FAKE `kzonecheck`/`knotc`/`systemctl`, plus `tests/install/run-tests.sh` (`standby_off`, install.sh invariants) and `test_migrate_servermail.php` (pdns -> bind). NOT verified: the real `kzonecheck`/`knotc`/`knotd` (the zone and conf syntax has never been through them, neither the Knot 3.3 of Ubuntu 24.04 nor a newer one), a real reload, a real query, `syncCreateConf` (needs the panel daemon round trip), the live switch on Ubuntu. Site PHP extra versions use `https://packages.sury.org/php/` (deb822 Signed-By, suite = Ubuntu VERSION_CODENAME, amd64+arm64), not `ppa:ondrej/php`. `install-site-php` checks Sury `dists/<codename>/Release` first, removes leftover `ondrej-*php*` sources, fails clearly if the suite or package is missing, and writes `log/install-site-php.status`. Per-domain pools: `/etc/php/X.Y/fpm/pool.d/kloxo-<domain>.conf`, socket `/run/php/<domain>-phpX.Y.sock` (`KloxoPhpfpm`; never `/run/php/php-fpm.sock`). Multiple PHP Install (`KloxoPhpver::availableToInstall`, cached Sury probe `etc/conf/sitephp-sury.json`) lists only installable versions. PHP Used also runs `update-alternatives` for the CLI. Tested with probes/fakes (`tests/install/test_phpver.php`, `tests/p2/test_phpfpm.php`, `run-tests.sh`); live extra-version install on Ubuntu 26.04 is UNVERIFIED.
Next: remaining P2 runtime, Debian packaging, or P4 exim UI. See briefing
section 7.

## Commands
```
# Version bump on every commit (once per clone):
#   cp .githooks/pre-commit .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
# Opt out: SKIP_VERSION_BUMP=1 git commit ...
php tools/bump-version                # bump 10.1-YYYYMMDDNN + sync copies now
php tools/bump-version --print        # show current (kloxo/bin/kloxoversion)
php -l <file>                         # lint with PHP 8.3
bash tests/tls/run-tests.sh           # generate-tls-keys checks (php-cli + openssl; safe locally)
php kloxo/bin/install/generate-tls-keys --help
bash tests/remote/run-tests.sh        # C1 auth-before-unserialize checks (php-cli only)
bash tests/security/run-tests.sh      # P0.4/P0.5 source invariants (php-cli only)
bash tests/p1/run-tests.sh            # P1 stack-shrink source invariants (php-cli only)
bash tests/p2/run-tests.sh            # P2 first-party PHP 8 parse + autoload + UFW (php-cli only)
php tests/p2/test_mariadb.php         # Add Database MariaDB SQL (no live server)
php tests/p2/test_backup.php          # admin/client Backup Now / Restore argv + dump (no live server)
php tests/p2/test_disable_policy.php  # Disable Policy percentage / warn / disable (no live collectquota)
php tests/p2/test_auxiliary.php       # Auxiliary Login class, hash, delete/pserver flags (no live login)
php tests/p2/test_sshauthorizedkey.php # SSH Authorized Keys ~/.ssh/authorized_keys parse/add/delete
php tests/p2/test_ssl.php             # domain SSL install + Let's Encrypt redirects (fake certbot)
php tests/p2/test_stats.php           # visitor stats aggregator + dashboard HTML (fake logs)
bash tests/p3/run-tests.sh            # P3 kloxocp CLI + snapshot/rollback (php-cli only)
bash tests/install/run-tests.sh       # Ubuntu schema + Hiawatha installer invariants (php-cli only)
php kloxo/bin/install/init-panel-db --help
php kloxo/bin/install/init-slave --help
php kloxo/bin/install/install-knot --help
php kloxo/bin/install/install-apache --help
php kloxo/bin/install/install-webfront --help
bash kloxo/bin/install/install-hiawatha --help
php kloxo/bin/install/install-webcache --help
php kloxo/bin/install/install-fail2ban --help
bash tests/mail/run-tests.sh          # mail checks (needs MariaDB root socket; disposable machine only)
python3 tests/mail/e2e.py             # 25 live mail checks (needs running Exim/Dovecot/Rspamd; see tests/mail/README.md)
php kloxo/bin/mail/kloxo-mail help
php kloxo/bin/kloxocp help            # status, doctor, upcp/update, snapshot, rollback
php kloxo/bin/storage/kloxo-migrate   # panel.sqlite + stats.sqlite PRAGMA user_version
php kloxo/bin/storage/kloxo-regen     # render Hiawatha config, validate, rename
php kloxo/bin/storage/kloxo-stats     # incremental access-log aggregator
php kloxo/bin/storage/kloxo-backup-sqlite  # VACUUM INTO, keep 7
```

## Hard rules
- Never weaken the security defaults in `docs/MAIL_DESIGN.md` section 3 or `docs/AUDIT_REPORT.md`.
- Do not mass-rename `lx*` identifiers, DB columns or the `lxlabs` service account.
- Keep AGPLv3 notices and `NOTICE`; no secrets or private keys in the repo.
- New code: PHP 8.3, `declare(strict_types=1)`, prepared statements, no shell strings, validated input, tests.
- Service config changes: render, validate with the service's own checker, atomic install, roll back on failure.
- Say exactly what you tested and what you did not. Update `docs/` as you go.
