<?php
/**
 * LogRedactor — mask secrets and personal data in text that leaves the site.
 *
 * The PHP port of the agent's redact package (joinery-agent/redact), rule for
 * rule, so a log line masked on the node by the agent and one masked here by
 * the platform read the same. Used wherever text is about to leave this site
 * or reach someone who should not see its secrets: a problem report's bundle,
 * the management API's recent-errors lines, the plane's job page.
 *
 * text() masks everything the agent's redact.Text() masks:
 *
 *   - credential VALUES named by a key from $secret_keys, in the shapes the
 *     platform writes (var_export, JSON, KEY=value assignments, secret-key
 *     headers, --clone-key= flags), a URL's userinfo password, and a bearer
 *     token; the key name stays so the line still reads;
 *   - the personal half of an email address: local@example.com becomes
 *     <email>@example.com;
 *   - IPv4 and IPv6 literals, which become <ip>;
 *   - standalone opaque tokens (32 or more hex characters mixing digits and
 *     letters, or 32 or more base64 characters carrying digits and both
 *     cases), which become <token>.
 *
 * secrets() masks only the credential shapes (no URL userinfo, bearer token,
 * email, IP or token): the display-time pass the server manager's job page
 * has always applied, which SmSecretRedactor delegates to.
 *
 * Version strings, timestamps, file paths, setting names and class names pass
 * through unchanged; a redactor that eats the diagnosis is as useless as one
 * that leaks it.
 *
 * Free-text redaction masks SHAPES. A member's name inside an exception
 * message is not a shape and passes. That is why a problem report shows the
 * reporter everything it will send before it is sent.
 *
 * The key list is pinned equal to the agent's redact/keys.go by
 * tests/unit/agent_redactor_parity_test.php.
 *
 * @version 1.0.1 - the clone keys are named as the retired Clone's leftovers
 * @version 1.0.0
 */
class LogRedactor {

	const MASK = '********';

	/**
	 * Credential key names whose value is masked. Mirrors the agent's
	 * secretKeys entry for entry and in the same order.
	 *
	 * Longest first where one name is a prefix of another: the alternation is
	 * tried in order, so 'credentials' ahead of 'credentials_b64' would match
	 * the prefix and then fail on the closing quote. Backtracking saves it in
	 * this engine, but the ordering says what is meant without relying on that.
	 */
	private static $secret_keys = array(
		'secret_key', 'access_key', 'application_key', 'app_key', 'api_key',
		'api_secret', 'apk_secret_key', 'password', 'passwd', 'token', 'secret',
		// A bearer token and a dump password, in the job rows the retired
		// Clone left on a management node.
		'export_key', 'clone_key',
		// A storage target's credential, as it travels in a backup job's
		// parameters and in a config heredoc.
		'credentials_b64', 'credentials',
	);

	/** The credential key names, for the parity test and for callers that list them. */
	public static function secretKeys(): array {
		return self::$secret_keys;
	}

	/**
	 * $text with credential values, the personal half of email addresses, IP
	 * literals and opaque tokens masked. Safe on any string; a string carrying
	 * none of them comes back unchanged. A non-string is returned as is.
	 */
	public static function text($text) {
		if (!is_string($text) || $text === '') {
			return $text;
		}

		// scheme://user:password@host — the password half of a URL's userinfo.
		$text = preg_replace('~([A-Za-z][A-Za-z0-9+.\-]*://[^\s:/@]+:)[^\s@/]+@~', '${1}' . self::MASK . '@', $text);

		$text = self::secrets($text);

		// Authorization: Bearer <token>.
		$text = preg_replace('~\b(bearer\s+)[A-Za-z0-9._\~+/\-]+=*~i', '${1}' . self::MASK, $text);

		// local@domain; the domain is what a mail diagnosis needs, so it stays.
		$text = preg_replace('~[A-Za-z0-9._%+\-]+@((?:[A-Za-z0-9\-]+\.)+[A-Za-z]{2,})\b~', '<email>@${1}', $text);

		// IPv6: the full eight-group form, or a form carrying "::" with groups
		// in front; with nothing in front (::1) a group must follow and no word
		// character or colon may precede, so PathHelper::getThemeFilePath and
		// the any-address [::] are left alone.
		$text = preg_replace('~\b(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}\b~', '<ip>', $text);
		$text = preg_replace('~\b(?:[0-9a-fA-F]{1,4}:){1,7}:(?:[0-9a-fA-F]{1,4}(?::[0-9a-fA-F]{1,4}){0,6})?~', '<ip>', $text);
		$text = preg_replace('~(^|[^A-Za-z0-9_:])::[0-9a-fA-F]{1,4}(?::[0-9a-fA-F]{1,4}){0,6}~', '${1}<ip>', $text);

		// IPv4: four dotted octets. A version string has three parts.
		$text = preg_replace('~\b(?:\d{1,3}\.){3}\d{1,3}\b~', '<ip>', $text);

		// Opaque tokens: a hex run mixing digits and letters, and a base64 run
		// carrying a digit and both cases — so a long number, a run of one
		// letter or a long CamelCase class name is never a "token".
		$text = preg_replace_callback('~\b[0-9a-fA-F]{32,}\b~', function ($m) {
			$digit = preg_match('~[0-9]~', $m[0]) === 1;
			$letter = preg_match('~[a-fA-F]~', $m[0]) === 1;
			return ($digit && $letter) ? '<token>' : $m[0];
		}, $text);
		$text = preg_replace_callback('~(^|[^A-Za-z0-9+/_\-])([A-Za-z0-9+]{32,}={0,2})(\z|[^A-Za-z0-9+/_\-])~', function ($m) {
			$run = $m[2];
			if (preg_match('~[0-9]~', $run) && preg_match('~[A-Z]~', $run) && preg_match('~[a-z]~', $run)) {
				return $m[1] . '<token>' . $m[3];
			}
			return $m[0];
		}, $text);

		return $text;
	}

	/**
	 * Every string value in $value masked with text(), descending into arrays.
	 * Values of other types are left alone: a number or a bool is not text a
	 * person wrote.
	 */
	public static function fields($value) {
		if (is_string($value)) {
			return self::text($value);
		}
		if (is_array($value)) {
			foreach ($value as $k => $v) {
				$value[$k] = self::fields($v);
			}
		}
		return $value;
	}

	/**
	 * $text with credential values masked, and nothing else. Safe on any
	 * string; a value with no secret material passes through unchanged.
	 */
	public static function secrets($text) {
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

		// Header-style "secret-key: value".
		$text = preg_replace('/(secret-key\s*:\s*)([^\s\'"]+)/i', '${1}' . self::MASK, $text);

		// The bootstrap's --clone-key=KEY flag (a quoted or bare value).
		$text = preg_replace('/(--clone-key=)(\'[^\']*\'|"[^"]*"|\S+)/', '${1}' . self::MASK, $text);

		// Assignments: PGPASSWORD=..., AWS_SECRET_ACCESS_KEY=..., GITHUB_TOKEN=...
		// (a console command) and, in any case, password=..., dbpassword=...,
		// csrf_token=..., api_key=... (a DSN, a query string or a config dump).
		// A name qualifies by containing PASSWORD/PASSWD/TOKEN/SECRET anywhere,
		// in any case, or by being one of the secret keys, in any case.
		$text = preg_replace(
			'/\b([A-Za-z][A-Za-z0-9_]*(?:password|passwd|token|secret)[A-Za-z0-9_]*|(?:' . $keys . '))=(?!\s)(\'[^\']*\'|"[^"]*"|\S+)/i',
			'${1}=' . self::MASK,
			$text
		);
		// A name ending in _KEY qualifies only in the conventional uppercase
		// spelling (API_KEY, DEPLOY_KEY). Lowercase names ending in _key are
		// the shape of flags and column names (--key=path,
		// primary_key=usr_user_id), which carry no secret. Bare KEY anywhere
		// would swallow SSH_KEY_PATH, which is a path.
		$text = preg_replace(
			'/\b([A-Z][A-Z0-9_]*_KEY)=(?!\s)(\'[^\']*\'|"[^"]*"|\S+)/',
			'${1}=' . self::MASK,
			$text
		);

		return $text;
	}
}
