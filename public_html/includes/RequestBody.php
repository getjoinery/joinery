<?php
/**
 * RequestBody - what to tell a caller whose request body is not there.
 *
 * Two different events leave PHP with an empty body while Content-Length
 * still reports what was sent:
 *
 *   - the body was over post_max_size, and PHP discarded it before any code
 *     ran (413, RequestTooLarge);
 *   - the body never arrived in full: Apache gave up waiting for it
 *     (mod_reqtimeout's body limit) or the sender went away, and PHP was
 *     handed the request without it (400, RequestBodyIncomplete).
 *
 * Telling them apart is a size comparison. Reporting the second as the first
 * names a limit the caller never reached and hides a stalled connection
 * (specs/slow_request_diagnosis_words.md WP1 B4: "Content-Length 2 exceeds
 * post_max_size 32M").
 *
 * @version 1.0
 */
class RequestBody {

	/** An INI size ("32M", "1G", "8192") in bytes; 0 when unset or unlimited. */
	public static function ini_bytes($value): int {
		$value = trim((string)$value);
		if ($value === '' || !preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m)) {
			return 0;
		}
		$n = (int)$m[1];
		switch (strtoupper($m[2])) {
			case 'G': $n *= 1024;
			// no break
			case 'M': $n *= 1024;
			// no break
			case 'K': $n *= 1024;
		}
		return $n;
	}

	/**
	 * The refusal for a request whose declared body is missing.
	 *
	 * @param int $content_length what Content-Length declared
	 * @return array{error_type: string, status: int, message: string, note: string}
	 */
	public static function missing_body_refusal(int $content_length): array {
		$limit_text  = (string)ini_get('post_max_size');
		$limit_bytes = self::ini_bytes($limit_text);
		if ($limit_bytes > 0 && $content_length > $limit_bytes) {
			return [
				'error_type' => 'RequestTooLarge',
				'status'     => 413,
				'message'    => 'That request was too large for this server to accept (limit ' . $limit_text
					. '). Nothing was received, so nothing was changed.',
				'note'       => 'Content-Length ' . $content_length . ' exceeds post_max_size ' . $limit_text,
			];
		}
		return [
			'error_type' => 'RequestBodyIncomplete',
			'status'     => 400,
			'message'    => "The request's body did not arrive in full. Nothing was changed.",
			'note'       => 'Content-Length ' . $content_length . ' declared, body not received',
		];
	}
}
