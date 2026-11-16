<?php

include_once "theme/coredisplaylib.php";

// Do not log raw REQUEST (passwords/tokens) — security N18.
log_log("ajax", "ajax request method=" . (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : ''));

include_once "lib/html/ajaxcore.php";
