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
 * failed read. A 'joinery:vault-locked' dispatched by page code (the lock
 * chip's Lock now) stops the beacon the same way.
 *
 * Each beat also asks whether any feature has work waiting on the open window
 * (specs/in_window_deferred_work.md). When it does, the beacon fires a separate
 * vault_deferred_work request and chains while work remains — separate because
 * a slice can involve a language model, and the beat must stay fast.
 *
 * Drains observe a quiet period: for the first QUIET_MS after the beacon
 * starts (an unlock, or a page load with the window already open), work_pending
 * schedules the drain for the end of the period instead of firing it. The
 * page's own requests — the mail list refresh an unlock triggers, a fresh
 * page's content fetches — get the workers and the database first; the backlog
 * is background work and loses nothing by starting a few seconds late.
 *
 * Chained drains are paced: DRAIN_GAP_MS between one slice ending and the
 * next starting. Every request here counts against the API's per-address
 * budget (default 1000 an hour), the same budget the reader's own requests
 * draw on. Back-to-back ten-second slices plus the beat plus the reader can
 * spend it inside an hour when a consumer has a long backlog, and the reader
 * then answers 429 to everything — the person is locked out of their own mail
 * by background work. A drain the server refuses (429, or any failure) backs
 * off for BACKOFF_MS instead of being retried on the next beat.
 *
 * @version 1.5
 * @changelog 1.5 - chained drains paced (DRAIN_GAP_MS); a refused drain backs
 *   off (BACKOFF_MS). A long backlog drained the per-address API budget and
 *   429'd the reader (jeremytunnell, 2026-09-15).
 */
(function () {
	'use strict';

	var QUIET_MS = 10000;
	var DRAIN_GAP_MS = 15000;   // between chained slices: ~144 drains an hour at most
	var BACKOFF_MS = 60000;     // after a drain the server refused or that failed

	var timer = null;
	var draining = false;
	var quietUntil = 0;
	var drainTimer = null;

	function beat() {
		joineryApi.post('vault_heartbeat', {}).then(function (res) {
			if (res && res.alive === false && timer) {
				stop();
				document.dispatchEvent(new CustomEvent('joinery:vault-locked'));
				return;
			}
			if (res && res.work_pending) { drain(); }
		}).catch(function () { /* transient failure - keep beating */ });
	}

	// Deferred work runs in its own request so a slow model can never block the
	// beat that holds the window open (specs/in_window_deferred_work.md). One
	// drain at a time per tab: a slice can outlast the 25s beat interval, and
	// overlapping requests would just contend for the same advisory lock.
	function drain() {
		if (draining || !timer) { return; }
		var wait = quietUntil - Date.now();
		if (wait > 0) {
			if (!drainTimer) {
				drainTimer = setTimeout(function () { drainTimer = null; drain(); }, wait);
			}
			return;
		}
		draining = true;
		joineryApi.post('vault_deferred_work', {}).then(function (res) {
			draining = false;
			// More waiting and the window still open - keep going rather than
			// idling until the next beat, but paced: the next slice starts
			// DRAIN_GAP_MS after this one ended.
			if (res && res.more && !res.locked && timer) {
				quietUntil = Date.now() + DRAIN_GAP_MS;
				drain();
			}
		}).catch(function () {
			draining = false;
			// Refused or failed: wait it out. A 429 in particular means the
			// address's budget is spent; asking again sooner only spends more.
			quietUntil = Date.now() + BACKOFF_MS;
		});
	}

	function start() {
		if (timer) { return; }
		quietUntil = Date.now() + QUIET_MS;
		beat();
		timer = setInterval(beat, 25000);
	}

	function stop() {
		if (timer) { clearInterval(timer); timer = null; }
		if (drainTimer) { clearTimeout(drainTimer); drainTimer = null; }
		draining = false;   // a drain in flight finds timer null and stops chaining
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
