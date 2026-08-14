/**
 * Joinery poll loop — the shared "ask the server again in a moment" helper.
 *
 * Several surfaces keep themselves current by asking a /api/v1 action for what
 * changed rather than holding a connection open: a held stream would pin a
 * php-fpm worker per open tab, and the worker pools on a deployment are small.
 * The loop below is that pattern in one place — chained timeouts (never
 * setInterval, which stacks requests when one runs long), a pause while the tab
 * is hidden with an immediate catch-up on return, and a poke() for "I just did
 * something, look now".
 *
 * Usage:
 *
 *   var loop = joineryPoll.create({
 *       action:   'messenger/messenger_poll',   // or a full endpoint path
 *       body:     function () { return { conversation_id: id }; },
 *       interval: function () { return 3000; },  // ms; re-read every tick
 *       onData:   function (data) { ... },
 *       onError:  function (err)  { ... }        // optional
 *   });
 *   loop.start();
 *   loop.poke();    // run one now and restart the clock
 *   loop.stop();
 *
 * A tick never overlaps its predecessor: the next timer is armed only after the
 * previous request settles. Consecutive failures back the cadence off (doubling
 * up to a minute) so a server having a bad minute is not hammered by every open
 * tab, and one success restores the normal rhythm.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var MAX_BACKOFF_MS = 60000;

	function create(options) {
		var opts = options || {};
		var timer = null;
		var running = false;
		var inFlight = false;
		var pokePending = false;
		var failures = 0;

		function intervalMs() {
			var v = typeof opts.interval === 'function' ? opts.interval() : opts.interval;
			v = parseInt(v, 10);
			if (!v || v < 250) { v = 3000; }
			if (failures > 0) {
				v = Math.min(v * Math.pow(2, failures), MAX_BACKOFF_MS);
			}
			return v;
		}

		function hidden() {
			return opts.pauseWhenHidden !== false && document.hidden;
		}

		function arm() {
			clearTimer();
			if (!running || hidden()) { return; }
			timer = setTimeout(tick, intervalMs());
		}

		function clearTimer() {
			if (timer) { clearTimeout(timer); timer = null; }
		}

		function tick() {
			timer = null;
			if (!running || inFlight || hidden()) { return; }

			var body = typeof opts.body === 'function' ? opts.body() : (opts.body || {});
			// A body of null is the caller saying "nothing to ask about right
			// now" — skip the request but keep the clock running.
			if (body === null) { arm(); return; }

			inFlight = true;
			window.joineryApi.post(opts.action, body).then(function (data) {
				inFlight = false;
				failures = 0;
				if (opts.onData) { opts.onData(data); }
				settle();
			}).catch(function (err) {
				inFlight = false;
				failures = Math.min(failures + 1, 6);
				if (opts.onError) { opts.onError(err); }
				settle();
			});
		}

		/**
		 * What happens after a request finishes.
		 *
		 * A poke that arrived mid-request is honoured here rather than dropped.
		 * That matters more than it looks: a caller pokes because it just
		 * changed something, and a response that was already in flight computed
		 * its answer before that change — yet it still advances whatever cursor
		 * the caller keeps. Skipping the catch-up would lose the change until
		 * something else happened to move the cursor again.
		 */
		function settle() {
			if (pokePending) {
				pokePending = false;
				clearTimer();
				tick();
				return;
			}
			arm();
		}

		function onVisibility() {
			if (!running) { return; }
			if (document.hidden) {
				clearTimer();
			} else {
				// Back on screen with an unknown amount of missed traffic: catch
				// up immediately rather than waiting out an interval.
				poke();
			}
		}

		function start() {
			if (running) { return api; }
			running = true;
			document.addEventListener('visibilitychange', onVisibility);
			tick();
			return api;
		}

		function stop() {
			running = false;
			clearTimer();
			document.removeEventListener('visibilitychange', onVisibility);
			return api;
		}

		/** Run one now and restart the clock from this moment. */
		function poke() {
			if (!running) { return api; }
			clearTimer();
			if (inFlight) {
				pokePending = true;
				return api;
			}
			tick();
			return api;
		}

		var api = { start: start, stop: stop, poke: poke,
			isRunning: function () { return running; } };
		return api;
	}

	window.joineryPoll = { create: create };
})();
