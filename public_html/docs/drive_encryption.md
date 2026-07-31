# Drive Encryption — Client-Custody End-to-End Encryption

Drive files inside an **encrypted vault folder** are end-to-end encrypted in the
browser: the server stores and streams ciphertext and never holds a key or sees
plaintext. This is the Proton-Drive-shaped feature — client-side, zero-knowledge.
It layers on [Drive](drive.md) (folders, quotas, versioning, sharing, the
resumable upload API) and, through it, the [file blob layer](drive.md): ciphertext
flows through the upload pipeline, blobs, offload, signed URLs, and quotas
untouched.

Encryption is a client-custody consumer of the [Sealed Vault](sealed_vault.md).
The per-user encryption identity — one X25519 keypair, its passkey / recovery-key
/ passphrase unlockers, and the enrollment and recovery ceremonies — comes from
the vault's `drive` scope. Drive adds only the file-content encryption and the
multi-user key sharing on top of that identity.

## Threat model

Defends: a mass-exploited server vulnerability that reads files, dumps the
database, or steals a backup yields **ciphertext plus wrapped keys** — including
from the S3 bucket, whose provider also sees only ciphertext. Server-side
`SecretBox` is deliberately **not** used anywhere in this feature: if PHP could
decrypt, the exploit that dumps the table would dump the key. That is exactly why
Drive is **client-custody, not server-custody** — the secret key is unwrapped
only in the browser, never held in server RAM (unlike the server-custody
mail/chat scopes, whose unlock window is the right tradeoff for those and the
wrong one here).

Does not fully defend: the **served-JS problem** — an attacker who can alter the
JavaScript this server serves can capture credentials at the next unlock. This is
forward-looking only; snapshots (a stolen backup, a dumped database) stay
worthless. The mitigation is native/extension clients (a later hardening phase,
shared with the password manager). Endpoint compromise is out of scope, as for
every product in this category.

Recovery honesty, stated plainly at opt-in: **lose every unlocker (passkey
devices, recovery key, passphrase) and the encrypted files are unrecoverable.**
There is no support-desk recovery — that is the correct behavior for
zero-knowledge encryption, said out loud.

## Crypto architecture

The identity and unlock come from the vault's `drive` scope — its **own**
client-custody keypair, separate from the password manager's `passwords` scope (a
deliberate decision: unlocking Drive must not open the password vault) and from
the server-custody `user` scope. Each scope has its own X25519 keypair and its
own WebAuthn PRF context (`vault-drive-kek`), so a KEK derived for one scope can
never open another's key.

1. **Account keypair** — the `drive` client-custody vault keypair (X25519). The
   public key is cleartext at rest; the secret key is unwrapped **only in the
   browser**, via the vault's shared unlockers (passkey PRF, recovery key, or
   optional passphrase). Enrollment and recovery are the vault's shared keyring
   UX (`assets/js/vault-keyring.js`), not drive-specific.
2. **Per-file keys.** Each encrypted file gets a random 256-bit **file key (FK)**.
   Content is encrypted client-side in fixed chunks (AES-256-GCM, per-chunk random
   IV; the AAD binds a per-file random **content id** and the chunk index, so a
   chunk can neither be reordered within a file nor transplanted to another).
3. **Key grants** — `FileKeyGrant` (`fkg_file_key_grants`): the FK sealed to a
   user's `drive` vault public key (X25519 sealed box, produced in the owner's
   browser), one row per (file, user), unique on (file, user). The owner always
   holds a row. **This table is the multi-user story**: sharing an encrypted file
   is the owner's browser unwrapping the FK and re-wrapping it to the recipient's
   public key — one new row, no content re-encryption. Revocation deletes the row
   (and, as in every E2EE product, cannot un-know a key a past recipient saved —
   documented, not solved).
4. **Unlock.** The vault's client-custody unlock — a browser-derived KEK, the
   secret key held only in the tab's memory for the page lifetime (client-custody
   has no server unlock window). Drive's unlock is its own: it does not open the
   password vault and does not touch the server-custody mail/chat key.

WebCrypto provides AES-GCM and X25519 natively; the only dependency is the
Argon2id WASM module for the passphrase KDF, which is the **vault's shared
browser module**, not a drive-local copy.

## Access and readability — the two-table model

A `FileAccessGrant` grants **access**; a `FileKeyGrant` grants **readability**.
Both are required to open a shared encrypted file, and the share dialog creates
them together:

- **Access** (`FileAccessGrant`, unchanged from [Drive](drive.md)) — who may
  list, download, and (for an editor) write the file. Enforced server-side.
- **Readability** (`FileKeyGrant`) — who holds the file key. Enforced by the math:
  without the key the ciphertext is opaque. The server stores only the wrapped
  key; it can never read it.

A grantee's Drive listing carries their own wrapped file key inline
(`wrapped_file_key`), so their browser opens the file in one round trip.

## The vault folder model

Encryption is a property of a **folder subtree**, and **a vault is a top-level
tree**: an encrypted folder exists only at the Drive root or inside another
encrypted folder. A folder carries `fol_encrypted`; a folder created under an
encrypted parent inherits it, and creating one under a plaintext parent is
refused (`drive_folder_create`), matching the move rule below. The invariant this
buys: a plaintext folder can never contain an encrypted descendant — so a public
link on a plaintext folder can trust its entire subtree, and the lock icon at the
top of a tree tells the truth about everything under it. Every file created
inside an encrypted folder is encrypted (`fil_encrypted`). A single-user instance
that wants "everything encrypted" makes its top-level folders vaults.

The server never transforms bytes, so a file cannot cross the encryption boundary
in place: moving a plaintext file into a vault, or an encrypted file out of one,
is refused — the client converts by re-uploading (download → decrypt/encrypt →
upload). An encrypted vault folder may move to the Drive root (a top-level
vault) or inside another vault; `drive_move` blocks every other placement.

**Filenames and metadata are encrypted too.** For an encrypted file `fil_name` /
`fil_title` hold an opaque identifier; the real name, MIME type, chunk layout,
content id, and thumbnail flag live in a small per-file metadata blob encrypted
under the FK (`fil_encrypted_metadata`). The server sees sizes and timestamps
(padding is out of scope, stated honestly), but not names or types. **Rename
follows the same rule**: the browser decrypts the metadata, swaps the name,
re-encrypts under the same FK, and submits the blob (`drive_rename` with
`encrypted_metadata`); a plaintext `name` for an encrypted file is refused, so
the chosen name never reaches the server.

## What the platform keeps doing, unchanged

The entire Drive pipeline operates on bytes it never interprets.

- **Blobs, upload API, dedup** — ciphertext flows through `drive_upload_init` /
  chunk transport / `drive_upload_complete` like plaintext. The sha256 is the
  ciphertext hash, so dedup never matches across encryptions (each file has a
  random key and IVs) — correct, since a match would leak content equality.
- **Cloud offload + private store** — offloads ciphertext, including the
  encrypted thumbnail variant: `store_encrypted_variant` records its size key on
  the blob (`fbb_encrypted_variant_key`), and `FileBlob::variant_size_keys()` is
  the single variant inventory every lifecycle path enumerates (offload, cloud
  delete, splitCopy, visibility move, pull-back) — a ciphertext blob's
  octet-stream MIME would otherwise hide the slot from the image-gated paths.
  The verified-private gate is defense in depth, not the boundary anymore.
- **Signed URLs + serving** — stream ciphertext; the client decrypts after
  download. No decrypt hook is registered for the `drive` source, so
  `File::serve_from_path()` streams the raw ciphertext.
- **Quotas** — charge ciphertext size. The per-file tier cap means **plaintext**
  bytes: for a vault destination `drive_upload_init` gates against
  `DriveHelper::encrypted_size_ceiling()` (the cap plus the container's fixed
  32 bytes per 4 MiB chunk), so a file that fits the cap never fails only
  because its destination is encrypted.
- **Versioning, trash, change feed, folder tree, grants** — operate on ids and
  blobs. Versions reuse the file's stable FK and content id, so prior versions
  stay decryptable; the head's metadata follows the current content. The reuse
  is enforced at `drive_upload_complete`: a version upload carrying a wrapped
  key is refused (a fresh key would strand the content behind grants wrapping
  the old one), and the uploader must hold a `FileKeyGrant` — the only proof
  they could re-encrypt under the existing key. Clients produce versions with
  `DriveCrypto.encryptFileWith(file, fkBytes, contentId)`.

## The skip-list — what the server never does to an encrypted file

Every server feature that interprets file content is disabled for `fil_encrypted`
files (`File::is_encrypted()` is the gate):

1. **Thumbnails / size variants** — the server resize pipeline skips encrypted
   files (`File::resize()` and `File::is_image()` short-circuit). The client
   generates a thumbnail before encrypting, encrypts it under the FK, and it rides
   `drive_upload_complete` into the blob's thumb variant slot
   (`FileBlob::store_encrypted_variant()`); the UI fetches and decrypts it. Non-image
   files get client-side type icons.
2. **Previews** — decrypt-in-browser; no server preview.
3. **Search** — the server can't see encrypted names. After unlock the client
   decrypts the metadata blobs in the listing and searches names in memory.
   Content-text search inside encrypted files never exists, by design.
4. **Photo system / entity photos** — encrypted files are undisplayable
   server-side and are not eligible.
5. **AI surfaces** — cannot read encrypted files; excluded at the model layer.
6. **Office editing** — incompatible (the editor server reads plaintext); not
   offered. Editable-or-encrypted is a per-folder choice.

## Sharing

- **Upload seals to the reader set.** A new encrypted file arrives readable by
  everyone who can already reach it: the uploader's browser resolves the
  destination folder's readers (owner + all grant holders) via
  `drive_public_keys` with `folder_id`, seals the FK to each, and submits the
  `wrapped_file_keys` map. The server requires the owner's entry (a vault file
  its owner can never read must not be creatable) and refuses entries for users
  without access (a key-exfiltration primitive otherwise). This is what makes an
  editor's upload into someone else's shared vault readable by the vault's
  owner.
- **User-to-user** — the share dialog creates the `FileAccessGrant` (access) and,
  in the owner's browser, the `FileKeyGrant` wrap (readability). For a folder it
  batch-wraps every contained file's FK to the recipient's vault public key
  (fetched with `drive_public_keys`), enumerating the subtree completely — the
  walk pages through `drive_list` with `offset` while `truncated` is set, and
  aborts the whole key sync loudly on any gap (granting access without keys is
  the failure mode being prevented). Requires the recipient to have a Drive vault
  (opted in); the dialog says so if a member has none. Removing access re-wraps to
  the remaining grantees, so the removed user's key grant is deleted.
- **Anonymous share links** — a single-file mechanism: `drive_link_create` mints
  the token, and the client appends the file key to the URL **fragment** (never
  sent to the server). `/s/{token}#<key>` serves ciphertext plus a
  decrypt-in-browser page (`assets/js/share-decrypt.js`) that reads the fragment
  key, decrypts the metadata to recover the name/type, and decrypts the content on
  download. Encrypted **folders** can't use public links (one fragment can't carry
  many keys) — share them to members instead.

## Server surface

- **Identity / keyring: none new** — the vault owns the keypair, the wrappings,
  and the `vault_client_*` keyring actions. Drive reuses them with scope `drive`.
- **Data** — `FileKeyGrant` (`data/file_key_grants_class.php`); `fol_encrypted`;
  `fil_encrypted`; `fil_encrypted_metadata`.
- **Actions** (`logic/{name}_logic.php` + `{name}_logic_descriptor()`):
  - `drive_public_keys` — two modes: `identifiers` batch-resolves members'
    `drive` vault public keys for share wraps (null for a member with no Drive
    vault); `folder_id` returns the folder's full reader set — owner plus every
    grant holder on the folder or an ancestor — for upload-time sealing
    (requires write access to the folder).
  - `drive_key_grants_sync` — owner reconciles the per-user wrapped file keys for
    one or more files (`file_keys`: `{ file_id: { user_id: wrapped_file_key } }`).
  - `drive_key_grants` — fetch the caller's own wrapped file keys for a set of
    files (their own key material only).
  - `drive_upload_complete` — for a new encrypted file, accepts the opaque
    `encrypted_metadata`, `wrapped_file_keys` (`{ user_id: wrapped_file_key }`,
    owner entry required, every target validated as a reader of the
    destination), and `encrypted_thumbnail` payloads (validated in the logic,
    passed through the boundary untouched); it sets `fil_encrypted` from the
    destination folder, stores the metadata, creates one `FileKeyGrant` per
    entry, and writes the thumbnail into the blob's thumb variant slot. For a
    new **version** of an encrypted file it requires the uploader to hold a
    `FileKeyGrant` and refuses any wrapped-key payload (the version must reuse
    the file's key and content id).
  - `drive_rename` — an encrypted file renames via `encrypted_metadata`; a
    plaintext `name` for one is refused.
  - `drive_folder_create` accepts `encrypted` at the root (forced on under an
    encrypted parent, refused under a plaintext one); `drive_move` enforces the
    encryption boundary; `drive_upload_init` gates a vault destination against
    the ciphertext size ceiling; `drive_list` accepts `offset` for complete
    subtree enumeration.
  - `drive_vault_status` — `{scope: 'drive'}` → `{set_up, public_key,
    key_generation}`, reachable with a session key. The lean probe a native sync
    client needs to seal file keys and to notice a rotation; it carries no
    wrappings, salts, or KDF parameters, because those are unlock material and
    unlocking stays in the browser.

## Device custody — encrypted folders on a sync client

A desktop client that only ever received ciphertext could sync encrypted
folders but never open one. So the vault key is handed to a named device, once,
during the device-link ceremony (`docs/drive.md` § Device linking).

The device generates an X25519 keypair at first launch and sends only the public
half. In the approving browser — the one place the vault can be unlocked — the
unlocked `VaultKeyring` session seals the vault **secret** key to that public
key (`session.sealSecretKeyTo(devicePublicKey)`, the standard sealed-box
primitive) and posts the result. The server stores ciphertext it has no key for,
holds it until the device polls once, and scrubs it.

The sealing lives inside the session closure deliberately: a consumer can hand
the vault key to a device it names, and cannot read the raw bytes itself. On the
device the private half lives in the OS keychain (macOS Keychain, Windows DPAPI
+ Credential Manager, libsecret), and so does the vault secret key once
unsealed — nothing lands on disk in plaintext.

Consequences worth stating plainly: a linked device with the vault key can read
every encrypted file the user can, without a further ceremony, for as long as it
stays linked. That is what "this laptop syncs my encrypted folders" means. The
control is `drive_device_revoke`, which cuts off future access; files already
synced to that computer are already on that computer.

Sharing the key is per device and opt-in — the checkbox on the approval page.
Decline it and the device syncs everything else and simply skips encrypted
folders. A device that never offered a public key is not offered the choice.

### Timestamps

An encrypted file carries no plaintext `fil_content_modified_time`: a
modification time sitting next to ciphertext would tell an observer when the
file was last worked on. The true mtime is a field inside the encrypted metadata
blob, and `drive_upload_init` refuses a `modified_time` parameter when the
destination is a vault folder rather than silently dropping it.

## Client modules

- `assets/js/drive-crypto.js` (`DriveCrypto`) — the content layer on top of
  `VaultCrypto`: per-file key generation, chunked AES-GCM encrypt/decrypt with the
  content-id + chunk-index AAD (`encryptFile` mints a fresh key;
  `encryptFileWith` reuses an existing key + content id for version uploads),
  the encrypted metadata blob, the encrypted thumbnail (`decryptThumbnail` takes
  the raw ciphertext bytes a thumb-URL fetch yields), and the seal/open of a
  file key to a vault public key. The ciphertext container is self-delimiting
  (`uint32 blockLen || IV || ciphertext` per chunk), so decryption needs no size
  metadata.
- `assets/js/drive.js` — the Drive UI: on-demand vault unlock (enroll or unlock
  via the shared `VaultKeyring`), encrypted-folder upload with reader-set key
  sealing, decrypt-download, progressive name/thumbnail decryption in listings,
  rename via re-encrypted metadata, and the share-dialog key wrapping.
- `assets/js/share-decrypt.js` — the anonymous `/s/{token}` fragment-key decrypt
  page.

## Tests

- `tests/functional/drive/drive_crypto_gate.sh` (+ `drive_crypto_roundtrip.mjs`) —
  the `DriveCrypto` round-trip under Node WebCrypto: chunked content, metadata,
  file-key seal/open, version-reuse (`encryptFileWith`), thumbnail decryption
  from raw bytes, empty files, and the AAD transplant / chunk-reorder defenses.
  Node is used because Playwright virtual authenticators have no PRF support, so
  the passkey unlocker can't run in a browser test — the crypto correctness is
  proven directly, and unlock flows are exercised with the passphrase and
  recovery-key unlockers.
- `tests/functional/drive/encryption_test.php` — the server surface: the encrypted
  folder/file model layer and skip-list, `FileKeyGrant` round trips and cascade,
  the key actions (including the reader-set folder mode), the move-boundary guard
  and vault topology, encrypted rename, `drive_list` offset paging, the
  end-to-end encrypted upload pipeline (init → chunk → complete) with
  `wrapped_file_keys` validation, a grantee upload into a shared vault, the
  version key-reuse gates, the ciphertext size ceiling, and the blob variant
  inventory.
