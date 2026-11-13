/*
 * The mailbox domain editor (admin/admin_mailbox_domains.php). The page's
 * #mailbox-domain-config says which parts apply:
 *   fortress_gate  choosing Fortress opens the owner's mail vault before saving;
 *   receipt_title  the raise receipt's heading says when sealing is done;
 *   unseal_loop    a lowering's unseal passes run here;
 *   ceremony       the protection change's checklist and submit gating, with
 *                  the domain's current level and add-ons.
 *
 * @version 1.0
 */
(function () {
'use strict';
var CFG = (window.joineryPageData && joineryPageData('mailbox-domain-config')) || {};
if (CFG.fortress_gate) {
(function () {
	var form = document.getElementById('domain_form');
	if (!form) return;
	var opened = false, opening = false;
	form.addEventListener('submit', function (e) {
		if (opened || e.defaultPrevented) return;
		var picked = form.querySelector('input[name="ied_security_level"]:checked');
		if (!picked || picked.value !== 'fortress') return;
		e.preventDefault();
		if (opening) return;
		opening = true;
		var submitter = e.submitter || null;
		JoinerySealed.session('mail', { reason: 'to make the key Fortress mail is sealed to' }).then(function () {
			opened = true;
			form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
		}, function (err) {
			// Nothing saved, the form stays as it was. A cancel needs no
			// word; anything else says why the save did not go.
			var msg = (err && err.message) ? err.message : '';
			if (msg === 'Unlock cancelled.') return;
			msg = 'Your mail vault did not open, so the domain was not saved. ' + msg;
			if (window.JoineryModal) { JoineryModal.alert(msg); } else { alert(msg); }
		}).then(function () { opening = false; });
	});
})();
}
if (CFG.receipt_title) {
// The one thing beyond the shared loop: the card's heading states the
// finished fact once sealing is done.
document.addEventListener('ceremony:done', function () {
	var card = document.getElementById('raise-receipt');
	var title = document.getElementById('receipt-title');
	if (card && title && card.dataset.titleDone) { title.textContent = card.dataset.titleDone; }
});
}
if (CFG.unseal_loop) {
(function () {
	var card = document.getElementById('lowering-receipt');
	if (!card || card.dataset.windowOpen !== '1') return;
	var remaining = parseInt(card.dataset.ownBacklog, 10) || 0;
	if (remaining <= 0) return;
	var unsealedTotal = 0;
	var dot = document.querySelector('#lowering-unseal-row .receipt-dot');
	var text = document.getElementById('lowering-unseal-text');
	var noscriptForm = document.getElementById('unsealing-continue');
	if (noscriptForm) noscriptForm.remove();
	function setDot(color) { if (dot) dot.style.background = color; }
	function finish() {
		setDot('#28a745');
		text.textContent = unsealedTotal > 0
			? unsealedTotal + ' earlier message' + (unsealedTotal === 1 ? '' : 's') + ' unsealed'
			: 'All earlier messages are readable';
		var btn = document.getElementById('lowering-action');
		if (btn) btn.classList.remove('d-none');
	}
	function updateOthers(n) {
		var row = document.getElementById('lowering-others-text');
		if (row && n > 0) {
			row.textContent = n + ' message' + (n === 1 ? '' : 's') + ' stay sealed until their readers next unlock';
		} else if (row && n === 0) {
			var li = document.getElementById('lowering-others-row');
			if (li) li.remove();
		}
	}
	function batch() {
		joineryApi.post('mailbox/unseal_batch', {
			domain_id: parseInt(card.dataset.domainId, 10)
		}).then(function (d) {
			var unsealed = parseInt(d.unsealed, 10) || 0;
			remaining = parseInt(d.own_remaining, 10) || 0;
			unsealedTotal += unsealed;
			updateOthers(parseInt(d.others_remaining, 10) || 0);
			if (d.locked) {
				setDot('#ffc107');
				text.textContent = 'Unlock your vault, then reload this page to continue unsealing — '
					+ remaining + ' remaining.';
				return;
			}
			if (remaining > 0 && unsealed === 0) {
				setDot('#dc3545');
				text.textContent = remaining + ' message' + (remaining === 1 ? '' : 's')
					+ ' could not be unsealed yet; they are tried again within the hour (the error log says why).';
				return;
			}
			if (remaining > 0) {
				text.textContent = 'Unsealing earlier messages — ' + remaining + ' remaining…';
				batch();
			} else {
				finish();
			}
		}).catch(function () {
			setDot('#dc3545');
			text.textContent = 'Unsealing paused — reload this page to resume.';
		});
	}
	batch();
})();
}
if (CFG.ceremony) {
(function () {
	var C = CFG.ceremony;
	var currentRank = C.current_rank | 0;
	var currentLevel = C.current_level;
	// Add-ons already in force: switching one on is what shows its rows.
	var currentRelay = !!C.current_relay;
	var currentSend = !!C.current_send;
	var ranks = { standard: 0, private: 1, fortress: 2 };
	// The add-on switches, as ProtectionLevelPicker names them.
	var relayName = C.relay_name;
	var sendName = C.send_name;
	var form = document.querySelector('form[name="domain_form"], #domain_form') ||
		(document.querySelector('input[name="ied_security_level"]') || {}).form;
	if (!form) return;
	var submit = form.querySelector('button[type="submit"], input[type="submit"]');
	function ticked(name) {
		var box = form.querySelector('input[type="checkbox"][name="' + name + '"]');
		return !!(box && box.checked);
	}
	// Each checklist box is placed next to what it belongs to and shown
	// only when something there needs attention.
	function place(box, anchor, show) {
		if (!box) return null;
		box.classList.add('d-none');
		if (!show) return null;
		if (anchor && box.parentElement !== anchor) anchor.appendChild(box);
		if (box.dataset.allGreen !== '1') box.classList.remove('d-none');
		return box;
	}
	function refresh() {
		var chosen = form.querySelector('input[name="ied_security_level"]:checked');
		var level = chosen ? chosen.value : 'standard';
		var raising = (ranks[level] || 0) > currentRank;
		var isPrivate = (level === 'private');
		var newRelay = isPrivate && ticked(relayName) && !currentRelay;
		var newSend = isPrivate && ticked(sendName) && !currentSend;
		var card = document.getElementById('ied_security_level_' + level + '_card');
		var active = [
			place(document.getElementById('protection-ceremony-private'), card,
				raising && level === 'private'),
			place(document.getElementById('protection-ceremony-fortress'), card,
				raising && level === 'fortress'),
			place(document.getElementById('protection-ceremony-relay'),
				document.getElementById(relayName + '_container'), newRelay),
			place(document.getElementById('protection-ceremony-send'),
				document.getElementById(sendName + '_container'), newSend),
		];
		if (submit) {
			submit.disabled = active.some(function (box) {
				return box && box.dataset.requiredOk === '0';
			});
		}
	}
	form.addEventListener('change', function (e) {
		if (e.target && (e.target.name === 'ied_security_level'
				|| e.target.name === relayName
				|| e.target.name === sendName)) refresh();
	});
	refresh();

	// A level change is a sensitive action. Run the passkey step-up
	// INLINE before the form leaves the page, so the submission is
	// never lost to the redirect ceremony. Always — a render-time
	// freshness snapshot goes stale while the form sits open.
	// Fallback (no passkey, or the ceremony fails): plain submit —
	// the server redirects through /profile/verify-stepup and target_level
	// preserves the choice.
	var stepupDone = false;
	var stepupInFlight = false;
	form.addEventListener('submit', function (e) {
		// Choosing Fortress opens the mail vault first: its listener holds
		// this submission and asks for it again once the vault is open,
		// and the step-up runs then, once.
		if (stepupDone || e.defaultPrevented) return;
		var chosen = form.querySelector('input[name="ied_security_level"]:checked');
		var level = chosen ? chosen.value : currentLevel;
		// An add-on change is as sensitive as a level change.
		var sealing = (level === 'private');
		var addonsChanged = (sealing && ticked(relayName)) !== currentRelay
			|| (sealing && ticked(sendName)) !== currentSend;
		if (level === currentLevel && !addonsChanged) return;
		if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) {
			// The helper is absent, so it cannot report this itself -
			// record the fallthrough (keepalive survives the native
			// submit's navigation) so a server-ceremony detour is
			// never invisible in the request log.
			try {
				var fallthroughHeaders = { 'Content-Type': 'application/json' };
				var fallthroughToken = ((document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '');
				if (fallthroughToken) fallthroughHeaders['X-Joinery-Csrf'] = fallthroughToken;
				fetch('/api/v1/action/passkey_client_report', {
					method: 'POST', headers: fallthroughHeaders, keepalive: true,
					body: JSON.stringify({
						context: 'stepup-fallthrough:' + location.pathname,
						error_name: 'NoHelper',
						error_message: window.JoineryPasskeys ? 'WebAuthn unsupported' : 'passkeys.js not loaded at submit',
						focus: document.hasFocus(),
						visibility: document.visibilityState,
						elapsed_ms: 0,
					}),
				}).catch(function () {});
			} catch (te) { /* never block the fallback submit */ }
			return;
		}
		e.preventDefault();
		// One ceremony at a time: a second submit (double click, or the
		// validation layer re-dispatching) must not start a parallel
		// WebAuthn request — the browser kills the first with
		// NotAllowedError.
		if (stepupInFlight) return;
		stepupInFlight = true;
		var btnLabel = submit ? submit.textContent : '';
		if (submit) { submit.disabled = true; submit.textContent = 'Confirm with your passkey…'; }
		var csrf = (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
		function api(url, body) {
			return fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
				body: JSON.stringify(body || {})
			}).then(async function (r) {
				var j = await r.json();
				if (!r.ok) throw new Error(j.error || 'Request failed.');
				return j;
			});
		}
		api('/api/v1/action/passkey_stepup_options').catch(function (err) {
			// The server could not even start a passkey ceremony (e.g.
			// no passkeys enrolled — TOTP-only admin): hand the whole
			// submission to the server-side redirect ceremony instead.
			err.useServerCeremony = true;
			throw err;
		}).then(function (opts) {
			return JoineryPasskeys.authenticate(opts.data.options);
		}).then(function (credential) {
			return api('/api/v1/action/passkey_stepup_verify', { credential: credential });
		}).then(function () {
			stepupDone = true;
			form.submit();
		}).catch(async function (err) {
			if (err && err.useServerCeremony) { form.submit(); return; }
			stepupInFlight = false;
			if (submit) { submit.disabled = false; submit.textContent = btnLabel; }
			var msg = ((err && err.name) ? err.name + ': ' : '') + ((err && err.message) ? err.message : 'Passkey confirmation failed.');
			// A refused passkey must never dead-end the save — offer the
			// authenticator-code ceremony as the alternate path.
			if (window.JoineryModal && JoineryModal.confirmAsync) {
				var useCode = await JoineryModal.confirmAsync(msg + ' You can try the passkey again, or confirm with your authenticator code instead.', { confirmLabel: 'Use authenticator code', cancelLabel: 'Try passkey again', confirmStyle: 'primary' });
				if (useCode) { stepupDone = true; form.submit(); }
			}
		});
	});
})();
}
})();
