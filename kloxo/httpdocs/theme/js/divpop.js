/******************************************
* Popup Box- By Jim Silver @ jimsilver47@yahoo.com
* Visit http://www.dynamicdrive.com/ for full source code
* This notice must stay intact for use
******************************************/

var kloxoDrag = { el: null, dx: 0, dy: 0 };

function kloxoPopupDrag(e, id) {
	if (!e) {
		return;
	}
	if (e.preventDefault) {
		e.preventDefault();
	}
	var el = document.getElementById(id || 'showimage');
	if (!el) {
		return;
	}
	var r = el.getBoundingClientRect();
	el.style.left = r.left + 'px';
	el.style.top = r.top + 'px';
	el.style.right = 'auto';
	el.style.margin = '0';
	el.style.transform = 'none';
	kloxoDrag.el = el;
	kloxoDrag.dx = e.clientX - r.left;
	kloxoDrag.dy = e.clientY - r.top;
	document.onmousemove = function (ev) {
		if (!kloxoDrag.el || !ev) {
			return false;
		}
		kloxoDrag.el.style.left = (ev.clientX - kloxoDrag.dx) + 'px';
		kloxoDrag.el.style.top = (ev.clientY - kloxoDrag.dy) + 'px';
		return false;
	};
}

function password_initializedrag(e) {
	kloxoPopupDrag(e, 'showimage');
}

document.onmouseup = function () {
	kloxoDrag.el = null;
	document.onmousemove = null;
};

function password_hidebox(nameid) {
	if (typeof kloxoHidePopup === 'function') {
		kloxoHidePopup(nameid);
		return;
	}
	var crossobj = document.getElementById(nameid);
	if (!crossobj) {
		return;
	}
	crossobj.style.visibility = 'hidden';
	crossobj.style.display = 'none';
}

function password_showbox(fulltext) {
	var box = document.getElementById('showimage');
	var container = document.getElementById('password_container');
	if (container) {
		container.textContent = 'Password: ' + fulltext;
	}
	if (typeof kloxoShowPopup === 'function') {
		kloxoShowPopup('showimage');
		return;
	}
	if (box) {
		box.style.visibility = 'visible';
		box.style.display = 'block';
	}
}
