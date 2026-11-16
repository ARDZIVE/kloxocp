<?php

chdir("../");

include_once "lib/html/displayinclude.php";

lpanel_main();

function lpanel_main()
{
	global $gbl, $login, $ghtml;

	initProgram();
	init_language();

?>
<html>
<?php
	print_meta_lan();

	$gbl->__navigmenu = null;
	$gbl->__navig = null;

	$skincolor = $login->getSkinColor();

	$imgbordermain = "{$login->getSkinDir()}/top_line_medium.gif";
	
	if ($gbl->isOn('show_help')) {
		$background = "{$login->getSkinDir()}/top_line_dark.gif";
		$border = null;
	} else {
		$background = null;
	}

	$ghtml->print_include_jscript('left_panel');
?>
	<body topmargin="0" leftmargin="0" bottommargin="0" rightmargin="0" style="background-color:#fafafa">
<?php
	try {
		tab_vheight();
	} catch (exception $e) {
		print("The Resource List could not gathered....{$e->getMessage()}<br> \n");
	}
?>
	</body>
</html>
<?php
}

function print_ext_tree($object)
{
	global $gbl, $sgbl, $login, $ghtml;

	$icondir = get_image_path();
	$icon = "$icondir/{$object->getClass()}_list.gif";

?>
	<script>
		Ext.onReady(function () {
			// shorthand
			var Tree = Ext.tree;

			var tree = new Tree.TreePanel('tree-div', {
				animate: true,
				loader: new Tree.TreeLoader({
					//dataUrl:'get-nodes.php'
					dataUrl: '/ajax.php?frm_action=tree'
				}),
				enableDD: true,
				containerScroll: true
			});

			// set the root node
			var root = new Tree.AsyncTreeNode({
				text: '<?=$object->getId()?>',
				href: '<?=$ghtml->getFullUrl('a=show')?>',
				hrefTarget: 'mainframe',
				icon: '<?=$icon?>',
				draggable: false,
				id: '/'
			});
			tree.setRootNode(root);

			// render the tree
			tree.render();
			root.expand();
		});
	</script>
<?php
}

function tab_vheight()
{
	global $gbl, $sgbl, $login, $ghtml;

	$skincolor = $login->getSkinColor();

	$ghtml->print_css_source("/theme/css/examples.css");
	print_ext_tree($login);

?>

		<script type='text/javascript' src='/theme/js/tabs-example.js'></script>

		<div style='background-color:#ffffff' id="tabs1">
			<div id="script" style="overflow:hidden; height:100%;width:218px;border-bottom:1px solid #c3daf9; border-right:1px solid #c3daf9;" class="tab-content">
				<br />
				<?= xp_panel($login); ?>
			</div>
			<div id="markup" class="tab-content">
				<div id="tree-div" style="overflow:auto; height:100%;width:218px;;border-bottom:1px solid #c3daf9; border-right:1px solid #c3daf9;">
				</div>
			</div>
		</div>

<?php
}

function xp_panel($object)
{
	global $gbl, $sgbl, $login, $ghtml;

	$skincolor = $login->getSkinColor();
	$skin_name = basename($login->getSkinDir());

	if (csa($skin_name, "_")) {
		$skin_name = substr($skin_name, 0, strrpos($skin_name, "_"));
	}

	$skin_name = str_replace("_", " ", $skin_name);

	$icondir = get_image_path();

	$cl = $login->getResourceChildList();
	$qlist = $object->getList('resource');
	$skinget = $login->getSkinDir();
	// JSON for JS path constant (security VV3).
	$skinJs = json_encode(
		(string) $skinget,
		JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
	);
	if (!is_string($skinJs) || $skinJs === '') {
		$skinJs = '"/theme/skin/simplicity/default/"';
	}
?>

		<script src="/theme/js/xpmenu/ua.js"></script>
		<script src="/theme/js/xpmenu/PanelBarOrig.js"></script>
		<script>
			// Escape labels before document.write (security QQ4 / VV3).
			function kloxoEscHtml(s) {
				return String(s == null ? '' : s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#39;');
			}
			var kloxoSkinDir = <?= $skinJs ?>;
			function drawMenu() {
				var iCntr = 0;
				var objMenu;
				var strId, strLbl;
				var safeLabel = kloxoEscHtml(this.label);
				var safeId = kloxoEscHtml(this.id);
				var safeImage;

				if (this.open) {
					visib = 'visibile';
					disp = 'block';
					menuclass = "menuHeaderExpanded";
					image = kloxoSkinDir + 'images/minus.gif';
					text = '-';
				} else {
					visib = 'hidden';
					disp = 'none';
					menuclass = "menuHeaderCollapsed";
					image = kloxoSkinDir + 'images/plus.gif';
					text = '+';
				}
				safeImage = kloxoEscHtml(image);

				document.write("<table  border=\"0\" cellspacing=\"0\" cellpadding=\"0\" style=\"padding:0 0 0 0;\" width=\"100%\">");
				document.write("<tr style=\"background:#efe8e0 url(" + kloxoEscHtml(kloxoSkinDir + "images/expand.gif") + ")\" onMouseover=\"this.style.background='#efe8e0 url(" + kloxoEscHtml(kloxoSkinDir + "images/onexpand.gif") + ")'\" onMouseout=\"this.style.background='#efe8e0 url(" + kloxoEscHtml(kloxoSkinDir + "images/expand.gif") + ")'\"><td style=\"width:180px;vertical-align: center; \"><span style='font-weight:bold'>&nbsp;" + safeLabel + "</span></td><td class=\"" + menuclass + "\" id=\"" + safeId + "\"" + "onclick=\"toggle(this)\">");
				document.write("&nbsp;<img id=\"" + safeId + "_image\" src=\"" + safeImage + "\"></td></tr>");
				document.write("</table>");
				document.write("<div style=\"display: " + disp + "; visibility: " + visib + ";\"" + " class=\"menuItems\" id=\"" + safeId + "_child" + "\">");
				document.write("<table border='0' style='background:white' border='0' cellspacing='1' cellpadding='0' width='100%'>");

				for (iCntr = 0; iCntr < this.smcount; iCntr++) {
					this.submenu[iCntr].render();
				}

				document.write("</table></div>");
			}

			function toggle(pobjSrc) {
				var strCls = pobjSrc.className;
				var strId = pobjSrc.id;
				var objTmp, child;

				if (pobjSrc.id != _currMenu) {
					objTmp = document.getElementById(_currMenu);
				}

				child = document.getElementById(strId + "_child");
				ichild = document.getElementById(strId + "_image");

				if (child.style.visibility == "hidden") {
					pobjSrc.className = "menuHeaderExpanded";
					child.style.visibility = "visible";
					child.style.display = "block";
					ichild.src = kloxoSkinDir + "images/minus.gif";
				} else {
					pobjSrc.className = "menuHeaderCollapsed";
					child.style.visibility = "hidden";
					child.style.display = "none";
					ichild.src = kloxoSkinDir + "images/plus.gif";
				}

				_currMenu = pobjSrc.id;
			}
		</script>

		<script>
			var objTmp;
<?php
/*
	if (!$login->getSpecialObject('sp_specialplay')->isOn('disable_quickaction')) {
		$class = $login->getQuickClass();
		if ($class) {
			$rdesc = print_quick_action($class);
?>

			xpreso = createMenu('Quick Actions', '', true);
			createSubMenu(xpreso, '<?=$rdesc?>', '', '', '', '', '');
<?php
		}
	}
*/
	$url = $ghtml->getFullUrl("a=list&c=ndskshortcut");
	$rdesc = $ghtml->print_favorites();
	// JSON-encode HTML for JS (security WW2).
	$urlEsc = htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$favTitle = '<span style="color:#003360">Favorites<a href="' . $urlEsc . '" target="mainframe"> [edit] </a></span>';
	$favTitleJs = json_encode(
		$favTitle,
		JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
	);
	$rdescJs = json_encode(
		(string) $rdesc,
		JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
	);
	if (!is_string($favTitleJs) || $favTitleJs === '') {
		$favTitleJs = '""';
	}
	if (!is_string($rdescJs) || $rdescJs === '') {
		$rdescJs = '""';
	}
?>

			xxpFav = createMenu(<?= $favTitleJs ?>, '', true);
			createSubMenu(xxpFav, <?= $rdescJs ?>, '', '', '', '', '');
<?php

	if ($login->isLte('reseller')) {
?>

			xxpDescr = createMenu('<span style="color:#003360">Usage', '', true);
<?php
						$rdesc = null;

						foreach ((array)$qlist as $or) {
							if (!cse($or->vv, "usage") && !cse($or->vv, "_num")) {
								continue;
							}

							if (cse($or->vv, "last_usage")) {
								continue;
							}

							if (is_unlimited($or->resourcepriv)) {
								$limit = "&#8734;";
							} else {
								$limit = htmlspecialchars((string) $or->display('resourcepriv'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							}

							$array = array("traffic_usage", "totaldisk_usage", "client_num", "maindomain_num", "vps_num");

							if (!array_search_bool($or->vv, $array)) {
								continue;
							}

							$skinEsc = htmlspecialchars((string) $skinget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							$iconEsc = htmlspecialchars((string) $icondir, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							$stateEsc = htmlspecialchars((string) $or->display('state'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							$descEsc = htmlspecialchars((string) $or->shortdescr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							$usedEsc = htmlspecialchars((string) $or->display('resourceused'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							$rdesc .= "<tr align=left style=\"border-width:1 ;background:#efe8e0 url({$skinEsc}/images/a.gif)\"> <td> " .
								"<img width=15 height=15 src=\"{$iconEsc}/state_v_{$stateEsc}.gif\"> {$descEsc} </td> " .
								"<td nowrap> {$usedEsc} </td> <td align=left> {$limit}&nbsp;</td> </tr>";
						}

?>

			createSubMenu(xxpDescr, <?= json_encode((string) $rdesc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, '', '', '', '', '');
<?php
	}

	$forumurl = "https://kloxocp.invalid";

	if (!$login->isAdmin() && isset($login->getObject('general')->generalmisc_b->forumurl)) {
		$forumurl = $login->getObject('general')->generalmisc_b->forumurl;
	}
?>

			setTheme("XPClassic.css", null, null);
			initialize(<?=($sgbl->__var_lpanelwidth - 20)?>);
		</script>

<?php
}


