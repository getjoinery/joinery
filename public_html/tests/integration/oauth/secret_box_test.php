<?php
/**
 * SecretBox test - encrypt/decrypt round-trip, ciphertext properties, tamper
 * detection, and missing-key fail-closed.
 *
 * Run: php tests/integration/oauth/secret_box_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class SecretBoxTest {
    private $pass = 0;
    private $fail = 0;

    private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
    private function ok($cond, $label) {
        if ($cond) { $this->pass++; $this->out('  PASS: ' . $label); }
        else { $this->fail++; $this->out('  FAIL: ' . $label); }
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
        $this->out('=== SecretBox tests ===');

        $box = new SecretBox();

        $plain = 'a-client-secret-value-123';
        $blob = $box->encrypt($plain);
        $this->ok($box->decrypt($blob) === $plain, 'round-trip recovers plaintext');
        $this->ok($blob !== $plain, 'ciphertext differs from plaintext');
        $this->ok($box->encrypt($plain) !== $box->encrypt($plain), 'ciphertext varies per call (nonce)');
        $this->ok(SecretBox::looksEncrypted($blob), 'looksEncrypted true for a blob');
        $this->ok(!SecretBox::looksEncrypted($plain), 'looksEncrypted false for plaintext');

        // Tamper: flip the last char of the ciphertext part.
        $bad = substr($blob, 0, -1) . (substr($blob, -1) === 'A' ? 'B' : 'A');
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

        $this->out('');
        $this->out('Results: ' . $this->pass . ' passed, ' . $this->fail . ' failed');
        return $this->fail === 0;
    }
}

$t = new SecretBoxTest();
exit($t->run() ? 0 : 1);
