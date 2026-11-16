<?php 

include_once "lib/html/include.php"; 

initProgram('admin');

// Roundcube is the unique webmail client (Afterlogic/Rainloop/Squirrelmail/Telaen dropped).
// argv only — never system shell string (security FF6).
if (function_exists('kloxo_exec_argv')) {
	kloxo_exec_argv(array('sh', '/script/setup-roundcube'));
}

installChooser();
