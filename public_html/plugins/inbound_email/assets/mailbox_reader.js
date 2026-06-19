/*
 * Mailbox Reader — vanilla-JS Gmail-style inbox over the scoped AJAX endpoints.
 * No framework. @version 2.4
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
			list.appendChild(mailboxItem(m.address, m.alias_id, m.unread, m.any_starred, m.folders));
		});
		if (state.allAccess && data.unmatched && data.unmatched.total > 0) {
			var li = mailboxItem('Unmatched', 'unmatched', data.unmatched.unread, false, []);
			li.title = 'Unrouted mail that matched no mailbox';
			list.appendChild(li);
		}

		// Highlight current selection + render the active mailbox's folder rail.
		highlightMailbox();
		renderFolderRail();
	}

	function mailboxItem(label, aliasId, unread, anyStarred, folders) {
		var li = el('li', 'mbx-mailbox');
		li.dataset.alias = (aliasId == null ? '' : String(aliasId));
		li._folders = folders || [];
		var addr = el('span', 'mbx-mailbox-addr', label);
		li.appendChild(addr);
		if (anyStarred) li.appendChild(el('span', 'mbx-star-dot', '★'));
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
		var cur = state.spamView ? 'spam' : (state.folderId == null ? '' : String(state.folderId));
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-folder'), function (li) {
			li.classList.toggle('active', li.dataset.folder === cur);
		});
	}

	function selectFolder(folderId, name) {
		closeThread();                    // leave any open conversation → show the list
		if (folderId === 'spam') {
			state.spamView = true;
			state.folderId = null;
		} else {
			state.spamView = false;
			state.folderId = folderId;
		}
		$('#mbx-list-title').textContent = (state.mailboxLabel || 'All mail')
			+ (folderId == null ? '' : ' / ' + name);
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

	function selectMailbox(aliasId, label) {
		closeThread();                    // leave any open conversation → show the list
		state.aliasId = aliasId;
		state.folderId = null;            // reset to the folder-unfiltered view
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
		if (t.snippet) {
			mid.appendChild(el('span', 'mbx-thread-snippet', ' — ' + t.snippet));
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

		btn.addEventListener('click', function () { panel.hidden = !panel.hidden; });
		wrap.appendChild(btn);
		wrap.appendChild(panel);
		return wrap;
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
		right.appendChild(kebabMenu(m));
		head.appendChild(right);

		head.addEventListener('click', function () { wrap.classList.toggle('mbx-collapsed'); });
		wrap.appendChild(head);

		var body = el('div', 'mbx-message-body');
		if (m.body_html) {
			var note = el('div', 'mbx-sandbox-note',
				'Sandboxed HTML — stored mail is attacker-controlled; scripts and links are disabled.');
			body.appendChild(note);
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
		return wrap;
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
		var files = document.getElementById('mbx_attachments');
		if (files) files.value = '';

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
		var form = e.target;
		hideComposeError();

		var to = document.getElementById('mbx_to').value.trim();
		if (!to) { showComposeError('Add at least one recipient.'); return; }

		var btn = document.getElementById('mbx_send');
		if (btn) btn.disabled = true;

		fetch(CFG.sendUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-CSRF-Token': CFG.csrf || '' },
			body: new FormData(form)
		}).then(function (r) { return r.json(); }).then(function (data) {
			if (btn) btn.disabled = false;
			if (data && data.ok) {
				closeCompose();
				// Re-open the thread so the new outbound row renders in the dialog.
				if (state.threadKey != null) {
					reopenCurrentThread();
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

		// Compose: discard button + fetch-intercepted submit.
		var closeBtn = $('#mbx-compose-close');
		if (closeBtn) closeBtn.addEventListener('click', closeCompose);
		var composeForm = document.getElementById('mbx_compose_form');
		if (composeForm) composeForm.addEventListener('submit', submitCompose);

		// A click anywhere else closes any open kebab (⋮) menu.
		document.addEventListener('click', function () { closeAllKebabs(); });

		// Esc closes any kebab menu, then the compose panel, then the conversation.
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			var open = document.querySelector('.mbx-kebab-menu:not([hidden])');
			if (open) { closeAllKebabs(); }
			else if (!$('#mbx-compose').hidden) { closeCompose(); }
			else if (state.threadKey != null) { closeThread(); }
		});

		// Seed switcher, then pick a default mailbox.
		var seed = CFG.initialMailboxes || { mailboxes: [], all_access: false };
		renderMailboxes(seed);

		if (seed.mailboxes && seed.mailboxes.length) {
			selectMailbox(seed.mailboxes[0].alias_id, seed.mailboxes[0].address);
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
