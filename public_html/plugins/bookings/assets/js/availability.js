/*
 * /profile/bookings/availability (views/profile/availability.php): add and
 * remove weekly hours and date overrides, and grey out an override's times
 * while it is marked unavailable. The page's #availability-config carries the
 * time and day choices as [value, label] pairs, in order.
 *
 * @version 1.0
 */
(function () {
	'use strict';
	var CFG = (window.joineryPageData && joineryPageData('availability-config')) || {};
	// New rows use a high index base so they never collide with the server-rendered
	// indices; parallel field arrays stay aligned by their explicit [n] keys.
	var avIdx = 1000;

	function select(name, pairs, blank) {
		var sel = document.createElement('select');
		sel.name = name;
		if (blank) {
			var o = document.createElement('option');
			o.value = '';
			o.textContent = '--';
			sel.appendChild(o);
		}
		(pairs || []).forEach(function (p) {
			var opt = document.createElement('option');
			opt.value = p[0];
			opt.textContent = p[1];
			sel.appendChild(opt);
		});
		return sel;
	}
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text) e.textContent = text;
		return e;
	}
	function removeButton() {
		var b = el('button', 'av-remove', 'Remove');
		b.type = 'button';
		return b;
	}
	function addWindow() {
		var n = avIdx++;
		var div = el('div', 'av-row');
		div.appendChild(select('win_day[' + n + ']', CFG.day_options, false));
		div.appendChild(select('win_start[' + n + ']', CFG.time_options, true));
		div.appendChild(el('span', '', 'to'));
		div.appendChild(select('win_end[' + n + ']', CFG.time_options, true));
		div.appendChild(removeButton());
		document.getElementById('av-windows').appendChild(div);
	}
	function addOverride() {
		var n = avIdx++;
		var div = el('div', 'av-row');
		var date = document.createElement('input');
		date.type = 'date';
		date.name = 'ovr_date[' + n + ']';
		div.appendChild(date);
		var label = document.createElement('label');
		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.name = 'ovr_unavailable[' + n + ']';
		cb.value = '1';
		cb.setAttribute('data-av-unavailable', '');
		label.appendChild(cb);
		label.appendChild(document.createTextNode(' Unavailable'));
		div.appendChild(label);
		var times = el('span', 'av-ovr-times');
		times.appendChild(select('ovr_start[' + n + ']', CFG.time_options, true));
		times.appendChild(document.createTextNode(' '));
		times.appendChild(el('span', '', 'to'));
		times.appendChild(document.createTextNode(' '));
		times.appendChild(select('ovr_end[' + n + ']', CFG.time_options, true));
		div.appendChild(times);
		div.appendChild(removeButton());
		document.getElementById('av-overrides').appendChild(div);
	}

	document.addEventListener('click', function (e) {
		var t = e.target;
		if (!t.closest) return;
		var rm = t.closest('.av-remove');
		if (rm && rm.parentNode) { rm.parentNode.remove(); return; }
		var add = t.closest('[data-av-add]');
		if (add) {
			if (add.getAttribute('data-av-add') === 'window') addWindow(); else addOverride();
		}
	});
	document.addEventListener('change', function (e) {
		var cb = e.target;
		if (!cb.hasAttribute || !cb.hasAttribute('data-av-unavailable')) return;
		var times = cb.closest('.av-row').querySelector('.av-ovr-times');
		if (times) times.classList.toggle('is-unavailable', cb.checked);
	});
})();
