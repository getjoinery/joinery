/**
 * Vault lock chip (docs/sealed_vault.md § The lock chip).
 *
 * The platform-wide "you're locked" idiom: every signed-in page for a user
 * with a set-up server-custody vault shows a padlock in a fixed place —
 * closed while the vault is locked (click runs the one-tap passkey unlock
 * ceremony right there), open while an unlock window is live (click opens a
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
 *
 * @version 1.0
 */
(function () {
	'use strict';

	if (window.JoineryVaultLock) { return; }

	var meta = document.querySelector('meta[name="joinery-vault"]');
	var state = meta && meta.getAttribute('content') === 'open' ? 'open' : 'locked';
	var idleMinutes = meta ? parseInt(meta.getAttribute('data-idle-minutes'), 10) || 30 : 30;
	var chip = null;
	var popover = null;
	var busy = false;

	var ICON_LOCKED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
	var ICON_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';

	function api(action, payload) {
		return window.joineryApi.post(action, payload || {});
	}

	// Run the passkey unlock ceremony; resolves true on success. This is THE
	// shared ceremony — consumer surfaces delegate here so every unlock updates
	// the chip and announces itself.
	async function unlock() {
		if (busy) { return false; }
		if (!window.JoineryPasskeys) {
			alert('Unlocking is unavailable on this page.');
			return false;
		}
		busy = true;
		if (chip) { chip.classList.add('jy-vault-lock--busy'); }
		try {
			var opt = await api('vault_unlock_options', {});
			if (!opt || !opt.options) { throw new Error('Could not start unlock.'); }
			var credential = (await JoineryPasskeys.derive(opt.options)).response;
			var res = await api('vault_unlock_passkey', { credential: credential });
			if (res && res.success === false) { throw new Error(res.message || 'Unlock failed.'); }
			setState('open');
			document.dispatchEvent(new CustomEvent('joinery:vault-unlocked'));
			return true;
		} catch (e) {
			alert(e.message || 'Could not unlock your vault.');
			return false;
		} finally {
			busy = false;
			if (chip) { chip.classList.remove('jy-vault-lock--busy'); }
		}
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

	function render() {
		if (!chip) { return; }
		chip.setAttribute('data-state', state);
		var btn = chip.querySelector('.jy-vault-lock-btn');
		btn.innerHTML = state === 'open' ? ICON_OPEN : ICON_LOCKED;
		btn.setAttribute('aria-label', state === 'open'
			? 'Vault unlocked — sealed content is readable. Click for options.'
			: 'Vault locked — click to unlock');
		btn.title = state === 'open' ? 'Vault unlocked' : 'Unlock your vault';
		if (state === 'locked') { hidePopover(); }
	}

	function hidePopover() {
		if (popover) { popover.hidden = true; }
	}

	function togglePopover() {
		if (!popover) { return; }
		popover.hidden = !popover.hidden;
	}

	function buildChip() {
		chip = document.createElement('div');
		chip.className = 'jy-vault-lock';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'jy-vault-lock-btn';
		btn.addEventListener('click', function () {
			if (state === 'locked') { unlock(); } else { togglePopover(); }
		});
		chip.appendChild(btn);

		popover = document.createElement('div');
		popover.className = 'jy-vault-lock-pop';
		popover.hidden = true;

		var title = document.createElement('div');
		title.className = 'jy-vault-lock-pop-title';
		title.textContent = 'Vault unlocked';
		popover.appendChild(title);

		var body = document.createElement('div');
		body.className = 'jy-vault-lock-pop-body';
		body.textContent = 'Sealed content is readable while you’re here. It locks on its own after '
			+ idleMinutes + ' minutes away.';
		popover.appendChild(body);

		var lockBtn = document.createElement('button');
		lockBtn.type = 'button';
		lockBtn.className = 'jy-vault-lock-pop-btn';
		lockBtn.textContent = 'Lock now';
		lockBtn.addEventListener('click', function () {
			lockBtn.disabled = true;
			lock().finally(function () { lockBtn.disabled = false; });
		});
		popover.appendChild(lockBtn);
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
	// ended in another session).
	document.addEventListener('joinery:vault-unlocked', function () { setState('open'); });
	document.addEventListener('joinery:vault-locked', function () { setState('locked'); });

	window.JoineryVaultLock = {
		unlock: unlock,
		lock: lock,
		state: function () { return state; }
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', buildChip);
	} else {
		buildChip();
	}
})();
