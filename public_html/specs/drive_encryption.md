# Drive Encryption — Client-Side Encrypted Vault Folders

## Status: active — design

End-to-end encryption for Drive files, layered on `specs/drive_core.md` (and
through it `specs/file_blob_layer.md` — ciphertext flows through the blob
layer untouched). The
product target is the **single-user instance** that explicitly opts in, accepting
the documented trade-offs — but the design must **function correctly multi-user**
(sharing encrypted files between users on the same instance works; it just isn't
a sales pitch). This is the Proton-Drive-shaped feature, scoped the way
`specs/password_vault.md` scoped the password manager: client-side zero-knowledge,
end of discussion, with the same threat model and the same honesty about limits.

## Threat model (inherited from the vault spec, restated)

Defends: a mass-exploited Joinery vulnerability that reads files, dumps the
database, or steals a backup yields **ciphertext plus wrapped keys** — including
from the S3 bucket, whose provider also sees only ciphertext. Server-side
`SecretBox` is explicitly not used for this; if PHP can decrypt, the exploit that
dumps the table dumps the key.

Does not fully defend: the served-JS problem — an attacker who can alter the
JavaScript this server serves can capture the passphrase at next unlock. Forward-
looking only; snapshots stay worthless. Mitigation path is identical to the
vault's Phase 4 (native/extension clients, SRI interim). Endpoint compromise is
out of scope as it is for every product in this category.

The opt-in screen states all of this plainly, plus: **lose the passphrase and the
recovery key, and the files are unrecoverable.** That is correct behavior, said
out loud, not buried.

## Crypto architecture

Reuses the vault-agnostic client crypto module that `specs/password_vault.md`
mandates ("build the crypto helpers as a clean, vault-agnostic client module so
a later consumer can reuse them rather than copy them") — same KDF decision
(Argon2id WASM recommended, PBKDF2 fallback), same wrapped-key shape, same
recovery-key pattern. If the vault plugin hasn't shipped first, the shared module
is built here and the vault consumes it later; either order works.

What Drive adds beyond the vault's symmetric design is **asymmetric identity**,
because sharing ciphertext between users requires wrapping keys *to someone else*:

1. **Account keys** — **`UserEncryptionKey`** (`uek_user_encryption_keys`), one
   row per opted-in user: `uek_usr_user_id`, `uek_public_key` (X25519, plaintext),
   `uek_wrapped_private_key` (encrypted under the passphrase-derived KEK),
   `uek_wrapped_private_key_recovery` (encrypted under the recovery key),
   `uek_kdf`, `uek_kdf_salt`, `uek_kdf_params`. Generated in the browser at
   opt-in; the passphrase, KEK, and raw private key never leave it.
2. **Per-file keys.** Each encrypted file gets a random 256-bit file key (FK).
   Content is encrypted client-side in fixed chunks (AES-256-GCM, per-chunk
   random IV, chunk index + file id in AAD so chunks can't be reordered or
   transplanted).
3. **Key grants** — **`FileKeyGrant`** (`fkg_file_key_grants`): `fkg_fil_file_id`,
   `fkg_usr_user_id`, `fkg_wrapped_file_key` (FK sealed to that user's public key
   via X25519 sealed-box), unique on (file, user). The owner always holds a row.
   **This table is the multi-user story**: sharing an encrypted file = the
   owner's client unwraps the FK and re-wraps it to the recipient's public key —
   one new row, no re-encryption of content. Revocation deletes the row (and, as
   in every E2EE product, cannot un-know a key a past recipient already saved —
   documented, not solved).
4. **Unlock.** Distinct from login, identical UX to the vault: enter passphrase,
   derive KEK, unwrap private key, hold in memory with idle auto-lock. If both
   vault and drive encryption are enabled, one passphrase and one unlock cover
   both (shared module, shared keyring UX).

WebCrypto provides AES-GCM and X25519 natively; the only dependency is the
Argon2id WASM lib already justified in the vault spec (pinned,
integrity-checked — a crypto primitive, not a UI framework).

## The vault folder model

Encryption is a property of a **folder subtree**, not an instance mode:
`fol_folders` gains `fol_encrypted` (bool). Files created inside an encrypted
folder are always encrypted (`fil_files` gains `fil_encrypted` bool); moving
plaintext files in (or encrypted files out) re-encrypts/decrypts client-side via
download-transform-upload — the server never transforms. A single-user instance
that wants "everything encrypted" makes its top-level folders vaults; no
instance-wide fork of the platform's behavior exists.

**Filenames and metadata are encrypted too.** For encrypted files, `fil_name` /
`fil_title` hold an opaque identifier; the real name, MIME type, and thumbnail
dimensions live in a small per-file metadata blob encrypted under the FK
(`fil_encrypted_metadata` text column). The server sees sizes and timestamps
(padding is out of scope, stated honestly), but not names or types.

## What the platform keeps doing, unchanged

This is the reason the feature is tractable: the entire drive_core pipeline
operates on bytes it never interprets.

- **Blobs, upload API, dedup machinery** — ciphertext blobs flow through
  `upload_init`/chunk/`upload_complete` exactly like plaintext (sha256 is the
  ciphertext hash; dedup simply never matches across encryptions, which is
  correct — matching would leak content equality).
- **Cloud offload + private store** — offloads ciphertext; the verified-private
  gate still applies (defense in depth, not the security boundary anymore).
- **Signed URLs + serving** — stream ciphertext; the client decrypts after
  download. `mintSignedUrl` unchanged.
- **Quotas** — charge ciphertext size (marginally larger than plaintext; fine).
- **Versioning, trash, change feed, folder tree, grants table** — all operate on
  ids and blobs, all work as-is. A `FileAccessGrant` on an encrypted file grants
  *access*; a `FileKeyGrant` grants *readability* — both are required, and the
  share dialog creates them together.

## What moves client-side (the content-understanding layer)

Inventoried up front — every server feature that interprets file content, and
its disposition for encrypted files:

1. **Thumbnails / size variants** (`File::resize`, `ImageSizeRegistry`) — server
   skips encrypted files entirely. The client generates a thumbnail *before*
   encrypting, encrypts it under the same FK, and uploads it as a sibling blob
   stored in the existing variant slot (`thumb/<stored_name>` holds ciphertext).
   The Drive UI decrypts thumbnails after fetch. Non-image files get client-side
   type icons (from decrypted metadata).
2. **Previews** — decrypt-in-browser (images, PDF, text, audio/video via MSE
   where practical); no server preview.
3. **Search** — server filename search cannot see encrypted names. After unlock
   the client fetches the (small) encrypted-metadata blobs for the vault and
   searches decrypted names in memory — the vault spec's "no server-side search"
   pattern. Content-text search inside encrypted files does not exist, ever, by
   design.
4. **Photo system / PhotoHelper, entity photos** — encrypted files are not
   eligible as entity photos (they'd be undisplayable server-side); UI excludes
   them.
5. **joinery_ai and any AI surface** — cannot read encrypted files; excluded at
   the model layer (`is_encrypted` check), stated in docs.
6. **Office editing** (`specs/cloud_drive_office_suite.md`) — incompatible with
   encrypted files (the editor server must read plaintext); the editor simply
   doesn't offer itself for them. A user chooses per-folder: editable or
   encrypted.
7. **Inbound-email attachment saves, admin_files thumbnails** — encrypted files
   render as opaque entries with icon + size; correct and acceptable.

## Sharing (works multi-user; sold single-user)

- **User-to-user**: share dialog on an encrypted file/folder creates the
  `FileAccessGrant` and, client-side, the `FileKeyGrant` wraps — for a folder,
  a batch wrap of every contained file's FK to the recipient (progress UI; the
  keys are tiny, this is fast). New files added later by anyone with editor role
  are wrapped by the uploader's client to all current key-grantees (grantee
  public keys are fetched with the folder listing). Requires the recipient to
  have opted in (has a keypair); the dialog says so if not.
- **Anonymous share links** (phase): the classic Firefox-Send / Proton pattern —
  the link carries the FK in the **URL fragment**, which browsers never send to
  the server. `/s/{token}` serves the ciphertext + a decrypt-in-browser page.
  Revoking the link revokes access (the token dies) but, like all E2EE sharing,
  not knowledge of the key.
- **Single-user reality**: none of this needs exercising — one keyring, own
  files, unlock and go. The multi-user machinery is present and tested but adds
  zero friction to the solo path.

## Server surface (small, deliberately)

New/changed on the PHP side — everything else is client work:

- `UserEncryptionKey`, `FileKeyGrant` data classes; `fol_encrypted`,
  `fil_encrypted`, `fil_encrypted_metadata` columns.
- API actions (core actions are flat-named, `logic/{name}_logic.php` +
  `{name}_logic_api()`): `drive_keyring_get` / `drive_keyring_create` /
  `drive_keyring_rewrap` (passphrase change: re-wrap private key only — content
  untouched), `drive_public_keys` (batch fetch for share wraps),
  `drive_key_grants_sync` (paired with `drive_share_sync`).
- Skip-resize/skip-AI/skip-editor checks on `fil_encrypted`.
- Opt-in + unlock + recovery-key UI in `/drive` (FormWriter; recovery key shown
  once, Phase mirrors the vault's Phase 2).

## Phases

1. **Shared crypto module + keyring** — Argon2id/KEK/keypair/recovery, opt-in
   flow, unlock/auto-lock. (Coordinates with `specs/password_vault.md` Phase 1;
   whichever builds first owns the module.)
2. **Encrypted vault folders** — encrypt/decrypt pipeline over the drive_core
   upload API, encrypted metadata + client-side name search, client thumbnails,
   preview-in-browser, skip-list enforcement (the integration inventory above).
3. **Multi-user key grants** — share-dialog wrap flow, batch folder wraps,
   `key_grants_sync`.
4. **Anonymous encrypted share links** — fragment-key links on `/s/{token}`.
5. **Hardening** — SRI on the crypto JS; native clients close the served-JS gap
   when the platform's native apps grow a Drive surface (shared timeline with
   vault Phase 4).

## Docs

On ship: `docs/drive_encryption.md` (threat model verbatim including the
served-JS limitation and recovery honesty; the two-table access/readability
model; the skip-list); a contrast line in `docs/secret_box.md` (server-held vs
client-held keys — same clarification the vault spec requires); update
`docs/drive.md` share-dialog section.

## Open decisions (resolve at implementation)

- Chunk size for content encryption (align with the upload-API chunk size so one
  chunk = one encrypted unit; likely 4 MiB).
- Whether folder display names in a vault are encrypted individually or the
  vault keeps a single encrypted folder-manifest per subtree (per-folder blobs
  proposed — simpler sync, one lookup per rename).
- Keyring sharing between vault plugin and drive (one keyring per user serving
  both — proposed — vs. independent keyrings).
- Video/audio streaming of encrypted media (MSE decrypt) in v1 or deferred to a
  phase (deferred proposed; download-then-play works day one).
