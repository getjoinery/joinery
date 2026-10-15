/**
 * The Help panel: ask the support desk from any admin page or setup step
 * (support_chatbot.md, The site side).
 *
 * Mounts on every [data-support-help] button the page carries (the admin
 * header's Help, a setup step's "Ask for help"). The button's data-route is
 * the page or step the question is asked from. The panel is a slide-over on
 * the right; it never talks to the desk itself — every call goes to this
 * site's support_* actions through window.joineryApi, and this site's server
 * sends it on.
 *
 * Views: the consent question (what the first message would send, section by
 * section, with what each grants, asked once for the site); the thread list
 * with a composer for a new question; one thread, its transcript drawn by the
 * core transcript (server-rendered bubbles, the running turn by
 * window.JoineryChatTranscript), Helpful / Not helpful under each answer,
 * Talk to a person, and Report this as a bug; and "support is not offered
 * here" with the public documentation.
 *
 * The unread mark on the button is server-rendered; while the panel has been
 * used in the last week, the button refreshes it once in a while from
 * support_threads.
 *
 * Vanilla JS, no framework. @version 1.1.0
 */
(function () {
	'use strict';

	var api = function (action, body) { return window.joineryApi.post(action, body || {}); };
	var CHECK_EVERY_MS = 10 * 60 * 1000;

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	var panel = null;      // the slide-over root
	var state = null;      // support_state answer
	var route = '';
	var current = 0;       // local thread id open now
	var running = null;    // {threadId, messageId}
	var buttons = [];

	function setBadge(n) {
		buttons.forEach(function (b) {
			var badge = b.querySelector('.jy-support-badge');
			if (!badge) return;
			badge.textContent = n > 0 ? String(n) : '';
			badge.hidden = !(n > 0);
		});
	}

	function build() {
		panel = el('aside', 'jy-support-panel');
		panel.setAttribute('aria-label', 'Help');
		panel.hidden = true;
		var head = el('div', 'jy-support-head');
		var title = el('div', 'jy-support-title', 'Help');
		var sub = el('div', 'jy-support-sub', '');
		var close = el('button', 'jy-support-close', '×');
		close.type = 'button';
		close.setAttribute('aria-label', 'Close help');
		close.addEventListener('click', hide);
		var titles = el('div');
		titles.appendChild(title);
		titles.appendChild(sub);
		head.appendChild(titles);
		head.appendChild(close);
		panel.appendChild(head);
		panel.appendChild(el('div', 'jy-support-body'));
		document.body.appendChild(panel);
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && panel && !panel.hidden) hide();
		});
	}

	function body() { return panel.querySelector('.jy-support-body'); }
	function sub(text) { panel.querySelector('.jy-support-sub').textContent = text || ''; }

	function show(fromRoute) {
		if (!panel) build();
		route = fromRoute || '';
		panel.hidden = false;
		document.body.classList.add('jy-support-open');
		loading();
		api('support_state', { route: route }).then(function (s) {
			state = s;
			sub('Answers from ' + s.destination);
			setBadge(s.unread || 0);
			if (!s.consent.answered) { consentView(); return; }
			if (current) { openThread(current); return; }
			listView();
		}).catch(failView);
	}

	function hide() {
		if (!panel) return;
		panel.hidden = true;
		document.body.classList.remove('jy-support-open');
	}

	function clear() {
		var b = body();
		while (b.firstChild) b.removeChild(b.firstChild);
		return b;
	}

	function loading() { clear().appendChild(el('p', 'jy-support-muted', 'Loading…')); }

	function notice(text, kind) {
		return el('div', 'jy-support-notice' + (kind ? ' is-' + kind : ''), text);
	}

	function failView(err) {
		var reason = err && err.data ? err.data.reason : '';
		var b = clear();
		if (reason === 'not_offered') {
			b.appendChild(notice(err.message));
		} else {
			b.appendChild(notice((err && err.message) || 'The Help panel could not load.', 'warn'));
		}
		var docs = el('a', 'jy-support-link', 'Read the documentation');
		docs.href = (state && state.docs_url) || 'https://getjoinery.com/docs';
		docs.target = '_blank';
		docs.rel = 'noopener';
		b.appendChild(docs);
	}

	// ---------------------------------------------------------------- consent

	function consentView() {
		var b = clear();
		b.appendChild(el('p', null, 'Before your first question: answers are better when the support desk at ' + state.destination
			+ ' knows about this site. This is everything the first message of a thread would send, exactly as it would be sent. '
			+ 'You are asked once for the site; it can be changed on the Management Node page.'));
		(state.preview || []).forEach(function (s) {
			var d = el('details', 'jy-support-section');
			var sum = el('summary', null, s.heading);
			d.appendChild(sum);
			if (s.grants) d.appendChild(el('p', 'jy-support-muted', s.grants));
			d.appendChild(el('pre', 'jy-support-pre', s.text));
			b.appendChild(d);
		});
		var live = null;
		if (state.may_live_reads) {
			var label = el('label', 'jy-support-check');
			live = el('input');
			live.type = 'checkbox';
			label.appendChild(live);
			label.appendChild(document.createTextNode(' Also allow live reads: ' + state.live_reads_text));
			b.appendChild(label);
		}
		var row = el('div', 'jy-support-actions');
		var yes = el('button', 'jy-support-btn is-primary', 'Send these facts with my questions');
		var no = el('button', 'jy-support-btn', 'Ask without them');
		yes.type = no.type = 'button';
		yes.addEventListener('click', function () { answer('yes', live && live.checked ? 'yes' : 'no'); });
		no.addEventListener('click', function () { answer('no', 'no'); });
		row.appendChild(yes);
		row.appendChild(no);
		b.appendChild(row);
	}

	function answer(bundle, liveReads) {
		loading();
		api('support_consent', { bundle: bundle, live_reads: liveReads }).then(function (r) {
			state.consent = r.consent;
			listView();
		}).catch(failView);
	}

	// ------------------------------------------------------------------- list

	function listView() {
		current = 0;
		var b = clear();
		b.appendChild(composer(0));
		var list = el('div', 'jy-support-list');
		list.appendChild(el('p', 'jy-support-muted', 'Loading your past questions…'));
		b.appendChild(list);
		api('support_threads', {}).then(function (r) {
			setBadge(r.unread || 0);
			list.textContent = '';
			if (!r.threads.length) {
				list.appendChild(el('p', 'jy-support-muted', 'No questions yet from this site.'));
				return;
			}
			list.appendChild(el('div', 'jy-support-list-head', 'Past questions'));
			r.threads.forEach(function (t) {
				var item = el('button', 'jy-support-item' + (t.unread ? ' is-unread' : ''));
				item.type = 'button';
				item.appendChild(el('span', 'jy-support-item-title', t.title || 'Question'));
				item.appendChild(el('span', 'jy-support-item-state', stateLabel(t.state) + (t.unread ? ' · new reply' : '')));
				item.addEventListener('click', function () { openThread(t.thread_id); });
				list.appendChild(item);
			});
		}).catch(function (err) {
			if (err && err.data && err.data.reason === 'not_offered') { failView(err); return; }
			list.textContent = '';
			list.appendChild(notice(err.message || 'Past questions could not be loaded.', 'warn'));
		});
	}

	function stateLabel(s) {
		return {
			open: 'Open', waiting_on_person: 'Waiting for a person', answered_by_person: 'A person answered', closed: 'Closed'
		}[s] || '';
	}

	// ----------------------------------------------------------------- thread

	function openThread(id) {
		current = id;
		loading();
		api('support_threads', { thread_id: id }).then(function (r) {
			setBadge(r.unread || 0);
			threadView(r.thread, r.html || '');
		}).catch(failView);
	}

	function threadView(thread, html) {
		var b = clear();
		var back = el('button', 'jy-support-back', '← All questions');
		back.type = 'button';
		back.addEventListener('click', listView);
		b.appendChild(back);
		var t = el('div', 'joai-chat-transcript jy-support-transcript');
		t.innerHTML = html;
		b.appendChild(t);
		wireThumbs(t);
		var st = el('div', 'jy-support-state');
		b.appendChild(st);
		showState(thread.state);
		b.appendChild(composer(thread.thread_id));
		var more = el('div', 'jy-support-actions');
		var person = el('button', 'jy-support-btn', 'Talk to a person');
		person.type = 'button';
		person.addEventListener('click', function () {
			person.disabled = true;
			api('support_escalate', { thread_id: thread.thread_id }).then(function () {
				showState('waiting_on_person');
			}).catch(function (err) { person.disabled = false; inlineError(err); });
		});
		var bug = el('a', 'jy-support-btn', 'Report this as a bug');
		bug.href = '/report_problem?from=' + encodeURIComponent(route.indexOf('setup:') === 0 ? '/setup' : (route || '/admin'))
			+ '&msg=' + encodeURIComponent('From support thread #' + thread.remote_id + ' at ' + thread.desk
				+ ' ("' + (thread.title || '') + '")');
		more.appendChild(person);
		more.appendChild(bug);
		b.appendChild(more);
		t.scrollTop = t.scrollHeight;
	}

	function showState(s) {
		var st = panel.querySelector('.jy-support-state');
		if (!st) return;
		st.textContent = s === 'waiting_on_person'
			? 'This thread is waiting for a person at the support desk. Their reply appears here, and the Help button shows a mark when it arrives.'
			: (s === 'answered_by_person' ? 'A person at the support desk answered. Your next question goes back to the assistant.' : '');
		st.hidden = !st.textContent;
	}

	function wireThumbs(container) {
		container.querySelectorAll('.jy-support-thumbs').forEach(function (box) {
			box.querySelectorAll('.jy-support-thumb').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var verdict = btn.getAttribute('data-verdict');
					var note = verdict === 'down' ? (window.prompt('What was missing or wrong? (optional)') || '') : '';
					api('support_feedback', { thread_id: current, message_id: parseInt(box.getAttribute('data-message-id'), 10),
						verdict: verdict, note: note }).then(function () {
						box.querySelectorAll('.jy-support-thumb').forEach(function (o) { o.classList.remove('is-chosen'); });
						btn.classList.add('is-chosen');
					}).catch(inlineError);
				});
			});
		});
	}

	function inlineError(err) {
		var b = body();
		var n = notice((err && err.message) || 'That did not go through.', 'warn');
		b.insertBefore(n, b.querySelector('.jy-support-composer'));
		setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 12000);
	}

	// --------------------------------------------------------------- composer

	function composer(threadId) {
		var box = el('div', 'jy-support-composer');
		var ta = el('textarea', 'jy-support-input');
		ta.rows = 3;
		ta.maxLength = 8000;
		ta.placeholder = threadId ? 'Ask a follow-up…' : 'Ask how to do something, or what is wrong…';
		var send = el('button', 'jy-support-btn is-primary', 'Ask');
		send.type = 'button';
		box.appendChild(ta);
		box.appendChild(send);
		send.addEventListener('click', function () {
			if (running) { cancel(send); return; }
			var text = ta.value.trim();
			if (!text) return;
			ask(threadId, text, ta, send);
		});
		ta.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send.click(); }
		});
		return box;
	}

	function ask(threadId, text, ta, send) {
		send.disabled = true;
		api('support_send', { thread_id: threadId, message: text, route: route }).then(function (r) {
			ta.value = '';
			if (!threadId) {
				// A new thread: open it, and stream the answer there.
				current = r.thread_id;
				api('support_threads', { thread_id: r.thread_id }).then(function (t) {
					threadView(t.thread, t.html || '');
					follow(r);
				}).catch(failView);
				return;
			}
			var transcript = panel.querySelector('.jy-support-transcript');
			var mine = el('div', 'joai-chat-msg joai-chat-mine');
			mine.appendChild(el('div', 'joai-chat-body', text));
			transcript.appendChild(mine);
			send.disabled = false;
			follow(r);
		}).catch(function (err) {
			send.disabled = false;
			if (err && err.data && err.data.reason === 'not_offered') { failView(err); return; }
			inlineError(err);
		});
	}

	/** Stream a running answer into the thread, or show that it waits on a person. */
	function follow(r) {
		showState(r.state);
		if (r.status !== 'running' || !r.message_id) return;
		var transcript = panel.querySelector('.jy-support-transcript');
		var send = panel.querySelector('.jy-support-composer .jy-support-btn');
		running = { threadId: current, messageId: r.message_id };
		if (send) { send.disabled = false; send.textContent = 'Cancel'; }
		var bubble = JoineryChatTranscript.liveBubble(transcript, r.message_id);
		transcript.scrollTop = transcript.scrollHeight;
		var done = function () {
			running = null;
			if (send) { send.disabled = false; send.textContent = 'Ask'; }
		};
		JoineryChatTranscript.poll({
			interval: 900,
			fetch: function () { return api('support_poll', { thread_id: current, message_id: r.message_id }); },
			onData: function (d) {
				if (d.status === 'running') {
					JoineryChatTranscript.showPartial(bubble, d.partial_text || '', d);
					transcript.scrollTop = transcript.scrollHeight;
					return true;
				}
				if (d.status === 'failed') {
					JoineryChatTranscript.failedBubble(transcript, r.message_id, d.error || 'No answer was written.', null);
				} else if (d.html) {
					var holder = document.createElement('div');
					holder.innerHTML = d.html;
					var fresh = holder.firstElementChild;
					if (fresh) { bubble.parentNode.replaceChild(fresh, bubble); wireThumbs(transcript); }
				}
				showState(d.state);
				transcript.scrollTop = transcript.scrollHeight;
				done();
				return false;
			},
			onGiveUp: function (msg) {
				JoineryChatTranscript.failedBubble(transcript, r.message_id, msg, null);
				done();
			}
		});
	}

	function cancel(send) {
		if (!running) return;
		send.disabled = true;
		api('support_cancel', { thread_id: running.threadId, message_id: running.messageId }).catch(function () { send.disabled = false; });
	}

	// ----------------------------------------------------------------- mount

	function refreshUnread() {
		var last = 0;
		// One check for the whole browser, not one per open tab.
		try { last = parseInt(window.localStorage.getItem('jySupportChecked') || '0', 10); } catch (e) {}
		if (Date.now() - last < CHECK_EVERY_MS) return;
		try { window.localStorage.setItem('jySupportChecked', String(Date.now())); } catch (e) {}
		api('support_threads', {}).then(function (r) { setBadge(r.unread || 0); }).catch(function () {});
	}

	function mount() {
		buttons = Array.prototype.slice.call(document.querySelectorAll('[data-support-help]'));
		buttons.forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.preventDefault();
				show(b.getAttribute('data-route') || window.location.pathname);
			});
		});
		if (buttons.some(function (b) { return b.getAttribute('data-support-check') === '1'; })) refreshUnread();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
	else mount();
})();
