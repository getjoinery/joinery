# Drive Content Search — inside files, at every protection level that allows it

**Status: DRAFT 2026-08-02 — the v2 companion to
`specs/implemented/drive_private_tier.md` (split out per doctrine decision D1).
Unbuilt. Its dependency is met: the Private tier is built and filed. Drive search today is filename-only (`_drive_list_search()`,
`logic/drive_list_logic.php:228`), and that stays working at every level —
this spec adds matches on what's *inside* the files.**

## Intent

Searching Drive finds files by their contents: the PDF that mentions a
contract number, the note that names a person. Availability follows the
protection ladder honestly:

- **Standard** files: content search always works, no window needed.
- **Private** files: content search works **while your unlock window is open**
  — the index over sealed content is itself sealed.
- **Fortress** files: no server-side content search, ever (the server has no
  plaintext to index). Name search doesn't apply either (Fortress names are
  encrypted). Unchanged from today.

## Design — extract once at upload, index cheaply later

The expensive step (pulling text out of a PDF) and the window-bound step
(updating a sealed index) are different steps, and the design keeps them
apart:

**1. Extraction happens at upload-finalize, while the plaintext is in hand.**
A new `DriveTextExtractor` registry maps types to extractors; v1 formats:
plain text / markdown / code (as-is), HTML (tags stripped), PDF
(`pdftotext`). Everything else indexes name-only. The extract is capped
(decision S2) and stored per file:

- Standard file → extract goes into a `tsvector` column, searched with
  PostgreSQL FTS directly. No SQLite, no window, no working copy — Postgres
  already does this job for plaintext content.
- Private file → extract is sealed as a small blob **under the file's own
  FK** (the `SealedFileContainer` from the Private tier spec) and stored as a
  blob variant, the `fbb_encrypted_variant_key` pattern. Sealing needs no
  window (public-key only), so uploads stay window-independent. Because the
  extract rides the file's existing key wrapping, **rotation costs nothing
  new** — re-wrapping `fil_sealed_key` covers it.

**2. The Private index is the mail pattern, fed by pre-made extracts.**
`MailboxIndex` (`plugins/mailbox/includes/MailboxIndex.php`) generalizes into
a core `SealedFtsIndex` used by both consumers: SQLite FTS5 working copy in
`/dev/shm` (plaintext never on disk), incremental fold from a high-water
mark, persisted between windows as a single sealed blob, `wipe()` on window
close, `purgePersisted()` on key rotation. The Drive fold reads sealed
*extracts* (small, pre-chewed text), not original files — so folding a
thousand-file backlog is decrypt-and-insert, never PDF parsing in-window.

**3. One search box, merged results.** `_drive_list_search()` grows two
content branches alongside its title queries: the Postgres FTS match
(always), and the sealed-index match (when the window is open). While locked,
the UI adds one quiet line to results: "Private file contents not searched —
unlock to include them." Fold-on-unlock reuses the reader-driven quiet
convergence pattern (`mailbox_reader_mount.php:262-303`).

## What the mail pattern must fix before reuse (the scaling honesty)

`MailboxIndex::persistOrThrow()` reads the whole SQLite file into memory and
seals it as one string (`MailboxIndex.php:289,:300`) — acceptable for a mail
corpus, not for a Drive one. The generalized `SealedFtsIndex` persists
through `SealedFileContainer::sealStream()` instead (streaming, binary, no
base64), which the Private tier build provides anyway. This also becomes an
available (not required) improvement for the mail consumer when it migrates
to the shared class. Extract caps (S2) bound the index size; the persisted
blob is a rebuildable cache, so a corrupt or oversized one is deleted and
refolded, never repaired.

## Consumer obligations (per docs/sealed_vault.md)

`onWipe` → delete the `/dev/shm` working copy (mail's exemplar,
`plugins/mailbox/includes/bootstrap.php:190`). Rotation → `purgePersisted()`
(disposable cache rule — purge, don't re-seal). Registered from the Drive
core-consumer bootstrap the Private tier adds (refactor R4 there). The
Postgres-side extract column for Standard files has no vault obligations.

## Level transitions

Raise (Standard → Private): the extract moves custody with the file — the
batch job seals the existing extract and clears the `tsvector`. Lower: the
reverse, in-window. Both ride the transition machinery from the Private tier
spec; this spec adds only the two per-file steps.

## Decisions

- **S1 — v1 extractor set:** text/markdown/code, HTML, PDF. Office formats
  (docx/xlsx) deferred until an extraction dependency is chosen deliberately
  — no composer package sneaks in for this. Recommendation as stated.
- **S2 — extract cap:** first 512 KB of extracted text per file. Bounds both
  the Postgres row and the sealed index; the tail of a 400-page PDF stops
  being searchable before the index stops being viable. Recommendation: 512 KB.
- **S3 — index granularity:** one sealed index per user (mail's shape), not
  per folder. Simpler bookkeeping, one fold cursor; revisit only if a real
  corpus shows the single-blob persist straining even with streaming.

## Tests

`tests/functional/drive/content_search_test.php` (db): extraction registry
per format + cap enforcement, Standard FTS match end-to-end, Private
extract-sealed-at-upload (no window), in-window fold + match, locked search
omits Private content and says so, raise/lower moves extract custody,
rotation purges the persisted blob, wipe clears the working copy. Shared
`SealedFtsIndex` unit tests move the relevant mail index coverage into core.

## Docs

At build time: `docs/drive.md` search section; `docs/sealed_vault.md`
consumer table row for the shared index; mailbox overview updated only if the
mail consumer migrates onto `SealedFtsIndex` in the same checkin.
