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
 * next starting. The budget is not what sets this. A signed-in browser is
 * metered per person (api_session_rate_limit_requests, default 5000 an hour)
 * and thirty short slices are a small part of it. The gap stands because a long
 * backlog of work nobody is looking at should not run a one-CPU box at a 40%
 * duty cycle for an hour. A drain the server refuses (429, or any failure) backs
 * off for BACKOFF_MS instead of being retried on the next beat, and nothing
 * here cuts that short.
 *
 * A page whose own work is waiting on a drain calls drainNow() (the mail
 * reader, when its list says relay-sealed mail is waiting to be parsed). That
 * starts a foreground run, past the quiet period and the chain gap: each slice
 * posts foreground=1, which the server keeps to the work a page can wait on, and
 * the next starts FOREGROUND_GAP_MS after the last ended, up to
 * FOREGROUND_MAX_SLICES. Background pacing takes over when the server says no
 * foreground work remains, a slice makes no progress (another tab holds the
 * lock, or every pending row failed to parse), the page is hidden, the cap is
 * reached, or the beacon stops.
 *
 * The cap is why a run's count outlives the run. The reader re-reads its list
 * after every slice that parsed something, and that list says "parsing" again
 * while mail is waiting, so a count reset on exit would restart the run after
 * each background slice and pace nothing. The count clears when the server
 * says no foreground work remains, or when the beacon stops. A row that fails
 * to parse every time keeps that answer true, so the worst case is thirty
 * short requests a page, then background pacing.
 *
 * Every drain that answers dispatches 'joinery:vault-work-done' with the
 * server's {done, more}, so a page can refresh what a drain just made readable.
 *
 * @version 1.7
 * @changelog 1.7 - foreground runs (specs/sealed_mail_after_unlock.md); drainNow()
 *   no longer cuts short a chain gap or a back-off from a hidden page or after
 *   the cap; an answer from a run stop() ended changes nothing; the header states
 *   the real budget
 * @changelog 1.6 - drainNow(); 'joinery:vault-work-done' after each drain
 * @changelog 1.5 - chained drains paced (DRAIN_GAP_MS); a refused drain backs
 *   off (BACKOFF_MS). A long backlog drained the per-address API budget and
 *   429'd the reader (jeremytunnell, 2026-09-15).
 */
(function () {
	'use strict';

	var QUIET_MS = 10000;
	var DRAIN_GAP_MS = 15000;   // between chained slices: ~144 drains an hour at most
	var BACKOFF_MS = 60000;     // after a drain the server refused or that failed
	var FOREGROUND_GAP_MS = 1000;
	var FOREGROUND_MAX_SLICES = 30;

	var timer = null;
	var draining = false;
	var quietUntil = 0;
	var drainTimer = null;
	var foreground = false;
	var foregroundSlices = 0;
	var backoffUntil = 0;
	var epoch = 0;              // stop() moves it: a drain still in flight then answers for a run that ended

	function isHidden() { return document.visibilityState === 'hidden'; }

	function madeProgress(done) {
		return Object.keys(done || {}).some(function (id) { return done[id] > 0; });
	}

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
		if (foreground) { foregroundSlices++; }
		var sentIn = epoch;
		joineryApi.post('vault_deferred_work', foreground ? { foreground: 1 } : {}).then(function (res) {
			var stale = sentIn !== epoch;
			if (!stale) { draining = false; }
			if (res) {
				document.dispatchEvent(new CustomEvent('joinery:vault-work-done',
					{ detail: { done: res.done || {}, more: !!res.more } }));
			}
			if (stale) { return; }
			if (res && !res.foreground_more) { foregroundSlices = 0; }
			if (foreground && (!res || res.locked || !res.foreground_more || !madeProgress(res.done)
					|| isHidden() || foregroundSlices >= FOREGROUND_MAX_SLICES)) {
				foreground = false;
			}
			if (foreground && timer) {
				quietUntil = Date.now() + FOREGROUND_GAP_MS;
				drain();
				return;
			}
			// More waiting and the window still open - keep going rather than
			// idling until the next beat, but paced: the next slice starts
			// DRAIN_GAP_MS after this one ended.
			if (res && res.more && !res.locked && timer) {
				quietUntil = Date.now() + DRAIN_GAP_MS;
				drain();
			}
		}).catch(function () {
			if (sentIn !== epoch) { return; }
			draining = false;
			foreground = false;
			// Refused or failed: wait it out. A 429 in particular means the
			// budget is spent; asking again sooner only spends more.
			backoffUntil = Date.now() + BACKOFF_MS;
			quietUntil = backoffUntil;
		});
	}

	// Start a foreground run, past the quiet period and the chain gap. A run
	// under way keeps its own schedule; a drain in flight is left to finish and
	// the run carries on from its answer. A hidden page, a spent cap and a
	// back-off all leave the existing pacing alone.
	function drainNow() {
		if (!timer) { start(); }
		if (foreground || isHidden() || foregroundSlices >= FOREGROUND_MAX_SLICES
				|| Date.now() < backoffUntil) {
			return;
		}
		foreground = true;
		quietUntil = 0;
		if (drainTimer) { clearTimeout(drainTimer); drainTimer = null; }
		drain();
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
		draining = false;   // a drain in flight is stale from here on and changes nothing
		epoch++;
		foreground = false;
		foregroundSlices = 0;
	}

	// An immediate beat when the tab becomes visible again recovers quickly
	// from background throttling.
	document.addEventListener('visibilitychange', function () {
		if (timer && document.visibilityState === 'visible') { beat(); }
		if (foreground && isHidden()) {
			foreground = false;
			// A slice already waiting on the short gap takes the long one instead.
			if (drainTimer) {
				clearTimeout(drainTimer);
				drainTimer = null;
				quietUntil = Date.now() + DRAIN_GAP_MS;
				drain();
			}
		}
	});

	// A page that opens a window mid-life announces it; an explicit lock
	// anywhere on the page ends the beacon.
	document.addEventListener('joinery:vault-unlocked', start);
	document.addEventListener('joinery:vault-locked', stop);

	window.JoineryVaultPresence = { start: start, stop: stop, drainNow: drainNow };

	if (document.querySelector('meta[name="joinery-vault-window"][content="open"]')) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', start);
		} else {
			start();
		}
	}
})();
