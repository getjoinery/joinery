/**
 * Messages app.
 *
 * One renderer for both halves of the app's life: the conversation list and
 * thread are drawn from the JSON the page embedded, and every later change —
 * new messages, reactions, tombstones, read positions, typing, list movement —
 * arrives through the same messenger_poll payload and goes through the same
 * draw. There is no second code path for "live" updates.
 *
 * The poll loop itself is the shared helper (assets/js/joinery-poll.js): fast
 * with a conversation open, slow on the list alone, paused while the tab is
 * hidden, and poked immediately after the member does something.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var root = document.getElementById('msgr');
	var bootEl = document.getElementById('msgr-boot');
	if (!root || !bootEl) { return; }

	var boot = JSON.parse(bootEl.textContent || '{}');

	var REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉', '🔥'];

	function icon(paths) {
		return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" '
			+ 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
			+ 'stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
	}
	var ICONS = {
		react: icon('<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/>'
			+ '<line x1="9" y1="9.5" x2="9.01" y2="9.5"/><line x1="15" y1="9.5" x2="15.01" y2="9.5"/>'),
		reply: icon('<polyline points="9 14 4 9 9 4"/><path d="M20 20v-5a6 6 0 0 0-6-6H4"/>'),
		trash: icon('<polyline points="3 6 5 6 21 6"/>'
			+ '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
			+ '<path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'),
		check: icon('<polyline points="20 6 9 17 4 12"/>'),
		clock: icon('<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>'),
		warn:  icon('<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="13"/>'
			+ '<line x1="12" y1="16" x2="12.01" y2="16"/>')
	};

	var state = {
		me: boot.user_id,
		settings: boot.settings || {},
		conversations: boot.conversations || [],
		openId: null,
		messages: [],
		rows: {},              // message id -> the row element drawing it
		cursor: 0,             // highest message id we have
		threadReady: false,    // first page landed; the poll may ask after cursor
		hasMoreBack: false,
		replyTo: null,
		pending: [],           // uploads waiting to be sent
		participants: [],
		typingOut: false,
		listSince: null,
		filter: ''
	};

	// ---- Elements ----------------------------------------------------
	var el = {
		list:      document.getElementById('msgr-list'),
		filter:    document.getElementById('msgr-filter'),
		thread:    document.getElementById('msgr-thread'),
		head:      document.getElementById('msgr-thread-head'),
		title:     document.getElementById('msgr-title'),
		subtitle:  document.getElementById('msgr-subtitle'),
		level:     document.getElementById('msgr-level'),
		menu:      document.getElementById('msgr-menu'),
		log:       document.getElementById('msgr-log'),
		receipt:   document.getElementById('msgr-receipt'),
		typing:    document.getElementById('msgr-typing'),
		composer:  document.getElementById('msgr-composer'),
		input:     document.getElementById('msgr-input'),
		send:      document.getElementById('msgr-send'),
		attach:    document.getElementById('msgr-attach'),
		file:      document.getElementById('msgr-file'),
		tray:      document.getElementById('msgr-tray'),
		empty:     document.getElementById('msgr-empty'),
		error:     document.getElementById('msgr-error'),
		back:      document.getElementById('msgr-back'),
		replyBar:  document.getElementById('msgr-reply-bar'),
		replyBody: document.getElementById('msgr-reply-body'),
		replyCancel: document.getElementById('msgr-reply-cancel'),
		newBtn:    document.getElementById('msgr-new'),
		peopleDialog: document.getElementById('msgr-people-dialog'),
		peopleTitle:  document.getElementById('msgr-people-title'),
		peopleSearch: document.getElementById('msgr-people-search'),
		peopleResults: document.getElementById('msgr-people-results'),
		picked:    document.getElementById('msgr-picked'),
		groupName: document.getElementById('msgr-group-name'),
		nameLabel: document.getElementById('msgr-name-label'),
		peopleConfirm: document.getElementById('msgr-people-confirm'),
		infoDialog: document.getElementById('msgr-info-dialog'),
		rename:    document.getElementById('msgr-rename'),
		renameSave: document.getElementById('msgr-rename-save'),
		members:   document.getElementById('msgr-members'),
		addMember: document.getElementById('msgr-add-member'),
		photoBtn:  document.getElementById('msgr-photo-btn'),
		photoFile: document.getElementById('msgr-photo-file'),
		emojiDialog: document.getElementById('msgr-emoji-dialog'),
		emojiGrid: document.getElementById('msgr-emoji-grid'),
		protectDialog: document.getElementById('msgr-protect-dialog'),
		protectNote: document.getElementById('msgr-protect-note'),
		protectSave: document.getElementById('msgr-protect-save'),
		newLevelPicker: document.getElementById('msgr-new-level-picker'),
		remote: document.getElementById('msgr-remote'),
		remoteAddress: document.getElementById('msgr-remote-address'),
		remoteStatus: document.getElementById('msgr-remote-status'),
		remoteCheck: document.getElementById('msgr-remote-check')
	};

	// ---- Small helpers -----------------------------------------------

	function api(action, body) {
		return window.joineryApi.post('messenger/' + action, body);
	}

	function fail(err) {
		el.error.textContent = (err && err.message) || 'Something went wrong.';
		setTimeout(function () { el.error.textContent = ''; }, 6000);
	}

	function node(tag, className, text) {
		var n = document.createElement(tag);
		if (className) { n.className = className; }
		if (text !== undefined && text !== null) { n.textContent = text; }
		return n;
	}

	/** Server times are UTC 'Y-m-d H:i:s'. */
	function toDate(value) {
		if (!value) { return null; }
		return new Date(String(value).replace(' ', 'T') + 'Z');
	}

	function clockTime(value) {
		var d = toDate(value);
		return d ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';
	}

	/** Relative-ish stamp for the conversation list. */
	function listTime(value) {
		var d = toDate(value);
		if (!d) { return ''; }
		var now = new Date();
		if (d.toDateString() === now.toDateString()) {
			return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
		}
		var days = (now - d) / 86400000;
		if (days < 7) { return d.toLocaleDateString([], { weekday: 'short' }); }
		return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
	}

	function fileSize(bytes) {
		if (!bytes) { return ''; }
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = 0;
		while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
		return (i === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[i];
	}

	function initials(name) {
		return String(name || '?').trim().split(/\s+/).slice(0, 2)
			.map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
	}

	function conversationById(id) {
		for (var i = 0; i < state.conversations.length; i++) {
			if (state.conversations[i].id === id) { return state.conversations[i]; }
		}
		return null;
	}

	function atBottom() {
		return el.log.scrollHeight - el.log.scrollTop - el.log.clientHeight < 80;
	}

	function scrollToBottom() {
		el.log.scrollTop = el.log.scrollHeight;
	}

	// ---- The conversation list ---------------------------------------

	function renderList() {
		el.list.textContent = '';

		var term = state.filter.toLowerCase();
		var shown = state.conversations.filter(function (c) {
			if (!term) { return true; }
			var hay = (c.title || '') + ' ' + ((c.last_message && c.last_message.excerpt) || '');
			return hay.toLowerCase().indexOf(term) !== -1;
		});

		if (!shown.length) {
			var empty = node('li', 'msgr-list-empty',
				state.conversations.length ? 'Nothing matches that.' : 'No conversations yet.');
			el.list.appendChild(empty);
			return;
		}

		shown.forEach(function (c) {
			var li = node('li');
			var btn = node('button', 'msgr-item');
			btn.type = 'button';
			if (c.id === state.openId) { btn.classList.add('msgr-item--open'); }
			if (c.unread > 0) { btn.classList.add('msgr-item--unread'); }

			if (c.avatar) {
				var img = node('img', 'msgr-item-avatar');
				img.src = c.avatar;
				img.alt = '';
				btn.appendChild(img);
			} else {
				btn.appendChild(node('span', 'msgr-item-initials', initials(c.title)));
			}

			var body = node('div', 'msgr-item-body');
			var titleRow = node('div', 'msgr-item-title');
			titleRow.appendChild(node('span', 'msgr-item-name', c.title));
			if (c.last_message) {
				titleRow.appendChild(node('span', 'msgr-item-time', listTime(c.last_message.time)));
			}
			body.appendChild(titleRow);

			var preview = node('div', 'msgr-item-preview');
			var text = '';
			if (c.last_message) {
				if (c.last_message.type === 'system') {
					text = c.last_message.excerpt;
				} else {
					var who = c.last_message.is_mine ? 'You'
						: (c.is_group ? c.last_message.sender_name : '');
					text = (who ? who + ': ' : '') + (c.last_message.excerpt || 'Attachment');
				}
			} else {
				text = 'No messages yet';
			}
			preview.appendChild(node('span', 'msgr-item-excerpt', text));
			if (c.is_muted) { preview.appendChild(node('span', 'msgr-mute', 'Muted')); }
			if (c.unread > 0) { preview.appendChild(node('span', 'msgr-badge', String(c.unread))); }
			body.appendChild(preview);

			btn.appendChild(body);
			btn.addEventListener('click', function () { openConversation(c.id); });
			li.appendChild(btn);
			el.list.appendChild(li);
		});
	}

	/** Fold a fresh set of conversation payloads into the list, newest first. */
	function mergeConversations(rows, isFullList) {
		if (isFullList) {
			state.conversations = rows;
		} else {
			rows.forEach(function (row) {
				var existing = conversationById(row.id);
				if (existing) {
					state.conversations.splice(state.conversations.indexOf(existing), 1);
				}
				state.conversations.unshift(row);
			});
		}
		state.conversations.sort(function (a, b) {
			var at = (a.last_message && a.last_message.time) || '';
			var bt = (b.last_message && b.last_message.time) || '';
			return bt.localeCompare(at);
		});
		renderList();
	}

	// ---- The open thread ---------------------------------------------

	function openConversation(id) {
		if (id === state.openId) {
			root.dataset.pane = 'thread';
			return;
		}
		state.openId = id;
		state.messages = [];
		state.rows = {};
		state.cursor = 0;
		// The poll leaves this conversation alone until the first page has
		// landed: a tick racing the thread fetch would ask "everything after 0"
		// and pull the OLDEST page into an empty view.
		state.threadReady = false;
		state.replyTo = null;
		state.pending = [];
		renderTray();
		renderReplyBar();
		el.log.textContent = '';
		root.dataset.pane = 'thread';
		syncUrl();

		api('messenger_thread', { conversation_id: id, mark_read: true }).then(function (data) {
			if (state.openId !== id) { return; }
			applyConversation(data.conversation);
			state.hasMoreBack = data.has_more;
			addMessages(data.messages, true);
			scrollToBottom();
			markLocallyRead(id);
			renderList();
			state.threadReady = true;
			loop.poke();
		}).catch(fail);
	}

	function closeConversation() {
		state.openId = null;
		root.dataset.pane = 'list';
		el.head.hidden = true;
		el.composer.hidden = true;
		el.log.hidden = true;
		el.empty.hidden = false;
		el.log.textContent = '';
		el.receipt.textContent = '';
		el.typing.textContent = '';
		state.replyTo = null;
		renderReplyBar();
		syncUrl();
		renderList();
	}

	function syncUrl() {
		var url = state.openId ? '/profile/messenger?c=' + state.openId : '/profile/messenger';
		if (window.history && window.history.replaceState) {
			window.history.replaceState({}, '', url);
		}
	}

	function applyConversation(payload) {
		if (!payload) { return; }
		var existing = conversationById(payload.id);
		if (existing) {
			state.conversations[state.conversations.indexOf(existing)] = payload;
		} else {
			state.conversations.unshift(payload);
		}
		state.participants = payload.participants || [];

		el.head.hidden = false;
		el.composer.hidden = false;
		el.log.hidden = false;
		el.empty.hidden = true;
		el.title.textContent = payload.title;
		el.subtitle.textContent = payload.is_group
			? (payload.participants.length + ' people')
			: '';

		if (payload.protection_level && payload.protection_level !== 'standard') {
			el.level.hidden = false;
			el.level.textContent = payload.protection_label;
			el.level.className = 'msgr-level-chip msgr-level-chip--' + payload.protection_level;
		} else {
			el.level.hidden = true;
		}

		var muteBtn = el.menu.querySelector('[data-msgr-menu="mute"]');
		muteBtn.textContent = payload.is_muted ? 'Unmute conversation' : 'Mute conversation';
		el.menu.querySelector('[data-msgr-menu="info"]').hidden = !payload.is_group;
		el.menu.querySelector('[data-msgr-menu="leave"]').hidden = !payload.is_group;

		renderReceipts();
	}

	/**
	 * Add messages to the thread.
	 *
	 * Rows are built once and kept, so a poll that brings nothing new touches no
	 * DOM at all and never interrupts a selection or a scroll.
	 */
	function addMessages(messages, replace) {
		if (replace) {
			el.log.textContent = '';
			state.rows = {};
			state.messages = [];
			if (state.hasMoreBack) { renderEarlierButton(); }
		}
		if (!messages || !messages.length) { return; }

		var stick = replace || atBottom();

		messages.forEach(function (message) {
			if (state.rows[message.id]) { return; }
			var previous = state.messages[state.messages.length - 1] || null;
			state.messages.push(message);
			var row = buildRow(message, previous);
			state.rows[message.id] = row;
			el.log.appendChild(row);
			if (message.id > state.cursor) { state.cursor = message.id; }
		});

		if (stick) { scrollToBottom(); }
	}

	/** Older messages go on top, keeping the reader's place. */
	function prependMessages(messages) {
		if (!messages || !messages.length) { return; }
		var before = el.log.scrollHeight;
		var anchor = el.log.querySelector('.jy-chat-row, .jy-chat-system');
		messages.forEach(function (message, index) {
			if (state.rows[message.id]) { return; }
			state.messages.splice(index, 0, message);
			var row = buildRow(message, index > 0 ? messages[index - 1] : null);
			state.rows[message.id] = row;
			el.log.insertBefore(row, anchor);
		});
		el.log.scrollTop += el.log.scrollHeight - before;
	}

	function renderEarlierButton() {
		var btn = node('button', 'btn btn-sm btn-outline msgr-earlier', 'Load earlier messages');
		btn.type = 'button';
		btn.addEventListener('click', function () {
			var oldest = state.messages.length ? state.messages[0].id : 0;
			btn.disabled = true;
			api('messenger_thread', {
				conversation_id: state.openId,
				before_message_id: oldest
			}).then(function (data) {
				prependMessages(data.messages);
				state.hasMoreBack = data.has_more;
				btn.disabled = false;
				if (!state.hasMoreBack) { btn.remove(); }
			}).catch(function (err) { btn.disabled = false; fail(err); });
		});
		el.log.appendChild(btn);
	}

	function buildRow(message, previous) {
		if (message.type === 'system') {
			// A system note in a protected conversation is sealed like anything
			// else, so with the vault locked there are no words to show. Say that,
			// rather than drawing an empty chip that reads as a rendering bug.
			return node('div', 'jy-chat-system',
				message.is_locked ? 'Protected update' : message.body);
		}

		var row = node('div', 'jy-chat-row' + (message.is_mine ? ' jy-chat-row--mine' : ''));
		row.dataset.messageId = message.id;

		// A run of messages from the same person shows one face, at the top;
		// the rest indent to line up under it. My own messages carry no face —
		// the side they sit on already says who wrote them.
		var sameAsPrevious = previous && previous.type !== 'system'
			&& previous.sender_id === message.sender_id
			&& previous.remote_address === message.remote_address;

		if (!message.is_mine) {
			if (message.sender_avatar && !sameAsPrevious) {
				var avatar = node('img', 'jy-chat-avatar');
				avatar.src = message.sender_avatar;
				avatar.alt = '';
				row.appendChild(avatar);
			} else {
				row.appendChild(node('span', 'jy-chat-avatar jy-chat-avatar--spacer'));
			}
		}

		var stack = node('div', 'jy-chat-stack');

		var conversation = conversationById(state.openId);
		if (!message.is_mine && !sameAsPrevious && conversation && conversation.is_group) {
			stack.appendChild(node('div', 'jy-chat-sender', message.sender_name || 'Unknown'));
		}

		var bubble = node('div', 'jy-chat-bubble');
		if (message.is_deleted) {
			bubble.classList.add('jy-chat-bubble--deleted');
			bubble.textContent = 'This message was deleted';
		} else if (message.is_locked) {
			// Sealed, and nobody who can open it is here. The words are not
			// withheld from this member — they are simply not readable until
			// they prove who they are, so the bubble offers that and nothing else.
			bubble.classList.add('jy-chat-bubble--locked');
			bubble.appendChild(node('span', null, 'Protected message'));
			var unlock = node('button', 'btn btn-sm btn-outline', 'Unlock to read');
			unlock.type = 'button';
			unlock.addEventListener('click', function () {
				if (window.JoineryVaultLock) {
					window.JoineryVaultLock.unlock();
				}
			});
			bubble.appendChild(unlock);
		} else {
			if (message.reply_to) {
				var quote = node('button', 'jy-chat-quote');
				quote.type = 'button';
				quote.appendChild(node('span', 'jy-chat-quote-sender', message.reply_to.sender_name || 'Message'));
				quote.appendChild(node('span', 'jy-chat-quote-body', message.reply_to.excerpt));
				quote.addEventListener('click', function () { jumpTo(message.reply_to.id); });
				bubble.appendChild(quote);
			}
			if (message.body) {
				bubble.appendChild(document.createTextNode(message.body));
			}
			(message.attachments || []).forEach(function (attachment) {
				bubble.appendChild(buildAttachment(attachment));
			});
		}
		stack.appendChild(bubble);

		var reactions = node('div', 'jy-chat-reactions');
		stack.appendChild(reactions);

		var meta = node('div', 'jy-chat-meta');
		meta.appendChild(node('span', null, clockTime(message.time)));

		// Ticks only where they mean something: a message that never leaves this
		// site is delivered the moment it is stored, so a tick there would be
		// decoration pretending to be information.
		if (message.is_mine && message.delivery_state && message.delivery_state !== 'local') {
			var tick = node('span', 'msgr-tick msgr-tick--' + message.delivery_state);
			if (message.delivery_state === 'delivered') {
				tick.innerHTML = ICONS.check;
				tick.title = 'Delivered to the other site';
			} else if (message.delivery_state === 'failed') {
				tick.innerHTML = ICONS.warn;
				tick.title = 'Could not be delivered to the other site';
			} else {
				tick.innerHTML = ICONS.clock;
				tick.title = 'Waiting to reach the other site';
			}
			meta.appendChild(tick);
		}
		if (!message.is_deleted) {
			var actions = node('div', 'msgr-bubble-actions');
			actions.appendChild(actionButton('React', ICONS.react, function () { openEmoji(message.id); }));
			actions.appendChild(actionButton('Reply', ICONS.reply, function () { startReply(message); }));
			if (message.is_mine) {
				actions.appendChild(actionButton('Delete', ICONS.trash, function () { deleteMessage(message.id); }));
			}
			meta.appendChild(actions);
		}
		stack.appendChild(meta);

		row.appendChild(stack);
		paintReactions(row, message.reactions);
		return row;
	}

	function actionButton(label, svg, onClick) {
		var btn = node('button', 'msgr-bubble-action');
		// Drawn rather than typed: a glyph like 🗑 is a font's opinion and
		// renders as an empty box on any machine that lacks it.
		btn.innerHTML = svg;
		btn.type = 'button';
		btn.title = label;
		btn.setAttribute('aria-label', label);
		btn.addEventListener('click', onClick);
		return btn;
	}

	function buildAttachment(attachment) {
		if (attachment.is_image && attachment.url) {
			var link = node('a');
			link.href = attachment.url;
			link.target = '_blank';
			link.rel = 'noopener';
			var img = node('img', 'jy-chat-media');
			img.src = attachment.thumb_url || attachment.url;
			img.alt = attachment.name || '';
			img.loading = 'lazy';
			link.appendChild(img);
			return link;
		}
		var chip = node('a', 'jy-chat-file');
		chip.href = attachment.url || '#';
		chip.target = '_blank';
		chip.rel = 'noopener';
		chip.appendChild(node('span', null, attachment.name || 'File'));
		chip.appendChild(node('span', 'jy-chat-file-size', fileSize(attachment.size)));
		return chip;
	}

	function paintReactions(row, reactions) {
		var holder = row.querySelector('.jy-chat-reactions');
		if (!holder) { return; }
		holder.textContent = '';
		(reactions || []).forEach(function (reaction) {
			var chip = node('button', 'jy-chat-reaction' + (reaction.mine ? ' jy-chat-reaction--mine' : ''));
			chip.type = 'button';
			chip.appendChild(document.createTextNode(reaction.emoji));
			chip.appendChild(node('span', null, String(reaction.count)));
			chip.addEventListener('click', function () {
				react(Number(row.dataset.messageId), reaction.emoji);
			});
			holder.appendChild(chip);
		});
	}

	/** Apply the poll's re-statement of the mutable parts of visible bubbles. */
	function applyUpdates(updates) {
		(updates || []).forEach(function (update) {
			var row = state.rows[update.id];
			if (!row) { return; }
			var bubble = row.querySelector('.jy-chat-bubble');
			if (update.is_deleted && bubble && !bubble.classList.contains('jy-chat-bubble--deleted')) {
				bubble.classList.add('jy-chat-bubble--deleted');
				bubble.textContent = 'This message was deleted';
				var actions = row.querySelector('.msgr-bubble-actions');
				if (actions) { actions.remove(); }
			}
			paintReactions(row, update.reactions);
		});
	}

	function jumpTo(messageId) {
		var row = state.rows[messageId];
		if (!row) { return; }
		row.scrollIntoView({ block: 'center', behavior: 'smooth' });
		row.style.transition = 'background-color 0.6s ease';
		row.style.backgroundColor = 'var(--jy-color-info-bg)';
		setTimeout(function () { row.style.backgroundColor = ''; }, 900);
	}

	function renderReceipts() {
		if (!state.openId) { el.receipt.textContent = ''; return; }
		var newest = 0;
		for (var i = state.messages.length - 1; i >= 0; i--) {
			if (state.messages[i].is_mine && !state.messages[i].is_deleted) {
				newest = state.messages[i].time;
				break;
			}
		}
		if (!newest) { el.receipt.textContent = ''; return; }

		var seen = state.participants.filter(function (p) {
			return !p.is_me && p.last_read_time && p.last_read_time >= newest;
		});
		if (!seen.length) { el.receipt.textContent = ''; return; }

		var conversation = conversationById(state.openId);
		if (conversation && !conversation.is_group) {
			el.receipt.textContent = 'Seen';
		} else if (seen.length === state.participants.length - 1) {
			el.receipt.textContent = 'Seen by everyone';
		} else {
			el.receipt.textContent = 'Seen by ' + seen.map(function (p) { return p.name; }).join(', ');
		}
	}

	function renderTyping(typists) {
		el.typing.textContent = '';
		if (!typists || !typists.length) { return; }
		var names = typists.map(function (t) { return t.name; });
		var label = names.length === 1
			? names[0] + ' is typing'
			: (names.length === 2 ? names.join(' and ') + ' are typing' : 'Several people are typing');
		el.typing.appendChild(node('span', null, label));
		var dots = node('span', 'jy-chat-typing-dots');
		dots.appendChild(node('i'));
		dots.appendChild(node('i'));
		dots.appendChild(node('i'));
		el.typing.appendChild(dots);
	}

	function markLocallyRead(id) {
		var conversation = conversationById(id);
		if (conversation) { conversation.unread = 0; }
	}

	// ---- Composing ---------------------------------------------------

	function startReply(message) {
		state.replyTo = message;
		renderReplyBar();
		el.input.focus();
	}

	function renderReplyBar() {
		if (!state.replyTo) { el.replyBar.hidden = true; return; }
		el.replyBar.hidden = false;
		var quoted = state.replyTo.is_locked ? 'Protected message'
			: (state.replyTo.body || 'Attachment');
		el.replyBody.textContent = (state.replyTo.sender_name || 'Message') + ': '
			+ quoted.slice(0, 120);
	}

	function renderTray() {
		el.tray.textContent = '';
		state.pending.forEach(function (item, index) {
			var chip = node('span', 'jy-chat-tray-item');
			if (item.is_image && item.thumb_url) {
				var img = node('img');
				img.src = item.thumb_url;
				img.alt = '';
				chip.appendChild(img);
			}
			chip.appendChild(node('span', null, item.name));
			var remove = node('button', null, '✕');
			remove.type = 'button';
			remove.setAttribute('aria-label', 'Remove ' + item.name);
			remove.addEventListener('click', function () {
				state.pending.splice(index, 1);
				renderTray();
			});
			chip.appendChild(remove);
			el.tray.appendChild(chip);
		});
	}

	function upload(file) {
		// Through the shared transport, so an upload after an idle session
		// recovers with the fresh-token retry like every other messenger call.
		var form = new FormData();
		form.append('file', file);
		return window.joineryApi.postForm('messenger/messenger_upload', form);
	}

	function attachFiles(files) {
		Array.prototype.forEach.call(files, function (file) {
			upload(file).then(function (data) {
				state.pending.push(data);
				renderTray();
			}).catch(fail);
		});
	}

	function send() {
		var body = el.input.value.trim();
		if (!body && !state.pending.length) { return; }
		if (!state.openId) { return; }

		el.send.disabled = true;
		var payload = {
			conversation_id: state.openId,
			body: body,
			attachment_ids: state.pending.map(function (p) { return p.attachment_id; })
		};
		if (state.replyTo) { payload.reply_to_message_id = state.replyTo.id; }

		api('messenger_send', payload).then(function (data) {
			el.input.value = '';
			el.input.style.height = 'auto';
			state.pending = [];
			state.replyTo = null;
			state.typingOut = false;
			renderTray();
			renderReplyBar();
			if (data.message) {
				addMessages([data.message]);
				noteOwnMessageInList(data.message);
			}
			scrollToBottom();
			el.send.disabled = false;
			loop.poke();
		}).catch(function (err) {
			el.send.disabled = false;
			fail(err);
		});
	}

	/**
	 * Move a just-sent message to the top of the list immediately.
	 *
	 * The poll would bring the same thing a moment later, but the member who
	 * pressed send should not watch their own conversation sit stale behind
	 * someone else's while it catches up.
	 */
	function noteOwnMessageInList(message) {
		var conversation = conversationById(state.openId);
		if (!conversation) { return; }
		conversation.last_message = {
			excerpt: message.body || 'Attachment',
			time: message.time,
			type: message.type,
			sender_id: message.sender_id,
			sender_name: message.sender_name,
			is_mine: true
		};
		conversation.unread = 0;
		mergeConversations([], false);
	}

	function react(messageId, emoji) {
		api('messenger_action', {
			action: 'react',
			conversation_id: state.openId,
			message_id: messageId,
			emoji: emoji
		}).then(function (data) {
			var row = state.rows[messageId];
			if (row) { paintReactions(row, data.reactions); }
		}).catch(fail);
	}

	function deleteMessage(messageId) {
		if (!window.confirm('Delete this message for everyone?')) { return; }
		api('messenger_action', {
			action: 'delete_message',
			conversation_id: state.openId,
			message_id: messageId
		}).then(function () {
			applyUpdates([{ id: messageId, is_deleted: true, reactions: [] }]);
			loop.poke();
		}).catch(fail);
	}

	// ---- Typing ------------------------------------------------------

	var typingTimer = null;
	function noteTyping() {
		if (!state.typingOut) {
			state.typingOut = true;
			loop.poke();
		}
		clearTimeout(typingTimer);
		typingTimer = setTimeout(function () { state.typingOut = false; }, 4000);
	}

	// ---- Dialogs -----------------------------------------------------

	var picking = { mode: 'new', chosen: [] };

	function openPeopleDialog(mode) {
		picking = { mode: mode, chosen: [] };
		el.peopleTitle.textContent = mode === 'add' ? 'Add people' : 'New message';
		el.groupName.value = '';
		el.groupName.parentElement.hidden = (mode === 'add');
		el.peopleSearch.value = '';
		el.peopleResults.textContent = '';
		el.picked.textContent = '';
		el.peopleConfirm.textContent = mode === 'add' ? 'Add' : 'Start';
		// Adding people to a conversation that already exists does not get to
		// choose its protection — that is a raise, and it lives in its own
		// dialog with its own warning.
		el.newLevelPicker.hidden = (mode === 'add');
		if (mode !== 'add') { setPickedLevel('msgr_new_level', state.settings.default_level); }
		el.peopleDialog.showModal();
		el.peopleSearch.focus();
	}

	var searchTimer = null;
	function searchPeople() {
		clearTimeout(searchTimer);
		searchTimer = setTimeout(function () {
			var q = el.peopleSearch.value.trim();
			if (q.length < 2) { el.peopleResults.textContent = ''; return; }
			var payload = { q: q };
			if (picking.mode === 'add' && state.openId) {
				payload.exclude_conversation_id = state.openId;
			}
			api('messenger_people', payload).then(function (data) {
				el.peopleResults.textContent = '';
				(data.people || []).forEach(function (person) {
					if (picking.chosen.some(function (c) { return c.user_id === person.user_id; })) { return; }
					var li = node('li');
					var btn = node('button', 'msgr-person');
					btn.type = 'button';
					var img = node('img');
					img.src = person.avatar;
					img.alt = '';
					btn.appendChild(img);
					btn.appendChild(node('span', 'msgr-person-name', person.name));
					btn.addEventListener('click', function () {
						picking.chosen.push(person);
						renderPicked();
						el.peopleSearch.value = '';
						el.peopleResults.textContent = '';
					});
					li.appendChild(btn);
					el.peopleResults.appendChild(li);
				});
			}).catch(fail);
		}, 220);
	}

	function renderPicked() {
		el.picked.textContent = '';
		picking.chosen.forEach(function (person, index) {
			var chip = node('span', 'msgr-chip');
			chip.appendChild(node('span', null, person.name));
			var remove = node('button', null, '✕');
			remove.type = 'button';
			remove.setAttribute('aria-label', 'Remove ' + person.name);
			remove.addEventListener('click', function () {
				picking.chosen.splice(index, 1);
				renderPicked();
			});
			chip.appendChild(remove);
			el.picked.appendChild(chip);
		});
		el.nameLabel.textContent = picking.chosen.length > 1
			? 'Group name (optional)' : 'Group name (only for a group)';
	}

	function confirmPeople() {
		if (!picking.chosen.length) { return; }
		var ids = picking.chosen.map(function (p) { return p.user_id; });

		if (picking.mode === 'add') {
			var conversationId = state.openId;
			var chain = Promise.resolve();
			ids.forEach(function (id) {
				chain = chain.then(function () {
					return api('messenger_group', {
						action: 'add_member',
						conversation_id: conversationId,
						member_id: id
					});
				});
			});
			chain.then(function (data) {
				el.peopleDialog.close();
				if (data && data.conversation) { applyConversation(data.conversation); }
				loop.poke();
			}).catch(fail);
			return;
		}

		var level = pickedLevel('msgr_new_level');

		if (ids.length === 1 && !el.groupName.value.trim()) {
			// A 1:1 may already exist, so it is opened rather than created —
			// and the chosen level is then applied to it, which is the same
			// raise a member could do from the thread afterwards.
			api('messenger_action', { action: 'open', to: ids[0] }).then(function (data) {
				el.peopleDialog.close();
				mergeConversations([data.conversation], false);
				openConversation(data.conversation_id);
				if (level !== 'standard') {
					return api('messenger_action', {
						action: 'protection',
						conversation_id: data.conversation_id,
						protection_level: level
					}).then(function () { reopenThread(); });
				}
			}).catch(fail);
			return;
		}

		api('messenger_group', {
			action: 'create',
			member_ids: ids,
			name: el.groupName.value.trim(),
			protection_level: level
		}).then(function (data) {
			el.peopleDialog.close();
			mergeConversations([data.conversation], false);
			openConversation(data.conversation_id);
		}).catch(fail);
	}

	/**
	 * Chatting with someone on another Joinery site.
	 *
	 * The compose surface says what will happen BEFORE the member commits: an
	 * address that cannot be reached by chat says so and offers to send an
	 * email instead. A chat message is never quietly turned into an email —
	 * that would be a different thing arriving in a different place under the
	 * member's name.
	 */
	/** The address the last check said yes to, or null. */
	var remoteChecked = null;

	function remoteButton() {
		var address = el.remoteAddress.value.trim();
		// One button, two jobs, and which one it is depends on whether this
		// exact address has already been checked — so the label a member reads
		// and the thing the click does cannot disagree.
		if (remoteChecked !== null && remoteChecked === address) {
			startRemote(address);
		} else {
			checkRemote();
		}
	}

	function checkRemote() {
		var address = el.remoteAddress.value.trim();
		if (!address) { return; }
		el.remoteStatus.textContent = 'Checking…';
		el.remoteStatus.className = 'msgr-remote-status';
		el.remoteCheck.disabled = true;

		api('messenger_action', { action: 'reachability', address: address })
			.then(function (data) {
				el.remoteCheck.disabled = false;
				if (data.reachable) {
					el.remoteStatus.textContent = 'Reachable by chat.';
					el.remoteStatus.className = 'msgr-remote-status msgr-remote-status--ok';
					el.remoteCheck.textContent = 'Start chat';
					remoteChecked = address;
					return;
				}
				el.remoteStatus.textContent = '';
				el.remoteStatus.className = 'msgr-remote-status msgr-remote-status--no';
				el.remoteStatus.appendChild(node('span', null, data.reason));
				var email = node('a', null, 'Send an email instead');
				email.href = data.email_url;
				el.remoteStatus.appendChild(email);
			}).catch(function (err) {
				el.remoteCheck.disabled = false;
				fail(err);
			});
	}

	function startRemote(address) {
		api('messenger_action', { action: 'open_remote', address: address })
			.then(function (data) {
				el.peopleDialog.close();
				mergeConversations([data.conversation], false);
				openConversation(data.conversation_id);
			}).catch(fail);
	}

	function openInfoDialog() {
		var conversation = conversationById(state.openId);
		if (!conversation) { return; }

		el.rename.value = conversation.subject || '';
		el.rename.disabled = !conversation.is_admin;
		el.renameSave.hidden = !conversation.is_admin;
		el.photoBtn.hidden = !conversation.is_admin;
		el.addMember.hidden = !conversation.is_admin;

		el.members.textContent = '';
		(conversation.participants || []).forEach(function (person) {
			var li = node('li', 'msgr-member');
			var img = node('img');
			img.src = person.avatar;
			img.alt = '';
			li.appendChild(img);
			var name = node('div', 'msgr-member-name');
			name.appendChild(node('div', null, person.name + (person.is_me ? ' (you)' : '')));
			if (person.is_admin) { name.appendChild(node('div', 'msgr-member-role', 'Admin')); }
			li.appendChild(name);

			if (conversation.is_admin && !person.is_me) {
				var remove = node('button', 'btn btn-sm btn-outline', 'Remove');
				remove.type = 'button';
				remove.addEventListener('click', function () {
					api('messenger_group', {
						action: 'remove_member',
						conversation_id: conversation.id,
						member_id: person.user_id
					}).then(function (data) {
						applyConversation(data.conversation);
						openInfoDialog();
						loop.poke();
					}).catch(fail);
				});
				li.appendChild(remove);
			}
			el.members.appendChild(li);
		});

		el.infoDialog.showModal();
	}

	/** Which protection level a card picker is currently showing. */
	function pickedLevel(field) {
		var checked = document.querySelector('input[name="' + field + '"]:checked');
		return checked ? checked.value : 'standard';
	}

	function setPickedLevel(field, value) {
		var input = document.querySelector('input[name="' + field + '"][value="' + value + '"]');
		if (input) {
			input.checked = true;
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	/**
	 * The protection dialog.
	 *
	 * Opens by asking the server what the conversation is set to and what would
	 * stand in the way of raising it — a member should learn that Bob has not
	 * set up protection before they choose, not after.
	 */
	function openProtectDialog() {
		if (!state.openId) { return; }
		api('messenger_action', { action: 'protection', conversation_id: state.openId })
			.then(function (data) {
				setPickedLevel('msgr_raise_level', data.protection_level);

				var blockers = data.members_without_protection || [];
				el.protectNote.textContent = blockers.length
					? 'Protecting this conversation needs everyone in it to set up protection first. '
						+ 'Waiting on: ' + blockers.join(', ') + '.'
					: 'Protection can be raised but never lowered.';

				// Rungs above Standard are unreachable while someone lacks a
				// vault. Showing them disabled with the reason above beats
				// letting the member choose and then refusing.
				document.querySelectorAll('input[name="msgr_raise_level"]').forEach(function (input) {
					input.disabled = blockers.length > 0 && input.value !== 'standard';
				});

				el.protectDialog.showModal();
			}).catch(fail);
	}

	function saveProtection() {
		var level = pickedLevel('msgr_raise_level');
		if (!window.confirm('Set this conversation to ' + level + '? Protection cannot be lowered afterwards.')) {
			return;
		}
		el.protectSave.disabled = true;
		api('messenger_action', {
			action: 'protection',
			conversation_id: state.openId,
			protection_level: level
		}).then(function (data) {
			el.protectSave.disabled = false;
			el.protectDialog.close();
			if (data.conversation) { applyConversation(data.conversation); }
			// Every bubble's readability just changed — re-read the thread
			// rather than guessing which ones did.
			reopenThread();
		}).catch(function (err) {
			el.protectSave.disabled = false;
			fail(err);
		});
	}

	/** Re-read the open conversation from scratch, keeping the member in it. */
	function reopenThread() {
		var id = state.openId;
		if (!id) { return; }
		state.openId = null;
		openConversation(id);
	}

	var emojiFor = null;
	function openEmoji(messageId) {
		emojiFor = messageId;
		el.emojiGrid.textContent = '';
		REACTIONS.forEach(function (emoji) {
			var btn = node('button', null, emoji);
			btn.type = 'button';
			btn.addEventListener('click', function () {
				el.emojiDialog.close();
				react(emojiFor, emoji);
			});
			el.emojiGrid.appendChild(btn);
		});
		el.emojiDialog.showModal();
	}

	// ---- Menu actions ------------------------------------------------

	function menuAction(action) {
		var conversation = conversationById(state.openId);
		if (!conversation) { return; }
		el.menu.removeAttribute('open');

		if (action === 'info') { openInfoDialog(); return; }
		if (action === 'protection') { openProtectDialog(); return; }

		if (action === 'mute') {
			api('messenger_action', {
				action: conversation.is_muted ? 'unmute' : 'mute',
				conversation_id: conversation.id
			}).then(function () {
				conversation.is_muted = !conversation.is_muted;
				applyConversation(conversation);
				renderList();
			}).catch(fail);
			return;
		}

		if (action === 'leave') {
			if (!window.confirm('Leave this group? You will stop receiving its messages.')) { return; }
			api('messenger_group', { action: 'leave', conversation_id: conversation.id })
				.then(function () { dropConversation(conversation.id); }).catch(fail);
			return;
		}

		if (action === 'delete') {
			if (!window.confirm('Remove this conversation from your inbox? A new message brings it back.')) { return; }
			api('messenger_action', { action: 'delete', conversation_id: conversation.id })
				.then(function () { dropConversation(conversation.id); }).catch(fail);
		}
	}

	function dropConversation(id) {
		var conversation = conversationById(id);
		if (conversation) {
			state.conversations.splice(state.conversations.indexOf(conversation), 1);
		}
		closeConversation();
	}

	// ---- The poll ----------------------------------------------------

	var loop = window.joineryPoll.create({
		action: 'messenger/messenger_poll',
		interval: function () {
			return state.openId && !document.hidden
				? state.settings.poll_thread_ms
				: state.settings.poll_list_ms;
		},
		body: function () {
			var payload = { list_since: state.listSince || '' };
			if (state.openId && state.threadReady) {
				payload.conversation_id = state.openId;
				payload.after_message_id = state.cursor;
				payload.typing = state.typingOut;
				payload.mark_read = !document.hidden;
			}
			return payload;
		},
		onData: function (data) {
			state.listSince = data.now;

			if (data.conversation_gone && state.openId) {
				dropConversation(state.openId);
			}

			if (data.conversation && data.conversation.id === state.openId) {
				addMessages(data.conversation.messages);
				applyUpdates(data.conversation.updates);
				state.participants = data.conversation.participants || [];
				renderReceipts();
				renderTyping(data.conversation.typing);
				if (data.conversation.messages && data.conversation.messages.length) {
					markLocallyRead(state.openId);
				}
			}

			if (data.inbox) {
				if (data.inbox.is_full_list || data.inbox.conversations.length) {
					mergeConversations(data.inbox.conversations, data.inbox.is_full_list);
				}
			}

			updateHeaderBadge(data.unread_total);
		},
		onError: function () {
			// The loop backs itself off; a transient failure is not worth a
			// banner over the conversation the member is reading.
		}
	});

	function updateHeaderBadge(total) {
		var link = document.querySelector('.header-messages-link');
		if (!link) { return; }
		var badge = link.querySelector('.messages-count');
		if (total > 0) {
			if (!badge) {
				badge = node('span', 'messages-count');
				link.appendChild(badge);
			}
			badge.textContent = String(total);
		} else if (badge) {
			badge.remove();
		}
	}

	// ---- Wiring ------------------------------------------------------

	el.filter.addEventListener('input', function () {
		state.filter = this.value.trim();
		renderList();
	});

	el.composer.addEventListener('submit', function (event) {
		event.preventDefault();
		send();
	});

	el.input.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && !event.shiftKey) {
			event.preventDefault();
			send();
		}
	});

	el.input.addEventListener('input', function () {
		this.style.height = 'auto';
		this.style.height = Math.min(this.scrollHeight, 160) + 'px';
		noteTyping();
	});

	el.attach.addEventListener('click', function () { el.file.click(); });
	el.file.addEventListener('change', function () {
		attachFiles(this.files);
		this.value = '';
	});

	el.log.addEventListener('dragover', function (event) { event.preventDefault(); });
	el.log.addEventListener('drop', function (event) {
		if (!state.openId || !event.dataTransfer || !event.dataTransfer.files.length) { return; }
		event.preventDefault();
		attachFiles(event.dataTransfer.files);
	});

	el.replyCancel.addEventListener('click', function () {
		state.replyTo = null;
		renderReplyBar();
	});

	el.back.addEventListener('click', function () { root.dataset.pane = 'list'; });

	if (el.newBtn) {
		el.newBtn.addEventListener('click', function () { openPeopleDialog('new'); });
	}
	el.peopleSearch.addEventListener('input', searchPeople);
	el.peopleConfirm.addEventListener('click', confirmPeople);
	el.addMember.addEventListener('click', function () {
		el.infoDialog.close();
		openPeopleDialog('add');
	});

	el.renameSave.addEventListener('click', function () {
		api('messenger_group', {
			action: 'rename',
			conversation_id: state.openId,
			name: el.rename.value.trim()
		}).then(function (data) {
			applyConversation(data.conversation);
			renderList();
			el.infoDialog.close();
			loop.poke();
		}).catch(fail);
	});

	el.photoBtn.addEventListener('click', function () { el.photoFile.click(); });
	el.photoFile.addEventListener('change', function () {
		if (!this.files.length) { return; }
		var file = this.files[0];
		this.value = '';
		upload(file).then(function (data) {
			return api('messenger_group', {
				action: 'set_photo',
				conversation_id: state.openId,
				file_id: data.attachment_id
			});
		}).then(function (data) {
			applyConversation(data.conversation);
			renderList();
			loop.poke();
		}).catch(fail);
	});

	el.protectSave.addEventListener('click', saveProtection);

	if (el.remoteCheck) {
		el.remoteCheck.addEventListener('click', remoteButton);
		el.remoteAddress.addEventListener('input', function () {
			// A changed address is a different question: the answer, and the
			// button that acts on it, both go back to un-asked.
			el.remoteStatus.textContent = '';
			el.remoteCheck.textContent = 'Check';
			remoteChecked = null;
		});
	}

	el.menu.querySelectorAll('[data-msgr-menu]').forEach(function (btn) {
		btn.addEventListener('click', function () { menuAction(this.dataset.msgrMenu); });
	});

	// The vault opening or closing changes what every sealed bubble can show,
	// and it happens from the lock chip rather than from anything in this app —
	// so listen for it and re-read the conversation rather than leaving stale
	// placeholders on screen.
	document.addEventListener('joinery:vault-unlocked', reopenThread);
	document.addEventListener('joinery:vault-locked', reopenThread);

	// ---- First paint -------------------------------------------------

	renderList();

	if (boot.open) {
		state.openId = boot.open.conversation.id;
		state.hasMoreBack = boot.open.has_more;
		applyConversation(boot.open.conversation);
		addMessages(boot.open.messages, true);
		markLocallyRead(state.openId);
		renderList();
		scrollToBottom();
	}

	loop.start();
})();
