# Password Manager — Client-Custody Vault Consumer (zero-knowledge)

## Status: active — design

The fourth leg of the self-hosted Proton-suite replacement, alongside mail (built), calendar
(built — `specs/implemented/scheduling_system.md`), and drive (`specs/drive_core.md`). This is
the **password manager** — client-side zero-knowledge, end of discussion.

**Two senses of "vault," disambiguated up front:** *the Sealed Vault* is the platform's shared
per-user encryption identity (`specs/implemented/sealed_vault_core.md`) — the keypair and unlockers. *Your
vault* (user-facing) is your collection of passwords. This feature is the **password manager**;
it is a **client-custody consumer of the Sealed Vault**, sharing the same keypair, passkey,
recovery scheme, and browser crypto module as Drive encryption. It lives in `plugins/vault/`
and does **not** build its own key derivation or keyring — those are the Sealed Vault's.
(Earlier drafts defined a standalone `VaultKeyring` with its own Argon2id KDF and
recovery-wrapped DEK; that identity layer is superseded by the vault.)

## The threat model is the whole design

Designed around one deliberately chosen threat: **a Joinery vulnerability gets mass-exploited,
and the attacker reads files, dumps the database, or steals a backup.** On a full Linux + Apache
+ Joinery box, that surface is large and exploitation is automated — so any key the OS or PHP
process can reach is, for password-manager purposes, already compromised.

The conclusion: **the server must never hold the key or see plaintext.** This is precisely why
the password manager is **client-custody, not server-custody** — the server-side unlock window
that serves mail and chat would put a decryptable key in server RAM, which for passwords is
categorically wrong. Server-side `SecretBox` is not used; if PHP can decrypt the vault, the same
exploit that dumps the table dumps the means to read it.

### What this defends

A DB dump, file read, stolen backup, or SQL-injection exfiltration yields only **ciphertext plus
a wrapped key**. Security then reduces to the cost of brute-forcing offline — which is why the
KDF choice for the passphrase fallback is load-bearing. This dominant automated threat is fully
defended.

### What it does NOT fully defend (stated honestly, up front)

A web vault's crypto is *served by the same server being defended*. An attacker who compromises
Joinery deeply enough to **alter the JavaScript it serves** can capture credentials the next time
they're entered. Forward-looking, not retroactive — a snapshot still yields nothing. This is why
mature competitors ship browser extensions and native apps; the mitigation here is the same — a
**Phase 4 hardening client**, shared with Drive and the vault. Nothing served from the same
origin closes this gap (SRI included). Known limitation, documented, not a v1 blocker.

### Out of scope

Endpoint compromise (a keylogger on the user's own machine) defeats any password manager.

## Crypto architecture

The **identity and unlock come from the password manager's own Sealed Vault scope** — its
**own** client-custody keypair (scope `passwords`), **separate from Drive and from mail/chat**
(a deliberate decision: unlocking Drive or reading mail must never open the password vault).
The password manager adds only the entry encryption on top.

1. **Identity = the `passwords` client-custody vault keypair** (`uev`/`uew`,
   `uev_scope='passwords'`, `uev_custody='client'`, X25519). The secret key is unwrapped **only
   in the browser**, via the vault's shared unlockers:
   - **Passkey (primary):** WebAuthn PRF, context **`vault-passwords-kek`** — derived in the
     authenticator, used in the browser as the KEK, never sent to the server. One deliberate tap
     to open the password vault. The context is distinct from every other scope's, so no other
     unlock — not Drive's, not a server-captured mail/chat KEK — can ever open this one (vault
     spec isolation rule). This is the crown-jewels isolation.
   - **Master passphrase (optional fallback):** `KEK = Argon2id(passphrase, uev_salt, uev_kdf_params)`
     in the browser via the vault's vendored WASM module. Now *optional* — the passkey is the
     everyday unlocker — where it was the sole unlocker before.
   - **Recovery key:** the vault's shared recovery scheme.
2. **Store data key (DEK).** A random 256-bit DEK encrypts every entry (AES-256-GCM). The DEK is
   **sealed once to the vault's client-custody public key** (`uev_public_key`) and stored as one
   wrapped blob. Unlock = browser unwraps the vault secret key (via any unlocker) → opens the
   sealed DEK → holds it as a non-extractable `CryptoKey`. The keypair indirection means changing
   or adding an unlocker only re-wraps the vault secret in `uew` — entries and the DEK are
   untouched (the client-side mirror of the server's `password_needs_rehash` upgrade path). The
   old recovery-wrapped-DEK duplication is gone — recovery is a vault unlocker now.
3. **Per-entry encryption.** Each entry is one AES-GCM blob over a JSON record
   (`{type, title, username, password, url, notes, totp_seed, …}`) with its own random IV.
   Everything — including the coarse `type` — lives inside the blob; the list renders only after
   client-side decryption, so a cleartext column would only leak. **Blob format everywhere:**
   `base64(IV ‖ ciphertext)`, a self-contained string with the 12-byte IV prepended. The vault's
   shared browser crypto module exposes exactly this `encrypt()→blob` / `blob→decrypt()` contract.
4. **Unlock.** The vault's client-custody unlock — browser-derived KEK, DEK held as a
   non-extractable `CryptoKey`, **idle auto-lock (15 min default in v1)**. Closing the tab is an
   implicit lock. **The password unlock is its own** — a separate deliberate act from Drive and
   from mail/chat, by design (separate keypair).

**No server-side search.** A password store is small: the client fetches all entry blobs on
unlock and searches/decrypts in memory. No server index, no blind-index gymnastics — the vault's
client-custody in-memory-search pattern.

### Key-derivation choice (the passphrase-fallback KDF)

The passphrase-fallback KDF is **Argon2id via the vault's vendored `argon2-browser` WASM** — the
same module Drive uses, not a password-manager-local copy. Memory-hard, the strongest defense for
offline brute-force of the passphrase against a stolen wrapped key, and consistent with the
server-side `PASSWORD_ARGON2ID` choice. **Parameters: 64 MiB, t=3, p=4** (RFC 9106 second
recommendation / Bitwarden default), stored per-user in `uev_kdf_params` so raising them later
re-derives and re-wraps at next unlock (entries untouched). PBKDF2 was rejected (gives up
memory-hardness). This lives in the vault spec now; the exception to vanilla-JS-by-default (a
vendored, hash-pinned crypto primitive, no CDN, no runtime npm) is the vault's, shared here.

## Data model

A self-contained **plugin** (`/plugins/vault/`) with its own admin/profile surface. The
**identity is the vault** (`uev`/`uew`) — no plugin-local KDF/keyring. Plugin-local classes:

- **`VaultKeyring`** (`vlk_vault_keyring`), one row per user (`vlk_usr_user_id` unique):
  `vlk_usr_user_id` (FK), `vlk_wrapped_dek` (the store DEK sealed to the client-custody
  `uev_public_key`), timestamps. That is **all** it holds now — the KDF, salt, params, and
  recovery-wrapped DEK are gone (they're the vault's `uev`/`uew`). It exists only to bind the
  store DEK to the user's vault identity.
- **`VaultEntry`** (`vle_vault_entries`): `vle_usr_user_id` (FK), `vle_ciphertext` (a blob in the
  standard format), created/updated timestamps, `vle_delete_time` (soft delete — trash/restore).
  No searchable plaintext; title/username/URL all live inside the blob.

**Deletion.** Cascade on the user FK. Entries soft-delete (trashed ciphertext is no less
protected); the keyring is hard-delete only. Both tables auto-created by `update_database`.

### Server surface

- **Identity/keyring: none new** — the vault owns `uev`/`uew` and the unlock/recovery/rewrap
  actions. The password manager reuses them.
- **Password-specific:** `_logic_api()` actions in `plugins/vault/logic/` — get keyring (the
  sealed DEK), save keyring, list entries, save entry, delete entry — called over `/api/v1` with
  the browser-session credential. The server treats every payload as opaque ciphertext: it stores
  and returns blobs and never inspects, validates, or logs their contents.

## Work

### Phase 1 — Crypto core + usable manager (the v1)

The **vault's client-custody identity** (shared module, keyring, passkey/recovery/passphrase
unlock, auto-lock) is built by `specs/implemented/sealed_vault_core.md` — this phase consumes it. On top:
generate + seal the store DEK on first use; CRUD on encrypted entries (server stores/returns
opaque blobs only); list view that decrypts in memory, in-memory search, copy-to-clipboard. A
usable, zero-knowledge manager unlocked by a passkey tap.

### Phase 2 — Recovery

Recovery is a **vault unlocker** — the forgot-passphrase / lost-device flow re-establishes access
via the vault's recovery key or another enrolled unlocker, then opens the sealed DEK. No
password-manager-specific recovery crypto.

### Phase 3 — Quality-of-life

Client-side password generator; TOTP seed storage with in-browser code generation (an
authenticator too); encrypted import/export (Bitwarden/1Password/CSV in, encrypted backup out);
user-configurable auto-lock timeout.

### Phase 4 — Hardening (the served-JS residual)

Browser extension and/or native client so unlock code isn't re-fetched per use, with autofill as
the headline UX win — shared timeline with Drive and the vault's client hardening. The existing
iOS web-view shell does not qualify (JS still fetched from Joinery); a Phase 4 client counts only
if the unlock/crypto code ships in the installed artifact.

## Docs

When Phase 1 ships: `plugins/vault/docs/overview.md` (the zero-knowledge model, the threat model
incl. the served-JS limitation, the recovery flow, and that identity/unlock is the Sealed Vault);
update `docs/sealed_vault.md`'s consumer list; a one-line contrast in `docs/secret_box.md`.

## Open decisions

- ~~Passwords/Drive one scope vs. separate~~ **Decided: separate** — passwords use their own
  `passwords` scope/keypair and `vault-passwords-kek` context, isolated from Drive and mail/chat.
  Unlocking the password vault is always a deliberate, separate act. Recovery material is
  per-scope (the setup flow generates password-vault recovery independently).
- The auto-lock default (15 min, fixed v1) and Argon2id params (64 MiB / t=3 / p=4) are settled.
