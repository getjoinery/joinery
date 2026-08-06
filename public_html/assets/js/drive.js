/* Drive — member file storage client. Vanilla JS, talks to /api/v1 drive_*
 * actions through the shared joineryApi transport (browser-session credential). */
(function () {
	'use strict';

	var api = window.joineryApi;
	var CFG = window.DRIVE_CONFIG || {};
	var DC = window.DriveCrypto;
	var VK = window.VaultKeyring;
	var SCOPE = CFG.vaultScope || 'drive';
	var state = {
		view: 'mine',
		folderId: 0,
		folderEncrypted: false,
		viewMode: 'list',
		items: [],
		breadcrumb: [],
		usage: null
	};

	// The unlocked drive vault session (VaultKeyring makeSession), held only in
	// this tab's memory for the page lifetime — client-custody has no server
	// unlock window. Per-file keys/metadata are cached after first decrypt.
	var driveSession = null;
	var fkCache = {}; // fileId -> { fkKey, fkBytes, meta }

	// ---- tiny DOM helpers --------------------------------------------------
	function $(id) { return document.getElementById(id); }
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

	var ICONS = {
		folder: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
		file: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
		image: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>',
		star: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		more: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>'
	};

	function humanBytes(n) {
		n = Number(n) || 0;
		if (n < 1024) return n + ' B';
		var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
		do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
		return n.toFixed(n < 10 ? 1 : 0) + ' ' + u[i];
	}
	function fmtDate(s) {
		if (!s) return '';
		var d = new Date((s + '').replace(' ', 'T') + 'Z');
		return isNaN(d) ? '' : d.toLocaleDateString();
	}

	function toast(msg) {
		var t = el('div', 'drv-toast', msg);
		t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:.6rem 1rem;border-radius:8px;z-index:1000;font-size:.9rem';
		document.body.appendChild(t);
		setTimeout(function () { t.remove(); }, 3200);
	}

	// ---- vault unlock (client-custody, scope 'drive') ----------------------
	// One tab-lifetime unlocked session gates every encrypt/decrypt. The modal
	// runs enrollment (first time) or unlock (locked) via the shared VaultKeyring.
	var vaultError = function (msg) { var e = $('drvVaultError'); if (e) { e.textContent = msg || ''; e.hidden = !msg; } };

	function ensureUnlocked() {
		if (driveSession && !driveSession.locked()) return Promise.resolve(driveSession);
		if (!DC || !VK) return Promise.reject(new Error('Encryption is unavailable in this browser.'));
		return DC.isSupported().then(function (ok) {
			if (!ok) throw new Error('This browser cannot open encrypted files (needs modern WebCrypto).');
			return VK.status(SCOPE);
		}).then(function (st) {
			return new Promise(function (resolve, reject) {
				openVaultDialog(st, resolve, reject);
			});
		});
	}

	var vaultResolve = null, vaultReject = null, pendingRecovery = null;
	function openVaultDialog(st, resolve, reject) {
		vaultResolve = resolve; vaultReject = reject; vaultError('');
		var dlg = $('drvVaultDialog');
		var setUp = st && st.set_up;
		$('drvVaultSetup').hidden = setUp;
		$('drvVaultUnlock').hidden = !setUp;
		$('drvVaultRecovery').hidden = true;
		$('drvVaultSetupPpWrap').hidden = true;
		$('drvVaultSetupPpGo').hidden = true;
		// Hide passkey buttons when passkeys aren't enabled on the instance.
		$('drvVaultSetupPasskey').hidden = !CFG.passkeysEnabled;
		$('drvVaultUnlockPasskey').hidden = !CFG.passkeysEnabled;
		dlg.returnValue = '';
		dlg.showModal();
	}
	function closeVaultDialog(ok) {
		var dlg = $('drvVaultDialog');
		if (dlg.open) dlg.close();
		if (!ok && vaultReject) { vaultReject(new Error('Unlock cancelled.')); }
		vaultResolve = null; vaultReject = null;
	}
	function vaultUnlocked(session) {
		driveSession = session;
		var r = vaultResolve; vaultResolve = null; vaultReject = null;
		if ($('drvVaultDialog').open) $('drvVaultDialog').close();
		if (r) r(session);
	}

	function wireVaultDialog() {
		var g = function (id) { return $(id); };
		if (g('drvVaultSetupPpToggle')) g('drvVaultSetupPpToggle').onclick = function () {
			$('drvVaultSetupPpWrap').hidden = false; $('drvVaultSetupPpGo').hidden = false;
		};
		if (g('drvVaultSetupPasskey')) g('drvVaultSetupPasskey').onclick = function () { doSetup('passkey'); };
		if (g('drvVaultSetupPpGo')) g('drvVaultSetupPpGo').onclick = function () { doSetup('passphrase'); };
		if (g('drvVaultRecoveryDone')) g('drvVaultRecoveryDone').onclick = function () {
			if (pendingRecovery) { vaultUnlocked(pendingRecovery.session); pendingRecovery = null; }
		};
		if (g('drvVaultUnlockPasskey')) g('drvVaultUnlockPasskey').onclick = function () { doUnlock('passkey'); };
		if (g('drvVaultUnlockPpGo')) g('drvVaultUnlockPpGo').onclick = function () { doUnlock('passphrase'); };
		if (g('drvVaultUnlockRecGo')) g('drvVaultUnlockRecGo').onclick = function () { doUnlock('recovery'); };
		var dlg = $('drvVaultDialog');
		if (dlg) dlg.addEventListener('cancel', function () { closeVaultDialog(false); });
		dlg.querySelectorAll('[data-close]').forEach(function (b) { b.onclick = function () { closeVaultDialog(false); }; });
	}

	async function doSetup(method) {
		vaultError('');
		if (!$('drvVaultAck').checked) { vaultError('Please confirm you understand the recovery warning.'); return; }
		try {
			var opts = { acknowledged: true };
			if (method === 'passkey') {
				opts.passkey = await VK.derivePasskeyKek(SCOPE);
			} else {
				var pp = $('drvVaultSetupPp').value || '';
				if (pp.length < 10) { vaultError('Use a passphrase of at least 10 characters.'); return; }
				opts.passphrase = pp;
			}
			var res = await VK.setup(SCOPE, opts);
			$('drvVaultSetupPp').value = '';
			// Show recovery codes once, then finish.
			pendingRecovery = res;
			$('drvVaultRecoveryCodes').textContent = (res.recoveryCodes || []).join('\n');
			$('drvVaultRecovery').hidden = false;
			$('drvVaultSetup').querySelectorAll('button').forEach(function (b) {
				if (b.id !== 'drvVaultRecoveryDone') b.disabled = true;
			});
		} catch (e) { vaultError(e.message || 'Setup failed.'); }
	}

	async function doUnlock(method) {
		vaultError('');
		try {
			var session;
			if (method === 'passkey') {
				var d = await VK.derivePasskeyKek(SCOPE);
				session = await VK.unlockWithPasskey(SCOPE, d.kek, d.credentialId);
			} else if (method === 'passphrase') {
				session = await VK.unlockWithPassphrase(SCOPE, $('drvVaultUnlockPp').value || '');
				$('drvVaultUnlockPp').value = '';
			} else {
				var r = await VK.unlockWithRecovery(SCOPE, $('drvVaultUnlockRec').value || '');
				session = r.session;
				$('drvVaultUnlockRec').value = '';
			}
			vaultUnlocked(session);
		} catch (e) { vaultError(e.message || 'Unlock failed.'); }
	}

	// ---- encrypted-file helpers --------------------------------------------
	// Resolve a file's AES key + decrypted metadata (cached), unwrapping the
	// caller's wrapped_file_key with the unlocked session.
	async function fileKeyFor(it) {
		if (fkCache[it.id]) return fkCache[it.id];
		var session = await ensureUnlocked();
		var wrapped = it.wrapped_file_key;
		if (!wrapped) {
			// Not in the listing (e.g. deep link) — fetch the caller's own grant.
			var r = await api.post('drive_key_grants', { file_ids: [it.id] });
			wrapped = r.keys && r.keys[it.id];
			if (!wrapped) throw new Error('You do not hold a key for this file.');
		}
		var fkBytes = await DC.openWrappedFileKey(session, wrapped);
		var fkKey = await DC.importFileKey(fkBytes);
		var meta = it.encrypted_metadata ? await DC.decryptMetadata(it.encrypted_metadata, fkKey) : {};
		var entry = { fkKey: fkKey, fkBytes: fkBytes, meta: meta };
		fkCache[it.id] = entry;
		return entry;
	}

	// ---- rendering ---------------------------------------------------------
	function render(data) {
		state.items = data.items || [];
		state.breadcrumb = data.breadcrumb || [];
		state.folderEncrypted = !!(data.folder && data.folder.encrypted);
		state.folderLevel = (data.folder && data.folder.protection_level) || 'standard';
		if (data.usage) state.usage = data.usage;
		if (typeof data.folder_id !== 'undefined') state.folderId = data.folder_id || 0;
		renderBreadcrumb();
		renderItems();
		renderMeter();
		if (data.truncated) toast('Showing the first 2000 items.');
	}

	function renderBreadcrumb() {
		var bc = $('drvBreadcrumb');
		bc.innerHTML = '';
		if (state.view !== 'mine') {
			var label = { shared: 'Shared with me', starred: 'Starred', trash: 'Trash' }[state.view] || '';
			bc.appendChild(el('span', 'crumb', label));
			return;
		}
		var root = el('a', 'crumb', 'My Drive');
		root.onclick = function () { openFolder(0); };
		bc.appendChild(root);
		(state.breadcrumb || []).forEach(function (c) {
			bc.appendChild(el('span', 'sep', '/'));
			var a = el('a', 'crumb', c.name);
			a.onclick = function () { openFolder(c.id); };
			bc.appendChild(a);
		});
	}

	function renderItems() {
		var wrap = $('drvItems');
		wrap.innerHTML = '';
		wrap.className = 'drv-items ' + (state.viewMode === 'grid' ? 'drv-view-grid' : 'drv-view-list');
		if (!state.items.length) { $('drvEmpty').hidden = false; return; }
		$('drvEmpty').hidden = true;
		state.items.forEach(function (it) { wrap.appendChild(renderItem(it)); });
		maybeDecryptVisible();
	}

	// Decrypt names/thumbnails for the encrypted files on screen. Inside an
	// encrypted folder we proactively unlock (the user came here to read them);
	// elsewhere we only decrypt if the vault is already open, so a mixed listing
	// never forces an unlock prompt.
	function hasEncryptedItems() {
		return state.items.some(function (it) { return it.entity_type === 'file' && it.encrypted; });
	}
	function maybeDecryptVisible() {
		if (!hasEncryptedItems()) return;
		if (driveSession && !driveSession.locked()) { decryptVisible(); return; }
		if (state.folderEncrypted) {
			ensureUnlocked().then(decryptVisible).catch(function () {/* left locked */});
		}
	}
	function decryptVisible() {
		state.items.forEach(function (it) {
			if (it.entity_type !== 'file' || !it.encrypted) return;
			var row = document.querySelector('.drv-item[data-id="' + it.id + '"][data-type="file"]');
			if (!row) return;
			fileKeyFor(it).then(function (entry) {
				it._name = entry.meta.name || it.name;
				it._mime = entry.meta.mime || '';
				var nameEl = row.querySelector('.drv-item-name');
				if (nameEl) nameEl.textContent = it._name;
				// Type-aware icon + optional decrypted thumbnail.
				var icon = row.querySelector('.drv-item-icon');
				if (icon) {
					var isImg = (it._mime || '').indexOf('image/') === 0;
					icon.innerHTML = isImg ? ICONS.image : ICONS.file;
					if (isImg && entry.meta.thumb && it.thumb_url) {
						loadEncryptedThumb(it, entry, icon);
					}
				}
			}).catch(function () {/* stays as a locked placeholder */});
		});
	}
	function loadEncryptedThumb(it, entry, icon) {
		fetch(it.thumb_url).then(function (r) { return r.ok ? r.arrayBuffer() : null; }).then(function (buf) {
			if (!buf) return;
			return DC.decryptThumbnail(new Uint8Array(buf), entry.fkKey, entry.meta.cid);
		}).then(function (blob) {
			if (!blob) return;
			var img = new Image();
			img.className = 'drv-item-thumb';
			img.alt = '';
			img.src = URL.createObjectURL(blob);
			icon.innerHTML = '';
			icon.appendChild(img);
		}).catch(function () {/* keep the type icon */});
	}

	function renderItem(it) {
		var row = el('div', 'drv-item');
		row.setAttribute('role', 'listitem');
		row.dataset.type = it.entity_type;
		row.dataset.id = it.id;

		var icon = el('div', 'drv-item-icon');
		if (it.entity_type === 'file' && it.encrypted) {
			// Ciphertext thumbnail can't be an <img src> — decryptVisible() fills
			// the real name + thumb once the vault is open. Start with a file icon.
			icon.innerHTML = ICONS.file;
		} else if (it.entity_type === 'file' && it.thumb_url) {
			var img = new Image();
			img.className = 'drv-item-thumb';
			img.alt = '';
			// Fall back to the generic image icon if the thumbnail variant is missing.
			img.onerror = function () { icon.innerHTML = ICONS.image; };
			img.src = it.thumb_url;
			icon.appendChild(img);
		} else {
			icon.innerHTML = it.entity_type === 'folder' ? ICONS.folder : (it.is_image ? ICONS.image : ICONS.file);
		}
		row.appendChild(icon);

		// Encrypted files show a locked placeholder until decryptVisible() swaps in
		// the real name; encrypted folders get a lock badge next to their name.
		var displayName = (it.entity_type === 'file' && it.encrypted)
			? (it._name || 'Encrypted file')
			: it.name;
		var name = el('div', 'drv-item-name', displayName);
		// One badge, one meaning. Fortress says "only your browser can read this";
		// Private says "the server can, but only while you're here".
		if (it.encrypted) {
			var lk = el('span', 'drv-item-lock', '🔒');
			lk.title = 'Fortress — encrypted by your browser';
			name.appendChild(lk);
		} else if (it.protection_level === 'private') {
			var pv = el('span', 'drv-item-lock', '🔑');
			pv.title = it.entity_type === 'folder'
				? 'Private — opened only while you\'re signed in and unlocked. Not synced to your devices.'
				: 'Private — opened only while you\'re signed in and unlocked';
			name.appendChild(pv);
		}
		row.appendChild(name);

		var meta = el('div', 'drv-item-meta');
		if (it.entity_type === 'file') meta.textContent = humanBytes(it.size) + ' · ' + fmtDate(it.create_time);
		else meta.textContent = fmtDate(it.create_time);
		row.appendChild(meta);

		if (it.entity_type === 'file' && state.view !== 'trash') {
			var star = el('button', 'drv-star' + (it.starred ? '' : ' off'));
			star.type = 'button';
			star.innerHTML = ICONS.star;
			star.title = it.starred ? 'Unstar' : 'Star';
			star.onclick = function (e) { e.stopPropagation(); toggleStar(it, star); };
			row.appendChild(star);
		}

		var more = el('button', 'drv-item-more');
		more.type = 'button';
		more.innerHTML = ICONS.more;
		more.setAttribute('aria-label', 'More actions');
		more.onclick = function (e) { e.stopPropagation(); openMenu(e, it); };
		row.appendChild(more);

		row.onclick = function () { activate(it); };
		row.oncontextmenu = function (e) { e.preventDefault(); openMenu(e, it); };
		return row;
	}

	function renderMeter() {
		if (!state.usage) return;
		var used = state.usage.bytes_used || 0, quota = state.usage.quota_bytes || 0;
		var pct = quota > 0 ? Math.min(100, Math.round(used / quota * 100)) : 0;
		var fill = $('drvMeterFill');
		fill.style.width = pct + '%';
		fill.classList.toggle('full', pct >= 100);
		$('drvMeterLabel').textContent = quota > 0
			? humanBytes(used) + ' of ' + humanBytes(quota) + ' used'
			: humanBytes(used) + ' used';
		var up = $('drvUpgrade');
		if (quota > 0 && used >= quota) {
			up.hidden = false;
			up.innerHTML = '<a class="jy-btn jy-btn-primary" href="/pricing">Get more storage</a>';
		} else {
			up.hidden = true;
		}
	}

	// ---- data --------------------------------------------------------------
	function load() {
		var body = { view: state.view };
		if (state.view === 'mine') body.folder_id = state.folderId;
		return api.post('drive_list', body).then(render).catch(function (e) { toast(e.message || 'Could not load Drive.'); });
	}

	function openFolder(id) {
		state.view = 'mine';
		state.folderId = id || 0;
		setActiveNav('mine');
		load();
	}

	function activate(it) {
		if (it.entity_type === 'folder') {
			if (state.view === 'trash') return; // trashed folders are not browsable
			openFolder(it.id);
		} else if (it.encrypted) {
			downloadEncrypted(it);
		} else if (it.protection_level === 'private') {
			openSealed(it);
		} else {
			if (it.download_url) window.open(it.download_url, '_blank');
		}
	}

	// A Private file's bytes are opened by the SERVER, inside the owner's unlock
	// window — so there is nothing to decrypt here. The only thing this has to
	// get right is the locked case: ask for the window, then re-run the original
	// request. Same contract as the mail reader; the shared ceremony
	// (assets/js/vault-lock.js) keeps the header chip and the presence beacon in
	// step with an unlock started from here.
	function openSealed(it) {
		if (!it.download_url) return;
		fetch(it.download_url, { method: 'HEAD' }).then(function (r) {
			if (r.status !== 423) {
				window.open(it.download_url, '_blank');
				return;
			}
			if (!window.JoineryVaultLock) {
				toast('Unlock your vault to open this file.');
				return;
			}
			return JoineryVaultLock.unlock().then(function (ok) {
				if (ok) window.open(it.download_url, '_blank');
			});
		}).catch(function () {
			// A HEAD that cannot be made is not a reason to refuse the download;
			// let the browser follow the link and show whatever comes back.
			window.open(it.download_url, '_blank');
		});
	}

	// Fetch ciphertext, decrypt in the browser, and hand the plaintext to the user
	// as a download under its real name. The server never sees a key or plaintext.
	async function downloadEncrypted(it) {
		try {
			var entry = await fileKeyFor(it);
			toast('Decrypting ' + (entry.meta.name || 'file') + '…');
			var resp = await fetch(it.download_url);
			if (!resp.ok) throw new Error('Download failed.');
			var buf = await resp.arrayBuffer();
			var plain = await DC.decryptContent(buf, entry.fkKey, entry.meta.cid);
			var blob = new Blob([plain], { type: entry.meta.mime || 'application/octet-stream' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url; a.download = entry.meta.name || 'download';
			document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
		} catch (e) { toast(e.message || 'Could not decrypt file.'); }
	}

	function toggleStar(it, btn) {
		api.post('reaction_toggle', { entity_type: 'file', entity_id: it.id, reaction_type: 'like' })
			.then(function (r) {
				it.starred = (r.action === 'reacted');
				btn.classList.toggle('off', !it.starred);
				if (state.view === 'starred' && !it.starred) load();
			})
			.catch(function (e) { toast(e.message || 'Could not update star.'); });
	}

	// ---- context menu ------------------------------------------------------
	function openMenu(e, it) {
		var menu = $('drvMenu');
		menu.innerHTML = '';
		var opts = [];
		if (state.view === 'trash') {
			opts.push(['Restore', function () { doRestore(it); }]);
			opts.push(['Delete forever', function () { confirmDelete(it); }, true]);
		} else {
			if (it.entity_type === 'file') opts.push(['Download', function () { if (it.encrypted) { downloadEncrypted(it); } else if (it.protection_level === 'private') { openSealed(it); } else if (it.download_url) { window.open(it.download_url, '_blank'); } }]);
			opts.push(['Rename', function () { openRename(it); }]);
			opts.push(['Move to…', function () { openMove(it); }]);
			if (it.entity_type === 'file') opts.push([it.starred ? 'Unstar' : 'Star', function () { toggleStar(it, {classList:{toggle:function(){}}}); }]);
			// Protection belongs to a whole tree, so it is offered on top-level
			// folders only — and only between the two levels the server can
			// convert (a Fortress tree is the browser's to make, at creation).
			if (it.entity_type === 'folder' && it.owner_id === CFG.userId
				&& !it.parent_id && it.protection_level !== 'fortress') {
				opts.push(['Protection…', function () { openProtection(it); }]);
			}
			if (it.owner_id === CFG.userId) opts.push(['Share', function () { openShare(it); }]);
			if (it.entity_type === 'file') opts.push(['Version history', function () { openVersions(it); }]);
			opts.push(['Delete', function () { doTrash(it); }, true]);
		}
		opts.forEach(function (o) {
			var b = el('button', o[2] ? 'danger' : '', o[0]);
			b.type = 'button';
			b.onclick = function () { hideMenu(); o[1](); };
			menu.appendChild(b);
		});
		menu.hidden = false;
		var x = e.clientX, y = e.clientY;
		var r = menu.getBoundingClientRect();
		if (x + r.width > window.innerWidth) x = window.innerWidth - r.width - 8;
		if (y + r.height > window.innerHeight) y = window.innerHeight - r.height - 8;
		menu.style.left = (x + window.scrollX) + 'px';
		menu.style.top = (y + window.scrollY) + 'px';
	}
	function hideMenu() { $('drvMenu').hidden = true; }

	// ---- mutations ---------------------------------------------------------
	function doTrash(it) {
		api.post('drive_trash', { entity_type: it.entity_type, entity_id: it.id })
			.then(load).catch(function (e) { toast(e.message || 'Delete failed.'); });
	}
	function doRestore(it) {
		api.post('drive_restore', { entity_type: it.entity_type, entity_id: it.id })
			.then(load).catch(function (e) { toast(e.message || 'Restore failed.'); });
	}
	function confirmDelete(it) {
		api.post('drive_delete_forever', { entity_type: it.entity_type, entity_id: it.id })
			.then(function (r) {
				var im = r.impact || { files: 0, folders: 0, bytes: 0 };
				$('drvConfirmBody').textContent = 'This permanently deletes ' + im.files + ' file(s) and ' + im.folders + ' folder(s) (' + humanBytes(im.bytes) + '). This cannot be undone.';
				var dlg = $('drvConfirmDialog');
				$('drvConfirmOk').onclick = function () {
					api.post('drive_delete_forever', { entity_type: it.entity_type, entity_id: it.id, confirm: true })
						.then(function () { dlg.close(); load(); })
						.catch(function (e) { toast(e.message || 'Delete failed.'); });
				};
				dlg.showModal();
			})
			.catch(function (e) { toast(e.message || 'Delete failed.'); });
	}

	// ---- dialogs -----------------------------------------------------------
	function dialogForm(dlg) { return dlg.querySelector('form'); }

	// Take over a FormWriter dialog form's submission. The form's
	// JoineryValidator owns the submit event and re-submits natively via
	// form.submit() when valid (a full-page POST) — so the handler must be
	// installed as its submitHandler, not as a second submit listener that the
	// native re-submit would navigate over. The listener path is the fallback
	// for a form without a validator.
	function interceptSubmit(form, handler) {
		if (!form) return;
		var wrapped = function (e) { if (e && e.preventDefault) e.preventDefault(); handler(e || { preventDefault: function () {} }); };
		var attach = function () {
			if (form.joineryValidator) {
				form.joineryValidator.submitHandler = function () { wrapped(null); };
			} else {
				form.addEventListener('submit', wrapped);
			}
		};
		// This script is deferred, so it runs before the FormWriter inline
		// scripts' DOMContentLoaded handlers create the validators — wait for
		// them, or the takeover would land on a form whose validator then
		// native-submits right past it.
		if (form.joineryValidator || document.readyState === 'complete') attach();
		else document.addEventListener('DOMContentLoaded', attach);
	}

	function openNewFolder() {
		var dlg = $('drvNewFolderDialog');
		$('drvNewFolderName').value = '';
		// A protected tree is a top-level tree, so the level is chosen once, at
		// the root. Inside any folder the new subfolder inherits its parent's
		// level and there is nothing to ask (the server refuses anything else).
		var wrap = $('drvNewFolderLevelWrap');
		var atRoot = (state.view === 'mine' && !state.folderId);
		if (wrap) wrap.hidden = !atRoot;
		if (atRoot) {
			var std = document.querySelector('input[name="drv_new_folder_level"][value="standard"]');
			if (std) std.checked = true;
		}
		dlg.showModal();
		setTimeout(function () { $('drvNewFolderName').focus(); }, 30);
	}
	function newFolderLevel() {
		var picked = document.querySelector('input[name="drv_new_folder_level"]:checked');
		return picked ? picked.value : 'standard';
	}
	function submitNewFolder(e) {
		e.preventDefault();
		var name = $('drvNewFolderName').value.trim();
		if (!name) return;
		var body = { name: name };
		var inFolder = (state.view === 'mine' && state.folderId);
		if (inFolder) body.parent_id = state.folderId;
		// Only the root offers a choice; elsewhere the server inherits it.
		body.protection_level = inFolder ? (state.folderLevel || 'standard') : newFolderLevel();
		var proceed = function () {
			api.post('drive_folder_create', body)
				.then(function () { $('drvNewFolderDialog').close(); load(); })
				.catch(function (e) { toast(e.message || 'Could not create folder.'); });
		};
		// Either protected level needs the owner's vault to exist first — Fortress
		// so the browser has a key, Private so the server has one to seal to.
		if (body.protection_level !== 'standard') {
			ensureUnlocked().then(proceed).catch(function (e) { toast(e.message || 'Vault unlock needed.'); });
		} else {
			proceed();
		}
	}

	// ---- protection level --------------------------------------------------
	// Changing a folder's level is two steps on purpose: the promise changes at
	// once (everything uploaded from here on lands at the new level), and the
	// files already inside are converted afterwards, in bounded batches, with a
	// progress row that says where it is. The batch loop is the shared one every
	// converge-afterwards change uses (assets/js/ceremony-batch.js).
	var protectionTarget = null;
	// Whether the owner has already been shown, and accepted, what going Private
	// will end. It lives with the DIALOG, not with a request body: the server
	// answers `needs_confirmation` to the first Apply and the owner's answer is
	// the SECOND Apply, which builds a fresh request. Anything recorded on the
	// first request's body is gone by then.
	var protectionConfirmed = false;
	function openProtection(it) {
		protectionTarget = it;
		protectionConfirmed = false;
		var dlg = $('drvProtectionDialog');
		if (!dlg) return;
		$('drvProtectionFolder').textContent = it.name;
		var current = it.protection_level || 'standard';
		var picked = document.querySelector('input[name="drv_protection_level"][value="' + current + '"]');
		if (picked) picked.checked = true;
		$('drvProtectionProgress').hidden = true;
		$('drvProtectionWarning').hidden = true;
		$('drvProtectionApply').disabled = false;
		dlg.showModal();
	}

	function submitProtection(e) {
		e.preventDefault();
		if (!protectionTarget) return;
		var picked = document.querySelector('input[name="drv_protection_level"]:checked');
		var target = picked ? picked.value : 'standard';
		if (target === (protectionTarget.protection_level || 'standard')) { $('drvProtectionDialog').close(); return; }

		var body = { folder_id: protectionTarget.id, protection_level: target };
		if (protectionConfirmed) body.confirm_revoke_sharing = true;

		$('drvProtectionApply').disabled = true;
		api.post('drive_level_change', body).then(function (d) {
			// The server reports what going Private will end before it ends it.
			if (d && d.needs_confirmation) {
				var warn = $('drvProtectionWarning');
				warn.textContent = (d.blockers || []).map(function (b) { return b.label; }).join(' · ')
					+ ' — apply again to continue.';
				warn.hidden = false;
				$('drvProtectionApply').disabled = false;
				protectionConfirmed = true;
				return;
			}
			startProtectionBatch(protectionTarget.id, d && d.remaining ? d.remaining : 0, target);
		}).catch(function (err) {
			var warn = $('drvProtectionWarning');
			warn.textContent = err.message || 'Could not change the protection level.';
			warn.hidden = false;
			$('drvProtectionApply').disabled = false;
		});
	}

	function startProtectionBatch(folderId, remaining, target) {
		var box = $('drvProtectionProgress');
		box.hidden = false;
		var verb = (target === 'private') ? 'Sealing' : 'Opening';
		box.setAttribute('data-ceremony-batch', JSON.stringify({
			action: 'drive/level_batch',
			payload: { folder_id: folderId },
			remaining: remaining,
			doneKey: 'converted',
			labels: {
				working: verb + ' files — {remaining} to go…',
				done: '{total} file{s:total} converted',
				none: 'No files needed converting',
				stuck: '{remaining} file{s:remaining} could not be converted — they keep their old protection.',
				paused: 'Paused — reopen this folder to resume.'
			}
		}));
		delete box.dataset.ceremonyStarted;
		box.addEventListener('ceremony:done', function () {
			setTimeout(function () { $('drvProtectionDialog').close(); load(); }, 1200);
		}, { once: true });
		if (window.JoineryCeremonyBatch) JoineryCeremonyBatch.run(box);
	}

	var renameTarget = null;
	function openRename(it) {
		renameTarget = it;
		// An encrypted file's real name is the decrypted metadata name, not the
		// opaque fil_title.
		$('drvRenameName').value = (it.entity_type === 'file' && it.encrypted) ? (it._name || '') : it.name;
		$('drvRenameDialog').showModal();
		setTimeout(function () { $('drvRenameName').select(); }, 30);
	}
	function submitRename(e) {
		e.preventDefault();
		if (!renameTarget) return;
		var name = $('drvRenameName').value.trim();
		if (!name) return;
		var it = renameTarget;
		var post;
		if (it.entity_type === 'file' && it.encrypted) {
			// The name lives INSIDE the encrypted metadata: decrypt, swap the
			// name, re-encrypt with the same file key, submit the opaque blob —
			// the plaintext name never reaches the server (which refuses one).
			post = fileKeyFor(it).then(function (entry) {
				entry.meta.name = name; // cache mutates too, so listings show it
				return DC.encryptMetadata(entry.meta, entry.fkKey);
			}).then(function (blob) {
				return api.post('drive_rename', { entity_type: 'file', entity_id: it.id, encrypted_metadata: blob });
			});
		} else {
			post = api.post('drive_rename', { entity_type: it.entity_type, entity_id: it.id, name: name });
		}
		post.then(function () { $('drvRenameDialog').close(); load(); })
			.catch(function (e) { toast(e.message || 'Rename failed.'); });
	}

	var moveTarget = null;
	function openMove(it) {
		moveTarget = it;
		var sel = $('drvMoveParent');
		sel.innerHTML = '<option value="0">My Drive (root)</option>';
		api.post('drive_list', { view: 'folders' }).then(function (r) {
			(r.folders || []).forEach(function (f) {
				if (it.entity_type === 'folder' && f.id === it.id) return; // can't move into self
				var o = document.createElement('option');
				o.value = f.id; o.textContent = f.path;
				sel.appendChild(o);
			});
			$('drvMoveDialog').showModal();
		}).catch(function (e) { toast(e.message || 'Could not load folders.'); });
	}
	function submitMove(e) {
		e.preventDefault();
		if (!moveTarget) return;
		var pid = parseInt($('drvMoveParent').value, 10) || 0;
		api.post('drive_move', { entity_type: moveTarget.entity_type, entity_id: moveTarget.id, parent_id: pid })
			.then(function () { $('drvMoveDialog').close(); load(); })
			.catch(function (e) { toast(e.message || 'Move failed.'); });
	}

	// ---- sharing -----------------------------------------------------------
	var shareTarget = null;
	var shareGrants = []; // current [{user_id, email, name, role}]

	function openShare(it) {
		shareTarget = it;
		$('drvShareTitle').textContent = 'Share "' + it.name + '"';
		$('drvNewLink').hidden = true; $('drvNewLink').innerHTML = '';
		$('drvShareDialog').showModal();
		loadShares();
	}

	function loadShares() {
		// Returned so syncGrants can await the refreshed shareGrants before the
		// key-grant sync reads it — syncing the stale list would skip new
		// grantees' keys and silently re-write removed users' (no revocation).
		return api.post('drive_shares', { entity_type: shareTarget.entity_type, entity_id: shareTarget.id })
			.then(function (r) {
				shareGrants = r.grants || [];
				renderGrants();
				renderLinks(r.links || []);
				// Public links can't carry an encrypted folder's many keys, so the
				// section is hidden for encrypted folders (files are fine — one key
				// rides the URL fragment).
				var noFolderLink = shareTarget.encrypted && shareTarget.entity_type === 'folder';
				$('drvShareLinksSection').hidden = !r.share_links_enabled || noFolderLink;
			})
			.catch(function (e) { toast(e.message || 'Could not load sharing.'); });
	}

	function grantMap(extra) {
		var m = {};
		shareGrants.forEach(function (g) { m[g.user_id] = g.role; });
		if (extra) { for (var k in extra) m[k] = extra[k]; }
		return m;
	}
	function syncGrants(map) {
		return api.post('drive_share_sync', { entity_type: shareTarget.entity_type, entity_id: shareTarget.id, grants: map })
			.then(function (r) {
				if (r && r.skipped && r.skipped.length) {
					toast('No member found for: ' + r.skipped.join(', '));
				}
				return loadShares();
			})
			.then(function () {
				// For an encrypted entity, keep the key grants aligned with access:
				// re-wrap the file key(s) to exactly the current set of grantees.
				if (shareTarget.encrypted) { return syncEncryptedKeys(); }
			})
			.catch(function (e) { toast(e.message || 'Update failed.'); });
	}

	// Re-wrap encrypted file keys to the current grantee set (file, or every
	// encrypted file in a shared folder subtree). The owner's browser unwraps each
	// file key once and seals it to each recipient's drive vault public key.
	async function syncEncryptedKeys() {
		try {
			var session = await ensureUnlocked();
			var granteeIds = shareGrants.map(function (g) { return g.user_id; });

			// Resolve recipients' public keys (members without a Drive vault can't
			// receive encrypted files — surfaced, not silently dropped).
			var pubByUser = {};
			if (granteeIds.length) {
				var pk = await api.post('drive_public_keys', { identifiers: granteeIds });
				var missing = [];
				(pk.keys || []).forEach(function (k) {
					if (k.user_id && k.public_key) pubByUser[k.user_id] = k.public_key;
					else if (k.user_id) missing.push(k.identifier);
				});
				if (missing.length) toast('These members have no Drive vault yet and can\'t open shared encrypted files.');
			}

			// Gather the target encrypted files (a file, or a folder subtree).
			var files = shareTarget.entity_type === 'file'
				? [shareTarget]
				: await collectEncryptedFiles(shareTarget.id);

			var fileKeys = {};
			for (var i = 0; i < files.length; i++) {
				var f = files[i];
				if (!f.encrypted || !f.wrapped_file_key) continue;
				var fkBytes = await session.openSealed(f.wrapped_file_key);
				var perUser = {};
				for (var uid in pubByUser) {
					perUser[uid] = await DC.wrapFileKeyTo(fkBytes, pubByUser[uid]);
				}
				fileKeys[f.id] = perUser; // owner's own key is preserved server-side
			}
			if (Object.keys(fileKeys).length) {
				await api.post('drive_key_grants_sync', { file_keys: fileKeys });
			}
		} catch (e) { toast(e.message || 'Could not update encrypted access.'); }
	}

	// Walk a folder subtree, collecting every encrypted file (with the caller's
	// own wrapped key) via repeated listings, paging past the listing cap with
	// `offset`. Completeness is load-bearing: any file this walk misses would be
	// granted ACCESS by the share sync but no KEY — so on any gap (guard tripped,
	// listing failed) it throws and the caller aborts the whole key sync loudly.
	async function collectEncryptedFiles(rootFolderId) {
		var out = [];
		var queue = [rootFolderId];
		var guard = 0;
		while (queue.length) {
			var fid = queue.shift();
			var offset = 0, more = true;
			while (more) {
				if (++guard > 5000) throw new Error('This folder is too large to sync encrypted access for.');
				var r = await api.post('drive_list', { view: 'mine', folder_id: fid, offset: offset });
				var items = r.items || [];
				items.forEach(function (it) {
					if (it.entity_type === 'folder') queue.push(it.id);
					else if (it.encrypted) out.push(it);
				});
				more = !!r.truncated;
				if (more && !items.length) throw new Error('Could not fully enumerate the folder.');
				offset += items.length;
			}
		}
		return out;
	}

	function renderGrants() {
		var box = $('drvShareGrants');
		box.innerHTML = '';
		if (!shareGrants.length) { box.appendChild(el('div', 'drv-share-empty', 'Not shared with anyone yet.')); return; }
		shareGrants.forEach(function (g) {
			var row = el('div', 'drv-share-row');
			row.appendChild(el('span', '', (g.name && g.name.trim()) ? (g.name + ' (' + g.email + ')') : g.email));
			var right = el('span');
			var sel = document.createElement('select');
			['viewer', 'editor'].forEach(function (r) { var o = document.createElement('option'); o.value = r; o.textContent = r.charAt(0).toUpperCase() + r.slice(1); if (r === g.role) o.selected = true; sel.appendChild(o); });
			sel.onchange = function () { var m = grantMap(); m[g.user_id] = sel.value; syncGrants(m); };
			right.appendChild(sel);
			var rm = el('button', 'drv-link-del', 'Remove');
			rm.type = 'button';
			rm.onclick = function () { var m = grantMap(); delete m[g.user_id]; syncGrants(m); };
			right.appendChild(rm);
			row.appendChild(right);
			box.appendChild(row);
		});
	}

	function renderLinks(links) {
		var box = $('drvShareLinks');
		box.innerHTML = '';
		var live = links.filter(function (l) { return l.live; });
		if (!live.length) { box.appendChild(el('div', 'drv-share-empty', 'No active links.')); return; }
		live.forEach(function (l) {
			var row = el('div', 'drv-share-row');
			row.appendChild(el('span', '', 'Active link · ' + l.access_count + ' view(s)' + (l.has_password ? ' · 🔒' : '') + (l.expires_time ? ' · expires ' + fmtDate(l.expires_time) : '')));
			var rm = el('button', 'drv-link-del', 'Revoke');
			rm.type = 'button';
			rm.onclick = function () { api.post('drive_link_revoke', { link_id: l.link_id }).then(loadShares).catch(function (e) { toast(e.message || 'Revoke failed.'); }); };
			row.appendChild(rm);
			box.appendChild(row);
		});
	}

	function submitAddPerson(e) {
		e.preventDefault();
		var email = $('drvShareEmail').value.trim();
		if (!email) return;
		var role = $('drvShareRole').value || 'viewer';
		var extra = {}; extra[email] = role;
		$('drvShareEmail').value = '';
		syncGrants(grantMap(extra));
	}

	// Raw bytes -> base64url (no padding), for the URL fragment key.
	function bytesToB64url(bytes) {
		var bin = '';
		for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function submitCreateLink(e) {
		e.preventDefault();
		var days = parseInt($('drvLinkExpires').value, 10) || 0;
		var pw = $('drvLinkPw').value;
		var body = { entity_type: shareTarget.entity_type, entity_id: shareTarget.id, expires_days: days };
		if (pw) body.password = pw;

		var mint = function (fragment) {
			api.post('drive_link_create', body).then(function (r) {
				$('drvLinkPw').value = '';
				var nl = $('drvNewLink');
				nl.hidden = false;
				nl.innerHTML = '<div>Link created — copy it now, it won\'t be shown again:</div>';
				var inp = document.createElement('input');
				inp.type = 'text'; inp.readOnly = true;
				inp.value = (r.url || r.path) + (fragment || '');
				inp.onclick = function () { inp.select(); };
				nl.appendChild(inp);
				loadShares();
			}).catch(function (e) { toast(e.message || 'Could not create link.'); });
		};

		// For an encrypted file the link must carry the file key in its fragment
		// (never sent to the server). Unwrap our own key, encode it, append it.
		if (shareTarget.encrypted && shareTarget.entity_type === 'file') {
			ensureUnlocked().then(function (session) {
				return session.openSealed(shareTarget.wrapped_file_key);
			}).then(function (fkBytes) {
				mint('#' + bytesToB64url(fkBytes));
			}).catch(function (e) { toast(e.message || 'Could not prepare the encrypted link.'); });
		} else {
			mint('');
		}
	}

	// ---- version history ---------------------------------------------------
	function openVersions(it) {
		var dlg = $('drvVersionsDialog');
		var body = $('drvVersionsBody');
		body.innerHTML = 'Loading…';
		$('drvVersionsTitle').textContent = 'Version history — ' + it.name;
		dlg.showModal();
		api.post('drive_versions', { file_id: it.id }).then(function (r) {
			body.innerHTML = '';
			var head = el('div', 'drv-version-row');
			head.appendChild(el('span', 'drv-version-label', 'Current (' + humanBytes(it.size) + ')'));
			body.appendChild(head);
			if (!r.versions || !r.versions.length) {
				body.appendChild(el('div', 'drv-version-empty', 'No earlier versions.'));
				return;
			}
			r.versions.forEach(function (v) {
				var row = el('div', 'drv-version-row');
				row.appendChild(el('span', 'drv-version-label', 'v' + v.version_number + ' · ' + humanBytes(v.size) + ' · ' + fmtDate(v.create_time)));
				var b = el('button', 'jy-btn jy-btn-secondary', 'Restore');
				b.type = 'button';
				b.onclick = function () {
					api.post('drive_version_restore', { file_id: it.id, version_id: v.version_id })
						.then(function () { dlg.close(); load(); })
						.catch(function (e) { toast(e.message || 'Restore failed.'); });
				};
				row.appendChild(b);
				body.appendChild(row);
			});
		}).catch(function (e) { body.textContent = e.message || 'Could not load versions.'; });
	}

	// ---- upload (resumable chunk protocol, per-file progress) --------------
	function uploadFiles(files) {
		if (!files || !files.length) return;
		if (!CFG.quotaBytes || !CFG.maxFileBytes) { toast('Uploads are not available on your plan.'); return; }
		Array.prototype.forEach.call(files, function (f) { uploadOne(f); });
	}

	async function sha256Hex(file) {
		if (!(window.crypto && crypto.subtle)) return null;
		try {
			var buf = await file.arrayBuffer();
			var digest = await crypto.subtle.digest('SHA-256', buf);
			return Array.prototype.map.call(new Uint8Array(digest), function (b) {
				return b.toString(16).padStart(2, '0');
			}).join('');
		} catch (e) { return null; }
	}

	async function uploadOne(file) {
		var uploads = $('drvUploads');
		var row = el('div', 'drv-upload-row');
		row.appendChild(el('span', 'drv-upload-name', file.name));
		var bar = el('div', 'drv-upload-bar'); var span = el('span'); bar.appendChild(span); row.appendChild(bar);
		var pct = el('span', 'drv-upload-pct', '0%'); row.appendChild(pct);
		uploads.appendChild(row);
		function progress(f) { var p = Math.round(f * 100); span.style.width = p + '%'; pct.textContent = p + '%'; }
		function fail(msg) { row.classList.add('error'); pct.textContent = msg || 'failed'; }
		function done() { row.classList.add('done'); span.style.width = '100%'; pct.textContent = 'done'; setTimeout(function () { row.remove(); }, 1500); load(); }

		if (file.size > CFG.maxFileBytes) { fail('too large'); return; }

		var encrypted = state.view === 'mine' && state.folderId && state.folderEncrypted;

		try {
			var body;          // upload body (Blob): ciphertext when encrypted, else the file
			var initBody = { size_bytes: file.size, mime_type: file.type || 'application/octet-stream' };
			var completeExtra = {};

			if (encrypted) {
				var session = await ensureUnlocked();
				var packed = await DC.encryptFile(file);
				// Seal the file key to the destination's FULL reader set — the
				// folder owner plus every grantee — so the file lands readable by
				// everyone who can already reach it (an uploader-only key would
				// permanently lock the owner out of a file in their own vault;
				// the server requires the owner's entry).
				var pk = await api.post('drive_public_keys', { folder_id: state.folderId });
				var wrappedKeys = {};
				var haveSelf = false, missingVault = 0;
				var readers = pk.keys || [];
				for (var ri = 0; ri < readers.length; ri++) {
					var rk = readers[ri];
					if (!rk.user_id) continue;
					if (!rk.public_key) { missingVault++; continue; }
					wrappedKeys[rk.user_id] = await DC.wrapFileKeyTo(packed.fkBytes, rk.public_key);
					if (rk.user_id === CFG.userId) haveSelf = true;
				}
				if (!haveSelf) wrappedKeys[CFG.userId] = await session.sealTo(packed.fkBytes);
				if (missingVault) toast('Some members of this folder have no Drive vault yet and can\'t open this file.');
				body = packed.blob;
				initBody.name = 'enc-' + packed.contentId;      // opaque; real name is in metadata
				initBody.size_bytes = body.size;                 // ciphertext size (billed)
				initBody.mime_type = 'application/octet-stream';
				completeExtra.encrypted_metadata = await DC.encryptMetadata(packed.meta, packed.fkKey);
				completeExtra.wrapped_file_keys = wrappedKeys;
				if (packed.thumbB64) completeExtra.encrypted_thumbnail = packed.thumbB64;
			} else {
				body = file;
				initBody.name = file.name;
				// Hash small/medium files client-side to enable the dedup short-circuit.
				var sha = file.size <= 64 * 1024 * 1024 ? await sha256Hex(file) : null;
				if (sha) initBody.sha256 = sha;
			}
			if (state.view === 'mine' && state.folderId) initBody.folder_id = state.folderId;

			var init = await api.post('drive_upload_init', initBody);
			if (init.deduped) { done(); return; }

			var token = init.upload_token, chunkBytes = init.chunk_bytes || 8388608;
			var total = body.size;
			var offset = 0;
			while (offset < total) {
				var end = Math.min(offset + chunkBytes, total);
				var resp = await putChunk(token, body.slice(offset, end), offset, end - 1, total);
				if (resp.status === 409) { var j = await resp.json(); offset = (j.data && j.data.received_bytes) || 0; continue; }
				if (!resp.ok) { fail('chunk failed'); return; }
				var ok = await resp.json();
				offset = ok.data.received_bytes;
				progress(offset / total);
			}
			await api.post('drive_upload_complete', Object.assign({ upload_token: token }, completeExtra));
			done();
		} catch (e) {
			fail((e && e.message) ? e.message : 'failed');
		}
	}

	function putChunk(token, blob, start, end, total) {
		return fetch('/api/v1/drive_upload/' + encodeURIComponent(token), {
			method: 'PUT',
			headers: {
				'X-Joinery-Csrf': api.csrf(),
				'Content-Range': 'bytes ' + start + '-' + end + '/' + total,
				'Content-Type': 'application/octet-stream'
			},
			body: blob
		});
	}

	// ---- nav / view --------------------------------------------------------
	function setActiveNav(view) {
		var btns = document.querySelectorAll('#drvNav .drv-nav-item');
		Array.prototype.forEach.call(btns, function (b) { b.classList.toggle('active', b.dataset.view === view); });
	}
	function switchView(view) {
		state.view = view; state.folderId = 0;
		setActiveNav(view);
		load();
	}
	function toggleViewMode() {
		state.viewMode = state.viewMode === 'grid' ? 'list' : 'grid';
		$('drvViewToggle').textContent = state.viewMode === 'grid' ? 'List' : 'Grid';
		renderItems();
	}

	var searchTimer = null;
	function onSearch() {
		clearTimeout(searchTimer);
		var q = $('drvSearch').value.trim();
		searchTimer = setTimeout(function () {
			if (!q) { load(); return; }
			api.post('drive_list', { search: q }).then(render).catch(function (e) { toast(e.message || 'Search failed.'); });
		}, 300);
	}

	// ---- wiring ------------------------------------------------------------
	function wire() {
		document.querySelectorAll('#drvNav .drv-nav-item').forEach(function (b) {
			b.onclick = function () { switchView(b.dataset.view); };
		});
		$('drvNewFolderBtn').onclick = openNewFolder;
		$('drvUploadBtn').onclick = function () { $('drvFileInput').click(); };
		$('drvFileInput').onchange = function () { uploadFiles(this.files); this.value = ''; };
		$('drvViewToggle').onclick = toggleViewMode;
		$('drvSearch').oninput = onSearch;

		interceptSubmit(dialogForm($('drvNewFolderDialog')), submitNewFolder);
		interceptSubmit(dialogForm($('drvRenameDialog')), submitRename);
		interceptSubmit(dialogForm($('drvMoveDialog')), submitMove);
		interceptSubmit(dialogForm($('drvProtectionDialog')), submitProtection);
		interceptSubmit($('drvShareEmail').closest('form'), submitAddPerson);
		interceptSubmit($('drvLinkExpires').closest('form'), submitCreateLink);
		document.querySelectorAll('.drv-dialog [data-close]').forEach(function (b) {
			b.onclick = function () { b.closest('dialog').close(); };
		});

		// drag & drop
		var dz = $('drvDropzone');
		['dragenter', 'dragover'].forEach(function (ev) {
			dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('dragover'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			dz.addEventListener(ev, function (e) { e.preventDefault(); if (ev === 'drop' || e.target === dz) dz.classList.remove('dragover'); });
		});
		dz.addEventListener('drop', function (e) {
			if (e.dataTransfer && e.dataTransfer.files) uploadFiles(e.dataTransfer.files);
		});

		document.addEventListener('click', function (e) {
			if (!$('drvMenu').contains(e.target)) hideMenu();
		});
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
	}

	function init() {
		if (!api) { console.error('joineryApi missing'); return; }
		wire();
		wireVaultDialog();
		if (window.DRIVE_INITIAL && window.DRIVE_INITIAL.items) {
			render(window.DRIVE_INITIAL);
		} else {
			load();
		}
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
