<?php
/** @joinery-test
 * name: agent_redactor_parity
 * tier: safe
 * parallel: true
 * env: dev-only
 * needs: []
 */
/**
 * The agent's redact package and the plane's SmSecretRedactor mask the same
 * credential key names (specs/agent_log_access.md §3). One list read two ways
 * is how a secret gets masked on the plane's screen and not on the node's
 * wire, so the two lists are pinned equal here.
 *
 * Reads the Go source from the agent checkout the management node publishes
 * from (server_manager_agent_source_path, or its default), the way
 * installer_contract_test reads the installers. Skips where the checkout is
 * absent: a production node has no agent source and nothing to compare.
 *
 * Run: php tests/unit/agent_redactor_parity_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$settings = Globalvars::get_instance();
$source = trim((string)$settings->get_setting('server_manager_agent_source_path'));
if ($source === '') {
	$source = '/home/user1/joinery-agent';
}
$keys_go = rtrim($source, '/') . '/redact/keys.go';

section('The agent\'s secret-key list equals SmSecretRedactor\'s');

if (!is_file($keys_go)) {
	check(true, 'skipped: no agent checkout at ' . $keys_go . ' (nothing to compare on this box)');
	harness_finish();
	return;
}

$go = file_get_contents($keys_go);
check(preg_match('/secretKeys\s*=\s*\[\]string\{(.*?)\n\}/s', $go, $m) === 1, 'redact/keys.go declares secretKeys as a []string literal');
$go_keys = array();
if (!empty($m[1])) {
	preg_match_all('/"([^"]+)"/', $m[1], $mm);
	$go_keys = $mm[1];
}

$ref = new ReflectionClass('SmSecretRedactor');
$prop = $ref->getProperty('secret_keys');
$prop->setAccessible(true);
$php_keys = array_values($prop->getValue());

$missing_in_go  = array_values(array_diff($php_keys, $go_keys));
$missing_in_php = array_values(array_diff($go_keys, $php_keys));
check($missing_in_go === array(), 'every key SmSecretRedactor masks is in the agent\'s list', 'missing in Go: ' . implode(', ', $missing_in_go));
check($missing_in_php === array(), 'every key the agent masks is in SmSecretRedactor\'s list', 'missing in PHP: ' . implode(', ', $missing_in_php));
check(count($go_keys) > 5, 'the Go list is not empty (' . count($go_keys) . ' keys)');

harness_finish();
