<?php
	$status_title = '';
	try {
		$status_title = $this->print_message('simplicity');
		if (!is_string($status_title)) {
			$status_title = '';
		}
	} catch (Throwable $e) {
		log_log("error", "simplicity status: " . $e->getMessage());
		$status_title = '';
	}

	$has_toast = ($status_title !== '');
	if ($has_toast) {
		$status_title = str_replace(":  ", ":\n<ul style=\"margin-left:-20px\"><li>", $status_title);
		$status_title .= "</li></ul>";
	}
	$divclass = "div_header_message";
	$toast_display = $has_toast ? 'block' : 'none';
	$clock_now = date('H:i:s');
?>
				<div class="div_fixed_right">
					<div id="div_cfstatus" class="div_header_message_status<?= $has_toast ? ' kloxo-status-toast-open' : '' ?>" style="display:<?= $toast_display ?>;" role="status" aria-live="polite">
					<?=$status_title;?>
					</div>
					<div id="div_status" class="<?=$divclass;?>" onMouseOver="this.style.backgroundColor='#fff'; this.style.color='#000'; displayStatus('div_cfstatus', 'block');" onMouseOut="this.style.backgroundColor='#3498db'; this.style.color='#fff'; if (!window.kloxoStatusToastPinned) { displayStatus('div_cfstatus', 'none'); }">&nbsp;<?= $login->getKeywordUc('status'); ?>&nbsp;</div>
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

			function cf_fade() {
				var elemid = document.getElementById("div_cfstatus");
				if (!elemid) {
					return;
				}
				var op = 1;

				var timer = setInterval(function () {
					if (op <= 0.1) {
						clearInterval(timer);

						if (elemid.style.filter) {
							elemid.style.filter = 'alpha(opacity=100)';
						} else {
							elemid.style.opacity = 1;
						}

						elemid.style.display = 'none';
						window.kloxoStatusToastPinned = false;
						if (elemid.className) {
							elemid.className = elemid.className.replace(/\bkloxo-status-toast-open\b/g, '').replace(/\s+/g, ' ').trim();
						}
					}

					if (elemid.style.filter) {
						elemid.style.filter = 'alpha(opacity=' + op * 100 + ')';
					} else {
						elemid.style.opacity = op;
					}

					op -= op * 0.1;
				}, 150);
			}

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

			<?php if ($has_toast) { ?>
			displayStatus('div_cfstatus', 'block');
			setTimeout(function () { cf_fade(); }, 15000);
			<?php } ?>

			if (typeof startTime === 'function') {
				startTime('clock_div');
			}
		</script>
