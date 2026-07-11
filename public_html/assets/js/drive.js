/* Drive — member file storage client. Vanilla JS, talks to /api/v1 drive_*
 * actions through the shared joineryApi transport (browser-session credential). */
(function () {
	'use strict';

	var api = window.joineryApi;
	var CFG = window.DRIVE_CONFIG || {};
	var state = {
		view: 'mine',
		folderId: 0,
		viewMode: 'list',
		items: [],
		breadcrumb: [],
		usage: null
	};

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

	// ---- rendering ---------------------------------------------------------
	function render(data) {
		state.items = data.items || [];
		state.breadcrumb = data.breadcrumb || [];
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
	}

	function renderItem(it) {
		var row = el('div', 'drv-item');
		row.setAttribute('role', 'listitem');
		row.dataset.type = it.entity_type;
		row.dataset.id = it.id;

		var icon = el('div', 'drv-item-icon');
		if (it.entity_type === 'file' && it.thumb_url) {
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

		var name = el('div', 'drv-item-name', it.name);
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
		} else {
			if (it.download_url) window.open(it.download_url, '_blank');
		}
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
			if (it.entity_type === 'file') opts.push(['Download', function () { if (it.download_url) window.open(it.download_url, '_blank'); }]);
			opts.push(['Rename', function () { openRename(it); }]);
			opts.push(['Move to…', function () { openMove(it); }]);
			if (it.entity_type === 'file') opts.push([it.starred ? 'Unstar' : 'Star', function () { toggleStar(it, {classList:{toggle:function(){}}}); }]);
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

	function openNewFolder() {
		var dlg = $('drvNewFolderDialog');
		$('drvNewFolderName').value = '';
		dlg.showModal();
		setTimeout(function () { $('drvNewFolderName').focus(); }, 30);
	}
	function submitNewFolder(e) {
		e.preventDefault();
		var name = $('drvNewFolderName').value.trim();
		if (!name) return;
		var body = { name: name };
		if (state.view === 'mine' && state.folderId) body.parent_id = state.folderId;
		api.post('drive_folder_create', body)
			.then(function () { $('drvNewFolderDialog').close(); load(); })
			.catch(function (e) { toast(e.message || 'Could not create folder.'); });
	}

	var renameTarget = null;
	function openRename(it) {
		renameTarget = it;
		$('drvRenameName').value = it.name;
		$('drvRenameDialog').showModal();
		setTimeout(function () { $('drvRenameName').select(); }, 30);
	}
	function submitRename(e) {
		e.preventDefault();
		if (!renameTarget) return;
		var name = $('drvRenameName').value.trim();
		if (!name) return;
		api.post('drive_rename', { entity_type: renameTarget.entity_type, entity_id: renameTarget.id, name: name })
			.then(function () { $('drvRenameDialog').close(); load(); })
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
		api.post('drive_shares', { entity_type: shareTarget.entity_type, entity_id: shareTarget.id })
			.then(function (r) {
				shareGrants = r.grants || [];
				renderGrants();
				renderLinks(r.links || []);
				$('drvShareLinksSection').hidden = !r.share_links_enabled;
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
			}).catch(function (e) { toast(e.message || 'Update failed.'); });
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

	function submitCreateLink(e) {
		e.preventDefault();
		var days = parseInt($('drvLinkExpires').value, 10) || 0;
		var pw = $('drvLinkPw').value;
		var body = { entity_type: shareTarget.entity_type, entity_id: shareTarget.id, expires_days: days };
		if (pw) body.password = pw;
		api.post('drive_link_create', body).then(function (r) {
			$('drvLinkPw').value = '';
			var nl = $('drvNewLink');
			nl.hidden = false;
			nl.innerHTML = '<div>Link created — copy it now, it won\'t be shown again:</div>';
			var inp = document.createElement('input');
			inp.type = 'text'; inp.readOnly = true; inp.value = r.url || r.path;
			inp.onclick = function () { inp.select(); };
			nl.appendChild(inp);
			loadShares();
		}).catch(function (e) { toast(e.message || 'Could not create link.'); });
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

		try {
			// Hash small/medium files client-side to enable the dedup short-circuit.
			var sha = file.size <= 64 * 1024 * 1024 ? await sha256Hex(file) : null;
			var initBody = { name: file.name, size_bytes: file.size, mime_type: file.type || 'application/octet-stream' };
			if (sha) initBody.sha256 = sha;
			if (state.view === 'mine' && state.folderId) initBody.folder_id = state.folderId;

			var init = await api.post('drive_upload_init', initBody);
			if (init.deduped) { done(); return; }

			var token = init.upload_token, chunkBytes = init.chunk_bytes || 8388608;
			var offset = 0;
			while (offset < file.size) {
				var end = Math.min(offset + chunkBytes, file.size);
				var resp = await putChunk(token, file.slice(offset, end), offset, end - 1, file.size);
				if (resp.status === 409) { var j = await resp.json(); offset = (j.data && j.data.received_bytes) || 0; continue; }
				if (!resp.ok) { fail('chunk failed'); return; }
				var ok = await resp.json();
				offset = ok.data.received_bytes;
				progress(offset / file.size);
			}
			await api.post('drive_upload_complete', { upload_token: token });
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

		dialogForm($('drvNewFolderDialog')).addEventListener('submit', submitNewFolder);
		dialogForm($('drvRenameDialog')).addEventListener('submit', submitRename);
		dialogForm($('drvMoveDialog')).addEventListener('submit', submitMove);
		$('drvShareEmail').closest('form').addEventListener('submit', submitAddPerson);
		$('drvLinkExpires').closest('form').addEventListener('submit', submitCreateLink);
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
		if (window.DRIVE_INITIAL && window.DRIVE_INITIAL.items) {
			render(window.DRIVE_INITIAL);
		} else {
			load();
		}
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
