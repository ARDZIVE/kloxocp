<?php

dbschema_to_phpvar();

function dbschema_to_phpvar()
{
	$p = "/opt/kloxo/file/sql/";

	$c = file_get_contents("$p/.db_schema");
	// Arrays-only (security X9): .db_schema is a local dump, never objects.
	if (function_exists('kloxo_unserialize_deny_gadgets')) {
		$u = kloxo_unserialize_deny_gadgets($c, false);
	} else {
		$u = @unserialize($c, array('allowed_classes' => false));
	}
	if (!is_array($u)) {
		fwrite(STDERR, "dbschema-convert: refused or empty .db_schema\n");
		exit(1);
	}
/*
	$s = '<' . '?php' . "\n";

	foreach ($u as $k1 => $v1) {
		if (!is_array($v1)) {
			$s .= "\$var['$k1'] = $v1;\n";
		} else {
			foreach($v1 as $k2 => $v2) {
				if (!is_array($v2)) {
					$s .= "\$var['$k1']['$k2'] = $v2;\n";
				} else {
					foreach($v2 as $k3 => $v3) {
						if (!is_array($v3)) {
							$s .= "\$var['$k1']['$k2']['$k3'] = $v3;\n";
						}	
					}
				}	
			}
		}
	}
*/
	$v = var_export($u, true);

	file_put_contents("$p/db_schema.php", '<' . '?php' . "\n" . "\$var = " . $v . ';');
}
