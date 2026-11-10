<?php
	$status_title = '';
	$toast_kind = 'info';
	try {
		$status_title = $this->print_message('simplicity');
		if (!is_string($status_title)) {
			$status_title = '';
		}
		if (isset($this->__toast_kind) && is_string($this->__toast_kind) && $this->__toast_kind !== '') {
			$toast_kind = $this->__toast_kind;
		}
	} catch (Throwable $e) {
		log_log("error", "simplicity status: " . $e->getMessage());
		$status_title = '';
	}

	$has_toast = ($status_title !== '');
	$divclass = "div_header_message";
	$toast_display = $has_toast ? 'flex' : 'none';
	$clock_now = date('H:i:s');
	// Status toast must sit outside .div_fixed_right (flex + nowrap): otherwise a
	// long restore/backup message jams into Status / Show / Clock.
?>
<?php
	if ($has_toast) {
		$this->print_toast(htmlspecialchars($status_title, ENT_QUOTES), $toast_kind, 'div_cfstatus', $toast_display, true);
	} else {
?>
				<div id="div_cfstatus" class="kloxo-toast kloxo-toast-info div_header_message_status" style="display:none;" role="status" aria-live="polite"></div>
<?php
	}
?>
				<div class="div_fixed_right">
					<div id="div_status" class="<?=$divclass;?>" onMouseOver="this.style.backgroundColor='#fff'; this.style.color='#000'; displayStatus('div_cfstatus', 'flex');" onMouseOut="this.style.backgroundColor='#3498db'; this.style.color='#fff'; if (!window.kloxoStatusToastPinned) { displayStatus('div_cfstatus', 'none'); }">&nbsp;<?= $login->getKeywordUc('status'); ?>&nbsp;</div>
					<a href="#" onClick="toggleVisibilityById('mmm'); return false;">
						<div id="div_showhide" class="div_header_message" onMouseOver="this.style.backgroundColor='#fff'; this.style.color='#000';" onMouseOut="this.style.backgroundColor='#3498db'; this.style.color='#fff';">&nbsp;<?= $login->getKeywordUc('showhide') ?>&nbsp;</div>
					</a>
					<div id="clock_div" class="div_header_message">&nbsp;<?= $clock_now; ?>&nbsp;</div>
				</div>
<?php
		$clock_path = str_replace(getcwd(), "", getLinkCustomfile("{$skin_dir}/js", "clock.js"));
		$this->print_jscript_source($clock_path);
?>
		<script>
			window.kloxoStatusToastPinned = <?= $has_toast ? 'true' : 'false' ?>;

			function displayStatus(id, state) {
				var elemid = document.getElementById(id);
				if (!elemid) {
					return;
				}

				if (elemid.style.filter) {
					elemid.style.filter = 'alpha(opacity=100)';
				} else {
					elemid.style.opacity = 1;
				}

				elemid.style.display = state;
			}

			if (typeof startTime === 'function') {
				startTime('clock_div');
			}
		</script>
