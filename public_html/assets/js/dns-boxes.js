/*
 * The DNS boxes (includes/dns/): two small behaviours, each set up only on a
 * page that has its box.
 *
 *   - The publish box's Apply stays disabled while every record left to
 *     publish needs a confirmation (cutover, adopt, remove) that is not
 *     ticked; the records are the JSON block #dns-publish-gated, written by
 *     dns_publish_box.php.
 *   - The relocation box shows only the credential fields of the host
 *     chosen in #dns_move_target.
 *
 * @version 1.0
 */
(function () {
	'use strict';

	function publishGate() {
		var el = document.getElementById('dns-publish-gated');
		if (!el) return;
		var gated;
		try { gated = JSON.parse(el.textContent); } catch (e) { return; }
		if (!Array.isArray(gated)) return;
		var form = document.getElementById('dns_apply_form') || document.querySelector('form');
		if (!form) return;
		var btn = form.querySelector('[name=btn_dns_apply]') || form.querySelector('[type=submit]');
		if (!btn) return;
		var note = document.createElement('p');
		note.className = 'text-muted small mb-0';
		note.textContent = 'Tick a confirmation above — with none ticked this would publish nothing.';
		btn.parentNode.insertBefore(note, btn.nextSibling);
		function ticked(name) {
			var out = {};
			form.querySelectorAll("input[name='" + name + "[]']:checked").forEach(function (box) { out[box.value] = true; });
			return out;
		}
		function sync() {
			var c = ticked('dns_cutover'), a = ticked('dns_adopt'), r = ticked('dns_remove');
			var any = gated.some(function (g) {
				return (!g.cutover || c[g.key]) && (!g.adopt || a[g.key]) && (!g.remove || r[g.key]);
			});
			btn.disabled = !any;
			note.style.display = any ? 'none' : '';
		}
		form.addEventListener('change', function (e) {
			if (e.target && e.target.type === 'checkbox') sync();
		});
		sync();
	}

	function relocationTarget() {
		var target = document.getElementById('dns_move_target');
		if (!target) return;
		function sync() {
			document.querySelectorAll('.dns-move-cred').forEach(function (div) {
				div.classList.toggle('d-none', div.getAttribute('data-move-target') !== target.value);
			});
		}
		target.addEventListener('change', sync);
		sync();
	}

	function start() { publishGate(); relocationTarget(); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
