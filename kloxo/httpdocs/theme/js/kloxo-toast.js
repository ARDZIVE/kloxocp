/*
 * Panel toasts (.kloxo-toast): top-centre white cards. Auto-hide after 15s.
 * Included on every panel page and the login page.
 * KloxoToast.show(text, kind[, ms]) creates a live toast (upload errors, etc.).
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoToast) {
		return;
	}

	var DELAY = 15000;
	var FADE_MS = 400;
	var LIVE_ID = 'kloxo-live-toast';

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
			if (el.id === LIVE_ID && el.parentNode) {
				el.parentNode.removeChild(el);
			}
		}, FADE_MS);
	}

	function arm(el, ms) {
		if (!el || el.getAttribute('data-kloxo-toast-armed') === '1') {
			return;
		}
		if (el.id === 'div_cfstatus' && el.style.display === 'none') {
			return;
		}
		if (el.closest && (el.closest('.kloxo-password-force') || el.closest('.kloxo-mail-setup-force'))) {
			return;
		}
		// Content-area notices stay on the page (e.g. Set Auto Responder).
		if (el.className && /\bkloxo-inline-alert\b/.test(el.className) && el.id !== 'div_cfstatus') {
			return;
		}
		el.setAttribute('data-kloxo-toast-armed', '1');
		var delay = (typeof ms === 'number' && ms > 0) ? ms : DELAY;
		win.setTimeout(function () {
			hide(el);
		}, delay);
	}

	function iconSvg(kind) {
		var fill = { info: '#3498db', success: '#27ae60', warning: '#f39c12', error: '#e74c3c' };
		var c = fill[kind] || fill.info;
		if (kind === 'warning') {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
				+ '<path d="M12 4.5 L21 20 H3 Z" fill="' + c + '"/>'
				+ '<rect x="11" y="9" width="2" height="6" fill="#fff"/>'
				+ '<rect x="11" y="16.5" width="2" height="2" fill="#fff"/></svg>';
		}
		if (kind === 'error') {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
				+ '<circle cx="12" cy="12" r="10" fill="' + c + '"/>'
				+ '<path d="M8 8 L16 16 M16 8 L8 16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>';
		}
		if (kind === 'success') {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
				+ '<circle cx="12" cy="12" r="10" fill="' + c + '"/>'
				+ '<path d="M7 12.5l3 3 7-7" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
			+ '<circle cx="12" cy="12" r="10" fill="' + c + '"/>'
			+ '<rect x="11" y="10" width="2" height="7" rx="1" fill="#fff"/>'
			+ '<rect x="11" y="7" width="2" height="2" rx="1" fill="#fff"/></svg>';
	}

	function show(text, kind, ms) {
		var msg = (typeof text === 'string') ? text.replace(/\s+/g, ' ').trim() : '';
		if (msg === '') {
			return null;
		}
		var k = String(kind || 'info').toLowerCase();
		if (k === 'alert' || k === 'e') {
			k = 'error';
		} else if (k !== 'success' && k !== 'warning' && k !== 'error' && k !== 'info') {
			k = 'info';
		}
		var prev = doc.getElementById(LIVE_ID);
		if (prev && prev.parentNode) {
			prev.parentNode.removeChild(prev);
		}
		var el = doc.createElement('div');
		el.id = LIVE_ID;
		el.className = 'kloxo-toast kloxo-toast-' + k + ' kloxo-status-toast-open';
		el.setAttribute('role', (k === 'error' || k === 'warning') ? 'alert' : 'status');
		// Icon SVG is allow-listed by kind; message is textContent (security SS3).
		var icon = doc.createElement('span');
		icon.className = 'kloxo-toast-icon';
		icon.innerHTML = iconSvg(k); // allow-listed SVG only (security TT6; security UU6; security VV6; security WW6; security XX6; security YY6; security ZZ6; security AAA6; security BBB6; security CCC6; security DDD6)
		var body = doc.createElement('div');
		body.className = 'kloxo-toast-body';
		body.textContent = msg == null ? '' : String(msg);
		el.appendChild(icon);
		el.appendChild(body);
		(doc.body || doc.documentElement).appendChild(el);
		arm(el, (typeof ms === 'number' && ms > 0) ? ms : 20000);
		return el;
	}

	function scan() {
		var nodes = doc.querySelectorAll('.kloxo-toast');
		for (var i = 0; i < nodes.length; i++) {
			arm(nodes[i]);
		}
	}

	win.KloxoToast = { hide: hide, scan: scan, show: show };

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}
})(window, document);
