/*
 * Live install/remove steps on Webserver Configure (Multiple PHP Install
 * and Multiple PHP Remove). The apt job runs as root in the background;
 * this page stays put and polls /theme/lbin/sitephp-progress.php.
 *
 * Forms post into a hidden iframe (data-kloxo-nobusy) so the busy spinner
 * does not cover the log, and the rest of the page does not navigate away.
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoSitephp) {
		return;
	}

	var POLL_BUSY_MS = 800;
	var POLL_IDLE_MS = 2500;
	var URL = '/theme/lbin/sitephp-progress.php';
	var timer = null;
	var stickBottom = true;
	var wired = false;

	function $(id) {
		return doc.getElementById(id);
	}

	function findForm(sa) {
		var inputs = doc.querySelectorAll('input[name="frm_subaction"]');
		var i;
		for (i = 0; i < inputs.length; i++) {
			if (inputs[i].value === sa && inputs[i].form) {
				return inputs[i].form;
			}
		}
		return null;
	}

	function ensureBox() {
		var box = $('kloxo-sitephp-box');
		var installForm;
		var removeForm;
		var host;
		var title;
		var status;
		var pre;
		if (box) {
			return box;
		}
		installForm = findForm('multiple_php_install');
		removeForm = findForm('multiple_php_remove');
		host = installForm || removeForm;
		if (!host || !host.parentNode) {
			return null;
		}
		box = doc.createElement('div');
		box.id = 'kloxo-sitephp-box';
		box.setAttribute('data-kloxo-nobusy', '1');
		box.style.cssText = 'margin:8px 0 16px 0;border:1px solid #aaa;background:#102018;color:#d8f5e0;padding:10px;';
		title = doc.createElement('div');
		title.style.cssText = 'font-weight:bold;color:#222;background:#dfe;margin:-10px -10px 8px -10px;padding:8px 10px;border-bottom:1px solid #aaa;';
		title.appendChild(doc.createTextNode('PHP install / remove progress'));
		status = doc.createElement('div');
		status.id = 'kloxo-sitephp-status';
		status.style.cssText = 'margin:0 0 8px 0;font-size:12px;color:#9ee6b0;';
		status.appendChild(doc.createTextNode('No job running.'));
		pre = doc.createElement('pre');
		pre.id = 'kloxo-sitephp-log';
		pre.setAttribute('aria-live', 'polite');
		pre.style.cssText = 'margin:0;max-height:280px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:12px/1.35 monospace;background:#0b1610;color:#d8f5e0;padding:8px;';
		pre.appendChild(doc.createTextNode('Steps appear here when you click Update on Multiple PHP Install or Multiple PHP Remove.'));
		pre.addEventListener('scroll', function () {
			stickBottom = (pre.scrollHeight - pre.scrollTop - pre.clientHeight) < 40;
		});
		box.appendChild(title);
		box.appendChild(status);
		box.appendChild(pre);
		host.parentNode.insertBefore(box, host);
		return box;
	}

	function setView(state, message, logText) {
		var status = $('kloxo-sitephp-status');
		var pre = $('kloxo-sitephp-log');
		var label;
		if (!status || !pre) {
			return;
		}
		label = state || 'idle';
		if (message) {
			label += ' — ' + message;
		}
		status.textContent = label;
		if (typeof logText === 'string' && logText !== '') {
			pre.textContent = logText;
		}
		if (stickBottom) {
			pre.scrollTop = pre.scrollHeight;
		}
	}

	function poll() {
		var req = new XMLHttpRequest();
		req.open('GET', URL + '?t=' + Date.now(), true);
		req.onreadystatechange = function () {
			var data;
			if (req.readyState !== 4) {
				return;
			}
			if (req.status !== 200) {
				return;
			}
			try {
				data = JSON.parse(req.responseText);
			} catch (e) {
				return;
			}
			if (!data || data.ok === false) {
				return;
			}
			setView(data.state || 'idle', data.message || '', data.log || '');
			schedule(!!data.busy);
		};
		req.send();
	}

	function schedule(busy) {
		if (timer) {
			win.clearTimeout(timer);
		}
		timer = win.setTimeout(poll, busy ? POLL_BUSY_MS : POLL_IDLE_MS);
	}

	function startNow(kind) {
		ensureBox();
		setView('queued', kind === 'remove'
			? 'Remove requested. Waiting for root to start apt…'
			: 'Install requested. Waiting for root to start apt…',
			'');
		if (timer) {
			win.clearTimeout(timer);
		}
		poll();
	}

	function iframe() {
		var f = $('kloxo-sitephp-frame');
		if (f) {
			return f;
		}
		f = doc.createElement('iframe');
		f.name = 'kloxo-sitephp-frame';
		f.id = 'kloxo-sitephp-frame';
		f.setAttribute('aria-hidden', 'true');
		f.style.display = 'none';
		doc.body.appendChild(f);
		f.addEventListener('load', function () {
			var href = '';
			var m;
			var key;
			try {
				href = f.contentWindow && f.contentWindow.location
					? String(f.contentWindow.location.href)
					: '';
			} catch (e) {
				href = '';
			}
			if (/frm_smessage=/.test(href) || /running_in_background/.test(href)) {
				poll();
				return;
			}
			if (/frm_emessage=/.test(href)) {
				m = href.match(/frm_emessage=([^&]+)/);
				key = m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : 'request failed';
				setView('failed', key.replace(/_/g, ' '), '');
			}
		});
		return f;
	}

	function wireForm(form, kind) {
		if (!form || form.getAttribute('data-kloxo-sitephp') === '1') {
			return;
		}
		form.setAttribute('data-kloxo-sitephp', '1');
		form.setAttribute('data-kloxo-nobusy', '1');
		form.target = 'kloxo-sitephp-frame';
		iframe();
		form.addEventListener('submit', function () {
			if (typeof check_for_needed_variables === 'function' && form.id) {
				if (!check_for_needed_variables(form.id)) {
					return;
				}
			}
			startNow(kind);
		});
	}

	function boot() {
		var installForm = findForm('multiple_php_install');
		var removeForm = findForm('multiple_php_remove');
		if (!installForm && !removeForm) {
			return;
		}
		if (wired) {
			return;
		}
		wired = true;
		ensureBox();
		wireForm(installForm, 'install');
		wireForm(removeForm, 'remove');
		poll();
	}

	win.KloxoSitephp = {poll: poll, start: startNow};
	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}(window, document));
