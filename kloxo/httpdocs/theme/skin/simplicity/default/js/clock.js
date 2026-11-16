	function startTime(id) {
		var today=new Date();
		var h=today.getHours();
		var m=today.getMinutes();
		var s=today.getSeconds();
		// add a zero in front of numbers<10
		h=checkTime(h);
		m=checkTime(m);
		s=checkTime(s);
		// textContent (security UU3).
		var el = document.getElementById(id);
		if (el) {
			el.textContent = "\u00a0" + h + ":" + m + ":" + s + "\u00a0";
		}
		t=setTimeout(function(){startTime(id)},1000);
	}

	function checkTime(i) {
		if (i<10) {
			i="0"+i;
 		}

		return i;
	}
