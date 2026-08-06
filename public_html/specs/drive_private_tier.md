# Drive Private Tier — server-custody encrypted files, opened in-window

**Status: DRAFT 2026-08-02 — build spec for the Private rung on Drive, per
`specs/protection_levels_platform.md` (doctrine, resolved decisions R1–R3).
Unbuilt. Written for maximum reuse of the machinery that already exists;
each section names the code it rides on and the refactors it needs.**

**Defects found in review of the build are tracked in
`specs/implemented/drive_private_tier_defects.md` (D1–D7). D7 carries the answer to this
spec's build-time verification flag: the unlock window is bound to the browser
session, so a cookie-less signed-URL fetch can never open a Private file.**

## Intent

A Drive folder can be **Private**: its files are ciphertext at rest, sealed to
the owner's vault, and the server decrypts them only while the owner's unlock
window is open. AI, previews, thumbnails, and (when the office suite lands)
editing all work in-window — this is the tier that ends the choice between
"encrypted" and "my AI can help me with it." A stolen database or backup
yields ciphertext; a live server during the owner's window does not, and the
card says so honestly.

The existing encrypted folders (client custody) are untouched: they become the
**Fortress** card. Private is a new, third mode between plaintext and Fortress.

## What already exists, and what this spec reuses

| Machinery | Where | Reused for |
|---|---|---|
| Layer 0 sealed-columns contract (mint/wrap DEK, generation, save() rails) | `includes/SystemBase.php:659` (sealColumns), `:602` (read) | the file's key wrapping columns on `fil_files` |
| Sealing-needs-only-the-public-key rule | `docs/sealed_vault.md` §write path | uploads into a Private folder work with the window **closed** |
| Chunked AEAD container format (4 MiB chunks, content-id + chunk-index AAD) | browser `assets/js/drive-crypto.js`; documented `docs/drive_encryption.md:55-60` | the on-disk format, now written/read by PHP too |
| Per-file decrypt hook + 423 mapping | `data/files_class.php:522`, `:598-614` | the serve path (extended to stream — refactor R2) |
| Encrypted thumbnail variant slot | `FileBlob::store_encrypted_variant()` `data/file_blobs_class.php:884`, `fbb_encrypted_variant_key:55` | sealed server-generated thumbnails |
| Ciphertext quota + size ceiling | `DriveUsage::recompute()`, `DriveHelper::encrypted_size_ceiling():179` | unchanged; new container overhead constant |
| Contiguous-subtree refusal triad | `drive_folder_create_logic.php:49`, `drive_move_logic.php:52`, `drive_link_create_logic.php:50` | the same triad, now level-aware |
| Raise/lower ceremony batching + receipt UI | `plugins/mailbox/includes/protection_ceremony.php:420,:495`, `admin_mailbox_domains.php:264` | folder level transitions (byte-budgeted — refactor R5) |
| Rotation/wipe consumer contract | `VaultUnlock::onReseal():393`, exemplar `plugins/mailbox/includes/bootstrap.php:76-186` | Drive's rotation callback (core consumer — refactor R4) |
| Locked-state UX stack (chip, heartbeat, events, 423 handling) | `assets/js/vault-lock.js`, `vault-presence.js`, `PublicPageBase::render_vault_lock_slot()` | Drive page locked/unlock behavior, zero new ceremony code |
| Upload pipeline (init → chunk PUT → complete) | `drive_upload_init_logic.php`, `DriveUploadTransport.php`, `drive_upload_complete_logic.php` | unchanged protocol; encryption happens at the existing finalize step |

What is deliberately **not** reused: `VaultCrypto::sealField()`/`openField()`
for file bytes. They are whole-string, base64url (~1.34× size blowup), and
in-RAM (~3.4× plaintext peak per download). Mail survives that under its 25 MB
cap; Drive cannot. File bytes get a streaming sibling (refactor R1) — the
string APIs remain the right tool for every DB column.

## Data model

**One mode column replaces two booleans (refactor R3).** `fol_folders` and
`fil_files` gain `_protection_level` (`varchar(16)`, NOT NULL, default
`'standard'`, values `standard|private|fortress`). Pre-launch data update maps
`fol_encrypted/fil_encrypted = true → 'fortress'`; the boolean columns are
dropped. `File::is_encrypted()` is redefined as `level === 'fortress'` — every
existing gate (thumbnail skip, metadata-blob handling, move refusals, key-grant
checks) keeps its exact current meaning with no per-site audit. New
`File::is_sealed()` = `level === 'private'`.

**The file row carries the Layer 0 wrapping.** `fil_files` adds the four
standard columns (`fil_content_sealed`, `fil_sealed_key`,
`fil_sealed_owner_user_id`, `fil_key_generation`) with
`$sealed_fields = []` — no DB column ciphertext, the DEK exists to seal the
**blob** (and its thumbnail variant), exactly the pattern mail attachments use
where the message DEK seals related Files. Refactor R6 lets `sealColumns()`
run with an empty value set for precisely this blob-only consumer shape.

**What stays plaintext at Private (resolved below, decision P1):** `fil_title`,
size, type, folder structure, timestamps. Names are the calendar's
"times stay plaintext" analogue — they describe the tree, not the content.
Consequences bought by this: listings render while locked, rename works
unchanged, **Drive's existing name search works on Private files with zero
build** (`_drive_list_search()` is title-only), sort/sync metadata unchanged,
and no `encrypted_metadata` machinery is needed at Private. The card's fine
print states it: *"File names and sizes stay visible; contents are sealed."*
A member whose file names are themselves sensitive uses Fortress.

## The container (refactor R1 — the one new crypto surface)

New core helper `includes/SealedFileContainer.php`:

- **Format:** binary framing — magic + version + content-id + chunk-size
  header, then per-chunk AES-256-GCM: random 12-byte nonce, AAD =
  `content_id || chunk_index`, 16-byte tag. **This is the browser's Fortress
  chunk scheme** (`docs/drive_encryption.md:55-60`) implemented in PHP
  (`openssl_encrypt` AES-256-GCM), so overhead accounting
  (`encrypted_size_ceiling()`, ~32 B per 4 MiB chunk), the chunk-index range
  math, and developer intuition are shared across both custody modes. No
  base64 anywhere — flat binary on disk, ~1.000008× plaintext, not 1.34×.
- **API:** `sealStream($src, $dest, $fk)` / `openRange($path, $fk, $offset,
  $length)` / `openStream($path, $fk)` — constant memory (one chunk at a
  time), never a whole file in RAM.
- **Key:** 32-byte per-file FK from `VaultCrypto::newItemDek()`, wrapped with
  `VaultCrypto::sealItemDek($fk, $owner_public_key)` into `fil_sealed_key`.
  Unwrap via `VaultUnlock::secretKey()` + `openItemDek()`, and the unwrap path
  **must call `SealedEgressGuard::markHot()`** with the file's AD — opening a
  Private file arms the hot-turn rule exactly as opening a sealed mail column
  does. The AD is `fil:{file_id}:content`.

## Write path — encrypt at finalize, no window needed

The upload protocol is untouched (init → chunk PUT → complete; the client
uploads **plaintext** over TLS, same as Standard). In
`drive_upload_complete_logic.php`, where the Drive branch calls
`FileBlob::createFromPath()` (`:187` versions, `:229` new files): when the
destination folder is Private, the finalize step instead streams the `.part`
file through `SealedFileContainer::sealStream()` into the blob location, then
seals the row (`sealColumns` on the File, empty values, returns the FK's DEK).
Notes carried over from mail's hard-won lessons:

- Sealing uses only the owner's vault **public key** — uploads into a Private
  folder succeed with the vault locked, from any session with write access,
  same as mail ingest. The *folder owner*, not the uploader, is the sealing
  target (`fil_sealed_owner_user_id`), mirroring
  `sealTargetForAlias()`'s owner rule.
- `fil_type` / sniffing runs against the plaintext **before** sealing
  (the `createFromBytes()`-sniffs-ciphertext gotcha at
  `InboundEmailRouter.php:981-984`).
- Record plaintext size for display (new `fil_plain_size_bytes`), charge quota
  on ciphertext bytes as today — the `ima_size_bytes` precedent.
- Dedup by sha256 naturally never matches a sealed blob (ciphertext hashes are
  per-encryption); the dedup short-circuit in `drive_upload_init` must simply
  skip the lookup for Private destinations so a plaintext twin elsewhere isn't
  linked in.

**Thumbnails** are generated server-side at finalize from the still-plaintext
`.part` (reusing `ImageSizeRegistry` + `File::resize()` internals), encrypted
under the same FK, and stored via `FileBlob::store_encrypted_variant()` —
the e2ee variant slot reused verbatim. One sealed thumb variant, like Fortress.

**Versions** reuse the file's FK (the e2ee "a version must not carry a fresh
key" rule at `drive_upload_complete_logic.php:128-144`, same reason).

## Read path — streaming decrypt with honest ranges (refactor R2)

`File::serve_from_path()` gains a streaming branch. The current decrypt-hook
contract (whole bytes in, whole bytes out, no `Accept-Ranges` —
`data/files_class.php:598-631`) stays for mail/chat attachments; a second hook
shape, `registerStreamingDecryptHook($source, $opener)`, returns an object
that can `serveRange($offset, $length)`. For a Private file: Range header →
chunk indices → read only those ciphertext chunks (fseek locally;
`CloudStorageDriver::get_range()` for offloaded blobs — the range-by-bucket
path at `serve.php:340` comes back to life instead of dying) → decrypt →
emit. `Accept-Ranges: bytes` is advertised, video seek and resumable download
work. `VaultLockedException` maps to 423 exactly as today.

Locked behavior in the Drive UI: listings render (names are plaintext);
content actions on a sealed file — download, preview, thumbnail — trigger the
standard unlock ceremony via `JoineryVaultLock.unlock()` and then re-run, the
mail-reader pattern (`mailbox_reader.js:151-196`) with no new ceremony code.
Thumbnails of sealed files render a neutral tile while locked.

**Build-time verification flag:** signed-URL fetches are sessionless. Confirm
`VaultUnlock::secretKey($owner)` resolves the owner's window for a
cookie-less request (mail attachment serving suggests it does); if window
resolution turns out session-bound, native-app transport for Private files
needs the web-session bridge and this spec gains a decision.

## Level transitions (the raise/lower ceremony)

Levels attach to **top-level folder trees** with the same contiguity the
Fortress rule enforces today: children inherit; the refusal triad
(`create`/`move`/`link`) becomes level-aware. The lattice is ordered
`standard < private < fortress`, and the one rule is: **a folder's level is
the floor for everything inside it.**

- **Standard → Private (raise):** batch job seals existing files in place —
  stream-encrypt each blob (`File::replace_bytes()` is copy-on-write-aware for
  deduped blobs, `data/files_class.php:356-380`), seal the row, regenerate the
  sealed thumbnail, delete plaintext variants. Needs only the public key: runs
  from any owner session, window or not. Reuses the protection-ceremony
  receipt loop (progress card, stuck detector, `<noscript>` one-batch-per-load
  — `admin_mailbox_domains.php:264-326`), extracted per refactor R5. Batches
  are **byte-budgeted** (e.g. 64 MB per pass), not row-counted — mail's
  200-row batches are safe only under its 25 MB message cap.
- **Private → Standard (lower):** the inverse, requires the owner's open
  window (decrypt needs the secret), mirrors mail's caller-scoped unseal
  batches. Streaming per file — never accumulate a batch's plaintext in
  memory (the `$att_writes` accumulation at
  `inbound_email_message_class.php:745-765` is the anti-pattern; noted there,
  fixed here).
- **Moves across the standard↔private boundary** re-encrypt/decrypt
  server-side under the same rules (raise direction: any time; lower
  direction: in-window). Single files inline when small, else the batch job.
  The e2ee justification for refusing cross-boundary moves — "the server
  never transforms bytes" (`drive_move_logic.php:52-57`) — genuinely does not
  apply at Private; that comment gets rewritten as the fortress-only rule.
- **Anything ↔ Fortress: unchanged refusals.** Fortress transitions require
  client-side ceremonies and are out of scope (doctrine D2).

## What Private refuses (v1)

- **Public links:** `drive_link_create_logic` extends its refusal from
  fortress to `level !== 'standard'`; `share_logic`'s subtree walk gets the
  same guard. "Opened only while *you're* present" and an anonymous URL are
  contradictions.
- **Member grants / sharing:** refused at v1 (`drive_share_sync_logic` guard)
  — a grantee without a key wrapping would 423 forever, which is worse than a
  clear refusal. The door stays open by design: `fkg_file_key_grants` already
  models per-user wrapped keys, and **server custody can re-wrap in-window**
  — so shared Private folders are a v2 feature (seal the FK to each grantee's
  vault), not a redesign. This is the first crack in the platform's
  "multi-reader means Standard" wall, worth its own small spec when wanted.
- **Sync clients:** Private subtrees are excluded — `file_export()` /
  `folder_export()` emit the level, clients skip, and the folder UI says so
  ("Not synced: Private folders open only while you're signed in"). Doctrine
  D3. The sync surface needs no other change.
- **Office editing:** nothing to gate yet (no office suite in tree —
  `specs/cloud_drive_office_suite.md` is a sketch). This spec sets the policy
  it must implement: allowed in-window at Private, refused at Fortress.
- **Content search:** deferred to v2 per doctrine D1, specced separately in
  `specs/drive_content_search.md` (extract-at-upload, sealed extracts under
  the file's FK, a generalized `SealedFtsIndex` from the mail pattern). Name
  search — all Drive has ever had — already covers Private via P1.

## Rotation, wipe, egress

- **Rotation (refactor R4):** Drive is core, but consumer bootstraps load from
  plugins only (`VaultUnlock::loadConsumerBootstraps():103`, `CONSUMER_PLUGINS`
  list). Add a core-consumer registration point (`includes/DriveSealed.php`
  loaded alongside the plugin bootstraps). Its `onReseal` follows the four
  rules of the mail exemplar: select `fil_key_generation = $old_generation`,
  re-wrap `fil_sealed_key` only (content untouched), attempt-all-count-throw,
  unconditional coverage. When v2 sharing lands, grantee wrappings in
  `fkg_file_key_grants` join the sweep.
- **Wipe:** nothing to register — Private keeps no in-window plaintext working
  copy (streams only). The FTS working copy will need `onWipe` when v2 search
  arrives; noted so it isn't forgotten.
- **Egress:** unwrapping a Private file's FK marks the process hot
  (`SealedEgressGuard::markHot`), so an AI turn that reads a Private file
  falls under the hot-turn rule automatically — derived writes must land
  sealed or are refused. No Drive-specific egress code exists or is needed;
  this is the single-choke-point design doing its job.

## Refactors (summary — each is small, and each pays beyond this feature)

| # | Refactor | Why it's the right general move |
|---|---|---|
| R1 | `SealedFileContainer` — chunked binary AEAD, streaming, browser-format-compatible | Gives the platform a large-blob sealing primitive; mail's raw-message and attachment sealing can migrate to it later and drop their whole-file-in-RAM ceilings |
| R2 | Streaming decrypt-hook shape + range-aware serve branch in `File::serve_from_path()` | Fixes the documented no-`Accept-Ranges` limitation for every future sealed-file consumer, not just Drive |
| R3 | `fol/fil_encrypted` booleans → `_protection_level` enum; `is_encrypted()` ≡ fortress | The doctrine's ladder lands in the data model once; every existing e2ee gate keeps meaning without a site-by-site audit |
| R4 | Core-consumer bootstrap loading for `VaultUnlock` callbacks | Removes the "consumers must be plugins" assumption before a second core consumer appears |
| R5 | Extract the ceremony receipt loop (JS batch driver + progress card + noscript fallback) into a shared component; add byte-budgeted batching | Mail and Drive raise/lower share one tested loop; the third consumer gets it free |
| R6 | `sealColumns()` accepts an empty value set (mint + record wrapping only) | Blesses the blob-only consumer shape mail already approximates via return-DEK |

## Decisions — all owner-resolved 2026-08-02

- **P1 (owner-resolved 2026-08-02) — names, sizes, types stay plaintext at Private.** The
  content/description split (calendar's times, run purge's counts). Buys
  locked listings, working rename/sort/name-search, no metadata-blob
  machinery. Fortress remains the answer for sensitive names.
- **P2 (owner-resolved 2026-08-02) — v1 is owner-only:** no public links, no member grants, no sync for
  Private subtrees; sharing is the designed-for v2 (grantee key wrappings).
- **P3 (owner-resolved 2026-08-02) — the container is the browser's chunk format in PHP**, not libsodium
  secretstream: format-compatibility with the existing e2ee corpus and one
  set of overhead math beat secretstream's marginal API convenience.
- **P4 (owner-resolved 2026-08-02) — level transitions v1 = Standard↔Private only** (doctrine D2
  restated): raise any time, lower in-window, Fortress boundaries unchanged.

## Tests

Mirror `tests/functional/drive/encryption_test.php` with
`tests/functional/drive/private_tier_test.php` (db tier): mode lattice
(create/move/link refusals and permissions per level pair), upload-finalize
seals (blob is ciphertext on disk, row wrapping present, plaintext size
recorded, thumbnail variant sealed), locked read → 423 / in-window read
round-trips (including a Range request decrypted correctly against chunk
boundaries), raise ceremony batch (bytes re-encrypted in place, plaintext
variants gone, COW respected for a deduped blob), lower ceremony requires
window, rotation re-wrap sweep picks up exactly the old generation, dedup
short-circuit skipped for Private destinations, and the egress arm check
(open a Private file, assert the process is hot). Container unit test in
`tests/vault/`: seal/open round-trip, per-chunk tamper detection, range reads
across chunk seams, and a cross-check that PHP opens a browser-produced
container fixture (format compatibility pinned, not assumed).

## Docs (written at build time, current-state only)

`docs/drive.md` gains the three-level model; `docs/drive_encryption.md` is
retitled around Fortress folders and a new sibling section (or file) covers
Private; `docs/sealed_vault.md`'s consumer list adds Drive with the core-
consumer registration note; `docs/file_signed_urls.md` updates the ranges
paragraph (streaming hooks answer ranges). Card copy lives with the shared
picker component from `specs/protection_levels_platform.md`.

## Build order

1. R3 (mode column + data update) with the existing gates green.
2. R1 + container unit tests (pure, no Drive wiring).
3. Write path (finalize encrypt + thumbnails + versions) behind the level.
4. R2 read path (streaming hook, ranges, 423, locked UI reuse).
5. Refusal triad + P2 guards (links/grants/sync exclusion).
6. R5 + transitions (raise, then lower).
7. R4 rotation callback + tests.
8. Docs + doctrine spec cross-references; move both specs to implemented
   together when the picker component lands.
