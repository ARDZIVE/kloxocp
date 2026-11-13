<?php 

include_once "lib/html/include.php"; 

initProgram('admin');

// Roundcube is the unique webmail client (Afterlogic/Rainloop/Squirrelmail/Telaen dropped).
system("sh /script/setup-roundcube");

installChooser();
