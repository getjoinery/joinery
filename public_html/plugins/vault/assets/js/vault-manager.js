/**
 * vault-manager.js - the password manager UI at /profile/vault.
 *
 * The consumer half of the client-custody Sealed Vault: it drives the shared
 * core modules (VaultCrypto, VaultKeyring) to unlock the vault identity, then
 * manages its own store DEK and encrypted entries. Every plaintext lives only
 * in this tab's memory; a lock (idle, manual, or closing the tab) discards it.
 *
 * @version 1.0
 */
(function () {
	'use strict';

	var app = document.getElementById('jy-vault-app');
	if (!app) return;
	var CONFIG = JSON.parse(app.getAttribute('data-config') || '{}');
	var SCOPE = CONFIG.scope || 'passwords';

	// ---- in-memory session state (all discarded on lock) ----------------------
	var session = null;      // VaultKeyring unlocked session (holds the secret key)
	var dekKey = null;       // non-extractable AES-GCM CryptoKey for entry content
	var entries = [];        // [{ id, record }] decrypted in memory
	var undecryptableCount = 0;   // stored blobs the current key could not open
	var trashMode = false;   // list pane showing trash instead of live entries
	var trashEntries = [];   // decrypted trashed entries (only while in trash mode)
	var trashUndecryptable = 0;
	var selectedId = null;
	var idleTimer = null;
	var lastRecoveryCode = null;
	var recoveryProven = false;
	var clipboardTimer = null;

	var $ = function (id) { return document.getElementById(id); };
	function showSection(id) {
		['jy-vault-loading', 'jy-vault-unsupported', 'jy-vault-ceremony', 'jy-vault-unlock', 'jy-vault-manager']
			.forEach(function (s) { var el = $(s); if (el) el.hidden = (s !== id); });
	}
	function setError(id, msg) {
		var el = $(id);
		if (!el) return;
		if (msg) { el.textContent = msg; el.hidden = false; } else { el.textContent = ''; el.hidden = true; }
	}
	function toast(msg) {
		var t = $('jy-vault-toast');
		if (!t) return;
		t.textContent = msg; t.hidden = false; t.classList.add('is-visible');
		setTimeout(function () { t.classList.remove('is-visible'); setTimeout(function () { t.hidden = true; }, 250); }, 2000);
	}

	// ==========================================================================
	// Boot
	// ==========================================================================
	async function boot() {
		if (!(await VaultKeyring.isSupported())) { showSection('jy-vault-unsupported'); return; }
		var st;
		try { st = await VaultKeyring.status(SCOPE); }
		catch (e) { showSection('jy-vault-unsupported'); return; }

		if (!st.set_up) { startCeremony(); return; }
		startUnlock(st);
	}

	// ==========================================================================
	// First-run ceremony
	// ==========================================================================
	function ceremonyStep(step) {
		document.querySelectorAll('#jy-vault-ceremony .jy-vault-step').forEach(function (el) {
			el.hidden = (el.getAttribute('data-step') !== step);
		});
		document.querySelectorAll('#jy-vault-ceremony .jy-vault-steps li').forEach(function (li) {
			li.classList.toggle('is-active', li.getAttribute('data-step') === step);
		});
	}

	function startCeremony() {
		showSection('jy-vault-ceremony');
		ceremonyStep('method');

		$('jy-vault-setup-passphrase-toggle').addEventListener('click', function () {
			$('jy-vault-setup-passphrase-fields').hidden = false;
			this.hidden = true;
			$('jy-vault-setup-passkey').hidden = !CONFIG.passkeysEnabled ? true : false;
			$('jy-vault-setup-passphrase').hidden = false;
		});
		if (!CONFIG.passkeysEnabled) {
			// No passkeys on this instance: passphrase is the only primary unlocker.
			$('jy-vault-setup-passkey').hidden = true;
			$('jy-vault-setup-passphrase-fields').hidden = false;
			$('jy-vault-setup-passphrase-toggle').hidden = true;
			$('jy-vault-setup-passphrase').hidden = false;
		}

		$('jy-vault-setup-passkey').addEventListener('click', function () { doSetup(true); });
		$('jy-vault-setup-passphrase').addEventListener('click', function () { doSetup(false); });

		$('jy-vault-recovery-proof').addEventListener('input', checkRecoveryProof);
		$('jy-vault-download-recovery').addEventListener('click', downloadRecovery);
		$('jy-vault-recovery-finish').addEventListener('click', function () {
			// The ceremony is over: no plaintext recovery code survives past it.
			app._recoveryCodes = null;
			lastRecoveryCode = null;
			$('jy-vault-recovery-codes').innerHTML = '';
			$('jy-vault-recovery-proof').value = '';
			ceremonyStep('done');
		});
		$('jy-vault-ceremony-add').addEventListener('click', function () { enterManager(); openEditor(null); });
	}

	function readOptionalPassphrase(errId) {
		var p = $('setup_passphrase').value || '';
		var c = $('setup_passphrase_confirm').value || '';
		if (p === '' && c === '') return '';
		if (p.length < 10) { setError(errId, 'Your passphrase must be at least 10 characters.'); return false; }
		if (p !== c) { setError(errId, 'The passphrases don\'t match.'); return false; }
		return p;
	}

	async function doSetup(usePasskey) {
		setError('jy-vault-setup-error', '');
		if (!$('ack_loss').checked) { setError('jy-vault-setup-error', 'Please acknowledge the recovery warning to continue.'); return; }

		var passphrase = readOptionalPassphrase('jy-vault-setup-error');
		if (passphrase === false) return;
		if (!usePasskey && passphrase === '') { setError('jy-vault-setup-error', 'Enter a passphrase to continue.'); return; }

		var opts = { acknowledged: true, passphrase: passphrase || null };
		var btn = usePasskey ? $('jy-vault-setup-passkey') : $('jy-vault-setup-passphrase');
		btn.disabled = true;
		try {
			if (usePasskey) {
				opts.passkey = await VaultKeyring.derivePasskeyKek(SCOPE);
			}
			var result = await VaultKeyring.setup(SCOPE, opts);
			session = result.session;
			await initStoreDek(session);
			showRecovery(result.recoveryCodes);
		} catch (e) {
			setError('jy-vault-setup-error', friendly(e, usePasskey));
		} finally {
			btn.disabled = false;
		}
	}

	function friendly(e, usePasskey) {
		var msg = (e && e.message) || String(e);
		if (usePasskey && /PRF|derived secret|PRF-capable/i.test(msg)) {
			return 'This device\'s passkey can\'t derive an encryption key. Add a passkey that supports it, or use a passphrase instead.';
		}
		return msg;
	}

	function showRecovery(codes) {
		lastRecoveryCode = codes[codes.length - 1];
		recoveryProven = false;
		var box = $('jy-vault-recovery-codes');
		box.innerHTML = '';
		codes.forEach(function (c) {
			var d = document.createElement('div');
			d.className = 'jy-vault-recovery-code';
			d.textContent = c;
			box.appendChild(d);
		});
		$('jy-vault-recovery-proof').value = '';
		$('jy-vault-recovery-finish').disabled = true;
		app._recoveryCodes = codes;
		ceremonyStep('recovery');
	}

	function normalizeCode(s) { return String(s).toUpperCase().replace(/O/g, '0').replace(/[IL]/g, '1').replace(/[^A-Z0-9]/g, ''); }
	function checkRecoveryProof() {
		var typed = normalizeCode($('jy-vault-recovery-proof').value);
		var target = normalizeCode(lastRecoveryCode || '');
		recoveryProven = recoveryProven || (typed.length > 0 && typed === target);
		$('jy-vault-recovery-finish').disabled = !recoveryProven;
		setError('jy-vault-recovery-error', '');
	}
	function downloadRecovery() {
		var text = 'Joinery password vault - recovery keys\n' +
			'Keep these somewhere safe and private. Any one of them can unlock your vault if you lose your passkey and passphrase.\n\n' +
			(app._recoveryCodes || []).join('\n') + '\n';
		var blob = new Blob([text], { type: 'text/plain' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = 'joinery-vault-recovery-keys.txt';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
		recoveryProven = true;
		$('jy-vault-recovery-finish').disabled = false;
	}

	// ==========================================================================
	// Store DEK (the key that encrypts entries), sealed to the vault public key
	// ==========================================================================
	async function initStoreDek(sess) {
		var d = await VaultCrypto.newDek();
		var sealed = await sess.sealTo(d.dekBytes);
		try {
			await joineryApi.post('vault/keyring_save', { wrapped_dek: sealed });
		} catch (e) {
			// The action is create-only: another tab/device sealed a DEK first.
			// Discard ours and use theirs - overwriting would orphan their entries.
			d.dekBytes.fill(0);
			var kr = await joineryApi.post('vault/keyring_get', {});
			if (kr.set_up && kr.wrapped_dek) { await openStoreDek(sess, kr.wrapped_dek); return; }
			throw e;
		}
		d.dekBytes.fill(0);
		dekKey = d.dekKey;
	}
	async function openStoreDek(sess, wrappedDek) {
		var dekBytes = await sess.openSealed(wrappedDek);
		dekKey = await VaultCrypto.importDek(dekBytes);
		dekBytes.fill(0);
	}
	async function loadStoreDek(sess) {
		var kr = await joineryApi.post('vault/keyring_get', {});
		if (!kr.set_up || !kr.wrapped_dek) { await initStoreDek(sess); return; }
		await openStoreDek(sess, kr.wrapped_dek);
	}

	// ==========================================================================
	// Unlock
	// ==========================================================================
	function startUnlock(st) {
		showSection('jy-vault-unlock');
		$('jy-vault-unlock-passkey').hidden = !(CONFIG.passkeysEnabled && st.passkey_wrapping_count > 0);
		$('jy-vault-unlock-passphrase-wrap').hidden = !st.has_passphrase;

		bindOnce($('jy-vault-unlock-passkey'), 'click', unlockPasskey);
		bindOnce($('jy-vault-unlock-passphrase-btn'), 'click', unlockPassphrase);
		bindOnce($('jy-vault-unlock-recovery-btn'), 'click', unlockRecovery);
		bindOnce($('unlock_passphrase'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); unlockPassphrase(); } });
		bindOnce($('recovery_code'), 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); unlockRecovery(); } });
		bindOnce($('jy-vault-show-recovery'), 'click', function () {
			$('jy-vault-unlock-recovery-wrap').hidden = false;
			this.hidden = true;
		});

		// If neither passkey nor passphrase is available, recovery is the way in.
		if ($('jy-vault-unlock-passkey').hidden && $('jy-vault-unlock-passphrase-wrap').hidden) {
			$('jy-vault-unlock-recovery-wrap').hidden = false;
			$('jy-vault-show-recovery').hidden = true;
		}
	}
	function bindOnce(el, evt, fn) {
		if (!el || el['_bound_' + evt]) return;
		el['_bound_' + evt] = true;
		el.addEventListener(evt, fn);
	}

	async function unlockPasskey() {
		setError('jy-vault-unlock-error', '');
		var btn = $('jy-vault-unlock-passkey'); btn.disabled = true;
		try {
			var pk = await VaultKeyring.derivePasskeyKek(SCOPE);
			var sess = await VaultKeyring.unlockWithPasskey(SCOPE, pk.kek, pk.credentialId);
			await postUnlock(sess);
		} catch (e) { setError('jy-vault-unlock-error', (e && e.message) || 'Could not unlock.'); }
		finally { btn.disabled = false; }
	}
	async function unlockPassphrase() {
		setError('jy-vault-unlock-error', '');
		try {
			var sess = await VaultKeyring.unlockWithPassphrase(SCOPE, $('unlock_passphrase').value || '');
			$('unlock_passphrase').value = '';
			await postUnlock(sess);
		} catch (e) { setError('jy-vault-unlock-error', (e && e.message) || 'Could not unlock.'); }
	}
	async function unlockRecovery() {
		setError('jy-vault-unlock-error', '');
		try {
			var res = await VaultKeyring.unlockWithRecovery(SCOPE, $('recovery_code').value || '');
			$('recovery_code').value = '';
			await postUnlock(res.session);
			toast('Recovery key used - consider regenerating your recovery keys.');
		} catch (e) { setError('jy-vault-unlock-error', (e && e.message) || 'Could not unlock.'); }
	}

	async function postUnlock(sess) {
		session = sess;
		await loadStoreDek(sess);
		await loadEntries();
		enterManager();
		resetIdle();
	}

	// ==========================================================================
	// Entries
	// ==========================================================================
	async function loadEntries() {
		var res = await joineryApi.post('vault/entries_list', {});
		entries = [];
		undecryptableCount = 0;
		for (var i = 0; i < res.entries.length; i++) {
			try {
				var json = await VaultCrypto.decrypt(res.entries[i].ciphertext, dekKey);
				entries.push({ id: res.entries[i].id, record: JSON.parse(json) });
			} catch (e) {
				// Never silently vanish: a blob this key can't open is counted and
				// surfaced as a persistent warning (see enterManager) - an empty
				// list with stored ciphertext must look like the incident it is.
				undecryptableCount++;
			}
		}
		entries.sort(byTitle);
	}

	function enterManager() {
		showSection('jy-vault-manager');
		bindManagerOnce();
		var warn = $('jy-vault-decrypt-warning');
		if (warn) {
			warn.hidden = !undecryptableCount;
			warn.textContent = undecryptableCount
				? (undecryptableCount + (undecryptableCount === 1 ? ' saved entry' : ' saved entries')
					+ ' could not be decrypted with this vault\'s key. '
					+ (undecryptableCount === 1 ? 'It is' : 'They are') + ' still stored encrypted and hidden from the list.')
				: '';
		}
		renderList('');
		showDetailEmpty();
	}

	var managerBound = false;
	function bindManagerOnce() {
		if (managerBound) return; managerBound = true;
		$('jy-vault-search').addEventListener('input', function () { renderList(this.value); });
		$('jy-vault-add').addEventListener('click', function () { openEditor(null); });
		$('jy-vault-lock').addEventListener('click', lock);
		$('jy-vault-entry-cancel').addEventListener('click', function () {
			if (selectedId) showDetailView(byId(selectedId)); else showDetailEmpty();
		});
		$('jy-vault-entry-delete').addEventListener('click', deleteSelected);
		$('jy-vault-entry-save').addEventListener('click', saveEntry);
		var pwField = $('entry_password');
		if (pwField) { addGenerateButton(pwField); }
		initAutolockControl();
		$('jy-vault-trash').addEventListener('click', toggleTrash);
		$('jy-vault-export').addEventListener('click', doExport);
		$('jy-vault-import').addEventListener('click', function () { $('jy-vault-import-file').click(); });
		$('jy-vault-import-file').addEventListener('change', doImport);
		// idle-defer on any activity in the manager
		['keydown', 'pointerdown', 'pointermove'].forEach(function (evt) {
			$('jy-vault-manager').addEventListener(evt, resetIdle, { passive: true });
		});
	}

	function byTitle(a, b) { return (a.record.title || '').localeCompare(b.record.title || ''); }
	function currentList() { return trashMode ? trashEntries : entries; }
	function byId(id) { var src = currentList(); for (var i = 0; i < src.length; i++) if (src[i].id === id) return src[i]; return null; }

	function renderList(filter) {
		var ul = $('jy-vault-list'); ul.innerHTML = '';
		var source = currentList();
		var q = (filter || '').trim().toLowerCase();
		var shown = source.filter(function (e) {
			if (!q) return true;
			var r = e.record;
			return [r.title, r.username, r.url].some(function (v) { return (v || '').toLowerCase().indexOf(q) !== -1; });
		});
		if (!shown.length) {
			var li = document.createElement('li');
			li.className = 'jy-vault-list-empty';
			li.textContent = source.length ? 'No matches.' : (trashMode ? 'Trash is empty.' : 'No entries yet.');
			ul.appendChild(li);
			appendTrashUndecryptableNote(ul);
			return;
		}
		shown.forEach(function (e) {
			var li = document.createElement('li');
			li.className = 'jy-vault-list-item' + (e.id === selectedId ? ' is-selected' : '');
			li.tabIndex = 0;
			var title = document.createElement('div'); title.className = 'jy-vault-item-title'; title.textContent = e.record.title || '(untitled)';
			var sub = document.createElement('div'); sub.className = 'jy-vault-item-sub';
			sub.textContent = e.record.type === 'note' ? 'Secure note' : (e.record.username || e.record.url || '');
			li.appendChild(title); li.appendChild(sub);
			li.addEventListener('click', function () { selectEntry(e.id); });
			li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') selectEntry(e.id); });
			ul.appendChild(li);
		});
		appendTrashUndecryptableNote(ul);
	}

	function appendTrashUndecryptableNote(ul) {
		if (!trashMode || !trashUndecryptable) return;
		var li = document.createElement('li');
		li.className = 'jy-vault-list-empty';
		li.textContent = trashUndecryptable + ' trashed ' + (trashUndecryptable === 1 ? 'entry' : 'entries')
			+ ' could not be decrypted with this vault\'s key.';
		ul.appendChild(li);
	}

	function selectEntry(id) {
		selectedId = id;
		renderList($('jy-vault-search').value);
		showDetailView(byId(id));
	}

	function showDetailEmpty() {
		$('jy-vault-detail-empty').hidden = false;
		$('jy-vault-detail-view').hidden = true;
		$('jy-vault-detail-edit').hidden = true;
	}

	// ---- detail (read) view: masked secrets, per-field reveal + copy ----------
	function showDetailView(entry) {
		if (!entry) { showDetailEmpty(); return; }
		var r = entry.record;
		var v = $('jy-vault-detail-view');
		v.innerHTML = '';
		var h = document.createElement('div'); h.className = 'jy-vault-detail-head';
		var title = document.createElement('h2'); title.textContent = r.title || '(untitled)';
		var action = document.createElement('button'); action.className = 'jy-btn jy-btn-link';
		if (trashMode) {
			action.textContent = 'Restore';
			action.addEventListener('click', function () { restoreEntry(entry); });
		} else {
			action.textContent = 'Edit';
			action.addEventListener('click', function () { openEditor(entry); });
		}
		h.appendChild(title); h.appendChild(action); v.appendChild(h);

		if (r.type !== 'note') {
			appendField(v, 'Username', r.username, false);
			appendField(v, 'Password', r.password, true);
			appendField(v, 'Website', r.url, false, true);
			appendTotp(v, r.totp_seed);
		}
		appendNotes(v, r.notes);

		$('jy-vault-detail-empty').hidden = true;
		$('jy-vault-detail-view').hidden = false;
		$('jy-vault-detail-edit').hidden = true;
	}

	function appendField(container, label, value, secret, isLink) {
		if (!value) return;
		var row = document.createElement('div'); row.className = 'jy-vault-field';
		var lab = document.createElement('div'); lab.className = 'jy-vault-field-label'; lab.textContent = label;
		var valWrap = document.createElement('div'); valWrap.className = 'jy-vault-field-value';
		var val = document.createElement('span');
		val.className = 'jy-vault-field-text' + (secret ? ' is-masked' : '');
		val.textContent = secret ? '••••••••••' : value;
		if (isLink && !secret) { var a = document.createElement('a'); a.href = value; a.target = '_blank'; a.rel = 'noopener'; a.textContent = value; val.textContent = ''; val.appendChild(a); }
		valWrap.appendChild(val);
		if (secret) {
			var reveal = iconBtn('Reveal', function () {
				var masked = val.classList.toggle('is-masked');
				val.textContent = masked ? '••••••••••' : value;
				reveal.title = masked ? 'Reveal' : 'Hide';
			});
			valWrap.appendChild(reveal);
		}
		valWrap.appendChild(iconBtn('Copy', function () { copyValue(value, label); }));
		row.appendChild(lab); row.appendChild(valWrap); container.appendChild(row);
	}
	function appendNotes(container, notes) {
		if (!notes) return;
		var row = document.createElement('div'); row.className = 'jy-vault-field';
		var lab = document.createElement('div'); lab.className = 'jy-vault-field-label'; lab.textContent = 'Notes';
		var pre = document.createElement('div'); pre.className = 'jy-vault-notes'; pre.textContent = notes;
		row.appendChild(lab); row.appendChild(pre); container.appendChild(row);
	}
	function iconBtn(label, fn) {
		var b = document.createElement('button'); b.type = 'button'; b.className = 'jy-vault-icon-btn'; b.textContent = label; b.title = label;
		b.addEventListener('click', fn); return b;
	}

	// ---- editor ---------------------------------------------------------------
	function openEditor(entry) {
		selectedId = entry ? entry.id : null;
		$('entry_id').value = entry ? entry.id : '';
		var r = entry ? entry.record : { type: 'login' };
		setSelect('entry_type', r.type || 'login');
		$('entry_title').value = r.title || '';
		$('entry_username').value = r.username || '';
		$('entry_password').value = r.password || '';
		$('entry_url').value = r.url || '';
		$('entry_totp_seed').value = r.totp_seed || '';
		$('entry_notes').value = r.notes || '';
		$('jy-vault-entry-delete').hidden = !entry;
		$('jy-vault-detail-empty').hidden = true;
		$('jy-vault-detail-view').hidden = true;
		$('jy-vault-detail-edit').hidden = false;
		$('entry_title').focus();
	}
	function setSelect(id, value) {
		var el = $(id); if (!el) return; el.value = value;
		el.dispatchEvent(new Event('change', { bubbles: true }));  // trigger FormWriter visibility_rules
	}

	async function saveEntry() {
		var type = $('entry_type').value || 'login';
		var record = {
			type: type,
			title: $('entry_title').value.trim(),
			notes: $('entry_notes').value,
		};
		if (type !== 'note') {
			record.username = $('entry_username').value;
			record.password = $('entry_password').value;
			record.url = $('entry_url').value.trim();
			record.totp_seed = $('entry_totp_seed').value.replace(/\s+/g, '');
		}
		if (!record.title) { toast('Give the entry a title.'); $('entry_title').focus(); return; }

		var blob = await VaultCrypto.encrypt(JSON.stringify(record), dekKey);
		var id = $('entry_id').value ? parseInt($('entry_id').value, 10) : 0;
		var res;
		try {
			res = await joineryApi.post('vault/entry_save', id ? { id: id, ciphertext: blob } : { ciphertext: blob });
		} catch (e) {
			// A silent failure here means a password the user believes is stored
			// is not. Say so, and leave the editor open with the text intact.
			toast('Could not save - check your connection or sign-in, then try again.');
			return;
		}
		var newId = res.id;
		var existing = byId(newId);
		if (existing) { existing.record = record; }
		else { entries.push({ id: newId, record: record }); }
		entries.sort(byTitle);
		selectedId = newId;
		renderList($('jy-vault-search').value);
		showDetailView(byId(newId));
		toast('Saved.');
	}

	async function deleteSelected() {
		var id = $('entry_id').value ? parseInt($('entry_id').value, 10) : selectedId;
		if (!id) return;
		if (!window.confirm('Move this entry to trash?')) return;
		try {
			await joineryApi.post('vault/entry_delete', { id: id });
		} catch (e) {
			toast('Could not delete - check your connection or sign-in, then try again.');
			return;
		}
		entries = entries.filter(function (e) { return e.id !== id; });
		selectedId = null;
		renderList($('jy-vault-search').value);
		showDetailEmpty();
		toast('Moved to trash.');
	}

	// ==========================================================================
	// Trash - the same list pane over the trashed entries, restore in the detail
	// ==========================================================================
	async function toggleTrash() {
		if (trashMode) { exitTrash(); return; }
		var res;
		try { res = await joineryApi.post('vault/entries_list', { trashed: 1 }); }
		catch (e) { toast('Could not load the trash - try again.'); return; }
		trashEntries = [];
		trashUndecryptable = 0;
		for (var i = 0; i < res.entries.length; i++) {
			try {
				var json = await VaultCrypto.decrypt(res.entries[i].ciphertext, dekKey);
				trashEntries.push({ id: res.entries[i].id, record: JSON.parse(json) });
			} catch (e) { trashUndecryptable++; }
		}
		trashEntries.sort(byTitle);
		trashMode = true;
		selectedId = null;
		$('jy-vault-trash').textContent = 'Back to entries';
		$('jy-vault-add').hidden = true;
		$('jy-vault-search').value = '';
		renderList('');
		showDetailEmpty();
	}
	function exitTrash() {
		trashMode = false;
		trashEntries = [];
		trashUndecryptable = 0;
		selectedId = null;
		$('jy-vault-trash').textContent = 'Trash';
		$('jy-vault-add').hidden = false;
		$('jy-vault-search').value = '';
		renderList('');
		showDetailEmpty();
	}
	async function restoreEntry(entry) {
		try { await joineryApi.post('vault/entry_restore', { id: entry.id }); }
		catch (e) { toast('Could not restore - check your connection or sign-in, then try again.'); return; }
		trashEntries = trashEntries.filter(function (t) { return t.id !== entry.id; });
		entries.push(entry);
		entries.sort(byTitle);
		selectedId = null;
		renderList($('jy-vault-search').value);
		showDetailEmpty();
		toast('Restored.');
	}

	// ==========================================================================
	// Password generator (Phase 3)
	// ==========================================================================
	function addGenerateButton(pwField) {
		if (pwField._genAdded) return; pwField._genAdded = true;
		var btn = document.createElement('button');
		btn.type = 'button'; btn.className = 'jy-btn jy-btn-link jy-vault-generate'; btn.textContent = 'Generate';
		btn.addEventListener('click', function () { pwField.value = generatePassword(20); pwField.type = 'text'; });
		if (pwField.parentNode) pwField.parentNode.appendChild(btn);
	}
	function generatePassword(len) {
		var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*-_=+';
		// Rejection sampling: a plain modulo would skew toward the alphabet's
		// first (256 % len) characters. Draw again for bytes past the largest
		// exact multiple of the alphabet size.
		var limit = 256 - (256 % alphabet.length);
		var out = '';
		while (out.length < len) {
			var bytes = VaultCrypto.randomBytes(len - out.length);
			for (var i = 0; i < bytes.length; i++) {
				if (bytes[i] < limit) out += alphabet[bytes[i] % alphabet.length];
			}
		}
		return out;
	}

	// ==========================================================================
	// TOTP (Phase 3) - in-browser code generation with a countdown
	// ==========================================================================
	function appendTotp(container, seed) {
		if (!seed) return;
		var row = document.createElement('div'); row.className = 'jy-vault-field';
		var lab = document.createElement('div'); lab.className = 'jy-vault-field-label'; lab.textContent = 'One-time code';
		var wrap = document.createElement('div'); wrap.className = 'jy-vault-field-value';
		var code = document.createElement('span'); code.className = 'jy-vault-totp-code'; code.textContent = '……';
		var ring = document.createElement('span'); ring.className = 'jy-vault-totp-ring';
		wrap.appendChild(code); wrap.appendChild(ring);
		wrap.appendChild(iconBtn('Copy', function () { copyValue(code.textContent.replace(/\s/g, ''), 'Code'); }));
		row.appendChild(lab); row.appendChild(wrap); container.appendChild(row);

		var timer = null;
		async function tick() {
			if (!document.body.contains(code)) { if (timer) clearInterval(timer); return; }  // detail changed
			try {
				var now = Math.floor(Date.now() / 1000);
				var c = await totp(seed, now);
				code.textContent = c.slice(0, 3) + ' ' + c.slice(3);
				var remain = 30 - (now % 30);
				ring.textContent = remain + 's';
			} catch (e) { code.textContent = 'bad key'; if (timer) clearInterval(timer); }
		}
		tick(); timer = setInterval(tick, 1000);
	}
	function base32decode(s) {
		var alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		s = String(s).toUpperCase().replace(/=+$/, '').replace(/[^A-Z2-7]/g, '');
		var bits = 0, value = 0, out = [];
		for (var i = 0; i < s.length; i++) {
			value = (value << 5) | alpha.indexOf(s[i]); bits += 5;
			if (bits >= 8) { out.push((value >>> (bits - 8)) & 0xff); bits -= 8; }
		}
		return new Uint8Array(out);
	}
	async function totp(seed, epoch) {
		var counter = Math.floor(epoch / 30);
		var msg = new Uint8Array(8);
		for (var i = 7; i >= 0; i--) { msg[i] = counter & 0xff; counter = Math.floor(counter / 256); }
		var keyBytes = base32decode(seed);
		var key = await crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
		var hmac = new Uint8Array(await crypto.subtle.sign('HMAC', key, msg));
		var off = hmac[hmac.length - 1] & 0x0f;
		var bin = ((hmac[off] & 0x7f) << 24) | (hmac[off + 1] << 16) | (hmac[off + 2] << 8) | hmac[off + 3];
		return ('000000' + (bin % 1000000)).slice(-6);
	}

	// ==========================================================================
	// Clipboard - copy with best-effort clear
	// ==========================================================================
	function copyValue(value, label) {
		if (!value) return;
		navigator.clipboard.writeText(value).then(function () {
			toast((label || 'Value') + ' copied');
			if (clipboardTimer) clearTimeout(clipboardTimer);
			var secs = CONFIG.clipboardClearSeconds || 30;
			clipboardTimer = setTimeout(function () { clearClipboard(value); }, secs * 1000);
		}).catch(function () { toast('Copy failed'); });
	}
	function clearClipboard(previous) {
		// Only possible while the page holds focus, and only clear if the
		// clipboard still holds what we put there (never stomp something else).
		if (!document.hasFocus()) return;
		if (!navigator.clipboard.readText) { navigator.clipboard.writeText('').catch(function () {}); return; }
		navigator.clipboard.readText().then(function (cur) {
			if (cur === previous) navigator.clipboard.writeText('').catch(function () {});
		}).catch(function () { /* no read permission - best effort ends here */ });
	}

	// ==========================================================================
	// Import / export (Phase 3)
	// ==========================================================================
	function downloadText(text, filename, mime) {
		var blob = new Blob([text], { type: mime || 'text/plain' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = filename;
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
	}

	// Encrypted backup out: all entries, encrypted under a passphrase the user
	// picks (independent of the vault - a portable, self-contained backup).
	async function doExport() {
		if (!entries.length) { toast('Nothing to export.'); return; }
		var pass = window.prompt('Choose a passphrase to encrypt this backup. You will need it to import the file later.');
		if (!pass) return;
		try {
			var salt = VaultCrypto.b64encode(VaultCrypto.randomBytes(16));
			var kek = await VaultCrypto.kekFromPassphrase(pass, salt, VaultCrypto.DEFAULT_KDF_PARAMS);
			var payload = JSON.stringify(entries.map(function (e) { return e.record; }));
			var blob = await VaultCrypto.encrypt(payload, kek);
			var backup = { format: 'joinery-vault-backup', version: 1, kdf: VaultCrypto.DEFAULT_KDF_PARAMS, salt: salt, blob: blob };
			downloadText(JSON.stringify(backup, null, 2), 'joinery-vault-backup.json', 'application/json');
			toast('Encrypted backup downloaded.');
		} catch (e) {
			toast('Could not create the backup - ' + ((e && e.message) || 'try again.'));
		}
	}

	// Import in: our own encrypted backup, a Bitwarden JSON export, or a CSV
	// (the common 1Password / generic export shape).
	async function doImport(ev) {
		var file = ev.target.files && ev.target.files[0];
		ev.target.value = '';
		if (!file) return;
		var text = await file.text();
		var records = [];
		try {
			var trimmed = text.replace(/^﻿/, '').trim();
			if (/\.json$/i.test(file.name) || trimmed[0] === '{' || trimmed[0] === '[') {
				records = await parseJsonImport(trimmed);
			} else {
				records = parseCsvImport(text);
			}
		} catch (e) { toast(e.message || 'Could not read that file.'); return; }
		records = records.filter(function (r) { return r && r.title; });
		if (!records.length) { toast('No entries found in that file.'); return; }

		var n = 0;
		try {
			for (var i = 0; i < records.length; i++) {
				var blob = await VaultCrypto.encrypt(JSON.stringify(records[i]), dekKey);
				var res = await joineryApi.post('vault/entry_save', { ciphertext: blob });
				entries.push({ id: res.id, record: records[i] });
				n++;
			}
		} catch (e) {
			entries.sort(byTitle);
			renderList($('jy-vault-search').value);
			toast('Import stopped after ' + n + ' of ' + records.length + ' entries - check your connection or sign-in, then re-import (already-imported entries will duplicate).');
			return;
		}
		entries.sort(byTitle);
		renderList($('jy-vault-search').value);
		toast('Imported ' + n + ' ' + (n === 1 ? 'entry' : 'entries') + '.');
	}

	async function parseJsonImport(text) {
		var data = JSON.parse(text);
		if (data && data.format === 'joinery-vault-backup') {
			var pass = window.prompt('Enter the passphrase for this backup file.');
			if (!pass) throw new Error('Import cancelled.');
			var kek = await VaultCrypto.kekFromPassphrase(pass, data.salt, data.kdf || VaultCrypto.DEFAULT_KDF_PARAMS);
			var plain;
			try { plain = await VaultCrypto.decrypt(data.blob, kek); }
			catch (e) { throw new Error('Wrong passphrase for that backup.'); }
			return JSON.parse(plain);
		}
		if (data && Array.isArray(data.items)) {   // Bitwarden JSON export
			return data.items.map(function (it) {
				if (it.login) {
					return {
						type: 'login', title: it.name || '(untitled)',
						username: it.login.username || '', password: it.login.password || '',
						url: (it.login.uris && it.login.uris[0] && it.login.uris[0].uri) || '',
						totp_seed: it.login.totp || '', notes: it.notes || '',
					};
				}
				return { type: 'note', title: it.name || '(untitled)', notes: it.notes || '' };
			});
		}
		if (Array.isArray(data)) {   // our own record array shape
			return data.map(function (r) { return Object.assign({ type: r.type || 'login' }, r); });
		}
		throw new Error('Unrecognised backup format.');
	}

	function parseCsvImport(text) {
		var rows = parseCsv(text);
		if (rows.length < 2) return [];
		var header = rows[0].map(function (h) { return String(h).toLowerCase().trim(); });
		function col(row, names) {
			for (var i = 0; i < names.length; i++) { var idx = header.indexOf(names[i]); if (idx !== -1 && row[idx]) return row[idx]; }
			return '';
		}
		var out = [];
		for (var r = 1; r < rows.length; r++) {
			var row = rows[r];
			if (!row.length || row.every(function (c) { return c === ''; })) continue;
			var title = col(row, ['name', 'title']);
			var username = col(row, ['username', 'login_username', 'user', 'email']);
			var password = col(row, ['password', 'login_password', 'pass']);
			var url = col(row, ['url', 'website', 'login_uri', 'uri', 'urls']);
			var totp = col(row, ['totp', 'login_totp', 'otpauth', 'one-time password']);
			var notes = col(row, ['notes', 'note']);
			out.push(username || password || url
				? { type: 'login', title: title || url || '(untitled)', username: username, password: password, url: url, totp_seed: totp, notes: notes }
				: { type: 'note', title: title || '(untitled)', notes: notes });
		}
		return out;
	}

	// Minimal RFC-4180-ish CSV parser (handles quoted fields and embedded commas/newlines).
	function parseCsv(text) {
		var rows = [], row = [], field = '', inQuotes = false;
		for (var i = 0; i < text.length; i++) {
			var c = text[i];
			if (inQuotes) {
				if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else inQuotes = false; }
				else field += c;
			} else if (c === '"') { inQuotes = true; }
			else if (c === ',') { row.push(field); field = ''; }
			else if (c === '\n' || c === '\r') {
				if (c === '\r' && text[i + 1] === '\n') i++;
				row.push(field); rows.push(row); row = []; field = '';
			} else field += c;
		}
		if (field !== '' || row.length) { row.push(field); rows.push(row); }
		return rows;
	}

	// ==========================================================================
	// User-configurable auto-lock (Phase 3), remembered per scope in localStorage
	// ==========================================================================
	function autolockStorageKey() { return 'jy_vault_autolock_' + SCOPE; }
	function initAutolockControl() {
		var sel = $('jy-vault-autolock-select');
		if (!sel) return;
		var stored = null;
		try { stored = localStorage.getItem(autolockStorageKey()); } catch (e) {}
		if (stored) { CONFIG.autolockMinutes = parseInt(stored, 10) || CONFIG.autolockMinutes; }
		sel.value = String(CONFIG.autolockMinutes);
		if (sel.value === '') { sel.value = '15'; CONFIG.autolockMinutes = 15; }
		sel.addEventListener('change', function () {
			CONFIG.autolockMinutes = parseInt(sel.value, 10) || 15;
			try { localStorage.setItem(autolockStorageKey(), String(CONFIG.autolockMinutes)); } catch (e) {}
			resetIdle();
		});
	}

	// ==========================================================================
	// Locking - idle, manual, tab-close. Discards ALL plaintext.
	// ==========================================================================
	function resetIdle() {
		if (idleTimer) clearTimeout(idleTimer);
		var mins = CONFIG.autolockMinutes || 15;
		idleTimer = setTimeout(lock, mins * 60 * 1000);
	}
	function lock() {
		if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
		if (clipboardTimer) { clearTimeout(clipboardTimer); clipboardTimer = null; }
		if (session) { session.lock(); session = null; }
		dekKey = null;
		entries = [];
		undecryptableCount = 0;
		trashMode = false;
		trashEntries = [];
		trashUndecryptable = 0;
		var trashBtn = $('jy-vault-trash'); if (trashBtn) trashBtn.textContent = 'Trash';
		var addBtn = $('jy-vault-add'); if (addBtn) addBtn.hidden = false;
		selectedId = null;
		// wipe any plaintext left in the DOM (including an open, unsaved editor)
		['entry_title', 'entry_username', 'entry_password', 'entry_url', 'entry_totp_seed', 'entry_notes', 'jy-vault-search']
			.forEach(function (id) { var el = $(id); if (el) el.value = ''; });
		$('jy-vault-list').innerHTML = '';
		$('jy-vault-detail-view').innerHTML = '';
		VaultKeyring.status(SCOPE).then(startUnlock).catch(function () { showSection('jy-vault-unlock'); });
	}
	// closing/hiding the tab: memory is discarded by the browser; also proactively lock
	window.addEventListener('pagehide', function () { if (session) session.lock(); });

	// Kick off once the deferred core modules are present.
	function ready() {
		if (window.VaultCrypto && window.VaultKeyring && window.JoineryPasskeys && window.joineryApi) { boot(); }
		else setTimeout(ready, 30);
	}
	ready();
})();
