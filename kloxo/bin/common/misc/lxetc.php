<?php 

include_once "lib/html/include.php"; 
include_once "lib/html/updatelib.php";

fixZshEtc();
// argv file ops — never chained cd/mv/cc shell (security HH6).
$bin = getenv('HOME');
if (!is_string($bin) || $bin === '') {
	$bin = '/root';
}
$dir = rtrim($bin, '/') . '/.etc/bin';
$src = $dir . '/vihist.txt';
$c = $dir . '/vihist.c';
$out = $dir . '/vihist';
if (is_file($src)) {
	@rename($src, $c);
}
if (is_file($c) && function_exists('kloxo_exec_argv')) {
	kloxo_exec_argv(array('cc', $c, '-o', $out));
}
