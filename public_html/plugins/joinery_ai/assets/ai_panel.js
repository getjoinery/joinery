/**
 * Area AI panel — the panel an area page (the mail reader now; calendar and
 * drive later) mounts to show the signed-in user what the AI is doing for
 * them there, what it needs them to answer, and which automations are on for
 * the context currently open
 * (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md § Phase 2).
 *
 * Host contract:
 *   JoineryAiPanel.mount({
 *       area: 'mailbox',
 *       getContext: function () { return { mailbox: currentAddress }; },
 *       anchor: headerElement,           // the AI button renders inside it
 *       container: sidebarSlot,          // optional: dock the panel in here
 *       hostSection: element             // optional: the host's own section
 *   });
 *
 * A hostSection is an element the host builds and keeps current itself (the
 * mail reader's "Your AI, your model" for end-to-end encrypted mailboxes); the
 * panel places it after the automations and never looks inside it. It shows
 * and hides itself with its own `hidden`.
 *
 * Given a container, the panel LIVES there — a docked panel in the host's own
 * sidebar, beside whatever else the host keeps there, and the AI button hides
 * itself: the panel is already on the page, with its own header and its own
 * counts. It marks its root data-collapsed while collapsed, data-loading while
 * its first content is on the way, and fires a bubbling 'joinerypanelcontent'
 * event whenever either or what it holds changes, which is all a host column
 * needs to decide its own width and visibility, and to keep what sits below a
 * still-growing panel from being pushed down. A host that hides
 * that container at narrow widths costs nothing: the same panel moves into a
 * slide-over, and the button comes back as the way in, so the surface is never
 * unreachable.
 *
 * The host may dispatch 'joineryareacontextchange' on document whenever its
 * context changes (the reader's rail switching mailboxes); a visible panel
 * refreshes to the new context's state.
 *
 * All facts on a card are server-rendered (ai_panel_state); this file never
 * interprets recipe config. The taint confirm dialog renders the server's own
 * confirm_text and retries the toggle with accept_tainted_writes — binding the
 * context captured at click time, not whatever the rail moved to since.
 *
 * The panel holds the whole AI surface for the area: the automations, one
 * line each — name, an on/off switch for the context open now, and a pencil
 * to the recipe's edit page — with "2 to go" under any that has work in
 * flight; then "Waiting for you", the queued actions it cannot take without an
 * approve or a decline (through ai_action_resolve, the same one execution path
 * the chat's inline cards use); then a pinned footer slot reserved for the
 * future task composer (renders nothing until that feature exists).
 *
 * Both counts are drawn on the panel header and on the AI button, whichever of
 * the two is in view: jobs in flight as a plain number, and actions waiting on
 * the person as the blue circle — one is progress, the other is a request, and
 * they must not read as the same kind of number.
 *
 * Vanilla JS, jy-ui styling, no framework. @version 2.12.0 - one line per recipe
 * (name, switch, pencil) with its progress under it; the Working now list is gone,
 * a run of a recipe not listed here gets a line of its own
 * @version 2.11.0 - Waiting for you has a
 * Current / Past switch (a declined action approved later, a failed one retried);
 * cards show a tool's labelled fields, a link on one line, a note clamped with 'more'
 * @version 2.10.0 - data-loading on the first load,
 * and a refresh keeps the cards (dimmed) until the new ones arrive: no layout shift
 * @version 2.9.0 - hostSection
 */
(function () {
	'use strict';

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text != null) node.textContent = text;
		return node;
	}

	function mount(opts) {
		var area = String(opts.area || '');
		var getContext = typeof opts.getContext === 'function' ? opts.getContext : function () { return {}; };
		var anchor = opts.anchor || null;
		var container = opts.container || null;
		if (!area || (!anchor && !container)) return;

		// ---- the AI button ----
		// It carries both counts, and it is the way in wherever the panel is not
		// docked. Icon and label both present: the kit shows the label on a
		// desktop and the icon alone on a phone (.jy-btn-icon / .jy-btn-label).
		var btn = null, btnJobs = null, btnBadge = null;
		if (anchor) {
			btn = el('button', 'btn btn-secondary aip-open-btn');
			btn.type = 'button';
			btn.setAttribute('aria-haspopup', 'dialog');
			btn.setAttribute('aria-label', 'AI');
			btn.title = 'AI';
			var icon = el('span', 'jy-btn-icon');
			icon.setAttribute('aria-hidden', 'true');
			icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
				+ ' stroke-linecap="round" stroke-linejoin="round">'
				+ '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/>'
				+ '<path d="M19 15.5l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9z"/></svg>';
			btn.appendChild(icon);
			btn.appendChild(el('span', 'jy-btn-label', 'AI'));
			btnJobs = el('span', 'aip-jobs');
			btnJobs.hidden = true;
			btn.appendChild(btnJobs);
			btnBadge = el('span', 'aip-badge');
			btnBadge.hidden = true;
			btn.appendChild(btnBadge);
			anchor.appendChild(btn);
		}

		// ---- the panel ----
		// One element, wherever it lives: docked in the host's sidebar, or inside
		// the slide-over when the host has no room for it.
		var panel = el('section', 'jy-ui aip-panel');
		var head = el('header', 'aip-head');
		var headToggle = el('button', 'aip-head-toggle', '\u25be');
		headToggle.type = 'button';
		headToggle.hidden = true;
		head.appendChild(headToggle);
		head.appendChild(el('h2', 'aip-title', 'AI'));
		var headCounts = el('span', 'aip-head-counts');
		var headJobs = el('span', 'aip-jobs');
		headJobs.hidden = true;
		var headBadge = el('span', 'aip-badge aip-badge-inline');
		headBadge.hidden = true;
		headCounts.appendChild(headJobs);
		headCounts.appendChild(headBadge);
		head.appendChild(headCounts);
		var closeBtn = el('button', 'aip-close', '\u00d7');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close');
		head.appendChild(closeBtn);

		var body = el('div', 'aip-body');
		// The automations first — what is on, and how far along — then what is
		// stopped waiting for the person.
		var recipesBox = el('div', 'aip-recipes');
		var waitingBox = el('div', 'aip-waiting');
		waitingBox.hidden = true;
		body.appendChild(recipesBox);
		body.appendChild(waitingBox);
		if (opts.hostSection && opts.hostSection.nodeType === 1) {
			body.appendChild(opts.hostSection);
		}
		// Pinned slot the future task composer fills; renders nothing today.
		var footer = el('footer', 'aip-composer-slot');
		panel.appendChild(head);
		panel.appendChild(body);
		panel.appendChild(footer);

		// ---- the slide-over, for hosts with nowhere to dock it ----
		var overlay = el('div', 'jy-ui aip-overlay');
		overlay.hidden = true;
		var drawer = el('aside', 'aip-drawer');
		drawer.setAttribute('role', 'dialog');
		drawer.setAttribute('aria-label', 'AI');
		overlay.appendChild(drawer);
		document.body.appendChild(overlay);

		// ---- counts ----
		function setCount(node, count) {
			if (!node) return;
			node.textContent = count > 99 ? '99+' : String(count);
			node.hidden = !(count > 0);
		}
		function setCounts(jobs, pending) {
			var jobsLabel = jobs + (jobs === 1 ? ' job running or queued' : ' jobs running or queued');
			var pendingLabel = pending + (pending === 1 ? ' action waiting for you' : ' actions waiting for you');
			setCount(btnJobs, jobs);
			setCount(headJobs, jobs);
			setCount(btnBadge, pending);
			setCount(headBadge, pending);
			[btnJobs, headJobs].forEach(function (n) { if (n) n.title = jobsLabel; });
			[btnBadge, headBadge].forEach(function (n) { if (n) n.title = pendingLabel; });
			if (btn) {
				btn.setAttribute('aria-label', 'AI \u2014 ' + jobsLabel + ', ' + pendingLabel);
			}
		}

		// ---- where the panel lives ----
		// Dock first, then look: the host's column may be empty-and-hidden until
		// this panel is in it, so asking whether the slot is on screen BEFORE
		// filling it always answers no. Once docked and announced, a slot still
		// not rendering means the host is not showing that column at this width
		// — and the slide-over takes over, without the host having to say so.
		function isDocked() { return !!container && panel.parentNode === container; }
		function isVisible() { return (isDocked() && panel.offsetParent !== null) || !overlay.hidden; }

		var COLLAPSED_KEY = 'joineryAiPanel.collapsed';
		function collapsed() {
			try { return window.localStorage.getItem(COLLAPSED_KEY) === '1'; } catch (e) { return false; }
		}
		function setCollapsed(v) {
			try { window.localStorage.setItem(COLLAPSED_KEY, v ? '1' : '0'); } catch (e) {}
			applyCollapsed();
		}
		function applyCollapsed() {
			var shut = isDocked() && collapsed();
			body.hidden = shut;
			footer.hidden = shut;
			panel.setAttribute('data-collapsed', shut ? 'true' : 'false');
			headToggle.textContent = shut ? '\u25b8' : '\u25be';
			headToggle.title = shut ? 'Show AI' : 'Hide AI';
			headToggle.setAttribute('aria-expanded', shut ? 'false' : 'true');
			announce();
		}

		// The host column decides its own width and visibility from this.
		function announce() {
			panel.dispatchEvent(new CustomEvent('joinerypanelcontent', { bubbles: true }));
		}

		var booted = false;
		function place() {
			if (container) {
				if (panel.parentNode !== container) {
					container.appendChild(panel);
					panel.classList.add('aip-panel-docked');
					closeBtn.hidden = true;
					headToggle.hidden = false;
					applyCollapsed();
				}
				if (panel.offsetParent !== null) {
					overlay.hidden = true;
					// The panel is on the page with its own header and its own
					// counts, so a button that opens it would be a second copy
					// of what is already in front of the person.
					if (btn) btn.hidden = true;
					// A docked panel is on screen without anyone opening it, so
					// this is where its first load happens.
					if (!booted && !collapsed()) { booted = true; refresh(); }
					return;
				}
			}
			// Nowhere to dock: the button is the only way in, and carries the
			// counts that the panel header would have shown.
			if (btn) btn.hidden = false;
			if (panel.parentNode !== drawer) {
				drawer.appendChild(panel);
				if (container) announce();   // the host column just lost its panel
			}
			panel.classList.remove('aip-panel-docked');
			panel.removeAttribute('data-collapsed');
			closeBtn.hidden = false;
			headToggle.hidden = true;
			body.hidden = false;
			footer.hidden = false;
		}

		function open() {
			place();
			if (isDocked()) {
				// Docked and shut: the button is how it comes back.
				if (collapsed()) setCollapsed(false);
				refresh();
				return;
			}
			overlay.hidden = false;
			refresh();
		}
		function close() { overlay.hidden = true; }

		place();
		// The host may not have wired its column up yet (this script can run
		// before the host's own DOMContentLoaded work), and a column that has not
		// heard from us yet is still hidden — which reads exactly like a column
		// this width does not have. So the placement is settled again once the
		// page is up, rather than decided on the first paint alone.
		document.addEventListener('DOMContentLoaded', place);
		window.addEventListener('load', place);
		if (btn) btn.addEventListener('click', open);
		headToggle.addEventListener('click', function () { setCollapsed(!collapsed()); if (!collapsed()) refresh(); });
		closeBtn.addEventListener('click', close);
		overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && !overlay.hidden) close();
		});
		document.addEventListener('joineryareacontextchange', function () {
			if (isVisible()) refresh();
		});
		window.addEventListener('resize', function () { place(); });

		// The counts are wanted whether or not anything is open — they are the
		// reason the button exists. Then a slow heartbeat, because a panel that
		// sits open all day would otherwise still show this morning's queue.
		refreshStatus();
		window.setInterval(function () {
			if (!document.hidden && isVisible()) refreshStatus();
		}, 45000);

		function contextBody(extra) {
			var ctx = getContext() || {};
			var payload = { area: area };
			Object.keys(ctx).forEach(function (k) { payload[k] = ctx[k]; });
			Object.keys(extra || {}).forEach(function (k) { payload[k] = extra[k]; });
			return payload;
		}

		// The first load grows the panel from a line to its whole list. Until it
		// settles the root carries data-loading, so a host column can keep what
		// sits below it out of the layout: the growth then moves nothing else on
		// the page (a layout shift the person sees as the column jumping).
		var settled = false;
		function refresh() {
			var first = !settled;
			if (first) {
				panel.setAttribute('data-loading', 'true');
				announce();
			}
			var both = Promise.all([refreshStatus(), refreshCards()].map(function (p) {
				return Promise.resolve(p).catch(function () {});
			}));
			if (first) {
				both.then(function () {
					settled = true;
					panel.removeAttribute('data-loading');
					announce();
				});
			}
		}

		function refreshCards() {
			var ctx = getContext() || {};
			if (area === 'mailbox' && !ctx.mailbox) {
				recipesBox.classList.remove('aip-stale');
				showCards({ message: 'Select a mailbox to manage AI for it.' });
				return Promise.resolve();
			}
			// A refresh (another mailbox chosen) keeps the rows on screen, dimmed
			// and not clickable, until the new ones replace them: blanking them to
			// a loading line would shrink the panel and grow it back. Only an empty
			// panel says it is loading.
			if (recipesBox.childElementCount) {
				recipesBox.classList.add('aip-stale');
			} else {
				recipesBox.appendChild(el('p', 'aip-quiet', 'Loading\u2026'));
			}
			return joineryApi.post('joinery_ai/ai_panel_state', contextBody())
				.then(function (data) {
					recipesBox.classList.remove('aip-stale');
					var cards = data && data.cards ? data.cards : [];
					showCards(cards.length ? { cards: cards } : { message: 'No AI features for this page yet.' });
				})
				.catch(function (err) {
					recipesBox.classList.remove('aip-stale');
					showCards({ message: err && err.message ? err.message : 'Could not load.' });
				});
		}

		// The rows are drawn from two reads that arrive on their own clocks:
		// the recipes for this context (ai_panel_state) and the runs in flight
		// (ai_status, on the heartbeat). Each keeps its last answer here, and
		// either arriving redraws the list from both.
		var cardsView = null;   // {cards: [...]} or {message: '...'}; null until the first answer
		var lastJobs = [];

		function showCards(view) {
			cardsView = view;
			renderRecipes();
		}

		function setJobs(jobs) {
			lastJobs = jobs;
			if (cardsView) renderRecipes();
		}

		function renderRecipes() {
			var byRecipe = {};
			lastJobs.forEach(function (job) {
				if (job.recipe_id && !byRecipe[job.recipe_id]) byRecipe[job.recipe_id] = job;
			});
			recipesBox.innerHTML = '';
			var listed = {};
			var reasons = [];
			if (cardsView.cards) {
				// No header over them: each row says what it is and whether it
				// is on, and a label above a list that reads as a list is a
				// level of nesting that carries nothing.
				cardsView.cards.forEach(function (card) {
					if (card.recipe_id) listed[card.recipe_id] = true;
					recipesBox.appendChild(renderCard(card, card.recipe_id ? byRecipe[card.recipe_id] : null));
					// Why a switch is disabled, once per distinct reason: on a
					// mailbox where nothing can be turned on, every row has the
					// same one, and saying it three times says nothing more.
					if (card.blocked_reason && !card.covered && card.blocked_text
							&& reasons.indexOf(card.blocked_text) === -1) {
						reasons.push(card.blocked_text);
					}
				});
			} else {
				recipesBox.appendChild(el('p', 'aip-quiet', cardsView.message));
			}
			// A run of a recipe that is not one of this context's (another
			// area's, or a recipe with no area) still counts in the header, so
			// it gets a line too: the number there always has rows to match.
			lastJobs.forEach(function (job) {
				if (job.recipe_id && listed[job.recipe_id]) return;
				var row = el('div', 'aip-recipe');
				var head = el('div', 'aip-recipe-head');
				head.appendChild(el('span', 'aip-recipe-name', job.name || 'A recipe'));
				row.appendChild(head);
				row.appendChild(progressLine(job));
				recipesBox.appendChild(row);
			});
			reasons.forEach(function (text) {
				recipesBox.appendChild(el('p', 'aip-recipe-blocked', text));
			});
			announce();
		}

		// How far along a run is — '2 to go', '4 done, 11 to go' — coloured by
		// its state, with the state itself (running, queued, waiting for the
		// person's unlocked session) on hover.
		function progressLine(job) {
			var line = el('p', 'aip-recipe-progress aip-job-' + (job.state || 'running'),
				job.progress || job.label || '');
			if (job.label) line.title = job.label;
			return line;
		}

		// ---- what the AI is doing, and what it needs answered ----
		// One call for both, so the header counts and the two lists below them
		// can never disagree. Facts are server-rendered from each action's
		// literal arguments; approve/decline go the same ai_action_resolve path
		// the chat's inline cards use.
		function refreshStatus(keepList) {
			return joineryApi.post('joinery_ai/ai_status', {})
				.then(function (data) {
					data = data || {};
					setCounts(data.job_count || 0, data.pending_count || 0);
					setJobs(data.jobs || []);
					if (keepList) {
						lastActions = data.actions || [];
						lastHasPast = !!data.has_past;
						modeSwitch.hidden = false;
					} else {
						renderWaiting(data.actions || [], !!data.has_past);
					}
				})
				.catch(function () {
					waitingBox.hidden = true;
					announce();
				});
		}

		// Waiting for you, with a Current / Past switch once anything has been
		// resolved: Past is where a decline the person regrets can still be
		// approved, and a failure retried, until the action expires. The
		// heartbeat redraws only the Current list; Past is fetched when chosen.
		var waitingMode = 'current';
		var lastActions = [], lastHasPast = false;
		var waitingHead = el('div', 'aip-waiting-head');
		waitingHead.appendChild(el('h3', 'aip-section-title aip-waiting-title', 'Waiting for you'));
		var modeSwitch = el('div', 'aip-switch');
		modeSwitch.setAttribute('role', 'group');
		modeSwitch.setAttribute('aria-label', 'Show');
		var modeBtns = {};
		[['current', 'Current'], ['past', 'Past']].forEach(function (m) {
			var b = el('button', 'aip-switch-btn', m[1]);
			b.type = 'button';
			b.addEventListener('click', function () { setWaitingMode(m[0]); });
			modeBtns[m[0]] = b;
			modeSwitch.appendChild(b);
		});
		modeBtns.current.classList.add('is-on');
		modeBtns.current.setAttribute('aria-pressed', 'true');
		modeBtns.past.setAttribute('aria-pressed', 'false');
		waitingHead.appendChild(modeSwitch);
		var waitingList = el('div', 'aip-waiting-list');
		waitingBox.appendChild(waitingHead);
		waitingBox.appendChild(waitingList);

		function setWaitingMode(mode) {
			waitingMode = mode;
			Object.keys(modeBtns).forEach(function (k) {
				modeBtns[k].classList.toggle('is-on', k === mode);
				modeBtns[k].setAttribute('aria-pressed', k === mode ? 'true' : 'false');
			});
			if (mode === 'past') loadPast(); else renderWaiting(lastActions, lastHasPast);
		}

		function renderWaiting(actions, hasPast) {
			lastActions = actions;
			lastHasPast = !!hasPast;
			modeSwitch.hidden = !lastHasPast && waitingMode === 'current';
			if (!actions.length && !lastHasPast && waitingMode === 'current') {
				waitingBox.hidden = true;
				announce();
				return;
			}
			waitingBox.hidden = false;
			if (waitingMode === 'current') {
				fillList(actions, 'Nothing is waiting for you.');
			}
			announce();
		}

		function loadPast() {
			waitingList.innerHTML = '';
			waitingList.appendChild(el('p', 'aip-quiet', 'Loading…'));
			joineryApi.post('joinery_ai/ai_actions_list', { status: 'past' })
				.then(function (data) {
					if (waitingMode === 'past') fillList((data && data.actions) || [], 'Nothing resolved yet.');
				})
				.catch(function (err) {
					waitingList.innerHTML = '';
					waitingList.appendChild(el('p', 'aip-quiet', err && err.message ? err.message : 'Could not load.'));
				});
		}

		function fillList(actions, emptyText) {
			waitingList.innerHTML = '';
			if (!actions.length) {
				waitingList.appendChild(el('p', 'aip-quiet', emptyText));
			}
			actions.forEach(function (a) { waitingList.appendChild(actionCard(a)); });
			fitClamps(waitingList);
			announce();
		}

		var OUTCOME = { approved: 'Approved', declined: 'Declined', failed: 'Failed', expired: 'Expired' };

		// One queued action. Facts are server-rendered: labelled fields when the
		// tool states them, its plain lines otherwise.
		function actionCard(a) {
			var past = a.status !== 'pending';
			var card = el('section', 'aip-card aip-action-card' + (past ? ' is-past' : ''));
			if (past) {
				var when = resolvedLabel(a.resolved_time);
				card.appendChild(el('p', 'aip-card-outcome aip-outcome-' + a.status,
					(OUTCOME[a.status] || a.status) + (when ? ' · ' + when : '')));
			}
			if (a.locked) {
				card.appendChild(el('p', 'aip-card-status',
					'Sealed to your vault — unlock to view' + (past ? '.' : ' and resolve.')));
				return card;
			}
			if (a.fields) {
				if (a.kicker) card.appendChild(el('p', 'aip-card-kicker', a.kicker));
				card.appendChild(el('p', 'aip-card-name', a.headline || ''));
				var dl = el('dl', 'aip-fields');
				a.fields.forEach(function (f) { dl.appendChild(fieldRow(f)); });
				card.appendChild(dl);
			} else {
				(a.facts || []).forEach(function (line, i) {
					card.appendChild(el('p', i === 0 ? 'aip-card-name' : 'aip-card-fact', line));
				});
			}
			// Which automation asked — one muted tag, so the owner knows
			// what to adjust if these keep coming.
			if (a.source_type === 'recipe' && a.recipe_name) {
				card.appendChild(el('p', 'aip-card-status aip-card-origin', 'via ' + a.recipe_name));
			}
			if (a.model_note) {
				var det = document.createElement('details');
				det.className = 'aip-action-note';
				var sum = document.createElement('summary');
				sum.textContent = 'The assistant’s stated reason';
				det.appendChild(sum);
				var q = document.createElement('blockquote');
				q.textContent = a.model_note;
				det.appendChild(q);
				card.appendChild(det);
			}
			if (past && a.result && (a.status === 'approved' || a.status === 'failed')) {
				card.appendChild(el('p', 'aip-card-result', a.result));
			}
			var row = el('div', 'aip-action-buttons');
			if (!past) {
				var approve = el('button', 'btn btn-primary', 'Approve');
				approve.type = 'button';
				var decline = el('button', 'btn btn-secondary', 'Decline');
				decline.type = 'button';
				approve.addEventListener('click', function () { resolveAction(a.action_id, 'approve', card); });
				decline.addEventListener('click', function () { resolveAction(a.action_id, 'decline', card); });
				row.appendChild(approve);
				row.appendChild(decline);
			} else if (a.can_approve) {
				var again = el('button', 'btn btn-secondary', a.status === 'failed' ? 'Try again' : 'Approve now');
				again.type = 'button';
				again.addEventListener('click', function () { resolveAction(a.action_id, 'approve', card); });
				row.appendChild(again);
			}
			if (row.childElementCount) card.appendChild(row);
			return card;
		}

		// A label beside its value. A 'line' value (a link) stays on one line
		// until clicked — the whole of it is still there to read; a 'clamp'
		// value (a note) shows its first lines and a 'more'.
		function fieldRow(f) {
			var wrap = el('div', 'aip-field');
			wrap.appendChild(el('dt', 'aip-field-label', f.label));
			var dd = el('dd', 'aip-field-value');
			if (f.display === 'line') {
				var line = el('button', 'aip-field-line', f.value);
				line.type = 'button';
				line.title = f.value;
				line.setAttribute('aria-expanded', 'false');
				line.addEventListener('click', function () {
					var open = line.classList.toggle('is-open');
					line.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
				dd.appendChild(line);
			} else if (f.display === 'clamp') {
				var text = el('div', 'aip-field-clamp', f.value);
				var more = el('button', 'aip-link aip-field-more', 'more');
				more.type = 'button';
				more.hidden = true;
				more.addEventListener('click', function () {
					var open = text.classList.toggle('is-open');
					more.textContent = open ? 'less' : 'more';
				});
				dd.appendChild(text);
				dd.appendChild(more);
			} else {
				dd.textContent = f.value;
			}
			wrap.appendChild(dd);
			return wrap;
		}

		// Offer 'more' only on a note that is actually cut. Measured once the
		// card is laid out; a panel not on screen yet (the closed slide-over)
		// has nothing to measure, so there a long note is assumed to be cut.
		function fitClamps(root) {
			window.requestAnimationFrame(function () {
				root.querySelectorAll('.aip-field-clamp').forEach(function (text) {
					var more = text.nextSibling;
					if (!more || text.classList.contains('is-open')) return;
					more.hidden = text.clientHeight > 0
						? text.scrollHeight <= text.clientHeight + 1
						: !(text.textContent.length > 150 || text.textContent.split('\n').length > 3);
				});
			});
		}

		// '3:04 PM' today, 'Sep 28' before that — the browser's own zone.
		function resolvedLabel(utc) {
			if (!utc) return '';
			var d = new Date(String(utc).substring(0, 19).replace(' ', 'T') + 'Z');
			if (isNaN(d.getTime())) return '';
			return d.toDateString() === new Date().toDateString()
				? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
				: d.toLocaleDateString([], { month: 'short', day: 'numeric' });
		}

		function resolveAction(actionId, resolution, card) {
			card.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
			joineryApi.post('joinery_ai/ai_action_resolve',
					{ action_id: actionId, resolution: resolution })
				.then(function (data) {
					var c = data && data.card;
					var text;
					if (c && c.status === 'approved') {
						text = 'Approved — done.' + (c.result ? ' ' + c.result : '');
					} else if (c && c.status === 'failed') {
						text = 'Approved, but it failed: ' + ((c && c.result) || 'unknown error');
					} else {
						text = 'Declined — nothing was run. It stays under Past if you change your mind.';
					}
					card.innerHTML = '';
					card.classList.add('is-resolved');
					card.appendChild(el('p', 'aip-card-status', text));
					// Both counts come from the status read rather than the
					// resolve's own pending_count: approving may have started a
					// job, and the header must not show one number from before
					// the change beside one from after it. The list is left as
					// it is, so the outcome stays readable where it was clicked.
					refreshStatus(true);
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : 'Could not resolve the action.');
					if (waitingMode === 'past') loadPast();
					refreshStatus();
				});
		}

		// One line per recipe: its name, a switch saying whether it is on for
		// the context open right now (and turning it on or off there), and a
		// pencil to its edit page for a viewer who has one. Under it, only while
		// it has work in flight, how far along it is.
		function renderCard(card, job) {
			var row = el('div', 'aip-recipe');
			if (card.covered) row.classList.add('is-on');

			var head = el('div', 'aip-recipe-head');
			head.appendChild(el('span', 'aip-recipe-name', card.name));

			// On = bound to this context AND running automatically. A recipe
			// bound here but set to Manually only shows Off; turning it on puts
			// it on the arrival schedule.
			var sw = el('button', 'aip-toggle' + (card.on ? ' is-on' : ''));
			sw.type = 'button';
			sw.setAttribute('role', 'switch');
			sw.setAttribute('aria-checked', card.on ? 'true' : 'false');
			sw.setAttribute('aria-label', card.name);
			sw.title = card.on ? 'On \u2014 turn off' : 'Off \u2014 turn on';
			// A covered recipe can always be turned off — only turning one ON is
			// ever blocked, and then the reason is on the switch and under the list.
			var blocked = !!card.blocked_reason && !card.covered;
			if (blocked) {
				sw.disabled = true;
				sw.title = card.blocked_text || 'Cannot be turned on here';
			} else {
				sw.addEventListener('click', function () {
					// Capture the context at click time: if the rail moves while
					// the confirm dialog is open, the change still applies to the
					// mailbox it was clicked on.
					var payload = contextBody({ enabled: !card.on });
					if (card.recipe_id) payload.recipe_id = card.recipe_id;
					else payload.template_key = card.template_key;
					sendToggle(payload, sw);
				});
			}
			head.appendChild(sw);

			if (card.dashboard_url) {
				var edit = el('a', 'aip-recipe-edit');
				edit.href = card.dashboard_url;
				edit.title = 'Edit';
				edit.setAttribute('aria-label', 'Edit ' + card.name);
				edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
					+ ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
					+ '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
				head.appendChild(edit);
			}
			row.appendChild(head);

			if (job) row.appendChild(progressLine(job));
			return row;
		}

		function sendToggle(payload, control) {
			control.disabled = true;
			joineryApi.post('joinery_ai/ai_panel_toggle', payload)
				.then(function (data) {
					if (data && data.confirm_required) {
						confirmDialog(data.confirm_text, function (accepted) {
							if (!accepted) { refreshCards(); return; }
							payload.accept_tainted_writes = true;
							sendToggle(payload, control);
						});
						return;
					}
					refreshCards();
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : 'Could not update.');
					refreshCards();
				});
		}

		function confirmDialog(text, done) {
			var dlg = document.createElement('dialog');
			dlg.className = 'jy-ui aip-confirm';
			dlg.appendChild(el('p', 'aip-confirm-text', text));
			var actions = el('div', 'aip-confirm-actions');
			var cancel = el('button', 'btn btn-secondary', 'Cancel');
			cancel.type = 'button';
			var accept = el('button', 'btn btn-primary', 'I accept');
			accept.type = 'button';
			actions.appendChild(cancel);
			actions.appendChild(accept);
			dlg.appendChild(actions);
			document.body.appendChild(dlg);
			function finish(accepted) {
				dlg.close();
				dlg.remove();
				done(accepted);
			}
			cancel.addEventListener('click', function () { finish(false); });
			accept.addEventListener('click', function () { finish(true); });
			dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); finish(false); });
			dlg.showModal();
		}
	}

	window.JoineryAiPanel = { mount: mount };
})();
