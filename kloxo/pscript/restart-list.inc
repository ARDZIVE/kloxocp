# POSIX service lists for restart-* (sourced by #!/bin/sh).
# Ubuntu /bin/sh is dash — no bash arrays.

list_kloxo="kloxo-web kloxo-php kloxo-wrap"

# Enabled/available phpX.Y-fpm units (space-separated).
list_phpfpm=$(systemctl list-unit-files --type=service 2>/dev/null \
	| awk '/^php[0-9].*-fpm\.service/ { sub(/\.service.*/, ""); print $1 }' \
	| tr '\n' ' ')

list_web="kloxo-webcache kloxo-webproxy spawn-fcgi memcached nginx hiawatha httpd apache2 htcacheclean"
list_web="$list_phpfpm $list_web"

list_mysql="mysql mysqld mariadb"
list_mail="exim4 dovecot postfix"
list_ftp="pure-ftpd vsftpd"
list_dns="named bind9 knot nsd yadifad"
# Optional / unused on Ubuntu (kept empty so restart-perl-fastcgi is safe).
list_perlfastcgi=""
list_syslog="syslog rsyslog syslog-ng httpry"

list_services="$list_mysql $list_dns $list_web $list_mail $list_ftp"
list_all="$list_syslog $list_services $list_kloxo"
