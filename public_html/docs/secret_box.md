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
$box = new SecretBox();                      // throws if secret_box_key is missing/malformed
$blob   = $box->seal($locator, $plaintext);  // seal a REGISTERED secret; refuses an unregistered locator
$result = $box->open($stored);               // never throws — ['state' => ok|absent|dead|plaintext, 'value' => ...]

SecretBox::looksEncrypted($value);           // static bool: is this a SecretBox blob vs. legacy plaintext?
```

Consumers use **`seal()` / `open()`** — the registered, non-throwing contract:

- **`seal($locator, $plaintext): string`** — encrypts a value for a declared
  sealed-secret category. The `$locator` must be declared in a `sealed_secrets`
  manifest block (a setting name, or `table.column`); an unregistered one is
  **refused**, the same way `Setting::put()` refuses an undeclared setting name.
  This makes the registry load-bearing — you cannot seal a value the reconciler
  cannot find and heal when a database moves. See [Sealed Secrets](sealed_secrets.md).
- **`open($stored): array`** — reads a stored value without ever throwing.
  `state` is one of `ok` (here is the secret, in `value`), `absent` (nothing
  stored — not configured), `dead` (stored but undecryptable — moved database or
  rotated key), or `plaintext` (a legacy unencrypted value, returned raw in
  `value`). A consumer that gets `dead` treats it like `absent` and never crashes.
- **`looksEncrypted($value): bool`** (static) — cheap prefix check.
- **`__construct()`** — reads the key (below) and validates it's exactly 32 bytes;
  throws if absent or wrong length (fail closed).

`encrypt()` / `decrypt()` remain as the low-level primitives underneath, but they
are **not** the consumer API: a grep-enforced test
(`tests/unit/sealed_secret_callsites_test.php`) fails CI on any raw
`->encrypt()` / `->decrypt()` call outside `SecretBox` itself.

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

The key exists without operator action:

- **On install**, `_site_init.sh` generates it per environment.
- **On upgrade**, the `update_database` pipeline's SecretBox Key Check step
  (`SecretBox::ensureConfigKey()`) generates and writes one when the config
  file has none — covering sites installed before the key existed. A present
  key is never touched; an unwritable config file is reported in the upgrade
  output instead of failing the upgrade.

If the key is absent, the constructor throws (fail closed). **Changing or losing
the key makes every value encrypted with it permanently undecryptable** — treat it
like the DB password: per-environment, backed up, never rotated casually.

## Usage pattern

Seal before persisting, open on read. `open()` collapses the legacy-plaintext
migration idiom — a plaintext value comes back in `value` with `state` `plaintext`:

```php
// write
$setting->set('stg_value', (new SecretBox())->seal('my_secret', $plaintext));

// read (tolerating a moved database and a legacy plaintext value)
$stored = $settings->get_setting('my_secret');
$result = (new SecretBox())->open($stored);
$plain  = $result['value'];   // null when dead/absent — treat as not configured
```

Every new sealed value needs a `sealed_secrets` declaration naming its category,
kind, and locator — see [Sealed Secrets](sealed_secrets.md). Without it, `seal()`
refuses the value.

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
scopes — the [password manager](../plugins/vault/docs/overview.md) and
[Drive encryption](drive_encryption.md) — even the vault's secret key is unwrapped
only in the browser: SecretBox and server-side `SealedBox` are never in that path,
because the server is never meant to decrypt that content at all. A Drive file key
is generated, wrapped, and opened entirely client-side; the server stores only
opaque ciphertext and wrapped keys.
