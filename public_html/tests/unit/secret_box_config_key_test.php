<?php
/** @joinery-test
 * name: secret_box_config_key
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/_config_secrets.sh]
 */
/**
 * secret_box_key: the PHP side reports, the root side mints.
 *
 * The key lives in config/Globalvars_site.php, which is PHP the pool
 * `require`s. A process that can rewrite that file can put anything in the
 * site's boot path, so the web user does not write it — minting happens as root
 * at the host installers' moments, and SecretBox only says which state a site is
 * in (specs/read_only_tree.md).
 *
 * Both halves are checked here: SecretBox::checkConfigKey() must report and
 * never write, and the shell that does the minting must produce a config file
 * that parses, on each of the shapes a real one takes. All checks run against
 * scratch files; the real site config is never touched.
 *
 * Run: php tests/unit/secret_box_config_key_test.php
 *
 * @version 1.1 - the backup site key: a seed is minted, an old X25519 keypair moved to .retired
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = sys_get_temp_dir() . '/sbck_test_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);

function sbck_key(string $contents): ?string {
	return preg_match('/secret_box_key["\']\]\s*=\s*["\']([^"\']+)["\']/', $contents, $m) ? $m[1] : null;
}

/**
 * Run one function from _config_secrets.sh against a scratch file.
 *
 * The root gate is stripped: the harness is not root and must never be, and
 * what is under test here is the edit the function makes, not the gate. The
 * gate itself is checked separately, unstripped.
 */
function sbck_shell(string $fn, string $arg, bool $strip_root_gate = true): string {
	$src = file_get_contents(dirname(PathHelper::getRootDir())
		. '/maintenance_scripts/install_tools/_config_secrets.sh');
	if ($strip_root_gate) {
		$src = str_replace('[[ "$(id -u)" == "0" ]] || return 1', ':', $src);
	}
	$script = tempnam(sys_get_temp_dir(), 'sbck');
	file_put_contents($script, $src . "\n" . $fn . ' ' . escapeshellarg($arg) . "\n");
	$out = (string)shell_exec('bash ' . escapeshellarg($script) . ' 2>&1');
	@unlink($script);
	return $out;
}

section('SecretBox reports and never writes');

$cfg = $dir . '/Globalvars_site.php';
file_put_contents($cfg, "<?php\n\$this->settings['webDir'] = 'example.com';\n\n?>\n");
$before = file_get_contents($cfg);

$r = SecretBox::checkConfigKey($cfg);
check($r['ok'] === false && $r['action'] === 'awaiting_root',
	'a config with no key reports that it is waiting on the root actor');
check(file_get_contents($cfg) === $before,
	'and the file is byte-for-byte unchanged',
	'the web user writing the code it boots is the whole thing this closes');
check(stripos($r['message'], 'converger') !== false,
	'the message names what will mint it, so the wait is legible');

file_put_contents($cfg, "<?php\n\$this->settings['secret_box_key'] = 'PRE_EXISTING';\n");
$r = SecretBox::checkConfigKey($cfg);
check($r['ok'] === true && $r['action'] === 'present', 'an existing key reports present');

$r = SecretBox::checkConfigKey($dir . '/does_not_exist.php');
check($r['ok'] === false && $r['action'] === 'missing_config', 'a missing config is reported, not fatal');

// The write is gone from the class, not merely unreached: a later edit that
// restores it would put the pool back in the business of editing its own boot
// path, and this is the check that would catch it.
$sb_src = file_get_contents(PathHelper::getIncludePath('includes/SecretBox.php'));
check(strpos($sb_src, 'file_put_contents') === false,
	'SecretBox writes no file at all',
	'the key is minted at a root moment, never from a web request');

section('The root side mints a config that still parses');

// A config with a closing tag. Anything after that tag is emitted as page
// output, so where the block lands is not cosmetic.
$c1 = $dir . '/with_close.php';
file_put_contents($c1, "<?php\n\$this->settings['webDir'] = 'example.com';\n\n?>\n");
sbck_shell('joinery_mint_secret_box_key', $c1);
$body = file_get_contents($c1);
$key = sbck_key($body);
check($key !== null, 'a key is written');
$decoded = base64_decode((string)$key, true);
check($decoded !== false && strlen($decoded) === 32, 'it is 32 base64-encoded bytes');
check(strpos($body, '?>') > strpos($body, 'secret_box_key'),
	'the assignment lands before the closing tag');
check(strpos((string)shell_exec('php -l ' . escapeshellarg($c1) . ' 2>&1'), 'No syntax errors') !== false,
	'the config still parses');
check(strpos($body, 'webDir') !== false, 'existing settings are preserved');

// And one without.
$c2 = $dir . '/no_close.php';
file_put_contents($c2, "<?php\n\$this->settings['webDir'] = 'example.com';\n");
sbck_shell('joinery_mint_secret_box_key', $c2);
check(sbck_key(file_get_contents($c2)) !== null, 'a config with no closing tag is appended to');
check(strpos((string)shell_exec('php -l ' . escapeshellarg($c2) . ' 2>&1'), 'No syntax errors') !== false,
	'and still parses');

section('Minting never runs twice over a live key');

// This is the one that matters: a second mint over an existing key orphans
// every secret the first one encrypted — sealed vault wrappings, stored
// credentials, DKIM keys — and it looks like a clean run.
sbck_shell('joinery_mint_secret_box_key', $c1);
check(sbck_key(file_get_contents($c1)) === $key, 'an existing key is never regenerated');
check(substr_count(file_get_contents($c1), 'secret_box_key') === 1,
	'and no second assignment is added');

$c3 = $dir . '/preexisting.php';
file_put_contents($c3, "<?php\n\$this->settings['secret_box_key'] = 'PRE_EXISTING_VALUE';\n");
sbck_shell('joinery_mint_secret_box_key', $c3);
check(strpos(file_get_contents($c3), 'PRE_EXISTING_VALUE') !== false
	&& substr_count(file_get_contents($c3), 'secret_box_key') === 1,
	'a key already in place is left exactly as it was');

section('Minting is root-only');

$c4 = $dir . '/needs_root.php';
file_put_contents($c4, "<?php\n\$this->settings['webDir'] = 'example.com';\n");
sbck_shell('joinery_mint_secret_box_key', $c4, false);
check(sbck_key(file_get_contents($c4)) === null,
	'an unprivileged caller mints nothing',
	'config/ belongs to the tree owner; a non-root mint would mean the pool could write it');

section('The backup site key is an X-Wing seed, and an old keypair is moved aside');

$kd = $dir . '/keys';
mkdir($kd, 0700);
sbck_shell('joinery_mint_backup_site_key', $kd);
$seed = base64_decode(trim((string)@file_get_contents($kd . '/backup_site_key')), true);
check($seed !== false && strlen($seed) === 32, 'a fresh site key is base64 of a 32-byte seed');
$minted = file_get_contents($kd . '/backup_site_key');
sbck_shell('joinery_mint_site_secrets', $dir);   // config_dir = $dir/config, absent: mints nothing there
sbck_shell('joinery_retire_old_backup_site_key', $kd);
check(file_get_contents($kd . '/backup_site_key') === $minted && !file_exists($kd . '/backup_site_key.retired'),
	'a seed is left exactly where it is');

$old = base64_encode(sodium_crypto_box_keypair());
file_put_contents($kd . '/backup_site_key', $old);
$site = $dir . '/site';
mkdir($site . '/config', 0700, true);
copy($kd . '/backup_site_key', $site . '/config/backup_site_key');
$out = sbck_shell('joinery_mint_site_secrets', $site);
check(file_get_contents($site . '/config/backup_site_key.retired') === $old,
	'an old 64-byte keypair is renamed to backup_site_key.retired, unchanged', $out);
$new = base64_decode(trim((string)@file_get_contents($site . '/config/backup_site_key')), true);
check($new !== false && strlen($new) === 32, 'and a seed is minted in its place on the same converge');
check(strpos($out, 'moved the old X25519 backup_site_key aside') !== false, 'the converge says so');

file_put_contents($site . '/config/backup_site_key', $old);
sbck_shell('joinery_mint_site_secrets', $site);
check(file_get_contents($site . '/config/backup_site_key.retired') === $old
	&& count(glob($site . '/config/backup_site_key.retired.*')) === 1,
	'a second old key never overwrites the first .retired file');

file_put_contents($kd . '/backup_site_key', $old);
sbck_shell('joinery_retire_old_backup_site_key', $kd, false);
check(file_get_contents($kd . '/backup_site_key') === $old, 'moving a key aside is root-only too');

exec('rm -rf ' . escapeshellarg($dir));

harness_finish();
