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
 * The key exists on every site without operator action: the installer
 * generates it for new sites, and ensureConfigKey() — run by the
 * update_database pipeline — backfills it into the config file on sites
 * installed before the key existed.
 *
 * A value is sealed through seal($locator, $plaintext), which refuses a locator
 * that is not declared in a `sealed_secrets` manifest block — the same way
 * Setting::put() refuses an undeclared setting name. This makes the registry
 * load-bearing: you cannot seal a value the reconciler does not know how to find
 * and heal. It is read back through open($stored), which never throws and
 * reports one of four states (ok / absent / dead / plaintext) so a moved
 * database or a rotated key is a fact a consumer can handle rather than an
 * exception that takes down a feature.
 *
 * @version 1.2 - seal() teeth, four-state open() contract, key canary
 * @version 1.1
 */
class SecretBox {

    /** @var string Raw 32-byte key. */
    private $key;

    const KEY_BYTES = 32;

    // open() outcomes. Keeping absent and dead apart is the point: absent means
    // "never configured" (stay silent), dead means "stored but unreadable"
    // (needs attention). Collapsing them makes a health panel show green over a
    // dead secret — a lie in the safe-looking direction.
    const OPEN_OK        = 'ok';
    const OPEN_ABSENT    = 'absent';
    const OPEN_DEAD      = 'dead';
    const OPEN_PLAINTEXT = 'plaintext';

    // The canary: a known constant sealed at key-mint time. On a mass failure it
    // separates "one secret is corrupt" (canary still opens) from "the key is
    // wrong so everything is dead" (canary itself fails) — a distinction the
    // cipher cannot otherwise give, since a wrong key and a bit flip both surface
    // as the same authentication failure.
    const CANARY_SETTING = 'secret_box_canary';
    const CANARY_PLAINTEXT = 'joinery.secretbox.canary.v1';

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
     * Ensure config/Globalvars_site.php carries a secret_box_key, generating
     * and writing one when absent. Idempotent and non-destructive: an
     * existing non-empty key assignment is never touched; an unwritable or
     * missing config file is reported, not fatal. The key value itself is
     * never included in the returned message or any log.
     *
     * $config_path overrides the target file (tests); by default the site's
     * own config file is used.
     *
     * @return array{ok:bool, action:string, message:string}
     */
    public static function ensureConfigKey(?string $config_path = null): array {
        if ($config_path === null) {
            $config_path = PathHelper::getSiteRoot() . '/config/Globalvars_site.php';
        }

        if (!file_exists($config_path)) {
            return array('ok' => false, 'action' => 'missing_config',
                'message' => 'Config file not found: ' . $config_path);
        }
        $contents = file_get_contents($config_path);
        if ($contents === false) {
            return array('ok' => false, 'action' => 'unreadable',
                'message' => 'Config file could not be read: ' . $config_path);
        }

        if (preg_match("/settings\\[['\"]secret_box_key['\"]\\]\\s*=\\s*['\"][^'\"]+['\"]/", $contents)) {
            return array('ok' => true, 'action' => 'present',
                'message' => 'secret_box_key already configured.');
        }

        if (!is_writable($config_path)) {
            return array('ok' => false, 'action' => 'unwritable',
                'message' => 'secret_box_key is missing and the config file is not writable: '
                    . $config_path);
        }

        $key = base64_encode(random_bytes(self::KEY_BYTES));
        $block = "\n// Key for SecretBox (secrets at rest). Generated automatically for a site\n"
            . "// installed before this key existed. 32 random bytes, base64-encoded.\n"
            . "\$this->settings['secret_box_key'] = '" . $key . "';\n";

        // Content after a PHP closing tag would be emitted as page output, so
        // the block goes before a trailing closing tag when one exists.
        $close = strrpos($contents, '?>');
        if ($close !== false) {
            $new_contents = substr($contents, 0, $close) . $block . "\n" . substr($contents, $close);
        } else {
            $new_contents = rtrim($contents) . "\n" . $block;
        }

        if (file_put_contents($config_path, $new_contents, LOCK_EX) === false) {
            return array('ok' => false, 'action' => 'write_failed',
                'message' => 'Failed writing secret_box_key to ' . $config_path);
        }

        return array('ok' => true, 'action' => 'generated',
            'message' => 'secret_box_key generated and written to the config file.');
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

    /**
     * Encrypt a value for a REGISTERED sealed-secret category.
     *
     * The locator must be declared in a `sealed_secrets` manifest block; an
     * unregistered one is refused outright, exactly as Setting::put() refuses an
     * undeclared setting name. This is the teeth: the omission fails the moment
     * someone adds a secret — not months later when the database moves and the
     * reconciler has no idea the value exists.
     *
     * @param string $locator The declared locator (setting name, or "table.column").
     * @param string $plaintext
     * @return string Ciphertext blob.
     * @throws RuntimeException when the locator is not declared.
     */
    public function seal(string $locator, string $plaintext): string {
        require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));
        if (!SealedSecretsDeclarations::isDeclared($locator)) {
            throw new RuntimeException(
                "SecretBox::seal: '{$locator}' is not a declared sealed secret. Add a "
                . 'sealed_secrets entry to settings.json (core) or the plugin.json before '
                . 'sealing a value there — an unregistered secret cannot be found or healed '
                . 'when a database moves.'
            );
        }
        return $this->encrypt($plaintext);
    }

    /**
     * Read a stored sealed value without ever throwing into the caller.
     *
     * Returns ['state' => one of the OPEN_* constants, 'value' => string|null].
     * A consumer that gets DEAD treats it the same as ABSENT (reports itself not
     * configured) and never crashes; the reconciler is what acts on the dead
     * one. A PLAINTEXT value reads as OK to the feature (value carries the raw
     * string); the reconciler reseals it on its next cold pass (never here, since
     * this may run inside a hot request).
     *
     * The path distinguishes only what the cipher lets it: structural
     * malformation is separable, but a wrong key and an in-place bit flip both
     * surface as the same authentication failure, so both are reported DEAD.
     *
     * @param string|null $stored The value read from storage.
     * @return array{state:string, value:?string}
     */
    public function open(?string $stored): array {
        if ($stored === null || $stored === '') {
            return array('state' => self::OPEN_ABSENT, 'value' => null);
        }
        if (!self::looksEncrypted($stored)) {
            return array('state' => self::OPEN_PLAINTEXT, 'value' => $stored);
        }
        try {
            return array('state' => self::OPEN_OK, 'value' => $this->decrypt($stored));
        } catch (\Throwable $e) {
            return array('state' => self::OPEN_DEAD, 'value' => null);
        }
    }

    /**
     * Mint the key canary if this install has none. Cold-only: the write is a
     * long SecretBox blob to stg_settings, which the egress guard refuses in a
     * hot request. Called from update_database and the reconciler, both cold.
     *
     * @return bool True once a canary exists.
     */
    public static function provisionCanary(): bool {
        $dblink = DbConnector::get_instance()->get_db_link();
        $q = $dblink->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
        $q->execute(array(self::CANARY_SETTING));
        $existing = $q->fetchColumn();
        if ($existing !== false && $existing !== '') {
            return true;
        }

        $blob = (new self())->encrypt(self::CANARY_PLAINTEXT);
        // Fill the (usually seeded-but-empty) row only while it is empty, so a
        // concurrent winner's canary is never overwritten. Mirrors the
        // signing-key first-mint guard.
        $ins = $dblink->prepare(
            "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
             VALUES (?, ?, 1, NOW(), NOW(), 'general')
             ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value
             WHERE stg_settings.stg_value IS NULL OR stg_settings.stg_value = ''");
        $ins->execute(array(self::CANARY_SETTING, $blob));
        return true;
    }

    /**
     * The canary's health right now: OPEN_OK (key is fine — an individual dead
     * secret is corrupt), OPEN_DEAD (the key is wrong — every sealed value is
     * dead), or OPEN_ABSENT (no canary minted yet). Cheap: one row, one decrypt.
     */
    public static function canaryState(): string {
        $dblink = DbConnector::get_instance()->get_db_link();
        $q = $dblink->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
        $q->execute(array(self::CANARY_SETTING));
        $stored = $q->fetchColumn();
        if ($stored === false || $stored === '') {
            return self::OPEN_ABSENT;
        }
        $result = (new self())->open((string)$stored);
        if ($result['state'] === self::OPEN_OK && $result['value'] !== self::CANARY_PLAINTEXT) {
            // Decrypts but is not the constant we sealed — corrupt, not our key.
            return self::OPEN_DEAD;
        }
        return $result['state'];
    }

    private static function b64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $s) {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
