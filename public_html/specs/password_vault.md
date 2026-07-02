# Self-Hosted Password Vault (zero-knowledge)

## Status: active — design

The fourth leg of the self-hosted Proton-suite replacement, alongside mail (built),
calendar (built — `specs/implemented/scheduling_system.md`), and drive
(`specs/drive_core.md`). This is the **vault / password
manager** — the piece flagged as "genuinely difficult and scary," now scoped down to
something tractable by fixing one decision up front: it is **client-side
zero-knowledge**, end of discussion.

## The threat model is the whole design

The vault is designed around one specific, deliberately chosen threat: **a Joinery
vulnerability gets mass-exploited by automated attacks, and the attacker reads files,
dumps the database, or steals a backup.** On a single Linux + Apache + full Joinery
codebase, that attack surface is large and the exploitation is automated — so any key
the OS or the PHP process can reach is, for vault purposes, already compromised.

The conclusion that drives everything below: **the server must never hold the key, and
must never see plaintext.** Server-side encryption (e.g. the core `SecretBox` helper)
is therefore explicitly **not** used here — if PHP can decrypt the vault, the same
exploit that dumps the table dumps the means to read it. SecretBox remains the right
tool for at-rest secrets the *server itself* must use; it is the wrong tool for a
password manager.

### What this defends

A DB dump, file read, stolen backup, or SQL-injection exfiltration yields only
**ciphertext plus a wrapped key**. Vault security then reduces to the cost of
brute-forcing the master password offline — which is why the KDF choice (below) is
load-bearing. This is the dominant automated threat, and it is fully defended.

### What it does NOT fully defend (stated honestly, up front)

A web vault's crypto code is *served by the same server being defended*. An attacker
who compromises Joinery deeply enough to **alter the JavaScript it serves** can ship
malicious unlock code that captures the master password the **next time it is typed**.
This is forward-looking, not retroactive — a disk/database snapshot still yields
nothing, and secrets stored before the compromise are not exposed by the dump itself.

This residual risk is the reason mature competitors (Proton Pass, Bitwarden, 1Password)
ship browser extensions and native apps, whose code is not re-fetched from the server
on every unlock. The mitigation path here is the same — an extension/native client is
**Phase 4 hardening**. Nothing served from the same origin can close this gap
(Subresource Integrity included — the hash lives in HTML the same server serves), so
the risk is fully open until Phase 4 ships. It is a known limitation, documented, not
a v1 blocker.

### Out of scope

Endpoint compromise (a keylogger on the user's own machine) defeats any password
manager and is not addressed by any product in this category.

## Crypto architecture

Standard wrapped-key design (the Bitwarden/1Password shape), all client-side:

1. **Key derivation.** `KEK = Argon2id(master_password, per-user salt, params)`,
   computed in the browser. The master password and KEK never leave the browser and
   are never sent to the server in any form.
2. **Data key.** A random 256-bit `DEK` is generated once. Every secret is encrypted
   with AES-256-GCM under the DEK.
3. **Wrapping.** The server stores `wrapped_DEK = AES-GCM(KEK, DEK)`. The KEK→DEK
   indirection lets the master password change without re-encrypting every entry —
   only the wrapped DEK is re-wrapped.
4. **Per-entry encryption.** Each entry is one AES-GCM blob over a JSON record
   (`{type, title, username, password, url, notes, totp_seed, …}`) with its own random
   IV. Field contents are inside the blob — including the coarse `type`
   (login/note/card): the list renders only after client-side decryption, so a
   cleartext type column would never be read, only leak. The server sees an opaque
   ciphertext.

   **Blob format (everywhere).** Every encrypted value — wrapped DEK, recovery-wrapped
   DEK, each entry — is one self-contained string: `base64(IV ‖ ciphertext)`. The
   client prepends the fresh 12-byte IV before encoding and splits it back off after
   decoding. One `encrypt() → blob` / `blob → decrypt()` contract, no side-channel IV
   columns, and the reusable crypto module (see build-generally notes) exposes exactly
   that.
5. **Unlock.** Distinct from Joinery login. After login, the user enters the master
   password; the browser derives the KEK, unwraps the DEK, and holds it in memory
   (a non-extractable `CryptoKey` where the platform allows) for the session, with an
   idle **auto-lock** that zeroes it.
   **Auto-lock: 15 minutes idle, fixed in v1** (the Bitwarden default; 1Password uses
   10). Short enough for an unattended machine, long enough not to punish a long
   master password into being shortened. Closing the tab or browser is already an
   implicit lock — the DEK exists only in that tab's memory — and an explicit lock
   control just drops the key reference. A user-configurable timeout is Phase 3
   quality-of-life, not v1.
6. **Recovery.** A high-entropy recovery key, shown once and stored offline by the
   user, independently wraps the DEK (`wrapped_DEK_recovery = AES-GCM(recovery_key,
   DEK)`). Forget the master password → unwrap via recovery key → set a new master.
   Lose both → the data is unrecoverable, which is the correct and honest behavior.

**No server-side search.** Unlike mail (where encryption-at-rest fought full-text
search), a vault is small: the client fetches all blobs on unlock and
searches/decrypts in memory. No server index, no blind-index gymnastics. This is the
same reason the feature that looked hardest is actually the simplest to keep
zero-knowledge.

### Key-derivation choice (the one real decision)

The platform already standardizes on **Argon2id** server-side
(`data/users_class.php` → `PASSWORD_ARGON2ID`). Browser WebCrypto provides PBKDF2 and
AES-GCM natively but **not** Argon2, so matching that choice client-side requires
vendoring an Argon2 WASM library.

**Decided: Argon2id via a vendored copy of `argon2-browser`** (the reference Argon2 C
code compiled to WASM). Memory-hard; the strongest available defense for the exact
case this vault is built around (offline brute-force of the master password against a
stolen wrapped key), and consistent with the server-side choice. The library is what
Bitwarden ships for its Argon2 KDF option and what KeeWeb uses — this exact
derive-vault-keys-in-a-browser role, production-tested for years.

This is a deliberate one-off exception to the vanilla-JS-by-default rule — a crypto
primitive, not a UI framework. Terms of the exception: the pinned `.wasm` and its JS
loader are **vendored into `plugins/vault/assets/`** (no CDN, no runtime npm — served
like any other plugin asset), with the upstream version and file hashes recorded in
the plugin so any later change to the binary shows up as a git diff. That vendoring-
time check is the supply-chain control that works here (runtime SRI does not, per the
threat model above). Upstream moves slowly; that is acceptable for a frozen algorithm
(Argon2 is unchanged since RFC 9106), and the vendored copy means upstream abandonment
cannot break us.

PBKDF2-HMAC-SHA256 (WebCrypto-native, zero deps) was considered and rejected: it gives
up memory-hardness against GPU/ASIC cracking of a stolen wrapped key — the exact
attack this vault is designed around.

**Parameters: 64 MiB memory, 3 iterations, 4 lanes** — the RFC 9106 second
recommendation and Bitwarden's default. OWASP's lower floor (19 MiB, t=2) is
calibrated for high-volume server logins; an unlock happens at most every 15 minutes
and can afford ~0.5–1.5 s of derivation on ordinary hardware. The WASM build is
single-threaded, so the 4 lanes run serially — unlock is somewhat slower, the
attacker's job no easier; Bitwarden ships the same configuration. Because the
parameters are stored per-user in `vlk_kdf_params`, raising them later only requires
re-deriving and re-wrapping the DEK at next unlock (entries untouched) — the
client-side mirror of the server's `password_needs_rehash` upgrade path.

## Data model

A self-contained **plugin** (`/plugins/vault/`), since it is an isolable module with
its own admin/profile surface. Two data classes following the active-record
`$field_specifications` convention:

- **`VaultKeyring`** (`vlk_vault_keyring`), one row per user (`vlk_usr_user_id`
  unique — a duplicate keyring with a second wrapped DEK would strand entries
  unreadably, so the DB enforces the invariant): `vlk_usr_user_id` (FK),
  `vlk_kdf` (`argon2id`), `vlk_kdf_salt`, `vlk_kdf_params` (JSON: memory, iterations,
  parallelism), `vlk_wrapped_dek` (master-wrapped), `vlk_wrapped_dek_recovery`
  (nullable until a recovery key is generated), timestamps. Holds **only** crypto
  material the server cannot use without the master password.
- **`VaultEntry`** (`vle_vault_entries`): `vle_usr_user_id` (FK), `vle_ciphertext`
  (a blob in the standard format above), created/updated timestamps. The row carries
  no searchable plaintext — title, username, URL, etc. all live inside the blob.

**Deletion.** Both classes declare `$foreign_key_actions` cascade on their user FK, so
deleting a user removes their keyring and entries. Entries also get `vle_delete_time`
for soft delete — trash/restore in the UI, permanent purge via the platform deletion
system — because accidentally deleting a login is exactly the mistake that should be
recoverable, and trashed ciphertext is no less protected than live ciphertext. The
keyring is hard-delete only; without its user it is meaningless.

Both tables created automatically by `update_database` from the specs; no schema
migrations.

### Server surface

The server side is a set of `_logic_api()` actions in `plugins/vault/logic/` — get
keyring, save keyring, list entries, save entry, delete entry — called from the vault
page's JavaScript over `/api/v1` with the browser-session credential, per the platform
API rule (`docs/api.md`). No `/ajax/` endpoints, no page-postback forms. The server
treats every payload as opaque: it stores and returns base64 blobs and never inspects,
validates, or logs their contents. The same actions are reachable by the native-app
transport, which is exactly the surface a Phase 4 native client needs.

## Work

### Phase 1 — Crypto core + usable vault (the v1)

Keyring creation on first use (derive KEK, generate + wrap DEK); master-password
unlock with in-browser KEK derivation and DEK unwrap; idle auto-lock. CRUD on
encrypted entries (the server only ever stores/returns opaque blobs). List view that
decrypts in memory, in-memory search, copy-to-clipboard. This alone is a usable,
zero-knowledge vault.

### Phase 2 — Recovery

Recovery-key generation (shown once), recovery-wrapped DEK, and the
forgot-master-password → re-establish flow.

### Phase 3 — Quality-of-life

Client-side password generator; TOTP seed storage with in-browser code generation
(turns the vault into an authenticator too); encrypted import/export
(Bitwarden/1Password/CSV in, encrypted backup out); user-configurable auto-lock
timeout.

### Phase 4 — Hardening (addresses the served-JS residual risk)

Browser extension (and/or native client) so unlock code is not re-fetched from the
server per use, with autofill as the headline UX win. Note the existing iOS app shell
does **not** qualify: it renders server-served web views, so vault JS inside it is
still fetched from Joinery per load. A Phase 4 client counts only if the unlock/crypto
code ships in the installed artifact (native crypto or bundled JS). This is where the
one honest limitation above gets closed; it is intentionally last.

## Build-generally notes

- The **wrapped-key + client-side KDF** pattern is reusable: the same primitive could
  back any future end-to-end-encrypted feature (encrypted notes, encrypted drive
  files). Build the crypto helpers as a clean, vault-agnostic client module so a later
  consumer can reuse them rather than copy them.
- This is the inverse of `SecretBox`'s niche (server-held key for secrets the server
  must read). Keep the two clearly separated in docs so neither is reached for in the
  other's situation.

## Docs

No doc lands with this spec (`docs/` describe current state only). When Phase 1 ships,
add `plugins/vault/docs/overview.md` documenting the zero-knowledge model, the threat
model (including the served-JS limitation), and the recovery flow — and a one-line
contrast in `docs/secret_box.md` clarifying when to use each.

## Open decisions

None — the KDF (Argon2id via vendored `argon2-browser`), its parameters (64 MiB /
t=3 / p=4), and the auto-lock default (15 minutes, fixed in v1) are all settled above.
The spec is ready to implement.
