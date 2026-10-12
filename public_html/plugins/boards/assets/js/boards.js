/**
 * Boards app.
 *
 * One script for every Boards page (the board list, a board, My cards, the import
 * screen). The page embeds what it needs as JSON (#boards-boot) and the script
 * draws it; every later change goes through the boards_* API actions, and an open
 * board keeps itself current through boards_poll (assets/js/joinery-poll.js: it
 * runs every few seconds while the tab is visible and not at all while it is
 * hidden).
 *
 * THE RULES THIS FILE KEEPS
 *  - No text a person wrote ever reaches innerHTML. Everything is built with DOM
 *    calls and textContent. The only HTML set from a string is what the server
 *    rendered itself (description_html, body_html), which is already sanitised.
 *  - Times travel as ISO-8601 UTC strings and are shown in the viewer's zone.
 *  - Positions are the server's business: a drop sends the two neighbours' ids and
 *    the server takes the midpoint. The browser only guesses (optimistically) until
 *    the answer comes back.
 *  - A poll answer never stomps what the person is in the middle of: a card being
 *    dragged, a card whose move is still in flight, an open text box.
 *
 * Sections: helpers - dialogs - board list - My cards - import - board state -
 * board drawing - drag and drop - filters - card back - pickers - board menu -
 * shortcuts - live updates - start.
 *
 * @version 1.0.2 - a large attachment opens and finishes through upload_init / upload_complete, so it works with Drive off
 * @version 1.0.1 - a board whose mail domain went off still shows its admin the domain picker
 * @version 1.0.0
 */
(function () {
	'use strict';

	var root = document.getElementById('boards-app');
	var bootEl = document.getElementById('boards-boot');
	if (!root || !bootEl) { return; }

	var boot = JSON.parse(bootEl.textContent || '{}');
	var api = window.joineryApi;

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Build an element: el('div', {class: 'x', text: 'hi', on: {click: fn}}, [children]). */
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { node.className = v; }
				else if (k === 'text') { node.textContent = v; }
				else if (k === 'on') { Object.keys(v).forEach(function (ev) { node.addEventListener(ev, v[ev]); }); }
				else if (k === 'data') { Object.keys(v).forEach(function (d) { node.dataset[d] = v[d]; }); }
				else if (k === 'style') { node.setAttribute('style', v); }
				else if (v === true) { node.setAttribute(k, ''); }
				else { node.setAttribute(k, v); }
			});
		}
		append(node, children);
		return node;
	}

	function append(node, children) {
		if (children === null || children === undefined || children === false) { return node; }
		if (!Array.isArray(children)) { children = [children]; }
		children.forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } return node; }

	/** Static, trusted markup only (icons). Never pass user text. */
	function svg(paths, size) {
		var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		s.setAttribute('width', size || 14); s.setAttribute('height', size || 14);
		s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none');
		s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2');
		s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round');
		s.setAttribute('aria-hidden', 'true');
		s.innerHTML = paths;
		return s;
	}
	var ICON = {
		clock: '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
		check: '<polyline points="20 6 9 17 4 12"/>',
		list: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><polyline points="3 6 4 7 6 5"/><polyline points="3 12 4 13 6 11"/><polyline points="3 18 4 19 6 17"/>',
		comment: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
		clip: '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
		eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
		close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
		filter: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
		menu: '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>'
	};
	function icon(name, size) { return svg(ICON[name], size); }

	function api_(action, body) {
		return api.post('boards/' + action, body || {});
	}

	var liveEl = document.getElementById('boards-live');
	var toastTimer = null;
	/** Say something without blocking: a toast, mirrored into the aria-live region. */
	function toast(message, isError) {
		var old = document.querySelector('.jy-boards-toast');
		if (old) { old.parentNode.removeChild(old); }
		var t = el('div', { class: 'jy-boards-toast' + (isError ? ' is-error' : ''), role: isError ? 'alert' : 'status', text: message });
		document.body.appendChild(t);
		if (liveEl) { liveEl.textContent = message; }
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, isError ? 7000 : 3500);
	}
	function fail(err) {
		toast((err && err.message) ? err.message : 'Something went wrong. Try again.', true);
	}

	// ---- dates ------------------------------------------------------------

	var fmtDay = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
	var fmtDayYear = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	var fmtTime = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });

	function parseIso(iso) { return iso ? new Date(iso) : null; }
	function hasTime(d) { return d.getHours() !== 0 || d.getMinutes() !== 0; }
	/** "Oct 12" or "Oct 12, 5:00 PM"; the year only when it is not this one. */
	function fmtDue(iso, withTime) {
		var d = parseIso(iso);
		if (!d) { return ''; }
		var text = (d.getFullYear() === new Date().getFullYear() ? fmtDay : fmtDayYear).format(d);
		if (withTime !== false && hasTime(d)) { text += ', ' + fmtTime.format(d); }
		return text;
	}
	function fmtStamp(iso) {
		var d = parseIso(iso);
		return d ? fmtDayYear.format(d) + ' ' + fmtTime.format(d) : '';
	}
	/** An ISO instant as the value a datetime-local input wants (the viewer's local time). */
	function toLocalInput(iso) {
		var d = parseIso(iso);
		if (!d) { return ''; }
		function p(n) { return (n < 10 ? '0' : '') + n; }
		return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
	}
	/** A datetime-local value back to an ISO-8601 UTC instant ('' clears). */
	function fromLocalInput(value) {
		if (!value) { return ''; }
		var d = new Date(value);
		return isNaN(d.getTime()) ? '' : d.toISOString().replace(/\.\d+Z$/, 'Z');
	}
	/** 'overdue' | 'soon' | 'done' | '' for a due badge. */
	function dueState(card) {
		if (!card.due) { return ''; }
		if (card.is_done) { return 'done'; }
		var ms = new Date(card.due).getTime() - Date.now();
		if (ms < 0) { return 'overdue'; }
		return ms < 86400000 ? 'soon' : '';
	}

	function initials(name) {
		var parts = String(name || '?').trim().split(/\s+/);
		return ((parts[0] || '?').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
	}
	/** A person's picture, or their initials when they have none. */
	function avatar(person, size) {
		var name = (person && person.name) || 'Unknown';
		var url = person && person.avatar;
		var node;
		if (url && url.indexOf('blank-avatar') === -1) {
			node = el('img', { class: 'jy-boards-avatar', src: url, alt: name, title: name, loading: 'lazy' });
		} else {
			node = el('span', { class: 'jy-boards-avatar jy-boards-avatar--initials', title: name, 'aria-label': name, text: initials(name) });
		}
		if (size) { node.style.width = size + 'px'; node.style.height = size + 'px'; node.style.fontSize = Math.round(size * 0.4) + 'px'; }
		return node;
	}

	function button(label, cls, onclick, attrs) {
		var a = { type: 'button', class: 'btn ' + (cls || 'btn-secondary'), text: label, on: { click: onclick } };
		if (attrs) { Object.keys(attrs).forEach(function (k) { a[k] = attrs[k]; }); }
		return el('button', a);
	}

	function debounce(fn, ms) {
		var t = null;
		return function () {
			var args = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, args); }, ms);
		};
	}

	/** Run an API call, showing its error, and hand back the data (or null). */
	function run(promise) {
		return promise.catch(function (err) { fail(err); return null; });
	}

	function pageUrl(params) {
		var u = new URL(window.location.href);
		Object.keys(params).forEach(function (k) {
			if (params[k] === null || params[k] === '' || params[k] === undefined) { u.searchParams.delete(k); }
			else { u.searchParams.set(k, params[k]); }
		});
		return u.pathname + (u.search || '');
	}

	// =========================================================================
	// Dialogs
	// =========================================================================

	var dialogStack = [];

	/**
	 * A modal dialog: head (title + close), body, foot. Escape closes it, focus
	 * goes to the first field and returns to where it was. opts: title, cls,
	 * onClose.
	 */
	function openDialog(opts) {
		var returnTo = document.activeElement;
		var dlg = el('dialog', { class: 'jy-ui jy-boards-dialog ' + (opts.cls || ''), 'aria-label': opts.title || 'Dialog' });
		var head = el('div', { class: 'jy-boards-dialog-head' }, [
			el('h3', { text: opts.title || '' }),
			el('button', { type: 'button', class: 'jy-boards-iconbtn', 'aria-label': 'Close', on: { click: function () { api_close(); } } }, [icon('close', 16)])
		]);
		var body = el('div', { class: 'jy-boards-dialog-body' });
		var foot = el('div', { class: 'jy-boards-dialog-foot' });
		append(dlg, [head, body, foot]);
		document.body.appendChild(dlg);

		var closed = false;
		function api_close() {
			if (closed) { return; }
			closed = true;
			try { dlg.close(); } catch (e) { /* already closed */ }
			if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); }
			dialogStack = dialogStack.filter(function (d) { return d !== handle; });
			if (opts.onClose) { opts.onClose(); }
			if (returnTo && document.contains(returnTo) && returnTo.focus) { try { returnTo.focus(); } catch (e) { /* gone */ } }
		}
		dlg.addEventListener('cancel', function (e) { e.preventDefault(); api_close(); });
		dlg.addEventListener('click', function (e) { if (e.target === dlg) { api_close(); } });

		var handle = { dlg: dlg, body: body, foot: foot, head: head, close: api_close, title: head.firstChild };
		dialogStack.push(handle);
		dlg.showModal();
		return handle;
	}

	/** Focus the first field in a dialog (after content is added). */
	function focusFirst(handle) {
		var f = handle.body.querySelector('input:not([type=hidden]), textarea, select, button');
		if (f) { f.focus(); }
	}

	function confirmDialog(message, confirmLabel, onConfirm, danger) {
		var d = openDialog({ title: 'Are you sure?', cls: 'jy-boards-dialog--small' });
		d.body.appendChild(el('p', { text: message }));
		d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
		d.foot.appendChild(button(confirmLabel, danger ? 'btn-danger' : 'btn-primary', function () { d.close(); onConfirm(); }));
		return d;
	}

	/** A one-field prompt: title, label, value, then onSubmit(value). */
	function promptDialog(title, label, value, submitLabel, onSubmit, multiline) {
		var d = openDialog({ title: title, cls: 'jy-boards-dialog--small' });
		var input = multiline
			? el('textarea', { rows: 4, class: 'jy-boards-input' })
			: el('input', { type: 'text', class: 'jy-boards-input', maxlength: 255 });
		input.value = value || '';
		d.body.appendChild(el('label', { class: 'jy-boards-field' }, [el('span', { text: label }), input]));
		function go() {
			var v = input.value.trim();
			if (!v) { input.focus(); return; }
			d.close();
			onSubmit(v);
		}
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !multiline) { e.preventDefault(); go(); } });
		d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
		d.foot.appendChild(button(submitLabel, 'btn-primary', go));
		input.focus(); input.select();
		return d;
	}

	function field(label, control, help) {
		return el('label', { class: 'jy-boards-field' }, [el('span', { text: label }), control, help ? el('small', { text: help }) : null]);
	}
	function input(type, value, attrs) {
		var a = { type: type, class: 'jy-boards-input' };
		if (attrs) { Object.keys(attrs).forEach(function (k) { a[k] = attrs[k]; }); }
		var n = el('input', a);
		if (value !== undefined && value !== null) { n.value = value; }
		return n;
	}
	function select(options, value) {
		var s = el('select', { class: 'jy-boards-input' });
		options.forEach(function (o) {
			var opt = el('option', { value: o[0], text: o[1] });
			if (String(o[0]) === String(value)) { opt.selected = true; }
			s.appendChild(opt);
		});
		return s;
	}

	// =========================================================================
	// Board list (/profile/boards)
	// =========================================================================

	function bgClass(background) {
		return /^[a-z]+$/.test(background || '') ? ' jy-boards-bg-' + background : ' jy-boards-bg-blue';
	}
	function applyBackground(node, board) {
		node.className = node.className.replace(/\s*jy-boards-bg-\w+/g, '');
		node.style.backgroundImage = '';
		if (board.background_url) {
			node.style.backgroundImage = 'url("' + String(board.background_url).replace(/["\\\n]/g, '') + '")';
			node.classList.add('jy-boards-bg-image');
		} else {
			node.classList.remove('jy-boards-bg-image');
			node.className += bgClass(board.background);
		}
	}

	var VISIBILITY_TEXT = {
		invite_only: 'Invite only',
		all_members: 'Open to all members'
	};
	var VISIBILITY_HELP = {
		invite_only: 'Only people added to this board can open it. Superadmins can open every board.',
		all_members: 'Every member of the site can open this board. Members who join can add and move cards.'
	};

	function boardTile(b, extra) {
		var tile = el('a', { class: 'jy-boards-tile', href: '/profile/boards/board?id=' + b.id }, [
			el('span', { class: 'jy-boards-tile-name', text: b.name }),
			b.description ? el('span', { class: 'jy-boards-tile-desc', text: b.description }) : null,
			el('span', { class: 'jy-boards-tile-meta' }, [
				el('span', { text: b.cards + (b.cards === 1 ? ' card' : ' cards') }),
				el('span', { class: 'jy-boards-chip', text: VISIBILITY_TEXT[b.visibility] || b.visibility })
			])
		]);
		applyBackground(tile, b);
		if (!extra) { return tile; }
		return el('div', { class: 'jy-boards-tilewrap' }, [tile, extra]);
	}

	function pageIndex() {
		var idx = boot.index || {};
		var starters = boot.starters || [];

		function section(title, boards, opts) {
			opts = opts || {};
			var body = el('div', { class: 'jy-boards-tiles' });
			boards.forEach(function (b) {
				var extra = null;
				if (opts.join) {
					extra = button('Join', 'btn-primary btn-sm', function () {
						run(api_('boards_members_save', { op: 'join', board_id: b.id })).then(function (d) {
							if (d) { window.location.href = '/profile/boards/board?id=' + b.id; }
						});
					});
				} else if (opts.useTemplate) {
					extra = button('Use this template', 'btn-secondary btn-sm', function () { newBoardDialog({ template_id: b.id, name: b.name }); });
				}
				body.appendChild(boardTile(b, extra));
			});
			if (!boards.length && opts.empty) { body.appendChild(el('p', { class: 'jy-boards-muted', text: opts.empty })); }
			if (opts.collapsed) {
				return el('details', { class: 'jy-boards-section' }, [el('summary', { text: title + ' (' + boards.length + ')' }), body]);
			}
			return el('section', { class: 'jy-boards-section' }, [el('h2', { text: title }), body]);
		}

		clear(root);
		root.appendChild(section('My boards', idx.mine || [], { empty: 'You are not on any boards yet. Start one with New board.' }));
		if ((idx.open || []).length) { root.appendChild(section('Open to all members', idx.open, { join: true })); }
		if ((idx.templates || []).length) { root.appendChild(section('Templates', idx.templates, { useTemplate: true })); }
		if ((idx.archived || []).length) { root.appendChild(section('Archived', idx.archived, { collapsed: true })); }

		var btn = document.getElementById('boards-new-board');
		if (btn) { btn.addEventListener('click', function () { newBoardDialog({}); }); }

		function newBoardDialog(preset) {
			var d = openDialog({ title: 'New board', cls: 'jy-boards-dialog--medium' });
			var name = input('text', preset.name || '', { maxlength: 255, required: true });
			var desc = el('textarea', { class: 'jy-boards-input', rows: 2 });
			var vis = select([['invite_only', 'Invite only'], ['all_members', 'Open to all members']], 'invite_only');
			var visHelp = el('small', { class: 'jy-boards-muted', text: VISIBILITY_HELP.invite_only });
			vis.addEventListener('change', function () { visHelp.textContent = VISIBILITY_HELP[vis.value]; });

			var sourceKind = preset.template_id ? 'template' : 'blank';
			var kindSel = select([['blank', 'A blank board'], ['starter', 'A starter board'], ['template', 'A copy of a template']], sourceKind);
			var starterSel = select(starters.map(function (s) { return [s.key, s.name]; }), starters.length ? starters[0].key : '');
			var starterNote = el('small', { class: 'jy-boards-muted' });
			var tplSel = select((idx.templates || []).map(function (t) { return [t.id, t.name]; }), preset.template_id || '');
			var starterWrap = field('Starter', starterSel); var tplWrap = field('Template', tplSel);

			function refreshKind() {
				starterWrap.hidden = kindSel.value !== 'starter';
				starterNote.hidden = kindSel.value !== 'starter';
				tplWrap.hidden = kindSel.value !== 'template';
				var s = starters.filter(function (x) { return x.key === starterSel.value; })[0];
				starterNote.textContent = s ? s.description : '';
			}
			kindSel.addEventListener('change', refreshKind); starterSel.addEventListener('change', refreshKind);
			if (!starters.length) { kindSel.querySelector('option[value=starter]').disabled = true; }
			if (!(idx.templates || []).length) { kindSel.querySelector('option[value=template]').disabled = true; }

			append(d.body, [
				field('Board name', name), field('Description (optional)', desc),
				field('Who can open it', vis), visHelp,
				field('Start from', kindSel), starterWrap, starterNote, tplWrap
			]);
			refreshKind();

			function create() {
				var v = name.value.trim();
				if (!v) { name.focus(); return; }
				var body = { op: 'create', name: v, description: desc.value, visibility: vis.value };
				if (kindSel.value === 'starter') { body.starter = starterSel.value; }
				if (kindSel.value === 'template') { body.template_id = parseInt(tplSel.value, 10); }
				createBtn.disabled = true;
				run(api_('boards_board_save', body)).then(function (data) {
					if (data) { window.location.href = '/profile/boards/board?id=' + data.board.id; }
					else { createBtn.disabled = false; }
				});
			}
			name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); create(); } });
			var createBtn = button('Create board', 'btn-primary', create);
			d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
			d.foot.appendChild(createBtn);
			name.focus(); name.select();
		}
	}

	// =========================================================================
	// My cards (/profile/boards/my_cards)
	// =========================================================================

	function pageMyCards() {
		var cards = boot.cards || [];
		var now = new Date();
		var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
		var groups = [
			{ title: 'Overdue', rows: [], cls: 'is-overdue' }, { title: 'Today', rows: [] },
			{ title: 'This week', rows: [] }, { title: 'Later', rows: [] }, { title: 'No date', rows: [] }
		];
		cards.forEach(function (c) {
			if (!c.due) { groups[4].rows.push(c); return; }
			var t = new Date(c.due).getTime();
			if (c.overdue || t < startToday) { groups[0].rows.push(c); }
			else if (t < startToday + 86400000) { groups[1].rows.push(c); }
			else if (t < startToday + 7 * 86400000) { groups[2].rows.push(c); }
			else { groups[3].rows.push(c); }
		});

		clear(root);
		if (!cards.length) {
			root.appendChild(el('p', { class: 'jy-boards-muted', text: 'Nothing is assigned to you. Cards you are added to, and checklist items given to you, appear here.' }));
			return;
		}
		groups.forEach(function (g) {
			if (!g.rows.length) { return; }
			var list = el('ul', { class: 'jy-boards-mylist' });
			g.rows.forEach(function (c) {
				var title = c.kind === 'item' ? c.title + ' — ' + c.card_title : c.title;
				list.appendChild(el('li', {}, [
					el('a', { class: 'jy-boards-myrow', href: '/profile/boards/board?id=' + c.board_id + '&card=' + c.id }, [
						el('span', { class: 'jy-boards-myrow-title' }, [
							c.kind === 'item' ? el('span', { class: 'jy-boards-chip', text: 'Checklist item' }) : null,
							title
						]),
						el('span', { class: 'jy-boards-myrow-where', text: c.board_name + ' · ' + c.list_name }),
						c.due ? el('span', { class: 'jy-boards-due ' + (c.overdue ? 'is-overdue' : ''), text: fmtDue(c.due) }) : null
					])
				]));
			});
			root.appendChild(el('section', { class: 'jy-boards-section ' + (g.cls || '') }, [el('h2', { text: g.title + ' (' + g.rows.length + ')' }), list]));
		});
	}

	// =========================================================================
	// Import (/profile/boards/import)
	// =========================================================================

	function pageImport() {
		var result = document.getElementById('boards-import-result');
		var form = root.querySelector('form');
		var fileInput = form ? form.querySelector('input[type=file]') : null;
		if (!form || !fileInput || !result) { return; }
		var jsonText = '';

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var f = fileInput.files && fileInput.files[0];
			clear(result);
			if (!f) { toast('Choose the exported JSON file first.', true); return; }
			if (f.size > 20 * 1024 * 1024) { toast('That file is too large to import.', true); return; }
			var reader = new FileReader();
			reader.onload = function () {
				jsonText = String(reader.result || '');
				run(api_('boards_import', { op: 'preview', json: jsonText })).then(function (data) { if (data) { showPreview(data); } });
			};
			reader.onerror = function () { toast('That file could not be read.', true); };
			reader.readAsText(f);
		});

		function showPreview(p) {
			clear(result);
			var c = p.counts || {};
			var summary = el('ul', { class: 'jy-boards-importlist' }, [
				el('li', { text: c.lists + ' lists' }),
				el('li', { text: c.cards + ' cards (' + c.open_cards + ' not archived)' }),
				el('li', { text: c.labels + ' labels' }),
				el('li', { text: c.checklist_items + ' checklist items' }),
				el('li', { text: c.comments + ' comments' })
			]);
			var matched = (p.members || []).filter(function (m) { return m.match; });
			var skipped = (p.members || []).filter(function (m) { return !m.match; });

			var people = el('div', { class: 'jy-boards-importpeople' });
			if (matched.length) {
				people.appendChild(el('h3', { text: 'People who matched a site member' }));
				var ul = el('ul', {});
				matched.forEach(function (m) { ul.appendChild(el('li', { text: m.name + ' → matched to a site member' })); });
				people.appendChild(ul);
			}
			if (skipped.length) {
				people.appendChild(el('h3', { text: 'People who did not match' }));
				people.appendChild(el('p', { class: 'jy-boards-muted', text: 'These people are skipped: they are not added to cards. Their comments are kept under your name with "Originally by …" in front.' }));
				var ul2 = el('ul', {});
				skipped.forEach(function (m) { ul2.appendChild(el('li', { text: m.name })); });
				people.appendChild(ul2);
			}

			var go = button('Import “' + p.name + '”', 'btn-primary', function () {
				go.disabled = true;
				run(api_('boards_import', { op: 'import', json: jsonText, member_map: {} })).then(function (data) {
					if (data) { window.location.href = data.url; } else { go.disabled = false; }
				});
			});
			append(result, [el('h2', { text: p.name }), summary, people, go]);
		}
	}

	// =========================================================================
	// Board: state
	// =========================================================================

	var S = null;          // set by pageBoard()
	var D = {              // drawing handles, built by buildBoard()
		app: null, bar: null, filterbar: null, scroller: null, listsWrap: null, addList: null,
		listEls: {}, cardEls: {}
	};

	function canEdit() { return !!S && !S.board.archived && (S.role === 'editor' || S.role === 'admin'); }
	function isAdmin() { return !!S && S.role === 'admin'; }

	function labelById(id) {
		for (var i = 0; i < S.labels.length; i++) { if (S.labels[i].id === id) { return S.labels[i]; } }
		return null;
	}
	function personById(id) {
		for (var i = 0; i < S.people.length; i++) { if (S.people[i].id === id) { return S.people[i]; } }
		return { id: id, name: 'Member', avatar: '' };
	}
	function listById(id) {
		for (var i = 0; i < S.lists.length; i++) { if (S.lists[i].id === id) { return S.lists[i]; } }
		return null;
	}
	function sortedLists() {
		return S.lists.slice().sort(function (a, b) { return a.position - b.position || a.id - b.id; });
	}
	/** The live cards of a list in board order. */
	function cardsOfList(listId) {
		var out = [];
		Object.keys(S.cards).forEach(function (k) {
			var c = S.cards[k];
			if (c.list_id === listId && !c.archived) { out.push(c); }
		});
		out.sort(function (a, b) { return a.position - b.position || a.id - b.id; });
		return out;
	}

	function upsertCard(summary) {
		if (!summary) { return; }
		S.cards[summary.id] = summary;
		if (summary.archived) { delete S.cards[summary.id]; }
	}

	// =========================================================================
	// Board: drawing
	// =========================================================================

	function buildBoard() {
		clear(root);
		D.listEls = {}; D.cardEls = {};

		var board = el('div', { class: 'jy-boards-board' });
		D.board = board;
		D.bar = el('header', { class: 'jy-boards-bar' });
		D.filterbar = el('div', { class: 'jy-boards-filterbar', hidden: true });
		D.banner = el('div', { class: 'jy-boards-banner', hidden: true });
		D.listsWrap = el('div', { class: 'jy-boards-lists' });
		D.scroller = el('div', { class: 'jy-boards-scroller' }, [D.listsWrap]);
		D.addList = el('div', { class: 'jy-boards-addlist' });
		D.listsWrap.appendChild(D.addList);
		append(board, [D.bar, D.filterbar, D.banner, D.scroller]);
		root.appendChild(board);

		renderChrome();
		syncLists();
		buildFilterBar();
		applyFilters();
	}

	/** The board header, banner and add-list box — everything that depends on the role or the board's own fields. */
	function renderChrome() {
		applyBackground(D.board, S.board);
		document.title = S.board.name + ' – Boards';

		clear(D.bar);
		var name = el('h2', { class: 'jy-boards-bar-name', text: S.board.name, tabindex: isAdmin() ? 0 : null,
			title: isAdmin() ? 'Rename this board' : null });
		if (isAdmin() && !S.board.archived) {
			name.addEventListener('click', function () {
				promptDialog('Rename board', 'Board name', S.board.name, 'Save', function (v) {
					run(api_('boards_board_save', { op: 'update', board_id: S.board.id, name: v })).then(function (d) { if (d) { S.board = d.board; renderChrome(); } });
				});
			});
		}
		var people = el('div', { class: 'jy-boards-bar-people', 'aria-label': 'People on this board' });
		S.people.slice(0, 6).forEach(function (p) { people.appendChild(avatar(p, 28)); });
		if (S.people.length > 6) { people.appendChild(el('span', { class: 'jy-boards-chip', text: '+' + (S.people.length - 6) })); }
		people.addEventListener('click', function () { openBoardMenu('people'); });

		var watch = el('button', { type: 'button', class: 'btn btn-secondary btn-sm', 'aria-pressed': S.watching ? 'true' : 'false',
			title: 'Tell me about every new card on this board',
			on: { click: function () {
				run(api_('boards_board_save', { op: S.watching ? 'unwatch' : 'watch', board_id: S.board.id })).then(function (d) {
					if (d) { S.watching = d.watching_board; renderChrome(); toast(S.watching ? 'You will hear about every new card.' : 'You will only hear about cards you are on.'); }
				});
			} } }, [icon('eye', 14), ' ', S.watching ? 'Watching' : 'Watch']);

		var filterBtn = el('button', { type: 'button', class: 'btn btn-secondary btn-sm', id: 'boards-filter-btn', 'aria-expanded': D.filterbar && !D.filterbar.hidden ? 'true' : 'false',
			on: { click: function () { toggleFilterBar(); } } }, [icon('filter', 14), ' Filter']);

		append(D.bar, [
			name,
			el('span', { class: 'jy-boards-chip', text: VISIBILITY_TEXT[S.board.visibility] || '' }),
			S.board.is_template ? el('span', { class: 'jy-boards-chip', text: 'Template' }) : null,
			el('span', { class: 'jy-boards-bar-spacer' }),
			people, watch, filterBtn,
			S.role === 'viewer' && S.board.visibility === 'all_members' && !S.board.archived
				? button('Join this board', 'btn-primary btn-sm', function () {
					run(api_('boards_members_save', { op: 'join', board_id: S.board.id })).then(function (d) { if (d) { window.location.reload(); } });
				}) : null,
			button('Menu', 'btn-secondary btn-sm', function () { openBoardMenu(); }, { id: 'boards-menu-btn' })
		]);

		clear(D.banner);
		D.banner.hidden = !S.board.archived;
		if (S.board.archived) {
			D.banner.appendChild(el('span', { text: 'This board is archived and read only.' }));
			if (isAdmin()) {
				D.banner.appendChild(button('Restore', 'btn-primary btn-sm', function () {
					run(api_('boards_board_save', { op: 'restore', board_id: S.board.id })).then(function (d) {
						if (d) { S.board = d.board; S.role = d.role; renderChrome(); syncLists(); }
					});
				}));
			}
		}
		renderAddList();
		if (S.role === 'viewer') { D.board.classList.add('is-readonly'); } else { D.board.classList.remove('is-readonly'); }
	}

	// ---- lists ------------------------------------------------------------

	function syncLists() {
		var ordered = sortedLists();
		var seen = {};
		ordered.forEach(function (l, i) {
			seen[l.id] = true;
			var node = D.listEls[l.id];
			if (!node) { node = buildList(l); D.listEls[l.id] = node; }
			fillListHead(node, l);
			syncList(l.id);
			var current = D.listsWrap.children[i];
			if (current !== node) { D.listsWrap.insertBefore(node, current || D.addList); }
		});
		Object.keys(D.listEls).forEach(function (id) {
			if (!seen[id]) {
				var n = D.listEls[id];
				if (n.parentNode) { n.parentNode.removeChild(n); }
				delete D.listEls[id];
			}
		});
	}

	function buildList(l) {
		var node = el('section', { class: 'jy-boards-list', data: { listId: l.id }, 'aria-label': l.name });
		node._head = el('div', { class: 'jy-boards-list-head' });
		node._cards = el('div', { class: 'jy-boards-cards', data: { listId: l.id } });
		node._foot = el('div', { class: 'jy-boards-list-foot' });
		append(node, [node._head, node._cards, node._foot]);
		node._head.addEventListener('pointerdown', function (e) { watchDrag(e, 'list', node, l.id); });
		buildComposer(node, l.id);
		return node;
	}

	function fillListHead(node, l) {
		clear(node._head);
		node.setAttribute('aria-label', l.name);
		var count = cardsOfList(l.id).length;
		var title = el('h3', { class: 'jy-boards-list-name', text: l.name, tabindex: canEdit() ? 0 : null });
		if (canEdit()) {
			title.addEventListener('click', function () { renameListInline(title, l); });
			title.addEventListener('keydown', function (e) { if (e.key === 'Enter') { renameListInline(title, l); } });
		}
		append(node._head, [title, el('span', { class: 'jy-boards-list-count', text: String(count) })]);
		if (canEdit()) {
			node._head.appendChild(el('button', { type: 'button', class: 'jy-boards-iconbtn', title: 'Archive this list', 'aria-label': 'Archive list ' + l.name,
				on: { click: function () {
					confirmDialog('Archive the list “' + l.name + '”? It disappears from the board; its cards stay in it and come back if you restore it.', 'Archive list', function () {
						run(api_('boards_list_save', { op: 'archive', list_id: l.id })).then(function (d) { if (d) { S.lists = d.lists; syncLists(); } });
					});
				} } }, [icon('close', 14)]));
		}
	}

	function renameListInline(title, l) {
		var inp = input('text', l.name, { maxlength: 255, class: 'jy-boards-input jy-boards-list-rename', 'aria-label': 'List name' });
		title.replaceWith(inp);
		inp.focus(); inp.select();
		var done = false;
		function finish(save) {
			if (done) { return; } done = true;
			var v = inp.value.trim();
			if (save && v && v !== l.name) {
				run(api_('boards_list_save', { op: 'rename', list_id: l.id, name: v })).then(function (d) { if (d) { S.lists = d.lists; } syncLists(); });
			} else { syncLists(); }
		}
		inp.addEventListener('blur', function () { finish(true); });
		inp.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); finish(true); }
			if (e.key === 'Escape') { e.preventDefault(); finish(false); }
		});
	}

	function renderAddList() {
		clear(D.addList);
		D.addList.hidden = !canEdit();
		if (!canEdit()) { return; }
		var opener = el('button', { type: 'button', class: 'jy-boards-addbtn', on: { click: open } }, [icon('plus', 14), ' Add a list']);
		D.addList.appendChild(opener);
		function open() {
			clear(D.addList);
			var inp = input('text', '', { maxlength: 255, placeholder: 'List name', 'aria-label': 'List name' });
			function submit() {
				var v = inp.value.trim();
				if (!v) { inp.focus(); return; }
				run(api_('boards_list_save', { op: 'create', board_id: S.board.id, name: v })).then(function (d) {
					if (!d) { return; }
					S.lists = d.lists; syncLists(); renderAddList(); open();
					D.scroller.scrollLeft = D.scroller.scrollWidth;
				});
			}
			inp.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); submit(); }
				if (e.key === 'Escape') { renderAddList(); }
			});
			append(D.addList, [inp, el('div', { class: 'jy-boards-composer-actions' }, [
				button('Add list', 'btn-primary btn-sm', submit), button('Cancel', 'btn-secondary btn-sm', renderAddList)])]);
			inp.focus();
		}
	}

	// ---- cards ------------------------------------------------------------

	function syncList(listId) {
		var node = D.listEls[listId];
		if (!node) { return; }
		var cards = cardsOfList(listId);
		var seen = {};
		cards.forEach(function (c, i) {
			seen[c.id] = true;
			var cn = D.cardEls[c.id];
			if (!cn) { cn = buildCard(c.id); D.cardEls[c.id] = cn; }
			var sig = JSON.stringify(c) + '|' + labelSig(c) + '|' + (canEdit() ? 1 : 0);
			if (cn._sig !== sig) { fillCard(cn, c); cn._sig = sig; }
			var current = node._cards.children[i];
			if (current !== cn) { node._cards.insertBefore(cn, current || null); }
		});
		Array.prototype.slice.call(node._cards.children).forEach(function (child) {
			var id = child.dataset ? parseInt(child.dataset.cardId, 10) : NaN;
			if (child.classList.contains('jy-boards-card') && !seen[id]) {
				node._cards.removeChild(child);
				if (D.cardEls[id] === child) { delete D.cardEls[id]; }
			}
		});
		var count = node._head.querySelector('.jy-boards-list-count');
		if (count) { count.textContent = String(cards.length); }
		applyFiltersTo(node);
	}

	function labelSig(c) {
		return c.labels.map(function (id) { var l = labelById(id); return l ? l.color + ':' + l.name : ''; }).join(',');
	}

	/** Refresh every list that holds the given card, wherever it used to be. */
	function syncAllCards() {
		Object.keys(D.listEls).forEach(function (id) { syncList(parseInt(id, 10)); });
		Object.keys(D.cardEls).forEach(function (id) {
			var cn = D.cardEls[id];
			if (!S.cards[id] && cn.parentNode) { cn.parentNode.removeChild(cn); delete D.cardEls[id]; }
		});
	}

	function buildCard(id) {
		var cn = el('div', { class: 'jy-boards-card', role: 'button', tabindex: 0, data: { cardId: id } });
		cn.addEventListener('click', function (e) {
			if (dragJustEnded) { e.preventDefault(); return; }
			openCard(id);
		});
		cn.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target === cn) { e.preventDefault(); openCard(id); }
		});
		cn.addEventListener('pointerenter', function () { S.hover = id; });
		cn.addEventListener('pointerleave', function () { if (S.hover === id) { S.hover = null; } });
		cn.addEventListener('focus', function () { S.hover = id; });
		cn.addEventListener('pointerdown', function (e) { watchDrag(e, 'card', cn, id); });
		return cn;
	}

	function fillCard(cn, c) {
		clear(cn);
		cn.setAttribute('aria-label', c.title);
		cn.classList.toggle('is-done', !!c.is_done);
		if (c.cover) { cn.appendChild(el('img', { class: 'jy-boards-card-cover', src: c.cover, alt: '', loading: 'lazy', draggable: 'false' })); }
		var labels = c.labels.map(labelById).filter(Boolean);
		if (labels.length) {
			cn.appendChild(el('div', { class: 'jy-boards-card-labels' }, labels.map(function (l) {
				return el('span', { class: 'jy-boards-label jy-boards-label-' + l.color, title: l.name || l.color, text: l.name || '' });
			})));
		}
		cn.appendChild(el('div', { class: 'jy-boards-card-title', text: c.title }));

		var badges = el('div', { class: 'jy-boards-card-badges' });
		var ds = dueState(c);
		if (c.due) {
			badges.appendChild(el('span', { class: 'jy-boards-due' + (ds ? ' is-' + ds : ''), title: fmtStamp(c.due) }, [
				icon(c.is_done ? 'check' : 'clock', 12), ' ' + fmtDue(c.due, false)]));
		}
		if (c.is_template) { badges.appendChild(el('span', { class: 'jy-boards-badge is-template', text: 'Template' })); }
		if (c.has_description) { badges.appendChild(el('span', { class: 'jy-boards-badge', title: 'Has a description' }, [icon('menu', 12)])); }
		if (c.comments) { badges.appendChild(el('span', { class: 'jy-boards-badge', title: c.comments + ' comments' }, [icon('comment', 12), ' ' + c.comments])); }
		if (c.attachments) { badges.appendChild(el('span', { class: 'jy-boards-badge', title: c.attachments + ' attachments' }, [icon('clip', 12), ' ' + c.attachments])); }
		if (c.checklist && c.checklist.total) {
			badges.appendChild(el('span', { class: 'jy-boards-badge' + (c.checklist.done === c.checklist.total ? ' is-complete' : ''), title: 'Checklist' },
				[icon('list', 12), ' ' + c.checklist.done + '/' + c.checklist.total]));
		}
		if (badges.children.length) { cn.appendChild(badges); }
		if (c.members.length) {
			cn.appendChild(el('div', { class: 'jy-boards-card-members' }, c.members.map(function (id) { return avatar(personById(id), 24); })));
		}
	}

	// ---- add a card -------------------------------------------------------

	function buildComposer(node, listId) {
		node._foot.hidden = !canEdit();
		function renderOpener() {
			clear(node._foot);
			node._foot.hidden = !canEdit();
			if (!canEdit()) { return; }
			node._foot.appendChild(el('button', { type: 'button', class: 'jy-boards-addbtn', data: { composer: listId }, on: { click: openComposer } }, [icon('plus', 14), ' Add a card']));
			if (templateCards().length) {
				node._foot.appendChild(el('button', { type: 'button', class: 'jy-boards-addbtn jy-boards-addbtn--alt', on: { click: function () { fromTemplate(listId); } } }, ['From a template']));
			}
		}
		function openComposer() {
			clear(node._foot);
			var ta = el('textarea', { class: 'jy-boards-input jy-boards-composer-text', rows: 2, placeholder: 'Title for this card…', 'aria-label': 'Card title', maxlength: 255 });
			function submit() {
				var v = ta.value.trim();
				if (!v) { ta.focus(); return; }
				ta.disabled = true;
				run(api_('boards_card_save', { op: 'create', list_id: listId, title: v, at: 'bottom' })).then(function (d) {
					if (d && d.card) { upsertCard(d.card); syncList(listId); }
					openComposer();
					var cards = node._cards; cards.scrollTop = cards.scrollHeight;
				});
			}
			ta.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
				if (e.key === 'Escape') { e.preventDefault(); renderOpener(); }
			});
			append(node._foot, [ta, el('div', { class: 'jy-boards-composer-actions' }, [
				button('Add card', 'btn-primary btn-sm', submit), button('Cancel', 'btn-secondary btn-sm', renderOpener)])]);
			ta.focus();
		}
		node._renderOpener = renderOpener;
		node._openComposer = openComposer;
		renderOpener();
	}

	/** Cards on this board marked as templates. */
	function templateCards() {
		return Object.keys(S.cards).map(function (k) { return S.cards[k]; }).filter(function (c) { return c.is_template; });
	}

	/** Make a card by copying a template card into a list. */
	function fromTemplate(listId) {
		var d = openDialog({ title: 'New card from a template', cls: 'jy-boards-dialog--small' });
		var ul = el('ul', { class: 'jy-boards-picklist' });
		templateCards().forEach(function (t) {
			ul.appendChild(el('li', {}, [el('button', { type: 'button', class: 'jy-boards-pickrow', on: { click: function () {
				d.close();
				run(api_('boards_card_save', { op: 'create', list_id: listId, from_card_id: t.id, at: 'bottom' })).then(function (r) {
					if (r && r.card) { upsertCard(r.card); syncAllCards(); toast('Card made from the template.'); }
				});
			} } }, [el('span', { text: t.title })])]));
		});
		d.body.appendChild(ul);
		d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
	}

	/** After a role change the composers and add-list box follow it. */
	function refreshAffordances() {
		Object.keys(D.listEls).forEach(function (id) {
			var node = D.listEls[id];
			if (node._foot.querySelector('textarea') === null) { node._renderOpener(); }
		});
		renderAddList();
	}

	// =========================================================================
	// Board: drag and drop (pointer events: mouse, pen and touch)
	// =========================================================================

	var watching = null;        // a pointer is down on a card/list header; may become a drag
	var drag = null;            // the drag in progress
	var dragJustEnded = false;  // swallows the click that follows a drop
	var inflight = {};          // card/list ids whose move has not been answered yet

	function watchDrag(e, kind, node, id) {
		if (!canEdit() || drag || watching) { return; }
		if (e.pointerType === 'mouse' && e.button !== 0) { return; }
		if (e.target.closest && e.target.closest('button, input, textarea, select, a, .jy-boards-list-rename')) { return; }
		var w = { kind: kind, node: node, id: id, pointerId: e.pointerId, x: e.clientX, y: e.clientY,
			lx: e.clientX, ly: e.clientY, touch: e.pointerType !== 'mouse', timer: null };
		watching = w;
		// A finger that moves before the press is long enough is scrolling the
		// board, not carrying a card.
		if (w.touch) { w.timer = setTimeout(function () { if (watching === w) { beginDrag(w); } }, 380); }
		document.addEventListener('pointermove', onPointerMove, true);
		document.addEventListener('pointerup', onPointerUp, true);
		document.addEventListener('pointercancel', onPointerUp, true);
		document.addEventListener('keydown', onDragKey, true);
	}

	function stopWatching() {
		if (watching && watching.timer) { clearTimeout(watching.timer); }
		watching = null;
		document.removeEventListener('pointermove', onPointerMove, true);
		document.removeEventListener('pointerup', onPointerUp, true);
		document.removeEventListener('pointercancel', onPointerUp, true);
		document.removeEventListener('keydown', onDragKey, true);
	}

	function onPointerMove(e) {
		if (drag) {
			if (e.pointerId !== drag.pointerId) { return; }
			e.preventDefault();
			moveDrag(e.clientX, e.clientY);
			return;
		}
		if (!watching || e.pointerId !== watching.pointerId) { return; }
		watching.lx = e.clientX; watching.ly = e.clientY;
		var dist = Math.hypot(e.clientX - watching.x, e.clientY - watching.y);
		if (watching.touch) { if (dist > 10) { stopWatching(); } }
		else if (dist > 5) { beginDrag(watching); }
	}

	function onPointerUp(e) {
		if (drag) {
			if (e.pointerId !== drag.pointerId) { return; }
			finishDrag(e.type === 'pointerup');
			return;
		}
		stopWatching();
	}

	function onDragKey(e) {
		if (e.key === 'Escape' && drag) { e.preventDefault(); e.stopPropagation(); finishDrag(false); }
	}

	function blockTouchScroll(e) { if (drag && e.cancelable) { e.preventDefault(); } }

	function beginDrag(w) {
		if (watching && watching.timer) { clearTimeout(watching.timer); }
		var node = w.node;
		var rect = node.getBoundingClientRect();
		var ghost = node.cloneNode(true);
		ghost.classList.add('jy-boards-ghost');
		ghost.style.width = rect.width + 'px';
		ghost.style.height = rect.height + 'px';
		ghost.style.left = rect.left + 'px';
		ghost.style.top = rect.top + 'px';
		ghost.removeAttribute('id');
		document.body.appendChild(ghost);

		var placeholder = el('div', { class: 'jy-boards-placeholder jy-boards-placeholder--' + w.kind });
		placeholder.style.height = rect.height + 'px';
		if (w.kind === 'list') { placeholder.style.width = rect.width + 'px'; }
		node.parentNode.insertBefore(placeholder, node);
		node.classList.add('is-drag-source');

		drag = { kind: w.kind, id: w.id, node: node, ghost: ghost, placeholder: placeholder, pointerId: w.pointerId,
			offX: w.x - rect.left, offY: w.y - rect.top, x: w.lx, y: w.ly, raf: 0,
			fromListId: w.kind === 'card' ? S.cards[w.id].list_id : null,
			fromPosition: w.kind === 'card' ? S.cards[w.id].position : listById(w.id).position };
		stopWatchingKeepDrag();
		document.body.classList.add('jy-boards-dragging');
		document.addEventListener('touchmove', blockTouchScroll, { passive: false });
		document.addEventListener('pointermove', onPointerMove, true);
		document.addEventListener('pointerup', onPointerUp, true);
		document.addEventListener('pointercancel', onPointerUp, true);
		document.addEventListener('keydown', onDragKey, true);
		try { node.setPointerCapture(w.pointerId); } catch (err) { /* touch captures implicitly */ }
		moveDrag(w.lx, w.ly);
		drag.raf = requestAnimationFrame(autoScroll);
	}

	/** Clear the watch state without removing the document listeners the drag reuses. */
	function stopWatchingKeepDrag() {
		if (watching && watching.timer) { clearTimeout(watching.timer); }
		watching = null;
	}

	function moveDrag(x, y) {
		drag.x = x; drag.y = y;
		drag.ghost.style.left = (x - drag.offX) + 'px';
		drag.ghost.style.top = (y - drag.offY) + 'px';
		if (drag.kind === 'card') { placeCardPlaceholder(x, y); } else { placeListPlaceholder(x); }
	}

	/** The list column the pointer is over, or the nearest one. */
	function listUnder(x) {
		var best = null, bestDist = Infinity;
		sortedLists().forEach(function (l) {
			var n = D.listEls[l.id];
			if (!n) { return; }
			var r = n.getBoundingClientRect();
			var d = x < r.left ? r.left - x : (x > r.right ? x - r.right : 0);
			if (d < bestDist) { bestDist = d; best = n; }
		});
		return best;
	}

	function placeCardPlaceholder(x, y) {
		var listNode = listUnder(x);
		if (!listNode) { return; }
		var container = listNode._cards;
		var kids = Array.prototype.filter.call(container.children, function (c) {
			return c.classList.contains('jy-boards-card') && c !== drag.node;
		});
		var before = null;
		for (var i = 0; i < kids.length; i++) {
			var r = kids[i].getBoundingClientRect();
			if (y < r.top + r.height / 2) { before = kids[i]; break; }
		}
		if (drag.placeholder.parentNode !== container
			|| (before ? drag.placeholder.nextElementSibling !== before : container.lastElementChild !== drag.placeholder)) {
			// The source node (hidden) can sit right after the placeholder; skip it.
			container.insertBefore(drag.placeholder, before);
		}
	}

	function placeListPlaceholder(x) {
		var kids = sortedLists().map(function (l) { return D.listEls[l.id]; })
			.filter(function (n) { return n && n !== drag.node; });
		var before = D.addList;
		for (var i = 0; i < kids.length; i++) {
			var r = kids[i].getBoundingClientRect();
			if (x < r.left + r.width / 2) { before = kids[i]; break; }
		}
		if (drag.placeholder.nextElementSibling !== before) { D.listsWrap.insertBefore(drag.placeholder, before); }
	}

	/** Scroll the board (or a long list) while a card is held near its edge. */
	function autoScroll() {
		if (!drag) { return; }
		var edge = 56, speed = 16;
		var sr = D.scroller.getBoundingClientRect();
		if (drag.x < sr.left + edge) { D.scroller.scrollLeft -= speed; }
		else if (drag.x > sr.right - edge) { D.scroller.scrollLeft += speed; }
		if (drag.kind === 'card') {
			var ln = listUnder(drag.x);
			if (ln) {
				var cr = ln._cards.getBoundingClientRect();
				if (drag.y < cr.top + edge) { ln._cards.scrollTop -= speed; }
				else if (drag.y > cr.bottom - edge) { ln._cards.scrollTop += speed; }
			}
			placeCardPlaceholder(drag.x, drag.y);
		} else {
			placeListPlaceholder(drag.x);
		}
		drag.raf = requestAnimationFrame(autoScroll);
	}

	function finishDrag(commit) {
		var d = drag;
		drag = null;
		cancelAnimationFrame(d.raf);
		document.removeEventListener('touchmove', blockTouchScroll, { passive: false });
		document.body.classList.remove('jy-boards-dragging');
		stopWatching();

		var prevEl = d.placeholder.previousElementSibling, nextEl = d.placeholder.nextElementSibling;
		var container = d.placeholder.parentNode;
		// Skip the hidden source when it is the placeholder's immediate neighbour.
		var prevNode = prevEl === d.node ? prevEl.previousElementSibling : prevEl;
		var nextNode = nextEl === d.node ? nextEl.nextElementSibling : nextEl;
		var cls = d.kind === 'card' ? 'jy-boards-card' : 'jy-boards-list';
		var prevId = prevNode && prevNode.classList.contains(cls) ? parseInt(prevNode.dataset[d.kind === 'card' ? 'cardId' : 'listId'], 10) : null;
		var nextId = nextNode && nextNode.classList.contains(cls) ? parseInt(nextNode.dataset[d.kind === 'card' ? 'cardId' : 'listId'], 10) : null;
		var targetListId = d.kind === 'card' && container ? parseInt(container.dataset.listId, 10) : null;

		if (d.placeholder.parentNode) { d.placeholder.parentNode.removeChild(d.placeholder); }
		if (d.ghost.parentNode) { d.ghost.parentNode.removeChild(d.ghost); }
		d.node.classList.remove('is-drag-source');
		dragJustEnded = true;
		setTimeout(function () { dragJustEnded = false; }, 60);

		if (!commit || (d.kind === 'card' && !targetListId)) { syncLists(); flushPoll(); return; }

		function posOf(id) {
			if (id === null) { return null; }
			return d.kind === 'card' ? (S.cards[id] ? S.cards[id].position : null) : (listById(id) ? listById(id).position : null);
		}
		var before = posOf(prevId), after = posOf(nextId);
		var pos = before !== null && after !== null ? (before + after) / 2
			: before !== null ? before + 1024 : after !== null ? after - 1024 : 1024;

		var unchanged = d.kind === 'card'
			? (targetListId === d.fromListId && sameNeighbours(d, prevId, nextId))
			: sameNeighbours(d, prevId, nextId);
		if (unchanged) { syncLists(); flushPoll(); return; }

		inflight[d.kind + d.id] = true;
		if (d.kind === 'card') {
			var c = S.cards[d.id];
			var old = { list_id: c.list_id, position: c.position };
			c.list_id = targetListId; c.position = pos;
			syncAllCards();
			api_('boards_card_move', { card_id: d.id, list_id: targetListId, prev_id: prevId, next_id: nextId }).then(function (data) {
				if (data.card) { upsertCard(data.card); }
				syncAllCards();
			}).catch(function (err) {
				if (S.cards[d.id]) { S.cards[d.id].list_id = old.list_id; S.cards[d.id].position = old.position; }
				syncAllCards();
				fail(err);
			}).then(function () { delete inflight['card' + d.id]; flushPoll(); });
		} else {
			var l = listById(d.id);
			var oldPos = l.position;
			l.position = pos;
			syncLists();
			api_('boards_list_move', { list_id: d.id, prev_id: prevId, next_id: nextId }).then(function (data) {
				if (data.list) { l.position = data.list.position; }
				syncLists();
			}).catch(function (err) {
				l.position = oldPos; syncLists(); fail(err);
			}).then(function () { delete inflight['list' + d.id]; flushPoll(); });
		}
	}

	/** Did the item come back to exactly where it was picked up? */
	function sameNeighbours(d, prevId, nextId) {
		var siblings = d.kind === 'card' ? cardsOfList(d.fromListId) : sortedLists();
		var idx = -1;
		siblings.forEach(function (s, i) { if (s.id === d.id) { idx = i; } });
		if (idx < 0) { return false; }
		var p = idx > 0 ? siblings[idx - 1].id : null;
		var n = idx < siblings.length - 1 ? siblings[idx + 1].id : null;
		return p === prevId && n === nextId;
	}

	// =========================================================================
	// Board: filters (kept in the URL: ?q=&label=&member=&due=)
	// =========================================================================

	function filtersActive() {
		var f = S.filters;
		return !!(f.q || f.label || f.member || f.due);
	}

	function readFiltersFromUrl() {
		var p = new URLSearchParams(window.location.search);
		S.filters = { q: p.get('q') || '', label: p.get('label') || '', member: p.get('member') || '', due: p.get('due') || '' };
	}

	function writeFiltersToUrl() {
		var p = new URLSearchParams(window.location.search);
		['q', 'label', 'member', 'due'].forEach(function (k) {
			if (S.filters[k]) { p.set(k, S.filters[k]); } else { p.delete(k); }
		});
		history.replaceState(history.state, '', window.location.pathname + (p.toString() ? '?' + p.toString() : ''));
	}

	function cardMatches(c) {
		var f = S.filters;
		if (f.q) {
			var needle = f.q.toLowerCase();
			var hay = c.title.toLowerCase() + ' ' + c.labels.map(function (id) { var l = labelById(id); return l ? (l.name || '').toLowerCase() : ''; }).join(' ')
				+ ' ' + c.members.map(function (id) { return personById(id).name.toLowerCase(); }).join(' ');
			if (hay.indexOf(needle) === -1) { return false; }
		}
		if (f.label && c.labels.indexOf(parseInt(f.label, 10)) === -1) { return false; }
		if (f.member) {
			var uid = f.member === 'me' ? S.me : parseInt(f.member, 10);
			if (c.members.indexOf(uid) === -1) { return false; }
		}
		if (f.due) {
			var due = c.due ? new Date(c.due).getTime() : null, now = Date.now();
			if (f.due === 'none' && due !== null) { return false; }
			if (f.due === 'overdue' && !(due !== null && !c.is_done && due < now)) { return false; }
			if (f.due === 'week' && !(due !== null && !c.is_done && due >= now && due < now + 7 * 86400000)) { return false; }
		}
		return true;
	}

	function applyFiltersTo(listNode) {
		var active = filtersActive();
		Array.prototype.forEach.call(listNode._cards.children, function (cn) {
			if (!cn.classList.contains('jy-boards-card')) { return; }
			var c = S.cards[cn.dataset.cardId];
			cn.classList.toggle('is-dim', !!(active && c && !cardMatches(c)));
		});
	}

	function applyFilters() {
		Object.keys(D.listEls).forEach(function (id) { applyFiltersTo(D.listEls[id]); });
		var shown = 0, total = 0;
		Object.keys(S.cards).forEach(function (id) { total++; if (cardMatches(S.cards[id])) { shown++; } });
		if (D.filterCount) {
			D.filterCount.textContent = filtersActive() ? shown + ' of ' + total + ' cards match' : '';
		}
		D.board.classList.toggle('has-filter', filtersActive());
		writeFiltersToUrl();
	}

	function buildFilterBar() {
		clear(D.filterbar);
		var q = input('search', S.filters.q, { placeholder: 'Search cards', 'aria-label': 'Search cards', id: 'boards-filter-q' });
		var label = select([['', 'Any label']].concat(S.labels.map(function (l) { return [l.id, l.name || l.color]; })), S.filters.label);
		label.setAttribute('aria-label', 'Filter by label');
		var member = select([['', 'Anyone'], ['me', 'Me']].concat(S.people.filter(function (p) { return p.id !== S.me; }).map(function (p) { return [p.id, p.name]; })), S.filters.member);
		member.setAttribute('aria-label', 'Filter by member');
		var due = select([['', 'Any due date'], ['overdue', 'Overdue'], ['week', 'Due this week'], ['none', 'No due date']], S.filters.due);
		due.setAttribute('aria-label', 'Filter by due date');
		D.filterCount = el('span', { class: 'jy-boards-muted', 'aria-live': 'polite' });

		function changed() {
			S.filters = { q: q.value.trim(), label: label.value, member: member.value, due: due.value };
			applyFilters();
		}
		q.addEventListener('input', debounce(changed, 150));
		[label, member, due].forEach(function (s) { s.addEventListener('change', changed); });
		q.addEventListener('keydown', function (e) { if (e.key === 'Escape') { toggleFilterBar(false); } });

		append(D.filterbar, [q, label, member, due,
			button('Clear filters', 'btn-secondary btn-sm', function () {
				q.value = ''; label.value = ''; member.value = ''; due.value = ''; changed();
			}), D.filterCount]);
		if (filtersActive()) { D.filterbar.hidden = false; }
	}

	function toggleFilterBar(force) {
		var show = force === undefined ? D.filterbar.hidden : force;
		D.filterbar.hidden = !show;
		var btn = document.getElementById('boards-filter-btn');
		if (btn) { btn.setAttribute('aria-expanded', show ? 'true' : 'false'); }
		if (show) { var q = document.getElementById('boards-filter-q'); if (q) { q.focus(); } }
	}

	// =========================================================================
	// Card back
	// =========================================================================

	var CB = null;   // the open card back: { id, handle, detail, ... }

	/** Type "@" in a text box to pick a person on this board; returns who was picked. */
	function attachMentions(ta, host) {
		var chosen = {};
		var menu = el('ul', { class: 'jy-boards-mentionmenu', role: 'listbox', hidden: true });
		host.style.position = 'relative';
		host.appendChild(menu);
		var items = [], index = 0, tok = null;

		function token() {
			var pos = ta.selectionStart;
			var m = /(^|\s)@([^\s@]{0,30})$/.exec(ta.value.slice(0, pos));
			return m ? { start: pos - m[2].length - 1, query: m[2].toLowerCase(), pos: pos } : null;
		}
		function hide() { menu.hidden = true; items = []; }
		function render() {
			clear(menu);
			items.forEach(function (p, i) {
				menu.appendChild(el('li', { role: 'option', class: i === index ? 'is-active' : '', 'aria-selected': i === index ? 'true' : 'false',
					on: { mousedown: function (e) { e.preventDefault(); choose(p); } } }, [avatar(p, 20), ' ' + p.name]));
			});
			menu.hidden = !items.length;
		}
		function choose(p) {
			if (!tok) { return; }
			var insert = '@' + p.name + ' ';
			ta.value = ta.value.slice(0, tok.start) + insert + ta.value.slice(tok.pos);
			var caret = tok.start + insert.length;
			ta.setSelectionRange(caret, caret);
			chosen[p.id] = p.name;
			hide(); ta.focus();
		}
		ta.addEventListener('input', function () {
			tok = token();
			if (!tok) { hide(); return; }
			items = S.people.filter(function (p) { return p.id !== S.me && p.name.toLowerCase().indexOf(tok.query) !== -1; }).slice(0, 6);
			index = 0; render();
		});
		ta.addEventListener('keydown', function (e) {
			if (menu.hidden) { return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); index = (index + 1) % items.length; render(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); index = (index - 1 + items.length) % items.length; render(); }
			else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); choose(items[index]); }
			else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); hide(); }
		}, true);
		ta.addEventListener('blur', function () { setTimeout(hide, 120); });
		return {
			ids: function () {
				return Object.keys(chosen).filter(function (id) { return ta.value.indexOf('@' + chosen[id]) !== -1; }).map(Number);
			}
		};
	}

	function cardUrlParams(id) { return pageUrl({ id: S.board.id, card: id }); }

	function openCard(id, opts) {
		opts = opts || {};
		if (CB && CB.id === id) { return; }
		if (CB) { CB.silent = true; CB.handle.close(); }

		var handle = openDialog({
			title: 'Card', cls: 'jy-boards-dialog--card',
			onClose: function () {
				var closing = CB;
				CB = null;
				if (!closing || closing.silent) { return; }
				if (closing.pushed && history.state && history.state.boardsCard) { closing.popping = true; history.back(); }
				else { history.replaceState(null, '', pageUrl({ card: null })); }
			}
		});
		handle.head.hidden = true;
		CB = { id: id, handle: handle, detail: null, pushed: false, drafts: { comment: '' }, editDesc: false, editTitle: false, stale: false };
		handle.body.appendChild(el('p', { class: 'jy-boards-muted', text: 'Loading…' }));

		if (!opts.noPush) {
			history.pushState({ boardsCard: id }, '', cardUrlParams(id));
			CB.pushed = true;
		}
		handle.dlg.addEventListener('focusout', function () {
			setTimeout(function () { if (CB && CB.stale && !cardBackBusy()) { refreshCard(); } }, 200);
		});

		var ready = opts.detail ? Promise.resolve(opts.detail) : run(api_('boards_card', { card_id: id }));
		ready.then(function (d) {
			if (!CB || CB.id !== id) { return; }
			if (!d) { handle.close(); return; }
			CB.detail = d;
			renderCardBack();
		});
	}

	function closeCard() { if (CB) { CB.handle.close(); } }

	/** True while the person is typing in the card back, so a refresh would eat their words. */
	function cardBackBusy() {
		if (!CB) { return false; }
		var a = document.activeElement;
		return !!(a && CB.handle.dlg.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) || CB.editDesc || CB.editTitle;
	}

	function refreshCard() {
		if (!CB) { return; }
		var id = CB.id;
		CB.stale = false;
		run(api_('boards_card', { card_id: id })).then(function (d) {
			if (d && CB && CB.id === id) {
				if (cardBackBusy()) { CB.stale = true; return; }
				CB.detail = d; renderCardBack();
			}
		});
	}

	/** Run a card operation and redraw the card back from the answer. */
	function cardOp(body) {
		body.card_id = CB.id; body.detail = true;
		var id = CB.id;
		return run(api_('boards_card_save', body)).then(function (d) {
			if (!d) { return null; }
			if (d.archived || d.deleted) {
				delete S.cards[id]; syncAllCards(); closeCard();
				return d;
			}
			if (d.card) { upsertCard(d.card); syncAllCards(); }
			if (CB && CB.id === id && d.detail) { CB.detail = d.detail; renderCardBack(); }
			return d;
		});
	}

	function sectionEl(title, children, extraClass) {
		return el('section', { class: 'jy-boards-cb-section ' + (extraClass || '') }, [el('h3', { text: title })].concat(children || []));
	}

	/** Where an email-made card came from, as this viewer may be told. */
	function sourceEmailLine(src) {
		if (!src) { return null; }
		if (src.state === 'open' && src.url) {
			return el('p', { class: 'jy-boards-muted' }, ['Made from an email. ',
				el('a', { href: src.url, target: '_blank', rel: 'noopener', text: 'Open the original email' })]);
		}
		return el('p', { class: 'jy-boards-muted', text: src.state === 'gone'
			? 'The original email is no longer available.'
			: 'From an email in a mailbox you can\'t open.' });
	}

	function renderCardBack() {
		if (!CB || !CB.detail) { return; }
		var dt = CB.detail, card = dt.card;
		var body = CB.handle.body;
		var scroll = CB.main ? CB.main.scrollTop : 0;
		var editor = (dt.role === 'editor' || dt.role === 'admin') && !card.archived && !S.board.archived;
		var admin = dt.role === 'admin';
		clear(body);

		// ---- header ----
		if (card.cover) { body.appendChild(el('img', { class: 'jy-boards-cb-cover', src: card.cover, alt: '' })); }
		var title;
		if (CB.editTitle && editor) {
			title = input('text', card.title, { maxlength: 255, 'aria-label': 'Card title', class: 'jy-boards-input jy-boards-cb-titleinput' });
			var saved = false;
			var saveTitle = function (save) {
				if (saved) { return; } saved = true;
				CB.editTitle = false;
				var v = title.value.trim();
				if (save && v && v !== card.title) { cardOp({ op: 'update', title: v }); } else { renderCardBack(); }
			};
			title.addEventListener('blur', function () { saveTitle(true); });
			title.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); saveTitle(true); }
				if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); saveTitle(false); }
			});
			setTimeout(function () { title.focus(); title.select(); }, 0);
		} else {
			title = el('h2', { class: 'jy-boards-cb-title', text: card.title, tabindex: editor ? 0 : null, title: editor ? 'Edit the title' : null });
			if (editor) {
				title.addEventListener('click', function () { CB.editTitle = true; renderCardBack(); });
				title.addEventListener('keydown', function (e) { if (e.key === 'Enter') { CB.editTitle = true; renderCardBack(); } });
			}
		}
		var head = el('div', { class: 'jy-boards-cb-head' }, [
			el('div', { class: 'jy-boards-cb-headtext' }, [
				title,
				el('p', { class: 'jy-boards-muted' }, [
					'in list ', el('strong', { text: card.list_name }),
					card.is_template ? ' · Template card' : ''
				]),
				sourceEmailLine(dt.source_email)
			]),
			el('button', { type: 'button', class: 'jy-boards-iconbtn', 'aria-label': 'Close card', on: { click: closeCard } }, [icon('close', 18)])
		]);
		body.appendChild(head);
		if (card.archived) {
			body.appendChild(el('div', { class: 'jy-boards-banner' }, [el('span', { text: 'This card is archived.' }),
				admin || editor ? null : null]));
		}

		// ---- main column ----
		var main = el('div', { class: 'jy-boards-cb-main' });
		CB.main = main;

		var meta = el('div', { class: 'jy-boards-cb-meta' });
		if (dt.labels.length) {
			meta.appendChild(el('div', { class: 'jy-boards-cb-metagroup' }, [el('h4', { text: 'Labels' }), el('div', { class: 'jy-boards-cb-chips' },
				dt.labels.map(labelById).filter(Boolean).map(function (l) {
					return el('button', { type: 'button', class: 'jy-boards-label jy-boards-label-' + l.color, text: l.name || l.color,
						disabled: editor ? null : true, on: { click: function () { pickLabels(); } } });
				}))]));
		}
		if (dt.members.length) {
			meta.appendChild(el('div', { class: 'jy-boards-cb-metagroup' }, [el('h4', { text: 'Members' }), el('div', { class: 'jy-boards-cb-chips' },
				dt.members.map(function (p) { return el('span', { class: 'jy-boards-person' }, [avatar(p, 28), ' ' + p.name]); }))]));
		}
		if (card.due) {
			var cs = { due: card.due, is_done: card.is_done };
			var ds = dueState(cs);
			var doneBox = el('input', { type: 'checkbox', 'aria-label': 'Done', disabled: editor ? null : true });
			doneBox.checked = !!card.is_done;
			doneBox.addEventListener('change', function () { cardOp({ op: 'update', is_done: doneBox.checked }); });
			meta.appendChild(el('div', { class: 'jy-boards-cb-metagroup' }, [el('h4', { text: 'Due' }),
				el('div', { class: 'jy-boards-cb-chips' }, [
					el('label', { class: 'jy-boards-checkline' }, [doneBox]),
					el('button', { type: 'button', class: 'jy-boards-due' + (ds ? ' is-' + ds : ''), disabled: editor ? null : true,
						on: { click: pickDue } }, [icon('clock', 12), ' ' + fmtDue(card.due), card.is_done ? ' · done' : (ds === 'overdue' ? ' · overdue' : '')])
				])]));
		}
		if (meta.children.length) { main.appendChild(meta); }

		main.appendChild(renderDescription(dt, editor));
		dt.checklists.forEach(function (c) { main.appendChild(renderChecklist(c, editor)); });
		if (dt.attachments.length) { main.appendChild(renderAttachments(dt, editor)); }
		main.appendChild(renderComments(dt, editor));
		main.appendChild(renderActivity(dt));

		// ---- sidebar: every action visible, labelled ----
		var side = el('aside', { class: 'jy-boards-cb-side', 'aria-label': 'Card actions' });
		function sideBtn(label, handler, cls) {
			side.appendChild(el('button', { type: 'button', class: 'btn btn-secondary jy-boards-sidebtn ' + (cls || ''), text: label, on: { click: handler } }));
		}
		if (editor) {
			side.appendChild(el('h4', { text: 'Add to card' }));
			sideBtn('Members', pickMembers); sideBtn('Labels', pickLabels); sideBtn('Due date', pickDue);
			sideBtn('Checklist', addChecklist); sideBtn('Attach', pickAttach); sideBtn('Cover', pickCover);
			side.appendChild(el('h4', { text: 'Card' }));
			sideBtn('Move', function () { moveCopyDialog('move'); }); sideBtn('Copy', function () { moveCopyDialog('copy'); });
			sideBtn(card.is_template ? 'Stop being a template' : 'Make a template', function () { cardOp({ op: 'set_template', is_template: !card.is_template }); });
		}
		side.appendChild(el('h4', { text: 'Actions' }));
		sideBtn(dt.watching ? 'Unwatch' : 'Watch', function () { cardOp({ op: dt.watching ? 'unwatch' : 'watch' }); });
		if (card.archived && (editor || dt.role === 'editor' || admin)) {
			sideBtn('Restore', function () { cardOp({ op: 'restore' }); }, 'btn-primary');
		} else if (editor) {
			sideBtn('Archive', function () { cardOp({ op: 'archive' }).then(function (d) { if (d) { toast('Card archived.'); } }); });
		}
		if (admin) {
			sideBtn('Delete', function () {
				confirmDialog('Delete this card? It disappears for everyone. A site admin can still remove it for good.', 'Delete card', function () { cardOp({ op: 'delete' }); }, true);
			}, 'btn-danger-soft');
		}

		body.appendChild(el('div', { class: 'jy-boards-cb-grid' }, [main, side]));
		main.scrollTop = scroll;
		if (CB.focusAdd) {
			var addInp = body.querySelector('[data-add-item="' + CB.focusAdd + '"]');
			if (addInp) { addInp.focus(); }
			CB.focusAdd = null;
		}
		if (CB.focusEl) { var fe = body.querySelector(CB.focusEl); if (fe) { fe.focus(); } CB.focusEl = null; }
	}

	// ---- description ------------------------------------------------------

	function renderDescription(dt, editor) {
		var card = dt.card;
		var sec = sectionEl('Description', []);
		if (CB.editDesc && editor) {
			var host = el('div', { class: 'jy-boards-mentionhost' });
			var ta = el('textarea', { class: 'jy-boards-input', rows: 6, 'aria-label': 'Description', placeholder: 'Add a more detailed description. Markdown works; type @ to mention someone.' });
			ta.value = CB.drafts.desc !== undefined ? CB.drafts.desc : card.description;
			host.appendChild(ta);
			var mentions = attachMentions(ta, host);
			ta.addEventListener('input', function () { CB.drafts.desc = ta.value; });
			ta.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !e.defaultPrevented) { e.preventDefault(); e.stopPropagation(); CB.editDesc = false; delete CB.drafts.desc; renderCardBack(); }
				if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); }
			});
			function save() {
				var text = ta.value;
				CB.editDesc = false; delete CB.drafts.desc;
				cardOp({ op: 'update', description: text, mentions: mentions.ids() });
			}
			append(sec, [host, el('div', { class: 'jy-boards-composer-actions' }, [
				button('Save', 'btn-primary btn-sm', save),
				button('Cancel', 'btn-secondary btn-sm', function () { CB.editDesc = false; delete CB.drafts.desc; renderCardBack(); })])]);
			setTimeout(function () { ta.focus(); }, 0);
		} else if (card.description_html) {
			var view = el('div', { class: 'jy-boards-md' });
			view.innerHTML = card.description_html;   // rendered and sanitised by the server
			if (editor) {
				view.classList.add('is-editable');
				view.addEventListener('click', function (e) { if (!e.target.closest('a')) { CB.editDesc = true; renderCardBack(); } });
			}
			sec.appendChild(view);
			if (editor) { sec.appendChild(button('Edit', 'btn-secondary btn-sm', function () { CB.editDesc = true; renderCardBack(); })); }
		} else if (editor) {
			sec.appendChild(el('button', { type: 'button', class: 'jy-boards-placeholder-btn', text: 'Add a more detailed description…',
				on: { click: function () { CB.editDesc = true; renderCardBack(); } } }));
		} else {
			sec.appendChild(el('p', { class: 'jy-boards-muted', text: 'No description.' }));
		}
		return sec;
	}

	// ---- checklists -------------------------------------------------------

	function chk(body) {
		body.card_id = CB.id;
		var id = CB.id;
		return run(api_('boards_checklist_save', body)).then(function (d) {
			if (!d) { return null; }
			if (d.card) { upsertCard(d.card); }
			if (d.new_card) { upsertCard(d.new_card); }
			syncAllCards();
			if (CB && CB.id === id) { CB.detail.checklists = d.checklists; renderCardBack(); }
			return d;
		});
	}

	function addChecklist() {
		promptDialog('Add a checklist', 'Title', 'Checklist', 'Add', function (v) { chk({ op: 'create', title: v }); });
	}

	function renderChecklist(c, editor) {
		var done = c.items.filter(function (i) { return i.is_done; }).length;
		var pct = c.items.length ? Math.round(done / c.items.length * 100) : 0;

		var titleEl = el('h4', { class: 'jy-boards-cl-title', text: c.title, tabindex: editor ? 0 : null });
		if (editor) {
			titleEl.addEventListener('click', function () {
				promptDialog('Rename checklist', 'Title', c.title, 'Save', function (v) { chk({ op: 'rename', checklist_id: c.id, title: v }); });
			});
		}
		var head = el('div', { class: 'jy-boards-cl-head' }, [titleEl, el('span', { class: 'jy-boards-muted', text: done + '/' + c.items.length })]);
		if (editor) {
			head.appendChild(button('Delete', 'btn-danger-soft btn-sm', function () {
				confirmDialog('Delete the checklist “' + c.title + '” and its items?', 'Delete checklist', function () { chk({ op: 'delete', checklist_id: c.id }); }, true);
			}));
		}
		var bar = el('div', { class: 'jy-boards-progress', role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100 },
			[el('span', { style: 'width:' + pct + '%' })]);

		var list = el('ul', { class: 'jy-boards-cl-items' });
		c.items.forEach(function (item) { list.appendChild(renderItem(c, item, editor)); });

		var sec = el('section', { class: 'jy-boards-cb-section' }, [head, bar, list]);
		if (editor) {
			var add = input('text', '', { placeholder: 'Add an item', 'aria-label': 'Add an item to ' + c.title, maxlength: 500, data: null });
			add.setAttribute('data-add-item', c.id);
			function submit() {
				var v = add.value.trim();
				if (!v) { return; }
				CB.focusAdd = c.id;
				chk({ op: 'item_add', checklist_id: c.id, text: v });
			}
			add.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
			sec.appendChild(el('div', { class: 'jy-boards-cl-add' }, [add, button('Add', 'btn-secondary btn-sm', submit)]));
		}
		return sec;
	}

	function renderItem(c, item, editor) {
		var box = el('input', { type: 'checkbox', 'aria-label': item.text, disabled: editor ? null : true });
		box.checked = !!item.is_done;
		box.addEventListener('change', function () { chk({ op: 'item_update', item_id: item.id, is_done: box.checked }); });

		var text = el('span', { class: 'jy-boards-cl-text' + (item.is_done ? ' is-done' : ''), text: item.text, tabindex: editor ? 0 : null });
		if (editor) {
			text.addEventListener('click', function () {
				promptDialog('Edit item', 'Item', item.text, 'Save', function (v) { chk({ op: 'item_update', item_id: item.id, text: v }); });
			});
		}
		var li = el('li', { class: 'jy-boards-cl-item' }, [box, text]);
		if (item.due) {
			var overdue = !item.is_done && new Date(item.due).getTime() < Date.now();
			li.appendChild(el('span', { class: 'jy-boards-due' + (overdue ? ' is-overdue' : ''), title: fmtStamp(item.due) }, [icon('clock', 12), ' ' + fmtDue(item.due)]));
		}
		if (item.user) { li.appendChild(avatar(item.user, 24)); }
		if (editor) {
			li.appendChild(button('Assign / date', 'btn-secondary btn-sm', function () { itemDialog(c, item); }));
			li.appendChild(button('Make a card', 'btn-secondary btn-sm', function () { chk({ op: 'item_to_card', item_id: item.id }).then(function (d) { if (d) { toast('Made a card from the item.'); } }); }));
			li.appendChild(el('button', { type: 'button', class: 'jy-boards-iconbtn', 'aria-label': 'Delete item', title: 'Delete item',
				on: { click: function () { chk({ op: 'item_delete', item_id: item.id }); } } }, [icon('close', 14)]));
		}
		return li;
	}

	/** Give a checklist item a person and/or a date. */
	function itemDialog(c, item) {
		var d = openDialog({ title: 'Assign and date', cls: 'jy-boards-dialog--small' });
		var who = select([['0', 'Nobody']].concat(S.people.map(function (p) { return [p.id, p.name]; })), item.user ? item.user.id : 0);
		var due = input('datetime-local', toLocalInput(item.due));
		append(d.body, [field('Assigned to', who), field('Due', due)]);
		d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
		d.foot.appendChild(button('Save', 'btn-primary', function () {
			d.close();
			chk({ op: 'item_update', item_id: item.id, user_id: parseInt(who.value, 10), due: fromLocalInput(due.value) });
		}));
	}

	// ---- attachments ------------------------------------------------------

	function fmtBytes(n) {
		if (n < 1024) { return n + ' B'; }
		if (n < 1048576) { return Math.round(n / 1024) + ' KB'; }
		return (n / 1048576).toFixed(1) + ' MB';
	}

	function renderAttachments(dt, editor) {
		var list = el('ul', { class: 'jy-boards-attachments' });
		dt.attachments.forEach(function (a) {
			var thumb = a.thumb
				? el('img', { class: 'jy-boards-att-thumb', src: a.thumb, alt: '', loading: 'lazy' })
				: el('span', { class: 'jy-boards-att-thumb jy-boards-att-icon' }, [icon('clip', 20)]);
			var nameEl = a.url
				? el('a', { href: a.url, target: '_blank', rel: 'noopener noreferrer', text: a.name })
				: el('span', { text: a.name });
			var info = [];
			if (a.is_link) { info.push('Link'); } else { info.push(fmtBytes(a.size || 0)); }
			if (a.user) { info.push('added by ' + a.user.name); }
			if (a.created) { info.push(fmtStamp(a.created)); }
			var actions = el('div', { class: 'jy-boards-att-actions' });
			if (editor) {
				if (a.is_image && a.file_id) {
					actions.appendChild(button(a.is_cover ? 'Remove cover' : 'Make cover', 'btn-secondary btn-sm', function () {
						cardOp({ op: 'set_cover', attachment_id: a.is_cover ? 0 : a.id });
					}));
				}
				actions.appendChild(button('Remove', 'btn-danger-soft btn-sm', function () {
					confirmDialog('Remove “' + a.name + '” from this card?', 'Remove', function () { cardOp({ op: 'remove_attachment', attachment_id: a.id }); }, true);
				}));
			}
			list.appendChild(el('li', { class: 'jy-boards-att' }, [thumb,
				el('div', { class: 'jy-boards-att-body' }, [nameEl, el('small', { class: 'jy-boards-muted', text: info.join(' · ') })]), actions]));
		});
		return sectionEl('Attachments', [list]);
	}

	// ---- comments ---------------------------------------------------------

	function renderComments(dt, editor) {
		var sec = sectionEl('Comments', []);
		if (editor) {
			var host = el('div', { class: 'jy-boards-mentionhost' });
			var ta = el('textarea', { class: 'jy-boards-input', rows: 2, 'aria-label': 'Write a comment', placeholder: 'Write a comment… type @ to mention someone' });
			ta.value = CB.drafts.comment || '';
			host.appendChild(ta);
			var mentions = attachMentions(ta, host);
			ta.addEventListener('input', function () { CB.drafts.comment = ta.value; });
			function post() {
				var v = ta.value.trim();
				if (!v) { ta.focus(); return; }
				var ids = mentions.ids();
				ta.disabled = true;
				var id = CB.id;
				run(api_('boards_comment_save', { op: 'create', card_id: id, body: v, mentions: ids })).then(function (d) {
					if (!d) { ta.disabled = false; return; }
					CB.drafts.comment = '';
					upsertCard(d.card); syncAllCards();
					if (CB && CB.id === id) { CB.detail.comments = d.comments; CB.focusEl = 'textarea[aria-label="Write a comment"]'; refreshCardActivity(); renderCardBack(); }
				});
			}
			ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); post(); } });
			append(sec, [host, el('div', { class: 'jy-boards-composer-actions' }, [button('Save comment', 'btn-primary btn-sm', post)])]);
		}
		var list = el('ul', { class: 'jy-boards-comments' });
		dt.comments.forEach(function (c) { list.appendChild(renderComment(c)); });
		sec.appendChild(list);
		return sec;
	}

	function renderComment(c) {
		var body = el('div', { class: 'jy-boards-md' });
		body.innerHTML = c.body_html;   // rendered and sanitised by the server
		var li = el('li', { class: 'jy-boards-comment' }, [
			avatar(c.user || { name: 'Unknown' }, 32),
			el('div', { class: 'jy-boards-comment-main' }, [
				el('div', { class: 'jy-boards-comment-head' }, [
					el('strong', { text: c.user ? c.user.name : 'Unknown' }),
					el('small', { class: 'jy-boards-muted', text: ' ' + fmtStamp(c.created) + (c.edited ? ' (edited)' : '') + (c.source === 'email' ? ' · by email' : '') })
				]),
				body
			])
		]);
		var actions = el('div', { class: 'jy-boards-comment-actions' });
		if (c.can_edit) {
			actions.appendChild(button('Edit', 'btn-secondary btn-sm', function () {
				promptDialog('Edit comment', 'Comment', c.body, 'Save', function (v) {
					var id = CB.id;
					run(api_('boards_comment_save', { op: 'update', card_id: id, comment_id: c.id, body: v })).then(function (d) {
						if (d && CB && CB.id === id) { CB.detail.comments = d.comments; renderCardBack(); }
					});
				}, true);
			}));
		}
		if (c.can_delete) {
			actions.appendChild(button('Delete', 'btn-danger-soft btn-sm', function () {
				confirmDialog('Delete this comment?', 'Delete comment', function () {
					var id = CB.id;
					run(api_('boards_comment_save', { op: 'delete', card_id: id, comment_id: c.id })).then(function (d) {
						if (d) { upsertCard(d.card); syncAllCards(); if (CB && CB.id === id) { CB.detail.comments = d.comments; renderCardBack(); } }
					});
				}, true);
			}));
		}
		if (actions.children.length) { li.lastChild.appendChild(actions); }
		return li;
	}

	/** After something the card back changed locally, pull the history list fresh. */
	function refreshCardActivity() {
		var id = CB && CB.id;
		if (!id) { return; }
		run(api_('boards_card', { card_id: id })).then(function (d) {
			if (d && CB && CB.id === id) { CB.detail.activity = d.activity; if (!cardBackBusy()) { renderCardBack(); } }
		});
	}

	// ---- activity ---------------------------------------------------------

	function activityText(a) {
		var d = a.data || {};
		switch (a.verb) {
			case 'card.created': return 'created this card' + (d.list_name ? ' in “' + d.list_name + '”' : '');
			case 'card.moved':
				if (d.left_board) { return 'moved this card to another board'; }
				if (d.arrived_from_another_board) { return 'moved this card here from another board'; }
				return 'moved this card' + (d.from ? ' from “' + d.from + '”' : '') + (d.to ? ' to “' + d.to + '”' : '');
			case 'card.renamed': return 'renamed this card (it was “' + (d.from || '') + '”)';
			case 'card.described': return 'edited the description';
			case 'card.due_set': return d.to ? 'set the due date to ' + fmtDue(d.to) : 'removed the due date';
			case 'card.done': return d.is_done ? 'marked this card done' : 'marked this card not done';
			case 'card.archived': return 'archived this card';
			case 'card.restored': return 'restored this card';
			case 'member.added': return 'added ' + (d.name || 'someone') + ' to this card';
			case 'member.removed': return 'removed ' + (d.name || 'someone') + ' from this card';
			case 'label.added': return 'added the label “' + (d.name || d.color || '') + '”';
			case 'label.removed': return 'removed the label “' + (d.name || d.color || '') + '”';
			case 'checklist.item_done': return (d.is_done ? 'completed' : 'reopened') + ' “' + (d.text || '') + '”' + (d.checklist ? ' in ' + d.checklist : '');
			case 'comment.added': return 'commented';
			case 'attachment.added': return 'attached “' + (d.name || '') + '”';
			default: return a.verb;
		}
	}

	function renderActivity(dt) {
		var list = el('ul', { class: 'jy-boards-activity' });
		dt.activity.forEach(function (a) {
			list.appendChild(el('li', {}, [
				el('strong', { text: a.user ? a.user.name : 'Someone' }), ' ' + activityText(a),
				el('small', { class: 'jy-boards-muted', text: ' · ' + fmtStamp(a.created) })
			]));
		});
		if (!dt.activity.length) { list.appendChild(el('li', { class: 'jy-boards-muted', text: 'Nothing yet.' })); }
		return sectionEl('Activity', [list]);
	}

	// =========================================================================
	// Pickers
	// =========================================================================

	function pickMembers() {
		var d = openDialog({ title: 'Members', cls: 'jy-boards-dialog--small' });
		var list = el('ul', { class: 'jy-boards-picklist' });
		var results = el('ul', { class: 'jy-boards-picklist' });

		function draw() {
			clear(list);
			var onCard = {};
			CB.detail.members.forEach(function (m) { onCard[m.id] = true; });
			S.people.forEach(function (p) {
				var box = el('input', { type: 'checkbox', 'aria-label': p.name });
				box.checked = !!onCard[p.id];
				box.addEventListener('change', function () { toggle(p.id, box.checked); });
				list.appendChild(el('li', {}, [el('label', { class: 'jy-boards-pickrow' }, [box, avatar(p, 28), el('span', { text: p.name }),
					el('small', { class: 'jy-boards-muted', text: p.role })])]));
			});
		}
		function toggle(userId, add) {
			cardOp({ op: add ? 'add_member' : 'remove_member', user_id: userId }).then(draw);
		}
		append(d.body, [list]);
		draw();

		// An Open to all members board can put anyone who can open it on a card.
		if (S.board.visibility === 'all_members') {
			var search = input('search', '', { placeholder: 'Find someone by name', 'aria-label': 'Find someone by name' });
			search.addEventListener('input', debounce(function () {
				var q = search.value.trim();
				clear(results);
				if (q.length < 2) { return; }
				run(api_('boards_members_save', { op: 'search_people', board_id: S.board.id, q: q })).then(function (r) {
					if (!r) { return; }
					r.people.forEach(function (p) {
						results.appendChild(el('li', {}, [el('button', { type: 'button', class: 'jy-boards-pickrow', on: { click: function () {
							cardOp({ op: 'add_member', user_id: p.id }).then(function (res) {
								if (res && !S.people.some(function (x) { return x.id === p.id; })) { S.people.push({ id: p.id, name: p.name, avatar: p.avatar, role: 'viewer' }); }
								draw();
							});
						} } }, [avatar(p, 28), el('span', { text: p.name })])]));
					});
				});
			}, 250));
			d.body.insertBefore(field('Add someone else', search), list);
			d.body.appendChild(results);
		}
		d.foot.appendChild(button('Done', 'btn-primary', d.close));
		focusFirst(d);
	}

	function pickLabels() {
		var d = openDialog({ title: 'Labels', cls: 'jy-boards-dialog--small' });
		var list = el('ul', { class: 'jy-boards-picklist' });
		var admin = isAdmin();

		function drawList() {
			clear(list);
			var on = {};
			CB.detail.labels.forEach(function (id) { on[id] = true; });
			S.labels.forEach(function (l) {
				var box = el('input', { type: 'checkbox', 'aria-label': l.name || l.color });
				box.checked = !!on[l.id];
				box.addEventListener('change', function () {
					cardOp({ op: box.checked ? 'add_label' : 'remove_label', label_id: l.id }).then(drawList);
				});
				var row = el('li', { class: 'jy-boards-labelrow' }, [
					el('label', { class: 'jy-boards-pickrow' }, [box, el('span', { class: 'jy-boards-label jy-boards-label-' + l.color, text: l.name || ' ' })])
				]);
				if (admin) { row.appendChild(button('Edit', 'btn-secondary btn-sm', function () { editLabel(l); })); }
				list.appendChild(row);
			});
			if (!S.labels.length) { list.appendChild(el('li', { class: 'jy-boards-muted', text: 'This board has no labels yet.' })); }
		}

		function editLabel(l) { labelEditor(l, drawList); }

		d.body.appendChild(list);
		if (admin) { d.body.appendChild(button('Create a new label', 'btn-secondary btn-sm', function () { editLabel(null); })); }
		d.foot.appendChild(button('Done', 'btn-primary', d.close));
		drawList();
	}

	function pickDue() {
		var d = openDialog({ title: 'Due date', cls: 'jy-boards-dialog--small' });
		var inp = input('datetime-local', toLocalInput(CB.detail.card.due));
		d.body.appendChild(field('Due', inp, 'In your time zone. Leave the time at 12:00 AM for an all-day date.'));
		d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
		if (CB.detail.card.due) {
			d.foot.appendChild(button('Remove', 'btn-danger-soft', function () { d.close(); cardOp({ op: 'update', due: '' }); }));
		}
		d.foot.appendChild(button('Save', 'btn-primary', function () {
			var iso = fromLocalInput(inp.value);
			if (!iso) { inp.focus(); return; }
			d.close(); cardOp({ op: 'update', due: iso });
		}));
		inp.focus();
	}

	// ---- attach ------------------------------------------------------------

	var DIRECT_UPLOAD_LIMIT = 8 * 1024 * 1024;

	function pickAttach() {
		var d = openDialog({ title: 'Attach', cls: 'jy-boards-dialog--small' });
		var file = el('input', { type: 'file', multiple: true, class: 'jy-boards-input', 'aria-label': 'Choose files' });
		var status = el('ul', { class: 'jy-boards-uploads' });
		var url = input('url', '', { placeholder: 'https://…', 'aria-label': 'Link address' });
		var name = input('text', '', { placeholder: 'Link name (optional)', 'aria-label': 'Link name' });
		append(d.body, [
			el('h4', { text: 'A file from your computer' }), file, status,
			el('h4', { text: 'A link' }), url, name
		]);

		file.addEventListener('change', function () {
			Array.prototype.forEach.call(file.files, function (f) { uploadFile(f, status, false); });
			file.value = '';
		});
		d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
		d.foot.appendChild(button('Attach link', 'btn-primary', function () {
			if (!url.value.trim()) { url.focus(); return; }
			d.close(); cardOp({ op: 'add_link', url: url.value.trim(), name: name.value.trim() });
		}));
	}

	/** Upload one file to the open card: one request when small, the resumable chunked upload when not. */
	function uploadFile(f, statusList, asCover) {
		var row = el('li', {}, [f.name + ' — ', el('span', { text: 'uploading…' })]);
		statusList.appendChild(row);
		var cardId = CB.id;
		function finish(d) {
			if (!d) { row.lastChild.textContent = 'failed'; return; }
			row.lastChild.textContent = 'attached';
			if (d.card) { upsertCard(d.card); syncAllCards(); }
			if (CB && CB.id === cardId) { CB.detail.attachments = d.attachments; refreshCardActivity(); renderCardBack(); }
		}
		function problem(err) { row.lastChild.textContent = (err && err.message) || 'failed'; fail(err); }

		if (f.size <= DIRECT_UPLOAD_LIMIT) {
			var fd = new FormData();
			fd.append('file', f, f.name); fd.append('card_id', cardId);
			if (asCover) { fd.append('as_cover', '1'); }
			api.postForm('boards/boards_upload', fd).then(finish).catch(problem);
			return;
		}
		// Big file: open the resumable upload, PUT it in chunks, then claim it onto the card.
		var maxMb = (S.settings && S.settings.max_attachment_mb) || 25;
		if (f.size > maxMb * 1048576) { problem(new Error('Files can be up to ' + maxMb + ' MB. That one is larger.')); return; }
		api.post('upload_init', { purpose: 'boards_attachment', board_id: S.board.id, name: f.name, size_bytes: f.size, mime_type: f.type || 'application/octet-stream' })
			.then(function (init) {
				var chunk = init.chunk_bytes || 8388608, offset = 0;
				function next() {
					if (offset >= f.size) {
						return api.post('upload_complete', { upload_token: init.upload_token });
					}
					var end = Math.min(offset + chunk, f.size);
					return fetch('/api/v1/drive_upload/' + encodeURIComponent(init.upload_token), {
						method: 'PUT',
						headers: { 'X-Joinery-Csrf': api.csrf(), 'Content-Range': 'bytes ' + offset + '-' + (end - 1) + '/' + f.size, 'Content-Type': 'application/octet-stream' },
						body: f.slice(offset, end)
					}).then(function (resp) {
						return resp.json().then(function (j) {
							if (resp.status === 409) { offset = (j.data && j.data.received_bytes) || 0; return next(); }
							if (!resp.ok) { throw new Error((j && j.message) || 'The upload did not finish.'); }
							offset = j.data.received_bytes;
							row.lastChild.textContent = Math.round(offset / f.size * 100) + '%';
							return next();
						});
					});
				}
				return next();
			})
			.then(function (done) { return api.post('boards/boards_upload', { card_id: cardId, file_id: done.file.id, as_cover: asCover ? 1 : 0 }); })
			.then(finish).catch(problem);
	}

	function pickCover() {
		var d = openDialog({ title: 'Cover', cls: 'jy-boards-dialog--small' });
		var images = CB.detail.attachments.filter(function (a) { return a.is_image && a.file_id; });
		var status = el('ul', { class: 'jy-boards-uploads' });
		if (images.length) {
			var grid = el('div', { class: 'jy-boards-covergrid' });
			images.forEach(function (a) {
				grid.appendChild(el('button', { type: 'button', class: 'jy-boards-coverpick' + (a.is_cover ? ' is-selected' : ''), title: a.name,
					on: { click: function () { d.close(); cardOp({ op: 'set_cover', attachment_id: a.id }); } } }, [el('img', { src: a.thumb, alt: a.name })]));
			});
			d.body.appendChild(grid);
		} else {
			d.body.appendChild(el('p', { class: 'jy-boards-muted', text: 'Attach an image first, or choose one now.' }));
		}
		var file = el('input', { type: 'file', accept: 'image/*', class: 'jy-boards-input', 'aria-label': 'Choose a cover image' });
		file.addEventListener('change', function () { if (file.files[0]) { uploadFile(file.files[0], status, true); } });
		append(d.body, [field('Use a new image', file), status]);
		d.foot.appendChild(button('Close', 'btn-secondary', d.close));
		if (CB.detail.card.cover) {
			d.foot.appendChild(button('Remove cover', 'btn-danger-soft', function () { d.close(); cardOp({ op: 'set_cover', attachment_id: 0 }); }));
		}
	}

	// ---- move and copy -----------------------------------------------------

	function moveCopyDialog(mode) {
		var isMove = mode === 'move';
		var cardId = CB.id;
		var d = openDialog({ title: isMove ? 'Move card' : 'Copy card', cls: 'jy-boards-dialog--small' });
		d.body.appendChild(el('p', { class: 'jy-boards-muted', text: 'Loading…' }));

		run(api_('boards_card', { card_id: cardId, move_targets: true })).then(function (data) {
			if (!data) { d.close(); return; }
			var targets = data.move_targets || [];
			clear(d.body);
			var cache = {};   // board id -> its cards, for the position list

			var boardSel = select(targets.map(function (b) { return [b.id, b.name]; }), S.board.id);
			var listSel = select([], '');
			var posSel = select([], '');
			var title = input('text', CB.detail.card.title, { maxlength: 255 });
			var keep = el('input', { type: 'checkbox' });

			var fields = [field('Board', boardSel), field('List', listSel), field('Position', posSel)];
			if (!isMove) { fields.push(field('Title', title)); fields.push(el('label', { class: 'jy-boards-checkline' }, [keep, ' Keep the members'])); }
			append(d.body, fields);

			function drawLists() {
				var b = targets.filter(function (x) { return String(x.id) === boardSel.value; })[0];
				var current = CB.detail.card.list_id;
				clear(listSel);
				(b ? b.lists : []).forEach(function (l) {
					var o = el('option', { value: l.id, text: l.name });
					if (b.id === S.board.id ? l.id === current : false) { o.selected = true; }
					listSel.appendChild(o);
				});
				drawPositions();
			}
			function cardsOn(boardId, listId) {
				if (boardId === S.board.id) { return Promise.resolve(cardsOfList(listId)); }
				if (cache[boardId]) { return Promise.resolve(cache[boardId].filter(function (c) { return c.list_id === listId; })); }
				return run(api_('boards_board', { board_id: boardId })).then(function (r) {
					cache[boardId] = r ? r.cards.slice().sort(function (a, b) { return a.position - b.position; }) : [];
					return cache[boardId].filter(function (c) { return c.list_id === listId; });
				});
			}
			function drawPositions() {
				var boardId = parseInt(boardSel.value, 10), listId = parseInt(listSel.value, 10);
				cardsOn(boardId, listId).then(function (cards) {
					cards = cards.filter(function (c) { return c.id !== cardId || !isMove; });
					clear(posSel);
					for (var i = 0; i <= cards.length; i++) {
						var o = el('option', { value: i, text: i === 0 ? 'Top' : (i === cards.length ? 'Bottom' : 'After “' + cards[i - 1].title.slice(0, 40) + '”') });
						posSel.appendChild(o);
					}
					posSel.value = String(cards.length);
					posSel._cards = cards;
				});
			}
			boardSel.addEventListener('change', drawLists);
			listSel.addEventListener('change', drawPositions);
			drawLists();

			d.foot.appendChild(button('Cancel', 'btn-secondary', d.close));
			d.foot.appendChild(button(isMove ? 'Move' : 'Copy', 'btn-primary', function () {
				var cards = posSel._cards || [], at = parseInt(posSel.value, 10) || 0;
				var prevId = at > 0 && cards[at - 1] ? cards[at - 1].id : null;
				var nextId = cards[at] ? cards[at].id : null;
				var listId = parseInt(listSel.value, 10), boardId = parseInt(boardSel.value, 10);
				if (!listId) { return; }
				d.close();
				if (isMove) {
					run(api_('boards_card_move', { card_id: cardId, list_id: listId, prev_id: prevId, next_id: nextId })).then(function (r) {
						if (!r) { return; }
						if (r.board_id !== S.board.id) {
							delete S.cards[cardId]; syncAllCards(); closeCard();
							toast('Card moved to the board “' + (targets.filter(function (t) { return t.id === r.board_id; })[0] || {}).name + '”.');
						} else {
							upsertCard(r.card); syncAllCards(); if (CB && CB.id === cardId) { refreshCard(); }
						}
					});
				} else {
					run(api_('boards_card_save', { op: 'copy', card_id: cardId, list_id: listId, title: title.value.trim(), keep_members: keep.checked ? 1 : 0, prev_id: prevId, next_id: nextId })).then(function (r) {
						if (!r) { return; }
						if (r.board_id === S.board.id) { upsertCard(r.card); syncAllCards(); toast('Card copied.'); }
						else { toast('Card copied to the other board.'); }
					});
				}
			}));
		});
	}

	// =========================================================================
	// Board menu
	// =========================================================================

	var ROLE_TEXT = { admin: 'Admin', editor: 'Editor', viewer: 'Viewer' };

	function menuSection(title, id, children) {
		return el('section', { class: 'jy-boards-menusec', id: 'boards-menu-' + id }, [el('h3', { text: title })].concat(children));
	}

	function openBoardMenu(focus) {
		var d = openDialog({ title: 'Board menu', cls: 'jy-boards-dialog--menu' });
		var admin = isAdmin();
		var body = d.body;

		function saveBoard(changes, then) {
			changes.op = 'update'; changes.board_id = S.board.id;
			run(api_('boards_board_save', changes)).then(function (r) {
				if (!r) { return; }
				S.board = r.board; S.role = r.role; renderChrome(); refreshAffordances(); syncLists();
				if (then) { then(r); }
			});
		}

		// ---- about ----
		if (admin) {
			var name = input('text', S.board.name, { maxlength: 255 });
			var desc = el('textarea', { class: 'jy-boards-input', rows: 3 }); desc.value = S.board.description || '';
			var vis = select([['invite_only', 'Invite only'], ['all_members', 'Open to all members']], S.board.visibility);
			var visHelp = el('small', { class: 'jy-boards-muted', text: VISIBILITY_HELP[S.board.visibility] });
			vis.addEventListener('change', function () { visHelp.textContent = VISIBILITY_HELP[vis.value]; });
			body.appendChild(menuSection('About this board', 'about', [
				field('Name', name), field('Description', desc), field('Who can open it', vis), visHelp,
				button('Save', 'btn-primary btn-sm', function () {
					saveBoard({ name: name.value, description: desc.value, visibility: vis.value }, function () { toast('Saved.'); });
				})]));

			// ---- background ----
			var swatches = el('div', { class: 'jy-boards-swatches', role: 'radiogroup', 'aria-label': 'Background colour' });
			var drawSw = function () {
				clear(swatches);
				(boot.backgrounds || []).forEach(function (c) {
					swatches.appendChild(el('button', { type: 'button', role: 'radio', title: c, 'aria-checked': S.board.background === c && !S.board.background_url ? 'true' : 'false',
						class: 'jy-boards-swatch jy-boards-bg-' + c + (S.board.background === c && !S.board.background_url ? ' is-selected' : ''),
						on: { click: function () { saveBoard({ background: c }, drawSw); } } }));
				});
			};
			drawSw();
			var bgFile = el('input', { type: 'file', accept: 'image/*', class: 'jy-boards-input', 'aria-label': 'Upload a background image' });
			bgFile.addEventListener('change', function () {
				if (!bgFile.files[0]) { return; }
				var fd = new FormData(); fd.append('file', bgFile.files[0]); fd.append('board_id', S.board.id); fd.append('purpose', 'background');
				api.postForm('boards/boards_upload', fd).then(function (r) { S.board = r.board; renderChrome(); drawSw(); toast('Background changed.'); }).catch(fail);
			});
			body.appendChild(menuSection('Background', 'background', [swatches, field('Or use an image', bgFile)]));

			// ---- labels ----
			var labelList = el('ul', { class: 'jy-boards-picklist' });
			var drawLabels = function () {
				clear(labelList);
				S.labels.forEach(function (l) {
					labelList.appendChild(el('li', { class: 'jy-boards-labelrow' }, [
						el('span', { class: 'jy-boards-label jy-boards-label-' + l.color, text: l.name || ' ' }),
						button('Edit', 'btn-secondary btn-sm', function () { labelEditor(l, drawLabels); })]));
				});
			};
			drawLabels();
			body.appendChild(menuSection('Labels', 'labels', [labelList, button('Create a label', 'btn-secondary btn-sm', function () { labelEditor(null, drawLabels); })]));
		}

		// ---- people ----
		var peopleBox = el('div', {});
		body.appendChild(menuSection('People', 'people', [peopleBox]));
		drawPeople(peopleBox, admin);

		// ---- email to this board ----
		if (S.role !== 'viewer' || admin) {
			var emailBox = el('div', {});
			var emailSec = menuSection('Email to this board', 'email', [emailBox]);
			emailSec.hidden = true;
			body.appendChild(emailSec);
			loadEmailPanel(emailBox, emailSec, { op: 'panel', board_id: S.board.id });
		}

		// ---- archived items ----
		var archBox = el('div', {});
		var archBtn = button('Show archived cards and lists', 'btn-secondary btn-sm', function () {
			archBtn.disabled = true;
			run(api_('boards_board', { board_id: S.board.id, include_archived: true })).then(function (r) {
				archBtn.disabled = false;
				if (!r) { return; }
				drawArchived(archBox, r);
			});
		});
		body.appendChild(menuSection('Archive', 'archive', [archBtn, archBox]));

		// ---- board actions ----
		var actions = [];
		if (admin) {
			actions.push(button(S.board.is_template ? 'Stop being a template' : 'Make this a template', 'btn-secondary btn-sm', function () {
				run(api_('boards_board_save', { op: 'set_template', board_id: S.board.id, is_template: !S.board.is_template })).then(function (r) {
					if (r) { S.board = r.board; renderChrome(); d.close(); toast(S.board.is_template ? 'This board is now a template. People copy it to start their own.' : 'This board is a normal board again.'); }
				});
			}));
			actions.push(S.board.archived
				? button('Restore board', 'btn-primary btn-sm', function () {
					run(api_('boards_board_save', { op: 'restore', board_id: S.board.id })).then(function (r) { if (r) { S.board = r.board; renderChrome(); refreshAffordances(); d.close(); } });
				})
				: button('Archive board', 'btn-secondary btn-sm', function () {
					confirmDialog('Archive this board? It becomes read only. You can restore it from here.', 'Archive board', function () {
						run(api_('boards_board_save', { op: 'archive', board_id: S.board.id })).then(function (r) { if (r) { S.board = r.board; renderChrome(); refreshAffordances(); d.close(); } });
					});
				}));
			actions.push(button('Delete board', 'btn-danger-soft btn-sm', function () {
				confirmDialog('Delete “' + S.board.name + '”? The board and its cards disappear for everyone. A site admin can restore it.', 'Delete board', function () {
					run(api_('boards_board_save', { op: 'delete', board_id: S.board.id })).then(function (r) { if (r) { window.location.href = '/profile/boards'; } });
				}, true);
			}));
		}
		actions.push(button('Leave this board', 'btn-secondary btn-sm', function () {
			confirmDialog('Leave this board? You lose your place on its cards.', 'Leave board', function () {
				run(api_('boards_members_save', { op: 'leave', board_id: S.board.id })).then(function (r) { if (r) { window.location.href = '/profile/boards'; } });
			});
		}));
		body.appendChild(menuSection('Board', 'board', [el('div', { class: 'jy-boards-menuactions' }, actions)]));

		d.foot.appendChild(button('Close', 'btn-primary', d.close));
		if (focus) { var sec = document.getElementById('boards-menu-' + focus); if (sec) { sec.scrollIntoView(); } }
	}

	/** Make or edit a board label from the board menu. */
	function labelEditor(l, after) {
		var e = openDialog({ title: l ? 'Edit label' : 'New label', cls: 'jy-boards-dialog--small' });
		var name = input('text', l ? l.name : '', { maxlength: 100, placeholder: 'Name (optional)' });
		var color = l ? l.color : 'green';
		var swatches = el('div', { class: 'jy-boards-swatches', role: 'radiogroup', 'aria-label': 'Colour' });
		var draw = function () {
			clear(swatches);
			(boot.label_colors || []).forEach(function (c) {
				swatches.appendChild(el('button', { type: 'button', role: 'radio', title: c, 'aria-checked': c === color ? 'true' : 'false',
					class: 'jy-boards-swatch jy-boards-label-' + c + (c === color ? ' is-selected' : ''), on: { click: function () { color = c; draw(); } } }));
			});
		};
		draw();
		append(e.body, [field('Name', name), el('div', {}, [el('span', { class: 'jy-boards-fieldlabel', text: 'Colour' }), swatches])]);
		function applied(r) { if (r) { S.labels = r.labels; buildFilterBar(); syncAllCards(); if (after) { after(); } } }
		e.foot.appendChild(button('Cancel', 'btn-secondary', e.close));
		if (l) {
			e.foot.appendChild(button('Delete', 'btn-danger-soft', function () {
				e.close();
				confirmDialog('Delete this label from every card on the board?', 'Delete label', function () { run(api_('boards_label_save', { op: 'delete', label_id: l.id })).then(applied); }, true);
			}));
		}
		e.foot.appendChild(button('Save', 'btn-primary', function () {
			e.close();
			run(api_('boards_label_save', l ? { op: 'update', label_id: l.id, name: name.value, color: color } : { op: 'create', board_id: S.board.id, name: name.value, color: color })).then(applied);
		}));
		name.focus();
	}

	// ---- people ------------------------------------------------------------

	// ---- email to this board ------------------------------------------------

	/** Ask the server for the panel (or run one of its operations) and draw the answer. */
	function loadEmailPanel(box, section, params) {
		run(api_('boards_email', params)).then(function (r) {
			if (!r || !r.email || !r.email.available) { section.hidden = true; return; }
			section.hidden = false;
			drawEmailPanel(box, section, r.email);
		});
	}

	function drawEmailPanel(box, section, em) {
		clear(box);
		if (em.status !== 'ok') {
			box.appendChild(el('p', { class: 'jy-boards-muted', text: em.reason || 'Email is not available for this board.' }));
			if (em.is_admin) { drawDomainPicker(box, section, em); }
			return;
		}
		if (!em.can_have_address) {
			box.appendChild(el('p', { class: 'jy-boards-muted', text: 'Viewers have no email address for this board. Editors and admins can add cards by email.' }));
			return;
		}
		box.appendChild(el('p', { class: 'jy-boards-muted',
			text: 'Email this address from your own email account and the message becomes a card on this board, made by you. Keep it private: anyone with it who also sends from your address can add cards as you.' }));
		if (em.address) {
			var addr = el('input', { type: 'text', class: 'jy-boards-input', readonly: true, value: em.address, 'aria-label': 'Your email address for this board' });
			addr.addEventListener('focus', function () { addr.select(); });
			var copy = button('Copy', 'btn-secondary btn-sm', function () {
				addr.select();
				var done = function () { toast('Copied.'); };
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(em.address).then(done, function () { document.execCommand('copy'); done(); }); }
				else { document.execCommand('copy'); done(); }
			});
			var reset = button('Reset address', 'btn-secondary btn-sm', function () {
				confirmDialog('Make a new address? The old one stops working at once, anywhere you saved it.', 'Reset address', function () {
					loadEmailPanel(box, section, { op: 'reset', board_id: S.board.id });
				});
			});
			box.appendChild(el('div', { class: 'jy-boards-row' }, [addr, copy, reset]));
			box.appendChild(el('small', { class: 'jy-boards-muted',
				text: 'The subject becomes the title. Add #label to apply a label and @name to add a board member.' }));
		}
		if (!em.is_admin) { return; }

		// ---- admin: where cards land, and which domain ----
		var lists = sortedLists();
		var listSel = select([['', 'The first list']].concat(lists.map(function (l) { return [l.id, l.name]; })), em.landing.list_id || '');
		var posSel = select([['bottom', 'the bottom'], ['top', 'the top']], em.landing.position);
		var landingBtn = button('Save', 'btn-secondary btn-sm', function () {
			loadEmailPanel(box, section, { op: 'set_landing', board_id: S.board.id, list_id: listSel.value, position: posSel.value });
		});
		box.appendChild(el('h4', { text: 'Cards made by email' }));
		box.appendChild(el('div', { class: 'jy-boards-row' }, [field('Land in', listSel), field('At', posSel), landingBtn]));

		drawDomainPicker(box, section, em);
	}

	/** The admin's choice of mail domain, when the panel offers one. */
	function drawDomainPicker(box, section, em) {
		if (!em.domains || !em.domains.length) { return; }
		var domSel = select(em.domains.map(function (d) { return [d.id, d.name]; }), em.domain_id);
		box.appendChild(el('h4', { text: 'Address domain' }));
		box.appendChild(el('div', { class: 'jy-boards-row' }, [field('Use', domSel),
			button('Save', 'btn-secondary btn-sm', function () {
				loadEmailPanel(box, section, { op: 'set_domain', board_id: S.board.id, domain_id: domSel.value });
			})]));
		box.appendChild(el('small', { class: 'jy-boards-muted', text: 'Addresses people already have keep working at the old domain.' }));
	}

	function drawPeople(box, admin) {
		clear(box);
		if (!admin) {
			var ul = el('ul', { class: 'jy-boards-picklist' });
			S.people.forEach(function (p) {
				ul.appendChild(el('li', { class: 'jy-boards-pickrow' }, [avatar(p, 28), el('span', { text: p.name }), el('small', { class: 'jy-boards-muted', text: ROLE_TEXT[p.role] || p.role })]));
			});
			if (S.board.visibility === 'all_members') { box.appendChild(el('p', { class: 'jy-boards-muted', text: 'Every member of the site can open this board.' })); }
			box.appendChild(ul);
			return;
		}
		box.appendChild(el('p', { class: 'jy-boards-muted', text: 'Loading…' }));
		run(api_('boards_members_save', { op: 'members', board_id: S.board.id })).then(function (r) {
			if (!r) { return; }
			applyMembers(r);
			clear(box);
			var list = el('ul', { class: 'jy-boards-picklist' });
			r.member_rows.forEach(function (m) {
				var roleSel = select([['admin', 'Admin'], ['editor', 'Editor'], ['viewer', 'Viewer']], m.role);
				roleSel.setAttribute('aria-label', 'Role for ' + m.name);
				roleSel.addEventListener('change', function () {
					run(api_('boards_members_save', { op: 'set_role', board_id: S.board.id, member_id: m.id, role: roleSel.value })).then(function (x) {
						if (x) { applyMembers(x); } else { roleSel.value = m.role; }
						drawPeople(box, admin);
					});
				});
				list.appendChild(el('li', { class: 'jy-boards-pickrow' }, [
					m.kind === 'user' ? avatar(m, 28) : el('span', { class: 'jy-boards-avatar jy-boards-avatar--initials', text: 'G', title: 'Group' }),
					el('span', { text: m.name + (m.kind === 'group' ? ' (group)' : '') }), roleSel,
					button('Remove', 'btn-danger-soft btn-sm', function () {
						run(api_('boards_members_save', { op: 'remove', board_id: S.board.id, member_id: m.id })).then(function (x) {
							if (x) { applyMembers(x); } drawPeople(box, admin);
						});
					})]));
			});
			box.appendChild(list);

			// add a person or a group
			var who = input('search', '', { placeholder: 'Add a person by name', 'aria-label': 'Add a person by name' });
			var role = select([['editor', 'Editor'], ['viewer', 'Viewer'], ['admin', 'Admin']], 'editor');
			role.setAttribute('aria-label', 'Role for the new person');
			var results = el('ul', { class: 'jy-boards-picklist' });
			who.addEventListener('input', debounce(function () {
				clear(results);
				var q = who.value.trim();
				if (q.length < 2) { return; }
				run(api_('boards_members_save', { op: 'search_people', board_id: S.board.id, q: q })).then(function (x) {
					if (!x) { return; }
					x.people.forEach(function (p) {
						results.appendChild(el('li', {}, [el('button', { type: 'button', class: 'jy-boards-pickrow', on: { click: function () {
							run(api_('boards_members_save', { op: 'add_user', board_id: S.board.id, user_id: p.id, role: role.value })).then(function (y) {
								if (y) { applyMembers(y); } drawPeople(box, admin);
							});
						} } }, [avatar(p, 28), el('span', { text: p.name })])]));
					});
				});
			}, 250));
			var groups = el('ul', { class: 'jy-boards-picklist' });
			var findGroups = function () {
				run(api_('boards_members_save', { op: 'search_groups', board_id: S.board.id, q: '' })).then(function (x) {
					if (!x) { return; }
					clear(groups);
					x.groups.forEach(function (g) {
						groups.appendChild(el('li', {}, [el('button', { type: 'button', class: 'jy-boards-pickrow', on: { click: function () {
							run(api_('boards_members_save', { op: 'add_group', board_id: S.board.id, group_id: g.id, role: role.value })).then(function (y) {
								if (y) { applyMembers(y); } drawPeople(box, admin);
							});
						} } }, [el('span', { class: 'jy-boards-avatar jy-boards-avatar--initials', text: 'G' }), el('span', { text: g.name + ' (' + g.members + ')' })])]));
					});
					if (!x.groups.length) { groups.appendChild(el('li', { class: 'jy-boards-muted', text: 'No groups of members yet.' })); }
				});
			};
			append(box, [el('h4', { text: 'Add people' }), el('div', { class: 'jy-boards-inline' }, [who, role]), results,
				el('h4', { text: 'Add a whole group' }), button('Show groups', 'btn-secondary btn-sm', findGroups), groups]);
		});
	}

	/** Take a people-list answer into the board's state. */
	function applyMembers(r) {
		S.people = r.people; S.stamp = r.people_stamp;
		renderChrome(); buildFilterBar(); syncAllCards();
	}

	// ---- archived items -----------------------------------------------------

	function drawArchived(box, r) {
		clear(box);
		var lists = r.lists.filter(function (l) { return l.archived; });
		var cards = r.cards.filter(function (c) { return c.archived; });
		if (!lists.length && !cards.length) { box.appendChild(el('p', { class: 'jy-boards-muted', text: 'Nothing is archived.' })); return; }
		var ul = el('ul', { class: 'jy-boards-picklist' });
		lists.forEach(function (l) {
			ul.appendChild(el('li', { class: 'jy-boards-pickrow' }, [el('span', { text: 'List: ' + l.name }),
				canEdit() ? button('Restore', 'btn-secondary btn-sm', function () {
					run(api_('boards_list_save', { op: 'restore', list_id: l.id })).then(function (x) { if (x) { S.lists = x.lists; syncLists(); drawArchived(box, { lists: r.lists.filter(function (y) { return y.id !== l.id; }), cards: r.cards }); } });
				}) : null]));
		});
		cards.forEach(function (c) {
			ul.appendChild(el('li', { class: 'jy-boards-pickrow' }, [el('span', { text: 'Card: ' + c.title }),
				canEdit() ? button('Restore', 'btn-secondary btn-sm', function () {
					run(api_('boards_card_save', { op: 'restore', card_id: c.id })).then(function (x) {
						if (x) { upsertCard(x.card); syncAllCards(); drawArchived(box, { lists: r.lists, cards: r.cards.filter(function (y) { return y.id !== c.id; }) }); }
					});
				}) : null]));
		});
		box.appendChild(ul);
	}

	// =========================================================================
	// Shortcuts
	// =========================================================================

	var SHORTCUTS = [
		['n', 'New card'], ['f', 'Filter cards'], ['space', 'Add or remove me on the card'],
		['1 – 9', 'Toggle a label'], ['c', 'Archive the card'], ['?', 'Show this list']
	];

	function showShortcuts() {
		var d = openDialog({ title: 'Keyboard shortcuts', cls: 'jy-boards-dialog--small' });
		var dl = el('dl', { class: 'jy-boards-shortcuts' });
		SHORTCUTS.forEach(function (s) { append(dl, [el('dt', {}, [el('kbd', { text: s[0] })]), el('dd', { text: s[1] })]); });
		d.body.appendChild(dl);
		d.foot.appendChild(button('Close', 'btn-primary', d.close));
	}

	/** The card a shortcut acts on: the open one, else the one under the pointer or focus. */
	function targetCardId() { return CB ? CB.id : S.hover; }

	function onShortcut(e) {
		if (e.ctrlKey || e.metaKey || e.altKey) { return; }
		var t = e.target;
		if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) { return; }
		if (dialogStack.length && !CB) { return; }
		if (dialogStack.length > 1) { return; }
		var key = e.key, id;

		if (key === '?') { e.preventDefault(); showShortcuts(); return; }
		if (key === 'f' || key === 'F') { if (CB) { return; } e.preventDefault(); toggleFilterBar(true); return; }
		if (!canEdit()) { return; }

		if (key === 'n' || key === 'N') {
			if (CB) { return; }
			e.preventDefault();
			var listId = S.hover && S.cards[S.hover] ? S.cards[S.hover].list_id : (sortedLists()[0] || {}).id;
			var node = D.listEls[listId];
			if (node) { node._openComposer(); node.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
			return;
		}
		id = targetCardId();
		if (!id || !S.cards[id]) { return; }
		var c = S.cards[id];

		if (key === ' ') {
			e.preventDefault();
			var mine = c.members.indexOf(S.me) !== -1;
			run(api_('boards_card_save', { op: mine ? 'remove_member' : 'add_member', card_id: id, user_id: S.me, detail: !!CB })).then(function (r) {
				if (!r) { return; }
				upsertCard(r.card); syncAllCards();
				if (CB && CB.id === id && r.detail) { CB.detail = r.detail; renderCardBack(); }
				toast(mine ? 'You were removed from the card.' : 'You were added to the card.');
			});
			return;
		}
		if (/^[1-9]$/.test(key)) {
			var label = S.labels[parseInt(key, 10) - 1];
			if (!label) { return; }
			e.preventDefault();
			var has = c.labels.indexOf(label.id) !== -1;
			run(api_('boards_card_save', { op: has ? 'remove_label' : 'add_label', card_id: id, label_id: label.id, detail: !!CB })).then(function (r) {
				if (!r) { return; }
				upsertCard(r.card); syncAllCards();
				if (CB && CB.id === id && r.detail) { CB.detail = r.detail; renderCardBack(); }
			});
			return;
		}
		if (key === 'c' || key === 'C') {
			e.preventDefault();
			run(api_('boards_card_save', { op: 'archive', card_id: id })).then(function (r) {
				if (!r) { return; }
				delete S.cards[id]; syncAllCards(); if (CB && CB.id === id) { closeCard(); }
				toast('Card archived.');
			});
		}
	}

	// =========================================================================
	// Live updates
	// =========================================================================

	var pollLoop = null;
	var pollQueue = [];

	function startPoll() {
		pollLoop = window.joineryPoll.create({
			action: 'boards/boards_poll',
			body: function () {
				if (drag) { return null; }
				return { board_id: S.board.id, after_activity_id: S.cursor, people_stamp: S.stamp };
			},
			interval: function () { return (S.settings && S.settings.poll_ms) || 5000; },
			onData: function (data) {
				pollQueue.push(data);
				flushPoll();
			},
			onError: function (err) {
				if (err && /not found|no longer/i.test(err.message || '')) { showGone(); }
			}
		});
		pollLoop.start();
	}

	/** Apply queued poll answers, unless a card is in the air. */
	function flushPoll() {
		if (drag || !pollQueue.length) { return; }
		while (pollQueue.length) { applyPoll(pollQueue.shift()); }
	}

	function showGone() {
		if (pollLoop) { pollLoop.stop(); }
		closeCard();
		clear(root);
		root.appendChild(el('div', { class: 'jy-boards-gone' }, [
			el('p', { text: 'This board is no longer available.' }),
			el('a', { class: 'btn btn-primary', href: '/profile/boards', text: 'Back to your boards' })]));
	}

	function applyPoll(data) {
		S.cursor = Math.max(S.cursor, data.cursor || 0);
		if (!data.role) { showGone(); return; }

		var chromeBefore = JSON.stringify([S.board, S.role, S.watching, S.people.map(function (p) { return [p.id, p.role]; })]);
		var filterBefore = JSON.stringify([S.labels, S.people.map(function (p) { return p.id; })]);

		var roleChanged = data.role !== S.role;
		S.role = data.role;
		if (data.board) { S.board = data.board; }
		if (data.people) { S.people = data.people; }
		S.stamp = data.people_stamp || S.stamp;
		S.labels = data.labels || S.labels;
		if (data.lists) {
			S.lists = data.lists.map(function (l) {
				if (inflight['list' + l.id]) { var mine = listById(l.id); if (mine) { l.position = mine.position; } }
				return l;
			});
		}
		(data.cards || []).forEach(function (c) { if (!inflight['card' + c.id]) { upsertCard(c); } });
		(data.gone || []).forEach(function (id) {
			if (inflight['card' + id]) { return; }
			delete S.cards[id];
			if (CB && CB.id === id) { toast('This card was archived or removed.'); closeCard(); }
		});

		if (chromeBefore !== JSON.stringify([S.board, S.role, S.watching, S.people.map(function (p) { return [p.id, p.role]; })])) { renderChrome(); }
		if (roleChanged) { refreshAffordances(); }
		if (filterBefore !== JSON.stringify([S.labels, S.people.map(function (p) { return p.id; })]) && !document.activeElement.closest('.jy-boards-filterbar')) { buildFilterBar(); }
		syncLists();
		syncAllCards();
		applyFilters();

		if (CB) {
			var touched = (data.cards || []).some(function (c) { return c.id === CB.id; })
				|| (data.activity || []).some(function (a) { return a.card_id === CB.id; });
			if (touched) { if (cardBackBusy()) { CB.stale = true; } else { refreshCard(); } }
		}
	}

	// =========================================================================
	// Start
	// =========================================================================

	function pageBoard() {
		S = {
			me: boot.user_id, settings: boot.settings || {}, board: boot.board, role: boot.role, lists: boot.lists || [],
			cards: {}, labels: boot.labels || [], people: boot.people || [], stamp: boot.people_stamp || '',
			cursor: boot.cursor || 0, watching: !!boot.watching_board, filters: {}, hover: null
		};
		(boot.cards || []).forEach(upsertCard);
		readFiltersFromUrl();
		buildBoard();
		window.addEventListener('keydown', onShortcut);

		window.addEventListener('popstate', function () {
			var card = parseInt(new URLSearchParams(window.location.search).get('card'), 10);
			if (card && S.cards[card]) { if (!CB || CB.id !== card) { openCard(card, { noPush: true }); } }
			else if (CB) { CB.popping = true; CB.silent = true; CB.handle.close(); }
		});

		if (boot.open_card) {
			openCard(boot.open_card.card.id, { noPush: true, detail: boot.open_card });
		}
		startPoll();
	}

	if (boot.page === 'board') { pageBoard(); }
	else if (boot.page === 'my_cards') { pageMyCards(); }
	else if (boot.page === 'import') { pageImport(); }
	else { pageIndex(); }
})();
