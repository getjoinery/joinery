<?php
/** @joinery-test
 * name: vault_sealedbox
 * tier: safe
 * env: any
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable', 'SealedBox hard-requires ext-sodium');
	harness_finish();
}

$box = new SealedBox();

section('Keypair sealing');
$kp = $box->generateKeypair();
check(strlen(SealedBox::b64url_decode($kp['public'])) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, 'public key is 32 raw bytes');
$dek = random_bytes(32);
$sealed = $box->sealDek($dek, $kp['public']);
check(strpos($sealed, 'v1.seal.') === 0, 'sealed blob carries the v1.seal prefix');
check($box->openDek($sealed, $kp['secret']) === $dek, 'seal/open round trip returns the exact bytes');
$other = $box->generateKeypair();
$threw = false;
try { $box->openDek($sealed, $other['secret']); } catch (Exception $e) { $threw = true; }
check($threw, 'opening with a different secret key throws');
$threw = false;
try { $box->sealDek($dek, 'not-a-key'); } catch (Exception $e) { $threw = true; }
check($threw, 'sealing to a malformed public key throws');

section('AEAD refusals');
$key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$blob = $box->aeadEncrypt('attack at dawn', $key, 'row:1:field');
check(strpos($blob, 'v1.aead.') === 0, 'AEAD blob carries the v1.aead prefix');
check($box->aeadDecrypt($blob, $key, 'row:1:field') === 'attack at dawn', 'AEAD round trip');
check($box->aeadEncrypt('attack at dawn', $key, 'row:1:field') !== $blob, 'same plaintext twice yields distinct blobs (fresh nonce)');

$parts = explode('.', $blob);
$ct = SealedBox::b64url_decode($parts[3]);
$flip_ct = $ct; $flip_ct[3] = chr(ord($flip_ct[3]) ^ 0x01);
$tampered = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . SealedBox::b64url($flip_ct);
$threw = false;
try { $box->aeadDecrypt($tampered, $key, 'row:1:field'); } catch (Exception $e) { $threw = true; }
check($threw, 'a single flipped ciphertext byte throws');

$nonce = SealedBox::b64url_decode($parts[2]);
$flip_n = $nonce; $flip_n[0] = chr(ord($flip_n[0]) ^ 0x01);
$tampered = $parts[0] . '.' . $parts[1] . '.' . SealedBox::b64url($flip_n) . '.' . $parts[3];
$threw = false;
try { $box->aeadDecrypt($tampered, $key, 'row:1:field'); } catch (Exception $e) { $threw = true; }
check($threw, 'a single flipped nonce byte throws');

$threw = false;
try { $box->aeadDecrypt($blob, $key, 'row:2:field'); } catch (Exception $e) { $threw = true; }
check($threw, 'right key with wrong AD throws (splice defense)');
$threw = false;
try { $box->aeadDecrypt($blob, random_bytes(32), 'row:1:field'); } catch (Exception $e) { $threw = true; }
check($threw, 'wrong key throws');
$threw = false;
try { $box->aeadDecrypt('v1.aead.!!notb64.***', $key, 'row:1:field'); } catch (Exception $e) { $threw = true; }
check($threw, 'non-strict base64 in a blob throws');
$threw = false;
try { $box->aeadDecrypt('v1.aead.short', $key, 'row:1:field'); } catch (Exception $e) { $threw = true; }
check($threw, 'truncated blob throws');
$threw = false;
try { $box->aeadEncrypt('x', random_bytes(16), 'ad'); } catch (Exception $e) { $threw = true; }
check($threw, 'a 16-byte AEAD key is rejected before use');

section('Key wrapping');
$kek = random_bytes(32);
$wrapped = $box->wrapKey($kp['secret'], $kek, 'vault:1:2');
check($box->unwrapKey($wrapped, $kek, 'vault:1:2') === $kp['secret'], 'wrap/unwrap round trip');
$threw = false;
try { $box->unwrapKey($wrapped, random_bytes(32), 'vault:1:2'); } catch (Exception $e) { $threw = true; }
check($threw, 'unwrapping with the wrong KEK throws');
$threw = false;
try { $box->unwrapKey($wrapped, $kek, 'vault:1:3'); } catch (Exception $e) { $threw = true; }
check($threw, 'unwrapping with another row\'s AD throws');

section('Recovery codes');
$code = $box->generateRecoveryCode();
$bare = str_replace('-', '', $code);
check(strlen($bare) === 26, 'code is 26 chars', $code);
check(preg_match('/^[0-9A-HJKMNP-TV-Z]+$/', $bare) === 1, 'alphabet excludes I, L, O, U');
check(preg_match('/^[0-9A-Z]{5}(-[0-9A-Z]{5}){4}-[0-9A-Z]$/', $code) === 1, 'grouped in fives', $code);

$salt = $box->generateSalt();
$kek_clean = $box->kekFromRecoveryCode($code, $salt);
check($box->kekFromRecoveryCode(strtolower($code), $salt) === $kek_clean, 'lowercase entry derives the same KEK');
check($box->kekFromRecoveryCode($bare, $salt) === $kek_clean, 'ungrouped entry derives the same KEK');
$typo = strtr($code, ['0' => 'O', '1' => 'I']);
check($box->kekFromRecoveryCode($typo, $salt) === $kek_clean, 'O-for-0 and I-for-1 typos derive the same KEK');
$typo_l = strtr($code, ['1' => 'l']);
check($box->kekFromRecoveryCode($typo_l, $salt) === $kek_clean, 'lowercase l-for-1 typo derives the same KEK');
check($box->kekFromRecoveryCode($box->generateRecoveryCode(), $salt) !== $kek_clean, 'a different code derives a different KEK');
check($box->kekFromRecoveryCode($code, $box->generateSalt()) !== $kek_clean, 'a different salt derives a different KEK');

section('Passphrase KDF');
check(strlen(SealedBox::b64url_decode($salt)) === SODIUM_CRYPTO_PWHASH_SALTBYTES, 'generated salt is sized for Argon2id');
$k1 = $box->kekFromPassphrase('correct horse battery staple', $salt);
check(strlen($k1) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'passphrase KEK is 32 bytes');
check($box->kekFromPassphrase('correct horse battery staple', $salt) === $k1, 'deterministic for the same salt');
check($box->kekFromPassphrase('correct horse battery staple', $box->generateSalt()) !== $k1, 'salt-dependent');
$threw = false;
try { $box->kekFromPassphrase('x', SealedBox::b64url(random_bytes(8))); } catch (Exception $e) { $threw = true; }
check($threw, 'wrong-length salt throws');

harness_finish();
?>
