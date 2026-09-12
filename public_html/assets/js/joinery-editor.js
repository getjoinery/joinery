/**
 * Joinery editor — the platform's one editing surface for HTML and markdown.
 *
 * Enhances every `.jy-ed[data-jy-editor]` wrapper FormWriter emits around a
 * textarea. The wrapper's dialect decides what the buttons do:
 *
 *   html      a contenteditable surface over the textarea; buttons run the
 *             browser's own editing commands; views: visual, source.
 *   markdown  the textarea is the surface; buttons rewrite the selected text;
 *             views: write, split, preview (rendered by the server through the
 *             markdown_preview action, so nothing drifts from MarkdownRenderer).
 *
 * The textarea is the field, always. In the html dialect the surface writes to
 * it on every input and after every command, and NEVER before the first edit:
 * open, look, Save is byte-identical to what was loaded. Cleanup (assets/js/
 * html-cleanup.js) runs only when the author presses the button or the field
 * was declared prose-only with `editor_cleanup: always`.
 *
 * Self-initialises on DOMContentLoaded and on `jy-repeater-row-added`; no
 * inline script, so it lives under the Content-Security-Policy as is.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var PREVIEW_DEBOUNCE_MS = 350;
	var SURFACE_RENAMES = { b: 'strong', i: 'em', strike: 'del', s: 'del' };

	/* ================================================================ shared */

	function closePopover(state) {
		if (state.popover) {
			state.popover.remove();
			state.popover = null;
		}
	}

	/**
	 * A small panel under the toolbar. $build(panel) fills it. Closes on
	 * Escape, on a click outside, and when another popover opens.
	 */
	function openPopover(state, anchor, build) {
		closePopover(state);
		var panel = document.createElement('div');
		panel.className = 'jy-ed-pop';
		panel.setAttribute('role', 'dialog');
		build(panel);
		state.toolbar.appendChild(panel);
		var left = anchor.offsetLeft;
		var maxLeft = Math.max(0, state.toolbar.clientWidth - panel.offsetWidth - 4);
		panel.style.left = Math.min(left, maxLeft) + 'px';
		panel.style.top = (anchor.offsetTop + anchor.offsetHeight + 4) + 'px';
		state.popover = panel;

		function onKey(event) {
			if (event.key === 'Escape') { event.preventDefault(); done(); }
		}
		function onClick(event) {
			if (!panel.contains(event.target) && event.target !== anchor) done();
		}
		function done() {
			document.removeEventListener('keydown', onKey, true);
			document.removeEventListener('mousedown', onClick, true);
			closePopover(state);
		}
		document.addEventListener('keydown', onKey, true);
		document.addEventListener('mousedown', onClick, true);
		panel.close = done;
		var first = panel.querySelector('input, button');
		if (first) first.focus();
		return panel;
	}

	function field(panel, labelText, name, value, placeholder) {
		var label = document.createElement('label');
		label.className = 'jy-ed-pop-field';
		var span = document.createElement('span');
		span.textContent = labelText;
		var input = document.createElement('input');
		input.type = 'text';
		input.name = name;
		input.value = value || '';
		if (placeholder) input.placeholder = placeholder;
		label.appendChild(span);
		label.appendChild(input);
		panel.appendChild(label);
		return input;
	}

	function checkbox(panel, labelText, name, checked) {
		var label = document.createElement('label');
		label.className = 'jy-ed-pop-check';
		var input = document.createElement('input');
		input.type = 'checkbox';
		input.name = name;
		input.checked = !!checked;
		label.appendChild(input);
		label.appendChild(document.createTextNode(' ' + labelText));
		panel.appendChild(label);
		return input;
	}

	function submitRow(panel, text, onSubmit) {
		var row = document.createElement('div');
		row.className = 'jy-ed-pop-actions';
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'jy-ed-pop-submit';
		btn.textContent = text;
		btn.addEventListener('click', function (event) { event.preventDefault(); onSubmit(); });
		row.appendChild(btn);
		panel.appendChild(row);
		panel.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && event.target.tagName === 'INPUT' && event.target.type === 'text') {
				event.preventDefault();
				onSubmit();
			}
		});
	}

	function setView(state, view) {
		state.view = view;
		state.root.setAttribute('data-mode', view);
		state.viewButtons.forEach(function (btn) {
			var on = btn.getAttribute('data-jy-ed-view') === view;
			btn.classList.toggle('is-active', on);
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		if (state.dialect.onView) state.dialect.onView(state, view);
	}

	function toggleFullscreen(state) {
		var on = !state.root.classList.contains('is-fullscreen');
		state.root.classList.toggle('is-fullscreen', on);
		document.documentElement.classList.toggle('jy-ed-fullscreen-open', on);
		var btn = state.root.querySelector('[data-jy-ed-action="fullscreen"]');
		if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
	}

	function setToolbarEnabled(state, enabled) {
		state.root.querySelectorAll('.jy-ed-toolbar button').forEach(function (btn) {
			var action = btn.getAttribute('data-jy-ed-action');
			var always = action === 'fullscreen' || btn.hasAttribute('data-jy-ed-view');
			btn.disabled = !enabled && !always;
		});
	}

	/* ========================================================= html dialect */

	function exec(command, value) {
		try {
			return document.execCommand(command, false, value === undefined ? null : value);
		} catch (e) {
			return false;
		}
	}

	/** The selection, if it lives inside the surface. */
	function surfaceRange(state) {
		var sel = window.getSelection();
		if (!sel || sel.rangeCount === 0) return null;
		var range = sel.getRangeAt(0);
		if (!state.surface.contains(range.commonAncestorContainer)) return null;
		return range;
	}

	function rememberSelection(state) {
		var range = surfaceRange(state);
		if (range) state.savedRange = range.cloneRange();
	}

	function restoreSelection(state) {
		state.surface.focus();
		if (!state.savedRange) return;
		var sel = window.getSelection();
		sel.removeAllRanges();
		try { sel.addRange(state.savedRange); } catch (e) { /* stale range */ }
	}

	function syncHtml(state) {
		state.edited = true;
		state.textarea.value = state.surface.innerHTML;
		state.textarea.dispatchEvent(new Event('input', { bubbles: true }));
	}

	/**
	 * Clean up replaces the whole surface. Engines keep the outermost block
	 * of the old content when the whole selection is replaced through
	 * insertHTML, so the replacement is a direct write and the button itself
	 * is the way back: it reads "Undo clean up" until the next edit.
	 */
	function setCleanupUndo(state, previous) {
		var btn = state.root.querySelector('[data-jy-ed-action="cleanup"]');
		if (!btn) return;
		if (previous === null) {
			state.cleanupUndo = null;
			if (btn.getAttribute('data-jy-ed-undo') === '1') {
				btn.removeAttribute('data-jy-ed-undo');
				btn.textContent = btn.getAttribute('data-jy-ed-face') || 'Clean up';
			}
			return;
		}
		state.cleanupUndo = previous;
		if (!btn.hasAttribute('data-jy-ed-face')) btn.setAttribute('data-jy-ed-face', btn.textContent);
		btn.setAttribute('data-jy-ed-undo', '1');
		btn.textContent = 'Undo clean up';
	}

	/**
	 * Engines disagree on the tag a command emits (b/strong, i/em,
	 * strike/s/del). Rename inside the affected range only: the elements the
	 * selection intersects, and the ones it sits inside. Nothing outside the
	 * selection is touched. That is the whole of the always-on normalisation.
	 */
	function normaliseRange(state) {
		var range = surfaceRange(state);
		if (!range) return;
		var selector = Object.keys(SURFACE_RENAMES).join(',');
		var scope = range.commonAncestorContainer;
		if (scope.nodeType !== 1) scope = scope.parentElement;
		if (!scope) return;
		var candidates = Array.prototype.slice.call(scope.querySelectorAll(selector));
		for (var up = scope; up && up !== state.surface; up = up.parentElement) {
			if (up.matches(selector)) candidates.unshift(up);
		}
		var start = { node: range.startContainer, offset: range.startOffset };
		var end = { node: range.endContainer, offset: range.endOffset };
		var renamed = false;
		candidates.forEach(function (el) {
			if (!el.parentNode || !range.intersectsNode(el)) return;
			var fresh = document.createElement(SURFACE_RENAMES[el.localName]);
			Array.prototype.slice.call(el.attributes).forEach(function (a) { fresh.setAttribute(a.name, a.value); });
			while (el.firstChild) fresh.appendChild(el.firstChild);
			if (start.node === el) start.node = fresh;
			if (end.node === el) end.node = fresh;
			el.parentNode.replaceChild(fresh, el);
			renamed = true;
		});
		if (!renamed) return;
		try {
			var fixed = document.createRange();
			fixed.setStart(start.node, start.offset);
			fixed.setEnd(end.node, end.offset);
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange(fixed);
		} catch (e) { /* the boundary moved; the caret stays where the engine put it */ }
	}

	function runSimple(state, command, value) {
		restoreSelection(state);
		exec(command, value);
		normaliseRange(state);
		syncHtml(state);
		setCleanupUndo(state, null);
	}

	/** Replace the whole surface and leave the caret at the end. */
	function replaceSurface(state, html) {
		state.surface.innerHTML = html;
		state.surface.focus();
		var sel = window.getSelection();
		if (sel) {
			try { sel.collapse(state.surface, state.surface.childNodes.length); } catch (e) { /* no caret position */ }
		}
	}

	function cleanupFn() {
		return window.joineryHtmlCleanup ? window.joineryHtmlCleanup.cleanup : function (h) { return h; };
	}

	function linkPopover(state, anchor) {
		rememberSelection(state);
		var range = state.savedRange;
		var selectedText = range ? range.toString() : '';
		var existing = null;
		if (range) {
			var node = range.commonAncestorContainer;
			existing = (node.nodeType === 1 ? node : node.parentElement).closest('a');
			if (existing && !state.surface.contains(existing)) existing = null;
		}
		openPopover(state, anchor, function (panel) {
			var url = field(panel, 'URL', 'url', existing ? existing.getAttribute('href') : '', 'https://');
			var text = field(panel, 'Text', 'text', existing ? existing.textContent : selectedText);
			var blank = checkbox(panel, 'Open in new tab', 'blank', existing && existing.target === '_blank');
			submitRow(panel, existing ? 'Update link' : 'Insert link', function () {
				var href = url.value.trim();
				if (href === '') { url.focus(); return; }
				var label = text.value.trim() || href;
				panel.close();
				restoreSelection(state);
				if (existing) {
					existing.setAttribute('href', href);
					existing.textContent = label;
					if (blank.checked) { existing.target = '_blank'; existing.rel = 'noopener'; }
					else { existing.removeAttribute('target'); existing.removeAttribute('rel'); }
					syncHtml(state);
					return;
				}
				var a = document.createElement('a');
				a.setAttribute('href', href);
				if (blank.checked) { a.target = '_blank'; a.rel = 'noopener'; }
				a.textContent = label;
				exec('insertHTML', a.outerHTML);
				syncHtml(state);
				setCleanupUndo(state, null);
			});
		});
	}

	function imagePopover(state, anchor) {
		rememberSelection(state);
		openPopover(state, anchor, function (panel) {
			var url = field(panel, 'Image URL', 'url', '', 'https://');
			var alt = field(panel, 'Alt text', 'alt', '');
			var width = field(panel, 'Width', 'width', '', 'optional, e.g. 400');
			submitRow(panel, 'Insert image', function () {
				var src = url.value.trim();
				if (src === '') { url.focus(); return; }
				var img = document.createElement('img');
				img.setAttribute('src', src);
				img.setAttribute('alt', alt.value.trim());
				var w = width.value.trim();
				if (w !== '') img.setAttribute('width', w.replace(/px$/i, ''));
				panel.close();
				restoreSelection(state);
				exec('insertHTML', img.outerHTML);
				syncHtml(state);
				setCleanupUndo(state, null);
			});
		});
	}

	var FORMATS = [
		['p', 'Paragraph'], ['h1', 'Heading 1'], ['h2', 'Heading 2'],
		['h3', 'Heading 3'], ['h4', 'Heading 4'], ['blockquote', 'Quote'], ['pre', 'Code block']
	];

	function formatPopover(state, anchor) {
		rememberSelection(state);
		openPopover(state, anchor, function (panel) {
			panel.classList.add('jy-ed-pop-menu');
			FORMATS.forEach(function (pair) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'jy-ed-fmt-' + pair[0];
				btn.textContent = pair[1];
				btn.addEventListener('mousedown', function (event) { event.preventDefault(); });
				btn.addEventListener('click', function (event) {
					event.preventDefault();
					panel.close();
					runSimple(state, 'formatBlock', '<' + pair[0] + '>');
				});
				panel.appendChild(btn);
			});
		});
	}

	var HTML_ACTIONS = {
		undo: function (state) { runSimple(state, 'undo'); },
		redo: function (state) { runSimple(state, 'redo'); },
		bold: function (state) { runSimple(state, 'bold'); },
		italic: function (state) { runSimple(state, 'italic'); },
		del: function (state) { runSimple(state, 'strikeThrough'); },
		sup: function (state) { runSimple(state, 'superscript'); },
		sub: function (state) { runSimple(state, 'subscript'); },
		ul: function (state) { runSimple(state, 'insertUnorderedList'); },
		ol: function (state) { runSimple(state, 'insertOrderedList'); },
		hr: function (state) { runSimple(state, 'insertHorizontalRule'); },
		'align-left': function (state) { runSimple(state, 'justifyLeft'); },
		'align-center': function (state) { runSimple(state, 'justifyCenter'); },
		'align-right': function (state) { runSimple(state, 'justifyRight'); },
		'align-justify': function (state) { runSimple(state, 'justifyFull'); },
		removeformat: function (state) { runSimple(state, 'removeFormat'); },
		format: formatPopover,
		link: linkPopover,
		image: imagePopover,
		cleanup: function (state) {
			if (state.cleanupUndo !== null && state.cleanupUndo !== undefined) {
				replaceSurface(state, state.cleanupUndo);
				syncHtml(state);
				setCleanupUndo(state, null);
				return;
			}
			var previous = state.surface.innerHTML;
			replaceSurface(state, cleanupFn()(previous));
			syncHtml(state);
			setCleanupUndo(state, previous);
		}
	};

	function loadSurface(state) {
		var html = state.textarea.value;
		if (state.cleanup === 'always') html = cleanupFn()(html);
		state.surface.innerHTML = html;
	}

	var HTML_DIALECT = {
		name: 'html',
		defaultView: 'visual',

		setup: function (state) {
			state.surface = state.root.querySelector('.jy-ed-surface');
			if (!state.surface) return false;
			state.cleanup = state.root.getAttribute('data-jy-ed-cleanup') || 'button';
			state.edited = false;
			state.savedRange = null;
			state.cleanupUndo = null;

			var editable = !state.textarea.disabled && !state.textarea.readOnly;
			state.surface.contentEditable = editable ? 'true' : 'false';

			exec('defaultParagraphSeparator', 'p');
			exec('styleWithCSS', false);

			state.surface.addEventListener('focus', function () {
				exec('defaultParagraphSeparator', 'p');
				exec('styleWithCSS', false);
			});
			state.surface.addEventListener('input', function () { syncHtml(state); setCleanupUndo(state, null); });
			document.addEventListener('selectionchange', function () { rememberSelection(state); });

			state.surface.addEventListener('keydown', function (event) {
				var accel = event.metaKey || event.ctrlKey;
				if (!accel || event.altKey) return;
				var key = event.key.toLowerCase();
				if (key === 'b') { event.preventDefault(); HTML_ACTIONS.bold(state); }
				else if (key === 'i') { event.preventDefault(); HTML_ACTIONS.italic(state); }
				else if (key === 'k') {
					event.preventDefault();
					var btn = state.root.querySelector('[data-jy-ed-action="link"]');
					linkPopover(state, btn || state.toolbar);
				}
			});

			if (state.cleanup === 'always') {
				state.surface.addEventListener('paste', function (event) {
					var data = event.clipboardData;
					if (!data) return;
					var html = data.getData('text/html');
					var text = data.getData('text/plain');
					if (html) {
						event.preventDefault();
						exec('insertHTML', cleanupFn()(html));
						syncHtml(state);
					} else if (text) {
						event.preventDefault();
						exec('insertText', text);
						syncHtml(state);
					}
				});
			}

			// Source-view typing is an edit too.
			state.textarea.addEventListener('input', function () {
				if (state.view === 'source') state.edited = true;
			});

			// Capture phase: the textarea is clean before joinery-validate reads it.
			if (state.textarea.form) {
				state.textarea.form.addEventListener('submit', function () {
					if (state.cleanup === 'always' && state.edited && state.view === 'visual') {
						state.surface.innerHTML = cleanupFn()(state.surface.innerHTML);
						state.textarea.value = state.surface.innerHTML;
					}
				}, true);
			}

			if (!editable) setToolbarEnabled(state, false);
			return true;
		},

		run: function (state, action, button) {
			var fn = HTML_ACTIONS[action];
			if (!fn) return;
			if (state.view === 'source' && action !== 'fullscreen') return;
			fn(state, button);
		},

		onView: function (state, view) {
			if (view === 'visual') {
				loadSurface(state);
			}
			setCleanupUndo(state, null);
			var editable = !state.textarea.disabled && !state.textarea.readOnly;
			setToolbarEnabled(state, editable && view === 'visual');
			closePopover(state);
		}
	};

	/* ===================================================== markdown dialect */

	/**
	 * Replace a range through execCommand where the browser allows it, so the
	 * edit joins the textarea's native undo stack. Assigning to .value would
	 * wipe that stack, and an editor you cannot Ctrl+Z is worse than no editor.
	 */
	function replaceRange(ta, start, end, text) {
		ta.focus();
		ta.setSelectionRange(start, end);
		var done = false;
		try {
			done = document.execCommand('insertText', false, text);
		} catch (e) {
			done = false;
		}
		if (!done || ta.value.slice(start, start + text.length) !== text) {
			ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
			ta.setSelectionRange(start + text.length, start + text.length);
		}
	}

	function lineBounds(value, start, end) {
		var from = value.lastIndexOf('\n', start - 1) + 1;
		var to = value.indexOf('\n', end);
		if (to === -1) to = value.length;
		return { from: from, to: to };
	}

	/** Wrap or unwrap the selection with a marker pair (bold, italic, code). */
	function wrap(ta, before, after) {
		var start = ta.selectionStart;
		var end = ta.selectionEnd;
		var value = ta.value;
		var selected = value.slice(start, end);

		var outerStart = start - before.length;
		var outerEnd = end + after.length;
		if (outerStart >= 0
				&& value.slice(outerStart, start) === before
				&& value.slice(end, outerEnd) === after) {
			replaceRange(ta, outerStart, outerEnd, selected);
			ta.setSelectionRange(outerStart, outerStart + selected.length);
			return;
		}

		if (selected.slice(0, before.length) === before
				&& selected.slice(-after.length) === after
				&& selected.length >= before.length + after.length) {
			var inner = selected.slice(before.length, selected.length - after.length);
			replaceRange(ta, start, end, inner);
			ta.setSelectionRange(start, start + inner.length);
			return;
		}

		replaceRange(ta, start, end, before + selected + after);
		if (selected === '') {
			ta.setSelectionRange(start + before.length, start + before.length);
		} else {
			ta.setSelectionRange(start + before.length, start + before.length + selected.length);
		}
	}

	/**
	 * Toggle a line-start marker across every selected line. $prefix may be a
	 * string ('> ', '- ') or a function (index) => string for numbered lists.
	 */
	function togglePrefix(ta, prefix, matcher) {
		var value = ta.value;
		var bounds = lineBounds(value, ta.selectionStart, ta.selectionEnd);
		var lines = value.slice(bounds.from, bounds.to).split('\n');

		var allMarked = lines.every(function (line) {
			return line === '' || matcher.test(line);
		});

		var out = lines.map(function (line, i) {
			if (allMarked) {
				return line.replace(matcher, '');
			}
			if (line === '' && lines.length > 1) return line;
			return (typeof prefix === 'function' ? prefix(i) : prefix) + line.replace(matcher, '');
		}).join('\n');

		replaceRange(ta, bounds.from, bounds.to, out);
		ta.setSelectionRange(bounds.from, bounds.from + out.length);
	}

	function insertBlock(ta, block) {
		var start = ta.selectionStart;
		var value = ta.value;
		var prefix = (start === 0 || value[start - 1] === '\n') ? '' : '\n';
		var text = prefix + block;
		replaceRange(ta, start, ta.selectionEnd, text);
		ta.setSelectionRange(start + text.length, start + text.length);
	}

	function mdLinkPopover(state, anchor) {
		var ta = state.textarea;
		var start = ta.selectionStart, end = ta.selectionEnd;
		var selected = ta.value.slice(start, end);
		var isUrl = /^(https?:\/\/|\/|mailto:)/i.test(selected.trim());
		openPopover(state, anchor, function (panel) {
			var url = field(panel, 'URL', 'url', isUrl ? selected.trim() : '', 'https://');
			var text = field(panel, 'Text', 'text', isUrl ? '' : selected);
			submitRow(panel, 'Insert link', function () {
				var href = url.value.trim();
				if (href === '') { url.focus(); return; }
				var label = text.value.trim() || href;
				panel.close();
				replaceRange(ta, start, end, '[' + label + '](' + href + ')');
				schedulePreview(state);
			});
		});
	}

	function mdImagePopover(state, anchor) {
		var ta = state.textarea;
		var start = ta.selectionStart, end = ta.selectionEnd;
		openPopover(state, anchor, function (panel) {
			var url = field(panel, 'Image URL', 'url', '', 'https://');
			var alt = field(panel, 'Alt text', 'alt', ta.value.slice(start, end));
			submitRow(panel, 'Insert image', function () {
				var src = url.value.trim();
				if (src === '') { url.focus(); return; }
				panel.close();
				replaceRange(ta, start, end, '![' + alt.value.trim() + '](' + src + ')');
				schedulePreview(state);
			});
		});
	}

	var MD_ACTIONS = {
		bold: function (ta) { wrap(ta, '**', '**'); },
		italic: function (ta) { wrap(ta, '*', '*'); },
		code: function (ta) { wrap(ta, '`', '`'); },
		h1: function (ta) { togglePrefix(ta, '# ', /^#{1,6}\s+/); },
		h2: function (ta) { togglePrefix(ta, '## ', /^#{1,6}\s+/); },
		h3: function (ta) { togglePrefix(ta, '### ', /^#{1,6}\s+/); },
		quote: function (ta) { togglePrefix(ta, '> ', /^>\s+/); },
		ul: function (ta) { togglePrefix(ta, '- ', /^[-*+]\s+/); },
		ol: function (ta) {
			togglePrefix(ta, function (i) { return (i + 1) + '. '; }, /^\d+\.\s+/);
		},
		codeblock: function (ta) { insertBlock(ta, '```\n\n```\n'); },
		table: function (ta) {
			insertBlock(ta, '| Column | Column |\n|--------|--------|\n|        |        |\n');
		}
	};

	function renderPreview(state) {
		var markdown = state.textarea.value;
		if (markdown === state.rendered) return;
		if (!window.joineryApi) return;

		var seq = ++state.seq;
		window.joineryApi.post('markdown_preview', { markdown: markdown })
			.then(function (data) {
				if (seq !== state.seq) return;   // a later keystroke already won
				state.rendered = markdown;
				state.preview.innerHTML = (data && data.html) ? data.html : '';
			})
			.catch(function () {
				if (seq !== state.seq) return;
				state.preview.textContent = 'Preview unavailable.';
			});
	}

	function schedulePreview(state) {
		if (state.view === 'write') return;
		window.clearTimeout(state.timer);
		state.timer = window.setTimeout(function () { renderPreview(state); }, PREVIEW_DEBOUNCE_MS);
	}

	function continueList(state, event) {
		if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey) return;

		var ta = state.textarea;
		if (ta.selectionStart !== ta.selectionEnd) return;

		var caret = ta.selectionStart;
		var from = ta.value.lastIndexOf('\n', caret - 1) + 1;
		var line = ta.value.slice(from, caret);

		var match = line.match(/^(\s*)([-*+]|(\d+)\.)\s+/);
		if (!match) return;

		event.preventDefault();

		// An empty marker means "done with this list" — clear the line instead
		// of laying down another bullet the author has to delete.
		if (line.length === match[0].length) {
			replaceRange(ta, from, caret, '');
			return;
		}

		var marker = match[3] ? (parseInt(match[3], 10) + 1) + '. ' : match[2] + ' ';
		replaceRange(ta, caret, caret, '\n' + match[1] + marker);
	}

	var MARKDOWN_DIALECT = {
		name: 'markdown',
		defaultView: 'write',

		setup: function (state) {
			state.preview = state.root.querySelector('.jy-ed-preview');
			if (!state.preview) return false;
			state.rendered = null;
			state.seq = 0;
			state.timer = null;

			// Without the shared API transport there is no server to render the
			// preview, so drop the view controls rather than offer a button that fails.
			if (!window.joineryApi) {
				state.viewButtons.forEach(function (btn) { btn.remove(); });
				state.viewButtons = [];
			}

			var ta = state.textarea;
			ta.addEventListener('keydown', function (event) {
				var accel = event.metaKey || event.ctrlKey;
				if (accel && !event.altKey) {
					var key = event.key.toLowerCase();
					if (key === 'b') { event.preventDefault(); MD_ACTIONS.bold(ta); schedulePreview(state); return; }
					if (key === 'i') { event.preventDefault(); MD_ACTIONS.italic(ta); schedulePreview(state); return; }
					if (key === 'k') {
						event.preventDefault();
						var btn = state.root.querySelector('[data-jy-ed-action="link"]');
						mdLinkPopover(state, btn || state.toolbar);
						return;
					}
				}
				continueList(state, event);
			});
			ta.addEventListener('input', function () { schedulePreview(state); });

			if (ta.disabled || ta.readOnly) setToolbarEnabled(state, false);
			return true;
		},

		run: function (state, action, button) {
			if (action === 'link') { mdLinkPopover(state, button); return; }
			if (action === 'image') { mdImagePopover(state, button); return; }
			var fn = MD_ACTIONS[action];
			if (!fn) return;
			fn(state.textarea);
			schedulePreview(state);
		},

		onView: function (state, view) {
			if (view !== 'write') renderPreview(state);
		}
	};

	/* ================================================================ wiring */

	var DIALECTS = { html: HTML_DIALECT, markdown: MARKDOWN_DIALECT };

	function init(root) {
		if (root.getAttribute('data-jy-ed-ready') === '1') return null;
		var dialect = DIALECTS[root.getAttribute('data-jy-editor')];
		var textarea = root.querySelector('textarea');
		var toolbar = root.querySelector('.jy-ed-toolbar');
		if (!dialect || !textarea || !toolbar) return null;
		root.setAttribute('data-jy-ed-ready', '1');

		var state = {
			root: root,
			dialect: dialect,
			textarea: textarea,
			toolbar: toolbar,
			popover: null,
			view: null,
			viewButtons: Array.prototype.slice.call(root.querySelectorAll('[data-jy-ed-view]'))
		};

		if (!dialect.setup(state)) return null;

		toolbar.querySelectorAll('[data-jy-ed-action]').forEach(function (btn) {
			// Keep focus (and the selection) in the surface while a button is pressed.
			btn.addEventListener('mousedown', function (event) { event.preventDefault(); });
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				if (btn.disabled) return;
				var action = btn.getAttribute('data-jy-ed-action');
				if (action === 'fullscreen') { toggleFullscreen(state); return; }
				dialect.run(state, action, btn);
			});
		});

		state.viewButtons.forEach(function (btn) {
			btn.addEventListener('mousedown', function (event) { event.preventDefault(); });
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				setView(state, btn.getAttribute('data-jy-ed-view'));
			});
		});

		root.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && root.classList.contains('is-fullscreen') && !state.popover) {
				toggleFullscreen(state);
			}
		});

		setView(state, root.getAttribute('data-jy-ed-initial-view') || dialect.defaultView);
		root.jyEditor = state;
		return state;
	}

	function initAll(scope) {
		(scope || document).querySelectorAll('.jy-ed[data-jy-editor]').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { initAll(); });
	} else {
		initAll();
	}

	// A repeater row added after load carries its chrome already; only the
	// behaviour is missing.
	document.addEventListener('jy-repeater-row-added', function (event) {
		if (event.target && event.target.querySelectorAll) initAll(event.target);
	});

	window.joineryEditor = { init: init, initAll: initAll };
})();
