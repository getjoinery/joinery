/**
 * Joinery API browser transport — the single page-JS implementation of the
 * /api/v1 action call contract (docs/api.md § Authentication).
 *
 * window.joineryApi.post(action, body) POSTs JSON to /api/v1/action/{action}
 * with the browser-session CSRF header and resolves with the success
 * envelope's `data`. It rejects (Error with .status and .errorType) on an
 * error envelope or network failure — logic-level soft failures the action
 * returns inside `data` (e.g. {ok: false}) pass through resolved.
 *
 * window.joineryApi.csrf() reads the token cookie-first (docs/api.md § Browser
 * sessions): the joinery_api_csrf mirror cookie tracks the CURRENT session —
 * resynced on every response, including after a logout in another tab — while
 * the signed-in meta tag is frozen at render and serves as the cookie-less
 * fallback. Sessionless actions need no token; post() simply omits the header
 * when none is present.
 *
 * Loaded unconditionally by PublicPageBase::public_header() on every page,
 * before any inline page script.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	function csrf() {
		var m = document.cookie.match(/(?:^|; )joinery_api_csrf=([^;]+)/);
		if (m) return decodeURIComponent(m[1]);
		var meta = document.querySelector('meta[name="joinery-api-csrf"]');
		return (meta && meta.content) || '';
	}

	function post(action, body) {
		// Accept an action name ('calendar_feed', 'store/checkout_apply_coupon')
		// or a full endpoint URL (leading '/') — components configured with a
		// feed URL pass it straight through.
		var url = action.charAt(0) === '/' ? action : '/api/v1/action/' + action;
		var token = csrf();
		var headers = { 'Content-Type': 'application/json' };
		if (token) headers['X-Joinery-Csrf'] = token;
		return fetch(url, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify(body || {})
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (env) {
				if (!r.ok) {
					// Error envelope keys per api_error(): errortype + error.
					var err = new Error((env && env.error) || 'Request failed (' + r.status + ')');
					err.status = r.status;
					err.errorType = env && env.errortype;
					// An error envelope still carries a data payload, and some
					// refusals are instructions rather than dead ends — the
					// step-up gates answer with requires_stepup so the caller can
					// send the user to confirm and then retry. Dropping it would
					// turn "prove it is you" into "something went wrong".
					err.data = (env && env.data) || {};
					err.validationErrors = env && env.validation_errors;
					throw err;
				}
				return (env && env.data !== undefined) ? env.data : env;
			});
		});
	}

	window.joineryApi = { post: post, csrf: csrf };
})();
