/**
 * file-browser.js — list / browse view toggle for the admin Files page.
 *
 * The page renders its rows once, as a table. Browse mode is presentation over
 * that same markup: a class on #jyFilesBrowser turns the table into a tile grid
 * and reveals the source rail beside it. No second query, no second rendering,
 * and nothing to keep in sync when a row's markup changes.
 *
 * The choice is remembered in localStorage so it survives navigation and a
 * browser restart — a view style is a preference, not a per-page decision.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'joinery.files.view';
	var MODES = ['list', 'browse'];

	function readStoredMode() {
		try {
			var stored = window.localStorage.getItem(STORAGE_KEY);
			return MODES.indexOf(stored) !== -1 ? stored : 'list';
		} catch (e) {
			// Private mode, or storage disabled. The page still works; it just
			// opens in list view every time.
			return 'list';
		}
	}

	function storeMode(mode) {
		try {
			window.localStorage.setItem(STORAGE_KEY, mode);
		} catch (e) {
			/* not fatal — see readStoredMode */
		}
	}

	function apply(root, buttons, mode) {
		root.classList.toggle('jy-files-list', mode === 'list');
		root.classList.toggle('jy-files-browse', mode === 'browse');
		for (var i = 0; i < buttons.length; i++) {
			var isCurrent = buttons[i].getAttribute('data-files-view') === mode;
			buttons[i].setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
			buttons[i].classList.toggle('is-active', isCurrent);
		}
	}

	function init() {
		var root = document.getElementById('jyFilesBrowser');
		var buttons = document.querySelectorAll('[data-files-view]');
		if (!root || !buttons.length) {
			return;
		}

		apply(root, buttons, readStoredMode());

		for (var i = 0; i < buttons.length; i++) {
			buttons[i].addEventListener('click', function (e) {
				var mode = e.currentTarget.getAttribute('data-files-view');
				if (MODES.indexOf(mode) === -1) {
					return;
				}
				apply(root, buttons, mode);
				storeMode(mode);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
