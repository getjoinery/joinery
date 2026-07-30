/*
 * Mailbox Reader — vanilla-JS Gmail-style inbox over the scoped AJAX endpoints.
 * No framework. @version 2.31
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
		trashView: false,     // the Trash pseudo-folder (discarded mail, awaiting purge)
		trashRetentionDays: 0, // the purge window the server reported for the Trash view
		mailboxLabel: '',     // the active mailbox label, for composing folder titles
		draftsView: false,    // the Drafts pseudo-mailbox (saved drafts)
		mailboxes: [],
		messages: [],     // messages of the currently-open thread
		// Compose draft state (specs/mailbox_compose_maturity.md § Phase 2).
		draftId: null,        // the saved draft's id once autosaved (null = not yet saved)
		draftAlias: null,     // the From alias id the current compose is bound to
		draftDirty: false,    // unsaved changes since the last autosave
		draftSaving: false,   // an autosave is in flight
		draftAttachments: [], // server-side attachments of a reopened draft (read-only chips)
		contacts: [],         // the viewer's contacts (§ Phase 4), for recipient autocomplete
		contactsView: false,  // the Contacts manager pseudo-mailbox
		setupStatus: {}       // alias id → the Setup tab's verdict for that mailbox
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

	// A calendar date in the viewer's own timezone — for a purge date, where the
	// day is the whole point and the hour is noise.
	function fmtDate(iso) {
		if (!iso) return '';
		var d = new Date(iso.replace(' ', 'T') + 'Z');
		if (isNaN(d.getTime())) return iso;
		var opts = { month: 'short', day: 'numeric' };
		if (d.getFullYear() !== new Date().getFullYear()) { opts.year = 'numeric'; }
		return d.toLocaleDateString([], opts);
	}

	// The reader's endpoints are /api/v1 actions, called through the shared
	// joineryApi transport. apiGet keeps its query-string call convention: it
	// parses the query off the configured action URL into a JSON body. Errors
	// resolve as {} so list/render callers degrade to an empty state.
	function apiGet(url) {
		var qpos = url.indexOf('?');
		var base = qpos === -1 ? url : url.slice(0, qpos);
		var payload = {};
		if (qpos !== -1) {
			new URLSearchParams(url.slice(qpos + 1)).forEach(function (v, k) { payload[k] = v; });
		}
		return joineryApi.post(base, payload).catch(function () { return {}; });
	}
	function apiAction(payload) {
		var body = { action: payload.action };
		if (payload.aliasId != null) body.alias_id = String(payload.aliasId);
		if (payload.threadKey != null) body.thread_key = payload.threadKey;
		if (payload.ids) body.ids = payload.ids.map(function (id) { return String(id); });
		if (payload.folderId != null) body.folder_id = String(payload.folderId);
		if (payload.present != null) body.present = payload.present ? '1' : '0';
		if (payload.name != null) body.name = String(payload.name);
		return joineryApi.post(CFG.actionUrl, body).catch(function () { return {}; });
	}

	// ---- vault unlock (locked-state contract) ----
	// A locked/pending row arrives with cleartext metadata and a neutral "Sealed
	// message" placeholder; any content action (open a thread, search, download,
	// Fortress compose) runs the built passkey ceremony and then re-runs the
	// original request without navigation (specs/mailbox_security_levels.md § 4).
	function apiV1(action, payload) {
		return joineryApi.post(action, payload || {});
	}

	// Run the passkey unlock ceremony; resolves true on success. Delegates to
	// the shared platform ceremony (assets/js/vault-lock.js) when it's loaded,
	// so the header lock chip and the presence beacon stay in sync with
	// reader-initiated unlocks; the inline ceremony is the fallback for a page
	// without the chip. selfUnlocking suppresses the generic vault-unlocked
	// refresh listener while a reader action is about to re-run itself with
	// fresher state.
	var selfUnlocking = false;
	async function unlockVault() {
		selfUnlocking = true;
		try {
			if (window.JoineryVaultLock) {
				return await JoineryVaultLock.unlock();
			}
			if (!window.JoineryPasskeys) { alert('Unlocking is unavailable on this page.'); return false; }
			try {
				var opt = await apiV1('vault_unlock_options', {});
				if (!opt || !opt.options) {
					throw new Error('Could not start unlock.');
				}
				var credential = (await JoineryPasskeys.derive(opt.options)).response;
				var res = await apiV1('vault_unlock_passkey', { credential: credential });
				if (res && res.success === false) {
					throw new Error(res.message || 'Unlock failed.');
				}
				startHeartbeat();
				document.dispatchEvent(new CustomEvent('joinery:vault-unlocked'));
				return true;
			} catch (e) {
				alert(e.message || 'Could not unlock your vault.');
				return false;
			}
		} finally {
			selfUnlocking = false;
		}
	}

	// Presence is site-wide, not mail-page-only: the vault-presence beacon
	// (assets/js/vault-presence.js, included by the page header for signed-in
	// users) beats vault_heartbeat from EVERY Joinery page while a window is
	// open, so navigating away from the reader never ends the window. The
	// reader only announces state changes to it. With the beacon absent the
	// window simply stays unmonitored (idle TTL + caps still apply) — never a
	// false lock.
	function startHeartbeat() {
		if (window.JoineryVaultPresence) JoineryVaultPresence.start();
	}
	function stopHeartbeat() {
		if (window.JoineryVaultPresence) JoineryVaultPresence.stop();
	}

	// ---- mailbox switcher (left rail) ----
	function renderMailboxes(data) {
		state.allAccess = !!data.all_access;
		state.mailboxes = data.mailboxes || [];
		// A sealed mailbox with an open window (sealed but not locked) means a
		// live unlock window — make sure the presence beacon is beating.
		var anyOpen = state.mailboxes.some(function (m) {
			return m.security_level && m.security_level !== 'standard' && !m.locked;
		});
		if (anyOpen) { startHeartbeat(); }
		var list = $('#mbx-mailboxes');
		list.innerHTML = '';

		state.mailboxes.forEach(function (m) {
			list.appendChild(mailboxItem(m.address, m.alias_id, m.unread, m.folders, m.own));
		});
		if (state.allAccess && data.unmatched && data.unmatched.total > 0) {
			var li = mailboxItem('Unmatched', 'unmatched', data.unmatched.unread, []);
			li.title = 'Unrouted mail that matched no mailbox';
			list.appendChild(li);
		}
		// Drafts pseudo-mailbox (specs/mailbox_compose_maturity.md § Phase 2) — shown
		// whenever the viewer can compose. Clicking it lists saved drafts.
		if (state.mailboxes.length) {
			var draftsTotal = (data.drafts && data.drafts.total) ? data.drafts.total : 0;
			var dli = el('li', 'mbx-mailbox mbx-drafts-entry' + (state.draftsView ? ' active' : ''));
			dli.dataset.alias = 'drafts';
			dli.appendChild(el('span', 'mbx-mailbox-addr', 'Drafts'));
			dli.appendChild(el('span', 'mbx-badge' + (draftsTotal ? '' : ' zero'), String(draftsTotal)));
			dli.addEventListener('click', function () { selectDrafts(); });
			list.appendChild(dli);

			// Contacts manager (§ Phase 4).
			var cli = el('li', 'mbx-mailbox mbx-contacts-entry' + (state.contactsView ? ' active' : ''));
			cli.dataset.alias = 'contacts';
			cli.appendChild(el('span', 'mbx-mailbox-addr', 'Contacts'));
			cli.addEventListener('click', function () { selectContacts(); });
			list.appendChild(cli);
		}

		// Highlight current selection + render the active mailbox's folder rail.
		highlightMailbox();
		renderFolderRail();
		// The switcher data is what says how a mailbox is protected, so the chip
		// beside the list title can only be right once this has run.
		updateLevelChip();

		// New message is only ever composable when the viewer has at least one
		// accessible mailbox to send as (canCompose, mirrored client-side).
		var newBtn = $('#mbx-new-message');
		if (newBtn) newBtn.hidden = !state.mailboxes.length;
	}

	// ---- list title + protection chip ----
	// The protection level belongs beside the name of the mailbox being read, not
	// in the rail: there it competed with the address for the same narrow line and
	// won, hiding the very thing the row exists to show.
	function setListTitle(text) {
		var title = $('#mbx-list-title');
		if (title) title.textContent = text;
		updateLevelChip();
	}

	function updateLevelChip() {
		var chip = $('#mbx-level-chip');
		if (!chip) return;
		var level = '';
		// Only a single open mailbox has a level to state. The aggregate views
		// (Drafts, Contacts, all-mail) span mailboxes that may differ.
		if (!state.draftsView && !state.contactsView && state.aliasId != null) {
			state.mailboxes.forEach(function (m) {
				if (String(m.alias_id) !== String(state.aliasId)) return;
				if (m.security_level && m.security_level !== 'standard') { level = m.security_level; }
			});
		}
		chip.hidden = !level;
		chip.textContent = level ? (level.charAt(0).toUpperCase() + level.slice(1)) : '';
		chip.className = 'mbx-level-badge' + (level ? ' mbx-level-' + level : '');
		chip.title = level ? 'Mail protection level (set on the domain)' : '';
	}

	function mailboxItem(label, aliasId, unread, folders, own) {
		var li = el('li', 'mbx-mailbox');
		li.dataset.alias = (aliasId == null ? '' : String(aliasId));
		li._folders = folders || [];
		var addr = el('span', 'mbx-mailbox-addr', label);
		li.appendChild(addr);
		// Signature gear (§ Phase 3) — only on mailboxes the viewer is a member of
		// (a signature lives on a grant), never the superadmin's all-access extras.
		if (own && aliasId != null && aliasId !== 'unmatched' && !isNaN(Number(aliasId))) {
			var gear = el('button', 'mbx-sig-gear', '⚙');
			gear.type = 'button';
			gear.title = 'Edit signature';
			gear.setAttribute('aria-label', 'Edit signature');
			gear.addEventListener('click', function (e) { e.stopPropagation(); openSignatureEditor(aliasId); });
			li.appendChild(gear);
		}
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
		// (folder-unfiltered root), any tracked IMAP folders, and the "Spam" and
		// "Trash" views. Both are always present — they read a column (the verdict,
		// the delete time), not folder membership, so they work for local and IMAP
		// mailboxes alike.
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
		ul.appendChild(folderItem('trash', 'Trash'));
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
			: state.trashView ? 'trash'
			: state.inboxView ? 'inbox'
			: (state.folderId == null ? '' : String(state.folderId));
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-folder'), function (li) {
			li.classList.toggle('active', li.dataset.folder === cur);
		});
	}

	function selectFolder(folderId, name) {
		closeThread();                    // leave any open conversation → show the list
		state.draftsView = false;
		state.contactsView = false;
		state.inboxView = false;
		state.spamView = false;
		state.trashView = false;
		if (folderId === 'inbox') {
			state.inboxView = true;
			state.folderId = null;
		} else if (folderId === 'spam') {
			state.spamView = true;
			state.folderId = null;
		} else if (folderId === 'trash') {
			state.trashView = true;
			state.folderId = null;
		} else {
			state.folderId = folderId;    // null = All Mail; a number = a tracked folder
		}
		// Inbox is the mailbox's default, so its title is just the mailbox; the other
		// views append their name.
		setListTitle((state.mailboxLabel || 'All mail')
			+ (state.inboxView ? '' : ' / ' + (name || 'All Mail')));
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
		state.draftsView = false;
		state.contactsView = false;
		state.folderId = null;            // reset to the folder-unfiltered view
		state.inboxView = true;           // default to the Inbox (non-archived) view
		state.spamView = false;
		state.trashView = false;
		state.mailboxLabel = label || 'All mail';
		setListTitle(state.mailboxLabel);
		highlightMailbox();
		highlightDrafts();
		renderFolderRail();
		loadThreads(true);
	}

	// ---- setup banner ----
	// The Setup tab's own verdict for the open mailbox, fetched once per mailbox
	// and remembered for the page's life (the endpoint caches it server-side too,
	// so a reload is not a fresh round of DNS lookups). A mailbox that is all
	// green says nothing at all: silence is the normal state, so a banner means
	// something when it appears.
	//
	// Only ever asked for a single open mailbox. The aggregate views (All mail,
	// Drafts, Contacts) have no one mailbox to check, and the member mount has no
	// setupUrlBase — mail setup is operator work.
	function setupCheckable() {
		return !!CFG.setupUrlBase && !!state.aliasId && !isNaN(Number(state.aliasId))
			&& !state.draftsView && !state.contactsView;
	}

	// Ask (or re-use the answer) and paint. `fresh` forces a re-run server-side;
	// `reask` keeps the server's memory but skips this page's, for when the
	// answer may have changed under us (see the visibility handler).
	function updateSetupBanner(fresh, reask) {
		if (!setupCheckable()) return;
		var aliasId = state.aliasId;
		var known = state.setupStatus[aliasId];
		if (known && !fresh && !reask) { paintSetupBanner(aliasId, known); return; }
		joineryApi.post(CFG.setupStatusUrl, { alias_id: String(aliasId), fresh: fresh ? '1' : '0' })
			.then(function (data) {
				state.setupStatus[aliasId] = data || {};
				paintSetupBanner(aliasId, state.setupStatus[aliasId]);
			})
			.catch(function () { /* an unanswered check is not a verdict — stay quiet */ });
	}

	// The banner sits where the first conversation would be — the one place in
	// the reader an operator is already looking, and the place an empty inbox
	// raises the question the banner answers.
	function paintSetupBanner(aliasId, status) {
		// The list may have moved on while the check ran.
		if (String(aliasId) !== String(state.aliasId) || !setupCheckable()) return;
		var listEl = $('#mbx-threads');
		var existing = $('.mbx-setup-banner', listEl);
		if (existing) listEl.removeChild(existing);
		if (!status || status.status !== 'attention') return;

		var li = el('li', 'mbx-setup-banner');
		var body = el('div', 'mbx-setup-banner-body');
		body.appendChild(el('span', 'mbx-setup-banner-title', 'This mailbox needs attention'));
		body.appendChild(el('span', 'mbx-setup-banner-reason',
			(status.label ? status.label + ': ' : '') + (status.reason || '')));
		li.appendChild(body);
		var link = el('a', 'mbx-setup-banner-btn', 'Check setup');
		link.href = status.url || (CFG.setupUrlBase + encodeURIComponent(aliasId));
		li.appendChild(link);
		listEl.insertBefore(li, listEl.firstChild);
	}

	// The Drafts pseudo-mailbox: list the viewer's saved drafts (server-scoped to
	// their mailboxes). No folder rail, no inbox/spam split.
	function selectDrafts() {
		closeThread();
		state.aliasId = null;
		state.draftsView = true;
		state.contactsView = false;
		state.folderId = null;
		state.inboxView = false;
		state.spamView = false;
		state.trashView = false;
		state.mailboxLabel = 'Drafts';
		setListTitle('Drafts');
		var prior = $('#mbx-folder-rail');
		if (prior) prior.parentNode.removeChild(prior);
		highlightMailbox();
		highlightDrafts();
		loadThreads(true);
	}

	function highlightDrafts() {
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-drafts-entry'), function (li) {
			li.classList.toggle('active', !!state.draftsView);
		});
	}

	function refreshMailboxes() {
		return apiGet(CFG.mailboxesUrl).then(renderMailboxes);
	}

	// ---- thread list (center) ----
	function buildListQuery() {
		var p = new URLSearchParams();
		if (state.draftsView) {
			// Drafts view: server-scoped to the viewer's mailboxes; no other filters.
			p.set('drafts', '1');
			p.set('page', String(state.page));
			return CFG.listUrl + '?' + p.toString();
		}
		if (state.aliasId != null) p.set('alias_id', String(state.aliasId));
		if (state.filter === 'unread') p.set('unread_only', '1');
		if (state.filter === 'starred') p.set('starred_only', '1');
		if (state.search) { p.set('q', state.search); }
		if (state.trashView) { p.set('trash', '1'); }
		else if (state.spamView) { p.set('spam', '1'); }
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
			if (data.search_locked) {
				// A search over a sealed mailbox with no open window — prompt unlock,
				// then re-run the same query.
				var row = el('li', 'mbx-unlock-banner');
				row.appendChild(el('span', 'mbx-unlock-text', 'Unlock to search sealed mail.'));
				var sbtn = el('button', 'mbx-unlock-btn', 'Unlock');
				sbtn.type = 'button';
				sbtn.addEventListener('click', async function () {
					sbtn.disabled = true;
					if (await unlockVault()) { loadThreads(true); } else { sbtn.disabled = false; }
				});
				row.appendChild(sbtn);
				listEl.insertBefore(row, listEl.firstChild);
			}
			if (!listEl.children.length) {
				listEl.appendChild(emptyRow(state.trashView ? 'Trash is empty.' : 'No conversations.'));
			}
			// The retention line sits above whatever the list holds, empty included —
			// an empty Trash is exactly when someone wonders where it all went.
			if (state.trashView) {
				state.trashRetentionDays = data.trash_retention_days || 0;
				listEl.insertBefore(trashNoteRow(), listEl.firstChild);
			}
			// Setup banner above everything, including the unlock prompt and the
			// empty-list row — an unfinished mailbox is why the list is empty.
			// Only on a reset render; "Load more" appends below what is there.
			if (reset) { updateSetupBanner(false); }
			state.hasMore = !!data.has_more;
			$('#mbx-more').hidden = !state.hasMore;
		});
	}

	// The one line the Trash view says about itself: how long things stay, and — on
	// a mailbox that pulls from a source server — that restore and delete-forever
	// act here, not there.
	function trashNoteRow() {
		var li = el('li', 'mbx-trash-note');
		var days = state.trashRetentionDays;
		var text = days > 0
			? 'Mail here is permanently deleted ' + days + ' days after you delete it.'
			: 'Mail here is kept until you delete it permanently.';
		var hit = state.mailboxes.filter(function (m) {
			return String(m.alias_id) === String(state.aliasId);
		})[0];
		if (hit && hit.has_feed) {
			text += ' Restoring brings a message back here and leaves the copy on the source server alone.';
		}
		li.textContent = text;
		return li;
	}

	function sectionHeader(section) {
		return el('li', 'mbx-section', SECTION_LABELS[section] || section);
	}

	function loadingRow() { var li = el('li', 'mbx-loading', 'Loading…'); return li; }
	function emptyRow(text) { var li = el('li', 'mbx-loading', text); return li; }

	// Mail providers where the person is the identity and the domain says nothing:
	// a bare address here falls back to the local part, not to 'Gmail'.
	var CONSUMER_MAIL_DOMAINS = {
		'gmail': 1, 'googlemail': 1, 'outlook': 1, 'hotmail': 1, 'live': 1, 'msn': 1,
		'yahoo': 1, 'ymail': 1, 'aol': 1, 'icloud': 1, 'me': 1, 'mac': 1,
		'proton': 1, 'protonmail': 1, 'pm': 1, 'fastmail': 1, 'hey': 1, 'zoho': 1,
		'gmx': 1, 'web': 1, 'mail': 1, 'yandex': 1, 'qq': 1, '163': 1, '126': 1
	};

	// Registry-ish second levels, so example.co.uk yields 'example' and not 'co'.
	var REGISTRY_SECOND_LEVELS = {
		'co': 1, 'com': 1, 'net': 1, 'org': 1, 'edu': 1, 'gov': 1, 'ac': 1, 'or': 1, 'ne': 1
	};

	// Mailboxes no person owns. A role address is infrastructure, so its local part is
	// never the identity — the sending organization is, even at a consumer provider:
	// no-reply@notify.proton.me is Proton writing to you, not somebody named No-Reply.
	var ROLE_LOCAL_PARTS = {
		'noreply': 1, 'donotreply': 1, 'notify': 1, 'notification': 1, 'notifications': 1,
		'alert': 1, 'alerts': 1, 'bounce': 1, 'bounces': 1, 'postmaster': 1,
		'mailerdaemon': 1, 'abuse': 1, 'webmaster': 1, 'root': 1, 'support': 1,
		'help': 1, 'info': 1, 'billing': 1, 'sales': 1, 'admin': 1, 'contact': 1
	};

	// A role mailbox by name: exact match on the punctuation-stripped local part, or a
	// no-reply marker anywhere in it (AmericanExpress-no-reply, DOTServicesnoreply).
	function isRoleLocalPart(local) {
		var key = String(local).toLowerCase().replace(/[^a-z0-9]+/g, '');
		if (ROLE_LOCAL_PARTS[key]) return true;
		return key.indexOf('noreply') !== -1 || key.indexOf('donotreply') !== -1;
	}

	// True when the address sits BELOW a domain rather than at it (notify.proton.me vs
	// proton.me). A personal mailbox is never at a subdomain of its provider, so this
	// is what separates a provider's own outbound infrastructure from its users.
	function hasSubdomain(host) {
		var parts = String(host).toLowerCase().split('.').filter(Boolean);
		if (parts.length < 2) return false;
		parts.pop();
		if (parts.length > 1 && REGISTRY_SECOND_LEVELS[parts[parts.length - 1]]) {
			parts.pop();
		}
		return parts.length > 1;
	}

	// 'jeremy.tunnell' -> 'Jeremy Tunnell', 'e-trade' -> 'E-Trade'.
	function titleCase(label) {
		return label.replace(/[._+]+/g, ' ')
			.replace(/\s+/g, ' ')
			.trim()
			.replace(/(^|[\s-])([a-z])/g, function (all, lead, ch) { return lead + ch.toUpperCase(); });
	}

	// The organization label out of a host: accounts.google.com -> 'google',
	// mail.notifications.example.co.uk -> 'example'. Taking the LAST remaining
	// label after the public suffix drops infrastructure subdomains for free.
	function orgLabel(host) {
		var parts = String(host).toLowerCase().split('.').filter(Boolean);
		if (parts.length < 2) return parts[0] || '';
		parts.pop();                                                  // the TLD
		if (parts.length > 1 && REGISTRY_SECOND_LEVELS[parts[parts.length - 1]]) {
			parts.pop();                                              // a ccTLD's second level
		}
		return parts[parts.length - 1] || '';
	}

	// Pull a human display name from a "Name <addr>" / bare-address sender string,
	// hiding the email address (it stays on the row's hover title and on the open
	// message). With no display name the sending ORGANIZATION is the identity —
	// hello@fireworks.ai reads as 'Fireworks', not 'hello' — except at a consumer
	// mail provider, where the local part is the only identity there is. That
	// exception holds only for what could actually be a person's mailbox: a role
	// address, or one below the provider's own domain, is the company writing.
	function senderName(raw) {
		if (!raw) return '(unknown)';
		raw = String(raw).trim();
		var m = /^\s*"?([^"<]*?)"?\s*<[^>]+>\s*$/.exec(raw);
		if (m && m[1].trim()) return m[1].trim();

		var addr = raw.replace(/^[^<]*</, '').replace(/>.*$/, '').trim() || raw;
		var at = addr.lastIndexOf('@');
		if (at < 1) return addr.replace(/[<>]/g, '').trim() || '(unknown)';

		var local = addr.slice(0, at);
		var host = addr.slice(at + 1);
		var org = orgLabel(host);
		if (!org) return titleCase(local) || local;
		var personal = CONSUMER_MAIL_DOMAINS[org] && !hasSubdomain(host) && !isRoleLocalPart(local);
		return personal ? (titleCase(local) || local) : titleCase(org);
	}

	// The open message shows name AND address — the address is what survived DKIM,
	// and a display name is only ever as trustworthy as the domain behind it. The
	// stored form quotes the name; render it unquoted.
	function senderFull(raw) {
		if (!raw) return '(unknown)';
		raw = String(raw).trim();
		var m = /^\s*"([^"]*)"\s*(<[^>]+>)\s*$/.exec(raw);
		return m ? (m[1].trim() + ' ' + m[2]) : raw;
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
		// Hover keeps the address reachable without widening the column. Ingest
		// strips quotes out of names, so the only quotes here are the stored form's.
		from.title = String(t.senders || '').replace(/"/g, '');
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

		// Paperclip beside the time when the thread carries a real attachment
		// (inline cid: images don't count — they're part of the body).
		if (t.has_attachment) {
			var clip = el('span', 'mbx-thread-clip');
			clip.title = 'Has an attachment';
			clip.setAttribute('aria-label', 'Has an attachment');
			clip.setAttribute('role', 'img');
			clip.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"'
				+ ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
				+ '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66'
				+ 'l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
			li.appendChild(clip);
		}

		if (state.trashView) {
			// In Trash the date that matters is when this goes for good, not when it
			// arrived. Computed server-side from the retention window.
			var purge = el('span', 'mbx-thread-time mbx-thread-purge',
				t.purge_time ? fmtDate(t.purge_time) : 'Kept');
			purge.title = t.purge_time
				? 'Permanently deleted on ' + fmtDate(t.purge_time)
				: 'Kept indefinitely — trash retention is switched off';
			li.appendChild(purge);
		} else {
			li.appendChild(el('span', 'mbx-thread-time', fmtTime(t.latest_time)));
		}

		li.addEventListener('click', function () {
			if (state.draftsView) { openDraft(t.latest_id); }
			else { openThread(t, li); }
		});
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
		parkCompose();        // the compose box lives inside an open thread — move it
		                      // out before clearing, or re-opening a thread in place
		                      // (the unlock-and-reopen path) destroys it
		pane.innerHTML = '<div class="mbx-loading">Loading…</div>';

		var url = CFG.threadUrl + '?thread_key=' + encodeURIComponent(t.thread_key)
			+ (state.aliasId != null ? '&alias_id=' + encodeURIComponent(state.aliasId) : '')
			// A discarded conversation is invisible to the read scope, so the Trash
			// view has to say which scope it is asking under.
			+ (state.trashView ? '&trash=1' : '');
		apiGet(url).then(function (data) {
			// Track the thread's locked state so content actions within it (e.g. an
			// attachment download) can prompt one-tap unlock first (§ 4.1).
			state.threadLocked = !!data.locked;
			renderThread(t, data.messages || [], data.folders || []);
			loadSenderContext(data.messages || []); // member-context panel (§ Phase 5)
			if (data.locked) {
				// Sealed thread: metadata rendered, content is placeholders. Offer a
				// one-tap unlock that re-runs this exact open on success — no navigation.
				var banner = el('div', 'mbx-unlock-banner');
				banner.appendChild(el('span', 'mbx-unlock-text', 'This mail is sealed.'));
				var btn = el('button', 'mbx-unlock-btn', 'Unlock to read');
				btn.type = 'button';
				btn.addEventListener('click', async function () {
					btn.disabled = true;
					if (await unlockVault()) { openThread(t, rowEl); }
					else { btn.disabled = false; }
				});
				banner.appendChild(btn);
				pane.insertBefore(banner, pane.firstChild);
			}
			// Opening marks the whole thread read (shared per mailbox). Not in Trash:
			// every other mutation pins the row as not-deleted, so this would be a
			// round trip that changes nothing.
			if (t.unread_count > 0 && !state.trashView) {
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
		if (state.trashView) {
			// A discarded conversation has two things it can do: come back, or go for
			// good. Read/star/archive/spam are all refused server-side while it sits
			// here, so offering them would be offering nothing.
			actions.appendChild(actionBtn('Restore', false, function () {
				apiAction({ action: 'restore', threadKey: t.thread_key, aliasId: state.aliasId })
					.then(function () { closeThread(); refreshMailboxes(); loadThreads(true); });
			}));
			actions.appendChild(actionBtn('Delete forever', true, function () {
				JoineryModal.confirm(
					'Permanently delete this conversation? This cannot be undone.',
					function () {
						apiAction({ action: 'purge', threadKey: t.thread_key, aliasId: state.aliasId })
							.then(function () { closeThread(); refreshMailboxes(); loadThreads(true); });
					},
					{ confirmLabel: 'Delete forever' }
				);
			}));
			header.appendChild(actions);
			pane.appendChild(header);
			renderThreadMessages(pane, t, messages);
			return;
		}
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
			JoineryModal.confirm('Move this conversation to Trash?', function () {
				apiAction({ action: 'delete', threadKey: t.thread_key, aliasId: state.aliasId })
					.then(function () { closeThread(); refreshMailboxes(); loadThreads(true); });
			}, { confirmLabel: 'Move to Trash' });
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

	// The message blocks alone — what the Trash view renders. No reply/forward
	// chips: writing back from a conversation that is on its way out invites a
	// reply whose source disappears on the retention clock. Restore it first.
	function renderThreadMessages(pane, t, messages) {
		messages.forEach(function (m, idx) {
			pane.appendChild(messageBlock(m, idx === messages.length - 1));
		});
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
		var from = el('div', 'mbx-message-from', senderFull(m.sender));
		if (outbound) from.appendChild(el('span', 'mbx-sent-tag', 'Sent'));
		left.appendChild(from);
		left.appendChild(el('div', 'mbx-message-meta', 'to ' + (m.recipient || '')));
		// Bcc line: only your own Sent copy carries it (its own sealed column).
		if (outbound && m.bcc) left.appendChild(el('div', 'mbx-message-meta', 'Bcc: ' + m.bcc));
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

			// Downloading a sealed attachment is a content action: while the thread
			// is locked, prompt one-tap unlock, then open the download (§ 4.1).
			chip.addEventListener('click', function (ev) {
				if (!state.threadLocked) return; // unlocked / Standard — download directly
				ev.preventDefault();
				unlockVault().then(function (ok) {
					if (ok) { state.threadLocked = false; window.open(chip.href, '_blank', 'noopener'); }
				});
			});

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
		var existing = state.draftAttachments || [];
		if (!pendingFiles.length && !existing.length) { strip.hidden = true; strip.innerHTML = ''; return; }
		strip.hidden = false;
		strip.innerHTML = '';
		// A reopened draft's already-saved attachments (they ride along on send via
		// draft_id, so they are not re-uploaded here). The × removes the file + its
		// manifest row from the draft server-side (Fix 3).
		existing.forEach(function (a) {
			var chip = el('span', 'mbx-attach-chip mbx-attach-saved');
			chip.appendChild(el('span', 'mbx-attach-chip-name', a.filename));
			chip.appendChild(el('span', 'mbx-attach-chip-size', fmtBytes2(a.size_bytes)));
			chip.title = 'Saved on this draft';
			var rm = el('button', 'mbx-attach-chip-remove', '×');
			rm.type = 'button';
			rm.setAttribute('aria-label', 'Remove ' + a.filename);
			rm.addEventListener('click', function () { removeSavedAttachment(a.id); });
			chip.appendChild(rm);
			strip.appendChild(chip);
		});
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

	// Remove one already-saved attachment from the open draft (the saved-chip ×).
	function removeSavedAttachment(attId) {
		if (!state.draftId) { return; }
		joineryApi.post(CFG.draftAttachmentDeleteUrl, {
			draft_id: String(state.draftId), attachment_id: String(attId)
		}).then(function (data) {
			if (data && data.deleted) {
				state.draftAttachments = (state.draftAttachments || []).filter(function (a) { return a.id !== attId; });
				renderAttachStrip();
			}
		}).catch(function () {});
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
		markDraftDirty();
	}

	function clearPendingFiles() {
		pendingFiles = [];
		renderAttachStrip();
	}

	// ---- rich-text composer (contenteditable + toolbar, no dependency) ----
	// Inline (pasted/dragged) images are held here (not in pendingFiles, so they
	// don't show as attachment chips); each carries a local id used as its cid:
	// placeholder. composerHtml() rewrites the display blob URL to cid:{localId};
	// the server embeds the file and rewrites cid:{localId} → a real Content-ID.
	var inlineImages = [];
	var inlineSeq = 0;

	function richEl() { return document.getElementById('mbx_body_rich'); }

	function updateRichPlaceholder() {
		var r = richEl();
		if (!r) return;
		var empty = r.textContent.trim() === '' && !r.querySelector('img');
		r.classList.toggle('mbx-rich-empty', empty);
	}

	function revokeInlineUrls() {
		inlineImages.forEach(function (im) {
			if (im.url) { try { URL.revokeObjectURL(im.url); } catch (e) {} }
		});
	}

	function clearComposer() {
		var r = richEl();
		if (r) r.innerHTML = '';
		revokeInlineUrls();
		inlineImages = [];
		updateRichPlaceholder();
	}

	function setComposerHtml(html) {
		var r = richEl();
		if (r) r.innerHTML = html || '';
		updateRichPlaceholder();
	}

	// Reopen a draft's stored inline images (Fix 7): swap each cid:{content_id} img
	// src for its signed URL and stamp data-mbx-cid, so the image renders in the editor
	// and composerHtml() maps it back to cid:{content_id} for the next save/send.
	function rewriteInlineCids(html, inline) {
		if (!inline || !inline.length) { return html; }
		var map = {};
		inline.forEach(function (im) { if (im.content_id && im.url) map[im.content_id] = im.url; });
		var tmp = document.createElement('div');
		tmp.innerHTML = html || '';
		Array.prototype.forEach.call(tmp.querySelectorAll('img'), function (img) {
			var src = img.getAttribute('src') || '';
			if (src.indexOf('cid:') === 0) {
				var cid = src.slice(4);
				if (map[cid]) {
					img.setAttribute('src', map[cid]);
					img.setAttribute('data-mbx-cid', cid);
				}
			}
		});
		return tmp.innerHTML;
	}

	// The outgoing HTML: clone the editor, swap each inline image's display blob
	// URL for its cid: placeholder, and drop the editing-only data attribute.
	function composerHtml() {
		var r = richEl();
		if (!r) return '';
		var clone = r.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('img'), function (img) {
			var lid = img.getAttribute('data-mbx-cid');
			if (lid) { img.setAttribute('src', 'cid:' + lid); img.removeAttribute('data-mbx-cid'); }
		});
		var html = clone.innerHTML.trim();
		// An empty editor serializes to '' or a stray <br> — normalize to empty.
		return (html === '<br>' || html === '<div><br></div>') ? '' : html;
	}

	// Plaintext fallback (mobile / degraded clients); the server re-derives its own
	// from the sanitized HTML, so this is only the `body` param backstop.
	function composerText() {
		var r = richEl();
		return r ? r.innerText.replace(/ /g, ' ').trim() : '';
	}

	function execCmd(cmd) {
		var r = richEl();
		if (r) r.focus();
		if (cmd === 'createLink') {
			var url = window.prompt('Link URL:', 'https://');
			if (!url) return;
			if (!/^(https?:\/\/|mailto:)/i.test(url)) { url = 'https://' + url; }
			document.execCommand('createLink', false, url);
		} else {
			document.execCommand(cmd, false, null);
		}
		updateRichPlaceholder();
	}

	function insertInlineImage(file) {
		if (!file || file.type.indexOf('image/') !== 0) return;
		var localId = 'inl' + (++inlineSeq) + Math.random().toString(36).slice(2, 6);
		// Unique filename so the server matches this upload to its manifest entry.
		var uniqueName = localId + '-' + (file.name || 'image.png').replace(/[^A-Za-z0-9._-]/g, '_');
		var renamed = new File([file], uniqueName, { type: file.type });
		var url = URL.createObjectURL(renamed);
		inlineImages.push({ localId: localId, file: renamed, url: url });
		var r = richEl();
		if (r) r.focus();
		document.execCommand('insertHTML', false,
			'<img src="' + url + '" data-mbx-cid="' + localId + '" style="max-width:100%">');
		updateRichPlaceholder();
		markDraftDirty();
	}

	function onRichPaste(e) {
		var items = e.clipboardData && e.clipboardData.items;
		if (!items) return;
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
				var file = items[i].getAsFile();
				if (file) { e.preventDefault(); insertInlineImage(file); }
			}
		}
	}

	// ---- signatures (§ Phase 3) ----
	function mailboxById(aliasId) {
		return state.mailboxes.filter(function (m) { return String(m.alias_id) === String(aliasId); })[0] || null;
	}

	// Run a formatting command against a specific editor (main composer or the
	// signature modal), keeping the caret inside it.
	function execCmdOn(editorEl, cmd) {
		editorEl.focus();
		if (cmd === 'createLink') {
			var url = window.prompt('Link URL:', 'https://');
			if (!url) return;
			if (!/^(https?:\/\/|mailto:)/i.test(url)) { url = 'https://' + url; }
			document.execCommand('createLink', false, url);
		} else {
			document.execCommand(cmd, false, null);
		}
	}

	// A small toolbar for a contenteditable — the compose toolbar minus images
	// (a signature carries none).
	function buildMiniToolbar(editorEl) {
		var tb = el('div', 'mbx-toolbar');
		[['bold', 'B'], ['italic', 'I'], ['underline', 'U'],
		 ['insertUnorderedList', '• List'], ['insertOrderedList', '1. List'],
		 ['createLink', '🔗'], ['removeFormat', '✕']].forEach(function (c) {
			var b = el('button', 'mbx-tb', c[1]);
			b.type = 'button';
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function (e) { e.preventDefault(); execCmdOn(editorEl, c[0]); });
			tb.appendChild(b);
		});
		return tb;
	}

	// The signature editor modal, opened from a mailbox's gear. Saves to the
	// caller's own grant; the server sanitizes (images stripped) and echoes it back.
	function openSignatureEditor(aliasId) {
		var mb = mailboxById(aliasId);
		if (!mb) return;

		var overlay = el('div', 'mbx-modal-overlay');
		var modal = el('div', 'mbx-modal');
		modal.appendChild(el('h3', 'mbx-modal-title', 'Signature — ' + mb.address));
		modal.appendChild(el('p', 'mbx-modal-help', 'Inserted at the bottom of every new message from this mailbox.'));

		var editor = el('div', 'mbx-rich mbx-sig-editor');
		editor.contentEditable = 'true';
		editor.innerHTML = mb.signature || '';
		modal.appendChild(buildMiniToolbar(editor));
		modal.appendChild(editor);

		var actions = el('div', 'mbx-modal-actions');
		var cancel = el('button', 'mbx-action', 'Cancel');
		cancel.type = 'button';
		cancel.addEventListener('click', function () { closeModal(overlay); });
		var save = el('button', 'mbx-action mbx-primary', 'Save');
		save.type = 'button';
		save.addEventListener('click', function () {
			save.disabled = true;
			joineryApi.post(CFG.signatureSaveUrl, { alias_id: String(aliasId), signature: editor.innerHTML })
				.then(function (data) {
					data = data || {};
					mb.signature = data.signature || '';
					closeModal(overlay);
				}).catch(function () { save.disabled = false; alert('Could not save the signature.'); });
		});
		actions.appendChild(cancel);
		actions.appendChild(save);
		modal.appendChild(actions);

		overlay.appendChild(modal);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(overlay); });
		document.body.appendChild(overlay);
		editor.focus();
	}

	function closeModal(overlay) {
		if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
	}

	// Place the caret at the very start of an element (so the user types ABOVE an
	// inserted signature).
	function placeCaretAtStart(elem) {
		try {
			var range = document.createRange();
			range.setStart(elem, 0);
			range.collapse(true);
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange(range);
		} catch (e) {}
	}

	// Insert the mailbox's signature into an EMPTY composer, with blank room above
	// it and the caret at the top. No-op when there is no signature.
	function insertSignature(aliasId) {
		var mb = mailboxById(aliasId);
		var sig = mb && mb.signature ? mb.signature : '';
		var r = richEl();
		if (!sig || !r) return;
		r.innerHTML = '<div><br></div><div><br></div><div class="mbx-sig-block">' + sig + '</div>';
		updateRichPlaceholder();
		placeCaretAtStart(r);
	}

	// The composer holds nothing but (optionally) an inserted signature block —
	// safe to swap the signature when the From identity changes.
	function isComposerEmptyExceptSignature() {
		var r = richEl();
		if (!r) return true;
		var clone = r.cloneNode(true);
		var sig = clone.querySelector('.mbx-sig-block');
		if (sig) sig.parentNode.removeChild(sig);
		return (clone.innerText || '').replace(/ /g, ' ').trim() === '' && !clone.querySelector('img');
	}

	// ---- contact panel (§ Phase 5) ----
	var contextCache = {}; // message_id -> payload, per session

	function fmtDate(iso) {
		if (!iso) return '';
		var d = new Date(String(iso).replace(' ', 'T') + 'Z');
		return isNaN(d.getTime()) ? iso : d.toLocaleDateString();
	}

	// Lazily fetch + render who the thread's counterparty is: their entry in the
	// caller's contact store, plus (admin only, CFG.canSeeContext) their account here.
	// The client sends a message id (never an address), so the endpoint can't be a
	// membership oracle.
	function loadSenderContext(messages) {
		var panel = $('#mbx-context');
		if (!panel) return;
		if (!CFG.canSeeContext) { panel.hidden = true; return; }
		var target = lastInboundOrLast(messages);
		if (!target || target.alias_id == null) { panel.hidden = true; return; }
		var mid = target.id;
		if (contextCache[mid]) { renderSenderContext(contextCache[mid]); return; }
		fetchSenderContext(mid);
	}

	function fetchSenderContext(mid) {
		var panel = $('#mbx-context');
		joineryApi.post(CFG.senderContextUrl, { message_id: String(mid) }).then(function (data) {
			data = data || {};
			if (data.locked) { if (panel) panel.hidden = true; return; }
			contextCache[mid] = data;
			renderSenderContext(data);
		}).catch(function () { if (panel) panel.hidden = true; });
	}

	function contextSection(t) { return el('div', 'mbx-context-section', t); }

	// "Name <address>" when the message carried a display name — what the contact
	// store stores, so a one-click add keeps the name it showed in the thread.
	function contactToken(data) {
		var name = (data.display_name || '').replace(/["<>]/g, '').trim();
		return name ? ('"' + name + '" <' + data.address + '>') : data.address;
	}

	// Put an address in the search box and run it — the panel's "all mail" link.
	function searchForAddress(address) {
		var box = $('#mbx-search');
		if (box) box.value = address;
		state.search = address;
		closeThread();
		loadThreads(true);
	}

	function renderSenderContext(data) {
		var panel = $('#mbx-context');
		if (!panel) return;
		panel.innerHTML = '';

		var head = el('div', 'mbx-context-head');
		head.appendChild(el('span', 'mbx-context-title', 'Contact'));
		var hide = el('button', 'mbx-iconbtn', '×');
		hide.type = 'button'; hide.title = 'Hide';
		hide.addEventListener('click', function () { panel.hidden = true; });
		head.appendChild(hide);
		panel.appendChild(head);

		if (!data.address) { panel.hidden = false; return; }

		var m = data.is_member ? data.member : null;
		var contact = data.contact || null;
		var card = el('div', 'mbx-context-card');

		// Best name available: what the contact store holds, else the account name,
		// else the display name off the message, else nothing but the address.
		var name = (contact && contact.name) || (m && m.name) || data.display_name || '';
		if (name && name !== data.address) card.appendChild(el('div', 'mbx-context-name', name));
		card.appendChild(el('div', 'mbx-context-email',
			data.address + (m && m.email_verified ? ' ✓' : '')));

		if (contact && contact.locked) {
			// A sealed contact store with no open window: whether they are a contact is
			// unknown, so say that rather than claiming they are not one.
			var lockRow = el('div', 'mbx-context-addrow');
			lockRow.appendChild(el('span', 'mbx-context-note', 'Contacts locked'));
			var unlock = el('button', 'mbx-context-add', 'Unlock');
			unlock.type = 'button';
			unlock.addEventListener('click', async function () {
				if (await unlockVault()) fetchSenderContext(data.message_id);
			});
			lockRow.appendChild(unlock);
			card.appendChild(lockRow);
		} else if (contact && contact.saved) {
			card.appendChild(el('div', 'mbx-context-badge', 'In Contacts'));
		} else {
			var row = el('div', 'mbx-context-addrow');
			row.appendChild(el('span', 'mbx-context-note', 'Not in Contacts'));
			var add = el('button', 'mbx-context-add', '+ Add');
			add.type = 'button';
			add.title = 'Add ' + data.address + ' to Contacts';
			add.addEventListener('click', function () {
				add.disabled = true;
				add.textContent = 'Adding…';   // the round trip can take a moment; say so
				joineryApi.post(CFG.contactsImportUrl, { address: contactToken(data) })
					.then(function () {
						delete contextCache[data.message_id];
						loadContacts();                       // keep compose autocomplete current
						fetchSenderContext(data.message_id);  // re-render from the server's truth
					})
					.catch(function () { add.disabled = false; add.textContent = 'Could not add'; });
			});
			row.appendChild(add);
			card.appendChild(row);
		}

		if (contact && !contact.locked) {
			// use_count counts harvests (a send, or opening a thread), not messages —
			// so the wording is "seen", which is what the store actually knows.
			var seen = 'Seen ' + contact.use_count + (contact.use_count === 1 ? ' time' : ' times');
			if (contact.last_time) seen += ' · last ' + fmtDate(contact.last_time);
			card.appendChild(el('div', 'mbx-context-since', seen));
			if (contact.first_time) {
				card.appendChild(el('div', 'mbx-context-since', 'First seen ' + fmtDate(contact.first_time)));
			}
		}

		var all = el('a', 'mbx-context-link', 'All mail with this address →');
		all.href = '#';
		all.addEventListener('click', function (e) { e.preventDefault(); searchForAddress(data.address); });
		card.appendChild(all);
		panel.appendChild(card);

		// Site account: admins only. For everyone else the server never looked, so the
		// section is absent rather than reporting an absence it can't vouch for.
		if (data.account_visible) {
			panel.appendChild(contextSection('Site account'));
			if (!m) {
				panel.appendChild(el('div', 'mbx-context-note', 'No account on this site'));
			} else {
				if (m.member_since) {
					panel.appendChild(el('div', 'mbx-context-row', 'Joined ' + fmtDate(m.member_since)));
				}
				var link = el('a', 'mbx-context-link', 'Open account →');
				link.href = m.edit_url; link.target = '_blank'; link.rel = 'noopener';
				panel.appendChild(link);
			}
		}

		if (data.orders && data.orders.length) {
			panel.appendChild(contextSection('Recent orders'));
			data.orders.forEach(function (o) {
				var row = el('div', 'mbx-context-row');
				row.appendChild(el('span', null, '#' + o.id + ' · ' + o.status));
				row.appendChild(el('span', 'mbx-context-muted', '$' + (Number(o.total) || 0).toFixed(2)));
				panel.appendChild(row);
			});
		}
		if (data.registrations && data.registrations.length) {
			panel.appendChild(contextSection('Recent registrations'));
			data.registrations.forEach(function (r) {
				panel.appendChild(el('div', 'mbx-context-row', r.event));
			});
		}
		if (data.conversations && data.conversations.count) {
			panel.appendChild(contextSection('Messaging'));
			panel.appendChild(el('div', 'mbx-context-row',
				data.conversations.count + ' conversation' + (data.conversations.count === 1 ? '' : 's')));
		}
		panel.hidden = false;
	}

	// ---- contacts (§ Phase 4): autocomplete + management ----

	// Fetch the (small) contact list once per compose open. A locked vault returns
	// no contacts → autocomplete is silently absent (typing by hand still works).
	function loadContacts() {
		joineryApi.post(CFG.contactsUrl, {}).then(function (data) {
			data = data || {};
			state.contacts = (data.locked || !data.contacts) ? [] : data.contacts;
		}).catch(function () { state.contacts = []; });
	}

	function matchContacts(token) {
		token = (token || '').trim().toLowerCase();
		if (!token) return [];
		return state.contacts.filter(function (c) {
			return c.address.indexOf(token) !== -1
				|| (c.name && c.name.toLowerCase().indexOf(token) !== -1);
		}).slice(0, 8);
	}

	// The token being typed = text after the last comma in the field.
	function currentToken(value) {
		var i = value.lastIndexOf(',');
		return i === -1 ? value : value.slice(i + 1);
	}
	function commitToken(input, address) {
		var v = input.value;
		var i = v.lastIndexOf(',');
		var prefix = i === -1 ? '' : v.slice(0, i + 1) + ' ';
		input.value = prefix + address + ', ';
		input.focus();
	}

	// A vanilla recipient-autocomplete on a To/Cc/Bcc input, filtering the fetched
	// list client-side (no server prefix-search over ciphertext). Enter/Tab commits
	// the highlighted contact; typing by hand is always available.
	function attachAutocomplete(input) {
		if (!input || input._acAttached) return;
		input._acAttached = true;
		var wrap = input.parentNode;
		if (wrap) wrap.style.position = 'relative';
		var dd = el('div', 'mbx-ac-dropdown');
		dd.hidden = true;
		if (wrap) wrap.appendChild(dd);
		var items = [];
		var active = -1;

		function hide() { dd.hidden = true; active = -1; }
		function render() {
			items = matchContacts(currentToken(input.value));
			dd.innerHTML = '';
			if (!items.length) { dd.hidden = true; return; }
			items.forEach(function (c, idx) {
				var row = el('div', 'mbx-ac-item' + (idx === active ? ' active' : ''));
				row.appendChild(el('span', 'mbx-ac-name', c.name || c.address));
				if (c.name) row.appendChild(el('span', 'mbx-ac-addr', c.address));
				row.addEventListener('mousedown', function (e) {
					e.preventDefault();
					commitToken(input, c.address); hide(); markDraftDirty();
				});
				dd.appendChild(row);
			});
			dd.hidden = false;
		}

		input.addEventListener('input', function () { active = -1; render(); });
		input.addEventListener('keydown', function (e) {
			if (dd.hidden) return;
			if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); render(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, -1); render(); }
			else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0 && items[active]) { commitToken(input, items[active].address); markDraftDirty(); } hide(); }
			else if (e.key === 'Tab') { if (active >= 0 && items[active]) { e.preventDefault(); commitToken(input, items[active].address); markDraftDirty(); } hide(); }
			else if (e.key === 'Escape') { e.preventDefault(); hide(); }
		});
		input.addEventListener('blur', function () { setTimeout(hide, 150); });
	}

	// ---- contacts manager (list / add / delete / import) ----
	function selectContacts() {
		closeThread();
		state.contactsView = true;
		state.draftsView = false;
		state.trashView = false;
		state.mailboxLabel = 'Contacts';
		setListTitle('Contacts');
		var prior = $('#mbx-folder-rail');
		if (prior) prior.parentNode.removeChild(prior);
		highlightMailbox();
		highlightDrafts();
		renderContactsManager();
	}

	function renderContactsManager() {
		$('#mbx-reader').classList.add('reading');
		var pane = $('#mbx-thread');
		parkCompose();
		pane.innerHTML = '<div class="mbx-loading">Loading contacts…</div>';

		joineryApi.post(CFG.contactsUrl, {}).then(function (data) {
			data = data || {};
			pane.innerHTML = '';
			var header = el('div', 'mbx-thread-header');
			var back = el('button', 'mbx-thread-back', null);
			back.type = 'button';
			back.appendChild(el('span', 'mbx-back-arrow', '←'));
			back.appendChild(el('span', null, 'Back'));
			back.addEventListener('click', closeThread);
			header.appendChild(back);
			header.appendChild(el('h1', null, 'Contacts'));
			pane.appendChild(header);

			if (data.locked) {
				var lb = el('div', 'mbx-unlock-banner');
				lb.appendChild(el('span', 'mbx-unlock-text', 'Unlock to view your contacts.'));
				var ub = el('button', 'mbx-unlock-btn', 'Unlock'); ub.type = 'button';
				ub.addEventListener('click', async function () { if (await unlockVault()) renderContactsManager(); });
				lb.appendChild(ub);
				pane.appendChild(lb);
				return;
			}
			state.contacts = data.contacts || [];

			// Add + import controls.
			var tools = el('div', 'mbx-contacts-tools');
			var addInput = document.createElement('input');
			addInput.type = 'text';
			addInput.className = 'mbx-contacts-add';
			addInput.placeholder = 'Add a contact (Name <email>)';
			var addBtn = el('button', 'mbx-action mbx-primary', 'Add'); addBtn.type = 'button';
			var doAdd = function () {
				var v = addInput.value.trim();
				if (!v) return;
				addBtn.disabled = true;
				joineryApi.post(CFG.contactsImportUrl, { address: v }).then(function () {
					addInput.value = ''; addBtn.disabled = false; renderContactsManager();
				}).catch(function () { addBtn.disabled = false; alert('That is not a valid email address.'); });
			};
			addBtn.addEventListener('click', doAdd);
			addInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
			tools.appendChild(addInput);
			tools.appendChild(addBtn);

			var importLabel = el('label', 'mbx-action mbx-contacts-import', 'Import .vcf / .csv');
			var importInput = document.createElement('input');
			importInput.type = 'file';
			importInput.accept = '.vcf,.csv,text/vcard,text/csv';
			importInput.style.display = 'none';
			importInput.addEventListener('change', function () {
				if (!importInput.files || !importInput.files.length) return;
				var fd = new FormData();
				fd.append('file', importInput.files[0], importInput.files[0].name);
				fetch(CFG.contactsImportUrl, { method: 'POST', credentials: 'same-origin',
					headers: { 'X-Joinery-Csrf': joineryApi.csrf() }, body: fd })
					.then(function (r) { return r.json(); }).then(function (env) {
						var d = (env && env.data) ? env.data : {};
						alert('Imported ' + (d.imported || 0) + ', skipped ' + (d.skipped || 0) + '.');
						renderContactsManager();
					}).catch(function () { alert('Import failed.'); });
			});
			importLabel.appendChild(importInput);
			tools.appendChild(importLabel);
			pane.appendChild(tools);

			var list = el('div', 'mbx-contacts-list');
			if (!state.contacts.length) {
				list.appendChild(el('div', 'mbx-loading', 'No contacts yet. They fill in as you send and read mail.'));
			}
			state.contacts.forEach(function (c) {
				var rowEl = el('div', 'mbx-contact-row');
				var info = el('div', 'mbx-contact-info');
				info.appendChild(el('span', 'mbx-contact-name', c.name || c.address));
				if (c.name) info.appendChild(el('span', 'mbx-contact-addr', c.address));
				rowEl.appendChild(info);
				var del = el('button', 'mbx-contact-del', '×'); del.type = 'button'; del.title = 'Delete';
				del.addEventListener('click', function () {
					joineryApi.post(CFG.contactDeleteUrl, { contact_id: String(c.id) })
						.then(function () { rowEl.parentNode.removeChild(rowEl); })
						.catch(function () {});
				});
				rowEl.appendChild(del);
				list.appendChild(rowEl);
			});
			pane.appendChild(list);
		}).catch(function () { pane.innerHTML = '<div class="mbx-loading">Contacts could not be loaded.</div>'; });
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

	// Reset Bcc to hidden-behind-toggle with an empty field, on every compose open.
	function resetBcc() {
		var row = document.getElementById('mbx-bcc-row');
		if (row) row.hidden = true;
		var toggle = document.getElementById('mbx-bcc-toggle');
		if (toggle) toggle.hidden = false;
		var f = document.getElementById('mbx_bcc');
		if (f) f.value = '';
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

		clearComposer();
		resetBcc();
		clearPendingFiles();
		resetDraftState();
		state.draftAlias = source.alias_id;   // implicit From identity for autosave
		insertSignature(source.alias_id);     // signature at the bottom, caret above (§ Phase 3)
		loadContacts();                       // recipient autocomplete (§ Phase 4)

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
		clearComposer();
		resetBcc();
		clearPendingFiles();
		resetDraftState();
		var aliasSel = document.getElementById('mbx_alias_id');
		state.draftAlias = aliasSel ? aliasSel.value : state.aliasId;
		insertSignature(state.draftAlias);    // signature for the chosen From (§ Phase 3)
		loadContacts();                       // recipient autocomplete (§ Phase 4)

		var chips = document.querySelector('.mbx-reply-actions');
		if (chips) chips.hidden = true;
		var compose = $('#mbx-compose');
		compose.hidden = false;
		compose.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		document.getElementById('mbx_to').focus();
	}

	// ---- drafts (autosave / reopen / discard) ----
	var draftTimer = null;
	// Incremented every time the compose panel is reset/reopened (resetDraftState);
	// an in-flight autosave captures it and ignores its own resolution if it changed,
	// so a stale save can never write its draft id into the next message (Fix 4).
	var composeGen = 0;

	// The From alias the compose is bound to: the From selector when it's shown
	// (new/draft), else the implicit source-message alias (reply/forward).
	function composeAliasId() {
		var fromRow = document.getElementById('mbx-from-row');
		var sel = document.getElementById('mbx_alias_id');
		if (fromRow && !fromRow.hidden && sel) return sel.value;
		return state.draftAlias != null ? String(state.draftAlias) : '';
	}

	// The shared compose payload (used by both autosave → draft_save and submit → send).
	// includeFiles=false omits attachment bytes + the inline manifest — the fields-only
	// shape the keepalive beforeunload save uses to stay under the ~64 KB budget (Fix 8);
	// the bytes were already persisted by the debounced autosaves. Returns the FormData
	// plus snapshots of exactly which pendingFiles / unsaved inlineImages were appended,
	// so a resolving autosave can drop precisely those from the resend queue (Fix 3).
	function buildComposeBody(includeFiles) {
		var body = new FormData();
		body.append('mode', document.getElementById('mbx_mode').value);
		body.append('source_id', document.getElementById('mbx_source_id').value);
		body.append('alias_id', composeAliasId());
		body.append('to', document.getElementById('mbx_to').value);
		body.append('cc', document.getElementById('mbx_cc').value);
		var bccEl = document.getElementById('mbx_bcc');
		body.append('bcc', bccEl ? bccEl.value : '');
		body.append('subject', document.getElementById('mbx_subject').value);
		body.append('body_html', composerHtml());
		body.append('body', composerText());
		var sentFiles = [];
		var sentInline = [];
		if (includeFiles) {
			pendingFiles.forEach(function (f) { body.append('attachments[]', f, f.name); sentFiles.push(f); });
			var manifest = {};
			inlineImages.forEach(function (im) {
				// A saved inline image is never re-appended and never re-listed — the
				// server already holds its bytes and re-embeds it from storage on send.
				if (im.saved) { return; }
				manifest[im.localId] = im.file.name;
				body.append('attachments[]', im.file, im.file.name);
				sentInline.push(im);
			});
			if (Object.keys(manifest).length) {
				body.append('inline_manifest', JSON.stringify(manifest));
			}
		}
		return { body: body, sentFiles: sentFiles, sentInline: sentInline };
	}

	// True when the compose has anything worth saving (never create an empty draft).
	function hasComposeContent() {
		var v = function (id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; };
		var hasImg = !!richEl() && !!richEl().querySelector('img');
		return !!(v('mbx_to') || v('mbx_cc') || v('mbx_bcc') || v('mbx_subject')
			|| composerText() || hasImg || pendingFiles.length);
	}

	function markDraftDirty() {
		if (composeHidden()) return;
		state.draftDirty = true;
		clearTimeout(draftTimer);
		draftTimer = setTimeout(function () { autosaveDraft(false); }, 3000);
	}

	// Persist the current compose as a draft. sync=true uses a keepalive fetch for
	// the beforeunload path (fields only, no file bytes — Fix 8). cb runs after the
	// save resolves.
	function autosaveDraft(sync, cb) {
		if (!hasComposeContent() || state.draftSaving) { if (cb) cb(); return; }
		// The compose the resolve handler is allowed to mutate (Fix 4): if the panel
		// was reset/reopened while the save was in flight, the resolved id belongs to
		// a compose that no longer exists — clear draftSaving but touch nothing else.
		var gen = composeGen;
		var built = buildComposeBody(!sync);
		var body = built.body;
		if (state.draftId) body.append('draft_id', String(state.draftId));
		state.draftSaving = true;
		var opts = { method: 'POST', credentials: 'same-origin',
			headers: { 'X-Joinery-Csrf': joineryApi.csrf() }, body: body };
		if (sync) opts.keepalive = true;
		fetch(CFG.draftSaveUrl, opts).then(function (r) { return r.json(); }).then(function (env) {
			state.draftSaving = false;
			if (gen !== composeGen) { if (cb) cb(); return; }   // stale — see above
			var data = (env && env.data) ? env.data : {};
			if (data.draft_id) {
				state.draftId = data.draft_id;
				state.draftDirty = false;
				// Drop exactly the files/inline this save persisted from the resend
				// queue (files added mid-flight survive); the server's authoritative
				// list replaces the saved-chip strip (Fix 3).
				built.sentFiles.forEach(function (f) {
					var i = pendingFiles.indexOf(f);
					if (i !== -1) pendingFiles.splice(i, 1);
				});
				built.sentInline.forEach(function (im) { im.saved = true; });
				state.draftAttachments = data.attachments || [];
				renderAttachStrip();
			}
			if (cb) cb();
		}).catch(function () { state.draftSaving = false; if (cb) cb(); });
	}

	function resetDraftState() {
		clearTimeout(draftTimer);
		// Bump the compose generation so a still-in-flight autosave's resolve handler
		// knows its draft id belongs to a compose that is no longer open (Fix 4).
		composeGen++;
		state.draftId = null;
		state.draftAlias = null;
		state.draftDirty = false;
		state.draftAttachments = [];
	}

	// Open a saved draft from the Drafts list into the composer.
	function openDraft(draftId) {
		joineryApi.post(CFG.draftGetUrl, { draft_id: String(draftId) }).then(async function (data) {
			data = data || {};
			if (data.locked) {
				if (await unlockVault()) { openDraft(draftId); }
				return;
			}
			if (!data.draft_id) { alert('This draft could not be opened.'); return; }
			showDraftComposer(data);
		}).catch(function () { alert('This draft could not be opened.'); });
	}

	// Render the reading pane as just the composer (a draft has no conversation).
	function showDraftComposer(data) {
		$('#mbx-reader').classList.add('reading');
		$('#mbx-read-pane').scrollTop = 0;
		var pane = $('#mbx-thread');
		parkCompose();
		pane.innerHTML = '';

		var header = el('div', 'mbx-thread-header');
		var back = el('button', 'mbx-thread-back', null);
		back.type = 'button';
		back.appendChild(el('span', 'mbx-back-arrow', '←'));
		back.appendChild(el('span', null, 'Back to drafts'));
		back.addEventListener('click', function () { closeThread(); });
		header.appendChild(back);
		header.appendChild(el('h1', null, data.subject || '(no subject)'));
		pane.appendChild(header);

		var compose = document.getElementById('mbx-compose');
		pane.appendChild(compose);
		populateComposerFromDraft(data);
	}

	function populateComposerFromDraft(data) {
		$('#mbx-compose-title').textContent = 'Draft';
		hideComposeError();
		document.getElementById('mbx_mode').value = data.mode || 'new';
		document.getElementById('mbx_source_id').value = data.source_id || '';

		populateFromSelect(data.alias_id);
		var fromRow = document.getElementById('mbx-from-row');
		if (fromRow) fromRow.hidden = false;

		document.getElementById('mbx_to').value = data.to || '';
		document.getElementById('mbx_cc').value = data.cc || '';
		if (data.bcc) {
			document.getElementById('mbx_bcc').value = data.bcc;
			var row = document.getElementById('mbx-bcc-row'); if (row) row.hidden = false;
			var tgl = document.getElementById('mbx-bcc-toggle'); if (tgl) tgl.hidden = true;
		} else {
			resetBcc();
		}
		document.getElementById('mbx_subject').value = data.subject || '';
		// Rewrite each cid:{content_id} img src to its signed URL and tag it with
		// data-mbx-cid so the editor displays the stored inline image and composerHtml()
		// re-emits cid:{content_id} on later saves/sends (Fix 7). The bytes are never
		// re-uploaded — the server re-embeds them from storage on send.
		setComposerHtml(rewriteInlineCids(data.body_html || '', data.inline || []));
		clearPendingFiles();

		state.draftId = data.draft_id;
		state.draftAlias = data.alias_id;
		state.draftDirty = false;
		state.draftAttachments = data.attachments || [];
		loadContacts();                       // recipient autocomplete (§ Phase 4)
		renderAttachStrip();

		var chips = document.querySelector('.mbx-reply-actions');
		if (chips) chips.hidden = true;
		$('#mbx-compose').hidden = false;
		document.getElementById('mbx_to').focus();
	}

	// Close the compose panel. discard=true deletes the saved draft; otherwise the
	// panel saves-and-closes (the panel is always safe to close, §Phase 2).
	function closeCompose(discard) {
		clearTimeout(draftTimer);
		if (discard === true) {
			if (state.draftId) {
				var did = state.draftId;
				joineryApi.post(CFG.draftDeleteUrl, { draft_id: String(did) })
					.then(function () { refreshMailboxes(); if (state.draftsView) loadThreads(true); })
					.catch(function () {});
			}
			resetDraftState();
		} else if (state.draftDirty && hasComposeContent()) {
			autosaveDraft(false, function () {
				refreshMailboxes();
				if (state.draftsView) loadThreads(true);
			});
			resetDraftState();
		} else {
			resetDraftState();
		}
		var panel = $('#mbx-compose');
		if (panel) panel.hidden = true;
		var chips = document.querySelector('.mbx-reply-actions');
		if (chips) chips.hidden = false;
	}

	// True when there is no open compose panel — either it is hidden or (defensively)
	// it is not in the DOM. Navigation must never depend on the panel being present:
	// closeThread() runs closeCompose() first, so a throw here would strand the reader
	// in the reading view with a dead back button and a dead mailbox rail.
	function composeHidden() {
		var panel = $('#mbx-compose');
		return !panel || panel.hidden;
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
		clearTimeout(draftTimer);

		// Wait for any in-flight autosave to settle before sending, so the same file
		// cannot arrive via both this request and a concurrent draft persist (Fix 3).
		// Bounded: ~250 ms polls up to ~5 s, then proceed regardless.
		var waited = 0;
		(function whenSaved() {
			if (state.draftSaving && waited < 5000) { waited += 250; setTimeout(whenSaved, 250); return; }
			sendComposeNow(e, btn);
		})();
	}

	function sendComposeNow(e, btn) {
		// The shared compose payload (rich HTML + plaintext + attachments + inline
		// manifest), plus the reader token and draft_id (a saved draft morphs into
		// the Sent row, reusing its already-uploaded attachments).
		var body = buildComposeBody(true).body;
		body.append('_csrf_token', document.getElementById('mbx_csrf').value);
		if (state.draftId) body.append('draft_id', String(state.draftId));

		// Multipart send (attachments) — joineryApi.post is JSON-only, so this
		// keeps a direct fetch and borrows only the shared CSRF read.
		fetch(CFG.sendUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Joinery-Csrf': joineryApi.csrf() },
			body: body
		}).then(function (r) { return r.json(); }).then(async function (env) {
			if (btn) btn.disabled = false;
			var data = (env && env.data) ? env.data : {};
			if (!(env && env.errortype) && (data.outbound_id || data.pending_sent_ingest)) {
				// The draft (if any) was morphed into the Sent row (or deleted in the
				// Gmail pending-ingest case) server-side — drop our handle without a
				// save-and-close.
				var wasDrafts = state.draftsView;
				resetDraftState();
				closeCompose();
				if (wasDrafts) {
					// Sent from the Drafts view — return to the (now shorter) list.
					closeThread();
					loadThreads(true);
				} else if (state.threadKey != null) {
					// Re-open the thread so the new outbound row renders in the dialog.
					reopenCurrentThread();
				} else {
					// New message: no thread was open — refresh the list so the new
					// conversation appears without a manual reload.
					loadThreads(true);
				}
				refreshMailboxes();
			} else if (data.locked) {
				// Fortress compose while locked: one-tap unlock, then resubmit the
				// same draft without re-navigation (specs/mailbox_security_levels.md § 4.1).
				showComposeError('Your vault is locked. Unlocking…');
				if (await unlockVault()) { hideComposeError(); submitCompose(e); }
				else { showComposeError('Unlock is needed to send from this address.'); }
			} else {
				showComposeError((env && env.error) || 'The message could not be sent.');
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
		var ctx = document.getElementById('mbx-context'); if (ctx) ctx.hidden = true;
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
		// Enter commits the search now and leaves any open conversation — the
		// reading view replaces the list entirely, so results would otherwise
		// land behind the message being read. Same idiom as selectFolder /
		// selectMailbox: a view switch closes the thread first.
		$('#mbx-search').addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') { return; }
			e.preventDefault();
			clearTimeout(searchTimer);
			state.search = e.target.value.trim();
			closeThread();
			loadThreads(true);
		});

		$('#mbx-refresh').addEventListener('click', function () {
			refreshMailboxes();
			loadThreads(true);
			updateSetupBanner(true);   // Refresh means "check again", setup included
		});

		// Coming back to a reader left open in another tab: the operator may have
		// been off fixing the very thing the banner is complaining about (the
		// Setup tab stamps its verdict as it renders), so re-ask rather than
		// trusting what this page decided minutes ago. Cheap — the server answers
		// from its own memory unless that has aged out.
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) { updateSetupBanner(false, true); }
		});
		$('#mbx-more').addEventListener('click', function () { state.page += 1; loadThreads(false); });

		// Any lock — the header chip's Lock now, or a heartbeat learning the
		// window ended elsewhere — re-seals the reader: sealed rows back to
		// placeholders. Explicit lock lives on the platform lock chip
		// (docs/sealed_vault.md § The lock chip), not in the reader.
		document.addEventListener('joinery:vault-locked', function () {
			stopHeartbeat();
			refreshMailboxes();
			loadThreads(true);
			// Collapse any open sealed thread back to placeholders.
			if (state.threadKey) {
				var open = state.openThread || { thread_key: state.threadKey, subject: '' };
				openThread(open, null);
			}
		});

		// An unlock that happened outside the reader (the header chip) reveals
		// sealed content in place; a reader-initiated unlock re-runs its own
		// action instead (selfUnlocking).
		document.addEventListener('joinery:vault-unlocked', function () {
			if (selfUnlocking) { return; }
			refreshMailboxes();
			loadThreads(true);
		});

		var newMsgBtn = $('#mbx-new-message');
		if (newMsgBtn) newMsgBtn.addEventListener('click', openComposeNew);

		// Compose: × is save-and-close (the panel is always safe to close, § Phase 2);
		// the 🗑 discards after a confirm; a fetch-intercepted submit sends.
		var closeBtn = $('#mbx-compose-close');
		if (closeBtn) closeBtn.addEventListener('click', function () { closeCompose(); });
		var discardBtn = $('#mbx-compose-discard');
		if (discardBtn) discardBtn.addEventListener('click', function () {
			if (state.draftId || hasComposeContent()) {
				if (!confirm('Discard this draft?')) { return; }
			}
			closeCompose(true);
		});
		var composeForm = document.getElementById('mbx_compose_form');
		if (composeForm) composeForm.addEventListener('submit', submitCompose);

		// Autosave: any edit to a compose field marks the draft dirty (debounced save).
		['mbx_to', 'mbx_cc', 'mbx_bcc', 'mbx_subject'].forEach(function (id) {
			var f = document.getElementById(id);
			if (f) f.addEventListener('input', markDraftDirty);
		});
		var aliasSel = document.getElementById('mbx_alias_id');
		if (aliasSel) aliasSel.addEventListener('change', function () {
			state.draftAlias = aliasSel.value;
			// Swap the signature to the newly-chosen From, but only when the user
			// hasn't started writing (never clobber real content).
			if (isComposerEmptyExceptSignature()) { insertSignature(aliasSel.value); }
			markDraftDirty();
		});
		// Recipient autocomplete on To/Cc/Bcc (§ Phase 4).
		['mbx_to', 'mbx_cc', 'mbx_bcc'].forEach(function (id) { attachAutocomplete(document.getElementById(id)); });

		// Last-ditch save when the page is being torn down mid-compose.
		window.addEventListener('beforeunload', function () {
			if (!composeHidden() && state.draftDirty && hasComposeContent()) {
				autosaveDraft(true);
			}
		});

		// Rich-text toolbar: mousedown-preventDefault keeps the caret/selection in the
		// editor while a button is pressed, so execCommand acts on the right range.
		var toolbar = document.getElementById('mbx-toolbar');
		if (toolbar) {
			toolbar.addEventListener('mousedown', function (e) { e.preventDefault(); });
			toolbar.addEventListener('click', function (e) {
				var btn = e.target.closest ? e.target.closest('.mbx-tb') : null;
				if (!btn) return;
				e.preventDefault();
				execCmd(btn.getAttribute('data-cmd'));
			});
		}
		var rich = richEl();
		if (rich) {
			rich.addEventListener('input', function () { updateRichPlaceholder(); markDraftDirty(); });
			rich.addEventListener('paste', onRichPaste);
			// Image dropped onto the editor → inline; other files → attachments. Stop
			// propagation so the panel-level drop handler doesn't also add them.
			rich.addEventListener('drop', function (e) {
				if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
				e.preventDefault();
				e.stopPropagation();
				var nonImg = [];
				Array.prototype.forEach.call(e.dataTransfer.files, function (f) {
					if (f.type.indexOf('image/') === 0) { insertInlineImage(f); }
					else { nonImg.push(f); }
				});
				if (nonImg.length) { addFiles(nonImg); }
				var panel = $('#mbx-compose');
				if (panel) panel.classList.remove('mbx-compose-dragover');
			});
			updateRichPlaceholder();
		}

		// Bcc reveal (Gmail-style): the toggle shows the field, then hides itself.
		var bccToggle = document.getElementById('mbx-bcc-toggle');
		if (bccToggle) bccToggle.addEventListener('click', function () {
			var row = document.getElementById('mbx-bcc-row');
			if (row) row.hidden = false;
			bccToggle.hidden = true;
			var f = document.getElementById('mbx_bcc');
			if (f) f.focus();
		});

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
			else if (!composeHidden()) { closeCompose(); }
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
