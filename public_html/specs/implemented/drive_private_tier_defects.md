# Drive Private tier — defect fix pack

**Status: IMPLEMENTED 2026-08-06. Findings from the code review of the Private
tier build (`specs/drive_private_tier.md`). Seven defects, most severe first. D1
and D2 were data-loss and data-corruption bugs; D3 and D4 user-visible dead ends;
D5 a hole in the v1 refusals; D6 an integrity gap; D7 an unresolved question from
the parent spec's own build-time verification flag.**

**D1–D6 and D7-b are built and covered by tests** in
`tests/functional/drive/private_tier_test.php` — each reproduction was confirmed
to fail against the unfixed code before the fix landed. **D7-a** (native clients
reaching Private content) was answered by the owner, investigated, and deferred:
platform passkeys turn out to be structurally unavailable for it, and the full
analysis lives in `specs/native_vault_unlock.md`.

The parent `specs/drive_private_tier.md` stays active — it files with
`specs/protection_levels_platform.md` when the shared level-picker component
lands.

Parent spec: `specs/drive_private_tier.md` (stays in `specs/` until the shared
level-picker component lands). Doctrine: `specs/protection_levels_platform.md`.

---

## D1 — An interrupted raise destroys the file, unrecoverably

`DriveSealed::sealExistingFile()` swaps the blob's bytes **before** the key
wrapping is persisted:

```php
if (!$file->replace_bytes_from_path($tmp)) { ... }   // blob is now ciphertext
$file->set('fil_protection_level', PRIVATE_); $file->save();
File::recordSealedKey($file->key, $vault, $fk);      // key finally stored
```

**Failure scenario.** An owner raises a folder holding a 400 MB video.
`runTransitionBatch()` bounds a pass at 64 MB of *bytes* but has no time budget,
and `sealStream()` plus `renderThumbnail()` on that budget can exceed
`max_execution_time`. php-fpm kills the worker immediately after the `rename()`
inside `overwriteBytesFromPath()`. The row still reads
`fil_protection_level = 'standard'` with `fil_sealed_key = NULL`, and the bytes
on disk are AES-256-GCM ciphertext under a key that existed only in the `$fk`
local of a process that no longer exists. **The file is permanently unreadable.**

The retry is worse than the crash. The next batch pass loads the row, sees
`is_sealed()` false, and seals *the container* under a fresh key — burying the
first layer under a second whose inner key is already gone — and records
`filesize($src)` (the container's length) as `fil_plain_size_bytes`.

`SealedFileContainer::looksSealed()` exists and is called by nothing outside the
tests. It is exactly the missing guard.

**The fix.** Two changes, both in `DriveSealed::sealExistingFile()`.

1. **Record the wrapping before swapping the bytes.** The orders are not
   symmetric. Key-then-bytes leaves, at worst, a plaintext file carrying a spare
   wrapping — harmless, and the next pass re-mints over it. Bytes-then-key leaves
   ciphertext whose key never reached durable storage. Only one of those is
   recoverable, so only one is allowed.

2. **Resume from what is on disk, never from the row alone.** On entry, if the
   stored bytes already `looksSealed()`:
   - with a wrapping on the row → an interrupted pass got as far as the swap.
     Finish it: derive the plaintext size from the container's length (which
     needs no key), set the level, done.
   - with no wrapping → the key is gone. **Throw**, loudly and specifically.
     Re-sealing would bury the bytes under a second key, and the batch's
     per-file `catch` already leaves a thrower in the backlog with a log line.
     A file that needs a human is worth saying so about.

A resumed raise cannot render a thumbnail — the plaintext is gone by then. The
file keeps its type icon. Noted rather than worked around: inventing a thumbnail
is not worth reading the content back in-window during a ceremony that is
supposed to run locked.

The same window exists in `unsealExistingFile()` (bytes swapped at `:265`, row
cleared at `:280`) but is recoverable — the key survives, so the row can be
repaired — and the resume guard covers it: `is_sealed()` with plaintext bytes on
disk means the swap landed and the row update did not.

## D2 — Lowering a Private image serves the encrypted thumbnail as an image

`FileBlob::is_image()` reads `fbb_mime_type`. Every blob `createSealedFile()`
produces is sniffed from the **container**, so that column reads
`application/octet-stream`. Both `FileBlob::delete_resized()` and
`FileBlob::resize()` return `false` immediately on a non-image blob, and
`unsealExistingFile()` never restores the real type.

**Failure scenario.** Upload `holiday.jpg` into a Private folder: the blob is
octet-stream and the sealed thumbnail lands in `thumb/<stored_name>`. Later make
the folder Standard.

1. `replace_bytes_from_path()` → `delete_resized('all')` → no-op. **The sealed
   container in the thumbnail slot survives.**
2. `fbb_encrypted_variant_key` is cleared, so no lifecycle path — offload, cloud
   delete, `splitCopy`, visibility move, pull-back — knows the slot exists
   either. It is now an orphan.
3. `$file->resize('all')` → `$blob->resize()` → not an image → `false`. **No
   plaintext variants are ever generated.**
4. `file_export()` still emits `thumb_url` (it keys off `fil_type`, which is
   correct). Fetching it finds the leftover file and, because `is_sealed()` is
   now false, no decryptor is resolved: the raw container bytes are served with
   `Content-Type: image/jpeg`.

The raise and lower tests use `text/plain` throughout, so nothing covers it.

**The fix.** A blob's stored type must describe the bytes actually in it, at
both ends of the transition.

- **Sealing:** after the rewrite (so `delete_resized()` still runs against the
  real image type and drops the plaintext variants), set `fbb_mime_type` to
  `application/octet-stream`. A container blob then looks non-resizable to every
  generic pipeline, which is what it is. The file's own `fil_type` keeps the real
  type — that is what the member and the UI read.
- **Unsealing:** drop the sealed variant slot **explicitly**, then restore
  `fbb_mime_type` from `fil_type`, then regenerate. New
  `FileBlob::delete_encrypted_variant()` is the exact inverse of the existing
  `store_encrypted_variant()`: it removes the slot named by
  `fbb_encrypted_variant_key` — local or cloud — and clears the column, without
  consulting `is_image()`. Fortress deletion paths want the same method.

Both mutations happen after `replace_bytes_from_path()` has done its
copy-on-write split, so a shared blob's siblings are never re-typed. This
matters: a Private file with saved versions **does** have a reference count
above one.

## D3 — A folder with any link or grant can never be made Private

`assets/js/drive.js` `submitProtection()` rebuilds `body` on every submit, but
the confirmation flag is only ever written onto the *previous* invocation's
closure:

```js
var body = { folder_id: ..., protection_level: target };
var apply = function (confirmed) {
    if (confirmed) body.confirm_revoke_sharing = true;
    api.post('drive_level_change', body).then(function (d) {
        if (d && d.needs_confirmation) { ...; body.confirm_revoke_sharing = true; return; }
```

**Failure scenario.** A folder carries one public link. Apply → the server
answers `needs_confirmation` and the card says "apply again to continue". The
owner clicks Apply → `submitProtection` runs fresh → `apply(false)` on a brand
new `body` → the same `needs_confirmation`. Forever. The server side is correct;
the client can never send the flag.

**The fix.** Hold the confirmation on the dialog's state, not on a per-submit
object, and clear it in `openProtection()`. The `if (…) { apply(x) } else
{ apply(x) }` below it — identical branches — is where the state was meant to
live and goes with it.

## D4 — The mail raise receipt's action button never reappears

`protection_ceremony.php` gives the button both markers while a backlog exists:

```php
'<a id="receipt-action" class="btn btn-primary' . ($backlog > 0 ? ' d-none' : '') . '"'
  . ($backlog > 0 ? ' data-ceremony-when-done hidden' : '')
```

`ceremony-batch.js` `finish()` only clears `hidden`.
`theme/joinery-system/assets/css/style.css` defines
`.d-none { display: none !important }`, so the button stays invisible. The inline
loop this replaced did `btn.classList.remove('d-none')`; the behavior was lost in
the extraction.

**Failure scenario.** Raise a domain with a backlog and wait for "340 earlier
messages sealed". "Open the reader" never appears — the admin has to reload.

**The fix.** In the shared driver, reveal a `data-ceremony-when-done` element by
clearing `hidden` **and** removing `d-none`. Fixing it in the driver rather than
in mail's markup is what makes the third consumer free, which is the whole point
of extracting it.

## D5 — The Private refusals read the file's level, not the folder's promise

`drive_link_create_logic` and `drive_share_sync_logic` both test
`$entity->protection_level()` for a file. But `drive_level_change` flips the
**folder** immediately and the files converge over many batches — that gap is the
entire point of the design, and it can last a long time on a large tree.

**Failure scenario.** An owner makes a 5,000-file folder Private.
`_drive_level_revoke_sharing()` runs once, at change time. While the batches run,
the owner creates a public link on a file inside that folder: the file's own
level is still `standard`, so the guard passes and the link is minted. The next
batch seals the file and `share_logic` starts answering "File unavailable". The
same path mints a member grant that becomes a permanent 423.

Related and independent: `drive_move_logic` seals a file *into* a Private folder
but never touches that file's existing `fga_file_access_grants` or
`fsl_file_share_links`. Moving a shared file into a Private folder produces
exactly the "share that looks granted and never opens" outcome
`drive_share_sync_logic`'s own comment refuses to create.

**The fix.**

- A file's **effective** level is the stronger of its own and its folder's — new
  `DriveHelper::effective_file_level()`, built on `ProtectionLevel::max()`. The
  link and grant guards read that. A file inside a Private folder is refused from
  the moment the folder promises it, not from the moment the bytes catch up.
- A move that would convert a file to Private **refuses** when that file carries
  a live link or a member grant, naming what is in the way. Refusing is the
  non-destructive answer and needs no confirmation flow bolted onto a
  drag-and-drop. The level change keeps its report-then-confirm shape, because
  there the owner is already in a dialog making a deliberate promise about a
  whole tree.

## D6 — Container truncation is silent

Measured against the built container:

```
header-only container:   plainSize=0,       openString len=0        (no exception)
dropped last block:      plainSize=4194304, opened=4194304          (no exception)
```

Truncation on a block boundary leaves every surviving chunk authenticating
cleanly, and `plainSizeFrom()` derives a smaller but entirely plausible size from
`filesize()`. The per-chunk AEAD binds a chunk to its file and its index, which
stops reordering and transplanting; it says nothing about how many chunks there
should be.

**Failure scenario.** A cloud fetch in `serve.php` short-reads, or a write is
torn. The member gets `HTTP 200`, a `Content-Length` that matches what was sent,
and a silently truncated file. Nothing anywhere reports a problem.

**The fix.** The independently recorded truth is already on the row.
`fil_plain_size_bytes` is written at seal time and
`DriveSealedStream::plainSize()` deliberately ignores it ("the plaintext size is
read from the CONTAINER, not from the row"). That reasoning is right for the
thumbnail variant, whose size only its own container knows, and wrong for the
original.

So the decryptor has to know which one it is holding. `serve_from_path()` gains
the `$size_key` it is already serving, and passes it to the streaming hook's
opener; the opener hands `DriveSealedStream` the expected plaintext size for the
original and nothing for a variant. A mismatch is a 404, not a short 200.
`DriveSealed::openTo()` does the same check on its own path.

The class docblock's claim that "a truncated or corrupt container raises instead
of decrypting garbage" becomes true for the original and stays honest about the
variant.

## D7 — Signed URLs cannot open a Private file without a session cookie

The parent spec's build-time verification flag — *"Confirm
`VaultUnlock::secretKey($owner)` resolves the owner's window for a cookie-less
request"* — resolves **negative**:

```php
$sid = self::currentSessionIdOrNull();
if ($sid === null) return null;      // and the APCu key is vault:{session_id}:{user}:{scope}
```

The window is keyed to the browser session, so a signed URL fetched without the
cookie is always 423 no matter what window the owner holds elsewhere. In a
browser this is invisible — same-origin fetches carry the cookie — so neither the
tests nor the UI can catch it. Signed URLs exist for the native-app transport,
and `docs/file_signed_urls.md` describes streaming ranges and 423 without saying
that a sessionless client can never open a Private file at all.

**This build:** say so, in `docs/file_signed_urls.md` and
`docs/drive_encryption.md`. The behavior is correct and fail-closed; what is
missing is the statement.

**D7-a resolved (owner, 2026-08-06): native clients get first-class Private
access, secured by an on-device passkey.** Neither shape proposed here was
taken — not the cookie-sharing bridge, not window-bound signed URLs. The window
is generalized from a browser session to a *credential*, and a device opens its
own window with a native WebAuthn PRF ceremony. Specced separately in
`specs/native_vault_unlock.md`, which also absorbs D7-b (the `requires_window`
export flag).

Until that lands, the refusal built here is the correct behavior and the docs
state it.

---

## Build order

1. D1 + D2 together — they touch the same two functions and the same ordering
   argument, and D2's blob-type rule is what makes D1's resume test meaningful.
2. D6 — the plumbing (`$size_key` down to the opener) is small and the check
   lands in the same file.
3. D5 — guards and the move refusal.
4. D3, D4 — client-side, independent of everything above.
5. D7 — docs only.

## Tests

Extending `tests/functional/drive/private_tier_test.php` and
`tests/vault/sealed_file_container_test.php`:

- **D1** — simulate the crash: seal a file's bytes on disk and clear the level,
  with and without the wrapping present. With a wrapping, a re-run converges the
  row and the bytes still open. Without one, the re-run **throws** and the bytes
  are left exactly as they were (no second layer, `plainSize` unchanged).
- **D2** — the raise/lower round trip on a real **image**: after the raise the
  blob's stored type is `application/octet-stream` and the sealed thumb opens in
  window; after the lower the thumb slot holds a decodable image, not a
  container, and the plaintext variants are back.
- **D5** — a file whose own level lags its folder's is refused a link and a
  grant; a move that would seal a linked file is refused and the file does not
  move.
- **D6** — truncate a sealed original on a block boundary and assert the serve
  path 404s instead of returning a short 200; a sealed thumbnail still serves.
- **D3/D4** are DOM behavior; the receipt markup assertion in
  `plugins/mailbox/tests/raise_receipt_test.php` keeps `d-none` present and the
  driver change is what reveals it.

## Docs

`docs/drive_encryption.md` (the resume rule, the blob-type rule, the size
cross-check, the sessionless statement), `docs/file_signed_urls.md` (D7),
`docs/drive.md` (the effective-level rule for the refusals). Current-state only.
