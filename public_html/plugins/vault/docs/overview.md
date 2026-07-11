# Password Manager (Vault plugin)

A zero-knowledge password manager at `/profile/vault`. Passwords are encrypted
and decrypted **only in the browser**; the server stores opaque ciphertext and
never holds the key. It is a **client-custody consumer of the [Sealed
Vault](../../../docs/sealed_vault.md)** — the encryption identity, the
unlockers, the recovery scheme, and the browser crypto module all come from the
vault. This plugin adds only the entry storage and the manager UI on top.

## The threat model is the design

Designed around one deliberately chosen threat: **a Joinery vulnerability gets
mass-exploited and the attacker reads files, dumps the database, or steals a
backup.** On a full Linux + Apache + Joinery box that surface is large and
exploitation is automated, so any key the OS or PHP process can reach is, for
password-manager purposes, already compromised.

The conclusion: **the server must never hold the key or see plaintext.** This is
why the manager is **client-custody, not server-custody** — the server-side
unlock window that serves mail and chat would put a decryptable key in server
RAM, which for passwords is categorically wrong. Server-side `SecretBox` is not
used here. A DB dump, file read, stolen backup, or SQL-injection exfiltration
yields only **ciphertext plus a wrapped key**; security then reduces to the cost
of brute-forcing the passphrase offline, which is why the KDF choice is
load-bearing (Argon2id, memory-hard).

**What it does not fully defend, stated plainly:** a web vault's crypto is
served by the same server being defended. An attacker who compromises Joinery
deeply enough to **alter the JavaScript it serves** can capture credentials the
next time they are entered — forward-looking, never retroactive (a snapshot
still yields nothing). This is the served-JS residual every web vault shares;
the mitigation is a native/extension client that ships the unlock code in the
installed artifact, a hardening phase shared with Drive. Endpoint compromise (a
keylogger on your own machine) defeats any password manager and is out of scope.

## Crypto architecture

The identity is the vault's **own `passwords` client-custody keypair**
(`uev_scope='passwords'`, `uev_custody='client'`, X25519) — separate from Drive
and from server-custody mail/chat, so unlocking one never opens another. The key
hierarchy, performed entirely in the browser:

```
unlocker  --KEK-->  vault X25519 secret key  --seals-->  store DEK  --encrypts--> each entry
```

- **Unlockers** derive a KEK in the browser: a passkey's WebAuthn **PRF** output
  (context `vault-passwords-kek`, never sent to the server), a **recovery key**
  (≥128-bit, fast keyed hash), or an optional **passphrase** (Argon2id, 64 MiB /
  t=3 / p=4). Each KEK wraps the vault secret key (AES-256-GCM) — one wrapping
  row per unlocker. Adding or changing an unlocker only re-wraps the secret key;
  the store DEK and entries are untouched.
- **Store DEK** — a random 256-bit AES-GCM key sealed once (ECIES over X25519)
  to the vault public key and held as one blob (`vlk_wrapped_dek`). On unlock the
  browser unwraps the secret key, opens the sealed DEK, and holds it as a
  **non-extractable `CryptoKey`**.
- **Each entry** is one AES-256-GCM blob over a JSON record (`type`, `title`,
  `username`, `password`, `url`, `notes`, `totp_seed`, …). Everything — even the
  coarse `type` — lives inside the blob; there is deliberately no searchable
  plaintext column, so the list renders only after client-side decryption.

**No server-side search.** The client fetches all entry blobs on unlock and
searches/decrypts in memory (a password store is small).

## Server surface (opaque storage only)

The server stores and returns blobs and never inspects, validates, or logs their
contents. Two layers:

- **Identity (core, shared, scope-parameterized).** The client-custody `uev`/`uew`
  actions live in core `logic/` and are reused by Drive with scope `drive`:
  `vault_client_status` (keyring view), `vault_client_prf_options` (mint a PRF
  assertion; the browser reads the output locally and never posts it),
  `vault_client_setup`, `vault_client_add_wrapping`, `vault_client_remove_wrapping`
  (unlocker-floor enforced), `vault_client_replace_recovery`, and
  `vault_client_consume_recovery`. `includes/VaultClientCustody.php` is the shared
  helper.
- **Password-specific (this plugin).** `/api/v1/action/vault/*`: `keyring_get`,
  `keyring_save` (the sealed store DEK — create-only: the blob is the sole copy
  of the store key, so an existing row is never overwritten), `entries_list`, `entry_save`,
  `entry_delete` (trash), `entry_restore`. Every action is
  `requires_browser_session`.

Data classes: **`VaultKeyring`** (`vlk_vault_keyring`, one row per user — the
sealed store DEK) and **`VaultEntry`** (`vle_vault_entries`, one opaque blob per
entry, soft-deleted for trash/restore).

## The first-run ceremony

A guided, full-screen setup on first visit:

1. **Choose an unlocker.** A passkey is the everyday unlocker (with a PRF
   capability check); if the authenticator lacks PRF, a master passphrase is the
   primary unlocker instead.
2. **Save the recovery key.** Recovery keys are shown once; setup requires
   **proof of custody** — download the file or re-type the last key — before
   continuing. A bare "I saved it" checkbox is not enough.
3. Optionally set a master passphrase fallback (offered alongside the passkey).

Losing every unlocker (passkey, recovery key, and passphrase) permanently loses
the vault — stated up front and acknowledged before setup proceeds.

## Unlock and lock

One deliberate act opens the password vault — its own unlock, separate from
Drive and from mail/chat (separate keypair). The unlock screen offers the
passkey (primary), the passphrase (if enrolled), and a recovery key (last). A
consumed recovery key is one-time: the browser marks it used server-side and the
manager suggests regenerating keys once fewer than three remain.

**Locking discards all plaintext**, including unsaved edits — idle auto-lock
(15 minutes by default, user-configurable), a manual "Lock now", and closing the
tab. Keyboard and pointer activity defer the idle timer so the lock never fires
mid-keystroke. The store DEK (a non-extractable `CryptoKey`) and the vault secret
key are dropped on lock; relocking shows the unlock screen in place.

## Entry types and field handling

v1 ships **Login** (`username`, `password`, `url`, `totp_seed`, `notes`) and
**Secure Note** (`notes`). The blob's `type` tag lets later types (card,
identity) be added without migration. Passwords and TOTP seeds render masked with
a per-field reveal; every credential field has a copy button, and copied secrets
are cleared from the clipboard after 30 seconds (best-effort — the Clipboard API
only permits clearing while the page holds focus, and only if the clipboard still
holds the copied value). TOTP entries show the current code with a countdown,
generated in the browser.

## Quality-of-life

- **Password generator** in the entry editor.
- **TOTP** seed storage with in-browser code generation.
- **Import** an encrypted Joinery backup, a Bitwarden JSON export, or a CSV (the
  common 1Password / generic shape); **export** an encrypted backup (all entries
  encrypted under a passphrase you choose, independent of the vault).
- **User-configurable auto-lock** timeout, remembered per browser.

## The vendored Argon2id WASM

The passphrase-fallback KDF is Argon2id via a vendored, hash-pinned WASM
(`assets/vendor/argon2/argon2-bundled.min.js` — self-contained, no CDN, no
runtime npm). This is the Sealed Vault's sanctioned exception to the platform's
vanilla-JS-only rule. Recovery keys carry ≥128 bits of entropy, so their KEK is a
fast SHA-256, never Argon2id.

## Tests

`plugins/vault/tests/vault_client_custody_test.php` covers the server contract:
scope isolation, byte-for-byte opaque storage (the zero-knowledge property), the
passkey-resolution guards, the keyring status view, the unlocker floor across
scopes, and the entry/keyring models. The browser crypto round-trips (WebCrypto /
Argon2, which cannot run in CLI) are verified end-to-end in a browser via the
passphrase unlocker and the recovery-key path.
