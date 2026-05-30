/*
 * Mailbox Reader — vanilla-JS Gmail-style inbox over the scoped AJAX endpoints.
 * No framework. @version 1.0
 *
 * Visibility is enforced server-side (MailboxViewer/MailboxService); this client
 * only renders what the endpoints return and never decides access.
 */
(function () {
	'use strict';

	var CFG = window.MAILBOX_READER || {};

	var state = {
		aliasId: null,        // null = all accessible (or "All mail" for superadmin)
		allAccess: false,
		filter: 'all',        // all | unread | starred
		search: '',
		page: 1,
		hasMore: false,
		threadKey: null,
		mailboxes: []
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

		if (state.allAccess && data.all_mail) {
			list.appendChild(mailboxItem('All mail', null, data.all_mail.unread, false));
		}
		state.mailboxes.forEach(function (m) {
			list.appendChild(mailboxItem(m.address, m.alias_id, m.unread, m.any_starred));
		});
		if (state.allAccess && data.unmatched && data.unmatched.total > 0) {
			var info = el('li', 'mbx-mailbox', null);
			info.style.opacity = '.7';
			info.style.cursor = 'default';
			info.appendChild(el('span', 'mbx-mailbox-addr', 'Unmatched'));
			var b = el('span', 'mbx-badge' + (data.unmatched.unread ? '' : ' zero'),
				String(data.unmatched.unread));
			info.appendChild(b);
			info.title = 'Unrouted mail (no mailbox) — visible in All mail';
			list.appendChild(info);
		}

		// Highlight current selection.
		highlightMailbox();
	}

	function mailboxItem(label, aliasId, unread, anyStarred) {
		var li = el('li', 'mbx-mailbox');
		li.dataset.alias = (aliasId == null ? '' : String(aliasId));
		var addr = el('span', 'mbx-mailbox-addr', label);
		li.appendChild(addr);
		if (anyStarred) li.appendChild(el('span', 'mbx-star-dot', '★'));
		var badge = el('span', 'mbx-badge' + (unread ? '' : ' zero'), String(unread || 0));
		li.appendChild(badge);
		li.addEventListener('click', function () { selectMailbox(aliasId, label); });
		return li;
	}

	function highlightMailbox() {
		var cur = (state.aliasId == null ? '' : String(state.aliasId));
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-mailbox'), function (li) {
			if (li.dataset.alias === undefined) return;
			li.classList.toggle('active', li.dataset.alias === cur && li.style.cursor !== 'default');
		});
	}

	function selectMailbox(aliasId, label) {
		state.aliasId = aliasId;
		$('#mbx-list-title').textContent = label || 'All mail';
		highlightMailbox();
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
		if (state.search) { p.set('subject', state.search); p.set('sender', state.search); p.set('body', state.search); }
		p.set('page', String(state.page));
		return CFG.listUrl + '?' + p.toString();
	}

	function loadThreads(reset) {
		if (reset) { state.page = 1; }
		var listEl = $('#mbx-threads');
		if (reset) { listEl.innerHTML = ''; listEl.appendChild(loadingRow()); }
		apiGet(buildListQuery()).then(function (data) {
			if (reset) listEl.innerHTML = '';
			(data.threads || []).forEach(function (t) { listEl.appendChild(threadRow(t)); });
			if (!listEl.children.length) {
				listEl.appendChild(emptyRow('No conversations.'));
			}
			state.hasMore = !!data.has_more;
			$('#mbx-more').hidden = !state.hasMore;
		});
	}

	function loadingRow() { var li = el('li', 'mbx-loading', 'Loading…'); return li; }
	function emptyRow(text) { var li = el('li', 'mbx-loading', text); return li; }

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

		var main = el('div', 'mbx-thread-main');
		var row1 = el('div', 'mbx-thread-row');
		row1.appendChild(el('span', 'mbx-thread-from', t.senders || '(unknown)'));
		row1.appendChild(el('span', 'mbx-thread-time', fmtTime(t.latest_time)));
		main.appendChild(row1);

		var subj = el('div', 'mbx-thread-subject', t.subject || '(no subject)');
		if (t.msg_count > 1) {
			var c = el('span', 'mbx-thread-count', String(t.msg_count));
			subj.appendChild(c);
		}
		main.appendChild(subj);
		li.appendChild(main);

		li.addEventListener('click', function () { openThread(t, li); });
		return li;
	}

	// ---- reading pane (right) ----
	function openThread(t, rowEl) {
		state.threadKey = t.thread_key;
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-thread-item'), function (n) {
			n.classList.remove('active');
		});
		if (rowEl) rowEl.classList.add('active');
		$('#mbx-reader').classList.add('reading');

		var pane = $('#mbx-thread');
		var empty = $('#mbx-read-empty');
		empty.hidden = true;
		pane.hidden = false;
		pane.innerHTML = '<div class="mbx-loading">Loading…</div>';

		var url = CFG.threadUrl + '?thread_key=' + encodeURIComponent(t.thread_key)
			+ (state.aliasId != null ? '&alias_id=' + encodeURIComponent(state.aliasId) : '');
		apiGet(url).then(function (data) {
			renderThread(t, data.messages || []);
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

	function renderThread(t, messages) {
		var pane = $('#mbx-thread');
		pane.innerHTML = '';

		var header = el('div', 'mbx-thread-header');
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
		header.appendChild(actions);
		pane.appendChild(header);

		messages.forEach(function (m, idx) {
			pane.appendChild(messageBlock(m, idx === messages.length - 1));
		});
	}

	function actionBtn(label, danger, onClick) {
		var b = el('button', 'mbx-action' + (danger ? ' danger' : ''), label);
		b.type = 'button';
		b.addEventListener('click', onClick);
		return b;
	}

	function messageBlock(m, expanded) {
		var wrap = el('div', 'mbx-message' + (expanded ? '' : ' mbx-collapsed'));

		var head = el('div', 'mbx-message-head');
		var left = el('div');
		left.appendChild(el('div', 'mbx-message-from', m.sender || '(unknown)'));
		left.appendChild(el('div', 'mbx-message-meta', 'to ' + (m.recipient || '')));
		head.appendChild(left);
		head.appendChild(el('span', 'mbx-message-time', fmtTime(m.received_time)));
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
			body.appendChild(el('em', null, 'No text body. Use Raw / .eml.'));
		}

		// Per-message raw / .eml deep-link to the detail page.
		var links = el('div', 'mbx-thread-actions');
		links.style.marginTop = '10px';
		var base = CFG.messageDetailBase + '?iem_inbound_email_message_id=' + encodeURIComponent(m.id);
		var raw = el('a', 'mbx-action', 'View raw / .eml');
		raw.href = base + '&view=raw';
		raw.target = '_blank';
		raw.rel = 'noopener';
		links.appendChild(raw);
		body.appendChild(links);

		wrap.appendChild(body);
		return wrap;
	}

	function closeThread() {
		state.threadKey = null;
		$('#mbx-thread').hidden = true;
		$('#mbx-thread').innerHTML = '';
		$('#mbx-read-empty').hidden = false;
		$('#mbx-reader').classList.remove('reading');
	}

	// ---- wiring ----
	function init() {
		// Filters.
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-filter'), function (btn) {
			btn.addEventListener('click', function () {
				Array.prototype.forEach.call(document.querySelectorAll('.mbx-filter'), function (b) {
					b.classList.remove('active');
				});
				btn.classList.add('active');
				state.filter = btn.dataset.filter;
				loadThreads(true);
			});
		});

		// Debounced search.
		var searchTimer = null;
		$('#mbx-search').addEventListener('input', function (e) {
			clearTimeout(searchTimer);
			var v = e.target.value.trim();
			searchTimer = setTimeout(function () { state.search = v; loadThreads(true); }, 300);
		});

		$('#mbx-refresh').addEventListener('click', function () { refreshMailboxes(); loadThreads(true); });
		$('#mbx-more').addEventListener('click', function () { state.page += 1; loadThreads(false); });

		// Seed switcher, then pick a default mailbox.
		var seed = CFG.initialMailboxes || { mailboxes: [], all_access: false };
		renderMailboxes(seed);

		if (seed.all_access) {
			selectMailbox(null, 'All mail');
		} else if (seed.mailboxes && seed.mailboxes.length) {
			selectMailbox(seed.mailboxes[0].alias_id, seed.mailboxes[0].address);
		} else {
			$('#mbx-threads').appendChild(emptyRow('No mailboxes have been shared with you.'));
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
