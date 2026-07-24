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
 * @version 1.0
 */

class SmSecretRedactor {

	const MASK = '********';

	/** Credential key names whose value is masked (var_export or JSON shape). */
	private static $secret_keys = array(
		'secret_key', 'access_key', 'application_key', 'app_key',
		'api_secret', 'apk_secret_key', 'password', 'passwd', 'token', 'secret',
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

		return $text;
	}
}
