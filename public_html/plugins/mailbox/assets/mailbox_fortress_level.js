/**
 * Moving stored mail onto and off end-to-end, in place (specs/client_custody_mail.md § R8).
 *
 * One element carries the state: data-direction ("raise" or "lower"),
 * data-backlog and data-domain-id (optional, narrows the counts). Its status
 * line is [data-fortress-text], or the receipt's #fortress-move-row line
 * (never its other rows); its button is #fortress-lower-button (or
 * [data-fortress-lower]).
 *
 *  - raise: the messages move as the vault's deferred work, which the vault
 *    client runs while the window is open; this only watches the count
 *    (mailbox/fortress_backlog) until nothing is left, or until what is left
 *    is only messages that could not be moved. While the vault is locked it
 *    keeps watching, so an unlock picks up where it stopped.
 *  - lower: the button opens the mail vault (a passkey tap, or the passphrase)
 *    and has JoinerySealed.changeCustody('mail') move every message's key to
 *    the server's, then reloads, so the page shows what comes next (the
 *    unseal, on a way down to Standard).
 *
 * Used by the domain editor's receipt card and the mailbox page's banner.
 *
 * @version 1.1 - progress goes to the receipt's move row, not its first row; the raise
 *   watches the deferred work's count instead of running passes itself
 * @version 1.0
 */
(function () {
	'use strict';

	function plural(n, one) { return n + ' ' + one + (n === 1 ? '' : 's'); }

	function textOf(el) { return el.querySelector('[data-fortress-text]') || el.querySelector('#fortress-move-row .fortress-receipt-text'); }

	function setDot(el, color) {
		var dot = el.querySelector('#fortress-move-row .receipt-dot') || el.querySelector('[data-fortress-dot]');
		if (dot) dot.style.background = color;
	}

	/** Milliseconds between counts while messages move, and while the vault is locked. */
	var POLL_MOVING_MS = 5000;
	var POLL_LOCKED_MS = 15000;

	async function watchRaise(el) {
		var text = textOf(el);
		var body = {};
		if (el.dataset.domainId) body.domain_id = parseInt(el.dataset.domainId, 10);
		for (;;) {
			var d;
			try {
				d = await joineryApi.post('mailbox/fortress_backlog', body);
			} catch (e) {
				setDot(el, '#dc3545');
				if (text) text.textContent = 'Could not check on the move: ' + (e && e.message ? e.message : 'the server did not answer') + '. Reload to look again.';
				return;
			}
			var left = parseInt(d.raise_remaining, 10) || 0;
			var ready = parseInt(d.raise_ready, 10) || 0;
			if (left === 0) {
				setDot(el, '#28a745');
				if (text) text.textContent = 'Every earlier message is on your device key';
				el.dispatchEvent(new CustomEvent('fortress:done', { bubbles: true }));
				return;
			}
			if (ready === 0) {
				setDot(el, '#dc3545');
				if (text) text.textContent = plural(left, 'earlier message') + ' could not be moved to your device key. They stay as they were.';
				return;
			}
			if (!d.window_open) {
				setDot(el, '#ffc107');
				if (text) text.textContent = plural(left, 'earlier message') + ' still to move. Unlock your vault and they carry on.';
				await new Promise(function (r) { setTimeout(r, POLL_LOCKED_MS); });
				continue;
			}
			setDot(el, '#28a745');
			if (text) text.textContent = 'Moving earlier messages to your device key — ' + plural(left, 'message') + ' to go…';
			await new Promise(function (r) { setTimeout(r, POLL_MOVING_MS); });
		}
	}

	async function runLower(el, button) {
		var text = textOf(el);
		button.disabled = true;
		try {
			var result = await JoinerySealed.changeCustody('mail', {
				reason: 'to move your messages back to this server',
				progress: function (moved, total) {
					if (text) text.textContent = 'Moved ' + moved + (total != null ? ' of ' + total : '') + '…';
				},
			});
			setDot(el, '#28a745');
			if (text) text.textContent = result.moved > 0 ? plural(result.moved, 'message') + ' moved back' : 'Nothing left to move';
			window.location.reload();
		} catch (e) {
			button.disabled = false;
			setDot(el, '#dc3545');
			if (text) text.textContent = 'Moving stopped: ' + (e && e.message ? e.message : 'something went wrong') + '. Press the button to carry on.';
		}
	}

	function mount(el) {
		if (!el || el.dataset.fortressMounted) return;
		el.dataset.fortressMounted = '1';
		if ((parseInt(el.dataset.backlog, 10) || 0) <= 0) return;
		if (el.dataset.direction === 'raise') {
			watchRaise(el);
			return;
		}
		var button = el.querySelector('#fortress-lower-button') || el.querySelector('[data-fortress-lower]');
		if (button) button.addEventListener('click', function () { runLower(el, button); });
	}

	function mountAll() {
		document.querySelectorAll('#fortress-receipt, [data-fortress-level]').forEach(mount);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll);
	else mountAll();

	window.MailboxFortressLevel = { mount: mount };
})();
