/**
 * MailboxFilterMatch - a mailbox's mail rules evaluated on the device that
 * opens a relay-sealed message (specs/fortress_mobile_apps.md § R14). The
 * server cannot read such a message, so the device that parses it (the
 * browser, or a phone app with its own port) decides which rules match and
 * posts their ids; the server applies those rules' actions itself.
 *
 * A port of InboundEmailFilter::matches(), with PHP's semantics where
 * JavaScript's nearest equivalent differs: trim()'s character set,
 * preg_split('/\s+/u'), intval(). plugins/mailbox/tests/fixtures/
 * filter_match_cases.json holds cases whose expected ids the PHP matcher
 * wrote (port_vectors_test.php); port_vectors.mjs replays them here.
 *
 *   matches(rule, message) -> bool
 *   matchingIds(rules, message) -> the ids of the rules that match, in the
 *     order given (the server lists rules in the order it evaluates them)
 *
 *   rule: {id, match: {from, to, subject, has_words, excludes,
 *     size_op ('' | 'gt' | 'lt'), size_bytes, has_attachment}}, as
 *     mailbox/device_rules lists it. A rule with no criterion matches every
 *     message, as matches() does (a rule cannot be saved that way).
 *   message: {sender, recipient, subject, body_plain, body_html, size_bytes,
 *     has_attachment} - the parse's plaintext, the row's clear recipient and
 *     size, and whether the parse found any part at all.
 *
 * No DOM, no network: the same file runs under Node.
 *
 * @version 1.0
 */
(function (root) {
	'use strict';

	var PHP_TRIM = ' \t\n\r\0\x0B';
	// PCRE's \s with /u, as PHP matches it (email-digest.js carries the same set).
	var S_UCP = /[\t\n\x0B\f\r \u0085\u00A0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]+/u;
	var NUMERIC_PREFIX = /^[ \t\n\r\x0B\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/;

	function str(v) {
		if (v === null || v === undefined || v === false) return '';
		if (v === true) return '1';
		return String(v);
	}

	function trim(s) {
		s = str(s);
		var a = 0, b = s.length;
		while (a < b && PHP_TRIM.indexOf(s.charAt(a)) !== -1) a++;
		while (b > a && PHP_TRIM.indexOf(s.charAt(b - 1)) !== -1) b--;
		return s.slice(a, b);
	}

	function lower(s) { return str(s).toLowerCase(); }

	function intval(v) {
		if (typeof v === 'number') return isFinite(v) ? Math.trunc(v) : 0;
		if (v === true) return 1;
		if (v === null || v === undefined || v === false) return 0;
		var m = NUMERIC_PREFIX.exec(String(v));
		return m ? Math.trunc(parseFloat(m[0])) : 0;
	}

	/** True if any comma-separated term of list is a substring of hay. */
	function anyTermIn(list, hay) {
		var terms = list.split(',');
		for (var i = 0; i < terms.length; i++) {
			var t = lower(trim(terms[i]));
			if (t !== '' && hay.indexOf(t) !== -1) return true;
		}
		return false;
	}

	function tokens(phrase) {
		var out = [];
		var parts = trim(phrase).split(S_UCP);
		for (var i = 0; i < parts.length; i++) {
			var t = lower(trim(parts[i]));
			if (t !== '') out.push(t);
		}
		return out;
	}

	function matches(rule, m) {
		var c = (rule && rule.match) || {};
		m = m || {};
		var senderRaw = str(m.sender), subjectRaw = str(m.subject);
		var sender = lower(senderRaw);
		var recipient = lower(m.recipient);
		var subject = lower(subjectRaw);
		var hay = lower(senderRaw + ' ' + subjectRaw + ' ' + str(m.body_plain) + ' ' + str(m.body_html));

		var from = trim(c.from);
		if (from !== '' && !anyTermIn(from, sender)) return false;

		var to = trim(c.to);
		if (to !== '' && !anyTermIn(to, recipient)) return false;

		var subj = trim(c.subject);
		if (subj !== '' && subject.indexOf(lower(subj)) === -1) return false;

		var words = trim(c.has_words);
		if (words !== '') {
			var w = tokens(words);
			for (var i = 0; i < w.length; i++) if (hay.indexOf(w[i]) === -1) return false;
		}

		var excl = trim(c.excludes);
		if (excl !== '') {
			var x = tokens(excl);
			for (var j = 0; j < x.length; j++) if (hay.indexOf(x[j]) !== -1) return false;
		}

		var op = str(c.size_op);
		var bytes = intval(c.size_bytes);
		if ((op === 'gt' || op === 'lt') && bytes > 0) {
			var size = intval(m.size_bytes);
			if (op === 'gt' && !(size > bytes)) return false;
			if (op === 'lt' && !(size < bytes)) return false;
		}

		if (c.has_attachment === true && m.has_attachment !== true) return false;

		return true;
	}

	function matchingIds(rules, m) {
		var out = [];
		for (var i = 0; i < (rules || []).length; i++) {
			if (matches(rules[i], m)) out.push(intval(rules[i].id));
		}
		return out;
	}

	var api = { matches: matches, matchingIds: matchingIds };
	root.MailboxFilterMatch = api;
	if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : typeof self !== 'undefined' ? self : this);
