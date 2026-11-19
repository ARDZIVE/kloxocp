/*
 * Busy modal: a centred animated GIF spinner (theme/img/kloxo-spinner.gif)
 * while the panel is working on a request. Included on every panel page
 * (HtmlLib::print_include_jscript).
 *
 * KloxoBusy.show(text) / KloxoBusy.hide()
 *
 * Every in-panel navigation can show it: link click, form submit, a script
 * that sets location or calls form.submit(). Quick pages wait DELAY (300ms)
 * so they never flash. Heavy work (Switch Program, Backup/Restore, Services
 * icons, Fail2Ban, Multiple PHP, fixweb/dns/mail, …) shows the modal
 * immediately. Once shown it stays until the destination page has finished
 * its main content (or DOMContentLoaded), with a MAX_WAIT failsafe. Long
 * actions rotate the wait text.
 *
 * The busy state is kept in sessionStorage so a streamed response or
 * redirect stub does not drop the modal. A destination with frm_smessage /
 * frm_emessage (toast) clears it: the action is finished.
 *
 * Not shown for downloads, mailto:, target=_blank, file uploads, or
 * data-kloxo-nobusy. Service icons use fetch then follow the redirect.
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoBusy) {
		return;
	}

	var KEY = 'kloxo-busy';
	var SUPPRESS_KEY = 'kloxo-busy-suppress';
	// Flag must outlive long Switch Program / apt / sync work (was 8s → spinner vanished).
	var MAX_AGE = 3 * 60 * 1000;
	// Absolute failsafe for a hung request; hide so the UI is not stuck forever.
	var MAX_WAIT = 3 * 60 * 1000;
	var DEFAULT_TEXT = 'Please wait...';
	// Quick pages: short delay so light navigations never flash the modal.
	// Heavy actions use delay 0 (see isHeavy / heavyText).
	var DELAY = 300;
	var ROTATE_MS = 4000;
	var WAIT_TEXTS = [
		'Please wait...',
		'Still working...',
		'This may take a little longer...',
		'Working on the server...',
		'Hang on — almost ready...',
		'Please keep this window open...'
	];

	var overlay = null;
	var label = null;
	var suppressUntil = 0;
	var pending = null;
	var maxWaitTimer = null;
	var rotateTimer = null;
	var rotateIndex = 0;
	var rotateBase = DEFAULT_TEXT;
	var settled = false;
	var navToken = 0;

	function store() {
		try {
			return win.sessionStorage;
		} catch (e) {
			return null;
		}
	}

	function flagSet(text, shown) {
		var s = store();
		if (s) {
			try {
				s.setItem(KEY, JSON.stringify({t: Date.now(), text: text, shown: !!shown}));
			} catch (e) {
				// storage full or disabled
			}
		}
	}

	function flagTouch(text, shown) {
		var s = store();
		if (!s) {
			return;
		}
		try {
			var f = flagGet();
			var msg = text || (f && f.text) || DEFAULT_TEXT;
			var isShown = (typeof shown === 'boolean') ? shown : !!(f && f.shown);
			s.setItem(KEY, JSON.stringify({t: Date.now(), text: msg, shown: isShown}));
		} catch (e) {
			// ignore
		}
	}

	function flagGet() {
		var s = store();
		if (!s) {
			return null;
		}
		try {
			var raw = s.getItem(KEY);
			var f = raw ? JSON.parse(raw) : null;
			if (f && typeof f.t === 'number' && Date.now() - f.t < MAX_AGE) {
				return f;
			}
			if (raw) {
				s.removeItem(KEY);
			}
		} catch (e) {
			// unreadable flag
		}
		return null;
	}

	function flagClear() {
		var s = store();
		if (s) {
			try {
				s.removeItem(KEY);
			} catch (e) {
				// ignore
			}
		}
	}

	/** Frame refresh after Add Domain sets this so reloads do not re-arm Please wait... */
	function isSuppressed() {
		var s = store();
		if (!s) {
			return false;
		}
		try {
			var until = parseInt(s.getItem(SUPPRESS_KEY) || '0', 10);
			if (until > Date.now()) {
				return true;
			}
			if (until) {
				s.removeItem(SUPPRESS_KEY);
			}
		} catch (e) {
			// ignore
		}
		return false;
	}

	/** User clicks/submits must always show busy; suppress is only for auto frame reloads. */
	function clearSuppress() {
		var s = store();
		if (!s) {
			return;
		}
		try {
			s.removeItem(SUPPRESS_KEY);
		} catch (e) {
			// ignore
		}
		suppressUntil = 0;
	}

	function refreshTarget() {
		var metas = doc.getElementsByTagName('meta');
		for (var i = 0; i < metas.length; i++) {
			var he = (metas[i].getAttribute('http-equiv') || '').toLowerCase();
			var c = metas[i].getAttribute('content') || '';
			if (he === 'refresh' && /^\s*[01]?\s*(?:;|$)/.test(c) && /url\s*=/i.test(c)) {
				var m = /url\s*=\s*([^;\s]+)/i.exec(c);
				if (m && m[1]) {
					return m[1].replace(/&amp;/g, '&').replace(/^['"]|['"]$/g, '');
				}
			}
		}
		var body = doc.body;
		if (body && body.getAttribute) {
			var d = body.getAttribute('data-kloxo-redirect');
			if (d) {
				return d;
			}
		}
		return '';
	}

	function isImmediateRefresh() {
		return refreshTarget() !== '';
	}

	/** Destination pages with a Status toast are already finished work. */
	function hasToastQuery() {
		try {
			var q = win.location.search || '';
			return /[?&]frm_smessage=/.test(q) || /[?&]frm_emessage=/.test(q);
		} catch (e) {
			return false;
		}
	}

	/**
	 * Meta-refresh / Continue stubs: keep busy across the hop so the spinner
	 * does not vanish between "action done on server" and the next page.
	 */
	function followRedirectStub() {
		var u = refreshTarget();
		if (!u) {
			return false;
		}
		settled = false;
		flagTouch(DEFAULT_TEXT, true);
		cancelPending();
		cancelMaxWait();
		cancelRotate();
		try {
			if (win.parent && win.parent !== win && win.parent.frames && win.parent.frames.mainframe) {
				win.parent.frames.mainframe.location.replace(u);
			} else {
				win.location.replace(u);
			}
		} catch (e) {
			try {
				win.location.replace(u);
			} catch (e2) {
				win.location.href = u;
			}
		}
		return true;
	}

	function build() {
		if (overlay && overlay.parentNode) {
			return;
		}
		var host = doc.body || doc.documentElement;
		if (!host) {
			return;
		}
		if (!doc.getElementById('kloxo-busy-style')) {
			var css = doc.createElement('style');
			css.id = 'kloxo-busy-style';
			css.type = 'text/css';
			css.appendChild(doc.createTextNode(
				'#kloxo-busy{display:none !important;position:fixed !important;left:0;top:0;right:0;bottom:0;' +
				'z-index:2147483646 !important;background:rgba(0,0,0,.45) !important;' +
				'align-items:center;justify-content:center}' +
				'#kloxo-busy.on{display:flex !important}' +
				'#kloxo-busy .kb-box{background:#fff;border-radius:8px;padding:22px 34px;min-width:220px;max-width:90%;' +
				'text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.4);font:14px Arial,Helvetica,sans-serif;color:#333}' +
				'#kloxo-busy img{width:64px;height:64px;display:block;margin:0 auto 10px;background:#fff}'
			));
			(doc.head || doc.getElementsByTagName('head')[0] || host).appendChild(css);
		}

		overlay = doc.getElementById('kloxo-busy');
		if (!overlay) {
			overlay = doc.createElement('div');
			overlay.id = 'kloxo-busy';
			overlay.setAttribute('role', 'alertdialog');
			overlay.setAttribute('aria-busy', 'true');
			overlay.setAttribute('aria-live', 'assertive');
			var box = doc.createElement('div');
			box.className = 'kb-box';
			var img = doc.createElement('img');
			img.src = '/theme/img/kloxo-spinner.gif?v=3';
			img.alt = '';
			img.width = 64;
			img.height = 64;
			label = doc.createElement('div');
			box.appendChild(img);
			box.appendChild(label);
			overlay.appendChild(box);
			host.appendChild(overlay);
		} else if (!label) {
			label = overlay.querySelector('.kb-box div') || overlay.querySelector('div');
		}
	}

	function cancelPending() {
		if (pending !== null) {
			win.clearTimeout(pending);
			pending = null;
		}
	}

	function cancelMaxWait() {
		if (maxWaitTimer !== null) {
			win.clearTimeout(maxWaitTimer);
			maxWaitTimer = null;
		}
	}

	function cancelRotate() {
		if (rotateTimer !== null) {
			win.clearInterval(rotateTimer);
			rotateTimer = null;
		}
	}

	function setLabel(text) {
		if (label) {
			label.textContent = text || DEFAULT_TEXT;
		}
	}

	function startRotate(baseText) {
		cancelRotate();
		rotateBase = baseText || DEFAULT_TEXT;
		rotateIndex = 0;
		setLabel(rotateBase);
		rotateTimer = win.setInterval(function () {
			if (settled || !overlayOn()) {
				cancelRotate();
				return;
			}
			rotateIndex += 1;
			// Keep a custom action text for the first tick, then cycle formulations.
			if (rotateIndex === 1 && rotateBase !== DEFAULT_TEXT
				&& WAIT_TEXTS.indexOf(rotateBase) === -1) {
				setLabel(WAIT_TEXTS[1]);
				flagTouch(WAIT_TEXTS[1], true);
				return;
			}
			var idx = rotateIndex % WAIT_TEXTS.length;
			var msg = WAIT_TEXTS[idx];
			setLabel(msg);
			flagTouch(msg, true);
		}, ROTATE_MS);
	}

	function armMaxWait() {
		cancelMaxWait();
		var token = navToken;
		maxWaitTimer = win.setTimeout(function () {
			maxWaitTimer = null;
			if (token !== navToken || settled) {
				return;
			}
			clearBusyNow();
		}, MAX_WAIT);
	}

	function overlayOn() {
		return !!overlay && (overlay.className === 'on' || overlay.style.display === 'flex');
	}

	/**
	 * User click/submit: always arm busy. Domain Home / frame-refresh may set
	 * kloxo-busy-suppress so auto reloads do not flash Please wait — that must
	 * never block a deliberate Start/Stop/Update (it left settled=true and the
	 * modal never appeared).
	 */
	function beginNavigation(text, shown) {
		clearSuppress();
		settled = false;
		navToken += 1;
		flagSet(text || DEFAULT_TEXT, !!shown);
	}

	function present(text) {
		// Force visible even if a prior DCL left settled=true.
		settled = false;
		api.show(text);
		startRotate(text || DEFAULT_TEXT);
		armMaxWait();
		flagTouch(text || DEFAULT_TEXT, true);
	}

	/**
	 * Heavy panel work: show the modal now (no DELAY). Matches Services icons,
	 * Switch Program, Backup/Restore, Fail2Ban, SSH Terminal, Multiple PHP,
	 * fixweb/dns/mail, reboot, and similar slow pages/actions.
	 */
	var heavyRe = /(?:^|[?&#]|frm_)(?:subaction|action)=(?:switchprogram|backup|restore(?:_confirm(?:_confirm)?)?|start|stop|restart|toggle_state|toggle_boot_state|reboot|poweroff|installphp|phpbranch|multi(?:ple)?_?php|sitephp|fixweb|fixdns|fixmail|updateall|optimize|mysqlconvert|chown|hostname)|frm_o_cname=service|(?:\[class\]|%5Bclass%5D)=(?:fail2ban|sshclient|lxbackup|service|serverweb|ufwfirewall|logwatch)|(?:^|[?&])o=(?:fail2ban|sshclient|lxbackup|service|serverweb)|sa=(?:switchprogram|backup|restore)/i;

	function isHeavy(src) {
		return !!(src && heavyRe.test(String(src)));
	}

	function heavyText(src) {
		var s = String(src || '');
		if (/switchprogram/i.test(s)) {
			return 'Switching program...';
		}
		if (/restore/i.test(s)) {
			return 'Restoring...';
		}
		if (/backup/i.test(s)) {
			return 'Running backup...';
		}
		if (/fail2ban/i.test(s)) {
			return 'Loading Fail2Ban...';
		}
		if (/sshclient/i.test(s)) {
			return 'Opening SSH Terminal...';
		}
		if (/lxbackup/i.test(s)) {
			return 'Loading Backup / Restore...';
		}
		if (/serverweb|installphp|phpbranch|multi(?:ple)?_?php|sitephp/i.test(s)) {
			return 'Working on PHP / webserver...';
		}
		if (/fix(?:web|dns|mail)/i.test(s)) {
			return 'Applying server changes...';
		}
		if (/reboot|poweroff/i.test(s)) {
			return 'Sending power command...';
		}
		if (/frm_o_cname=service|\[class\]=service|%5Bclass%5D=service/i.test(s)) {
			return 'Working on service...';
		}
		return DEFAULT_TEXT;
	}

	function formSrc(f) {
		var parts = [];
		try {
			if (f.getAttribute) {
				parts.push(f.getAttribute('action') || '');
			}
			var els = f.elements;
			if (els) {
				for (var i = 0; i < els.length; i++) {
					var el = els[i];
					if (!el || !el.name) {
						continue;
					}
					var n = String(el.name);
					if (n === 'frm_subaction' || n === 'frm_action' || n.indexOf('frm_o_') === 0
						|| n.indexOf('frm_o_cname') === 0 || n === 'frm_o_cname') {
						parts.push(n + '=' + String(el.value || ''));
					}
				}
			}
		} catch (e) {
			// ignore
		}
		return parts.join('&');
	}

	function showSoon(text, since, shown) {
		cancelPending();
		if (settled) {
			return;
		}
		var token = navToken;
		var wait = shown ? 0 : DELAY - (Date.now() - since);
		if (wait <= 0) {
			present(text);
			return;
		}
		pending = win.setTimeout(function () {
			pending = null;
			if (token !== navToken || settled) {
				return;
			}
			present(text);
		}, wait);
	}

	/** Arm busy for a user navigation; heavy hints show the modal immediately. */
	function armNav(text, src) {
		var heavy = isHeavy(src) || isHeavy(text);
		var msg = text || (heavy ? heavyText(src) : DEFAULT_TEXT);
		if (msg === DEFAULT_TEXT && heavy) {
			msg = heavyText(src);
		}
		beginNavigation(msg, heavy);
		showSoon(msg, Date.now(), heavy);
	}

	var api = {
		/** @param {string=} text */
		show: function (text) {
			build();
			if (!overlay) {
				return;
			}
			setLabel(text || DEFAULT_TEXT);
			overlay.className = 'on';
			// Inline styles so a theme CSS rule cannot keep display:none.
			overlay.style.display = 'flex';
			overlay.style.visibility = 'visible';
			overlay.style.opacity = '1';
			overlay.style.pointerEvents = 'auto';
			overlay.style.zIndex = '2147483646';
		},
		hide: function () {
			cancelPending();
			cancelMaxWait();
			cancelRotate();
			if (overlay) {
				overlay.className = '';
				overlay.style.display = 'none';
				overlay.style.visibility = 'hidden';
			}
		}
	};
	win.KloxoBusy = api;

	function arrivedFully() {
		if (followRedirectStub()) {
			return;
		}
		settled = true;
		navToken += 1;
		flagClear();
		api.hide();
	}

	function clearBusyNow() {
		settled = true;
		cancelPending();
		cancelMaxWait();
		cancelRotate();
		flagClear();
		api.hide();
	}

	// Toast on the URL (Domain added / errors) means the action finished.
	if (hasToastQuery()) {
		clearBusyNow();
	}

	if (isSuppressed()) {
		clearBusyNow();
	}

	var arrived = flagGet();
	if (arrived && !hasToastQuery() && !isSuppressed()) {
		if (isImmediateRefresh()) {
			followRedirectStub();
		} else {
			// Continue spinner across the redirect hop / slow response.
			settled = false;
			navToken += 1;
			showSoon(arrived.text, arrived.t, !!arrived.shown);
		}
	} else if (isImmediateRefresh()) {
		followRedirectStub();
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', arrivedFully);
	} else if (!arrived || hasToastQuery() || isSuppressed()) {
		arrivedFully();
	} else if (!isImmediateRefresh()) {
		// Carried-over busy on an already-parsed document: wait for load, not
		// wipe immediately (that made long actions look finished early).
		win.addEventListener('load', function () {
			if (!isImmediateRefresh()) {
				arrivedFully();
			}
		});
	}

	win.addEventListener('load', function () {
		if (doc.readyState === 'complete' && !flagGet() && !isImmediateRefresh()) {
			if (overlayOn()) {
				arrivedFully();
			}
		}
	});

	// bfcache restore only — do not kill an in-flight busy carry-over.
	win.addEventListener('pageshow', function (ev) {
		if (isImmediateRefresh()) {
			return;
		}
		if (ev.persisted) {
			clearBusyNow();
			navToken += 1;
		}
	});

	// Leftover stuck overlay with no fresh flag (Refresh / race), not in-flight work.
	win.setTimeout(function () {
		if (isImmediateRefresh() || hasToastQuery() || isSuppressed()) {
			return;
		}
		if (doc.readyState !== 'interactive' && doc.readyState !== 'complete') {
			return;
		}
		var f = flagGet();
		if (!f && overlayOn()) {
			clearBusyNow();
		}
	}, DELAY + 250);

	// Absolute failsafe for hung/streaming responses.
	win.setTimeout(function () {
		if (isImmediateRefresh()) {
			return;
		}
		if (overlayOn() || flagGet() || !settled) {
			clearBusyNow();
		}
	}, MAX_WAIT);

	// Esc dismisses a stuck overlay so the user can keep working.
	doc.addEventListener('keydown', function (ev) {
		if (!ev || ev.key !== 'Escape') {
			return;
		}
		if (overlayOn() || flagGet()) {
			clearBusyNow();
		}
	}, true);

	// ---- Services icons ---------------------------------------------------------------

	var serviceAct = /[?&]frm_subaction=(start|stop|restart|toggle_state|toggle_boot_state)(?:&|$)/;
	var serviceObj = /[?&]frm_o_o(?:\[|%5B)\d+(?:\]|%5D)(?:\[|%5B)class(?:\]|%5D)=service(?:&|$)/i;
	var serviceListPage = /[?&]frm_o_cname=service(?:&|$)/i;
	// CSRF rejects GET update/add/delete; convert leftover GET links to POST + frm_token.
	var mutateAct = /[?&]frm_action=(update|add|delete)(?:&|$)/;
	var texts = {
		start: 'Starting service...',
		stop: 'Stopping service...',
		restart: 'Restarting service...',
		toggle_state: 'Changing service state...',
		toggle_boot_state: 'Changing boot state...'
	};

	function onServiceListPage() {
		try {
			return serviceListPage.test(win.location.search || '');
		} catch (e) {
			return false;
		}
	}

	function serviceText(href) {
		var m = serviceAct.exec(href);
		if (!m) {
			return '';
		}
		// Icon links use frm_o_o[…][class]=service; also accept the Services list page.
		if (serviceObj.test(href) || onServiceListPage()) {
			return texts[m[1]] || DEFAULT_TEXT;
		}
		return '';
	}

	var refreshRe = /http-equiv\s*=\s*["']?refresh["']?\s+content\s*=\s*["']?\s*\d+\s*;\s*url\s*=\s*([^"'>\s]+)/i;

	function csrfToken() {
		if (typeof win.kloxoCsrfToken === 'string' && win.kloxoCsrfToken) {
			return win.kloxoCsrfToken;
		}
		if (typeof win.uploadCsrfToken === 'string' && win.uploadCsrfToken) {
			return win.uploadCsrfToken;
		}
		var el = doc.querySelector('input[name="frm_token"]');
		return el && el.value ? el.value : '';
	}

	function run(href, text) {
		// Services always take noticeable time (systemctl): show the modal now.
		beginNavigation(text, true);
		present(text);
		var tok = csrfToken();
		var postUrl = href;
		var postBody = '';
		try {
			var u = new URL(href, win.location.href);
			postUrl = u.pathname;
			postBody = u.searchParams.toString();
			if (tok && postBody.indexOf('frm_token=') === -1) {
				postBody += (postBody ? '&' : '') + 'frm_token=' + encodeURIComponent(tok);
			}
		} catch (e) {
			if (tok && href.indexOf('frm_token=') === -1) {
				href += (href.indexOf('?') >= 0 ? '&' : '?') + 'frm_token=' + encodeURIComponent(tok);
			}
			postUrl = href;
		}
		if (!win.fetch) {
			win.location.href = tok && href.indexOf('frm_token=') === -1
				? (href + (href.indexOf('?') >= 0 ? '&' : '?') + 'frm_token=' + encodeURIComponent(tok))
				: href;
			return;
		}
		var fetchOpts = postBody
			? {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: postBody
			}
			: {credentials: 'same-origin', cache: 'no-store'};
		win.fetch(postUrl, fetchOpts).then(function (res) {
			return res.text().then(function (body) {
				return {url: res.url, body: body};
			});
		}).then(function (r) {
			var m = refreshRe.exec(r.body);
			var target = m ? m[1].replace(/&amp;/g, '&') : '';
			if (target && target.charAt(0) === '/' && target.charAt(1) !== '/') {
				flagTouch(text, true);
				win.location.assign(target);
				return;
			}
			flagClear();
			settled = true;
			cancelRotate();
			doc.open();
			doc.write(r.body);
			doc.close();
		}).catch(function () {
			win.location.reload();
		});
	}

	var noNavigate = /^(?:mailto:|tel:|sms:|blob:|data:|javascript:|#)/i;
	// File Manager download, and MySQL Database Backup Now (streams a dump).
	var downloadUrl = /[?&]frm_subaction=download(?:&|$)|[?&]frm_subaction=backup(?:&|$)|\/filedownload\.php/i;

	function suppress() {
		suppressUntil = Date.now() + 2000;
	}

	function hasChosenFile(f) {
		var inputs = f.querySelectorAll ? f.querySelectorAll('input[type="file"]') : [];
		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].value) {
				return true;
			}
		}
		return false;
	}

	function opensElsewhere(el) {
		var t = (el.getAttribute('target') || '').toLowerCase();
		// Panel frames use target=mainframe — that is still in-panel work and
		// must show the busy modal (was wrongly treated as a new window).
		if (!t || t === '_self' || t === 'mainframe') {
			return false;
		}
		return true;
	}

	doc.addEventListener('click', function (ev) {
		if (ev.defaultPrevented || ev.button > 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) {
			return;
		}
		var n = ev.target;
		while (n && n !== doc && !(n.tagName && n.tagName.toLowerCase() === 'a')) {
			n = n.parentNode;
		}
		if (!n || n === doc) {
			return;
		}
		var href = n.getAttribute('href') || '';
		if (n.hasAttribute('data-kloxo-nobusy') || n.hasAttribute('download') || opensElsewhere(n)
			|| noNavigate.test(href) || downloadUrl.test(href)) {
			suppress();
			return;
		}
		var svc = serviceText(href);
		if (svc) {
			ev.preventDefault();
			run(href, n.getAttribute('data-kloxo-busy') || svc);
			return;
		}
		if (mutateAct.test(href) && href.indexOf('#') !== 0) {
			ev.preventDefault();
			run(href, n.getAttribute('data-kloxo-busy') || DEFAULT_TEXT);
			return;
		}
		var own = n.getAttribute('data-kloxo-busy');
		armNav(own || '', href);
	}, true);

	doc.addEventListener('submit', function (ev) {
		var f = ev.target;
		if (!f || !f.getAttribute) {
			return;
		}
		if (f.hasAttribute('data-kloxo-nobusy') || opensElsewhere(f) || hasChosenFile(f)) {
			suppress();
			return;
		}
		var src = formSrc(f);
		var own = f.getAttribute('data-kloxo-busy');
		armNav(own || '', src);
	}, true);

	win.addEventListener('beforeunload', function () {
		if (Date.now() < suppressUntil || isSuppressed()) {
			flagClear();
			return;
		}
		if (settled) {
			flagClear();
			return;
		}
		var f = flagGet();
		var text = f ? f.text : DEFAULT_TEXT;
		var shown = overlayOn() || !!(f && f.shown);
		if (!f && !overlayOn()) {
			beginNavigation(text, false);
		} else {
			settled = false;
			flagSet(text, shown);
		}
		showSoon(text, Date.now(), shown);
	});
})(window, document);
