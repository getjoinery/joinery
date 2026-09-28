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
 * @version 1.3 - the key list and the masking live in core LogRedactor (secrets()), which this
 *                delegates to; behavior is unchanged
 * @version 1.2 - the assignment shape is masked in any case for names carrying password/passwd/token/secret
 *                and for the secret keys themselves (password=..., api_key=... inside a log line);
 *                api_key joins the key list; the _KEY tail stays uppercase-only so flags and column
 *                names stay readable. Mirrored in the agent's redact package (1.35.1)
 * @version 1.1 - mask shell env-var assignments (PGPASSWORD=..., *_TOKEN=...), the shape a
 *                hand-typed console command carries
 * @version 1.0
 */

class SmSecretRedactor {

	const MASK = LogRedactor::MASK;

	/**
	 * Return $text with credential values masked. Safe on any string; a value
	 * with no secret material passes through unchanged. The key list and the
	 * shapes are LogRedactor's, the same ones the agent masks on the node.
	 */
	public static function redact($text) {
		return LogRedactor::secrets($text);
	}
}
