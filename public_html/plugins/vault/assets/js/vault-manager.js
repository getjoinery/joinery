/**
 * vault-manager.js - the password manager UI at /profile/vault.
 *
 * The consumer half of the client-custody Sealed Vault. Core does the vault
 * identity: JoinerySealed.session('passwords') runs the setup or unlock
 * ceremony (VaultKeyring.ensureUnlocked, a modal) and holds the session, and
 * core locks it — after the idle time, on Lock now, when the page is left or
 * restored from the back/forward cache. This file manages the store DEK and
 * the encrypted entries, and wipes them in its onLock handler: every
 * plaintext lives only in this tab's memory and the lock discards it.
 *
 * @version 2.0 - the ceremony, the session and the idle lock are core's
 * @version 1.1 - lock() clears the clipboard it filled; a bfcache restore locks.
 */
(function () {
	'use strict';

	var app = document.getElementById('jy-vault-app');
	if (!app) return;
	var CONFIG = JSON.parse(app.getAttribute('data-config') || '{}');
	var SCOPE = CONFIG.scope || 'passwords';

	// ---- in-memory session state (all discarded on lock) ----------------------
	var session = null;      // the scope session JoinerySealed holds (it keeps the secret key)
	var dekKey = null;       // non-extractable AES-GCM CryptoKey for entry content
	var entries = [];        // [{ id, record }] decrypted in memory
	var undecryptableCount = 0;   // stored blobs the current key could not open
	var trashMode = false;   // list pane showing trash instead of live entries
	var trashEntries = [];   // decrypted trashed entries (only while in trash mode)
	var trashUndecryptable = 0;
	var selectedId = null;
	var clipboardTimer = null;
	var lastCopied = null;   // the value this page last put on the clipboard

	var $ = function (id) { return document.getElementById(id); };
	function showSection(id) {
		['jy-vault-loading', 'jy-vault-unsupported', 'jy-vault-locked', 'jy-vault-manager']
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
	// Boot: open the vault through core, then the store DEK and entries
	// ==========================================================================
	async function boot() {
		if (!(await VaultKeyring.isSupported())) { showSection('jy-vault-unsupported'); return; }
		JoinerySealed.onLock(SCOPE, wipe);
		$('jy-vault-open').addEventListener('click', open);
		open();
	}

	// First run or not, one call: core runs setup (recovery codes and their
	// proof included) or unlock. A first run mints the store DEK here.
	async function open() {
		setError('jy-vault-locked-error', '');
		showSection('jy-vault-loading');
		try {
			var st = await VaultKeyring.status(SCOPE);
			session = await JoinerySealed.session(SCOPE, { reason: 'to open your passwords' });
			await loadStoreDek(session);
			await loadEntries();
			enterManager();
			if (!st.set_up && !entries.length) openEditor(null);
		} catch (e) {
			session = null;
			showSection('jy-vault-locked');
			var msg = (e && e.message) || '';
			if (!/cancel/i.test(msg)) setError('jy-vault-locked-error', msg || 'Could not open your vault.');
		}
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
			lastCopied = value;
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
	// Auto-lock: the select is this browser's choice for every vault it holds
	// (core's JoinerySealed.setIdleMinutes); the site sets the default.
	// ==========================================================================
	function initAutolockControl() {
		var sel = $('jy-vault-autolock-select');
		if (!sel) return;
		var current = String(JoinerySealed.idleMinutes());
		if (!sel.querySelector('option[value="' + current + '"]')) {
			var opt = document.createElement('option');
			opt.value = current;
			opt.textContent = current + ' min';
			sel.appendChild(opt);
		}
		sel.value = current;
		sel.addEventListener('change', function () {
			JoinerySealed.setIdleMinutes(parseInt(sel.value, 10) || null);
		});
	}

	// ==========================================================================
	// Locking. Core locks (idle, Lock now, leaving the page, a back/forward
	// cache restore) and calls wipe(); what wipe() discards is ours: the store
	// DEK, every decrypted entry, the DOM, the clipboard we filled.
	// ==========================================================================
	function lock() { JoinerySealed.lock(SCOPE); }

	function wipe() {
		if (clipboardTimer) { clearTimeout(clipboardTimer); clipboardTimer = null; }
		if (lastCopied !== null) { clearClipboard(lastCopied); lastCopied = null; }
		session = null;
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
		showSection('jy-vault-locked');
	}

	// Kick off once the deferred core modules are present.
	function ready() {
		if (window.VaultCrypto && window.VaultKeyring && window.JoinerySealed && window.joineryApi) { boot(); }
		else setTimeout(ready, 30);
	}
	ready();
})();
