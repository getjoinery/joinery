/**
 * Joinery markdown editor — the platform's markdown editing surface.
 *
 * Enhances any textarea marked up by FormWriter's `markdownmode` option: a
 * formatting toolbar, keyboard shortcuts, list continuation, and a live
 * preview rendered by the SERVER (the markdown_preview action), so what the
 * author sees is what MarkdownRenderer will produce — there is no second
 * renderer in JavaScript to drift out of step with the PHP one.
 *
 * The textarea stays the form field and the markdown source stays canonical.
 * Nothing is round-tripped through HTML, so a save rewrites only the text the
 * author actually changed — which is what keeps a docs file's diff readable.
 *
 * Auto-initializes every [data-jy-markdown-editor] on the page; no inline
 * script, so it survives a strict Content-Security-Policy.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var PREVIEW_DEBOUNCE_MS = 350;

	/* ---------------------------------------------------------------- text */

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
		if (!done) {
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

	function insertLink(ta) {
		var start = ta.selectionStart;
		var end = ta.selectionEnd;
		var selected = ta.value.slice(start, end);
		var isUrl = /^(https?:\/\/|\/|mailto:)/i.test(selected.trim());

		var text = isUrl ? '[](' + selected.trim() + ')' : '[' + selected + '](url)';
		replaceRange(ta, start, end, text);

		if (isUrl) {
			ta.setSelectionRange(start + 1, start + 1);
		} else {
			var urlAt = start + selected.length + 3;
			ta.setSelectionRange(urlAt, urlAt + 3);
		}
	}

	function insertBlock(ta, block) {
		var start = ta.selectionStart;
		var value = ta.value;
		var prefix = (start === 0 || value[start - 1] === '\n') ? '' : '\n';
		var text = prefix + block;
		replaceRange(ta, start, ta.selectionEnd, text);
		ta.setSelectionRange(start + text.length, start + text.length);
	}

	var ACTIONS = {
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
		link: insertLink,
		codeblock: function (ta) { insertBlock(ta, '```\n\n```\n'); },
		table: function (ta) {
			insertBlock(ta, '| Column | Column |\n|--------|--------|\n|        |        |\n');
		}
	};

	/* ------------------------------------------------------------- preview */

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
		if (state.mode === 'write') return;
		window.clearTimeout(state.timer);
		state.timer = window.setTimeout(function () { renderPreview(state); }, PREVIEW_DEBOUNCE_MS);
	}

	function setMode(state, mode) {
		state.mode = mode;
		state.root.setAttribute('data-mode', mode);
		state.modeButtons.forEach(function (btn) {
			var on = btn.getAttribute('data-jy-md-mode') === mode;
			btn.classList.toggle('is-active', on);
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		if (mode !== 'write') renderPreview(state);
	}

	/* ---------------------------------------------------------- list enter */

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

	/* ------------------------------------------------------------- wiring */

	function init(root) {
		if (root.getAttribute('data-jy-md-ready') === '1') return;
		root.setAttribute('data-jy-md-ready', '1');

		var textarea = root.querySelector('textarea');
		var preview = root.querySelector('[data-jy-md-preview]');
		if (!textarea || !preview) return;

		var state = {
			root: root,
			textarea: textarea,
			preview: preview,
			mode: 'write',
			rendered: null,
			seq: 0,
			timer: null,
			modeButtons: Array.prototype.slice.call(root.querySelectorAll('[data-jy-md-mode]'))
		};

		// Without the shared API transport there is no server to render the
		// preview, so drop the controls rather than offer a button that fails.
		if (!window.joineryApi) {
			state.modeButtons.forEach(function (btn) { btn.remove(); });
			state.modeButtons = [];
		}

		root.querySelectorAll('[data-jy-md-action]').forEach(function (btn) {
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				var action = ACTIONS[btn.getAttribute('data-jy-md-action')];
				if (!action) return;
				action(textarea);
				schedulePreview(state);
			});
		});

		state.modeButtons.forEach(function (btn) {
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				setMode(state, btn.getAttribute('data-jy-md-mode'));
			});
		});

		textarea.addEventListener('keydown', function (event) {
			var accel = event.metaKey || event.ctrlKey;
			if (accel && !event.altKey) {
				var key = event.key.toLowerCase();
				if (key === 'b') { event.preventDefault(); ACTIONS.bold(textarea); schedulePreview(state); return; }
				if (key === 'i') { event.preventDefault(); ACTIONS.italic(textarea); schedulePreview(state); return; }
				if (key === 'k') { event.preventDefault(); ACTIONS.link(textarea); schedulePreview(state); return; }
			}
			continueList(state, event);
		});

		textarea.addEventListener('input', function () { schedulePreview(state); });

		setMode(state, root.getAttribute('data-jy-md-initial-mode') || 'write');
	}

	function initAll() {
		document.querySelectorAll('[data-jy-markdown-editor]').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}

	window.joineryMarkdownEditor = { init: init, initAll: initAll };
})();
