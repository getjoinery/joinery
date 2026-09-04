/**
 * Signature editors on the Email settings section (/profile/mailbox/settings).
 *
 * One rich editor per mailbox the member holds. The toolbar is the compose
 * toolbar minus images — a signature carries none — and Save posts to the
 * mailbox/signature_save action, which sanitizes the HTML and answers with what
 * it stored, so the editor shows exactly what a message will carry.
 *
 * Vanilla JS, no framework. The editor styling is the reader's (.mbx-rich,
 * .mbx-toolbar), whose stylesheet the plugin loads on every page.
 *
 * @version 1.1
 */
(function () {
	'use strict';

	var COMMANDS = [
		['bold', 'B'], ['italic', 'I'], ['underline', 'U'],
		['insertUnorderedList', '• List'], ['insertOrderedList', '1. List'],
		['createLink', 'Link'], ['removeFormat', 'Clear']
	];

	// Run a formatting command against one editor, keeping the caret inside it.
	function execOn(editor, cmd) {
		editor.focus();
		if (cmd === 'createLink') {
			var url = window.prompt('Link URL:', 'https://');
			if (!url) { return; }
			if (!/^(https?:\/\/|mailto:)/i.test(url)) { url = 'https://' + url; }
			document.execCommand('createLink', false, url);
			return;
		}
		document.execCommand(cmd, false, null);
	}

	function fillToolbar(toolbar, editor) {
		COMMANDS.forEach(function (c) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'mbx-tb';
			b.textContent = c[1];
			b.title = c[0];
			// mousedown-preventDefault keeps the selection in the editor, which
			// is what the command acts on.
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function (e) { e.preventDefault(); execOn(editor, c[0]); });
			toolbar.appendChild(b);
		});
	}

	function note(el, msg, ok) {
		el.hidden = false;
		el.className = 'mbx-sig-note ' + (ok ? 'is-ok' : 'is-error');
		el.textContent = msg;
	}

	function mount(block) {
		var aliasId = block.getAttribute('data-alias-id');
		var editor = block.querySelector('[data-sig-editor]');
		var toolbar = block.querySelector('[data-sig-toolbar]');
		var noteEl = block.querySelector('[data-sig-note]');
		var form = block.closest('form');
		if (!editor || !toolbar || !form) { return; }

		fillToolbar(toolbar, editor);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = form.querySelector('[type="submit"]');
			if (btn) { btn.disabled = true; }
			window.joineryApi.post('mailbox/signature_save', {
				alias_id: String(aliasId),
				signature: editor.innerHTML
			}).then(function (data) {
				if (btn) { btn.disabled = false; }
				// Show what was stored, not what was typed: the server strips
				// what a signature may not carry, and the difference is the
				// answer to "why does my mail not look like that".
				editor.innerHTML = (data && data.signature) || '';
				note(noteEl, 'Signature saved.', true);
			}).catch(function (err) {
				if (btn) { btn.disabled = false; }
				note(noteEl, (err && err.message) || 'Could not save the signature.', false);
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.mbx-sig-card'), mount);
	});
})();
