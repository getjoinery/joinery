/*
 * Loaded first by the page tests/unit/formwriter_behaviour_test.php writes:
 * nothing on the page may reach a server. fetch() and joineryApi.post()
 * answer from here, and every request is recorded for driver.js to read.
 */
(function () {
	'use strict';
	window.__requests = [];
	var people = [{ id: 7, text: 'Alice Smith' }, { id: 8, text: 'Alina Jones' }];
	window.fetch = function (url) {
		window.__requests.push({ via: 'fetch', url: String(url) });
		return Promise.resolve({ json: function () { return Promise.resolve(people); } });
	};
	window.joineryApi = {
		post: function (action, body) {
			window.__requests.push({ via: 'api', action: action, body: body });
			return Promise.resolve({ items: people });
		},
		csrf: function () { return ''; }
	};
})();
