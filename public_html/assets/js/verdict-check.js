/**
 * A model's answer turned into a verdict, in the browser — the port of
 * PipelineRunner::parseVerdict(), DescriptorValidator::coerce() and each
 * device-capable job's validateVerdict() (specs/fortress_mail_device_ai.md
 * § R5).
 *
 * The same answer must be accepted or refused, with the same words, whether
 * the server's runner or the owner's browser read it: a refusal's message is
 * what the one retry feeds back to the model. So: any <think>…</think> block
 * is dropped, the FIRST balanced {...} object is taken (braces inside quoted
 * strings do not count), it is coerced against the job's verdict descriptor
 * with PHP's rules (a JSON 8.0 is an integer, a numeric string is too; lengths
 * are in code points), and the job's own cross-field rule runs last (the
 * security scan's score must agree with its verdict word).
 *
 * Exposes window.VerdictCheck:
 *   parse(text, descriptor, jobId) -> {verdict} or {error}
 *   retryMessage(error)             the one follow-up the runner sends
 *
 * Vanilla JS, no framework. @version 1.0
 */
(function () {
	'use strict';

	function cps(s) { return Array.from(String(s)); }
	var PHP_TRIM = ' \t\n\r\0\x0B';
	function trim(s) {
		s = String(s);
		var a = 0, b = s.length;
		while (a < b && PHP_TRIM.indexOf(s.charAt(a)) !== -1) a++;
		while (b > a && PHP_TRIM.indexOf(s.charAt(b - 1)) !== -1) b--;
		return s.slice(a, b);
	}

	function Invalid(message) { this.message = message; }

	/** PipelineRunner::extractFirstJsonObject(). */
	function extractFirstJsonObject(text) {
		var start = text.indexOf('{');
		if (start === -1) return null;
		var depth = 0, inString = false, escaped = false;
		for (var i = start; i < text.length; i++) {
			var ch = text.charAt(i);
			if (escaped) { escaped = false; continue; }
			if (ch === '\\') { escaped = true; continue; }
			if (ch === '"') { inString = !inString; continue; }
			if (inString) continue;
			if (ch === '{') depth++;
			else if (ch === '}') {
				depth--;
				if (depth === 0) return text.slice(start, i + 1);
			}
		}
		return null;
	}

	function isList(v) { return Array.isArray(v); }
	function isArrayish(v) { return v !== null && typeof v === 'object'; }
	function isScalar(v) { return typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean'; }
	function isNumericString(v) { return typeof v === 'string' && /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test(v); }
	function phpString(v) {
		if (typeof v === 'boolean') return v ? '1' : '';
		return String(v);
	}

	/** DescriptorValidator::coerceValue(). */
	function coerceValue(value, type, field, label) {
		switch (type) {
			case 'int':
			case 'integer':
				if (typeof value === 'number' && Number.isInteger(value)) return value;
				if (typeof value === 'string' && /^-?\d+$/.test(value)) return parseInt(value, 10);
				throw new Invalid(label + ' (' + field + ') must be an integer.');
			case 'float':
			case 'number':
				if (typeof value === 'number') return value;
				if (isNumericString(value)) return parseFloat(value);
				throw new Invalid(label + ' (' + field + ') must be a number.');
			case 'bool':
			case 'boolean':
				if (typeof value === 'boolean') return value;
				if (value === 1 || value === '1' || value === 'true' || value === 'on') return true;
				if (value === 0 || value === '0' || value === 'false' || value === 'off') return false;
				throw new Invalid(label + ' (' + field + ') must be a boolean.');
			case 'object':
				if (!isArrayish(value)) throw new Invalid(label + ' (' + field + ') must be an object.');
				return value;
			default:
				if (typeof value === 'string') return value;
				if (isScalar(value)) return phpString(value);
				throw new Invalid(label + ' (' + field + ') must be a string.');
		}
	}

	/** DescriptorValidator::checkBounds(). */
	function checkBounds(value, spec, type, field, label) {
		if (Array.isArray(spec.enum) && spec.enum.length && spec.enum.indexOf(value) === -1) {
			throw new Invalid(label + ' (' + field + ') must be one of: ' + spec.enum.join(', ') + '.');
		}
		if (['int', 'integer', 'float', 'number'].indexOf(type) !== -1) {
			if (spec.min !== undefined && spec.min !== null && value < spec.min) throw new Invalid(label + ' (' + field + ') must be at least ' + spec.min + '.');
			if (spec.max !== undefined && spec.max !== null && value > spec.max) throw new Invalid(label + ' (' + field + ') must be at most ' + spec.max + '.');
		}
		if (['string', 'text', 'password'].indexOf(type) !== -1 && spec.max_length !== undefined && spec.max_length !== null) {
			if (cps(value).length > parseInt(spec.max_length, 10)) {
				throw new Invalid(label + ' (' + field + ') must be at most ' + spec.max_length + ' characters.');
			}
		}
	}

	/** DescriptorValidator::coerce(). */
	function coerce(descriptor, input) {
		var schema = (descriptor && isArrayish(descriptor.input)) ? descriptor.input : {};
		var out = {};
		Object.keys(schema).forEach(function (field) {
			var spec = schema[field];
			if (!isArrayish(spec)) return;
			var type = spec.type || 'string';
			var required = !!spec.required;
			var label = spec.label || field;
			var present = isArrayish(input) && Object.prototype.hasOwnProperty.call(input, field);
			var value = present ? input[field] : null;
			if (!present || value === null || value === '' || (type === 'array' && isList(value) && value.length === 0)) {
				if (required) throw new Invalid('Missing required field: ' + label + ' (' + field + ').');
				if (Object.prototype.hasOwnProperty.call(spec, 'default')) out[field] = spec['default'];
				return;
			}
			if (type === 'array') {
				out[field] = coerceArray(value, spec, field, label);
				return;
			}
			var coerced = coerceValue(value, type, field, label);
			checkBounds(coerced, spec, type, field, label);
			out[field] = coerced;
		});
		return out;
	}

	function coerceArray(value, spec, field, label) {
		if (!isList(value)) throw new Invalid(label + ' (' + field + ') must be an array.');
		if (spec.max_items !== undefined && value.length > parseInt(spec.max_items, 10)) {
			throw new Invalid(label + ' (' + field + ') must have at most ' + spec.max_items + ' items.');
		}
		var itemSchema = isArrayish(spec.items) ? spec.items : {};
		var scalarItems = typeof itemSchema.type === 'string';
		return value.map(function (item, i) {
			if (scalarItems) {
				var c = coerceValue(item, itemSchema.type, field + '[' + i + ']', label + ' #' + i);
				checkBounds(c, itemSchema, itemSchema.type, field + '[' + i + ']', label + ' #' + i);
				return c;
			}
			if (!isArrayish(item)) throw new Invalid(label + ' (' + field + ')[' + i + '] must be an object.');
			return coerce({ input: itemSchema }, item);
		});
	}

	/** Each device-capable job's validateVerdict(). */
	function validateJob(jobId, verdict) {
		if (jobId !== 'email_security_scan') return;
		var score = typeof verdict.score === 'number' ? verdict.score : -1;
		var expected = score >= 7 ? 'dangerous' : (score >= 5 ? 'caution' : 'safe');
		var word = String(verdict.verdict || '');
		if (word !== expected) {
			throw new Invalid("verdict ('" + word + "') does not match the required band for score " + score
				+ " ('" + expected + "'). 0-4=safe, 5-6=caution, 7-10=dangerous.");
		}
	}

	function parse(text, descriptor, jobId) {
		var stripped = trim(String(text || '').replace(/<think>[\s\S]*?<\/think>/g, ''));
		var json = extractFirstJsonObject(stripped);
		if (json === null) return { error: 'no JSON object found in the model response' };
		var decoded;
		try { decoded = JSON.parse(json); } catch (e) { return { error: 'model response was not valid JSON: Syntax error' }; }
		if (!isArrayish(decoded)) return { error: 'model response was not valid JSON: Syntax error' };
		try {
			var verdict = coerce(descriptor, decoded);
			validateJob(jobId, verdict);
			return { verdict: verdict };
		} catch (e) {
			if (e instanceof Invalid) return { error: e.message };
			throw e;
		}
	}

	function retryMessage(error) {
		return 'That response was invalid: ' + error + '\n\nRespond again with ONLY the corrected JSON object.';
	}

	window.VerdictCheck = { parse: parse, retryMessage: retryMessage, _coerce: coerce };
})();
