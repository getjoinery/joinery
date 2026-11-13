/*
 * The mail reader's two background tasks (includes/mailbox_reader_mount.php):
 *
 *   - [data-mbx-unseal-convergence] on the page: the signed-in user still owns
 *     sealed rows on domains that no longer seal, so mailbox/unseal_batch runs
 *     in a loop until done (specs/mailbox_lowering_unseal.md § 6). A locked
 *     vault stops it quietly; the next unlock and visit converge the rest.
 *   - .mbx-catchup-banner: its Catch up button drains the long lane, where the
 *     AI work runs.
 *
 * @version 1.0
 */
(function () {
	if (!document.querySelector('[data-mbx-unseal-convergence]')) return;
	function batch() {
		joineryApi.post('mailbox/unseal_batch', {}).then(function (d) {
			d = d || {};
			if (d.locked || !d.unsealed || !(d.own_remaining > 0)) return;
			batch();
		}).catch(function () { /* silent — the next visit resumes */ });
	}
	batch();
})();

(function () {
	var box = document.querySelector('.mbx-catchup-banner');
	if (!box) { return; }
	var btn = box.querySelector('.mbx-catchup-btn');
	var out = box.querySelector('.mbx-catchup-progress');
	var done = 0, running = false, idlePasses = 0;
	var IDLE_WAIT_MS = 5000, IDLE_MAX = 24;   // about two minutes of passes that finish nothing
	var input = { lane: 'long' };

	function pass() {
		joineryApi.post('vault_deferred_work', input).then(function (d) {
			d = d || {};
			if (d.locked) {
				out.textContent = 'Unlock your vault to continue.';
				stop();
				return;
			}
			var did = 0;
			Object.keys(d.done || {}).forEach(function (k) { did += d.done[k]; });
			done += did;
			out.textContent = done ? ('Processed ' + done + '\u2026') : 'Working\u2026';
			if (!d.long_more && !d.more) {
				out.textContent = 'All caught up.';
				stop();
				return;
			}
			// AI work first; while parsing holds it off, parse.
			input = d.long_more ? { lane: 'long' } : (d.foreground_more ? { foreground: 1 } : {});
			if (did > 0) { idlePasses = 0; pass(); return; }
			if (++idlePasses >= IDLE_MAX) {
				out.textContent = 'Nothing more could be done right now \u2014 try again later.';
				stop();
				return;
			}
			setTimeout(pass, IDLE_WAIT_MS);
		}).catch(function () {
			out.textContent = 'Stopped \u2014 try again.';
			stop();
		});
	}

	function stop() {
		running = false;
		btn.disabled = false;
		btn.textContent = 'Catch up';
	}

	btn.addEventListener('click', function () {
		if (running) { return; }
		running = true;
		idlePasses = 0;
		input = { lane: 'long' };
		btn.disabled = true;
		btn.textContent = 'Working';
		out.textContent = 'Working\u2026';
		pass();
	});
})();
