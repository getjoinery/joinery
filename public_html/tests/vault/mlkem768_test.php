<?php
/** @joinery-test
 * name: mlkem768
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * ML-KEM-768 through FFI to OpenSSL 3.5, held to NIST's known answers.
 *
 * MlKem768 is the platform's only route to a post-quantum KEM
 * (specs/post_quantum_sealing.md R4): neither PHP extension exposes one, so the
 * class binds libcrypto's EVP KEM calls with FFI. This suite runs the NIST
 * ACVP ML-KEM-768 vectors in tests/vault/fixtures/mlkem768_kat.json through
 * it — key generation from d‖z, encapsulation with fixed randomness through
 * OpenSSL's `ikme` parameter, and decapsulation including implicit rejection of
 * a modified ciphertext — plus SHAKE256 against its published digests.
 *
 * Unavailability is a failure, not a skip: Joinery requires Ubuntu 26.04
 * (OpenSSL 3.5) and FFI, so a box that cannot run this cannot seal.
 *
 * It also holds the tree to ONE FFI user: with ffi.enable = On any PHP could
 * read and write raw memory through FFI's static helpers without declaring a
 * binding, so any FFI call outside MlKem768 fails here (spec D3).
 *
 * @version 1.1 - the scan covers every FFI static helper, not only bindings (`new FFI` throws, so needs no check)
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('the floor');

$why = MlKem768::unavailableReason();
check($why === null, 'FFI and OpenSSL 3.5 are usable in this process', (string)$why);
if ($why !== null) {
	harness_finish();
}
$kem = new MlKem768();
$kat = json_decode((string)file_get_contents(__DIR__ . '/fixtures/mlkem768_kat.json'), true);
check(is_array($kat) && count($kat['keygen']) > 0 && count($kat['encapsulation']) > 0 && count($kat['decapsulation']) > 0,
	'the known-answer fixture loads');

/** Expect a RuntimeException from $fn. */
function mk_throws(callable $fn): bool {
	try { $fn(); } catch (RuntimeException $e) { return true; }
	return false;
}

section('key generation from d‖z (FIPS 203 ML-KEM.KeyGen_internal)');

$ok = 0;
$bad = [];
foreach ($kat['keygen'] as $t) {
	$ek = $kem->keygen(hex2bin($t['d']) . hex2bin($t['z']));
	if (bin2hex($ek) === $t['ek']) {
		$ok++;
	} else {
		$bad[] = $t['tcId'];
	}
}
check($bad === [], "every keygen vector reproduces its encapsulation key ($ok of " . count($kat['keygen']) . ')',
	'mismatched tcId: ' . implode(', ', $bad));

section('encapsulation with fixed randomness (ikme)');

$ok = 0;
$bad = [];
foreach ($kat['encapsulation'] as $t) {
	[$c, $k] = $kem->encapsulate(hex2bin($t['ek']), hex2bin($t['m']));
	if (bin2hex($c) === $t['c'] && bin2hex($k) === $t['k']) {
		$ok++;
	} else {
		$bad[] = $t['tcId'];
	}
}
check($bad === [], "every encapsulation vector reproduces its ciphertext and secret ($ok of " . count($kat['encapsulation']) . ')',
	'mismatched tcId: ' . implode(', ', $bad));

section('decapsulation, including implicit rejection');

$valid = 0;
$rejected = 0;
$bad = [];
foreach ($kat['decapsulation'] as $t) {
	$k = $kem->decapsulateExpanded(hex2bin($t['dk']), hex2bin($t['c']));
	if (bin2hex($k) !== $t['k']) {
		$bad[] = $t['tcId'];
	} elseif ($t['reason'] === 'modified ciphertext') {
		$rejected++;
	} else {
		$valid++;
	}
}
check($bad === [], "every decapsulation vector gives its expected secret ($valid valid, $rejected implicitly rejected)",
	'mismatched tcId: ' . implode(', ', $bad));
check($rejected > 0, 'the fixture exercises implicit rejection');

section('round trip from a seed');

$seed = random_bytes(MlKem768::SEED_BYTES);
$ek = $kem->keygen($seed);
check(strlen($ek) === MlKem768::PUBLIC_KEY_BYTES, 'the encapsulation key is 1184 bytes');
check($kem->keygen($seed) === $ek, 'key generation from one seed is deterministic');
[$c, $k] = $kem->encapsulate($ek);
check(strlen($c) === MlKem768::CIPHERTEXT_BYTES && strlen($k) === MlKem768::SHARED_BYTES, 'fresh encapsulation gives a 1088-byte ciphertext and a 32-byte secret');
check($kem->decapsulate($seed, $c) === $k, 'the seed decapsulates its own ciphertext');
[$c2, ] = $kem->encapsulate($ek);
check($c2 !== $c, 'fresh encapsulations differ (OpenSSL draws its own randomness)');
$tampered = $c;
$tampered[5] = chr(ord($tampered[5]) ^ 1);
check($kem->decapsulate($seed, $tampered) !== $k, 'a modified ciphertext yields a different secret, not an error');

section('refusals');

check(mk_throws(function () use ($kem) { $kem->keygen(random_bytes(32)); }), 'a seed that is not 64 bytes is refused');
check(mk_throws(function () use ($kem) { $kem->encapsulate(random_bytes(32)); }), 'a 32-byte (X25519-sized) key is refused');
check(mk_throws(function () use ($kem, $ek) { $kem->encapsulate($ek, random_bytes(16)); }), 'encapsulation randomness that is not 32 bytes is refused');
check(mk_throws(function () use ($kem, $seed) { $kem->decapsulate($seed, random_bytes(100)); }), 'a short ciphertext is refused');

section('the shared OpenSSL error queue');

while (openssl_error_string() !== false) {}
$bad_ek = str_repeat("\xff", MlKem768::PUBLIC_KEY_BYTES);   // every coefficient out of range
check(mk_throws(function () use ($kem, $bad_ek) { $kem->encapsulate($bad_ek); }), 'an encapsulation key that fails FIPS 203\'s modulus check is refused');
check(openssl_error_string() === false, 'a refusal here leaves nothing for openssl_error_string() to report');

section('SHAKE256');

check(bin2hex($kem->shake256('', 32)) === '46b9dd2b0ba88d13233b3feb743eeb243fcd52ea62b81b82b50c27646ed5762f',
	'SHAKE256 of the empty string (FIPS 202 sample)');
check(bin2hex($kem->shake256('abc', 32)) === '483366601360a8771c6863080cc4114d8db44530f8f1e1ee4f94ea37e78b5739',
	'SHAKE256 of "abc"');
check(strlen($kem->shake256('x', 96)) === 96 && substr($kem->shake256('x', 96), 0, 32) === $kem->shake256('x', 32),
	'a longer output extends a shorter one (XOF)');

section('one FFI user in the tree');

$root = dirname(__DIR__, 2);
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
	function ($f) {
		$n = $f->getFilename();
		return !($f->isDir() && in_array($n, ['.git', 'node_modules', 'vendor', 'uploads', 'tests'], true));
	}
));
foreach ($it as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$path = $file->getPathname();
	if (str_ends_with($path, '/includes/MlKem768.php')) {
		continue;
	}
	$src = @file_get_contents($path);
	if ($src !== false && preg_match('/\bFFI::(cdef|load|scope|new|cast|type|memcpy|memset|memcmp|string|addr|free|isNull|sizeof|alignof|arrayType)\b/', $src)) {
		$hits[] = substr($path, strlen($root) + 1);
	}
}
check($hits === [], 'no PHP file but includes/MlKem768.php uses FFI', implode(', ', $hits));

harness_finish();
