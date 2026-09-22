/**
 * Stored credentials — the Reset button beside a locked credential field.
 *
 * FormWriter draws a credential with something stored (passwordinput's
 * `stored` option) as a disabled field showing dots, with a button carrying
 * `data-stored-secret-for`. A browser does not submit a disabled field, so a
 * save that never touches it keeps the stored value.
 *
 *   Reset  enables and empties the field, restores `required`, focuses it.
 *          Typed text replaces the stored value; left blank, the save removes it.
 *   Undo   locks it again, so a Reset clicked by mistake costs nothing.
 *
 * Self-initialises on DOMContentLoaded; no inline script, so it lives under
 * the Content-Security-Policy as is.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	function wire(button) {
		if (button.hasAttribute('data-stored-secret-wired')) return;
		var field = document.getElementById(button.getAttribute('data-stored-secret-for'));
		if (!field) return;
		button.setAttribute('data-stored-secret-wired', '');

		var note = button.parentNode.querySelector('[data-stored-secret-note]');
		var placeholder = field.getAttribute('placeholder') || '';

		function lock(locked) {
			field.value = '';
			field.disabled = locked;
			field.required = !locked && field.hasAttribute('data-required');
			field.placeholder = locked ? placeholder : '';
			if (locked) {
				field.classList.remove('is-invalid');
				field.removeAttribute('aria-invalid');
			}
			button.textContent = button.getAttribute(locked ? 'data-reset-label' : 'data-undo-label');
			button.setAttribute('aria-expanded', locked ? 'false' : 'true');
			if (note) note.textContent = note.getAttribute(locked ? 'data-locked-text' : 'data-open-text');
			if (!locked) field.focus();
		}

		button.addEventListener('click', function () {
			lock(!field.disabled);
		});
	}

	function init() {
		document.querySelectorAll('[data-stored-secret-for]').forEach(wire);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
