/**
 * JoineryCeremonyBatch — the progress loop behind any change that takes effect
 * at once but has byte work trailing behind it.
 *
 * Raising a mail domain's protection seals the messages already received.
 * Making a Drive folder Private seals the files already in it. Both promise
 * immediately and converge afterwards, and both need the same thing on screen:
 * a row that counts down, resolves into a completed statement, says so plainly
 * when it stops making progress, and works without JavaScript one batch per
 * page load.
 *
 * Usage — put the config on the card and mark the pieces:
 *
 *   <div data-ceremony-batch='{"action":"drive/level_batch",
 *                              "payload":{"folder_id":12},
 *                              "remaining":42,
 *                              "labels":{...}}'>
 *     <span data-ceremony-dot></span>
 *     <span data-ceremony-text>Sealing…</span>
 *     <form data-ceremony-noscript>…</form>   <- removed when this runs
 *     <a data-ceremony-when-done hidden>Done</a>
 *   </div>
 *
 * Label templates take {remaining}, {done}, {total} and pluralize with
 * {s:total} (empty for 1, "s" otherwise).
 *
 * A pass that converts NOTHING while work remains is the stuck signal — the one
 * failure mode that must never become an infinite loop. It stops and says what
 * is left.
 *
 * @version 1.1
 */
window.JoineryCeremonyBatch = (function () {
	'use strict';

	var DEFAULT_LABELS = {
		working: 'Working — {remaining} remaining…',
		done: '{total} item{s:total} finished',
		none: 'Nothing needed doing',
		stuck: '{remaining} item{s:remaining} could not be finished',
		paused: 'Paused — reload this page to resume'
	};

	function fill(template, vars) {
		return String(template || '').replace(/\{(s:)?(\w+)\}/g, function (_, plural, key) {
			var value = vars[key];
			if (plural) return (Number(value) === 1) ? '' : 's';
			return (value === undefined || value === null) ? '' : String(value);
		});
	}

	function csrf() {
		var meta = document.querySelector('meta[name="joinery-api-csrf"]');
		return meta ? meta.content : '';
	}

	function run(card) {
		var config;
		try {
			config = JSON.parse(card.getAttribute('data-ceremony-batch') || '{}');
		} catch (e) {
			return;
		}
		if (!config.action) return;

		var labels = Object.assign({}, DEFAULT_LABELS, config.labels || {});
		var doneKey = config.doneKey || 'converted';
		var remainingKey = config.remainingKey || 'remaining';
		var remaining = parseInt(config.remaining, 10) || 0;
		var total = parseInt(config.doneTotal, 10) || 0;

		var dot = card.querySelector('[data-ceremony-dot]');
		var text = card.querySelector('[data-ceremony-text]');
		var whenDone = card.querySelectorAll('[data-ceremony-when-done]');

		// The no-JS fallback and this loop must never both run.
		var fallback = card.querySelector('[data-ceremony-noscript]');
		if (fallback) fallback.remove();

		function setDot(state) { if (dot) dot.setAttribute('data-state', state); }
		function say(template, vars) { if (text) text.textContent = fill(template, vars); }

		function finish() {
			setDot('done');
			say(total > 0 ? labels.done : labels.none, { total: total, done: total, remaining: 0 });
			// Reveal both ways a card can hide something. `hidden` is the marker
			// this driver documents, but a page already using the utility class
			// would stay invisible behind `display:none !important` — a card whose
			// countdown resolves into a button nobody can see is worse than no
			// button at all.
			for (var i = 0; i < whenDone.length; i++) {
				whenDone[i].hidden = false;
				whenDone[i].classList.remove('d-none');
			}
			card.dispatchEvent(new CustomEvent('ceremony:done', { bubbles: true, detail: { total: total } }));
		}

		function stop(state, template, vars) {
			setDot(state);
			say(template, vars);
			card.dispatchEvent(new CustomEvent('ceremony:stopped', { bubbles: true, detail: vars }));
		}

		function batch() {
			fetch('/api/v1/action/' + config.action, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf() },
				body: JSON.stringify(config.payload || {})
			}).then(function (r) {
				return r.json().then(function (j) {
					if (!r.ok) throw new Error((j && j.error) || 'Request failed.');
					return j.data;
				});
			}).then(function (d) {
				var did = parseInt(d[doneKey], 10) || 0;
				remaining = parseInt(d[remainingKey], 10) || 0;
				total += did;
				if (remaining > 0 && did === 0) {
					// Nothing moved and work is left: looping again would just
					// repeat the same failure forever.
					stop('error', labels.stuck, { remaining: remaining, total: total, done: 0 });
					return;
				}
				if (remaining > 0) {
					say(labels.working, { remaining: remaining, total: total, done: did });
					batch();
				} else {
					finish();
				}
			}).catch(function (e) {
				stop('error', e && e.message ? e.message : labels.paused,
					{ remaining: remaining, total: total, done: 0 });
			});
		}

		if (remaining <= 0) { finish(); return; }
		setDot('working');
		say(labels.working, { remaining: remaining, total: total, done: 0 });
		batch();
	}

	function init(root) {
		var cards = (root || document).querySelectorAll('[data-ceremony-batch]');
		for (var i = 0; i < cards.length; i++) {
			if (cards[i].dataset.ceremonyStarted) continue;
			cards[i].dataset.ceremonyStarted = '1';
			run(cards[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { init(document); });
	} else {
		init(document);
	}

	return { init: init, run: run };
})();
