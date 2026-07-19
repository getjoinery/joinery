<?php
/** @joinery-test
 * name: secret_box
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * SecretBox test - encrypt/decrypt round-trip, ciphertext properties, tamper
 * detection, and missing-key fail-closed.
 *
 * Run: php tests/integration/oauth/secret_box_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class SecretBoxTest {
    private function ok($cond, $label) {
        return check($cond, $label);
    }

    /** Temporarily override the in-memory secret_box_key. */
    private function withKey($value, callable $fn) {
        $gv = Globalvars::get_instance();
        $ref = new ReflectionProperty('Globalvars', 'settings');
        $ref->setAccessible(true);
        $arr = $ref->getValue($gv);
        $orig = $arr['secret_box_key'] ?? null;
        $arr['secret_box_key'] = $value;
        $ref->setValue($gv, $arr);
        try {
            $fn();
        } finally {
            $arr = $ref->getValue($gv);
            if ($orig === null) { unset($arr['secret_box_key']); }
            else { $arr['secret_box_key'] = $orig; }
            $ref->setValue($gv, $arr);
        }
    }

    function run() {
        section('SecretBox tests');

        $box = new SecretBox();

        $plain = 'a-client-secret-value-123';
        $blob = $box->encrypt($plain);
        $this->ok($box->decrypt($blob) === $plain, 'round-trip recovers plaintext');
        $this->ok($blob !== $plain, 'ciphertext differs from plaintext');
        $this->ok($box->encrypt($plain) !== $box->encrypt($plain), 'ciphertext varies per call (nonce)');
        $this->ok(SecretBox::looksEncrypted($blob), 'looksEncrypted true for a blob');
        $this->ok(!SecretBox::looksEncrypted($plain), 'looksEncrypted false for plaintext');

        // Tamper with a character in the middle of the ciphertext part.
        //
        // Flipping the *last* base64 character is not a reliable tamper: when
        // the encoded length is not a multiple of 3 the final character carries
        // fewer than six significant bits, so some flips decode to byte-for-byte
        // identical ciphertext. Decryption then succeeds because nothing was
        // actually changed, and this check failed roughly one run in eight —
        // a flaky gate that looked like an authentication weakness.
        $parts = explode('.', $blob);
        $cipher_part = $parts[count($parts) - 1];
        $mid = intdiv(strlen($cipher_part), 2);
        $parts[count($parts) - 1] = substr($cipher_part, 0, $mid)
            . ($cipher_part[$mid] === 'A' ? 'B' : 'A')
            . substr($cipher_part, $mid + 1);
        $bad = implode('.', $parts);

        // Prove the tamper landed before asserting anything about decryption:
        // an assertion that a *non*-tampered blob fails to decrypt is worse
        // than no assertion, because it reads as proof the check works.
        $b64url_decode = function ($s) {
            return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
        };
        $this->ok($b64url_decode($parts[count($parts) - 1]) !== $b64url_decode($cipher_part),
            'the tampered ciphertext really differs from the original');

        $threw = false;
        try { $box->decrypt($bad); } catch (Throwable $e) { $threw = true; }
        $this->ok($threw, 'tampered ciphertext fails to decrypt');

        $threw = false;
        try { $box->decrypt('not-a-valid-blob'); } catch (Throwable $e) { $threw = true; }
        $this->ok($threw, 'malformed blob throws');

        // Missing key fails closed on construction.
        $this->withKey('', function () {
            $threw = false;
            try { new SecretBox(); } catch (Throwable $e) { $threw = true; }
            $this->ok($threw, 'missing key throws on construction (fail closed)');
        });

        // Malformed key (wrong length) also fails closed.
        $this->withKey(base64_encode('short'), function () {
            $threw = false;
            try { new SecretBox(); } catch (Throwable $e) { $threw = true; }
            $this->ok($threw, 'wrong-length key throws on construction');
        });
    }
}

$t = new SecretBoxTest();
$t->run();
harness_finish();
