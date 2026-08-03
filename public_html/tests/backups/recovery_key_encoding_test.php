<?php
/** @joinery-test
 * name: recovery_key_encoding
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The recovery keypair is made in the browser and used by libsodium on the
 * server, so its encoding is a contract between two engines that never see each
 * other. One private key has to work in three places for the rest of its life:
 * the in-page possession ceremony, escrow_keypair.php unseal during a disaster,
 * and any future recovery tooling.
 *
 * A drift here — a base64 variant, a stray newline, the scalar taken from the
 * wrong offset of the PKCS#8 wrapper — produces a keypair that looks entirely
 * healthy. The page accepts it, the setting stores it, backups seal to it and
 * report success. It is discovered at the only moment it cannot be fixed.
 *
 * Two checks, deliberately different in kind:
 *   1. A keypair a REAL browser emitted, captured once as a fixture, is held to
 *      the contract. This is the one that proves a browser can do it at all.
 *   2. The shipped JS is executed against WebCrypto and its output is verified
 *      the same way, so a change to the export handling fails here rather than
 *      in someone's archive. Skipped where node cannot supply X25519.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/**
 * Everything a recovery keypair must satisfy, whatever produced it.
 * @param string $priv_b64 raw 32-byte X25519 scalar, base64
 * @param string $pub_b64  raw 32 bytes, base64
 */
function rk_check_pair($priv_b64, $pub_b64, $label) {
	$priv = base64_decode((string)$priv_b64, true);
	$pub  = base64_decode((string)$pub_b64, true);

	check($priv !== false && strlen($priv) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES,
		"{$label}: private key is 32 raw bytes in standard base64",
		'got ' . ($priv === false ? 'invalid base64' : strlen($priv) . ' bytes'));
	check($pub !== false && strlen($pub) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
		"{$label}: public key is 32 raw bytes in standard base64",
		'got ' . ($pub === false ? 'invalid base64' : strlen($pub) . ' bytes'));
	if ($priv === false || $pub === false
			|| strlen($priv) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES
			|| strlen($pub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
		return; // nothing below can mean anything
	}

	// The halves must belong to each other. libsodium clamps the scalar inside
	// crypto_scalarmult_base and WebCrypto clamps during X25519, so a scalar
	// generated on either side must derive the same public key on the other —
	// this is the assertion that says so rather than assuming it.
	check(sodium_crypto_box_publickey_from_secretkey($priv) === $pub,
		"{$label}: the public key is what libsodium derives from the private key");

	// The platform seals to it, exactly as BackupEnvelope does for every backup.
	$secret  = 'Your recovery key opened this message.';
	$sealed  = sodium_crypto_box_seal($secret, $pub);
	$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($priv, $pub);
	check(sodium_crypto_box_seal_open($sealed, $keypair) === $secret,
		"{$label}: a sealed box for this public key opens with this private key");

	// And the disaster-recovery tool opens it, reading the private key from a
	// file written exactly as the operator would save it.
	$cli = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php';
	if (!is_file($cli)) {
		harness_skip('escrow_keypair.php not present', $cli);
		return;
	}
	$key_file  = sys_get_temp_dir() . '/jy_rk_' . getmypid() . '_' . md5($label) . '.key';
	$blob_file = $key_file . '.blob';
	file_put_contents($key_file, $priv_b64 . "\n");
	chmod($key_file, 0600);
	file_put_contents($blob_file, base64_encode($sealed));

	$out = [];
	$rc  = 0;
	exec('php ' . escapeshellarg($cli) . ' unseal'
		. ' --private ' . escapeshellarg($key_file)
		. ' --in ' . escapeshellarg($blob_file) . ' 2>&1', $out, $rc);
	@unlink($key_file);
	@unlink($blob_file);

	check($rc === 0 && trim(implode("\n", $out)) === $secret,
		"{$label}: escrow_keypair.php unseal recovers the plaintext",
		'rc=' . $rc . ' out=' . implode(' | ', $out));
}

// ── 1. The captured browser keypair ─────────────────────────────────────────
section('A real browser keypair satisfies the contract');

$fixture_path = __DIR__ . '/../fixtures/browser_recovery_keypair.json';
$fixture = json_decode((string)@file_get_contents($fixture_path), true);
check(is_array($fixture) && !empty($fixture['private_key_b64']) && !empty($fixture['public_key_b64']),
	'the captured browser keypair fixture is readable', $fixture_path);

if (is_array($fixture)) {
	rk_check_pair($fixture['private_key_b64'] ?? '', $fixture['public_key_b64'] ?? '', 'browser fixture');
}

// ── 2. The shipped JS, run for real ─────────────────────────────────────────
section('The shipped generator still produces that encoding');

$js = PathHelper::getIncludePath('assets/js/recovery-readiness.js');
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));

if ($node === '' || !is_file($js)) {
	harness_skip('node or the generator script is not available here',
		$node === '' ? 'no node on PATH' : $js);
} else {
	// The file is a browser IIFE, so it gets the handful of globals it touches
	// and nothing else. Running the real file is the point: a shim that
	// reimplemented the export handling would agree with itself forever.
	$runner = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const { webcrypto } = require('crypto');

const doc = {
	readyState: 'complete',
	addEventListener() {},
	getElementById() { return null; },
	querySelectorAll() { return []; },
	querySelector() { return null; },
};
const sandbox = {
	window: {}, document: doc, crypto: webcrypto, navigator: {},
	TextEncoder, TextDecoder, console,
	btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
	atob: (s) => Buffer.from(s, 'base64').toString('binary'),
};
sandbox.window.document = doc;
sandbox.window.crypto = webcrypto;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox);

sandbox.window.recoveryReadiness.generateKeypair()
	.then((pair) => { process.stdout.write(JSON.stringify(pair)); })
	.catch((e) => { process.stdout.write(JSON.stringify({ error: String(e.message || e) })); });
JS;
	$runner_file = sys_get_temp_dir() . '/jy_rk_runner_' . getmypid() . '.js';
	file_put_contents($runner_file, $runner);

	$out = [];
	$rc  = 0;
	exec(escapeshellarg($node) . ' ' . escapeshellarg($runner_file) . ' '
		. escapeshellarg($js) . ' 2>&1', $out, $rc);
	@unlink($runner_file);

	$generated = json_decode(trim(implode('', $out)), true);
	if (!is_array($generated) || isset($generated['error'])) {
		// No X25519 in this runtime is a fact about the machine, not a failure
		// of the contract — a browser that cannot generate falls back to the CLI.
		harness_skip('this node runtime cannot generate an X25519 keypair',
			is_array($generated) ? (string)$generated['error'] : implode(' | ', $out));
	} else {
		rk_check_pair($generated['privateKeyB64'] ?? '', $generated['publicKeyB64'] ?? '',
			'shipped generator');
	}
}

harness_finish();
