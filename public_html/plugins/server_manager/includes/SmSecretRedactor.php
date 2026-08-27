<?php
/**
 * SmSecretRedactor — mask credential material in text bound for the admin UI.
 *
 * Management-job commands carry cloud-target and node-API secrets so the agent
 * can run them, and job output can echo them. Both are shown to permission-10
 * admins on the job-detail page. This redactor masks the secret *values* while
 * leaving structure and key names intact, so the display stays legible.
 *
 * It is a display-time guard, not storage security — the canonical secret
 * stores (bkt_credentials, settings) are SecretBox-encrypted at rest
 * independently. Apply it wherever a persisted command or raw job output is
 * rendered.
 *
 * @version 1.1 - mask shell env-var assignments (PGPASSWORD=..., *_TOKEN=...), the shape a
 *                hand-typed console command carries
 * @version 1.0
 */

class SmSecretRedactor {

	const MASK = '********';

	/**
	 * Credential key names whose value is masked (var_export or JSON shape).
	 *
	 * Longest first where one name is a prefix of another: the alternation is
	 * tried in order, so 'credentials' ahead of 'credentials_b64' would match
	 * the prefix and then fail on the closing quote. Backtracking saves it in
	 * this engine, but the ordering says what is meant without relying on that.
	 */
	private static $secret_keys = array(
		'secret_key', 'access_key', 'application_key', 'app_key',
		'api_secret', 'apk_secret_key', 'password', 'passwd', 'token', 'secret',
		// The storage target's credential, as it travels in a backup job's
		// parameters and in the SSH path's config heredoc. Nothing renders
		// either today, but redaction is the only thing standing between that
		// payload and a support bundle, an export, or a future job view that
		// shows more than a step's label.
		'credentials_b64', 'credentials',
	);

	/**
	 * Return $text with credential values masked. Safe on any string; a value
	 * with no secret material passes through unchanged.
	 */
	public static function redact($text) {
		if (!is_string($text) || $text === '') {
			return $text;
		}

		$keys = implode('|', array_map('preg_quote', self::$secret_keys));

		// Quoted key => 'value' / "value" (var_export and JSON, both separators).
		$text = preg_replace_callback(
			'/([\'"](?:' . $keys . ')[\'"]\s*(?:=>|:)\s*[\'"])([^\'"]*)([\'"])/i',
			function ($m) { return $m[1] . self::MASK . $m[3]; },
			$text
		);

		// Header-style "secret-key: value" carried in API step commands.
		$text = preg_replace('/(secret-key\s*:\s*)([^\s\'"]+)/i', '${1}' . self::MASK, $text);

		// Shell env-var assignments — PGPASSWORD=..., AWS_SECRET_ACCESS_KEY=...,
		// GITHUB_TOKEN=... — the shape a hand-typed console command uses. Matched
		// on the conventional uppercase spelling so ordinary flags (--key=path)
		// are left readable.
		// A name qualifies by containing PASSWORD/PASSWD/TOKEN/SECRET anywhere
		// (AWS_SECRET_ACCESS_KEY), or by ending in _KEY (API_KEY). Bare "KEY"
		// anywhere would swallow SSH_KEY_PATH and similar, which carry paths
		// rather than secrets — a redactor that eats the command is as unhelpful
		// as one that leaks it.
		$text = preg_replace(
			'/\b([A-Z][A-Z0-9_]*(?:PASSWORD|PASSWD|TOKEN|SECRET)[A-Z0-9_]*|[A-Z][A-Z0-9_]*_KEY)=(?!\s)(\'[^\']*\'|"[^"]*"|\S+)/',
			'${1}=' . self::MASK,
			$text
		);

		return $text;
	}
}
