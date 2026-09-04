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
 *       container: sidebarSlot           // optional: dock the panel in here
 *   });
 *
 * Given a container, the panel LIVES there — a docked panel in the host's own
 * sidebar, beside whatever else the host keeps there, and the AI button hides
 * itself: the panel is already on the page, with its own header and its own
 * counts. It marks its root data-collapsed while collapsed and fires a bubbling
 * 'joinerypanelcontent' event whenever what it holds changes, which is all a
 * host column needs to decide its own width and visibility. A host that hides
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
 * The panel holds the whole AI surface for the area, in the order the work
 * arrives at the person: what the AI is doing now (in-flight recipe runs),
 * then "Waiting for you" — the queued actions it cannot take without an
 * approve or a decline (through ai_action_resolve, the same one execution path
 * the chat's inline cards use) — then the standing automations whose toggles
 * decide what runs at all, and a pinned footer slot reserved for the future
 * task composer (renders nothing until that feature exists).
 *
 * Both counts are drawn on the panel header and on the AI button, whichever of
 * the two is in view: jobs in flight as a plain number, and actions waiting on
 * the person as the blue circle — one is progress, the other is a request, and
 * they must not read as the same kind of number.
 *
 * Vanilla JS, jy-ui styling, no framework. @version 2.5.0
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
		// The order is the order the work reaches the person: what is moving,
		// what is stopped waiting for them, then what is switched on at all.
		var workingBox = el('div', 'aip-working');
		workingBox.hidden = true;
		var waitingBox = el('div', 'aip-waiting');
		waitingBox.hidden = true;
		var recipesBox = el('div', 'aip-recipes');
		body.appendChild(workingBox);
		body.appendChild(waitingBox);
		body.appendChild(recipesBox);
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

		function refresh() {
			refreshStatus();
			refreshCards();
		}

		function refreshCards() {
			recipesBox.innerHTML = '';
			recipesBox.appendChild(el('p', 'aip-quiet', 'Loading\u2026'));
			var ctx = getContext() || {};
			if (area === 'mailbox' && !ctx.mailbox) {
				recipesBox.innerHTML = '';
				recipesBox.appendChild(el('p', 'aip-quiet', 'Select a mailbox to manage AI for it.'));
				return;
			}
			joineryApi.post('joinery_ai/ai_panel_state', contextBody())
				.then(function (data) { renderCards(data && data.cards ? data.cards : []); })
				.catch(function (err) {
					recipesBox.innerHTML = '';
					recipesBox.appendChild(el('p', 'aip-quiet', err && err.message ? err.message : 'Could not load.'));
				});
		}

		function renderCards(cards) {
			recipesBox.innerHTML = '';
			if (!cards.length) {
				recipesBox.appendChild(el('p', 'aip-quiet', 'No AI features for this page yet.'));
				announce();
				return;
			}
			// No header over them: each row says what it is and whether it is on,
			// and a label above a list that reads as a list is a level of
			// nesting that carries nothing.
			cards.forEach(function (card) { recipesBox.appendChild(renderCard(card)); });
			announce();
		}

		// ---- what the AI is doing, and what it needs answered ----
		// One call for both, so the header counts and the two lists below them
		// can never disagree. Facts are server-rendered from each action's
		// literal arguments; approve/decline go the same ai_action_resolve path
		// the chat's inline cards use.
		function refreshStatus() {
			return joineryApi.post('joinery_ai/ai_status', {})
				.then(function (data) {
					data = data || {};
					setCounts(data.job_count || 0, data.pending_count || 0);
					renderWorking(data.jobs || []);
					renderWaiting(data.actions || []);
				})
				.catch(function () {
					workingBox.hidden = true;
					waitingBox.hidden = true;
					announce();
				});
		}

		// In-flight recipe runs. Each line says which state it is in, because a
		// job that has not started because it is queued and one waiting for the
		// person's own unlocked session are a very different wait.
		function renderWorking(jobs) {
			workingBox.innerHTML = '';
			if (!jobs.length) { workingBox.hidden = true; announce(); return; }
			workingBox.appendChild(el('h3', 'aip-section-title', 'Working now'));
			jobs.forEach(function (job) {
				var row = el('div', 'aip-job aip-job-' + (job.state || 'running'));
				row.appendChild(el('span', 'aip-job-name', job.name || 'A recipe'));
				var state = job.label || '';
				// How far through its queue it is, when the job can say — the
				// answer to 'is it nearly done, or has it barely started'.
				if (job.progress) { state += ' \u00b7 ' + job.progress; }
				row.appendChild(el('span', 'aip-job-state', state));
				workingBox.appendChild(row);
			});
			workingBox.hidden = false;
			announce();
		}

		function renderWaiting(actions) {
			waitingBox.innerHTML = '';
			if (!actions.length) { waitingBox.hidden = true; announce(); return; }
			waitingBox.appendChild(el('h3', 'aip-section-title aip-waiting-title', 'Waiting for you'));
			actions.forEach(function (a) {
				var card = el('section', 'aip-card aip-action-card');
				if (a.locked) {
					card.appendChild(el('p', 'aip-card-status',
						'Sealed to your vault — unlock to view and resolve.'));
					waitingBox.appendChild(card);
					return;
				}
				(a.facts || []).forEach(function (line, i) {
					card.appendChild(el('p', i === 0 ? 'aip-card-name' : 'aip-card-status', line));
				});
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
				var row = el('div', 'aip-action-buttons');
				var approve = el('button', 'btn btn-primary', 'Approve');
				approve.type = 'button';
				var decline = el('button', 'btn btn-secondary', 'Decline');
				decline.type = 'button';
				approve.addEventListener('click', function () { resolveAction(a.action_id, 'approve', card); });
				decline.addEventListener('click', function () { resolveAction(a.action_id, 'decline', card); });
				row.appendChild(approve);
				row.appendChild(decline);
				card.appendChild(row);
				waitingBox.appendChild(card);
			});
			waitingBox.hidden = false;
			announce();
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
						text = 'Declined — nothing was run.';
					}
					card.innerHTML = '';
					card.appendChild(el('p', 'aip-card-status', text));
					// Both counts come from the status read rather than the
					// resolve's own pending_count: approving may have started a
					// job, and the header must not show one number from before
					// the change beside one from after it.
					refreshStatus();
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : 'Could not resolve the action.');
					refreshStatus();
				});
		}

		// One line per recipe: what it is, whether it is on for the context open
		// right now, and the link that changes that. Flat, because a list of five
		// things a person turns on and off does not need a section around it.
		function renderCard(card) {
			var row = el('div', 'aip-recipe');
			if (card.covered) row.classList.add('is-on');

			var head = el('div', 'aip-recipe-head');
			head.appendChild(el('span', 'aip-recipe-name', card.name));
			head.appendChild(el('span', 'aip-recipe-status' + (card.covered ? ' is-on' : ''),
				card.covered ? 'On' : 'Off'));
			row.appendChild(head);

			if (card.job_label) {
				row.appendChild(el('p', 'aip-recipe-job', card.job_label));
			}

			var meta = el('p', 'aip-recipe-meta');
			var bits = [];
			if (card.last_run) bits.push(card.last_run);
			if (card.other_count > 0) {
				bits.push('also on ' + card.other_count + ' other mailbox'
					+ (card.other_count === 1 ? '' : 'es'));
			}
			if (bits.length) {
				meta.textContent = bits.join(' \u00b7 ');
				row.appendChild(meta);
			}

			var blocked = !!card.blocked_reason;
			// A covered recipe can always be turned off — only turning one ON is
			// ever blocked, so the link stays available where it still does
			// something and the reason takes its place where it does not.
			if (blocked && !card.covered) {
				var blockedLine = el('p', 'aip-recipe-blocked', card.blocked_text || '');
				if (card.paused && card.dashboard_url) {
					blockedLine.textContent = '';
					var blockedLink = el('a', null,
						card.blocked_text || 'Set to run manually only — give it a schedule on the recipes dashboard.');
					blockedLink.href = card.dashboard_url;
					blockedLine.appendChild(blockedLink);
				}
				row.appendChild(blockedLine);
				return row;
			}

			var actions = el('p', 'aip-recipe-actions');
			var edit = el('button', 'aip-link', card.covered ? 'Turn off' : 'Turn on');
			edit.type = 'button';
			edit.addEventListener('click', function () {
				// Capture the context at click time: if the rail moves while the
				// confirm dialog is open, the change still applies to the mailbox
				// it was clicked on.
				var payload = contextBody({ enabled: !card.covered });
				if (card.recipe_id) payload.recipe_id = card.recipe_id;
				else payload.template_key = card.template_key;
				sendToggle(payload, edit);
			});
			actions.appendChild(edit);
			if (card.dashboard_url) {
				var link = el('a', 'aip-link', 'Edit');
				link.href = card.dashboard_url;
				actions.appendChild(link);
			}
			row.appendChild(actions);

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
