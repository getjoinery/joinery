# SecretBox

Authenticated encryption for secrets at rest. `SecretBox` (`includes/SecretBox.php`)
is a general-purpose core helper with **no OAuth or database dependency** — use it
anywhere the platform must persist a credential without storing plaintext: OAuth
client secrets and refresh tokens, IMAP passwords, any stored API secret.

It turns a plaintext string into a self-describing, tamper-evident blob and back.
The first caller is the OAuth core (see [OAuth2 Core](oauth2.md)), but nothing
about SecretBox is OAuth-specific.

## API

```php
$box = new SecretBox();                  // throws if secret_box_key is missing/malformed
$blob  = $box->encrypt($plaintext);      // -> "v1.<algo>.<nonce>.<ciphertext>"
$plain = $box->decrypt($blob);           // throws on tamper / wrong key / malformed blob

SecretBox::looksEncrypted($value);       // static bool: is this a SecretBox blob vs. legacy plaintext?
```

- **`__construct()`** — reads the key (below) and validates it's exactly 32 bytes.
  If absent or the wrong length it **throws** — SecretBox fails closed and never
  silently stores plaintext.
- **`encrypt($plaintext): string`** — fresh random nonce per call, so encrypting
  the same value twice yields different blobs.
- **`decrypt($blob): string`** — verifies the authentication tag; any tampering,
  wrong key, or malformed input throws rather than returning garbage.
- **`looksEncrypted($value): bool`** (static) — cheap prefix check so a caller can
  tell a blob from a pre-existing plaintext value and migrate lazily, without a
  separate flag column.

## Blob format

```
v1.<algo>.<nonce>.<ciphertext>
```

Base64url parts. `<algo>` is `sodium` (libsodium `crypto_secretbox`, preferred when
the extension is present) or `aesgcm` (OpenSSL AES-256-GCM fallback, with the
16-byte GCM auth tag prepended to the ciphertext). The algorithm travels inside
the value, so `decrypt()` never has to guess; the `v1` prefix leaves room to
rotate algorithms later without breaking existing blobs.

## The key — `secret_box_key`

`SecretBox` reads a 32-byte, base64-encoded key from `secret_box_key` in
`config/Globalvars_site.php`. It is **bootstrap-level infrastructure config**
(alongside the DB credentials), not a `stg_settings` value, because it must be
available before the database and must never live in the database it protects.

- **On install**, `_site_init.sh` generates it per environment.
- **For an existing site**, add it manually:

  ```php
  // in config/Globalvars_site.php
  $this->settings['secret_box_key'] = '<base64_encode(random_bytes(32)) output>';
  ```

  Generate the value without echoing it into a shared shell history/log:

  ```bash
  php -r 'echo base64_encode(random_bytes(32)), "\n";'
  ```

If the key is absent, the constructor throws (fail closed). **Changing or losing
the key makes every value encrypted with it permanently undecryptable** — treat it
like the DB password: per-environment, backed up, never rotated casually.

## Usage pattern

Encrypt before persisting, decrypt on read, and use `looksEncrypted()` to migrate
any pre-existing plaintext in place:

```php
// write
$setting->set('stg_value', (new SecretBox())->encrypt($plaintext));

// read (tolerating a legacy plaintext value)
$stored = $settings->get_setting('my_secret');
$plain  = SecretBox::looksEncrypted($stored) ? (new SecretBox())->decrypt($stored) : $stored;
```

## Guarantees

- **Authenticated** — tampering is detected on decrypt, never silently accepted.
- **Randomized** — a fresh nonce per call; identical plaintexts produce distinct blobs.
- **Fail-closed** — no key, no operation; never a plaintext fallback.
- **Quiet** — never logs or echoes plaintext.
- **Self-contained** — no DB dependency; safe to use from any layer.

## The per-user asymmetric layer above this

SecretBox is one symmetric key the *server* holds for its own secrets.
[`SealedBox`](sealed_vault.md) (`includes/SealedBox.php`) is the asymmetric
sibling one layer up: a per-*user* X25519 keypair whose secret half the
server never holds at rest, behind the [Sealed Vault](sealed_vault.md)'s
unlock window. Reach for SecretBox for server-held credentials (OAuth
secrets, IMAP passwords); reach for the vault for user content the server
should only read while the user has proven presence. For **client-custody**
scopes — the [password manager](../plugins/vault/docs/overview.md) and Drive —
even the vault's secret key is unwrapped only in the browser: SecretBox and
server-side `SealedBox` are never in that path, because the server is never meant
to decrypt that content at all.
