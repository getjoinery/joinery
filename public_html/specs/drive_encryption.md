# Drive Encryption — Client-Custody Vault Consumer

## Status: active — design

End-to-end encryption for Drive files, layered on `specs/drive_core.md` (and through it
`specs/file_blob_layer.md` — ciphertext flows through the blob layer untouched). This is the
Proton-Drive-shaped feature: **client-side zero-knowledge**, the server never holds the key or
sees plaintext. The product target is the single-user instance that opts in, but the design
functions correctly multi-user (sharing encrypted files between users on the same instance
works; it just isn't the sales pitch).

**This is a client-custody consumer of the Sealed Vault** (`specs/implemented/sealed_vault_core.md`). The
per-user **encryption identity and the unlockers come from the vault** — one keypair, one
passkey, one recovery scheme, shared with the password manager. Drive does **not** build its
own keyring; it adds only the file-content encryption and the multi-user sharing on top of the
vault's client-custody identity. (Earlier drafts defined a standalone `uek_user_encryption_keys`
table and a passphrase-only unlock; both are superseded by the vault.)

## Threat model (the vault's client-custody mode, restated)

Defends: a mass-exploited Joinery vulnerability that reads files, dumps the database, or steals
a backup yields **ciphertext plus wrapped keys** — including from the S3 bucket, whose provider
also sees only ciphertext. Server-side `SecretBox` is explicitly not used; if PHP can decrypt,
the exploit that dumps the table dumps the key. This is exactly why Drive is **client-custody,
not server-custody** — the server-side unlock window (mail/chat) would put a decryptable key in
server RAM, which for drive/passwords is the wrong tradeoff.

Does not fully defend: the served-JS problem — an attacker who can alter the JavaScript this
server serves can capture credentials at next unlock. Forward-looking only; snapshots stay
worthless. Mitigation is native/extension clients (a hardening phase, shared with the password
manager). Endpoint compromise is out of scope, as for every product in this category.

The opt-in screen states all of this plainly, plus: **lose every unlocker (passkey devices,
recovery key, passphrase) and the files are unrecoverable.** Correct behavior, said out loud.

## Crypto architecture

The **identity and unlock come from the vault's Drive scope** — its **own** client-custody
keypair (scope `drive`), separate from passwords (a deliberate decision: unlocking Drive must
not open the password vault) and from server-custody mail/chat:

1. **Account keypair = the `drive` client-custody vault keypair** (`uev`/`uew`,
   `uev_scope='drive'`, `uev_custody='client'`, X25519). Public key plaintext at rest; the
   secret key is unwrapped **only in the browser**, via the vault's shared unlockers:
   - **Passkey (primary):** WebAuthn PRF, context **`vault-drive-kek`** — the 32-byte secret
     derived in the authenticator, used **in the browser** as the KEK, never sent to the server.
     One tap. The everyday passkey also holds wrappings for the other scopes, but each scope's
     KEK is a distinct value derived only when that scope is unlocked (vault spec isolation
     rule), so this KEK opens Drive and nothing else.
   - **Recovery key** and **optional passphrase** (Argon2id via the vault's vendored WASM
     module): fallback unlockers, wrapping the same secret key.
   Enrollment and the recovery flow are the vault's shared keyring UX, not drive-specific.
2. **Per-file keys.** Each encrypted file gets a random 256-bit file key (FK). Content is
   encrypted client-side in fixed chunks (AES-256-GCM, per-chunk random IV, chunk index + file
   id in AAD so chunks can't be reordered or transplanted).
3. **Key grants** — **`FileKeyGrant`** (`fkg_file_key_grants`): `fkg_fil_file_id`,
   `fkg_usr_user_id`, `fkg_wrapped_file_key` (FK sealed to that user's **`uev_public_key`** via
   X25519 sealed-box), unique on (file, user). The owner always holds a row. **This table is the
   multi-user story**: sharing = the owner's browser unwraps the FK and re-wraps it to the
   recipient's vault public key — one new row, no content re-encryption. Revocation deletes the
   row (and, as in every E2EE product, cannot un-know a key a past recipient saved — documented,
   not solved).
4. **Unlock.** The vault's client-custody unlock — browser-derived KEK, secret key held as a
   non-extractable `CryptoKey` with idle auto-lock. **Drive's unlock is its own**: it does not
   open passwords (separate keypair) and does not touch the server-custody mail/chat key. One
   passkey tap unlocks Drive; passwords is a separate deliberate unlock.

WebCrypto provides AES-GCM and X25519 natively; the only dependency is the Argon2id WASM lib,
which is **the vault's shared browser module**, not a drive-local copy.

## The vault folder model

Encryption is a property of a **folder subtree**, not an instance mode: `fol_folders` gains
`fol_encrypted` (bool). Files created inside an encrypted folder are always encrypted
(`fil_files` gains `fil_encrypted` bool); moving plaintext files in (or encrypted files out)
re-encrypts/decrypts client-side via download-transform-upload — the server never transforms. A
single-user instance that wants "everything encrypted" makes its top-level folders vaults.

**Filenames and metadata are encrypted too.** For encrypted files, `fil_name`/`fil_title` hold
an opaque identifier; the real name, MIME type, and thumbnail dimensions live in a small
per-file metadata blob encrypted under the FK (`fil_encrypted_metadata` text column). The server
sees sizes and timestamps (padding is out of scope, stated honestly), but not names or types.

## What the platform keeps doing, unchanged

The entire drive_core pipeline operates on bytes it never interprets.

- **Blobs, upload API, dedup** — ciphertext flows through `upload_init`/chunk/`upload_complete`
  like plaintext (sha256 is the ciphertext hash; dedup never matches across encryptions, which
  is correct — matching would leak content equality).
- **Cloud offload + private store** — offloads ciphertext; the verified-private gate is defense
  in depth, not the boundary anymore.
- **Signed URLs + serving** — stream ciphertext; the client decrypts after download.
- **Quotas** — charge ciphertext size.
- **Versioning, trash, change feed, folder tree, grants** — operate on ids and blobs. A
  `FileAccessGrant` grants *access*; a `FileKeyGrant` grants *readability* — both required; the
  share dialog creates them together.

## What moves client-side (the content-understanding layer)

Inventoried up front — every server feature that interprets file content, and its disposition:

1. **Thumbnails / size variants** — server skips encrypted files; the client generates a
   thumbnail before encrypting, encrypts it under the FK, stores it in the existing variant slot
   (`thumb/<stored_name>` holds ciphertext); the UI decrypts after fetch. Non-image files get
   client-side type icons.
2. **Previews** — decrypt-in-browser; no server preview.
3. **Search** — server can't see encrypted names. After unlock the client fetches the small
   encrypted-metadata blobs and searches decrypted names in memory (the vault's client-custody
   in-memory-search pattern). Content-text search inside encrypted files never exists, by design.
4. **Photo system / entity photos** — encrypted files aren't eligible (undisplayable
   server-side); UI excludes them.
5. **joinery_ai / AI surfaces** — cannot read encrypted files; excluded at the model layer
   (`fil_encrypted` check).
6. **Office editing** (`specs/cloud_drive_office_suite.md`) — incompatible (the editor server
   reads plaintext); not offered for encrypted files. Editable-or-encrypted is a per-folder
   choice.
7. **Inbound-email attachment saves, admin_files thumbnails** — encrypted files render as opaque
   entries (icon + size).

## Sharing (works multi-user; sold single-user)

- **User-to-user**: the share dialog creates the `FileAccessGrant` and, client-side, the
  `FileKeyGrant` wrap — for a folder, a batch wrap of every contained file's FK to the
  recipient's vault public key (progress UI; keys are tiny). New files added later by any editor
  are wrapped by the uploader's browser to all current key-grantees (public keys fetched with
  the folder listing). Requires the recipient to have a vault identity (opted in); the dialog
  says so if not.
- **Anonymous share links** (phase): Firefox-Send/Proton pattern — the link carries the FK in
  the **URL fragment** (never sent to the server). `/s/{token}` serves ciphertext + a
  decrypt-in-browser page. Revoking kills the token, not knowledge of the key.
- **Single-user reality**: one keyring, own files, unlock and go. Multi-user machinery adds zero
  friction to the solo path.

## Server surface (small, deliberately)

- **Identity/keyring: none new** — the vault owns `uev`/`uew` and the keyring actions (create,
  unlock, rewrap on passphrase change, recovery). Drive reuses them.
- **Drive-specific:** `FileKeyGrant` data class; `fol_encrypted`, `fil_encrypted`,
  `fil_encrypted_metadata` columns. API actions (`logic/{name}_logic.php` + `{name}_logic_api()`):
  `drive_public_keys` (batch fetch grantees' `uev_public_key` for share wraps),
  `drive_key_grants_sync` (paired with `drive_share_sync`). Skip-resize/skip-AI/skip-editor
  checks on `fil_encrypted`.
- **Opt-in UI** in `/drive` routes into the **vault's** enrollment ceremony if the user has no
  client-custody identity yet (passkey enroll + recovery key), then marks folders encrypted.

## Phases

1. **Vault client-custody identity** — built by the password manager build
   (`specs/implemented/password_vault.md` Phase 1) as **core shared infrastructure**: the shared browser
   crypto module, client-custody keyring actions, and the scope-parameterized
   enrollment/recovery ceremony. (The sealed_vault_core package shipped server-custody only;
   the client-custody layer ships with its first consumer.) Drive consumes it; it is not
   rebuilt here. Drive uses its **own** `drive` scope/keypair and `vault-drive-kek` context —
   separate from passwords.
2. **Encrypted vault folders** — encrypt/decrypt pipeline over the drive_core upload API,
   encrypted metadata + client-side name search, client thumbnails, preview-in-browser,
   skip-list enforcement.
3. **Multi-user key grants** — share-dialog wrap flow, batch folder wraps, `key_grants_sync`.
4. **Anonymous encrypted share links** — fragment-key links on `/s/{token}`.
5. **Hardening** — native/extension clients close the served-JS gap (shared timeline with the
   password manager and the vault's client hardening).

## Docs

On ship: `docs/drive_encryption.md` (threat model verbatim incl. the served-JS limitation and
recovery honesty; the two-table access/readability model; the skip-list); update
`docs/sealed_vault.md`'s consumer list; a contrast line in `docs/secret_box.md`; update
`docs/drive.md` share-dialog section.

## Open decisions (resolve at implementation)

- Chunk size for content encryption (align with the upload-API chunk size; likely 4 MiB).
- Per-folder display-name encryption vs a single encrypted folder-manifest per subtree
  (per-folder blobs proposed — simpler sync).
- Video/audio streaming of encrypted media (MSE decrypt) in v1 or deferred (deferred proposed;
  download-then-play works day one).
- ~~Drive/passwords one scope vs. separate~~ **Decided: separate** — Drive uses scope `drive`,
  passwords uses scope `passwords`, distinct keypairs and PRF contexts. Unlocking Drive never
  opens the password vault.
