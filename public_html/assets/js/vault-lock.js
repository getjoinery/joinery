/**
 * Vault lock chip (docs/sealed_vault.md § The lock chip).
 *
 * The platform-wide "you're locked" idiom: every signed-in page for a user
 * with a set-up server-custody vault shows a padlock in a fixed place —
 * closed while the vault is locked (click runs the unlock ceremony right
 * there: a passkey, the bypass phrase or a recovery code, whichever it has), open while an unlock window is live (click opens a
 * small popover with a Lock now control). PublicPageBase includes this script
 * only when the user's vault exists and emits
 * <meta name="joinery-vault" content="locked|open" data-idle-minutes="30">.
 *
 * Mounting: the chip renders into the page's [data-vault-lock-slot] element
 * (the core page classes emit one in their header icon cluster); a theme
 * without a slot gets a fixed-position chip in the bottom-right corner
 * instead, so the idiom holds on every theme with zero theme work.
 *
 * Events (the cross-surface contract):
 *  - 'joinery:vault-unlocked' — dispatched on document after any successful
 *    unlock ceremony. The chip flips open; vault-presence starts beating; any
 *    consumer surface (mail reader, etc.) may refresh sealed placeholders.
 *  - 'joinery:vault-locked' — dispatched after any explicit lock, and by
 *    vault-presence when a heartbeat learns the window ended elsewhere. The
 *    chip flips closed; consumer surfaces re-seal their content.
 *
 * Ceremony surface for consumers: JoineryVaultLock.unlock() resolves true on
 * success (alerting on failure), JoineryVaultLock.lock() ends the window —
 * both keep the chip and events in sync, so page code should always go
 * through them rather than calling the vault actions directly.
 * JoineryVaultLock.collectUnlocker(purpose) gathers the fresh unlocker every
 * enrolment must present in its own request (adding a passkey, a bypass
 * phrase, new recovery codes): a wrapping is produced only in the request
 * that proved it may be (specs/unseal_daemon.md B1).
 *
 * One chip for every vault: it reads open while the server window OR any
 * vault this browser holds (JoinerySealed.openScopes()) is open, and its
 * popover lists each open vault with its own Lock now. The meta's
 * data-server-vault="0" means this person has no server vault: the chip then
 * shows only while a browser-held vault is open. It follows
 * 'joinery:vault-scope-unlocked' / 'joinery:vault-scope-locked' for those.
 *
 * @version 1.4 - unlock() offers every method the vault has, not only a passkey
 * @version 1.3 - one chip for the server window and browser-held vaults
 * @version 1.2 - collectUnlocker(): the shared "confirm it's you" step for enrolments
 * @version 1.1
 */
(function () {
	'use strict';

	if (window.JoineryVaultLock) { return; }

	var meta = document.querySelector('meta[name="joinery-vault"]');
	var state = meta && meta.getAttribute('content') === 'open' ? 'open' : 'locked';
	var idleMinutes = meta ? parseInt(meta.getAttribute('data-idle-minutes'), 10) || 30 : 30;
	var serverVault = !meta || meta.getAttribute('data-server-vault') !== '0';
	var serverLabel = (meta && meta.getAttribute('data-server-label')) || 'Mail & messages vault';
	var chip = null;
	var popover = null;
	var busy = false;

	var ICON_LOCKED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
	var ICON_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';

	function api(action, payload) {
		return window.joineryApi.post(action, payload || {});
	}

	// Run the unlock ceremony; resolves true on success. This is THE shared
	// ceremony — consumer surfaces delegate here so every unlock updates the
	// chip and announces itself. It offers what the vault has (a passkey, the
	// bypass phrase, a recovery code), the same choices collectUnlocker() does:
	// a vault with no working passkey still opens from any page.
	async function unlock() {
		if (busy) { return false; }
		if (!window.JoineryModal) {
			alert('Unlocking is unavailable on this page.');
			return false;
		}
		busy = true;
		if (chip) { chip.classList.add('jy-vault-lock--busy'); }
		try {
			var status = await api('vault_status', {});
			if (!status || !status.set_up) { throw new Error('Set up your vault first, on your security page.'); }
			var choices = [];
			if (status.passkey_wrapping_count > 0 && window.JoineryPasskeys) { choices.push('passkey'); }
			if (status.has_passphrase) { choices.push('passphrase'); }
			if (status.unused_recovery_code_count > 0) { choices.push('code'); }
			if (!choices.length) {
				throw new Error('Nothing can unlock your vault here: it has no working passkey, bypass phrase or recovery code.');
			}
			var method = choices.length === 1 ? choices[0] : await chooseUnlocker('to unlock your vault', choices);
			if (!method) { return false; }
			var res;
			if (method === 'passkey') {
				var opt = await api('vault_unlock_options', {});
				if (!opt || !opt.options) { throw new Error('Could not start unlock.'); }
				var credential = (await JoineryPasskeys.derive(opt.options)).response;
				res = await api('vault_unlock_passkey', { credential: credential });
			} else if (method === 'passphrase') {
				var phrase = await JoineryModal.promptAsync('Enter your bypass phrase:',
					{ inputType: 'password', confirmLabel: 'Unlock', confirmStyle: 'primary' });
				if (!phrase) { return false; }
				res = await api('vault_unlock_passphrase', { passphrase: phrase });
			} else {
				var code = await JoineryModal.promptAsync('Enter a recovery code. The code is used up by this:',
					{ confirmLabel: 'Unlock', confirmStyle: 'primary' });
				if (!code) { return false; }
				res = await api('vault_unlock_recovery', { code: code });
			}
			// A knowledge factor on an account that has a second factor needs a
			// fresh confirmation first: go through the step-up page and come back.
			if (res && res.second_factor_required) {
				window.location = '/verify-stepup?return=' + encodeURIComponent(window.location.pathname + window.location.search);
				return false;
			}
			if (res && res.success === false) { throw new Error(res.message || 'Unlock failed.'); }
			setState('open');
			document.dispatchEvent(new CustomEvent('joinery:vault-unlocked'));
			if (res && res.regenerate_recommended) {
				JoineryModal.alert('Unlocked. Fewer than 3 unused recovery codes remain — make a new set on your security page.');
			}
			return true;
		} catch (e) {
			if (e && e.status === 401) {
				// The session is gone (idle past expiry, no remember-me) — the
				// transport's stale-token retry already ran, so this denial is
				// real. Unlocking needs a signed-in session; go get one.
				window.location.href = '/login';
				return false;
			}
			JoineryModal.alert(e.message || 'Could not unlock your vault.');
			return false;
		} finally {
			busy = false;
			if (chip) { chip.classList.remove('jy-vault-lock--busy'); }
		}
	}

	// A fresh unlocker for an enrolment. Resolves {credential} (a vault-kek
	// assertion from a passkey that already unlocks the vault), {passphrase}
	// or {code}, which the caller sends as `unlocker` beside its own request —
	// or null when the person backed out of the prompt. `purpose` reads in the
	// prompts: "to let this passkey open your vault". Which methods are
	// offered follows what the vault actually has enrolled; with exactly one
	// there is nothing to choose and the prompt for it opens directly.
	async function collectUnlocker(purpose) {
		if (!window.JoineryModal) { throw new Error('Confirming is unavailable on this page.'); }
		var status = await api('vault_status', {});
		if (!status || !status.set_up) { throw new Error('Set up your vault first.'); }
		var choices = [];
		if (status.passkey_wrapping_count > 0 && window.JoineryPasskeys) { choices.push('passkey'); }
		if (status.has_passphrase) { choices.push('passphrase'); }
		if (status.unused_recovery_code_count > 0) { choices.push('code'); }
		if (!choices.length) {
			throw new Error('Nothing can confirm this: your vault has no working passkey, bypass phrase or recovery code.');
		}
		var method = choices.length === 1 ? choices[0] : await chooseUnlocker(purpose, choices);
		if (!method) { return null; }
		if (method === 'passkey') {
			var opt = await api('vault_unlock_options', {});
			if (!opt || !opt.options) { throw new Error('Could not start the passkey prompt.'); }
			var credential = (await JoineryPasskeys.derive(opt.options)).response;
			return { credential: credential };
		}
		if (method === 'passphrase') {
			var phrase = await JoineryModal.promptAsync('Enter your bypass phrase ' + purpose + ':',
				{ inputType: 'password', confirmLabel: 'Continue', confirmStyle: 'primary' });
			return phrase ? { passphrase: phrase } : null;
		}
		var code = await JoineryModal.promptAsync('Enter a recovery code ' + purpose + '. The code is used up by this:',
			{ confirmLabel: 'Continue', confirmStyle: 'primary' });
		return code ? { code: code } : null;
	}

	// One button per method the vault has; resolves the method picked, or null.
	function chooseUnlocker(purpose, choices) {
		return new Promise(function (resolve) {
			var labels = { passkey: 'Use a passkey', passphrase: 'Use my bypass phrase', code: 'Use a recovery code' };
			var picked = null;
			var buttons = choices.map(function (c) {
				return { label: labels[c], style: c === 'passkey' ? 'primary' : 'secondary', onClick: function () { picked = c; } };
			});
			buttons.push({ label: 'Cancel', style: 'secondary' });
			var text = document.createElement('p');
			text.textContent = 'Confirm it’s you ' + purpose + '.';
			var handle = JoineryModal.open(text, { buttons: buttons });
			handle.dialog.addEventListener('close', function () { resolve(picked); }, { once: true });
		});
	}

	// End the unlock window for this session and announce it.
	async function lock() {
		try { await api('vault_lock', {}); } catch (e) { /* window may already be gone */ }
		setState('locked');
		hidePopover();
		document.dispatchEvent(new CustomEvent('joinery:vault-locked'));
	}

	function setState(next) {
		if (state === next) { return; }
		state = next;
		render();
	}

	// Vaults this browser holds open right now (none on a page without JoinerySealed).
	function clientOpen() {
		return window.JoinerySealed ? JoinerySealed.openScopes() : [];
	}
	function serverOpen() { return serverVault && state === 'open'; }
	function anyOpen() { return serverOpen() || clientOpen().length > 0; }

	function render() {
		if (!chip) { return; }
		var open = anyOpen();
		chip.hidden = !serverVault && !open;
		chip.setAttribute('data-state', open ? 'open' : 'locked');
		var btn = chip.querySelector('.jy-vault-lock-btn');
		btn.innerHTML = open ? ICON_OPEN : ICON_LOCKED;
		btn.setAttribute('aria-label', open
			? 'Vault unlocked — sealed content is readable. Click for options.'
			: 'Vault locked — click to unlock');
		btn.title = open ? 'Vault unlocked' : 'Unlock your vault';
		if (!open) { hidePopover(); }
		else if (popover && !popover.hidden) { fillPopover(); }
	}

	function hidePopover() {
		if (popover) { popover.hidden = true; }
	}

	function togglePopover() {
		if (!popover) { return; }
		if (popover.hidden) { fillPopover(); }
		popover.hidden = !popover.hidden;
	}

	// One line per vault: its name and what can be done with it now.
	function popLine(name, note, actionLabel, action) {
		var row = document.createElement('div');
		row.className = 'jy-vault-lock-pop-row';
		var text = document.createElement('div');
		text.className = 'jy-vault-lock-pop-name';
		text.textContent = name;
		if (note) {
			var n = document.createElement('span');
			n.className = 'jy-vault-lock-pop-note';
			n.textContent = ' ' + note;
			text.appendChild(n);
		}
		row.appendChild(text);
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'jy-vault-lock-pop-btn';
		b.textContent = actionLabel;
		b.addEventListener('click', function () {
			b.disabled = true;
			Promise.resolve(action()).finally(function () { b.disabled = false; });
		});
		row.appendChild(b);
		return row;
	}

	function fillPopover() {
		popover.innerHTML = '';
		var title = document.createElement('div');
		title.className = 'jy-vault-lock-pop-title';
		title.textContent = 'Unlocked vaults';
		popover.appendChild(title);
		if (serverVault) {
			popover.appendChild(serverOpen()
				? popLine(serverLabel, null, 'Lock now', lock)
				: popLine(serverLabel, '(locked)', 'Unlock', unlock));
		}
		clientOpen().forEach(function (scope) {
			popover.appendChild(popLine(JoinerySealed.labelFor(scope), null, 'Lock now', function () {
				JoinerySealed.lock(scope);
			}));
		});
		var body = document.createElement('div');
		body.className = 'jy-vault-lock-pop-body';
		body.textContent = 'Sealed content is readable while you’re here. Each vault locks on its own after a while away'
			+ (serverVault ? ' (the server’s after ' + idleMinutes + ' minutes)' : '') + '.';
		popover.appendChild(body);
	}

	function buildChip() {
		chip = document.createElement('div');
		chip.className = 'jy-vault-lock';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'jy-vault-lock-btn';
		btn.addEventListener('click', function () {
			if (anyOpen()) { togglePopover(); }
			else if (serverVault) { unlock(); }
		});
		chip.appendChild(btn);

		popover = document.createElement('div');
		popover.className = 'jy-vault-lock-pop';
		popover.hidden = true;
		chip.appendChild(popover);

		// Click-away closes the popover.
		document.addEventListener('click', function (e) {
			if (popover && !popover.hidden && !chip.contains(e.target)) { hidePopover(); }
		});

		var slot = document.querySelector('[data-vault-lock-slot]');
		if (slot) {
			slot.appendChild(chip);
		} else {
			chip.classList.add('jy-vault-lock--floating');
			document.body.appendChild(chip);
		}
		render();
	}

	// Stay in sync with ceremonies and locks that happen anywhere else on the
	// page (a consumer's own unlock banner, a heartbeat learning the window
	// ended in another session, a browser-held vault opening or locking).
	document.addEventListener('joinery:vault-unlocked', function () { setState('open'); });
	document.addEventListener('joinery:vault-locked', function () { setState('locked'); });
	document.addEventListener('joinery:vault-scope-unlocked', render);
	document.addEventListener('joinery:vault-scope-locked', render);

	window.JoineryVaultLock = {
		unlock: unlock,
		lock: lock,
		collectUnlocker: collectUnlocker,
		state: function () { return state; }
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', buildChip);
	} else {
		buildChip();
	}
})();
