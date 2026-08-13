# Drive Encryption — the two encrypted levels

Drive encrypts files at two of its three [protection levels](drive.md#protection-levels),
and they differ in exactly one thing: **who holds the key.**

- **Fortress** is client custody. Files inside a Fortress folder are end-to-end
  encrypted in the browser: the server stores and streams ciphertext and never
  holds a key or sees plaintext. This is the Proton-Drive-shaped feature —
  zero-knowledge, and therefore opaque to every server-side capability.
- **Private** is server custody. Files are sealed at rest under a per-file key
  wrapped to the owner's vault, and the server opens them only inside the
  owner's unlock window. A stolen database or backup yields ciphertext; a live
  server during the owner's window does not — and that is precisely why
  previews, thumbnails and AI still work there.

Both layer on [Drive](drive.md) (folders, quotas, versioning, sharing, the
resumable upload API) and, through it, the [file blob layer](drive.md):
ciphertext flows through the upload pipeline, blobs, offload, signed URLs, and
quotas untouched.

Both are consumers of the [Sealed Vault](sealed_vault.md), each using the scope
that matches its custody: Fortress uses the `drive` scope (client custody — the
secret key is unwrapped only in the browser), Private uses the server-custody
`user` scope, the same vault mail and chat seal to. The per-user encryption
identity — one X25519 keypair per scope, its passkey / recovery-key / passphrase
unlockers, and the enrollment and recovery ceremonies — comes from the vault.
Drive adds only the file-content encryption on top.

Sections below marked **Fortress** describe the client-custody path; the
[Private files](#private-files--server-custody) section covers server custody.

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

## Private files — server custody

A Private file's bytes on disk are a **`SealedFileContainer`**
(`includes/SealedFileContainer.php`), sealed under a random 32-byte per-file key
that is wrapped to the owner's server-custody vault public key and stored on the
file row (`fil_sealed_key`, with `fil_sealed_owner_user_id` and
`fil_key_generation`). No database column holds ciphertext — the row's key exists
to seal the blob and its thumbnail variant — so `File` declares no
`$sealed_fields` and records its wrapping through
`SystemBase::recordSealedKey()`.

**The container is the browser's chunk scheme, in PHP.** A header (magic,
version, content id, plaintext chunk size) is followed by the same framing
`DriveCrypto` produces: `uint32be blockLen || IV[12] || AES-256-GCM(ct||tag)` per
4 MiB plaintext chunk, with `AAD = "{content_id}:{chunk_index}"`. One format
means one set of overhead math (`DriveHelper::encrypted_size_ceiling()`, 32 bytes
per chunk) across both custody models, and a chunk can be neither reordered
within its file nor transplanted into another. Every block but the last is a full
chunk, so the ciphertext offset of chunk *i* is arithmetic rather than a scan — a
Range request for the tail of a large file reads two chunks, not the whole file.

**Writing needs no unlock window.** Sealing uses only the owner's public key, so
an upload into a Private folder succeeds with the vault locked, from any session
with write access. `DriveSealed::createSealedFile()` runs the order that matters:
sniff the type from the plaintext (a container always sniffs as
`application/octet-stream`), mint the key and seal into a container in a temp
file, create the `File` from that container — so plaintext never lands in the
blob store at all — record the wrapping, then render the thumbnail from the
still-present plaintext and store it sealed in the encrypted variant slot. The
blob measures ciphertext, which is what quota is charged on; the plaintext size
is recorded in `fil_plain_size_bytes` for display and Range arithmetic. A new
version reuses the file's existing key, which is the one write that needs the
window.

**Reading needs the window.** `DriveSealed` registers a streaming decrypt hook
(`File::registerStreamingDecryptHook`), so `File::serve_from_path()` advertises
`Accept-Ranges: bytes` and answers 206 against plaintext offsets — video seek and
resumable downloads work. The key is resolved before the first header is written,
so a closed window is a clean 423 rather than a truncated body. Unwrapping the
key calls `SealedEgressGuard::markHot()` with `fil:{id}:content`, which is why an
AI turn that reads a Private file falls under the hot-turn rule with no
Drive-specific egress code.

The window is bound to the browser session that opened it (the APCu key is
`vault:{session_id}:{user_id}:{scope}`), so **a request carrying no session can
never open a Private file** — it answers 423 whatever windows the owner holds
elsewhere. Signed URLs authorize the *fetch*, not the *unsealing*; see
[File Signed URLs](file_signed_urls.md#sealed-content).

**Truncation is caught by the row, not by the cipher.** The per-chunk AEAD binds
a chunk to its file and to its index, so chunks cannot be reordered or
transplanted — but nothing in the framing states how many chunks a container
should have, and a container truncated on a block boundary still authenticates
every chunk it has left. `fil_plain_size_bytes` is the second witness:
`DriveSealed::assertContainerIsWhole()` compares it against the length the
container derives from its own size before the first header goes out, and a
disagreement is a 404. It applies to the original only — a variant's plaintext
size is known to nothing but its own container.

**Level changes** stream through a temp file and are copy-on-write aware
(`File::replace_bytes_from_path()`), so a deduped blob is split before it is
rewritten and a plaintext twin elsewhere is never turned into ciphertext behind
its owner's back.

**A conversion resumes from the bytes, never from the row alone.** A raise writes
the key wrapping *before* it swaps the bytes, so the worst a killed pass can
leave behind is a plaintext file carrying a spare wrapping, which the next pass
mints over. On entry both directions check what is actually on disk
(`SealedFileContainer::looksSealed()`): a Standard row over a container is a
raise that got as far as the swap and is finished in place (the plaintext size
comes from the container's length, so this stays locked-safe); a Private row over
plaintext is a lower in the same position. A container with no wrapping on its
row is refused rather than sealed again — a second layer over an inner key that
no longer exists would end the file — and the batch leaves it in the backlog with
a log line. A resumed raise has no plaintext left to render a thumbnail from, so
the file keeps its type icon.

**A blob's stored type describes its bytes.** Sealing sets `fbb_mime_type` to
`application/octet-stream`, because that is what a container sniffs as and
because the blob layer's resize, variant-cleanup and offload paths all branch on
it. The file's own `fil_type` keeps the real type — that is what the member, the
listings and the serve path read. Unsealing restores the blob's type from
`fil_type`, after dropping the sealed thumbnail through
`FileBlob::delete_encrypted_variant()`: the generic `delete_resized()` skips a
non-image blob, so the sealed slot has to be removed by the one remover that does
not ask.

**Rotation** re-wraps keys and never touches bytes: `DriveSealed`'s `onReseal`
callback selects exactly `fil_key_generation = $old_generation` for that owner,
re-wraps each `fil_sealed_key` to the new public key, attempts every file, and
throws if any failed. Drive is a **core** consumer — it has no plugin whose
bootstrap could carry the hooks — so it declares itself in `vault_consumers.json`
and `includes/DriveSealed.php` loads alongside the plugin bootstraps. There is no wipe
callback: a Private read streams from the container and caches no plaintext.

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
- `tests/vault/sealed_file_container_test.php` — the container: round trips at
  every chunk boundary (empty, one byte under / exactly / one byte over a chunk,
  multi-chunk), derived plaintext sizes, ranges across seams, per-chunk tamper
  detection, chunk reordering and cross-file transplant defenses, truncation, and
  a **format cross-check against a container the browser actually produced** —
  the fixture is generated by running `assets/js/drive-crypto.js` itself under
  Node (`tests/tools/make_drive_container_fixture.mjs`), so a drift in either
  implementation fails here rather than in a member's Drive.
- `tests/functional/drive/private_tier_test.php` — the server-custody level:
  sealing with the vault locked, ciphertext on disk with the type sniffed from
  the plaintext, in-window reads and ranges, a locked signed-URL fetch answering
  423, the raise and lower conversions and their window rules, copy-on-write on a
  deduped blob, the rotation sweep touching exactly one generation, the
  public-link and member-grant refusals, the folder lattice, and the
  move-boundary rules.
