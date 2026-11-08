/*
 * Busy modal: a centred animated GIF spinner (theme/img/kloxo-spinner.gif)
 * while the panel is working on a request. Included on every panel page
 * (HtmlLib::print_include_jscript).
 *
 * KloxoBusy.show(text) / KloxoBusy.hide()
 *
 * Every navigation away from a page can show it: link click, form submit, a
 * script that sets location or calls form.submit() (all of them end in
 * `beforeunload`). It appears only when the page is slow: DELAY ms after the
 * navigation started (counted across the page change, so quick pages never
 * show it). Once shown it stays until the next page has received its whole
 * HTML (DOMContentLoaded), with no time-out. Because the panel streams
 * its pages (the header is flushed, then the action runs for seconds), the new
 * document would otherwise replace the old one and take the modal with it; so
 * the "busy" state is kept in sessionStorage and the new page shows the modal
 * again from the first line of its <head>, before the body exists. A page that
 * ends with <meta http-equiv="Refresh" content="0;URL=..."> (the panel's
 * redirect after an action) keeps the modal up until the page it redirects to
 * has loaded.
 *
 * It is not shown for navigations that leave the page showing (they would
 * never hide it): downloads, mailto:, links or forms with a target, forms
 * with a chosen file (an upload has its own progress display), and links or
 * forms marked data-kloxo-nobusy. The delay is a timer in whichever document is
 * alive (the old page while the server has not answered, then the new page for
 * the rest of it), not a CSS fade: a CSS animation does not run in a document
 * that has not painted yet. A modal that was already shown before a redirect
 * is shown again at once by the next page (no gap between the hops).
 *
 * Service list icons (Sb, State, Start, Stop, Restart) run their action in the
 * background (fetch) and then follow the server's redirect. Any link or form
 * can set its own text with data-kloxo-busy="text".
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoBusy) {
		return;
	}

	var KEY = 'kloxo-busy';
	var MAX_AGE = 15 * 60 * 1000;   // a flag older than this is a navigation that never finished
	var DEFAULT_TEXT = 'Please wait...';
	var DELAY = 500;                // ms a navigation may take before the modal appears

	var overlay = null;
	var label = null;
	var suppressUntil = 0;
	var pending = null;

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
				// storage full or disabled: the modal just will not survive the page change
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
			// unreadable flag: ignore
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
			'#kloxo-busy.on{display:flex}' +
			'#kloxo-busy .kb-box{background:#fff;border-radius:8px;padding:22px 34px;min-width:200px;' +
			'text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.4);font:14px Arial,Helvetica,sans-serif;color:#333}' +
			'#kloxo-busy img{width:64px;height:64px;display:block;margin:0 auto 10px}'
		));
		(doc.head || doc.getElementsByTagName('head')[0] || host).appendChild(css);

		overlay = doc.createElement('div');
		overlay.id = 'kloxo-busy';
		overlay.setAttribute('role', 'alertdialog');
		overlay.setAttribute('aria-busy', 'true');
		var box = doc.createElement('div');
		box.className = 'kb-box';
		var img = doc.createElement('img');
		img.src = '/theme/img/kloxo-spinner.gif';
		img.alt = '';
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

	function overlayOn() {
		return !!overlay && overlay.className === 'on';
	}

	function present(text) {
		api.show(text);
		var f = flagGet();
		if (f) {
			flagSet(f.text, true);
		}
	}

	/*
	 * Show the modal once the navigation that started at `since` has been running for
	 * DELAY ms (at once when it was already shown before a redirect). The timer lives
	 * in the current document; if the page changes first, the next one carries on.
	 */
	function showSoon(text, since, shown) {
		cancelPending();
		var wait = shown ? 0 : DELAY - (Date.now() - since);
		if (wait <= 0) {
			present(text);
			return;
		}
		pending = win.setTimeout(function () {
			pending = null;
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
			if (overlay) {
				overlay.className = '';
			}
		}
	};
	win.KloxoBusy = api;

	// The page we arrived on was left while busy: carry on counting (or show it at once
	// if it was already on screen).
	var arrived = flagGet();
	if (arrived) {
		showSoon(arrived.text, arrived.t, !!arrived.shown);
	}

	// ---- Services icons: run in the background, then follow the redirect ----------------

	// /display.php?frm_action=update&frm_subaction=stop&...&frm_o_o[1][class]=service&...
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

	/*
	 * The finished response ends with <meta http-equiv="Refresh" content="0;URL=...">:
	 * follow that URL ourselves once the whole response (= the action) is done.
	 */
	var refreshRe = /http-equiv\s*=\s*["']?refresh["']?\s+content\s*=\s*["']?\s*\d+\s*;\s*url\s*=\s*([^"'>\s]+)/i;

	function run(href, text) {
		flagSet(text, false);
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
			// No redirect (an error page, for instance): show what the server sent.
			flagClear();
			doc.open();
			doc.write(r.body);
			doc.close();
		}).catch(function () {
			// The action may have run; never repeat it, just show the current state.
			win.location.reload();
		});
	}

	// ---- which navigations get the modal -------------------------------------------------

	// Navigations that leave the page on screen (the modal would never hide).
	var noNavigate = /^(?:mailto:|tel:|sms:|blob:|data:|#)/i;
	var downloadUrl = /[?&]frm_subaction=download(?:&|$)|\/filedownload\.php/i;

	function suppress() {
		suppressUntil = Date.now() + 2000;
	}

	// The panel renders almost every form as multipart/form-data, so the encoding says
	// nothing; only a form that is really uploading a file has its own progress display.
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
		var own = n.getAttribute('data-kloxo-busy');
		if (own) {
			flagSet(own, false);   // beforeunload shows the modal with this text
		}
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
		var t = f.getAttribute('data-kloxo-busy');
		if (t) {
			flagSet(t, false);
			showSoon(t, Date.now(), false);
		}
	}, true);

	// Any other way of leaving the page (links, forms, location, form.submit()).
	win.addEventListener('beforeunload', function () {
		if (Date.now() < suppressUntil) {
			return;
		}
		var f = flagGet();
		var text = f ? f.text : DEFAULT_TEXT;
		var shown = overlayOn() || !!(f && f.shown);
		flagSet(text, shown);
		showSoon(text, Date.now(), shown);
	});

	// ---- hiding it ---------------------------------------------------------------------

	// The page has all its HTML. If it is only a redirect page, stay busy for the next one.
	function arrivedFully() {
		var metas = doc.getElementsByTagName('meta');
		for (var i = 0; i < metas.length; i++) {
			var he = (metas[i].getAttribute('http-equiv') || '').toLowerCase();
			var c = metas[i].getAttribute('content') || '';
			if (he === 'refresh' && /^\s*[01]?\s*(?:;|$)/.test(c) && /url\s*=/i.test(c)) {
				return;
			}
		}
		flagClear();
		api.hide();
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', arrivedFully);
	} else {
		arrivedFully();
	}
	win.addEventListener('load', function () {
		// DOMContentLoaded already hid it; this only covers a missed event.
		if (doc.readyState === 'complete' && !doc.querySelector('meta[http-equiv="refresh" i]')) {
			flagClear();
			api.hide();
		}
	});

	// Back/forward cache restores the page with the modal still open.
	win.addEventListener('pageshow', function (ev) {
		if (ev.persisted) {
			flagClear();
			api.hide();
		}
	});
})(window, document);
