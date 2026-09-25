/**
 * Vault lock chip (docs/sealed_vault.md § The lock chip).
 *
 * The platform-wide "you're locked" idiom: every signed-in page for a user
 * with a set-up server-custody vault shows a padlock in a fixed place —
 * closed while the vault is locked (click runs the unlock ceremony right
 * there: a passkey, the passphrase or a recovery code, whichever it has), open while an unlock window is live (click opens a
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
 * One chip, one vault (specs/one_vault_experience.md § R3): it reads open
 * only while the account vault's window is open (when there is one) and every
 * browser-held vault that should be — the root (data-root-vault="1") and each
 * one the page reads (JoinerySealed.want). Its menu is one line: Unlock runs
 * the one ceremony, Lock now locks everything. The meta's data-server-vault="0"
 * means this person has no account vault: the chip then shows only while a
 * browser-held vault is open. It follows 'joinery:vault-scope-unlocked' /
 * 'joinery:vault-scope-locked' for those.
 *
 * @version 2.0 - the one vault: one unlock opens the account vault, the root and every browser-held vault;
 *   one line in the menu; Lock now locks everything; the server gets KEKs, never a code or a phrase
 * @version 1.6 - the chip reads open only when every vault the page needs is open
 *   (JoinerySealed.want); its menu offers Unlock for each one still shut
 * @version 1.5 - a bypass phrase or recovery code opens the vault on its own, with no step-up page
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
	var rootVault = !!meta && meta.getAttribute('data-root-vault') === '1';
	var chip = null;
	var popover = null;
	var busy = false;

	var ICON_LOCKED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
	var ICON_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';

	function api(action, payload) {
		return window.joineryApi.post(action, payload || {});
	}

	// What the vault can be opened with, from vault_status.
	function choicesFor(status) {
		var choices = [];
		if (status.passkey_wrapping_count > 0 && window.JoineryPasskeys) { choices.push('passkey'); }
		if (status.has_passphrase) { choices.push('passphrase'); }
		if (status.unused_recovery_code_count > 0) { choices.push('code'); }
		return choices;
	}

	function keyringReady() {
		return !!(window.VaultKeyring && VaultKeyring.passkeyUnlock && window.VaultCrypto && window.JoinerySealed);
	}

	// Run the one unlock (specs/one_vault_experience.md § R3); resolves true on
	// success. THE shared ceremony: one touch opens the account vault, the root
	// vault from the same touch, and through the root every browser-held vault
	// — consumer surfaces delegate here so every unlock updates the chip and
	// announces itself. It offers what the vault has (a passkey, the
	// passphrase, a recovery code), the same choices collectUnlocker() does.
	// The server is sent the account half of a KEK, never a code or a phrase.
	async function unlock(opts) {
		opts = opts || {};
		if (busy) { return false; }
		if (!window.JoineryModal) {
			alert('Unlocking is unavailable on this page.');
			return false;
		}
		if (!keyringReady()) {
			JoineryModal.alert('This page could not load what unlocking needs. Reload it and try again.');
			return false;
		}
		busy = true;
		if (chip) { chip.classList.add('jy-vault-lock--busy'); }
		try {
			var status = await api('vault_status', {});
			if (!status || !status.set_up) { throw new Error('Set up your vault first, on your security page.'); }
			var choices = choicesFor(status);
			if (!choices.length) {
				throw new Error('Nothing can unlock your vault here: it has no working passkey, passphrase or recovery code.');
			}
			var method = choices.length === 1 ? choices[0]
				: await chooseUnlocker(opts.reason ? opts.reason : 'to unlock your vault', choices);
			if (!method) { return false; }
			var res, root = null, heal = null, codes = null;
			if (method === 'passkey') {
				var r = await VaultKeyring.passkeyUnlock(status);
				res = r.res;
				root = r.rootSession;
				codes = r.codes;
				if (!root && status.root && status.root.set_up) {
					if (r.second) {
						root = await VaultKeyring.openRoot(status.root, await VaultCrypto.kekFromPrf(r.second), 'passkey', r.credentialId);
						// This passkey opens the account vault but not yet the root:
						// once the root is open another way, it learns this one.
						if (!root) { heal = { credentialId: r.credentialId, second: r.second }; }
					} else {
						// An authenticator that returns one output: a second touch for the root.
						try {
							var d = await VaultKeyring.rootPasskeyKek();
							root = await VaultKeyring.openRoot(status.root, d.kek, 'passkey', d.credentialId);
						} catch (e) { root = null; }
					}
				}
			} else if (method === 'passphrase') {
				var phrase = await JoineryModal.promptAsync('Enter your passphrase:',
					{ inputType: 'password', confirmLabel: 'Unlock', confirmStyle: 'primary' });
				if (!phrase) { return false; }
				var p = await VaultKeyring.passphraseUnlock(status, phrase);
				res = p.res;
				root = p.rootSession;
			} else {
				var code = await JoineryModal.promptAsync('Enter a recovery code. The code is used up by this:',
					{ confirmLabel: 'Unlock', confirmStyle: 'primary' });
				if (!code) { return false; }
				var c = await VaultKeyring.codeUnlock(status, code);
				res = c.res;
				root = c.rootSession;
			}
			if (res && res.success === false) { throw new Error(res.message || 'Unlock failed.'); }
			setState('open');
			document.dispatchEvent(new CustomEvent('joinery:vault-unlocked'));

			if (!root && heal) { root = await openRootAnotherWay(status); }
			if (root) {
				JoinerySealed.adopt(VaultKeyring.ROOT, root);
				if (heal) {
					VaultKeyring.addRootPasskey(root, heal.credentialId, heal.second).catch(function () { /* asks again next time */ });
				}
				await JoinerySealed.openAllThroughRoot(status.content || {});
			}
			render();
			if (codes) {
				await VaultKeyring.showRecoveryCodes(VaultKeyring.ROOT, 'vault', codes,
					'Your vault has new recovery codes: one set now opens all of it, including end-to-end content. '
					+ 'Your old codes no longer work. This is the only time these are shown. Download them, or copy them somewhere safe and type the last one below.');
			}
			if (res && res.passphrase_removed) {
				JoineryModal.alert('Your passphrase was removed: your passkey can hold your key, and your recovery codes cover a lost device.');
			} else if (res && res.regenerate_recommended) {
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

	// The passkey opened the account vault but not the root (it was enrolled
	// before the root existed): offer the passkey the root was set up with.
	async function openRootAnotherWay(status) {
		var go = await new Promise(function (resolve) {
			var picked = false;
			var text = document.createElement('p');
			text.textContent = 'Your vault is open, but this passkey does not open its end-to-end part yet. '
				+ 'Use the passkey you set your vault up with, once, and this one will open everything from then on.';
			var handle = JoineryModal.open(text, { buttons: [
				{ label: 'Use another passkey', style: 'primary', onClick: function () { picked = true; } },
				{ label: 'Not now', style: 'secondary' },
			] });
			handle.dialog.addEventListener('close', function () { resolve(picked); }, { once: true });
		});
		if (!go) { return null; }
		try {
			var d = await VaultKeyring.rootPasskeyKek();
			var root = await VaultKeyring.openRoot(status.root, d.kek, 'passkey', d.credentialId);
			if (!root) { JoineryModal.alert('That passkey does not open it either.'); }
			return root;
		} catch (e) {
			return null;
		}
	}

	// A fresh unlocker for an enrolment. Resolves {credential} (a vault-kek
	// assertion from a passkey that already unlocks the vault), {passphrase_kek}
	// or {code_kek} (the account half, derived here: the phrase and the code
	// never leave the browser), which the caller sends as `unlocker` beside its
	// own request — or null when the person backed out of the prompt. The root
	// vault opens on the way where it can (JoinerySealed.rootSession()), for an
	// enrolment that also changes the root. `purpose` reads in the prompts: "to
	// let this passkey open your vault".
	async function collectUnlocker(purpose) {
		if (!window.JoineryModal) { throw new Error('Confirming is unavailable on this page.'); }
		if (!keyringReady()) { throw new Error('This page could not load what confirming needs. Reload it and try again.'); }
		var status = await api('vault_status', {});
		if (!status || !status.set_up) { throw new Error('Set up your vault first.'); }
		var choices = choicesFor(status);
		if (!choices.length) {
			throw new Error('Nothing can confirm this: your vault has no working passkey, passphrase or recovery code.');
		}
		var method = choices.length === 1 ? choices[0] : await chooseUnlocker(purpose, choices);
		if (!method) { return null; }
		var value = null;
		if (method === 'passphrase') {
			value = await JoineryModal.promptAsync('Enter your passphrase ' + purpose + ':',
				{ inputType: 'password', confirmLabel: 'Continue', confirmStyle: 'primary' });
			if (!value) { return null; }
		} else if (method === 'code') {
			value = await JoineryModal.promptAsync('Enter a recovery code ' + purpose + '. The code is used up by this:',
				{ confirmLabel: 'Continue', confirmStyle: 'primary' });
			if (!value) { return null; }
		}
		var got = await VaultKeyring.enrolmentUnlocker(status, method, value);
		if (got.rootSession) { JoinerySealed.adopt(VaultKeyring.ROOT, got.rootSession); }
		return got.unlocker;
	}

	// One button per method the vault has; resolves the method picked, or null.
	function chooseUnlocker(purpose, choices) {
		return new Promise(function (resolve) {
			var labels = { passkey: 'Use a passkey', passphrase: 'Use my passphrase', code: 'Use a recovery code' };
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

	// Lock everything (§ R3): the account vault's window and every vault this
	// browser holds, and announce it.
	async function lock() {
		if (window.JoinerySealed) { JoinerySealed.lockAll(); }
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
	// Browser-held vaults that are shut but should be open: the ones this page
	// reads (JoinerySealed.want) and the root, when the person has one.
	function clientShut() {
		if (!window.JoinerySealed || !JoinerySealed.wantedScopes) { return []; }
		var want = JoinerySealed.wantedScopes().slice();
		if (rootVault && want.indexOf('root') < 0) { want.push('root'); }
		return want.filter(function (s) { return !JoinerySealed.isOpen(s); });
	}
	function serverOpen() { return serverVault && state === 'open'; }
	function anyOpen() { return serverOpen() || clientOpen().length > 0; }
	// Open means everything this page reads is open: the server window (when
	// there is a server vault) and every browser-held vault the page named.
	function allOpen() { return anyOpen() && (!serverVault || serverOpen()) && clientShut().length === 0; }
	// Something to list in the menu: an open vault to lock, or a named one to open.
	function hasMenu() { return anyOpen() || clientShut().length > 0; }

	function render() {
		if (!chip) { return; }
		var open = allOpen();
		var partly = !open && anyOpen();
		chip.hidden = !serverVault && !hasMenu();
		chip.setAttribute('data-state', open ? 'open' : 'locked');
		var btn = chip.querySelector('.jy-vault-lock-btn');
		btn.innerHTML = open ? ICON_OPEN : ICON_LOCKED;
		btn.setAttribute('aria-label', open
			? 'Vault unlocked — sealed content is readable. Click for options.'
			: (partly ? 'Vault partly locked — click to unlock the rest' : 'Vault locked — click to unlock'));
		btn.title = open ? 'Vault unlocked' : (partly ? 'Vault partly locked' : 'Unlock your vault');
		if (!hasMenu()) { hidePopover(); }
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

	// One line for the one vault (§ R3): open or locked, and the one action.
	function fillPopover() {
		popover.innerHTML = '';
		var title = document.createElement('div');
		title.className = 'jy-vault-lock-pop-title';
		title.textContent = 'Your vault';
		popover.appendChild(title);
		var open = allOpen();
		popover.appendChild(open
			? popLine('Vault', '(unlocked)', 'Lock now', lock)
			: popLine('Vault', anyOpen() ? '(partly locked)' : '(locked)', 'Unlock', function () {
				hidePopover();
				return unlock();
			}));
		var body = document.createElement('div');
		body.className = 'jy-vault-lock-pop-body';
		body.textContent = open
			? 'Everything sealed is readable while you’re here. It all locks together after a while away, or when you press Lock now.'
			: 'One unlock opens all of it.';
		popover.appendChild(body);
	}

	function buildChip() {
		chip = document.createElement('div');
		chip.className = 'jy-vault-lock';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'jy-vault-lock-btn';
		btn.addEventListener('click', function () {
			// Locked goes straight to the one unlock; open (or partly) shows the
			// menu with Lock now.
			if (anyOpen()) { togglePopover(); }
			else if (serverVault) { unlock(); }
			else if (hasMenu()) { togglePopover(); }
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
	// The account vault's window ending anywhere (Lock now in another tab, its
	// idle lock) ends the browser-held ones here too: they lock together.
	document.addEventListener('joinery:vault-locked', function () {
		if (window.JoinerySealed) { JoinerySealed.lockAll(); }
		setState('locked');
	});
	document.addEventListener('joinery:vault-scope-unlocked', render);
	document.addEventListener('joinery:vault-scope-locked', render);
	document.addEventListener('joinery:vault-scope-wanted', render);

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
