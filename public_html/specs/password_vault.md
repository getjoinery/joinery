# Self-Hosted Password Vault (zero-knowledge)

## Status: active — design

The fourth leg of the self-hosted Proton-suite replacement, alongside mail (built),
calendar (`specs/scheduling_system.md`), and drive. This is the **vault / password
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
**Phase 4 hardening**, with Subresource Integrity as a partial interim measure. It is
a known limitation, documented, not a v1 blocker.

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
   (`{title, username, password, url, notes, totp_seed, …}`) with its own random IV.
   Field contents are inside the blob; the server sees an opaque ciphertext.
5. **Unlock.** Distinct from Joinery login. After login, the user enters the master
   password; the browser derives the KEK, unwraps the DEK, and holds it in memory
   (a non-extractable `CryptoKey` where the platform allows) for the session, with an
   idle **auto-lock** that zeroes it.
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

- **Recommended — Argon2id (WASM).** Memory-hard; the strongest available defense for
  the exact case this vault is built around (offline brute-force of the master
  password against a stolen wrapped key). Consistent with the server-side choice.
  Catch: a new client-side dependency, which is a deliberate exception to the
  vanilla-JS-by-default rule — justified because a crypto primitive is not a UI
  framework, but it must be a reviewed, pinned, integrity-checked dependency.
- **Fallback — PBKDF2-HMAC-SHA256 (WebCrypto, zero deps).** No new dependency, but
  not memory-hard; needs a high iteration count (OWASP-current) and is weaker against
  GPU/ASIC cracking. Acceptable only if vendoring the WASM lib is rejected.

## Data model

A self-contained **plugin** (`/plugins/vault/`), since it is an isolable module with
its own admin/profile surface. Two data classes following the active-record
`$field_specifications` convention:

- **`VaultKeyring`** (`vlk_vault_keyring`), one row per user: `vlk_usr_user_id` (FK),
  `vlk_kdf` (`argon2id`), `vlk_kdf_salt`, `vlk_kdf_params` (JSON: memory, iterations,
  parallelism), `vlk_wrapped_dek` (master-wrapped), `vlk_wrapped_dek_recovery`
  (nullable until a recovery key is generated), timestamps. Holds **only** crypto
  material the server cannot use without the master password.
- **`VaultEntry`** (`vle_vault_entries`): `vle_usr_user_id` (FK), `vle_ciphertext`
  (base64 AES-GCM blob), `vle_iv`, created/updated timestamps. The row carries no
  searchable plaintext — title, username, URL, etc. all live inside the blob.

Both tables created automatically by `update_database` from the specs; no schema
migrations.

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
(Bitwarden/1Password/CSV in, encrypted backup out).

### Phase 4 — Hardening (addresses the served-JS residual risk)

Browser extension (and/or native client) so unlock code is not re-fetched from the
server per use, with autofill as the headline UX win. Subresource Integrity on the
vault JS as a partial interim measure. This is where the one honest limitation above
gets closed; it is intentionally last.

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

## Open decisions (resolve at implementation)

- **KDF: Argon2id-WASM (recommended) vs PBKDF2-native.** Settle the vanilla-JS
  dependency exception with the WASM choice; pin and integrity-check the lib.
- Auto-lock idle timeout default.
- Whether a coarse entry `type` (login/note/card, for list icons) is stored in clear
  as low-sensitivity metadata or kept inside the encrypted blob.
- Argon2id parameter tuning (memory/iterations/parallelism) for an acceptable
  in-browser unlock latency on typical hardware.
