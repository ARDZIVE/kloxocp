/*
 * Busy modal: a centred spinner (Loading.io "ball", theme/loadingio/fvz7y1.svg,
 * Loading.io BY License) while a long-running panel action executes.
 *
 * KloxoBusy.show(text) / KloxoBusy.hide()
 *
 * Service list icons (Sb, State, Start, Stop, Restart) show it on click until the
 * next page has loaded; any link or form can opt in with data-kloxo-busy="text".
 */
(function (win, doc) {
	'use strict';

	if (win.KloxoBusy) {
		return;
	}

	var overlay = null;
	var label = null;
	var timer = null;

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
			'#kloxo-busy img{width:72px;height:72px;display:block;margin:0 auto 10px;' +
			'animation:kloxo-busy-spin 1s steps(8) infinite}' +
			'@keyframes kloxo-busy-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}'
		));
		doc.getElementsByTagName('head')[0].appendChild(css);

		overlay = doc.createElement('div');
		overlay.id = 'kloxo-busy';
		overlay.setAttribute('role', 'alertdialog');
		overlay.setAttribute('aria-busy', 'true');
		var box = doc.createElement('div');
		box.className = 'kb-box';
		var img = doc.createElement('img');
		img.src = '/theme/loadingio/fvz7y1.svg';
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
			if (timer) {
				win.clearTimeout(timer);
			}
			// A download or a target=_blank link never replaces this page: do not stay stuck.
			timer = win.setTimeout(api.hide, 90000);
		},
		hide: function () {
			if (timer) {
				win.clearTimeout(timer);
				timer = null;
			}
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
			api.show(text);
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
