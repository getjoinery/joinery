/**
 * Forum — the page behaviour of /forum: writing, replying, quoting, editing,
 * liking, reporting, watching and moderating, each a call to a forum API action
 * over the browser session (window.joineryApi). No inline script; every hook is
 * a data-forum-* attribute the pages print.
 *
 * Pages change nothing on load except one thing a topic page reports after it
 * is shown: how far the member has read (forum_mark_read).
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var root = document.querySelector('.forum[data-forum-page]');
	if (!root || !window.joineryApi) return;

	var api = function (action, body) { return window.joineryApi.post('forum/' + action, body || {}); };
	var pageKind = root.getAttribute('data-forum-page');
	var topicId = parseInt(root.getAttribute('data-topic-id') || '0', 10);

	function problem(err) {
		var msg = (err && err.message) || 'Something went wrong. Try again.';
		if (window.JoineryModal) { window.JoineryModal.alert(msg); } else { window.alert(msg); }
	}

	function confirmThen(message, label, fn) {
		if (window.JoineryModal) {
			window.JoineryModal.confirm(message, fn, { confirmLabel: label });
		} else if (window.confirm(message)) {
			fn();
		}
	}

	function go(url) {
		if (!url) { window.location.reload(); return; }
		var here = window.location.pathname + window.location.search;
		var target = url.split('#')[0];
		window.location.href = url;
		if (target === here) window.location.reload();
	}

	/* ------------------------------------------------------------ composers */

	function composerParts(wrap) {
		return {
			wrap: wrap,
			form: wrap.querySelector('form'),
			title: wrap.querySelector('input[name="title"]'),
			body: wrap.querySelector('textarea[name="body"]'),
			files: wrap.querySelector('input[type="file"]'),
			list: wrap.querySelector('.forum-attach-list'),
			error: wrap.querySelector('.forum-composer-error'),
			replyTo: wrap.querySelector('.forum-reply-to'),
			submit: wrap.querySelector('[type="submit"]'),
			mode: wrap.getAttribute('data-forum-mode'),
			categoryId: parseInt(wrap.getAttribute('data-category-id') || '0', 10),
			attachments: [],
			replyToId: null,
			editPostId: null
		};
	}

	function showError(c, message) {
		if (!c.error) { problem({ message: message }); return; }
		c.error.textContent = message;
		c.error.hidden = !message;
	}

	function busy(c, on) {
		if (c.submit) c.submit.disabled = !!on;
	}

	function addAttachment(c, file) {
		var li = document.createElement('li');
		li.textContent = file.name + ' ';
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'btn btn-sm btn-link';
		remove.textContent = 'Remove';
		remove.addEventListener('click', function () {
			c.attachments = c.attachments.filter(function (id) { return id !== file.file_id; });
			li.remove();
		});
		li.appendChild(remove);
		c.list.appendChild(li);
		c.attachments.push(file.file_id);
	}

	function uploadFiles(c) {
		var chosen = Array.prototype.slice.call(c.files.files || []);
		c.files.value = '';
		chosen.forEach(function (file) {
			var placeholder = document.createElement('li');
			placeholder.textContent = 'Uploading ' + file.name + '…';
			c.list.appendChild(placeholder);
			var fd = new FormData();
			fd.append('file', file);
			fd.append('category_id', String(c.categoryId));
			busy(c, true);
			window.joineryApi.postForm('forum/forum_upload', fd).then(function (data) {
				placeholder.remove();
				addAttachment(c, { file_id: data.file_id, name: data.name || file.name });
			}).catch(function (err) {
				placeholder.remove();
				showError(c, (err && err.message) || 'That file could not be attached.');
			}).then(function () { busy(c, false); });
		});
	}

	function setReplyTo(c, postId, author) {
		c.replyToId = postId;
		if (!c.replyTo) return;
		if (postId) {
			c.replyTo.querySelector('span').textContent = 'Replying to ' + author;
			c.replyTo.hidden = false;
		} else {
			c.replyTo.hidden = true;
		}
	}

	function submitComposer(c) {
		showError(c, '');
		var body = c.body ? c.body.value : '';
		var call;
		if (c.mode === 'topic') {
			call = api('forum_topic_create', {
				category_id: c.categoryId,
				title: c.title ? c.title.value : '',
				body: body,
				attachments: c.attachments
			});
		} else if (c.mode === 'reply') {
			call = api('forum_post_create', {
				topic_id: topicId,
				body: body,
				reply_to_id: c.replyToId || undefined,
				attachments: c.attachments
			});
		} else {
			var payload = { post_id: c.editPostId, body: body };
			if (c.title && !c.title.closest('.form-group').hidden) payload.title = c.title.value;
			call = api('forum_post_edit', payload);
		}
		busy(c, true);
		call.then(function (data) {
			if (data && data.held) {
				window.JoineryModal.alert('Thanks. New members\' first posts are checked by a moderator before others can see them; yours will appear once it is approved.');
				window.setTimeout(function () { go(data.url); }, 50);
				return;
			}
			go(data && data.url);
		}).catch(function (err) {
			busy(c, false);
			showError(c, (err && err.message) || 'That did not save. Your text is still here.');
		});
	}

	var composers = {};
	root.querySelectorAll('.forum-composer').forEach(function (wrap) {
		var c = composerParts(wrap);
		composers[c.mode] = c;
		if (c.form) {
			c.form.addEventListener('submit', function (event) {
				event.preventDefault();
				submitComposer(c);
			});
		}
		if (c.files) c.files.addEventListener('change', function () { uploadFiles(c); });
		var clear = wrap.querySelector('[data-forum-clear-reply]');
		if (clear) clear.addEventListener('click', function () { setReplyTo(c, null, ''); });
		var cancel = wrap.querySelector('[data-forum-cancel]');
		if (cancel) cancel.addEventListener('click', function () {
			if (c.mode === 'edit') { closeEdit(c); } else { wrap.hidden = true; }
		});
	});

	root.querySelectorAll('[data-forum-open]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var wrap = document.getElementById(btn.getAttribute('data-forum-open'));
			if (!wrap) return;
			wrap.hidden = false;
			var first = wrap.querySelector('input[name="title"], textarea');
			if (first) first.focus();
		});
	});

	/* ------------------------------------------------------------ posts */

	function postOf(el) { return el.closest('.forum-post'); }
	function postId(el) { return parseInt(postOf(el).getAttribute('data-post-id'), 10); }

	function focusReply(c) {
		var section = document.getElementById('reply');
		if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
		if (c.body) window.setTimeout(function () { c.body.focus(); }, 250);
	}

	function startReply(btn, quote) {
		var c = composers.reply;
		if (!c) return;
		var post = postOf(btn);
		var id = postId(btn);
		setReplyTo(c, id, post.getAttribute('data-author'));
		if (!quote) { focusReply(c); return; }
		api('forum_post_source', { post_id: id }).then(function (data) {
			var quoted = String(data.body || '').split('\n').map(function (line) { return '> ' + line; }).join('\n');
			var lead = c.body.value && !/\n$/.test(c.body.value) ? '\n\n' : '';
			c.body.value += lead + '> **' + (data.author || '') + ' wrote:**\n' + quoted + '\n\n';
			c.body.dispatchEvent(new Event('input', { bubbles: true }));
			focusReply(c);
		}).catch(problem);
	}

	function closeEdit(c) {
		var post = c.wrap.parentNode && c.wrap.parentNode.closest ? c.wrap.parentNode.closest('.forum-post') : null;
		if (post) {
			var body = post.querySelector('.forum-post-body');
			if (body) body.hidden = false;
		}
		c.wrap.hidden = true;
		root.appendChild(c.wrap);
		c.editPostId = null;
	}

	function startEdit(btn) {
		var c = composers.edit;
		if (!c) return;
		if (c.editPostId) closeEdit(c);
		var post = postOf(btn);
		var id = postId(btn);
		api('forum_post_source', { post_id: id }).then(function (data) {
			c.editPostId = id;
			c.body.value = data.body || '';
			c.body.dispatchEvent(new Event('input', { bubbles: true }));
			var titleGroup = c.title ? c.title.closest('.form-group') : null;
			if (titleGroup) {
				titleGroup.hidden = !data.is_first;
				c.title.value = data.title || '';
			}
			var body = post.querySelector('.forum-post-body');
			body.hidden = true;
			body.parentNode.insertBefore(c.wrap, body.nextSibling);
			c.wrap.hidden = false;
			c.body.focus();
		}).catch(problem);
	}

	function toggleLike(btn) {
		btn.disabled = true;
		api('forum_like', { post_id: postId(btn) }).then(function (data) {
			btn.setAttribute('aria-pressed', data.liked ? 'true' : 'false');
			btn.firstChild.textContent = data.liked ? 'Liked' : 'Like';
			var count = btn.querySelector('.forum-like-count');
			if (count) count.textContent = data.count ? ' · ' + data.count : '';
		}).catch(problem).then(function () { btn.disabled = false; });
	}

	function removePost(btn) {
		var first = postOf(btn).hasAttribute('data-first');
		confirmThen(first ? 'Remove this topic and every reply in it?' : 'Remove this post?', 'Remove', function () {
			api('forum_post_delete', { post_id: postId(btn) }).then(function (data) { go(data.url); }).catch(problem);
		});
	}

	function report(btn) {
		var id = postId(btn);
		var box = document.createElement('div');
		box.className = 'forum-report-box';
		box.innerHTML = '<p>Tell the moderators what is wrong with this post.</p>';
		var reason = document.createElement('select');
		reason.className = 'form-control';
		reason.setAttribute('aria-label', 'Reason');
		[['spam', 'Spam or advertising'], ['abuse', 'Abusive or harassing'], ['off_topic', 'Off topic'], ['other', 'Something else']]
			.forEach(function (o) {
				var opt = document.createElement('option');
				opt.value = o[0];
				opt.textContent = o[1];
				reason.appendChild(opt);
			});
		var note = document.createElement('textarea');
		note.className = 'form-control';
		note.rows = 3;
		note.maxLength = 1000;
		note.placeholder = 'Anything they should know (optional)';
		note.setAttribute('aria-label', 'Note');
		box.appendChild(reason);
		box.appendChild(note);
		window.JoineryModal.open(box, { buttons: [
			{ label: 'Cancel', style: 'secondary' },
			{ label: 'Send report', style: 'primary', onClick: function () {
				window.joineryApi.post('content_report', {
					entity_type: 'forum_post', entity_id: id, reason: reason.value, note: note.value
				}).then(function () {
					btn.textContent = 'Reported';
					btn.disabled = true;
				}).catch(problem);
			} }
		] });
	}

	function moderatePost(btn) {
		var op = btn.getAttribute('data-forum-moderate');
		var run = function () {
			api('forum_post_moderate', { post_id: postId(btn), op: op }).then(function () { go(null); }).catch(problem);
		};
		if (op === 'reject') {
			confirmThen('Turn this post down? It will be removed.', 'Turn down', run);
		} else {
			run();
		}
	}

	root.addEventListener('click', function (event) {
		var t = event.target.closest('button');
		if (!t || !root.contains(t)) return;
		if (t.hasAttribute('data-forum-reply')) { startReply(t, false); }
		else if (t.hasAttribute('data-forum-quote')) { startReply(t, true); }
		else if (t.hasAttribute('data-forum-edit')) { startEdit(t); }
		else if (t.hasAttribute('data-forum-like')) { toggleLike(t); }
		else if (t.hasAttribute('data-forum-delete')) { removePost(t); }
		else if (t.hasAttribute('data-forum-report')) { report(t); }
		else if (t.hasAttribute('data-forum-moderate')) { moderatePost(t); }
		else if (t.hasAttribute('data-forum-topic-op')) { topicOp(t.getAttribute('data-forum-topic-op')); }
		else if (t.hasAttribute('data-forum-watch')) { watchCategory(t); }
	});

	/* ------------------------------------------------------------ topic tools */

	function topicOp(op, extra) {
		var run = function () {
			var body = Object.assign({ topic_id: topicId, op: op }, extra || {});
			api('forum_topic_set', body).then(function (data) { go(data.url); }).catch(problem);
		};
		if (op === 'delete') {
			confirmThen('Remove this topic and every reply in it?', 'Remove topic', run);
		} else {
			run();
		}
	}

	var move = root.querySelector('[data-forum-move]');
	if (move) {
		var original = move.value;
		move.addEventListener('change', function () {
			var label = move.options[move.selectedIndex].textContent;
			confirmThen('Move this topic to ' + label + '?', 'Move', function () {
				topicOp('move', { category_id: parseInt(move.value, 10) });
			});
			move.value = original;
		});
	}

	root.querySelectorAll('[data-forum-watch-select]').forEach(function (select) {
		select.addEventListener('change', function () {
			select.disabled = true;
			api('forum_watch_set', {
				target: select.getAttribute('data-forum-watch-select'),
				id: parseInt(select.getAttribute('data-id'), 10),
				level: select.value
			}).catch(problem).then(function () { select.disabled = false; });
		});
	});

	function watchCategory(btn) {
		var watching = btn.getAttribute('data-level') === 'watching';
		btn.disabled = true;
		api('forum_watch_set', {
			target: 'category',
			id: parseInt(btn.getAttribute('data-id'), 10),
			level: watching ? 'none' : 'watching'
		}).then(function (data) {
			var now = data.level === 'watching';
			btn.setAttribute('data-level', data.level);
			btn.setAttribute('aria-pressed', now ? 'true' : 'false');
			btn.textContent = now ? 'Watching new topics' : 'Watch new topics';
		}).catch(problem).then(function () { btn.disabled = false; });
	}

	/* ------------------------------------------------------------ on load */

	if (pageKind === 'topic') {
		var scrollTo = root.getAttribute('data-scroll-to');
		var hash = window.location.hash;
		var target = hash && hash.indexOf('#post-') === 0 ? document.getElementById(hash.slice(1))
			: (scrollTo ? document.getElementById('post-' + scrollTo) : null);
		if (target) {
			target.classList.add('is-target');
			if (!hash) target.scrollIntoView({ block: 'start' });
		}

		var params = new URLSearchParams(window.location.search);
		var replyTo = parseInt(params.get('reply_to') || '0', 10);
		if (replyTo && composers.reply) {
			var post = document.getElementById('post-' + replyTo);
			setReplyTo(composers.reply, replyTo, post ? post.getAttribute('data-author') : 'that post');
			focusReply(composers.reply);
		}

		var last = parseInt(root.getAttribute('data-last-post-id') || '0', 10);
		if (root.getAttribute('data-signed-in') === '1' && topicId && last) {
			api('forum_mark_read', { topic_id: topicId, post_id: last }).catch(function () {});
		}
	}
})();
