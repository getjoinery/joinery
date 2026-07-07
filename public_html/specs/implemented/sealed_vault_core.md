# Sealed Vault — A Core Per-User Encryption Capability

**Status:** Draft / awaiting implementation
**Version:** 1.1
**Extracted from:** `specs/mailbox_encryption_at_rest.md` (v1.3) — mail is the first
consumer; the key hierarchy, unlock window, sealing helper, key-rotation ceremony, unlocker
floor, and backup rules defined there are **promoted to core** by this spec and consumed by
mail unchanged in intent.
**Consumed by (inventory decided up front) — two custody modes, three keypairs (see *Two
Custody Modes* / *Custody-scoped keys*):**
1. **Mail** (`mailbox` encryption at rest) — **server-custody**, scope `user` — built.
2. **AI chat** (`joinery_ai`) — **server-custody**, scope `user` — `specs/joinery_ai_chat_encryption.md`.
3. **Drive / files** (`specs/drive_encryption.md`) — **client-custody**, scope `drive`.
4. **Passwords** (`specs/password_vault.md`) — **client-custody**, scope `passwords`.

All four share the **same unlocker types** (one passkey enrolls to every scope, plus recovery /
optional passphrase) and one keyring UX, but there are **three separate keypairs** — mail+chat
share `user`; Drive and passwords each get their own, so unlocking one never opens another. They
differ in **where the key is used** — server RAM for scope `user`, the browser only for `drive`
and `passwords`.

## Goal — one lock, many consumers

The encryption built for mail is not "mail encryption." It is a **personal vault**: one
lock (the user's passkey), and behind it any content sealed to a key the server never holds,
openable only inside a bounded unlock window. Mail was the first thing in the vault. Chat is
the second; drive and passwords are the third and fourth.

This spec names and extracts that shared core so every future consumer plugs into **one
vault with one unlock** — not four copies of the machinery, four separate unlocks, four
things to audit. That duplication is the product-specific-code trap; a single reusable
capability is the platform-level abstraction.

## What the vault is

A per-user **encryption identity**:
- One X25519 keypair (`sodium_crypto_box_keypair`). The **public** key is cleartext at rest
  and is all a consumer needs to *seal* content — so sealing works while the user is offline.
- The **secret** key exists at rest **only** as wrappings — one per enrolled unlocker
  (passkey PRF, recovery code, optional passphrase). It is unwrapped only transiently, into
  **server RAM** (server-custody) or **the browser only** (client-custody) — see *Two Custody
  Modes*.
- A bounded **unlock**: one unlocker act (a passkey tap) opens the window — held in server
  APCu for server-custody, or a browser `CryptoKey` with idle auto-lock for client-custody.
  One unlock covers every consumer of that key.

The vault owns the *identity and the lock*. Each consumer owns *what it seals and how it
presents locked state*. The boundary is the point of this spec.

## Two Custody Modes

Not all content wants the same model. The vault supports two, chosen **per scope** (a `uev`
row's `uev_custody`), sharing one identity and one unlocker set:

- **Server-custody** (mail, chat). The secret key is unwrapped into **server RAM** (APCu)
  during a `VaultUnlock` window; the **server decrypts**. This is required when the server
  must *process* the content — full-text search, AI triage, threading. It carries the
  active-session residual (a box compromised *during* a window reads what's decrypted).
- **Client-custody** (drive, passwords). The secret key is unwrapped **only in the browser**;
  the **server never holds it and never sees plaintext** (zero-knowledge). The server stores
  opaque ciphertext + wrapped keys and runs no decryption. Used when the server *never* needs
  to read the content — you would never want the server able to decrypt your password vault,
  even in a window. There is **no server-side window**; the unlock lives in the browser tab
  with idle auto-lock. No server hooks, no server-side search (the client fetches blobs and
  searches in memory — feasible because these datasets are small).

**The unlocker *types* are shared; each scope is its own keypair and its own unlock.** A
passkey (PRF), recovery code, or passphrase unlocks any scope — the difference is *where the
derived KEK is used*: server-custody sends the KEK to the server (to unwrap into APCu);
client-custody keeps the KEK in the browser (never transmitting it). But **scopes are
cryptographically separate keypairs** (decided: drive and passwords do not share a key — see
*Custody-scoped keys*), so unlocking one does **not** unlock another. The everyday passkey
enrolls once and holds a wrapping in *every* scope, so unlocking each is still a single tap —
but a *deliberate, separate* tap per domain. The master passphrase that today gates
drive/passwords becomes an **optional fallback** unlocker, exactly as it is for mail/chat.

**Critical isolation rule — one PRF context per scope.** Each scope derives its KEK from its
**own** WebAuthn PRF context, so the KEK for one scope can **never** unwrap another's key:
- `vault-kek` — server-custody (mail + chat). Sent to the server (unwraps into APCu).
- `vault-drive-kek` — the drive client-custody scope. Browser-only.
- `vault-passwords-kek` — the passwords client-custody scope. Browser-only.
This is load-bearing twice over: it keeps a server-captured server-custody KEK from opening any
zero-knowledge scope, **and** it keeps the drive unlock from opening passwords. One
authenticator can evaluate the salts it needs, but a scope's KEK is derived only when that scope
is actually unlocked, and a client-custody KEK is never transmitted.

**What each mode adds beyond the shared identity/unlockers:**
- Server-custody: `VaultUnlock` (APCu), server-side `VaultCrypto`, the two server hooks (File
  decrypt, sealed-field model), server-side search (mail's FTS index).
- Client-custody: a **browser crypto module** (WebCrypto AES-GCM/X25519 + a vendored Argon2id
  WASM for the passphrase-fallback KDF), no server decryption, in-memory client search, and
  the served-JS residual (mitigated by native/extension clients — the consumers' own hardening
  phase). The mail spec's *deferred client-side-crypto fork* **is** this mode; drive and
  passwords are where it ships, because their content is never server-processed.

## The shared core (promoted to core, was mail-plugin-local)

All of the following move from the mail plugin to core `includes/` / core tables and are
reused verbatim in intent by every consumer. The mail encryption executor package retargets
to these (a cheap edit — nothing is implemented yet):

- **`includes/SealedBox.php`** — already core. The `crypto_box_seal` envelope + AEAD
  (`xchacha20poly1305_ietf`) with row-binding additional data + KEK wrapping + recovery/
  passphrase KEK derivation. Unchanged.
- **The key hierarchy** — the per-user keypair record and the per-unlocker wrappings, moved
  from the mail plugin's `iek`/`iew` tables to **core** tables (`uev_user_encryption_vaults`,
  `uew_user_encryption_wrappings`; see *Schema*). One vault row per user; many wrapping rows.
- **The unlock window** — `includes/VaultUnlock.php` (was the mail plugin's `MailboxUnlock`):
  the APCu-backed secret-key store keyed to the session, TTL = idle timeout, activity-
  extended, wiped on close. **One window, one key, every consumer.** End-of-window *policy* —
  the ending events (heartbeat loss, IP change, credential events, explicit lock), the
  per-level idle/absolute caps, and arming requirements (user verification required) — is
  consumer-defined; the mail consumer's policy is `specs/mailbox_security_levels.md`
  § The Unlock Window, and `VaultUnlock` exposes the wipe hooks those events call. The
  three host-hardening
  facts (anonymous `apc.mmap_file_mask`, coredumps off, swap off/encrypted) are a **core**
  provisioner health check, not a per-consumer one.
- **The unlocker set + floor** — passkey PRF wrappings (per credential), ≥128-bit recovery
  codes (keyed-hash KEK), optional Argon2id passphrase; the structural unlocker floor
  (refuse a wrapping delete that would strand the vault) and the passkey revocation-veto
  hook. All core.
- **The key-rotation ceremony** — re-seal to a fresh keypair after suspected compromise. The
  ceremony is core, but it must call *each consumer* to re-seal its own per-item DEKs (a
  consumer callback — see the contract).
- **Backup rules** — key-material tables never excluded; the honest cost of a lost vault.
  Core.

## The passkey context is `vault-kek`, not `mail-kek`

`specs/passkeys_core.md`'s consumer #3 was "mail unlock" with PRF context `mail-kek`. It
becomes **three** contexts, one per vault scope (the isolation rule in *Two Custody Modes*):
`vault-kek` (server-custody, mail + chat — sent to the server), `vault-drive-kek` (Drive
client-custody — browser-only), and `vault-passwords-kek` (passwords client-custody —
browser-only). Distinct contexts guarantee one scope's KEK can never open another's key. All
three are additions to the passkeys package's consumer inventory; the authenticator derives a
scope's salt only when that scope is unlocked.

## The consumer contract

### Server-custody consumers (mail, chat)

A server-custody consumer seals its content to the vault and reads it in-window. The vault
exposes:

- **`VaultCrypto`** (core, the generic AD orchestration that was the mail plugin's
  `MailboxCrypto`): `newItemDek()`, `sealItemDek($dek, $public_key)`, `sealField($plaintext,
  $dek, $ad)`, `openItemDek($sealed, $pub, $secret)`, `openField($blob, $dek, $ad)`. The
  **AD is the consumer's row-binding string** — the consumer supplies a stable per-item
  identity (e.g. `mail:{message_id}:{field}`, `chat:{message_id}:body`) so a ciphertext
  can't be spliced onto another row. The convention lives in one tested place.
- **`VaultUnlock::secretKey($user_id): ?string`** — the in-window key, or null when locked.
  Every content read calls this and treats null as "locked" (surface the one-tap prompt, not
  an error).
- **The sealed-`File` decrypt hook** (from the mail package) — generic: a `File` whose
  source declares a consumer's decryptor is decrypted in-window before serving. Any consumer
  with sealed attachments reuses it.
- **The sealed-field model hook** (from the levels/AI work) — a model that declares sealed
  fields is read through an in-window decrypt accessor by generic readers
  (`ModelQueryExecutor`, exports). Any consumer's content model reuses it.
- **A re-seal callback** — the consumer registers how to re-seal its per-item DEKs during
  key rotation (the ceremony walks every consumer).

The consumer implements: **what content seals** (its own fields/bytes), **its level
semantics and scope** (mail: per domain; chat: per workspace/conversation — the consumer's
choice), and **its locked-state surfaces** (list placeholders, content-action unlock prompt,
native `locked` flag). The vault provides everything below the content.

### Client-custody consumers (drive, passwords)

A client-custody consumer never hands the server plaintext or the key. The vault provides the
**shared identity and unlockers**, not server-side decryption:
- The `uev`/`uew` rows (the client-custody keypair + its unlocker wrappings) — the same
  identity storage, but the secret key is unwrapped **only in the browser**.
- A **browser crypto module** (WebCrypto AES-GCM/X25519 + vendored Argon2id WASM for the
  passphrase fallback; passkey PRF via WebAuthn, the scope's own context — `vault-drive-kek` or
  `vault-passwords-kek`) — one shared client module, not one per consumer. It performs unlock
  (derive KEK → unwrap the scope's client key → hold in a
  non-extractable `CryptoKey` with idle auto-lock), and seal/open of the consumer's data-keys.
- The passkey/recovery/passphrase enrollment UI and the recovery-key flow — shared with
  server-custody (one keyring UX), differing only in that the KEK stays browser-side.

The consumer implements: its content encryption (drive: per-file keys + chunked AES-GCM;
passwords: per-entry blobs), its sharing/entry model (drive `FileKeyGrant`; password entries),
and its opaque-blob server actions (the server stores/returns ciphertext, never inspecting it).
The vault does **not** provide `VaultUnlock`/`VaultCrypto`/the server hooks to a client-custody
consumer — those are server-side and would defeat zero-knowledge.

## One unlock opens everything — the tradeoff, and where custody bounds it

Within **server-custody**, all consumers share one keypair, so a single unlock puts that one
secret key in RAM and any server-custody consumer's code can decrypt its content for the
window's duration. That is the UX win (one tap) and the honest cost: **an attacker resident
during an active server-custody window reads every server-custody consumer's in-window
content** (mail *and* chat), not just one. It is bounded the same way as the mail spec
(idle-timed window, seal-after-use, rotation) — the deliberate consequence of one server-side
vault over separate ones.

**Client-custody content is not in that blast radius.** Because its key never enters server
RAM, a server compromise — even during an active server-custody window — cannot read the
drive/password scopes. That is the whole reason those consumers are client-custody: the
crown-jewels (passwords) are structurally outside the server's reach, at rest and in use.

## Custody-scoped keys (three separate keypairs — decided)

A user holds **three separate keypairs**, one per `uev_scope`:
- **`user`** — server-custody — mail + chat (they share, since both are server-side and one
  window serving both is the UX win there).
- **`drive`** — client-custody — Drive files.
- **`passwords`** — client-custody — the password manager.

Drive and passwords are **deliberately separate keypairs** (decided), not a shared client
scope: unlocking your Drive must not also open your password vault. Each has its own PRF context
(above), its own unlocker wrappings in `uew`, and its own browser unlock with independent idle
auto-lock. The everyday passkey enrolls a wrapping in all three, so each unlock is one tap — but
three deliberate domains, not one. The honest cost of the separation: **recovery material is
per-scope** (a scope's recovery codes / passphrase unlock only that scope), so the setup flow
generates and asks the user to store recovery for each protected domain — more to manage, which
is the price of isolating the crown jewels. `uev_scope` + `uev_custody` express the two
dimensions (which keypair, and where its key is used).

## Schema (core, via data-class `$field_specifications`)

- **`uev_user_encryption_vaults`** (was mail `iek`): `uev_usr_user_id`, `uev_scope`
  (varchar, default `'user'`; unique with user id), `uev_custody` (varchar,
  `'server'`|`'client'`, default `'server'`), `uev_public_key`, `uev_salt` (KDF salt for the
  passphrase/recovery unlockers), `uev_kdf_params` (JSON — the Argon2id params for
  client-custody's browser KDF; null for server-custody, which uses libsodium defaults),
  `uev_key_generation`, created/updated. The secret key is never stored here — only wrapped in
  `uew`. A client-custody `uev` row's secret key is never unwrapped server-side.
- **`uew_user_encryption_wrappings`** (was mail `iew`): `uew_uev_user_encryption_vault_id`,
  `uew_unlocker_type` (`passkey`/`recovery`/`passphrase`), `uew_pkc_credential_id`,
  `uew_wrapped_secret_key`, `uew_key_generation`, `uew_is_used`, `uew_label`, times,
  `uew_delete_time`.
- Consumer per-item columns (the sealed DEK, e.g. mail's `iem_sealed_key`) stay on the
  **consumer's** rows — the vault holds the identity, the consumer holds its sealed items.

Any consumer-specific high-water marks, index blobs, etc. stay consumer-side (e.g. mail's
FTS index is a mail concern, not the vault's).

## Migration note (retarget the mail package)

The mail encryption executor package's **Phase 2** (key hierarchy `iek`/`iew`) and **Phase
3** (`MailboxUnlock`) are superseded by this core vault: mail becomes a consumer that reads
`VaultUnlock` and seals via `VaultCrypto`. Retarget those phases when the vault lands (small
edits, nothing built yet). Mail's *content* sealing, ingest reorder, FTS index, and
locked-state contract are unchanged — only the key-hierarchy-and-window layer moves under it.

## Documentation to Update

- New `docs/sealed_vault.md` — the capability, the consumer contract (`VaultCrypto`,
  `VaultUnlock`, the two hooks, the re-seal callback), the one-unlock-all tradeoff, and the
  second-keypair escape hatch.
- `docs/secret_box.md` — cross-reference the vault as the per-user asymmetric layer above
  `SecretBox`/`SealedBox`.
- `docs/passkeys.md` — the `vault-kek` context (renamed from `mail-kek`).

## Open Items to Confirm During Implementation

- Final core-table names (`uev`/`uew` are working prefixes).
- Whether the mail package's retarget is folded into this spec's executor package or done as
  a diff to the mail package — decide when writing the executor package.
- The exact re-seal-callback registration mechanism (a signal, or a registry the rotation
  ceremony consults) — mirror the revocation-veto hook's mechanism for consistency.
- Whether `uev_scope` ships now (enabling the passwords escape hatch later) or is added when
  the passwords consumer is built — recommend shipping the column now so the shape is fixed.
