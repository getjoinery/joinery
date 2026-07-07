/*
 * Mailbox Reader — vanilla-JS Gmail-style inbox over the scoped AJAX endpoints.
 * No framework. @version 2.10
 *
 * Two-pane layout: the main pane swaps between the conversation list and an
 * opened conversation (toggled by the `reading` class on #mbx-reader); a back
 * arrow returns to the list. Visibility is enforced server-side
 * (MailboxViewer/MailboxService); this client only renders what the endpoints
 * return and never decides access.
 */
(function () {
	'use strict';

	var CFG = window.MAILBOX_READER || {};

	var state = {
		aliasId: null,        // null = all accessible (or "All mail" for superadmin)
		allAccess: false,
		filter: 'all',        // retained for the list endpoint; sectioned view shows all
		lastSection: null,    // last section header emitted (for sectioned rendering)
		search: '',
		page: 1,
		hasMore: false,
		threadKey: null,
		folderId: null,       // null = folder-unfiltered (the mailbox's "All Mail")
		inboxView: true,      // the Inbox view (non-archived); the default landing view
		spamView: false,      // the Spam pseudo-folder (judged-spam, hidden from inbox)
		mailboxLabel: '',     // the active mailbox label, for composing folder titles
		mailboxes: [],
		messages: []      // messages of the currently-open thread
	};

	// ---- tiny DOM helpers ----
	function $(sel, root) { return (root || document).querySelector(sel); }
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}
	function fmtTime(iso) {
		if (!iso) return '';
		// DB times are UTC ISO strings; show a compact local time.
		var d = new Date(iso.replace(' ', 'T') + 'Z');
		if (isNaN(d.getTime())) return iso;
		var now = new Date();
		var opts = (d.toDateString() === now.toDateString())
			? { hour: 'numeric', minute: '2-digit' }
			: { month: 'short', day: 'numeric' };
		return d.toLocaleString([], opts);
	}

	function apiGet(url) {
		return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
			.then(function (r) { return r.json(); });
	}
	function apiAction(payload) {
		var body = new URLSearchParams();
		body.set('_csrf_token', CFG.csrf || '');
		body.set('action', payload.action);
		if (payload.aliasId != null) body.set('alias_id', String(payload.aliasId));
		if (payload.threadKey != null) body.set('thread_key', payload.threadKey);
		if (payload.ids) payload.ids.forEach(function (id) { body.append('ids[]', String(id)); });
		if (payload.folderId != null) body.set('folder_id', String(payload.folderId));
		if (payload.present != null) body.set('present', payload.present ? '1' : '0');
		if (payload.name != null) body.set('name', String(payload.name));
		return fetch(CFG.actionUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-CSRF-Token': CFG.csrf || ''
			},
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	// ---- mailbox switcher (left rail) ----
	function renderMailboxes(data) {
		state.allAccess = !!data.all_access;
		state.mailboxes = data.mailboxes || [];
		var list = $('#mbx-mailboxes');
		list.innerHTML = '';

		state.mailboxes.forEach(function (m) {
			list.appendChild(mailboxItem(m.address, m.alias_id, m.unread, m.folders));
		});
		if (state.allAccess && data.unmatched && data.unmatched.total > 0) {
			var li = mailboxItem('Unmatched', 'unmatched', data.unmatched.unread, []);
			li.title = 'Unrouted mail that matched no mailbox';
			list.appendChild(li);
		}

		// Highlight current selection + render the active mailbox's folder rail.
		highlightMailbox();
		renderFolderRail();

		// New message is only ever composable when the viewer has at least one
		// accessible mailbox to send as (canCompose, mirrored client-side).
		var newBtn = $('#mbx-new-message');
		if (newBtn) newBtn.hidden = !state.mailboxes.length;
	}

	function mailboxItem(label, aliasId, unread, folders) {
		var li = el('li', 'mbx-mailbox');
		li.dataset.alias = (aliasId == null ? '' : String(aliasId));
		li._folders = folders || [];
		var addr = el('span', 'mbx-mailbox-addr', label);
		li.appendChild(addr);
		var badge = el('span', 'mbx-badge' + (unread ? '' : ' zero'), String(unread || 0));
		li.appendChild(badge);
		li.addEventListener('click', function () { selectMailbox(aliasId, label); });
		return li;
	}

	// ---- folder rail (membership-driven, under the active mailbox) ----
	function activeMailboxLi() {
		var cur = (state.aliasId == null ? '' : String(state.aliasId));
		var hit = null;
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-mailbox'), function (li) {
			if (li.dataset.alias === cur) hit = li;
		});
		return hit;
	}

	function renderFolderRail() {
		// Remove any prior rail, then render the active mailbox's rail: "All Mail"
		// (folder-unfiltered root), any tracked IMAP folders, and the "Spam" view.
		// The Spam view is always present — it reads the verdict, not folder
		// membership, so it works for local and IMAP mailboxes alike.
		var prior = $('#mbx-folder-rail');
		if (prior) prior.parentNode.removeChild(prior);

		var li = activeMailboxLi();
		if (!li) return;
		var folders = li._folders || [];

		var ul = el('ul', 'mbx-folders');
		ul.id = 'mbx-folder-rail';
		// Inbox (non-archived) is the default; All Mail shows everything, archived
		// included. Tracked IMAP folders and the Spam view follow.
		ul.appendChild(folderItem('inbox', 'Inbox'));
		ul.appendChild(folderItem(null, 'All Mail'));
		folders.forEach(function (f) { ul.appendChild(folderItem(f.id, f.name)); });
		ul.appendChild(folderItem('spam', 'Spam'));
		li.parentNode.insertBefore(ul, li.nextSibling);
		highlightFolder();
	}

	function folderItem(folderId, name) {
		var li = el('li', 'mbx-folder');
		li.dataset.folder = (folderId == null ? '' : String(folderId));
		li.appendChild(el('span', 'mbx-folder-name', name));
		li.addEventListener('click', function (e) {
			e.stopPropagation();
			selectFolder(folderId, name);
		});
		return li;
	}

	function highlightFolder() {
		var cur = state.spamView ? 'spam'
			: state.inboxView ? 'inbox'
			: (state.folderId == null ? '' : String(state.folderId));
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-folder'), function (li) {
			li.classList.toggle('active', li.dataset.folder === cur);
		});
	}

	function selectFolder(folderId, name) {
		closeThread();                    // leave any open conversation → show the list
		state.inboxView = false;
		state.spamView = false;
		if (folderId === 'inbox') {
			state.inboxView = true;
			state.folderId = null;
		} else if (folderId === 'spam') {
			state.spamView = true;
			state.folderId = null;
		} else {
			state.folderId = folderId;    // null = All Mail; a number = a tracked folder
		}
		// Inbox is the mailbox's default, so its title is just the mailbox; the other
		// views append their name.
		$('#mbx-list-title').textContent = (state.mailboxLabel || 'All mail')
			+ (state.inboxView ? '' : ' / ' + (name || 'All Mail'));
		highlightFolder();
		loadThreads(true);
	}

	function highlightMailbox() {
		var cur = (state.aliasId == null ? '' : String(state.aliasId));
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-mailbox'), function (li) {
			if (li.dataset.alias === undefined) return;
			li.classList.toggle('active', li.dataset.alias === cur && li.style.cursor !== 'default');
		});
	}

	// Remember the last-opened mailbox so a page refresh returns to it instead of
	// snapping to whatever sorts first (the rail order shifts as unread changes).
	var LAST_MAILBOX_KEY = 'mbx.lastAlias';
	function rememberMailbox(aliasId) {
		try { window.localStorage.setItem(LAST_MAILBOX_KEY, String(aliasId)); } catch (e) {}
	}
	function recallMailbox() {
		try { return window.localStorage.getItem(LAST_MAILBOX_KEY); } catch (e) { return null; }
	}

	function selectMailbox(aliasId, label) {
		closeThread();                    // leave any open conversation → show the list
		rememberMailbox(aliasId);
		state.aliasId = aliasId;
		state.folderId = null;            // reset to the folder-unfiltered view
		state.inboxView = true;           // default to the Inbox (non-archived) view
		state.spamView = false;
		state.mailboxLabel = label || 'All mail';
		$('#mbx-list-title').textContent = state.mailboxLabel;
		highlightMailbox();
		renderFolderRail();
		loadThreads(true);
	}

	function refreshMailboxes() {
		return apiGet(CFG.mailboxesUrl).then(renderMailboxes);
	}

	// ---- thread list (center) ----
	function buildListQuery() {
		var p = new URLSearchParams();
		if (state.aliasId != null) p.set('alias_id', String(state.aliasId));
		if (state.filter === 'unread') p.set('unread_only', '1');
		if (state.filter === 'starred') p.set('starred_only', '1');
		if (state.search) { p.set('q', state.search); }
		if (state.spamView) { p.set('spam', '1'); }
		else if (state.folderId != null) { p.set('folder_id', String(state.folderId)); }
		else if (state.inboxView) { p.set('inbox', '1'); }
		p.set('page', String(state.page));
		return CFG.listUrl + '?' + p.toString();
	}

	// Gmail-style section labels, keyed by the server-provided `section` bucket.
	var SECTION_LABELS = { unread: 'Unread', starred: 'Starred', other: 'Everything else' };

	function loadThreads(reset) {
		if (reset) { state.page = 1; state.lastSection = null; }
		var listEl = $('#mbx-threads');
		if (reset) { listEl.innerHTML = ''; listEl.appendChild(loadingRow()); }
		apiGet(buildListQuery()).then(function (data) {
			if (reset) { listEl.innerHTML = ''; state.lastSection = null; }
			(data.threads || []).forEach(function (t) {
				// The list arrives ordered by section, so emit a header each time the
				// bucket changes — works seamlessly across paginated "Load more" calls.
				var section = t.section || 'other';
				if (section !== state.lastSection) {
					listEl.appendChild(sectionHeader(section));
					state.lastSection = section;
				}
				listEl.appendChild(threadRow(t));
			});
			if (!listEl.children.length) {
				listEl.appendChild(emptyRow('No conversations.'));
			}
			state.hasMore = !!data.has_more;
			$('#mbx-more').hidden = !state.hasMore;
		});
	}

	function sectionHeader(section) {
		return el('li', 'mbx-section', SECTION_LABELS[section] || section);
	}

	function loadingRow() { var li = el('li', 'mbx-loading', 'Loading…'); return li; }
	function emptyRow(text) { var li = el('li', 'mbx-loading', text); return li; }

	// Pull a human display name from a "Name <addr>" / bare-address sender string,
	// hiding the email address. Falls back to the local-part when there's no name.
	function senderName(raw) {
		if (!raw) return '(unknown)';
		raw = String(raw).trim();
		var m = /^\s*"?([^"<]*?)"?\s*<[^>]+>\s*$/.exec(raw);
		if (m && m[1].trim()) return m[1].trim();
		// Bare address (or no display name): show the local-part, address hidden.
		var at = raw.indexOf('@');
		if (at > 0) return raw.slice(0, at);
		return raw.replace(/[<>]/g, '').trim() || '(unknown)';
	}

	function threadRow(t) {
		var li = el('li', 'mbx-thread-item' + (t.unread_count > 0 ? ' unread' : ''));
		li.dataset.threadKey = t.thread_key;

		var star = el('span', 'mbx-thread-star' + (t.any_starred ? ' on' : ''), '★');
		star.title = t.any_starred ? 'Unstar' : 'Star';
		star.addEventListener('click', function (e) {
			e.stopPropagation();
			var turnOn = !t.any_starred;
			apiAction({ action: turnOn ? 'star' : 'unstar', threadKey: t.thread_key, aliasId: state.aliasId })
				.then(function () { t.any_starred = turnOn; star.classList.toggle('on', turnOn); refreshMailboxes(); });
		});
		li.appendChild(star);

		// Sender name (address hidden), fixed left column.
		var from = el('span', 'mbx-thread-from', senderName(t.sender || t.senders));
		from.title = t.senders || '';
		li.appendChild(from);

		// Subject + snippet share one clipped line: "Subject — preview text…".
		var mid = el('div', 'mbx-thread-mid');
		var subj = el('span', 'mbx-thread-subject', t.subject || '(no subject)');
		mid.appendChild(subj);
		if (t.msg_count > 1) {
			mid.appendChild(el('span', 'mbx-thread-count', String(t.msg_count)));
		}
		// AI security scan badge (specs/joinery_ai_email_security_scan.md):
		// silent below 3 -- an unremarkable inbox is the common case.
		if (t.danger_score !== null && t.danger_score !== undefined && t.danger_score >= 3) {
			var tier = t.danger_score >= 7 ? 'red' : 'amber';
			mid.appendChild(el('span', 'mbx-danger-badge ' + tier, 'Danger ' + t.danger_score + '/10'));
		}
		// AI triage summary (specs/implemented/joinery_ai_email_triage.md) replaces
		// the body snippet as the preview when the message has been triaged -- it
		// is the better snippet, so the two never stack.
		var preview = t.ai_summary || t.snippet;
		if (preview) {
			var previewCls = t.ai_summary ? 'mbx-thread-snippet mbx-thread-ai' : 'mbx-thread-snippet';
			var span = el('span', previewCls, ' — ' + preview);
			if (t.ai_summary) { span.title = 'AI summary'; }
			mid.appendChild(span);
		}
		li.appendChild(mid);

		li.appendChild(el('span', 'mbx-thread-time', fmtTime(t.latest_time)));

		li.addEventListener('click', function () { openThread(t, li); });
		return li;
	}

	// ---- reading pane (right) ----
	function openThread(t, rowEl) {
		state.threadKey = t.thread_key;
		state.openThread = t;
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-thread-item'), function (n) {
			n.classList.remove('active');
		});
		if (rowEl) rowEl.classList.add('active');
		$('#mbx-reader').classList.add('reading');
		$('#mbx-read-pane').scrollTop = 0;

		var pane = $('#mbx-thread');
		pane.innerHTML = '<div class="mbx-loading">Loading…</div>';

		var url = CFG.threadUrl + '?thread_key=' + encodeURIComponent(t.thread_key)
			+ (state.aliasId != null ? '&alias_id=' + encodeURIComponent(state.aliasId) : '');
		apiGet(url).then(function (data) {
			renderThread(t, data.messages || [], data.folders || []);
			// Opening marks the whole thread read (shared per mailbox).
			if (t.unread_count > 0) {
				apiAction({ action: 'mark_read', threadKey: t.thread_key, aliasId: state.aliasId })
					.then(function () {
						if (rowEl) { rowEl.classList.remove('unread'); }
						t.unread_count = 0;
						refreshMailboxes();
					});
			}
		});
	}

	// The tracked folders + cardinality for a mailbox (by alias id), from the
	// switcher data. Returns { folders:[{id,name,role}], exclusive:bool }.
	function mailboxFolders(aliasId) {
		var hit = state.mailboxes.filter(function (m) { return String(m.alias_id) === String(aliasId); })[0];
		if (!hit) return { folders: [], exclusive: true };
		return { folders: hit.folders || [], exclusive: !!hit.folders_exclusive };
	}

	function renderThread(t, messages, threadFolders) {
		state.messages = messages || [];
		state.threadFolders = threadFolders || [];
		var pane = $('#mbx-thread');
		parkCompose();        // move the compose box out before clearing the pane
		pane.innerHTML = '';

		var header = el('div', 'mbx-thread-header');

		var back = el('button', 'mbx-thread-back', null);
		back.type = 'button';
		back.appendChild(el('span', 'mbx-back-arrow', '←'));
		back.appendChild(el('span', null, 'Back to list'));
		back.addEventListener('click', closeThread);
		header.appendChild(back);

		header.appendChild(el('h1', null, t.subject || '(no subject)'));

		var actions = el('div', 'mbx-thread-actions');
		actions.appendChild(actionBtn('Mark unread', false, function () {
			apiAction({ action: 'mark_unread', threadKey: t.thread_key, aliasId: state.aliasId })
				.then(function () { refreshMailboxes(); loadThreads(true); });
		}));
		actions.appendChild(actionBtn(t.any_starred ? 'Unstar' : 'Star', false, function () {
			var turnOn = !t.any_starred;
			apiAction({ action: turnOn ? 'star' : 'unstar', threadKey: t.thread_key, aliasId: state.aliasId })
				.then(function () { t.any_starred = turnOn; refreshMailboxes(); loadThreads(true); });
		}));
		actions.appendChild(actionBtn('Delete', true, function () {
			if (!confirm('Delete this conversation?')) return;
			apiAction({ action: 'delete', threadKey: t.thread_key, aliasId: state.aliasId })
				.then(function () { closeThread(); refreshMailboxes(); loadThreads(true); });
		}));
		// Archive ("Skip the Inbox") / Move to Inbox — symmetric with star/spam, which
		// also have manual + filter-driven paths. Hidden in the Spam view (a spam
		// message archives nowhere useful).
		if (!state.spamView) {
			var archived = !!t.any_archived;
			actions.appendChild(actionBtn(archived ? 'Move to Inbox' : 'Archive', false, function () {
				apiAction({ action: archived ? 'unarchive' : 'archive', threadKey: t.thread_key, aliasId: state.aliasId })
					.then(function () { t.any_archived = !archived; closeThread(); refreshMailboxes(); loadThreads(true); });
			}));
		}
		// Spam correction: in the Spam view, restore to the inbox; elsewhere, mark spam.
		actions.appendChild(actionBtn(state.spamView ? 'Not spam' : 'Mark as spam', false, function () {
			apiAction({ action: state.spamView ? 'mark_not_spam' : 'mark_spam',
				threadKey: t.thread_key, aliasId: state.aliasId })
				.then(function () { closeThread(); refreshMailboxes(); loadThreads(true); });
		}));
		// Move (exclusive feed) / Labels (non-exclusive) — drives membership sync.
		var threadAlias = null;
		for (var mi = 0; mi < messages.length; mi++) {
			if (messages[mi].alias_id != null) { threadAlias = messages[mi].alias_id; break; }
		}
		var folderCtl = threadAlias != null ? buildFolderControl(t, threadAlias) : null;
		if (folderCtl) actions.appendChild(folderCtl);
		header.appendChild(actions);
		pane.appendChild(header);

		messages.forEach(function (m, idx) {
			pane.appendChild(messageBlock(m, idx === messages.length - 1));
		});

		// Gmail-style: Reply / Reply All / Forward chips at the bottom of the
		// conversation. They act on the latest message and only show for a real
		// mailbox (not the superadmin "Unmatched" view).
		var latest = lastInboundOrLast(messages);
		if (latest && latest.alias_id != null) {
			var chips = el('div', 'mbx-reply-actions');
			chips.appendChild(replyChip('↩ Reply', function () { openCompose('reply', t, latest); }));
			chips.appendChild(replyChip('↩ Reply All', function () { openCompose('reply_all', t, latest); }));
			chips.appendChild(replyChip('↪ Forward', function () { openCompose('forward', t, latest); }));
			pane.appendChild(chips);
		}

		// Move the single (hidden) compose box to the bottom of this conversation,
		// so it opens inline beneath the chips like Gmail.
		var compose = document.getElementById('mbx-compose');
		if (compose) { compose.hidden = true; pane.appendChild(compose); }
	}

	function replyChip(label, onClick) {
		var b = el('button', 'mbx-reply-btn', label);
		b.type = 'button';
		b.addEventListener('click', onClick);
		return b;
	}

	function actionBtn(label, danger, onClick) {
		var b = el('button', 'mbx-action' + (danger ? ' danger' : ''), label);
		b.type = 'button';
		b.addEventListener('click', onClick);
		return b;
	}

	/**
	 * Build the Move/Labels control for the open thread. Exclusive feeds get a
	 * single-pick "Move ▾" (choosing a folder relocates the thread); non-exclusive
	 * feeds (Gmail) get "Labels ▾" with a checkbox per folder (toggling adds/removes
	 * the label). Each change calls set_membership; two-way sync pushes it upstream.
	 * Returns null when the mailbox has no tracked folders.
	 */
	function buildFolderControl(t, aliasId) {
		var info = mailboxFolders(aliasId);
		if (!info.folders.length) return null;

		var wrap = el('div', 'mbx-folder-ctl');
		var btn = el('button', 'mbx-action', info.exclusive ? 'Move ▾' : 'Labels ▾');
		btn.type = 'button';
		var panel = el('div', 'mbx-folder-panel');
		panel.hidden = true;

		var current = {};
		(state.threadFolders || []).forEach(function (id) { current[String(id)] = true; });

		info.folders.forEach(function (f) {
			if (info.exclusive) {
				var item = el('div', 'mbx-folder-opt' + (current[String(f.id)] ? ' current' : ''), f.name);
				item.addEventListener('click', function () {
					apiAction({ action: 'set_membership', threadKey: t.thread_key, aliasId: state.aliasId,
						folderId: f.id, present: true })
						.then(function () { panel.hidden = true; closeThread(); refreshMailboxes(); loadThreads(true); });
				});
				panel.appendChild(item);
			} else {
				var lab = el('label', 'mbx-folder-opt');
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.checked = !!current[String(f.id)];
				cb.addEventListener('change', function () {
					apiAction({ action: 'set_membership', threadKey: t.thread_key, aliasId: state.aliasId,
						folderId: f.id, present: cb.checked })
						.then(function () {
							current[String(f.id)] = cb.checked;
							refreshMailboxes();
							if (state.folderId != null) { loadThreads(true); } // a filtered view may change
						});
				});
				lab.appendChild(cb);
				lab.appendChild(el('span', null, ' ' + f.name));
				panel.appendChild(lab);
			}
		});

		// "New label / New folder" — create locally; the sync push creates it on the
		// source (CREATE) and files the thread into it.
		var newRow = el('div', 'mbx-folder-newrow');
		var input = document.createElement('input');
		input.type = 'text';
		input.placeholder = info.exclusive ? 'New folder…' : 'New label…';
		input.className = 'mbx-folder-newinput';
		var addBtn = el('button', 'mbx-folder-newbtn', '+');
		addBtn.type = 'button';
		var submit = function () {
			var name = input.value.trim();
			if (name === '') { input.focus(); return; }
			addBtn.disabled = true;
			apiAction({ action: 'create_folder', threadKey: t.thread_key, aliasId: aliasId, name: name })
				.then(function (resp) {
					addBtn.disabled = false;
					if (!resp || !resp.folder) { alert('Could not create the label.'); return; }
					input.value = '';
					panel.hidden = true;
					// Refresh the switcher (new folder in the rail) and re-open the thread
					// so the control rebuilds with the new label checked.
					refreshMailboxes().then(function () { openThread(t); });
				});
		};
		addBtn.addEventListener('click', submit);
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
		newRow.appendChild(input);
		newRow.appendChild(addBtn);
		panel.appendChild(newRow);

		// Toggle open; stopPropagation so the document handler (which dismisses open
		// panels) doesn't immediately re-close it. Clicks inside the panel are
		// likewise contained so ticking labels / typing a new name keeps it open —
		// it closes on an outside click or Esc, like the kebab menu.
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var willOpen = panel.hidden;
			closeAllFolderPanels();
			closeAllKebabs();
			panel.hidden = !willOpen;
		});
		panel.addEventListener('click', function (e) { e.stopPropagation(); });
		wrap.appendChild(btn);
		wrap.appendChild(panel);
		return wrap;
	}

	function closeAllFolderPanels() {
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-folder-panel'), function (p) {
			p.hidden = true;
		});
	}

	// SPF/DKIM/DMARC verdicts are READ from the message's Authentication-Results
	// header (auth_source 'milter'/'mailgun'), never computed. Without a
	// verifying milter the message is honestly "unverified".
	function authText(m) {
		var base;
		if (m.auth_source === 'milter' || m.auth_source === 'mailgun') {
			base = 'SPF ' + (m.spf_result || 'none')
				+ ' · DKIM ' + (m.dkim_result || 'none')
				+ ' · DMARC ' + (m.dmarc_result || 'none');
		} else {
			base = 'Authentication: unverified (no verifying milter)';
		}
		// Content-spam score (specs/inbound_email_content_spam_filtering.md): shown for
		// transparency when the scanner reported one; never affects disposition.
		if (m.spam_score !== null && m.spam_score !== undefined) {
			base += ' · spam score ' + m.spam_score;
		}
		return base;
	}

	// AI security scan (specs/joinery_ai_email_security_scan.md). ai_scan is
	// null until a pipeline recipe judges the message. verdict maps directly
	// to the CSS tier (safe/suspicious/dangerous); the safe tier renders as a
	// small, low-key line rather than a full alert box -- a clean verdict is
	// reassurance, not a warning.
	function dangerBanner(m) {
		if (m.ai_danger_score === null || m.ai_danger_score === undefined || !m.ai_scan) return null;
		var scan = m.ai_scan;
		var tier = scan.verdict || (m.ai_danger_score >= 7 ? 'dangerous' : (m.ai_danger_score >= 3 ? 'suspicious' : 'safe'));

		var banner = el('div', 'mbx-danger-banner ' + tier);
		banner.appendChild(el('div', 'mbx-danger-banner-head', 'Danger score: ' + m.ai_danger_score + '/10'));
		if (scan.summary) banner.appendChild(el('div', null, scan.summary));
		if (tier !== 'safe' && Array.isArray(scan.red_flags) && scan.red_flags.length) {
			var list = el('ul', 'mbx-danger-banner-flags');
			scan.red_flags.forEach(function (flag) {
				list.appendChild(el('li', null, flag.finding || ''));
			});
			banner.appendChild(list);
		}
		return banner;
	}

	function messageBlock(m, expanded) {
		var outbound = (m.direction === 'outbound');
		var wrap = el('div', 'mbx-message' + (outbound ? ' mbx-outbound' : '') + (expanded ? '' : ' mbx-collapsed'));

		var head = el('div', 'mbx-message-head');
		var left = el('div');
		var from = el('div', 'mbx-message-from', m.sender || '(unknown)');
		if (outbound) from.appendChild(el('span', 'mbx-sent-tag', 'Sent'));
		left.appendChild(from);
		left.appendChild(el('div', 'mbx-message-meta', 'to ' + (m.recipient || '')));
		if (!outbound) left.appendChild(el('div', 'mbx-message-meta', authText(m)));
		head.appendChild(left);

		var right = el('div', 'mbx-message-right');
		right.appendChild(el('span', 'mbx-message-time', fmtTime(m.received_time)));
		// The kebab holds only detail-page deep links; the member mount has no
		// detail page (messageDetailBase null), so it renders no kebab at all.
		if (CFG.messageDetailBase) right.appendChild(kebabMenu(m));
		head.appendChild(right);

		head.addEventListener('click', function () { wrap.classList.toggle('mbx-collapsed'); });
		wrap.appendChild(head);

		// AI security scan banner (specs/joinery_ai_email_security_scan.md) --
		// a sibling of the body (not inside it), so it stays visible even when
		// the message is collapsed.
		var banner = dangerBanner(m);
		if (banner) wrap.appendChild(banner);

		var body = el('div', 'mbx-message-body');
		if (m.body_html) {
			// Render the sender-authored (untrusted) HTML in a fully locked-down
			// iframe: empty sandbox grants nothing, so no scripts run and the frame
			// can't reach the session or the surrounding page.
			var iframe = document.createElement('iframe');
			iframe.setAttribute('sandbox', '');
			iframe.setAttribute('srcdoc', m.body_html);
			body.appendChild(iframe);
		} else if (m.body_plain) {
			body.appendChild(el('pre', null, m.body_plain));
		} else {
			body.appendChild(el('em', null, 'No text body. Use the ⋮ menu → View raw / .eml.'));
		}

		wrap.appendChild(body);

		// Gmail-style attachment chips below the content area.
		if (m.attachments && m.attachments.length) {
			wrap.appendChild(attachmentsBlock(m.attachments));
		}

		return wrap;
	}

	// A row of download chips (icon + name + size) for a message's attachments.
	function attachmentsBlock(atts) {
		var box = el('div', 'mbx-attachments');
		box.appendChild(el('div', 'mbx-attachments-label',
			atts.length + (atts.length === 1 ? ' attachment' : ' attachments')));

		var grid = el('div', 'mbx-attachment-grid');
		atts.forEach(function (a) {
			var name = a.filename || 'attachment';
			var size = fmtBytes(a.size_bytes);
			var chip = el('a', 'mbx-attachment');
			chip.href = CFG.attachmentUrlBase + '?ima_inbound_message_attachment_id='
				+ encodeURIComponent(a.id);
			chip.target = '_blank';
			chip.rel = 'noopener';
			chip.title = name + ' (' + size + ')';

			chip.appendChild(fileIcon(a.content_type, name));
			var meta = el('div', 'mbx-attachment-meta');
			meta.appendChild(el('div', 'mbx-attachment-name', name));
			meta.appendChild(el('div', 'mbx-attachment-size', size));
			chip.appendChild(meta);
			grid.appendChild(chip);
		});
		box.appendChild(grid);
		return box;
	}

	// Human-readable byte size.
	function fmtBytes(n) {
		n = Number(n) || 0;
		if (n < 1024) return n + ' B';
		if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
		return (n / (1024 * 1024)).toFixed(1) + ' MB';
	}

	// A file-type icon element, using the SAME image assets the admin Files page
	// uses (pdf / Word / Excel), an image placeholder for pictures, and a neutral
	// document glyph for types the Files page doesn't special-case.
	function fileIcon(type, name) {
		type = (type || '').toLowerCase();
		var lname = (name || '').toLowerCase();
		function ends(suf) { return lname.length >= suf.length && lname.slice(-suf.length) === suf; }

		var src = null;
		if (type.indexOf('image/') === 0) {
			src = '/assets/images/image_placeholder.png';
		} else if (type.indexOf('application/pdf') !== -1 || ends('.pdf')) {
			src = '/assets/images/pdf_icon_80px.png';
		} else if (type.indexOf('msword') !== -1 || type.indexOf('wordprocessingml.document') !== -1 ||
			ends('.doc') || ends('.docx')) {
			src = '/assets/images/microsoft_word_icon_80px.png';
		} else if (type.indexOf('spreadsheetml') !== -1 || type.indexOf('ms-excel') !== -1 ||
			ends('.xls') || ends('.xlsx')) {
			src = '/assets/images/excel_icon_80px.png';
		}

		if (src) {
			var img = el('img', 'mbx-attachment-icon');
			img.src = src;
			img.alt = '';
			img.loading = 'lazy';
			return img;
		}

		// Neutral document glyph (inline SVG so it always renders).
		var span = el('span', 'mbx-attachment-icon mbx-attachment-icon--generic');
		span.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">'
			+ '<path fill="#9aa5b1" d="M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>'
			+ '<path fill="#e4e7eb" d="M14 2l6 6h-6z"/></svg>';
		return span;
	}

	// Gmail-style per-message kebab (⋮) menu — currently holds the raw / .eml
	// deep-link, kept out of the way until asked for.
	function kebabMenu(m) {
		var wrap = el('div', 'mbx-kebab-wrap');
		var btn = el('button', 'mbx-kebab', '⋮');
		btn.type = 'button';
		btn.title = 'More';
		btn.setAttribute('aria-label', 'More options');

		var menu = el('div', 'mbx-kebab-menu');
		menu.hidden = true;
		var base = CFG.messageDetailBase + '?iem_inbound_email_message_id=' + encodeURIComponent(m.id);
		var raw = el('a', 'mbx-kebab-item', 'View raw / .eml');
		raw.href = base + '&view=raw';
		raw.target = '_blank';
		raw.rel = 'noopener';
		menu.appendChild(raw);

		// Don't let kebab/menu clicks collapse the message (the head toggles it).
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var willOpen = menu.hidden;
			closeAllKebabs();
			closeAllFolderPanels();
			menu.hidden = !willOpen;
		});
		menu.addEventListener('click', function (e) { e.stopPropagation(); });

		wrap.appendChild(btn);
		wrap.appendChild(menu);
		return wrap;
	}

	function closeAllKebabs() {
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-kebab-menu'), function (mn) {
			mn.hidden = true;
		});
	}

	// ---- compose attachments (AI chat pattern: paperclip, chips, drag-and-drop) ----
	// Held client-side (not the form's own file input) so a chip can be removed;
	// submitCompose() builds the FormData manually from this array.
	var pendingFiles = [];

	function fmtBytes2(n) {
		n = Number(n) || 0;
		if (n < 1024) return n + ' B';
		if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
		return (n / (1024 * 1024)).toFixed(1) + ' MB';
	}

	function renderAttachStrip() {
		var strip = $('#mbx-attach-strip');
		if (!strip) return;
		if (!pendingFiles.length) { strip.hidden = true; strip.innerHTML = ''; return; }
		strip.hidden = false;
		strip.innerHTML = '';
		pendingFiles.forEach(function (file, idx) {
			var chip = el('span', 'mbx-attach-chip');
			chip.appendChild(el('span', 'mbx-attach-chip-name', file.name));
			chip.appendChild(el('span', 'mbx-attach-chip-size', fmtBytes2(file.size)));
			var rm = el('button', 'mbx-attach-chip-remove', '×');
			rm.type = 'button';
			rm.setAttribute('aria-label', 'Remove ' + file.name);
			rm.addEventListener('click', function () {
				pendingFiles.splice(idx, 1);
				renderAttachStrip();
			});
			chip.appendChild(rm);
			strip.appendChild(chip);
		});
	}

	// Client-side preflight only (fast, friendly message); the server is the
	// authority and re-validates every file and the running total.
	function addFiles(list) {
		var maxFiles = CFG.maxFiles || 10;
		var maxFileBytes = CFG.maxFileBytes || 10485760;
		var maxTotalBytes = CFG.maxTotalBytes || 26214400;
		var total = pendingFiles.reduce(function (sum, f) { return sum + f.size; }, 0);

		for (var i = 0; i < list.length; i++) {
			var f = list[i];
			if (pendingFiles.length >= maxFiles) {
				showComposeError('Up to ' + maxFiles + ' files per message.');
				break;
			}
			if (f.size > maxFileBytes) {
				showComposeError('"' + f.name + '" exceeds the ' + fmtBytes2(maxFileBytes) + ' per-file limit.');
				continue;
			}
			if (total + f.size > maxTotalBytes) {
				showComposeError('The attachments exceed the ' + fmtBytes2(maxTotalBytes) + ' total size limit.');
				break;
			}
			total += f.size;
			pendingFiles.push(f);
		}
		renderAttachStrip();
	}

	function clearPendingFiles() {
		pendingFiles = [];
		renderAttachStrip();
	}

	// ---- compose (reply / reply all / forward) ----

	// Pull the email out of a "Name <email>" display string (or a bare address).
	function extractEmail(s) {
		if (!s) return '';
		var m = /<([^>]+)>/.exec(s);
		return (m ? m[1] : s).trim();
	}

	// Split a stored recipient string into individual addresses.
	function splitAddrs(s) {
		if (!s) return [];
		return s.split(/[,;]+/).map(extractEmail).filter(Boolean);
	}

	// The current mailbox's own address (to drop from Reply-All), by alias id.
	function addressForAlias(aliasId) {
		if (aliasId == null) return '';
		var hit = state.mailboxes.filter(function (m) { return String(m.alias_id) === String(aliasId); })[0];
		return hit ? (hit.address || '') : '';
	}

	// Prefer the latest inbound message as the reply target; fall back to the last.
	function lastInboundOrLast(messages) {
		if (!messages || !messages.length) return null;
		for (var i = messages.length - 1; i >= 0; i--) {
			if (messages[i].direction !== 'outbound') return messages[i];
		}
		return messages[messages.length - 1];
	}

	function ensurePrefix(subject, prefix, altRe) {
		subject = subject || '';
		return altRe.test(subject) ? subject : (prefix + ' ' + subject);
	}

	function openCompose(mode, t, source) {
		var titles = { reply: 'Reply', reply_all: 'Reply All', forward: 'Forward' };
		$('#mbx-compose-title').textContent = titles[mode] || 'Reply';
		hideComposeError();

		// Reply / reply-all / forward keep their implicit sending identity (the
		// source message's mailbox) and never show the From selector.
		var fromRow = document.getElementById('mbx-from-row');
		if (fromRow) fromRow.hidden = true;

		document.getElementById('mbx_mode').value = mode;
		document.getElementById('mbx_source_id').value = source.id;

		var own = (addressForAlias(source.alias_id) || '').toLowerCase();
		var sender = extractEmail(source.sender);

		var to = '', cc = '';
		if (mode === 'forward') {
			to = '';
		} else {
			to = sender;
			if (mode === 'reply_all') {
				cc = splitAddrs(source.recipient).filter(function (a) {
					var la = a.toLowerCase();
					return la !== own && la !== sender.toLowerCase();
				}).join(', ');
			}
		}
		document.getElementById('mbx_to').value = to;
		document.getElementById('mbx_cc').value = cc;

		var subj = t.subject || source.subject || '';
		subj = (mode === 'forward')
			? ensurePrefix(subj, 'Fwd:', /^\s*(fwd?|fw)\s*:/i)
			: ensurePrefix(subj, 'Re:', /^\s*re\s*:/i);
		document.getElementById('mbx_subject').value = subj;

		document.getElementById('mbx_body').value = '';
		clearPendingFiles();

		var chips = document.querySelector('.mbx-reply-actions');
		if (chips) chips.hidden = true;
		var compose = $('#mbx-compose');
		compose.hidden = false;
		compose.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		document.getElementById('mbx_to').focus();
	}

	// Fill the From select from the already-loaded mailbox list (no new fetch),
	// preselecting the given alias when it's one of the options, else the first.
	function populateFromSelect(preselectAliasId) {
		var sel = document.getElementById('mbx_alias_id');
		if (!sel) return;
		sel.innerHTML = '';
		state.mailboxes.forEach(function (m) {
			var opt = document.createElement('option');
			opt.value = String(m.alias_id);
			opt.textContent = m.address;
			sel.appendChild(opt);
		});
		var want = preselectAliasId != null ? String(preselectAliasId) : null;
		var hasWant = want !== null && state.mailboxes.some(function (m) { return String(m.alias_id) === want; });
		if (hasWant) {
			sel.value = want;
		} else if (state.mailboxes.length) {
			sel.value = String(state.mailboxes[0].alias_id);
		}
	}

	// New message: no source thread/message, so identity comes from the From
	// selector — always shown in this mode (even with a single grant, as a plain
	// statement of the sending address, per the new-message compose spec).
	function openComposeNew() {
		$('#mbx-compose-title').textContent = 'New message';
		hideComposeError();

		document.getElementById('mbx_mode').value = 'new';
		document.getElementById('mbx_source_id').value = '';

		populateFromSelect(state.aliasId);
		var fromRow = document.getElementById('mbx-from-row');
		if (fromRow) fromRow.hidden = false;

		document.getElementById('mbx_to').value = '';
		document.getElementById('mbx_cc').value = '';
		document.getElementById('mbx_subject').value = '';
		document.getElementById('mbx_body').value = '';
		clearPendingFiles();

		var chips = document.querySelector('.mbx-reply-actions');
		if (chips) chips.hidden = true;
		var compose = $('#mbx-compose');
		compose.hidden = false;
		compose.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		document.getElementById('mbx_to').focus();
	}

	function closeCompose() {
		$('#mbx-compose').hidden = true;
		var chips = document.querySelector('.mbx-reply-actions');
		if (chips) chips.hidden = false;
	}

	// The compose box is a single element moved into the open thread. Park it back
	// on the main pane (hidden) before a thread's innerHTML is cleared, so clearing
	// the thread never destroys it.
	function parkCompose() {
		var c = document.getElementById('mbx-compose');
		if (c) { c.hidden = true; $('.mbx-main').appendChild(c); }
	}

	function showComposeError(msg) {
		var e = $('#mbx-compose-error');
		e.textContent = msg;
		e.hidden = false;
	}
	function hideComposeError() {
		var e = $('#mbx-compose-error');
		e.hidden = true;
		e.textContent = '';
	}

	function submitCompose(e) {
		e.preventDefault();
		hideComposeError();

		var to = document.getElementById('mbx_to').value.trim();
		if (!to) { showComposeError('Add at least one recipient.'); return; }

		var btn = document.getElementById('mbx_send');
		if (btn) btn.disabled = true;

		// Built manually (not new FormData(form)) so a removed chip is honored —
		// the form fields first, then the kept File objects as attachments[].
		var body = new FormData();
		body.append('mode', document.getElementById('mbx_mode').value);
		body.append('source_id', document.getElementById('mbx_source_id').value);
		var aliasSel = document.getElementById('mbx_alias_id');
		body.append('alias_id', aliasSel ? aliasSel.value : '');
		body.append('_csrf_token', document.getElementById('mbx_csrf').value);
		body.append('to', to);
		body.append('cc', document.getElementById('mbx_cc').value);
		body.append('subject', document.getElementById('mbx_subject').value);
		body.append('body', document.getElementById('mbx_body').value);
		pendingFiles.forEach(function (f) { body.append('attachments[]', f, f.name); });

		fetch(CFG.sendUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-CSRF-Token': CFG.csrf || '' },
			body: body
		}).then(function (r) { return r.json(); }).then(function (data) {
			if (btn) btn.disabled = false;
			if (data && data.ok) {
				closeCompose();
				if (state.threadKey != null) {
					// Re-open the thread so the new outbound row renders in the dialog.
					reopenCurrentThread();
				} else {
					// New message: no thread was open — refresh the list so the new
					// conversation appears without a manual reload.
					loadThreads(true);
				}
				refreshMailboxes();
			} else {
				showComposeError((data && data.error) || 'The message could not be sent.');
			}
		}).catch(function () {
			if (btn) btn.disabled = false;
			showComposeError('A network error prevented sending.');
		});
	}

	// Reload the open thread's messages in place (after a send).
	function reopenCurrentThread() {
		var url = CFG.threadUrl + '?thread_key=' + encodeURIComponent(state.threadKey)
			+ (state.aliasId != null ? '&alias_id=' + encodeURIComponent(state.aliasId) : '');
		apiGet(url).then(function (data) {
			renderThread(state.openThread || { thread_key: state.threadKey, subject: '' }, data.messages || []);
		});
	}

	function closeThread() {
		closeCompose();
		parkCompose();        // preserve the compose box before clearing the thread
		state.threadKey = null;
		state.messages = [];
		$('#mbx-thread').innerHTML = '';
		$('#mbx-reader').classList.remove('reading');
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-thread-item'), function (n) {
			n.classList.remove('active');
		});
	}

	// ---- wiring ----
	function init() {
		// Debounced search.
		var searchTimer = null;
		$('#mbx-search').addEventListener('input', function (e) {
			clearTimeout(searchTimer);
			var v = e.target.value.trim();
			searchTimer = setTimeout(function () { state.search = v; loadThreads(true); }, 300);
		});

		$('#mbx-refresh').addEventListener('click', function () { refreshMailboxes(); loadThreads(true); });
		$('#mbx-more').addEventListener('click', function () { state.page += 1; loadThreads(false); });

		var newMsgBtn = $('#mbx-new-message');
		if (newMsgBtn) newMsgBtn.addEventListener('click', openComposeNew);

		// Compose: discard button + fetch-intercepted submit.
		var closeBtn = $('#mbx-compose-close');
		if (closeBtn) closeBtn.addEventListener('click', closeCompose);
		var composeForm = document.getElementById('mbx_compose_form');
		if (composeForm) composeForm.addEventListener('submit', submitCompose);

		// Compose attachments: paperclip → hidden input; change → add; drag-and-drop
		// onto the open compose panel also adds files.
		var attachBtn = $('#mbx-attach-btn');
		var fileInput = $('#mbx-file-input');
		if (attachBtn && fileInput) {
			attachBtn.addEventListener('click', function () { fileInput.click(); });
			fileInput.addEventListener('change', function () {
				if (fileInput.files && fileInput.files.length) addFiles(fileInput.files);
				fileInput.value = ''; // allow re-selecting the same file
			});
		}
		var composePanel = $('#mbx-compose');
		if (composePanel) {
			['dragenter', 'dragover'].forEach(function (ev) {
				composePanel.addEventListener(ev, function (e) {
					e.preventDefault(); composePanel.classList.add('mbx-compose-dragover');
				});
			});
			['dragleave', 'drop'].forEach(function (ev) {
				composePanel.addEventListener(ev, function (e) {
					e.preventDefault(); composePanel.classList.remove('mbx-compose-dragover');
				});
			});
			composePanel.addEventListener('drop', function (e) {
				if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
					addFiles(e.dataTransfer.files);
				}
			});
		}

		// A click anywhere else closes any open kebab (⋮) menu or Move/Labels panel.
		document.addEventListener('click', function () { closeAllKebabs(); closeAllFolderPanels(); });

		// Esc closes any kebab menu or Move/Labels panel, then compose, then the conversation.
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			var open = document.querySelector('.mbx-kebab-menu:not([hidden]), .mbx-folder-panel:not([hidden])');
			if (open) { closeAllKebabs(); closeAllFolderPanels(); }
			else if (!$('#mbx-compose').hidden) { closeCompose(); }
			else if (state.threadKey != null) { closeThread(); }
		});

		// Seed switcher, then pick a default mailbox.
		var seed = CFG.initialMailboxes || { mailboxes: [], all_access: false };
		renderMailboxes(seed);

		if (seed.mailboxes && seed.mailboxes.length) {
			// Reopen the last-viewed mailbox if it's still available; else the first.
			var want = recallMailbox();
			var pick = null;
			if (want != null) {
				pick = seed.mailboxes.filter(function (m) {
					return String(m.alias_id) === want;
				})[0];
				if (!pick && want === 'unmatched' && seed.all_access && seed.unmatched) {
					selectMailbox('unmatched', 'Unmatched');
					pick = true;
				}
			}
			if (pick === true) { /* already selected the Unmatched view above */ }
			else if (pick) { selectMailbox(pick.alias_id, pick.address); }
			else { selectMailbox(seed.mailboxes[0].alias_id, seed.mailboxes[0].address); }
		} else {
			$('#mbx-threads').appendChild(emptyRow(seed.all_access
				? 'No mailboxes yet.'
				: 'No mailboxes have been shared with you.'));
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
