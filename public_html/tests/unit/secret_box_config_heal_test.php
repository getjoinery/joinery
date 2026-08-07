<?php
/** @joinery-test
 * name: secret_box_config_heal
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * SecretBox::ensureConfigKey() — the update_database self-heal that backfills
 * secret_box_key into Globalvars_site.php on sites installed before the key
 * existed. All checks run against scratch config files; the real site config
 * is never touched.
 *
 * Run: php tests/unit/secret_box_config_heal_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

$dir = sys_get_temp_dir() . '/sbch_test_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);

function sbch_extract_key(string $contents): ?string {
	return preg_match("/settings\\['secret_box_key'\\] = '([^']+)'/", $contents, $m) ? $m[1] : null;
}

try {

section('key generation into a legacy config');

$cfg = $dir . '/Globalvars_site.php';
file_put_contents($cfg, "<?php\n\$this->settings['webDir'] = 'example.com';\n\n?>\n");

$r1 = SecretBox::ensureConfigKey($cfg);
check($r1['ok'] === true && $r1['action'] === 'generated', 'missing key is generated');
$contents = file_get_contents($cfg);
$key = sbch_extract_key($contents);
check($key !== null, 'key assignment written');
$decoded = base64_decode((string)$key, true);
check($decoded !== false && strlen($decoded) === 32, 'key is 32 base64-encoded bytes');
check(strpos($r1['message'], (string)$key) === false, 'key value never appears in the message');
check(strpos($contents, "?>") > strpos($contents, "secret_box_key"),
	'assignment inserted before the closing tag');
$lint = shell_exec('php -l ' . escapeshellarg($cfg) . ' 2>&1');
check(strpos((string)$lint, 'No syntax errors') !== false, 'config file still parses');
check(strpos($contents, "webDir") !== false, 'existing settings preserved');

section('idempotency and protection');

$r2 = SecretBox::ensureConfigKey($cfg);
check($r2['ok'] === true && $r2['action'] === 'present', 'second run reports present');
check(sbch_extract_key(file_get_contents($cfg)) === $key, 'existing key never regenerated');

$cfg2 = $dir . '/custom_key.php';
file_put_contents($cfg2, "<?php\n\$this->settings['secret_box_key'] = 'PRE_EXISTING_VALUE';\n");
SecretBox::ensureConfigKey($cfg2);
check(substr_count(file_get_contents($cfg2), 'secret_box_key') === 1
	&& strpos(file_get_contents($cfg2), 'PRE_EXISTING_VALUE') !== false,
	'pre-existing key assignment untouched');

section('config without closing tag');

$cfg3 = $dir . '/no_close.php';
file_put_contents($cfg3, "<?php\n\$this->settings['webDir'] = 'example.com';\n");
$r3 = SecretBox::ensureConfigKey($cfg3);
check($r3['ok'] === true && sbch_extract_key(file_get_contents($cfg3)) !== null,
	'key appended when file has no closing tag');
$lint3 = shell_exec('php -l ' . escapeshellarg($cfg3) . ' 2>&1');
check(strpos((string)$lint3, 'No syntax errors') !== false, 'no-close config still parses');

section('failure modes are reported, not fatal');

$r4 = SecretBox::ensureConfigKey($dir . '/does_not_exist.php');
check($r4['ok'] === false && $r4['action'] === 'missing_config', 'missing config reported');

$cfg5 = $dir . '/readonly.php';
file_put_contents($cfg5, "<?php\n\$this->settings['webDir'] = 'example.com';\n");
chmod($cfg5, 0400);
$r5 = SecretBox::ensureConfigKey($cfg5);
// Skip on environments running as root, where 0400 files stay writable.
if (is_writable($cfg5)) {
	harness_skip('unwritable config reported', 'process can write read-only files (root)');
} else {
	check($r5['ok'] === false && $r5['action'] === 'unwritable', 'unwritable config reported');
}
chmod($cfg5, 0600);

} finally {
	array_map('unlink', glob($dir . '/*'));
	rmdir($dir);
}

harness_finish();
