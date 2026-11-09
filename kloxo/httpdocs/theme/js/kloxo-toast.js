/*
 * Panel toasts (.kloxo-toast): top-centre white cards. Auto-hide after 15s.
 * Included on every panel page and the login page.
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoToast) {
		return;
	}

	var DELAY = 15000;
	var FADE_MS = 400;

	function hide(el) {
		if (!el || el.getAttribute('data-kloxo-toast-gone') === '1') {
			return;
		}
		el.setAttribute('data-kloxo-toast-gone', '1');
		el.style.transition = 'opacity ' + FADE_MS + 'ms linear';
		el.style.opacity = '0';
		win.setTimeout(function () {
			el.style.display = 'none';
			el.style.opacity = '1';
			el.style.transition = '';
			win.kloxoStatusToastPinned = false;
			if (el.className) {
				el.className = el.className.replace(/\bkloxo-status-toast-open\b/g, '').replace(/\s+/g, ' ').trim();
			}
		}, FADE_MS);
	}

	function arm(el) {
		if (!el || el.getAttribute('data-kloxo-toast-armed') === '1') {
			return;
		}
		if (el.id === 'div_cfstatus' && el.style.display === 'none') {
			return;
		}
		if (el.closest && el.closest('.kloxo-password-force')) {
			return;
		}
		el.setAttribute('data-kloxo-toast-armed', '1');
		win.setTimeout(function () {
			hide(el);
		}, DELAY);
	}

	function scan() {
		var nodes = doc.querySelectorAll('.kloxo-toast');
		for (var i = 0; i < nodes.length; i++) {
			arm(nodes[i]);
		}
	}

	win.KloxoToast = { hide: hide, scan: scan };

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}
})(window, document);
