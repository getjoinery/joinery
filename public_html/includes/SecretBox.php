<?php
/**
 * SecretBox - Authenticated encryption for secrets at rest.
 *
 * Encrypts/decrypts arbitrary strings with authenticated encryption so the
 * platform can persist credentials (OAuth client secrets, OAuth refresh
 * tokens, IMAP passwords) without storing plaintext. libsodium
 * (sodium_crypto_secretbox) is used when the extension is present, otherwise
 * OpenSSL AES-256-GCM. The output is a self-describing string
 *
 *     v1.<algo>.<nonce>.<ciphertext>
 *
 * (base64url parts) so the algorithm that produced a value travels with the
 * value and decryption never has to guess.
 *
 * The key is read from `secret_box_key` in config/Globalvars_site.php (32
 * random bytes, base64-encoded). If the key is absent or malformed the
 * constructor throws — SecretBox fails closed and never silently stores or
 * returns plaintext. Plaintext is never logged or echoed.
 *
 * @version 1.0
 */
class SecretBox {

    /** @var string Raw 32-byte key. */
    private $key;

    const KEY_BYTES = 32;

    public function __construct() {
        $settings = Globalvars::get_instance();
        $encoded = (string)$settings->get_setting('secret_box_key', false, true);

        if ($encoded === '') {
            throw new RuntimeException(
                'SecretBox: secret_box_key is not configured. Add a base64-encoded '
                . '32-byte key to config/Globalvars_site.php (e.g. '
                . "base64_encode(random_bytes(32))) before storing any secret."
            );
        }

        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException(
                'SecretBox: secret_box_key must be exactly ' . self::KEY_BYTES
                . ' base64-encoded bytes.'
            );
        }

        $this->key = $key;
    }

    /**
     * Encrypt a plaintext string. Returns a self-describing ciphertext blob.
     */
    public function encrypt(string $plaintext): string {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
            return 'v1.sodium.' . self::b64url($nonce) . '.' . self::b64url($cipher);
        }

        // OpenSSL AES-256-GCM fallback. The 16-byte auth tag is prepended to the
        // ciphertext so both travel in the single ciphertext part.
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16
        );
        if ($cipher === false) {
            throw new RuntimeException('SecretBox: AES-256-GCM encryption failed.');
        }
        return 'v1.aesgcm.' . self::b64url($nonce) . '.' . self::b64url($tag . $cipher);
    }

    /**
     * Decrypt a blob produced by encrypt(). Throws on tamper / auth failure or
     * a malformed blob.
     */
    public function decrypt(string $blob): string {
        $parts = explode('.', $blob);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new RuntimeException('SecretBox: malformed ciphertext.');
        }

        $algo = $parts[1];
        $nonce = self::b64url_decode($parts[2]);
        $cipher = self::b64url_decode($parts[3]);
        if ($nonce === false || $cipher === false) {
            throw new RuntimeException('SecretBox: malformed ciphertext encoding.');
        }

        if ($algo === 'sodium') {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('SecretBox: libsodium required to decrypt this value.');
            }
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
            if ($plain === false) {
                throw new RuntimeException('SecretBox: decryption failed (tampered or wrong key).');
            }
            return $plain;
        }

        if ($algo === 'aesgcm') {
            if (strlen($cipher) < 16) {
                throw new RuntimeException('SecretBox: truncated AES-GCM ciphertext.');
            }
            $tag = substr($cipher, 0, 16);
            $ct = substr($cipher, 16);
            $plain = openssl_decrypt($ct, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($plain === false) {
                throw new RuntimeException('SecretBox: decryption failed (tampered or wrong key).');
            }
            return $plain;
        }

        throw new RuntimeException('SecretBox: unknown algorithm "' . $algo . '".');
    }

    /**
     * Is a string a SecretBox blob (vs. legacy plaintext)? Lets callers
     * migrate a value lazily without a flag column.
     */
    public static function looksEncrypted(string $value): bool {
        return strncmp($value, 'v1.sodium.', 10) === 0
            || strncmp($value, 'v1.aesgcm.', 10) === 0;
    }

    private static function b64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $s) {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
