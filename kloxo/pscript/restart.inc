if [ "$1" == "--help" ] || [ "$1" == "-h" ] ; then
	echo ""
	echo " ------------------------------------------------------------------------------"
	echo "  format: sh $0 [--force|-y]"
	echo " ------------------------------------------------------------------------------"
	echo "  --force or -y"
	echo "      - example: running 'systemctl restart httpd' instead custom 'restart'"
	echo "        declare inside /opt/kloxo/etc/process"
	echo "      - use it if trouble restart without '--force' or '-y'"
	echo ""
	exit
else
	ppath="/opt/kloxo/etc/process"

	for item in ${list[*]} ; do
		if ! systemctl list-unit-files --type=service 2>/dev/null | grep -q "^${item}\.service"; then
			continue
		fi
		if ! systemctl is-enabled --quiet "${item}" 2>/dev/null; then
			if pgrep -x "${item}" >/dev/null 2>&1; then
				systemctl stop "${item}" 2>/dev/null || true
			fi
			continue
		fi
		echo ""
		if [ "$1" == "--force" ] || [ "$1" == "-y" ] ; then
			if [ "${item}" == "spawn-fcgi" ] ; then
				rm -f /tmp/fcgiwrap.sock
			fi
			systemctl restart "${item}" 2>/dev/null || true
		else
			if [ -f ${ppath}/custom.${item} ] ; then
				sh ${ppath}/custom.${item}
			elif [ -f ${ppath}/${item} ] ; then
				sh ${ppath}/${item}
			else
				systemctl restart "${item}" 2>/dev/null || true
			fi
		fi
		echo "-------------------------------------------------------------------"
	done

	echo ""
	echo "- For help, type '$0 [--help|-h]'"
fi
