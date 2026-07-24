/**
 * Server Manager — shared admin page helpers.
 *
 * One copy of the three helpers every server_manager admin page needs, loaded
 * per-page by node_detail / index / node_add / job_detail (not globally — these
 * are superadmin-only pages). joinery-api.js is already loaded in the header, so
 * window.joineryApi is available before this file runs.
 *
 *   smApiPost(action, params) — POST to /api/v1/action/server_manager/{action}.
 *       Resolves with the action's payload. A logic-level soft failure the
 *       action returns in its body (e.g. {success:false, message:...}) resolves
 *       normally so callers can branch on it. A transport or HTTP-error failure
 *       REJECTS with a typed Error (err.smTransport === true) — it never resolves
 *       an empty {} to fake success. That soft-failure {} was the root cause of
 *       the backups tab minting duplicate scan jobs and rendering "undefined":
 *       an HTTP error looked like an empty-but-successful response.
 *
 *   smEsc(s)     — HTML-escape a value before it touches innerHTML.
 *   smSafeUrl(u) — return u only if it is an http(s) URL (blocks javascript:).
 *
 * @version 1.0
 */
(function () {
	'use strict';

	function smApiPost(action, params) {
		return joineryApi.post('server_manager/' + action, params || {})
			.catch(function (err) {
				var e = new Error((err && err.message) || 'Request failed');
				e.smTransport = true;
				e.status = err && err.status;
				throw e;
			});
	}

	function smEsc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function smSafeUrl(u) {
		return /^https?:\/\//i.test(String(u || '')) ? String(u) : '';
	}

	window.smApiPost = smApiPost;
	window.smEsc = smEsc;
	window.smSafeUrl = smSafeUrl;
})();
