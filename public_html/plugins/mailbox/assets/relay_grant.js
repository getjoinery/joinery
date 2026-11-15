/*
 * Every [data-relay-grant] button (includes/relay_section.php): open its
 * hidden step in the system modal, copying the fields it names across.
 *
 * And this browser's own note that it started a relay update
 * (specs/relay_update_trust_step.md R3): a submit inside an update's modal
 * ([data-relay-update]) marks the update as asked for; the page it lands on
 * says whether the update started ([data-relay-update-started], with the
 * run's id), and only then is the note written. A post the server refused
 * leaves no note. The mail page and the trust box read the note; this site
 * cannot write it.
 *
 * @version 1.1 - the note that this browser started a relay update
 * @version 1.0
 */
document.addEventListener('click', function (e) {
	var b = e.target.closest ? e.target.closest('[data-relay-grant]') : null;
	if (!b || !window.JoineryModal) return;
	e.preventDefault();
	var holder = document.getElementById(b.getAttribute('data-relay-grant'));
	if (!holder || !holder.firstElementChild) return;
	var node = holder.firstElementChild;
	var from = b.getAttribute('data-copy-from');
	var src = from ? document.getElementById(from) : null;
	if (src) {
		node.querySelectorAll('input[data-copy]').forEach(function (i) {
			var f = src.querySelector('[name="' + i.getAttribute('data-copy') + '"]');
			if (f) i.value = f.value;
		});
	}
	var m = JoineryModal.open(node, { buttons: [{ label: 'Cancel', style: 'secondary' }] });
	// Back into its holder when the modal closes, so it opens again.
	m.dialog.addEventListener('close', function () { holder.appendChild(node); }, { once: true });
});

(function () {
	var PENDING = 'joinery-relay-update-pending';
	var NOTE = 'joinery-relay-update:';
	// Long enough for the approval at Linode and back, no longer.
	var PENDING_MS = 2 * 60 * 60 * 1000;

	document.addEventListener('submit', function (e) {
		var tagged = e.target.closest ? e.target.closest('[data-relay-update]') : null;
		if (!tagged) return;
		try {
			window.localStorage.setItem(PENDING, JSON.stringify({
				relay_id: parseInt(tagged.getAttribute('data-relay-update'), 10), at: Date.now() }));
		} catch (err) { /* no storage here: no note */ }
	}, true);

	function landed() {
		var started = document.querySelector('[data-relay-update-started]');
		if (!started) return;
		var pending = null;
		try { pending = JSON.parse(window.localStorage.getItem(PENDING) || 'null'); } catch (err) { return; }
		if (!pending || typeof pending.at !== 'number' || Date.now() - pending.at > PENDING_MS) return;
		var relayId = parseInt(started.getAttribute('data-relay-update-started'), 10);
		if (pending.relay_id !== relayId) return;
		try {
			window.localStorage.setItem(NOTE + relayId, JSON.stringify({
				run_id: parseInt(started.getAttribute('data-relay-run'), 10), at: pending.at }));
			window.localStorage.removeItem(PENDING);
		} catch (err) { /* no storage here */ }
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', landed);
	else landed();
})();
