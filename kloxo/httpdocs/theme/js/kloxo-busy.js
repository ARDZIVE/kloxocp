/*
 * Busy modal: a centred animated GIF spinner (theme/img/kloxo-spinner.gif)
 * while a long-running panel action executes.
 *
 * KloxoBusy.show(text) / KloxoBusy.hide()
 *
 * Service list icons (Sb, State, Start, Stop, Restart) show it on click and it
 * stays until the request finishes (there is no time-out); the request runs
 * in the background (fetch), then the page follows the server's redirect.
 * Any link can opt in with data-kloxo-busy="text"; a form with the same
 * attribute shows the modal on submit.
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoBusy) {
		return;
	}

	var overlay = null;
	var label = null;

	function build() {
		if (overlay || !doc.body) {
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
		doc.getElementsByTagName('head')[0].appendChild(css);

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
		doc.body.appendChild(overlay);
	}

	var api = {
		show: function (text) {
			build();
			if (!overlay) {
				return;
			}
			label.textContent = text || 'Please wait...';
			overlay.className = 'on';
		},
		hide: function () {
			if (overlay) {
				overlay.className = '';
			}
		}
	};
	win.KloxoBusy = api;

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

	function textFor(a) {
		var t = a.getAttribute('data-kloxo-busy');
		if (t) {
			return t;
		}
		var href = a.getAttribute('href') || '';
		var m = serviceAct.exec(href);
		return (m && serviceObj.test(href)) ? texts[m[1]] : '';
	}

	/*
	 * Run the action in the background and keep this page (and the modal) on screen.
	 * A normal navigation swaps in the new document as soon as the server flushes
	 * the page header (the action then still runs for seconds), which showed a blank
	 * page with only the background image and no modal. The finished response ends
	 * with <meta http-equiv="Refresh" content="0;URL=...">: follow that URL ourselves
	 * once the whole response (= the action) is done.
	 */
	var refreshRe = /http-equiv\s*=\s*["']?refresh["']?\s+content\s*=\s*["']?\s*\d+\s*;\s*url\s*=\s*([^"'>\s]+)/i;

	function run(href, text) {
		api.show(text);
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
			doc.open();
			doc.write(r.body);
			doc.close();
		}).catch(function () {
			// The action may have run; never repeat it, just show the current state.
			win.location.reload();
		});
	}

	doc.addEventListener('click', function (ev) {
		if (ev.defaultPrevented || ev.button > 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey) {
			return;
		}
		var n = ev.target;
		while (n && n !== doc && !(n.tagName && n.tagName.toLowerCase() === 'a')) {
			n = n.parentNode;
		}
		if (!n || n === doc || n.target === '_blank') {
			return;
		}
		var text = textFor(n);
		if (text) {
			ev.preventDefault();
			run(n.getAttribute('href'), text);
		}
	}, true);

	doc.addEventListener('submit', function (ev) {
		var f = ev.target;
		var t = f && f.getAttribute ? f.getAttribute('data-kloxo-busy') : '';
		if (t) {
			api.show(t);
		}
	}, true);

	// Back/forward cache restores the page with the modal still open.
	win.addEventListener('pageshow', function () {
		api.hide();
	});
})(window, document);
