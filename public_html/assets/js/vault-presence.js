/**
 * Vault presence beacon (specs/mailbox_security_levels.md § The Unlock Window).
 *
 * Presence means "on Joinery": while the signed-in user has an open vault
 * unlock window, every page beats vault_heartbeat so the window survives
 * navigation anywhere on the site and ends only when the browser is genuinely
 * gone (closed, asleep, machine off). PublicPageBase includes this script for
 * every signed-in user and emits <meta name="joinery-vault-window"
 * content="open"> when a window is open at render time; a page that unlocks
 * mid-life (the mail reader, the security page) starts the beacon by calling
 * JoineryVaultPresence.start() or dispatching a 'joinery:vault-unlocked' event.
 *
 * Beat policy: every 25s. Hidden tabs keep beating — the browser throttles the
 * interval (~1/min background, worst ~1/5min), and the server's stale threshold
 * (VaultUnlock::HEARTBEAT_MAX_STALE_SECONDS) sits above the worst throttle, so
 * a background Joinery tab still counts as present. A beat answering
 * alive:false stops the beacon (window ended elsewhere - explicit lock,
 * credential event, cap) and dispatches 'joinery:vault-locked' so the lock
 * chip and any consumer surface on the page re-seal without waiting for a
 * failed read. A 'joinery:vault-locked' dispatched by page code (the chip's
 * Lock now, a reader's Lock control) stops the beacon the same way.
 *
 * @version 1.2
 */
(function () {
	'use strict';

	var timer = null;

	function beat() {
		joineryApi.post('vault_heartbeat', {}).then(function (res) {
			if (res && res.alive === false && timer) {
				stop();
				document.dispatchEvent(new CustomEvent('joinery:vault-locked'));
			}
		}).catch(function () { /* transient failure - keep beating */ });
	}

	function start() {
		if (timer) { return; }
		beat();
		timer = setInterval(beat, 25000);
	}

	function stop() {
		if (timer) { clearInterval(timer); timer = null; }
	}

	// An immediate beat when the tab becomes visible again recovers quickly
	// from background throttling.
	document.addEventListener('visibilitychange', function () {
		if (timer && document.visibilityState === 'visible') { beat(); }
	});

	// A page that opens a window mid-life announces it; an explicit lock
	// anywhere on the page ends the beacon.
	document.addEventListener('joinery:vault-unlocked', start);
	document.addEventListener('joinery:vault-locked', stop);

	window.JoineryVaultPresence = { start: start, stop: stop };

	if (document.querySelector('meta[name="joinery-vault-window"][content="open"]')) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', start);
		} else {
			start();
		}
	}
})();
