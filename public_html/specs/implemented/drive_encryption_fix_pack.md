# Drive Encryption — Review Fix Pack

## Status: implemented (2026-07-12)

Implementation note: live verification surfaced an 11th defect at the
FormWriter seam — every JoineryValidator-managed form re-submits natively via
`form.submit()` when valid, bypassing (and navigating over) page-JS submit
listeners, which broke all Drive dialog flows. Fixed at the cause:
`FormWriterV2Base` exposes the validator instance (`form.joineryValidator`) and
`drive.js` installs its dialog handlers as the validator's `submitHandler`
(`interceptSubmit()`), the validator's own documented takeover point.

Fixes the 10 confirmed findings from the post-implementation review of
`specs/implemented/drive_encryption.md` (workflow-backed review, 2026-07-12; every finding
adversarially verified against the working tree). All work is on the uncommitted
`security-levels` branch state. Platform is pre-launch: no data migrations are needed for
existing content, but dev test data may violate the new topology rule (harmless; recreate it).

The findings, ranked by severity:

| # | Defect | Class |
|---|--------|-------|
| 1 | Collaborator upload into a shared vault stores a key grant the owner cannot open — file permanently undecryptable | Data loss |
| 2 | Encrypted new-version upload discards the fresh wrapped key while grants still wrap the old one | Data loss |
| 3 | `loadShares()` drops its promise — key-grant sync runs against the stale grantee list; revocation silently fails | Access control |
| 4 | Public link on a plaintext folder exposes encrypted subfolders nested inside it | Exposure |
| 5 | Rename on an encrypted file writes the sensitive name into plaintext `fil_title` | Secrecy leak |
| 6 | `loadEncryptedThumb` passes bytes where `decryptThumbnail` expects base64 — encrypted thumbnails never render | Broken feature |
| 7 | Key-grant sync ignores `drive_list`'s `truncated` flag — files past 2000/folder silently get no keys | Broken feature |
| 8 | Encrypted thumb variants are invisible to every blob lifecycle path gated on `is_image()` (offload, delete, splitCopy, move, pull-back, localize) | Broken feature |
| 9 | Tier max-file-size is checked against ciphertext (+32 bytes/chunk), so files near the cap fail only in vault folders | Consistency |
| 10 | Create allows an encrypted folder under a plaintext parent; Move refuses that exact topology | Consistency |

## Decisions

**D1 — Vault topology: a vault is a top-level tree.** An encrypted folder may exist only at
the Drive root or inside another encrypted folder. Create adopts the invariant Move already
enforces (the stricter of the two contradictory rules). Consequence: a plaintext folder can
never contain an encrypted descendant, so the existing public-link guard on the linked folder
itself is *sufficient* — finding 4's exposure is eliminated at the topology layer, with no
subtree scan and no listing filter (no band-aid at the share layer). This also gives users a
simpler mental model: the lock icon at the top of a tree tells the truth about everything
under it.

**D2 — The uploader seals the file key to every reader, at upload time.** A new encrypted
file must arrive readable by everyone who can already reach it: the folder owner plus all
access grantees of the destination. The server knows that set; the client does the sealing.
`drive_upload_complete` replaces the single `wrapped_file_key` with a `wrapped_file_keys`
map `{ user_id: sealed_blob }` (uncommitted feature — no compatibility shim). No
after-the-fact reconcile pass is needed for the common path.

**D3 — File key and content-id are stable across versions, and the server enforces the
precondition.** The implemented spec already decided FK stability (prior versions must stay
decryptable); the client just had no way to comply. `DriveCrypto` gains a reuse path, and the
server gates encrypted version uploads on the uploader actually holding a `FileKeyGrant` for
the file — the only proof available that the client *can* reuse the FK. A
`wrapped_file_key`/`wrapped_file_keys` payload on the version path is rejected outright: a
client sending one has minted a fresh FK, and accepting the upload would orphan every grant.

**D4 — Renaming an encrypted file updates the encrypted metadata, not `fil_title`.** The
display name of an encrypted file lives inside `fil_encrypted_metadata`; that is what rename
must change. The client decrypts the metadata, swaps the name, re-encrypts with the same FK,
and submits the opaque blob. The server refuses a plaintext `name` for encrypted files —
`fil_title` keeps its opaque `enc-…` value forever.

**D5 — Size caps apply to plaintext; the server allows the deterministic ciphertext
overhead.** The container adds exactly 32 bytes per chunk (4-byte length prefix + 12-byte IV
+ 16-byte GCM tag) over 4 MiB chunks, so for a plaintext cap `C` the largest legal ciphertext
is `C + 32 * max(1, ceil(C / 4MiB))`. `drive_upload_init` uses that ceiling when the
destination is encrypted. Quota (total storage) continues to bill ciphertext bytes as stored.

**D6 — A blob's variant inventory becomes a single authoritative method.** Six lifecycle
paths independently assume "variants exist iff `is_image()`", and all six are wrong for
ciphertext blobs. One new method, `FileBlob::variant_size_keys()`, becomes the only source of
truth, backed by a durable record of the encrypted variant's size key (cloud-side operations
cannot scan a disk, and the image-size registry may change after write).

**D7 — `decryptThumbnail` takes raw ciphertext bytes.** The bytes come off a `fetch()` of the
thumb signed URL; base64 was never the natural interchange at that boundary. The encrypt side
(`maybeThumbnail` → transport to the server) keeps base64, which is correct for a JSON body.

**D8 — Subtree key-grant enumeration must be complete or fail loudly.** `drive_list` gains an
`offset` parameter; the share dialog's subtree walk pages through it. If enumeration still
cannot complete (guard tripped), the sync aborts with a visible error instead of granting
access without keys.

## Fixes

### 1. Collaborator uploads into a shared vault (D2) — `drive_upload_complete_logic.php`, `drive_public_keys_logic.php`, `drive.js`

The bug: the server stores the sole `FileKeyGrant` under `$owner_id`
(`drive_upload_complete_logic.php:167`) while the browser seals to the *uploader's* own key
(`drive.js:827` — `session.sealTo(packed.fkBytes)`, no target). When editor B uploads into
A's vault, the one grant row is (file, A) holding a key only B can open. Nobody can decrypt.

**`drive_public_keys` gains a folder mode.** New optional input `folder_id`: resolve the
folder, require the caller to hold write access (`DriveHelper::can_write`), and return the
public keys of the folder's full reader set — the owner plus every user holding an access
grant on the folder or any of its ancestors (the same resolution `can_read` uses). Response
rows keep the existing shape (`user_id`, `public_key`, null when no drive vault). The
`identifiers` mode is unchanged. Public keys are public; the write-access requirement only
prevents fishing for the grant list of folders the caller can't touch.

**`drive.js` `uploadOne` encrypted path:** call `drive_public_keys` with the destination
`folder_id`, seal `packed.fkBytes` to every returned key (`DC.wrapFileKeyTo`), always
including the uploader's own (the uploader is in the reader set; belt-and-braces: add self
via `session.sealTo` if absent). Send `completeExtra.wrapped_file_keys = { uid: blob, … }`.
Readers with no vault yet are skipped and surfaced with the existing "no Drive vault yet"
toast wording. The single `wrapped_file_key` field is deleted.

**`drive_upload_complete` new-encrypted-file path:** accept `wrapped_file_keys` (map).
Validate: non-empty; must contain an entry for `$owner_id`; every key must be the owner or a
user holding access to the destination folder (reject otherwise — a grant to an arbitrary
user id would be a key-exfiltration primitive). Store one `FileKeyGrant::put()` per entry.
The `file_export` wrapped-key return switches to `FileKeyGrant::wrapped_key_for($file->key,
$user_id)` so the *uploader* gets their own key back regardless of role.

If the owner has no drive vault (no public key to seal to), the upload fails with a clear
error before any bytes are admitted — an encrypted file the owner can never read must not be
creatable.

### 2. Encrypted new-version uploads (D3) — `drive-crypto.js`, `drive_upload_complete_logic.php`

**`DriveCrypto.encryptFileWith(file, fkBytes, contentId)`** — refactor `encryptFile` so the
key/content-id acquisition is a parameter: the existing `encryptFile(file)` mints fresh ones;
the new entry point imports the caller's FK (`importFileKey`) and reuses the caller's
`contentId` in every chunk AAD and the thumbnail AAD. Returned `meta.cid` is the reused id.
This is the only correct way to produce a new version of an encrypted file, and it is what
any future version-upload UI must call (today the version path is API-only).

**`drive_upload_complete` encrypted-version path (`$target_file` branch):**
- Reject the request if `wrapped_file_key` or `wrapped_file_keys` is present:
  `'A new version of an encrypted file must reuse its existing file key; do not send a new
  wrapped key.'` (A fresh FK here is silent data loss — fail the upload instead.)
- Require `FileKeyGrant::wrapped_key_for($target_file->key, $user_id)` to be non-null:
  without a key grant the uploader cannot have reused the FK, so the ciphertext is
  unreadable by every grant holder. Reject with a clear error.
- The existing behavior (metadata/thumbnail follow the new content, grants untouched, prior
  versions stay decryptable) is then actually true; keep it and fix the comment's claim to
  match the enforced contract.

The API descriptor documents the contract: encrypted version uploads must be produced with
the file's existing key and content id (`encryptFileWith`), holding a key grant is required,
and wrapped-key payloads are refused on this path.

### 3. Stale grantee list in key-grant sync — `drive.js`

`loadShares()` (`drive.js:567`) must `return api.post(…)` so the chain in `syncGrants`
(`drive.js:594`) actually waits for `shareGrants` to refresh before `syncEncryptedKeys()`
reads it. One-line fix; it makes new grantees receive keys and — the dangerous half — makes
removals *revoke* keys (`sync_for_file` reconciles to exactly the submitted set, so the
departed user's row is deleted instead of re-written from the stale list).

### 4. Encrypted folders under plaintext parents (D1) — `drive_folder_create_logic.php`, `views/drive.php`/`drive.js`

**Server:** in `drive_folder_create_logic.php`, when `$parent_id > 0` and the parent is
*not* encrypted, reject `encrypted = true`:
`'An encrypted folder can only be created at the Drive root or inside another encrypted
folder.'` (The inherit-under-vault branch at line 46 is unchanged.) With D1 in force,
`drive_link_create`'s existing self-check and `share_logic`'s subtree listing are already
correct — no changes there.

**Client:** the new-folder dialog shows the "encrypted vault" checkbox only at the root or
inside a vault (where it is implied and shown as inherited/disabled). No checkbox inside a
plaintext folder.

**Move (`drive_move_logic.php`):** already enforces this invariant — unchanged. The
create/move contradiction (finding 10) dissolves by making create match move.

### 5. Rename on encrypted files (D4) — `drive_rename_logic.php`, `drive.js`

**Server (`drive_rename_logic.php` file branch):** if `$entity->is_encrypted()`:
- Refuse a plaintext `name` for the rename (`fil_title` is never written for encrypted
  files).
- Accept a new optional opaque input `encrypted_metadata` (string, passed through the
  boundary untouched like upload_complete's): require it for encrypted files, write it to
  `fil_encrypted_metadata`, record `FileChange::KIND_RENAMED` as before.
- Folders are unaffected (`fol_name` is plaintext by design for vault folders).

Validation asymmetry is fine: plaintext files require `name`, encrypted files require
`encrypted_metadata` — enforce exactly one of the two in the logic, and say so in the
descriptor.

**Client (`drive.js`):** for an encrypted file, `openRename` prefills the *decrypted* name
(`it._name`) and requires an unlocked vault (`ensureUnlocked()`; the rename dialog is
reachable only after the listing decrypted, so this is normally a no-op). `submitRename`
takes the cached key entry from `fileKeyFor(it)`, updates `entry.meta.name`, re-encrypts via
`DC.encryptMetadata(entry.meta, entry.fkKey)`, and posts `{ entity_type, entity_id,
encrypted_metadata }`. On success, update the cached meta and the rendered row in place (the
reload's `decryptVisible` will also now show the new name, fixing the "rename appears to
silently fail" half of the finding).

### 6. Encrypted thumbnails never decrypt (D7) — `drive-crypto.js`, `drive.js`, `share-decrypt.js`

Change `decryptThumbnail(bytes, fkKey, contentId)` to accept a `Uint8Array`/`ArrayBuffer` of
ciphertext directly (drop the `b64decode`). `loadEncryptedThumb` (`drive.js:275`) already
passes bytes — now correctly. Audit `share-decrypt.js` for the same call and align it. Update
`drive_crypto_gate.sh` so the thumbnail round-trip test feeds raw bytes (the gate passed
because it fed base64 — the test must exercise the real call shape).

### 7. Truncation-blind subtree enumeration (D8) — `drive_list_logic.php`, `drive.js`

**`drive_list`** gains optional `offset` (int ≥ 0, default 0): skip that many children before
collecting up to `DRIVE_LIST_CAP`, using the listing's existing deterministic ordering.
`truncated` keeps meaning "more children exist past what was returned". Declare `offset` in
the descriptor.

**`collectEncryptedFiles` (`drive.js:649`):** for each folder, loop requesting successive
offsets while the response says `truncated`, appending items each pass. Keep a total-request
guard; if the guard trips (or any listing call fails), *throw* — `syncEncryptedKeys` must
abort with a visible toast rather than sync a partial key set. Partial silent coverage is the
bug; loud failure is acceptable, silent under-granting is not.

### 8. Encrypted variants invisible to blob lifecycle (D6) — `data/file_blobs_class.php`, `includes/cloud_storage/BlobStorageProfile.php`

**Schema:** new nullable column on `FileBlob`:

```php
'fbb_encrypted_variant_key' => array('type' => 'varchar(32)', 'is_nullable' => true),
```

`store_encrypted_variant()` records the size key it wrote (idempotent overwrite). This is the
durable inventory: remote deletion, pull-back, and localize enumerate variants *without* a
local disk to scan, and the value survives later image-size-registry changes (the remote key
must match what was used at write time).

**New method — the single source of truth:**

```php
/** Size keys that may hold a variant for this blob (original excluded). */
public function variant_size_keys() {
    $keys = array();
    if ($this->is_image()) {
        require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
        foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) { $keys[] = $size_key; }
    }
    $enc = (string)$this->get('fbb_encrypted_variant_key');
    if ($enc !== '' && !in_array($enc, $keys, true)) { $keys[] = $enc; }
    return $keys;
}
```

**Call-site inventory — all variant enumerations switch to `variant_size_keys()`** (complete
list; each currently gates on `is_image()`):

1. `BlobStorageProfile::itemsForRow()` (`includes/cloud_storage/BlobStorageProfile.php:78`) — offload to cloud (existence-checked per file, as now)
2. `BlobStorageProfile::reverseItemsForRow()` (`:107`) — reverse/localize enumeration
3. `FileBlob::_delete_cloud_bytes()` (`data/file_blobs_class.php:492`) — bucket deletion
4. `FileBlob::splitCopy()` (`:556`) — private/public split copy
5. `FileBlob::_move_single` caller in the visibility move (`:690`)
6. `FileBlob::_pull_back_from_cloud()` (`:744`) — cloud → local

`resize()` (`:888`) and the resize-cloud path (`:973`) keep their `is_image()` gates — resizing
ciphertext is meaningless and the skip is deliberate (documented skip-list behavior).

Fix the `store_encrypted_variant` docstring's offload claim to describe the now-true
behavior.

### 9. Ciphertext vs. plaintext size cap (D5) — `drive_upload_init_logic.php`, `DriveHelper.php`, `drive.js`

**`DriveHelper::encrypted_size_ceiling(int $plain_cap): int`** — `return $plain_cap + 32 *
max(1, (int)ceil($plain_cap / (4 * 1024 * 1024)));`. The constants mirror
`drive-crypto.js` (`CHUNK_BYTES` = 4 MiB; 4-byte length + 12-byte IV + 16-byte tag per
chunk); note the pairing in a comment on both sides so a chunk-size change updates both.

**`drive_upload_init`:** the destination folder is already resolved; when it is encrypted,
compare `size_bytes` against `DriveHelper::encrypted_size_ceiling($max_file)` instead of
`$max_file`. The quota check is unchanged (ciphertext is what is stored and billed).

**`drive.js`:** the early `file.size > CFG.maxFileBytes` check stays — it is the plaintext
check, now consistent with what the server effectively enforces.

### 10. Create/move invariant contradiction — resolved by fix 4 (D1)

No separate change; verify with the topology tests below.

## Tests

Extend the existing suites (harness + `@joinery-test` headers as per docs/testing.md):

**`tests/functional/drive/encryption_test.php` (db tier):**
- Grantee-upload: user B (editor grant on A's vault folder) completes an encrypted upload
  with `wrapped_file_keys` for both A and B → two `FileKeyGrant` rows; missing-owner map
  rejected; map entry for a user with no access rejected.
- Version upload: encrypted version with `wrapped_file_key(s)` present → rejected; version
  by a user with no `FileKeyGrant` → rejected; with a grant and no key payload → accepted,
  grants untouched.
- Topology: `drive_folder_create` with `encrypted` under a plaintext parent → rejected; at
  root and under a vault → accepted; move suite still green.
- Rename: plaintext `name` on an encrypted file → rejected; `encrypted_metadata` accepted
  and stored; `fil_title` unchanged.
- Size ceiling: init of `ceiling(cap)` bytes into a vault folder → accepted; `ceiling(cap)+1`
  → rejected; plaintext folder still enforces the raw cap.
- `drive_list` `offset`: create > cap children is impractical — instead temporarily define a
  low `DRIVE_LIST_CAP` via the test (or add an internal test-only cap override) and verify
  offset paging returns the full set exactly once with correct `truncated` flags.
- Blob variants: after an encrypted upload with a thumbnail, `variant_size_keys()` contains
  the thumb key and `itemsForRow()` lists the variant file.

**`tests/functional/drive/drive_crypto_gate.sh` (Node gate):**
- `encryptFileWith` reuses FK + contentId: encrypt v1 with `encryptFile`, v2 with
  `encryptFileWith(v1.fkBytes, v1.contentId)`, both decrypt with the same key/cid.
- `decryptThumbnail` round-trips from **raw ciphertext bytes** (the fetch shape), not base64.

**Live browser E2E (manual/passphrase, as before):** collaborator-upload round-trip — B
uploads into A's shared vault, A decrypts it; rename an encrypted file and confirm the new
name after a hard reload; share-with-member then remove them and confirm the key grant row is
gone.

## Documentation

Same-change updates, current-state voice only (no migration narration):

- **`docs/drive_encryption.md`** — topology rule (vaults at root or nested in vaults, and
  why the link guard is thereby complete); `wrapped_file_keys` upload contract (reader-set
  sealing, owner entry required); encrypted-version contract (FK/content-id reuse,
  key-grant precondition, wrapped-key payloads refused); rename-via-metadata; the size
  ceiling formula; `fbb_encrypted_variant_key` and `variant_size_keys()`.
- **`docs/drive.md`** — rename behavior for encrypted files; `drive_list` `offset`;
  folder-create `encrypted` placement rule.
- **Descriptors** — `drive_upload_complete`, `drive_rename`, `drive_list`,
  `drive_public_keys`, `drive_folder_create` descriptions updated to the new contracts (the
  descriptor text is the API doc surface).

## Out of scope

- The 12 cleanup-tier review findings below the report cap (style/altitude, none load-bearing).
- MSE streaming playback, phase 5 hardening items (native/extension clients) — deferred in
  the implemented spec, unchanged here.
- Any migration of existing nested-vault dev data (pre-launch; recreate by hand if wanted).

## Completion checklist

- [x] All 10 fixes implemented as specified (plus the FormWriter submitHandler fix above)
- [x] `php -l` + `validate_php_file.php` clean on every touched PHP file (validator *executes*
      the target — safe for these logic/data files, none run-on-include)
- [x] `drive_crypto_gate.sh` green including the new cases (14/14)
- [x] `encryption_test.php` green including the new sections (79/79)
- [x] `php tests/run.php safe` (35/35) and `db` (84/84) green
- [x] Live browser E2E on dev: vault unlock → encrypted upload via reader-set sealing →
      thumbnail decrypts and renders → rename via re-encrypted metadata (fil_title stays
      opaque, no plaintext in the DB) → hard reload → decrypt-from-scratch shows the new
      name; collaborator-upload and revocation flows covered by the db test suite
- [x] Docs updated; spec moved to `specs/implemented/`
