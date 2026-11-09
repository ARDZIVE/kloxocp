/*
 * Per-version progress bars on Webserver Configure (Multiple PHP Install
 * and Multiple PHP Remove). The apt job runs as root in the background;
 * this page stays put and polls /theme/lbin/sitephp-progress.php.
 *
 * When a job finishes, the install and remove dual-listboxes are refreshed
 * from the server so a removed phpX.Y reappears under Install, and the
 * reverse. Forms post into a hidden iframe (data-kloxo-nobusy).
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
	var hideTimer = null;
	var wired = false;
	var sessionJobs = false;
	var shownPct = {};

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

	function dual(kind) {
		var hid = doc.querySelector('input[type="hidden"][name$="multiple_php_' + kind + '"]');
		var name;
		if (!hid) {
			return null;
		}
		name = hid.id || hid.name;
		return {
			hidden: hid,
			avail: $('ts_' + name + '1'),
			sel: $('ts_' + name + '2')
		};
	}

	function fillSelect(sel, ids, skip, pruneOnly) {
		var have = {};
		var i;
		var id;
		if (!sel) {
			return;
		}
		skip = skip || {};
		for (i = 0; i < ids.length; i++) {
			have[ids[i]] = true;
		}
		for (i = sel.options.length - 1; i >= 0; i--) {
			id = sel.options[i].value;
			if (!have[id] || skip[id]) {
				sel.remove(i);
			}
		}
		if (pruneOnly) {
			return;
		}
		for (i = 0; i < ids.length; i++) {
			id = ids[i];
			if (skip[id]) {
				continue;
			}
			if (selectHas(sel, id)) {
				continue;
			}
			sel.add(new Option(id, id));
		}
	}

	function selectHas(sel, id) {
		var i;
		for (i = 0; i < sel.options.length; i++) {
			if (sel.options[i].value === id) {
				return true;
			}
		}
		return false;
	}

	function selectedMap(sel) {
		var out = {};
		var i;
		if (!sel) {
			return out;
		}
		for (i = 0; i < sel.options.length; i++) {
			out[sel.options[i].value] = true;
		}
		return out;
	}

	function syncLists(installIds, removeIds) {
		var inst = dual('install');
		var rem = dual('remove');
		if (!Array.isArray(installIds)) {
			installIds = [];
		}
		if (!Array.isArray(removeIds)) {
			removeIds = [];
		}
		if (inst) {
			fillSelect(inst.sel, installIds, {}, true);
			fillSelect(inst.avail, installIds, selectedMap(inst.sel), false);
			if (typeof createFormVariable === 'function' && inst.hidden && inst.sel) {
				createFormVariable(inst.hidden.form && inst.hidden.form.name, inst.hidden.name, inst.sel);
			}
		}
		if (rem) {
			fillSelect(rem.sel, removeIds, {}, true);
			fillSelect(rem.avail, removeIds, selectedMap(rem.sel), false);
			if (typeof createFormVariable === 'function' && rem.hidden && rem.sel) {
				createFormVariable(rem.hidden.form && rem.hidden.form.name, rem.hidden.name, rem.sel);
			}
		}
	}

	function ensureStyle() {
		var s;
		if ($('kloxo-sitephp-style')) {
			return;
		}
		s = doc.createElement('style');
		s.id = 'kloxo-sitephp-style';
		s.appendChild(doc.createTextNode(
			'@keyframes kloxo-sitephp-stripe{from{background-position:0 0}to{background-position:28px 0}}' +
			'@keyframes kloxo-sitephp-shine{from{left:-40%}to{left:100%}}' +
			'.kloxo-sitephp-track{position:relative;height:18px;border:1px solid #888;background:#eee;overflow:hidden}' +
			'.kloxo-sitephp-fill{height:100%;background:#66cc00}' +
			'.kloxo-sitephp-fill.is-failed{background:#c44}' +
			'.kloxo-sitephp-fill.is-busy{min-width:28%;background-image:repeating-linear-gradient(-45deg,#58b800 0 10px,#7ad000 10px 20px);background-size:28px 18px;animation:kloxo-sitephp-stripe .6s linear infinite}' +
			'.kloxo-sitephp-track.is-busy:after{content:"";position:absolute;top:0;left:-40%;width:40%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);animation:kloxo-sitephp-shine 1.1s ease-in-out infinite}'
		));
		(doc.head || doc.documentElement).appendChild(s);
	}

	function ensureBox() {
		var box = $('kloxo-sitephp-box');
		var installForm;
		var host;
		var title;
		var rows;
		ensureStyle();
		if (box) {
			return box;
		}
		installForm = findForm('multiple_php_install') || findForm('multiple_php_remove');
		host = installForm;
		if (!host || !host.parentNode) {
			return null;
		}
		box = doc.createElement('div');
		box.id = 'kloxo-sitephp-box';
		box.className = 'kloxo-sitephp-box';
		box.setAttribute('data-kloxo-nobusy', '1');
		box.style.cssText = 'display:none;width:600px;margin:0 auto 16px auto;border:1px solid #aaa;background:#fff;padding:0;box-sizing:content-box;';
		title = doc.createElement('div');
		title.style.cssText = 'font-weight:bold;background:#dfe;padding:8px 10px;border-bottom:1px solid #aaa;';
		title.appendChild(doc.createTextNode('PHP progress'));
		rows = doc.createElement('div');
		rows.id = 'kloxo-sitephp-rows';
		rows.style.cssText = 'padding:10px;';
		box.appendChild(title);
		box.appendChild(rows);
		host.parentNode.insertBefore(box, host);
		return box;
	}

	function displayPct(j, busy) {
		var id = j.id || 'php';
		var real = Math.max(0, Math.min(100, parseInt(j.pct, 10) || 0));
		var prev;
		var next;
		if (!busy || j.state === 'ok' || j.state === 'failed') {
			shownPct[id] = real;
			return real;
		}
		prev = typeof shownPct[id] === 'number' ? shownPct[id] : real;
		next = Math.max(real, Math.min(92, prev + 3));
		shownPct[id] = next;
		return next;
	}

	function upsertRow(rows, j, busy) {
		var id = j.id || 'php';
		var pct = displayPct(j, busy);
		var running = busy && j.state !== 'ok' && j.state !== 'failed';
		var row = rows.querySelector('[data-php="' + id + '"]');
		var cap;
		var track;
		var fill;
		var txt;
		var fillClass;
		var trackClass;
		if (!row) {
			row = doc.createElement('div');
			row.setAttribute('data-php', id);
			row.style.cssText = 'margin:0 0 10px 0;';
			cap = doc.createElement('div');
			cap.className = 'kloxo-sitephp-cap';
			cap.style.cssText = 'font-size:12px;margin:0 0 3px 0;';
			track = doc.createElement('div');
			track.className = 'kloxo-sitephp-track';
			fill = doc.createElement('div');
			fill.className = 'kloxo-sitephp-fill';
			txt = doc.createElement('div');
			txt.className = 'kloxo-sitephp-txt';
			txt.style.cssText = 'position:absolute;left:8px;top:0;z-index:1;line-height:18px;font-size:11px;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;right:8px;';
			track.appendChild(fill);
			track.appendChild(txt);
			row.appendChild(cap);
			row.appendChild(track);
			rows.appendChild(row);
		} else {
			cap = row.querySelector('.kloxo-sitephp-cap');
			track = row.querySelector('.kloxo-sitephp-track');
			fill = row.querySelector('.kloxo-sitephp-fill');
			txt = row.querySelector('.kloxo-sitephp-txt');
		}
		cap.textContent = id + '  ' + pct + '%';
		if (fill.style.width !== pct + '%') {
			fill.style.width = pct + '%';
		}
		fillClass = 'kloxo-sitephp-fill' + (j.state === 'failed' ? ' is-failed' : '') + (running ? ' is-busy' : '');
		trackClass = 'kloxo-sitephp-track' + (running ? ' is-busy' : '');
		if (fill.className !== fillClass) {
			fill.className = fillClass;
		}
		if (track.className !== trackClass) {
			track.className = trackClass;
		}
		if (txt.textContent !== (j.action || '')) {
			txt.textContent = j.action || '';
		}
	}

	function renderJobs(jobs, busy) {
		var box = ensureBox();
		var rows = $('kloxo-sitephp-rows');
		var i;
		var id;
		var keep = {};
		var kids;
		if (!box || !rows) {
			return;
		}
		if (!jobs || jobs.length === 0) {
			box.style.display = 'none';
			rows.textContent = '';
			shownPct = {};
			return;
		}
		box.style.display = 'block';
		for (i = 0; i < jobs.length; i++) {
			id = jobs[i].id || 'php';
			keep[id] = true;
			upsertRow(rows, jobs[i], !!busy);
		}
		kids = rows.querySelectorAll('[data-php]');
		for (i = 0; i < kids.length; i++) {
			if (!keep[kids[i].getAttribute('data-php')]) {
				rows.removeChild(kids[i]);
			}
		}
	}

	function hasFailed(jobs) {
		var i;
		for (i = 0; i < (jobs || []).length; i++) {
			if (jobs[i].state === 'failed') {
				return true;
			}
		}
		return false;
	}

	function hideWhenDone(jobs, busy, install, remove) {
		if (hideTimer) {
			win.clearTimeout(hideTimer);
			hideTimer = null;
		}
		if (busy || !sessionJobs) {
			return;
		}
		syncLists(install, remove);
		if (hasFailed(jobs)) {
			renderJobs(jobs, false);
			hideTimer = win.setTimeout(function () {
				renderJobs([]);
				sessionJobs = false;
			}, 4000);
			return;
		}
		renderJobs([]);
		sessionJobs = false;
	}

	function poll() {
		var req = new XMLHttpRequest();
		req.open('GET', URL + '?t=' + Date.now(), true);
		req.onreadystatechange = function () {
			var data;
			var busy;
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
			busy = !!data.busy;
			if (busy) {
				sessionJobs = true;
				if ((data.jobs || []).length > 0) {
					renderJobs(data.jobs, true);
				} else {
					renderJobs([{id: 'php', state: 'running', pct: 20, action: data.message || 'working'}], true);
				}
			} else {
				hideWhenDone(data.jobs || [], busy, data.install, data.remove);
			}
			schedule(busy);
		};
		req.send();
	}

	function schedule(busy) {
		if (timer) {
			win.clearTimeout(timer);
		}
		timer = win.setTimeout(poll, busy ? POLL_BUSY_MS : POLL_IDLE_MS);
	}

	function selectedFromForm(form) {
		var box;
		var i;
		var ids = [];
		if (!form) {
			return ids;
		}
		box = form.querySelector('select[id$="2"]');
		if (box && box.options) {
			for (i = 0; i < box.options.length; i++) {
				if (box.options[i].value) {
					ids.push(box.options[i].value);
				}
			}
		}
		return ids;
	}

	function startNow(kind, ids) {
		var jobs = [];
		var i;
		var action = kind === 'remove' ? 'Waiting to start removal' : 'Waiting to start install';
		sessionJobs = true;
		ensureBox();
		for (i = 0; i < ids.length; i++) {
			jobs.push({id: ids[i], state: 'queued', pct: 5, action: action});
		}
		if (jobs.length === 0) {
			jobs.push({id: 'php', state: 'queued', pct: 5, action: action});
		}
		renderJobs(jobs, true);
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
				sessionJobs = true;
				hideWhenDone([{id: 'php', state: 'failed', pct: 100, action: key.replace(/_/g, ' ')}], false, null, null);
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
			startNow(kind, selectedFromForm(form));
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
