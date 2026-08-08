/**
 * Area AI panel — the slide-over drawer an area page (the mail reader now;
 * calendar and drive later) mounts to show the signed-in user's AI recipes for
 * that area and toggle each one on or off for the context currently open
 * (specs/ai_recipes_multi_mailbox_and_ai_panel.md § Phase 2).
 *
 * Host contract:
 *   JoineryAiPanel.mount({
 *       area: 'mailbox',
 *       getContext: function () { return { mailbox: currentAddress }; },
 *       anchor: headerElement            // the AI button renders inside it
 *   });
 *
 * The host may dispatch 'joineryareacontextchange' on document whenever its
 *  context changes (the reader's rail switching mailboxes); an open drawer
 * refreshes to the new context's state.
 *
 * All facts on a card are server-rendered (ai_panel_state); this file never
 * interprets recipe config. The taint confirm dialog renders the server's own
 * confirm_text and retries the toggle with accept_tainted_writes — binding the
 * context captured at click time, not whatever the rail moved to since.
 *
 * The drawer holds the whole AI surface for the area: standing automations
 * (recipe cards) above, the "Waiting for you" pending-action list below
 * (approve/decline through ai_action_resolve — the same one execution path
 * the chat's inline cards use), and a pinned footer slot reserved for the
 * future task composer (renders nothing until that feature exists). The AI
 * button carries a pending-actions count badge.
 *
 * Vanilla JS, jy-ui styling, no framework. @version 1.1.0
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
		var anchor = opts.anchor;
		if (!area || !anchor) return;

		// ---- the AI button (with the pending-actions count badge) ----
		var btn = el('button', 'btn btn-secondary aip-open-btn', 'AI');
		btn.type = 'button';
		btn.setAttribute('aria-haspopup', 'dialog');
		var badge = el('span', 'aip-badge');
		badge.hidden = true;
		btn.appendChild(badge);
		anchor.appendChild(btn);

		function setBadge(count) {
			badge.textContent = count > 99 ? '99+' : String(count);
			badge.hidden = !(count > 0);
		}

		// ---- the drawer ----
		var overlay = el('div', 'jy-ui aip-overlay');
		overlay.hidden = true;
		var drawer = el('aside', 'aip-drawer');
		drawer.setAttribute('role', 'dialog');
		drawer.setAttribute('aria-label', 'AI');
		var head = el('header', 'aip-head');
		head.appendChild(el('h2', 'aip-title', 'AI'));
		var closeBtn = el('button', 'aip-close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close');
		head.appendChild(closeBtn);
		var body = el('div', 'aip-body');
		// Standing automations above, pending actions below — the layout the
		// spec fixes so the future composer slot changes no structure.
		var recipesBox = el('div', 'aip-recipes');
		var waitingBox = el('div', 'aip-waiting');
		waitingBox.hidden = true;
		body.appendChild(recipesBox);
		body.appendChild(waitingBox);
		// Pinned slot the future task composer fills; renders nothing today.
		var footer = el('footer', 'aip-composer-slot');
		drawer.appendChild(head);
		drawer.appendChild(body);
		drawer.appendChild(footer);
		overlay.appendChild(drawer);
		document.body.appendChild(overlay);
		refreshBadge();

		function open() { overlay.hidden = false; refresh(); }
		function close() { overlay.hidden = true; }
		btn.addEventListener('click', open);
		closeBtn.addEventListener('click', close);
		overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && !overlay.hidden) close();
		});
		document.addEventListener('joineryareacontextchange', function () {
			if (!overlay.hidden) refresh();
		});

		function contextBody(extra) {
			var ctx = getContext() || {};
			var payload = { area: area };
			Object.keys(ctx).forEach(function (k) { payload[k] = ctx[k]; });
			Object.keys(extra || {}).forEach(function (k) { payload[k] = extra[k]; });
			return payload;
		}

		function refresh() {
			refreshWaiting();
			recipesBox.innerHTML = '';
			recipesBox.appendChild(el('p', 'aip-quiet', 'Loading…'));
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
				return;
			}
			cards.forEach(function (card) { recipesBox.appendChild(renderCard(card)); });
		}

		// ---- Waiting for you: the user's pending queued actions ----
		// Facts are server-rendered from each action's literal arguments; the
		// same ai_action_resolve path the chat's inline cards use.
		function refreshBadge() {
			joineryApi.post('joinery_ai/ai_actions_list', { status: 'pending' })
				.then(function (data) { setBadge((data && data.pending_count) || 0); })
				.catch(function () {});
		}

		function refreshWaiting() {
			joineryApi.post('joinery_ai/ai_actions_list', { status: 'pending' })
				.then(function (data) {
					setBadge((data && data.pending_count) || 0);
					renderWaiting((data && data.actions) || []);
				})
				.catch(function () { waitingBox.hidden = true; });
		}

		function renderWaiting(actions) {
			waitingBox.innerHTML = '';
			if (!actions.length) { waitingBox.hidden = true; return; }
			waitingBox.appendChild(el('h3', 'aip-waiting-title', 'Waiting for you'));
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
					setBadge((data && data.pending_count) || 0);
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : 'Could not resolve the action.');
					refreshWaiting();
				});
		}

		function renderCard(card) {
			var wrap = el('section', 'aip-card');
			var row = el('div', 'aip-card-row');
			var info = el('div', 'aip-card-info');
			info.appendChild(el('h3', 'aip-card-name', card.name));
			info.appendChild(el('p', 'aip-card-status', card.job_label));
			info.appendChild(el('p', 'aip-card-run', card.last_run || ''));
			if (card.other_count > 0) {
				var also = el('p', 'aip-card-also',
					'Also on ' + card.other_count + ' other mailbox' + (card.other_count === 1 ? '' : 'es'));
				info.appendChild(also);
			}
			row.appendChild(info);

			// The toggle: "runs on this mailbox". Disabled when the server says
			// the state is not togglable from here — the reason renders below.
			var toggle = el('label', 'aip-toggle');
			var input = document.createElement('input');
			input.type = 'checkbox';
			input.checked = !!card.covered;
			var blocked = !!card.blocked_reason;
			// A covered recipe can always be unbound — only binding is blocked.
			input.disabled = blocked && !card.covered;
			toggle.appendChild(input);
			toggle.appendChild(el('span', 'aip-toggle-track'));
			row.appendChild(toggle);
			wrap.appendChild(row);

			if (blocked) {
				wrap.classList.add('aip-card-blocked');
				var blockedLine = el('p', 'aip-card-blocked-text', card.blocked_text || '');
				if (card.paused && card.dashboard_url) {
					blockedLine.textContent = '';
					var link = el('a', null, card.blocked_text || 'Paused from the recipes dashboard.');
					link.href = card.dashboard_url;
					blockedLine.appendChild(link);
				}
				wrap.appendChild(blockedLine);
			} else if (card.other_count > 0 && card.dashboard_url) {
				var manage = el('p', 'aip-card-also');
				var mlink = el('a', null, 'Manage on the recipes dashboard');
				mlink.href = card.dashboard_url;
				manage.appendChild(mlink);
				wrap.appendChild(manage);
			}

			input.addEventListener('change', function () {
				// Capture the context at click time: if the rail moves while the
				// confirm dialog is open, the toggle still applies to the mailbox
				// it was clicked on.
				var payload = contextBody({ enabled: input.checked });
				if (card.recipe_id) payload.recipe_id = card.recipe_id;
				else payload.template_key = card.template_key;
				sendToggle(payload, input);
			});

			return wrap;
		}

		function sendToggle(payload, input) {
			input.disabled = true;
			joineryApi.post('joinery_ai/ai_panel_toggle', payload)
				.then(function (data) {
					if (data && data.confirm_required) {
						confirmDialog(data.confirm_text, function (accepted) {
							if (!accepted) { refresh(); return; }
							payload.accept_tainted_writes = true;
							sendToggle(payload, input);
						});
						return;
					}
					refresh();
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : 'Could not update.');
					refresh();
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
