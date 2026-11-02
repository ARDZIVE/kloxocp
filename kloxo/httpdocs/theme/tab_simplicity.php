<?php

function print_tab_block_start($alist)
{
	global $gbl, $sgbl, $login, $ghtml;
	$img_path = $login->getSkinDir();

	if (!$alist) { return; }

	foreach ($alist as $k => $a) {
		$nalist[] = $ghtml->getFullUrl($a);
	}

	$alist = $nalist;

	if ($login->getSpecialObject('sp_specialplay')->isOn('enable_ajax')) {
		$ghtml->print_dialog($alist, $gbl->__c_object);
	}

	$skin_color = $login->getSpecialObject('sp_specialplay')->skin_color;

?>

<!-- "START TAB" -->
	<div class="div_tab_block">
<?php
	if (!$sgbl->isBlackBackground()) {
?>

		<div class="tabcompleteleft">&nbsp;&nbsp;</div>

<?php
	}

	// This gives a list of key value pair, which shows which of the tab is selected.
	// For instance, if the fifth tab is the selected on, then $list[5] will be true,
	// while all the others will be false. This is necessary because, printing will need to
	// know if the next tab is the selected one.

	$list = $ghtml->whichTabSelect($alist);
	if (!is_array($list)) {
		$list = array();
	}
	$list[-1] = false;
	$list[kcount($list) - 1] = false;

	foreach ($alist as $k => $a) {
		print_tab_button($k, $a, $list);
	}

?>
		<div class="tabcompleteright">&nbsp;</div>
	</div>
<!-- "END TAB" -->
<?php
}

function print_tab_button($key, $url, $list)
{
	global $gbl, $sgbl, $login, $ghtml;

	$skin_color = $login->getSpecialObject('sp_specialplay')->skin_color;

	$cobject = $gbl->__c_object;
	static $after_sel = false;

	$psuedourl = null;
	$target = null;
	$img_path = $login->getSkinDir();

	$buttonpath = '';
	$bpath = $login->getSkinDir();
	$bdpath = 'ddd';

	$button = '';

	$ghtml->resolve_int_ext($url, $psuedourl, $target);

	$path = $post = $file = $name = $image = $__t_identity = null;
	try {
		$descr = $ghtml->getActionDetails($url, $psuedourl, $buttonpath, $path, $post, $file, $name, $image, $__t_identity);
	} catch (Throwable $e) {
		log_log("error", "print_tab_button: " . $e->getMessage());
		$descr = array();
	}
	if (!is_array($descr)) {
		$descr = array();
	}

	$targetstring = $target;

	$form_name = $ghtml->createEncForm_name((string) $file . "_" . (string) $name);

	$check = $ghtml->compare_urls("display.php?{$ghtml->get_get_from_current_post(null)}", is_string($url) ? $url : '');

	$help = isset($descr['help']) ? $descr['help'] : '';
	$str = isset($descr[2]) ? $descr[2] : (isset($descr['desc']) ? $descr['desc'] : '');
	if (!is_string($str) || $str === '') {
		$path = $post = null;
		if (is_object($ghtml) && method_exists($ghtml, 'get_post_from_get')) {
			$ghtml->get_post_from_get(is_string($url) ? $url : '', $path, $post);
		}
		$sa = (is_array($post) && isset($post['frm_subaction']) && is_string($post['frm_subaction']))
			? $post['frm_subaction'] : '';
		if ($sa === 'reboot') {
			$str = 'Reboot';
		} elseif ($sa === 'poweroff') {
			$str = 'Poweroff';
		} elseif ($sa === 'switchprogram') {
			$str = 'Switch Program';
		} elseif ($sa !== '') {
			$str = ucfirst($sa);
		}
	} elseif ($str === 'reboot') {
		$str = 'Reboot';
	} elseif ($str === 'poweroff') {
		$str = 'Poweroff';
	} elseif ($str === 'switch_program' || $str === 'switchprogram' || $str === 'Switchprogram') {
		$str = 'Switch Program';
	}
	if ($help === '' && is_string($str)) {
		$help = $str;
	}
	$descstring = "<span title='{$help}'>{$str}</span>";

	if ($sgbl->isBlackBackground()) {
		if ($check) {
			$stylestring = "font-weight:bold; color:#999999";
		} else {
			$stylestring = "font-weight:normal; color:#999999";
		}

?>
		<a <?= $targetstring ?> href="<?= $url ?>"><span style="<?= $stylestring ?>"><?= $descstring ?></span> </a>
<?php
		return;
	}

	$lastkey = count($list);

	if ($check) {
?>

		<div class='verb3'><a <?= $targetstring ?> href="<?= $url ?>"><?= $descstring ?></a></div>
<?php
	} else {
?>

		<div class='verb'><a <?= $targetstring ?> href="<?= $url ?>"><?= $descstring ?></a></div>
<?php
	}

	return $check;
}
