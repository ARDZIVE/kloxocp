/*
 * Busy modal: a centred animated GIF spinner (theme/img/kloxo-spinner.gif)
 * while the panel is working on a request. Included on every panel page
 * (HtmlLib::print_include_jscript).
 *
 * KloxoBusy.show(text) / KloxoBusy.hide()
 *
 * Every in-panel navigation can show it: link click, form submit, a script
 * that sets location or calls form.submit(). It appears only when the page
 * is slow: DELAY ms after the navigation started (counted across the page
 * change, so quick pages never show it). Once shown it stays until the next
 * page has received its whole HTML (DOMContentLoaded), with a MAX_WAIT
 * failsafe. Because the panel can stream its pages (header first, then a
 * long action), the new document would otherwise replace the old one and
 * take the modal with it; so the "busy" state is kept in sessionStorage and
 * the new page shows the modal again from the first line of its <head>.
 * A page that ends with <meta http-equiv="Refresh" content="0;URL=...">
 * keeps the modal up until the page it redirects to has loaded.
 *
 * It is not shown for navigations that leave the page showing (they would
 * never hide it): downloads, mailto:, links or forms with a target, forms
 * with a chosen file (an upload has its own progress display), and links or
 * forms marked data-kloxo-nobusy. Service list icons (Sb, State, Start,
 * Stop, Restart) run their action in the background (fetch) and then follow
 * the server's redirect. Any link or form can set its own text with
 * data-kloxo-busy="text".
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoBusy) {
		return;
	}

	var KEY = 'kloxo-busy';
	var MAX_AGE = 2 * 60 * 1000;
	var MAX_WAIT = 90 * 1000;
	var DEFAULT_TEXT = 'Please wait...';
	var DELAY = 500;

	var overlay = null;
	var label = null;
	var suppressUntil = 0;
	var pending = null;
	var maxWaitTimer = null;
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

	/** Meta-refresh / Continue stubs: clear busy and navigate (do not wait). */
	function followRedirectStub() {
		var u = refreshTarget();
		if (!u) {
			return false;
		}
		flagClear();
		settled = true;
		cancelPending();
		cancelMaxWait();
		api.hide();
		try {
			win.location.replace(u);
		} catch (e) {
			win.location.href = u;
		}
		return true;
	}

	function build() {
		if (overlay) {
			return;
		}
		var host = doc.body || doc.documentElement;
		if (!host) {
			return;
		}
		var css = doc.createElement('style');
		css.type = 'text/css';
		css.appendChild(doc.createTextNode(
			'#kloxo-busy{display:none;position:fixed;left:0;top:0;right:0;bottom:0;z-index:2147483000;' +
			'background:rgba(0,0,0,.45);align-items:center;justify-content:center}' +
			'#kloxo-busy.on{display:flex;align-items:center;justify-content:center}' +
			'#kloxo-busy .kb-box{background:#fff;border-radius:8px;padding:22px 34px;min-width:200px;' +
			'text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.4);font:14px Arial,Helvetica,sans-serif;color:#333}' +
			'#kloxo-busy img{width:64px;height:64px;display:block;margin:0 auto 10px;background:#fff}'
		));
		(doc.head || doc.getElementsByTagName('head')[0] || host).appendChild(css);

		overlay = doc.createElement('div');
		overlay.id = 'kloxo-busy';
		overlay.setAttribute('role', 'alertdialog');
		overlay.setAttribute('aria-busy', 'true');
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

	function armMaxWait() {
		cancelMaxWait();
		var token = navToken;
		maxWaitTimer = win.setTimeout(function () {
			maxWaitTimer = null;
			if (token !== navToken || settled) {
				return;
			}
			flagClear();
			api.hide();
		}, MAX_WAIT);
	}

	function overlayOn() {
		return !!overlay && overlay.className === 'on';
	}

	/** Leaving this page: allow showSoon again (settled was true after DCL). */
	function beginNavigation(text, shown) {
		settled = false;
		navToken += 1;
		flagSet(text || DEFAULT_TEXT, !!shown);
	}

	function present(text) {
		if (settled) {
			return;
		}
		api.show(text);
		armMaxWait();
		var f = flagGet();
		if (f) {
			flagSet(f.text, true);
		}
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

	var api = {
		/** @param {string=} text */
		show: function (text) {
			build();
			if (!overlay) {
				return;
			}
			label.textContent = text || DEFAULT_TEXT;
			overlay.className = 'on';
		},
		hide: function () {
			cancelPending();
			cancelMaxWait();
			if (overlay) {
				overlay.className = '';
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

	var arrived = flagGet();
	if (arrived) {
		if (isImmediateRefresh()) {
			followRedirectStub();
		} else if (doc.readyState === 'loading') {
			showSoon(arrived.text, arrived.t, !!arrived.shown);
		} else {
			flagClear();
		}
	} else if (isImmediateRefresh()) {
		followRedirectStub();
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', arrivedFully);
	} else {
		arrivedFully();
	}
	win.addEventListener('load', function () {
		if (doc.readyState === 'complete') {
			arrivedFully();
		}
	});

	// Only bfcache restores need an extra clear (persisted document).
	win.addEventListener('pageshow', function (ev) {
		if (ev.persisted) {
			settled = true;
			flagClear();
			api.hide();
		}
	});

	// ---- Services icons ---------------------------------------------------------------

	var serviceAct = /[?&]frm_subaction=(start|stop|restart|toggle_state|toggle_boot_state)(?:&|$)/;
	var serviceObj = /[?&]frm_o_o(?:\[|%5B)\d+(?:\]|%5D)(?:\[|%5B)class(?:\]|%5D)=service(?:&|$)/;
	var texts = {
		start: 'Starting service...',
		stop: 'Stopping service...',
		restart: 'Restarting service...',
		toggle_state: 'Changing service state...',
		toggle_boot_state: 'Changing boot state...'
	};

	function serviceText(href) {
		var m = serviceAct.exec(href);
		return (m && serviceObj.test(href)) ? texts[m[1]] : '';
	}

	var refreshRe = /http-equiv\s*=\s*["']?refresh["']?\s+content\s*=\s*["']?\s*\d+\s*;\s*url\s*=\s*([^"'>\s]+)/i;

	function run(href, text) {
		beginNavigation(text, false);
		showSoon(text, Date.now(), false);
		if (!win.fetch) {
			win.location.href = href;
			return;
		}
		win.fetch(href, {credentials: 'same-origin', cache: 'no-store'}).then(function (res) {
			return res.text().then(function (body) {
				return {url: res.url, body: body};
			});
		}).then(function (r) {
			var m = refreshRe.exec(r.body);
			var target = m ? m[1].replace(/&amp;/g, '&') : '';
			if (target && target.charAt(0) === '/' && target.charAt(1) !== '/') {
				win.location.assign(target);
				return;
			}
			flagClear();
			settled = true;
			doc.open();
			doc.write(r.body);
			doc.close();
		}).catch(function () {
			win.location.reload();
		});
	}

	var noNavigate = /^(?:mailto:|tel:|sms:|blob:|data:|javascript:|#)/i;
	var downloadUrl = /[?&]frm_subaction=download(?:&|$)|\/filedownload\.php/i;

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
		var t = el.getAttribute('target');
		return !!t && t !== '_self';
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
		var own = n.getAttribute('data-kloxo-busy') || DEFAULT_TEXT;
		beginNavigation(own, false);
		showSoon(own, Date.now(), false);
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
		var t = f.getAttribute('data-kloxo-busy') || DEFAULT_TEXT;
		beginNavigation(t, false);
		showSoon(t, Date.now(), false);
	}, true);

	win.addEventListener('beforeunload', function () {
		if (Date.now() < suppressUntil) {
			return;
		}
		var f = flagGet();
		var text = f ? f.text : DEFAULT_TEXT;
		var shown = overlayOn() || !!(f && f.shown);
		// Navigation may have started without a click we saw (location= / form.submit()).
		if (!f && !overlayOn()) {
			beginNavigation(text, false);
		} else {
			settled = false;
			flagSet(text, shown);
		}
		showSoon(text, Date.now(), shown);
	});
})(window, document);
