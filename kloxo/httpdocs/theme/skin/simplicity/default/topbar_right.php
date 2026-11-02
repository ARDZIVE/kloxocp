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

	if ($status_title !== '') {
		$status_title = str_replace(":  ", ":\n<ul style=\"margin-left:-20px\"><li>", $status_title);
		$status_title .= "</li></ul>";
		$divclass = "div_header_message";
	} else {
		$divclass = "div_header_message";
	}

	$clock_now = date('H:i:s');
?>
				<div id="div_cfstatus" class="div_header_message_status" style="display:none">
				<?=$status_title;?>
				</div>

				<div class="div_fixed_right">
					<div id="div_status" class="<?=$divclass;?>" onMouseOver="this.style.backgroundColor='#fff'; this.style.color='#000'; displayStatus('div_cfstatus', 'inline');" onMouseOut="this.style.backgroundColor='#3498db'; this.style.color='#fff'; displayStatus('div_cfstatus', 'none');">&nbsp;<?= $login->getKeywordUc('status'); ?>&nbsp;</div>
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

			cf = setTimeout(function(){cf_fade()},15000);

			if (typeof startTime === 'function') {
				startTime('clock_div');
			}
		</script>
