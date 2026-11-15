/*
 * The Relay section's trust box (includes/relay_section.php,
 * specs/relay_update_trust_step.md R1): its calm words only when this
 * browser's own note says it started this update (relay_grant.js wrote it);
 * otherwise the server-rendered words stay, which say this browser cannot
 * confirm the site's account.
 *
 * @version 1.0
 */
(function () {
	var NOTE = 'joinery-relay-update:';
	var NOTE_MS = 24 * 60 * 60 * 1000;

	function apply() {
		document.querySelectorAll('[data-relay-trust-box]').forEach(function (box) {
			var relayId = box.getAttribute('data-relay-id');
			var runId = parseInt(box.getAttribute('data-relay-run'), 10);
			var note = null;
			try { note = JSON.parse(window.localStorage.getItem(NOTE + relayId) || 'null'); } catch (e) { return; }
			if (!note || typeof note.at !== 'number' || Date.now() - note.at > NOTE_MS || note.run_id !== runId) return;
			var words = box.querySelector('[data-relay-trust-words]');
			if (!words) return;
			var at = new Date(note.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
			words.textContent = 'You started this update from this browser at ' + at + '. Your relay was rebuilt '
				+ 'and has a new identity, so your mailboxes will not trust it until you do.';
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
	else apply();
})();
