#!/usr/bin/php
<?php
/**
 * hosted_ai_settings.php — set or clear this site's hosted AI credit key.
 *
 * A site whose hosting includes AI credit does not open an AI-provider account
 * of its own. Its hosting operator mints one capped key for it and hands that
 * key to this box; the Joinery AI plugin's `hosted` endpoint (ai_endpoints.json)
 * becomes available the moment the key is set and unavailable the moment it is
 * cleared.
 *
 * WHY THIS IS ITS OWN SCRIPT. The setting name is compiled in below, not sent
 * on the wire: what arrives is one VALUE, and where it lands is decided here, on
 * this machine, in a file the release manifest covers. That is the same rule
 * utils/hosted_mail_settings.php explains at length — each credential the
 * management node can push lives in a script with its names compiled in, so a
 * push can reach exactly those settings and nothing else. Folding this key into
 * the mail script would let a mail-credential push change AI settings too, and
 * widen that word for every node; a script of its own keeps each word's reach
 * to the one thing it names.
 *
 * AN EMPTY PUSH IS SAFE HERE, unlike the mail script. The mail settings are the
 * site owner's own fields once written (an owner who outgrows hosted mail types
 * their own credentials into them), so blanking them could wipe what the owner
 * typed. This setting is ours alone: it is a managed setting, never rendered on
 * a form, and an owner who wants their own provider enters that provider's key
 * in its own field. So an empty api_key clears it, and that is how hosting
 * turns hosted AI off on a site (suspension, removal, the end of a plan).
 *
 * THE JOINERY AI PLUGIN MAY BE INACTIVE. The setting is declared in the
 * plugin's plugin.json, and Setting::put accepts a name declared by any plugin
 * present on disk, active or not, so the key lands and waits for the plugin to
 * be switched on (activation only seeds missing rows; it never overwrites). A
 * site whose tree has no joinery_ai plugin at all refuses with that said
 * plainly, rather than minting a row nothing reads.
 *
 * The value arrives as one JSON object on stdin:
 *
 *   php utils/hosted_ai_settings.php <<'EOF'
 *   {"api_key":"…"}
 *   EOF
 *
 * Prints HOSTED_AI_SETTINGS=ok and whether the key was set or cleared, and
 * NEVER the key (job output is stored on the management node and read by
 * people). Exits 0 on success, 2 on unusable input, 1 on a failed write.
 *
 * The work is hosted_ai_settings_apply(), which a test includes this file to
 * call against the test database; run directly, the file reads stdin and exits
 * with its answer.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');

/** The one setting this script writes. This name is the security boundary. */
const HOSTED_AI_KEY_SETTING = 'joinery_ai_hosted_api_key';

/**
 * Write (or clear) the hosted AI key from one JSON object.
 *
 * @return array{0:int,1:string,2:string} exit code, stdout, stderr
 */
function hosted_ai_settings_apply(string $raw): array {
	$supplied = json_decode($raw, true);
	if (!is_array($supplied) || !array_key_exists('api_key', $supplied) || !is_string($supplied['api_key'])) {
		return array(2, '', "HOSTED_AI_SETTINGS=error\nThis script takes {\"api_key\":\"...\"} as a JSON object on stdin.\n");
	}

	// A key is one printable token; anything else is a damaged value that
	// would fail every request with an authentication error.
	$key = trim($supplied['api_key']);
	if ($key !== '' && !preg_match('/^[\x21-\x7e]{1,512}$/', $key)) {
		return array(2, '', "HOSTED_AI_SETTINGS=error\nThe key is not one printable token.\n");
	}

	if (!SettingsDeclarations::isDeclared(HOSTED_AI_KEY_SETTING)) {
		return array(1, '', "HOSTED_AI_SETTINGS=error\nThis site has no Joinery AI plugin, so it has nowhere to keep a hosted AI key. Apply an update that includes it first.\n");
	}

	try {
		Setting::put(HOSTED_AI_KEY_SETTING, $key);
	} catch (Throwable $e) {
		return array(1, '', "HOSTED_AI_SETTINGS=error\n" . $e->getMessage() . "\n");
	}

	// Never the value.
	return array(0, "HOSTED_AI_SETTINGS=ok\nkey=" . ($key === '' ? 'cleared' : 'set') . "\n", '');
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	[$code, $out, $err] = hosted_ai_settings_apply((string)stream_get_contents(STDIN));
	if ($out !== '') echo $out;
	if ($err !== '') fwrite(STDERR, $err);
	exit($code);
}
