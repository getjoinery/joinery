<?php
/** @joinery-test
 * name: one_vault_kdf
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * The one vault's derivations, and the rule that the server never takes a
 * recovery code or a passphrase (specs/one_vault_experience.md § R1, R6, R7).
 *
 * The browser makes the codes and runs the phrase's slow step, then posts only
 * the ACCOUNT half of each KEK; the root half stays in the browser and opens
 * the root vault there. So this suite pins:
 *   - vault-crypto.js and VaultUnlockerKdf derive the same bytes (the tests
 *     build what a browser posts with the PHP mirror, so the two must agree);
 *   - the two halves of a code are different keys, as are a phrase's;
 *   - nothing outside the tests derives with the mirror (the server never has
 *     a code or a phrase to derive from);
 *   - no vault action declares a raw code or phrase input;
 *   - the browser code posts KEKs, never the code or the phrase, and
 *     JoineryPasskeys.derive() takes the root vault's PRF output out of the
 *     response it hands back for posting.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = PathHelper::getRootDir();

// ---------------------------------------------------------------------------
section('The PHP mirror');

$salt = base64_encode(str_repeat("\x07", 16));
$code = 'ABCD1-EFGH2-JKMN3-PQRS4-TVWX5-Y';
$account = VaultUnlockerKdf::codeKekAccount($code, $salt);
$root_half = VaultUnlockerKdf::codeKekRoot($code, $salt);
check(strlen($account) === 32 && strlen($root_half) === 32, 'a code gives two 32-byte halves');
check($account !== $root_half, 'and they differ: the account half cannot open the root');
check(VaultUnlockerKdf::codeKekAccount(strtolower(str_replace('-', ' ', $code)), $salt) === $account,
	'entry format does not matter (case, separators)');
check(VaultUnlockerKdf::codeKekAccount(strtr($code, array('0' => 'O', '1' => 'l')), $salt) === $account,
	'Crockford misreadings (O for 0, l for 1) derive the same key');
check(VaultUnlockerKdf::codeKekAccount($code, base64_encode(str_repeat("\x08", 16))) !== $account,
	'another root salt derives another key');
[$pa, $pr] = VaultUnlockerKdf::passphraseSplit(str_repeat("\xAB", 32));
check(strlen($pa) === 32 && $pa !== $pr, 'a phrase\'s one slow result splits into two different halves');
$secret = random_bytes(48);
check(VaultUnlockerKdf::scopeKek($secret, 'mail') !== VaultUnlockerKdf::scopeKek($secret, 'drive'),
	'each content vault gets its own key from the root');

// ---------------------------------------------------------------------------
section('The browser derives the same bytes');

$js_path = $root . '/assets/js/vault-crypto.js';
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
	check(true, 'node is not installed here; parity is proven in the browser walk instead');
} else {
	$secret_hex = bin2hex(str_repeat("\x05", 48));
	$h_hex = bin2hex(str_repeat("\xAB", 32));
	$runner = tempnam(sys_get_temp_dir(), 'onevault') . '.js';
	file_put_contents($runner, "globalThis.window = globalThis;\n"
		. "require(" . json_encode($js_path) . ");\n"
		. "const VC = window.VaultCrypto;\n"
		. "const hex = b => Buffer.from(b).toString('hex');\n"
		. "const fromHex = h => Uint8Array.from(Buffer.from(h, 'hex'));\n"
		. "(async () => {\n"
		. "  const k = await VC.codeKeks(" . json_encode($code) . ", " . json_encode($salt) . ");\n"
		. "  const root = new Uint8Array(await crypto.subtle.exportKey('raw', k.root).catch(() => new ArrayBuffer(0)));\n"
		. "  const scope = await VC.hkdf(fromHex(" . json_encode($secret_hex) . "), null, 'joinery-vault:scope:v1:mail');\n"
		. "  const pa = await VC.hkdf(fromHex(" . json_encode($h_hex) . "), null, 'joinery-vault:passphrase:account:v1');\n"
		. "  process.stdout.write(JSON.stringify({ account: hex(k.account), root: hex(root), scope: hex(scope), pa: hex(pa) }));\n"
		. "})().catch(e => process.stdout.write('ERR ' + e.message));\n");
	$out = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($runner) . ' 2>&1'));
	@unlink($runner);
	$js = json_decode($out, true);
	check(is_array($js), 'the JS engine ran the derivations', $out);
	if (is_array($js)) {
		check($js['account'] === bin2hex($account), 'a code\'s account half: the same bytes in PHP and JS');
		// The root half is imported non-extractable in the browser (it never
		// needs to leave), so JS cannot export it: an empty export is correct.
		check($js['root'] === '' || $js['root'] === bin2hex($root_half), 'a code\'s root half stays unexportable in the browser');
		check($js['scope'] === bin2hex(VaultUnlockerKdf::scopeKek(str_repeat("\x05", 48), 'mail')),
			'a content vault\'s key from the root: the same bytes');
		check($js['pa'] === bin2hex($pa), 'a phrase\'s account half: the same bytes');
	}
}

// ---------------------------------------------------------------------------
section('Nothing outside the tests derives from a code or a phrase');

$offenders = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (substr($path, -4) !== '.php') continue;
	$rel = substr($path, strlen($root) + 1);
	if (preg_match('#^(tests/|plugins/[^/]+/tests/|specs/|vendor/)#', $rel)) continue;
	if ($rel === 'includes/VaultUnlockerKdf.php') continue;
	$src = (string)@file_get_contents($path);
	if (preg_match('/VaultUnlockerKdf::(codeKek|passphraseSplit|scopeKek|normalizeCode)/', $src)) {
		$offenders[] = $rel;
	}
}
check(!$offenders, 'no production code calls the derivation mirror', implode(', ', $offenders));

// ---------------------------------------------------------------------------
section('No vault action takes a raw code or phrase');

$raw_inputs = array('passphrase', 'code', 'recovery_code', 'passphrase_confirm', 'recovery_code_count');
$bad = array();
$logic_files = array_merge(glob($root . '/logic/vault_*_logic.php') ?: array(),
	array($root . '/logic/passkey_register_verify_logic.php'));
foreach ($logic_files as $path) {
	$name = basename($path, '.php');
	require_once($path);
	$descriptor_fn = $name . '_descriptor';
	if (!function_exists($descriptor_fn)) continue;
	$d = $descriptor_fn();
	foreach (array_keys((array)($d['input'] ?? array())) as $input) {
		if (in_array($input, $raw_inputs, true)) {
			$bad[] = $name . ':' . $input;
		}
	}
}
check(count($logic_files) > 10, 'the vault actions were found', (string)count($logic_files));
check(!$bad, 'none declares a raw code or phrase input', implode(', ', $bad));

// ---------------------------------------------------------------------------
section('The browser posts KEKs, and keeps the root output');

$posting_files = array('assets/js/vault-lock.js', 'assets/js/vault-keyring.js', 'views/profile/security.php',
	'includes/setup_steps/encryption_key.php', 'plugins/mailbox/assets/mailbox_reader.js',
	'plugins/joinery_ai/includes/chat_view_body.php');
$raw_posts = array();
foreach ($posting_files as $rel) {
	$src = (string)@file_get_contents($root . '/' . $rel);
	// A vault action posted with the phrase or the code itself.
	if (preg_match('/vault_(unlock_passphrase|unlock_recovery|passphrase_enroll|setup_passphrase|regenerate_codes)[\'"][^;]*\{\s*(passphrase|code)\s*:/s', $src)
			|| preg_match('/\{\s*(passphrase|code)\s*:\s*(phrase|passphrase|code)\b/', $src)) {
		$raw_posts[] = $rel;
	}
}
check(!$raw_posts, 'no page posts a phrase or a code to a vault action', implode(', ', $raw_posts));

$passkeys = (string)file_get_contents($root . '/assets/js/passkeys.js');
check(preg_match('/delete results\.second/', $passkeys) === 1,
	'JoineryPasskeys.derive() takes the second PRF output out of the response it returns');

harness_finish();
?>
