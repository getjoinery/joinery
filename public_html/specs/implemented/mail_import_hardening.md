# Mail import hardening

**Status:** Implemented. Built and verified 2026-07-28.

The record of everything that came *after* the two build specs — most of it found
by importing a real 142 MB Proton archive rather than by reasoning about one.

Companion documents, both already implemented:

- `specs/implemented/mail_archive_import.md` — the importer itself: readers, runs,
  entries, scan-then-choose, undo.
- `specs/implemented/chunked_upload_purposes.md` — opening the resumable chunk
  transport to consumers other than Drive.

This one exists because neither of those anticipated what actually broke. Four of
the defects below were in **core**, not in mail import, and had been shipping
quietly for some time.

---

## 1. Why this is worth reading

Both build specs were reasonable and both were wrong in the same way: they
described what the code should do and not what a real archive and a real
deployment would do to it. Every defect in § 3 was found by using the feature, and
none of them by a test written beforehand.

The single most transferable finding is in § 5 — **a test fixture that does not
match reality is worse than no test**, because it converts an unknown into a false
assurance. It happened three separate times here.

## 2. What the shape ended up being

```
  browser                    core                            mailbox plugin
  ───────                    ────                            ──────────────
  chunked upload  ────────▶  drive_upload_init               UploadPurposeRegistry
  (any size)                 PUT /api/v1/drive_upload/{tok}    registration
                             drive_upload_complete             (bootstrap.php)
                                    │
                                    ▼
                             File (fil_source =
                             mail_import_archive)
                                    │
  choose mailbox  ────────▶  mail_import_start ─────────▶  MailImportRun (queued)
  + own addresses                                                │
                                                                 ▼
                             RunMailImports (every cron pass)
                               scan  ──▶ MailImportEntry per message
                               stop  ──▶ SignalBus: mail_import.scanned
                                            │
  choose folders  ────────▶  mail_import_select                  │
                                                                 ▼
                               import ─▶ InboundEmailRouter::storeMessage
                                          (run_filters => false)
                               done  ──▶ SignalBus: mail_import.finished
                                    │
                                    ▼
                             PurgeMailImportArchives (daily)
```

Two properties carry the whole design and are worth not breaking:

**Dedup is the mechanism, not a feature.** Re-running an import stores nothing new,
which is what makes resume, retry, undo-and-retry, and "did I already do this" all
safe without a cursor being correct. This was exercised for real: the same archive
was imported three times.

**Filing and delivery address are independent columns.** Mail lands in the mailbox
the user chose while still recording the address it was genuinely delivered to.

## 3. Defects found by using it

### 3.1 Core — affecting everything, not just mail import

| Defect | Effect |
|---|---|
| `Notify` passed the link to the in-app notification but not to the email | **Every** notification email on the platform had no link |
| `protocol_mode = auto` inferred the scheme from the request, and cron has none | **Every** URL built headlessly was `http://` on TLS-only sites |
| A request body over `post_max_size` is discarded by PHP before any code runs | Schema validation then blamed a missing field the caller *had* supplied — affects every multipart action |
| `storeMessage` recognised only the *database* unique violation as dedup | The model's own pre-validation duplicate surfaced as a hard failure |

The protocol fix is the one worth understanding, because the naive repair would
have been wrong. `auto` cannot be resolved without a request, so the answer is not
to guess better — it is to **observe**: the front controller records the scheme
real requests arrive on, and headless code reads it back. That is accurate for an
HTTP-only deployment as well as a TLS one, because neither is assumed. A site that
has never served a request still falls back to `http`, since an http link to a TLS
site redirects while an https link to a plain site fails outright.

### 3.2 Mail import

| Defect | Effect |
|---|---|
| The upload was a plain form POST | Capped at `upload_max_filesize` — **2 MB** against archives of hundreds of MB |
| The scheduled task was never activated | Every import sat at *Waiting to start* forever, on any fresh deployment |
| Proton's numeric `LabelIDs` were treated as names | 1,932 messages tagged with a label called `15`, another 1,850 with `24` |
| `labels.json` was never read | Custom folders had no resolvable name — first mis-named, then silently **dropped** |
| `form.reset()` after a successful start | Looked as though the user's mailbox and address choices had been discarded |
| `workDir()` created a directory for every run | 115 empty directories accumulated; most formats never expand anything |
| Archives were never deleted | Unbounded disk growth, hundreds of MB per import |

The labels defect is the instructive one. The first fix — drop ids that cannot be
named — was *worse* than the bug it replaced: mis-naming a folder is visible, while
losing it is not. The user noticed only because they knew a "Meditation" folder
should be there. The real answer was sitting in the export the whole time as
`labels.json`, an id-to-name manifest that was never looked for.

**When an export carries metadata that cannot be interpreted, find the file that
explains it before deciding what to do with it.**

## 4. Decisions revised mid-build

- **Drive keeps its own upload path.** The first design made Drive a registered
  purpose like any other. Reading `drive_upload_complete` in full changed that:
  it handles versions, client-side encryption, per-reader key grants and encrypted
  thumbnails, none of it shared. Restructuring a working, security-sensitive path
  to make it an instance of an abstraction it does not need trades real risk for
  elegance. The purpose branch is taken *early* and Drive's code is untouched.

- **`webDir` is a host, not a URL.** An attempt to read a scheme from it could
  never fire — `Globalvars` strips it at construction and sources it from the
  config file — and would have competed with `protocol_mode` as a second override.
  Removed rather than patched around.

- **A grace period, not deletion on completion.** Deleting an archive when its run
  finishes is tidier and would have made this session impossible: the same archive
  was re-imported twice after an undo.

- **Numeric means system.** In a Proton export, a numeric label id is Proton's own
  and never something the user named. Unrecognised numerics are dropped; opaque
  ids are user folders and are resolved through the manifest.

## 5. The recurring failure mode: dishonest fixtures

Three separate times, a test passed against a shape the real world does not
produce:

1. The Proton sidecar fixture had `"LabelIDs":["0","Finance"]` — a bare *name*
   where a real export puts an opaque *id*. Assertions about label handling were
   therefore meaningless.
2. There was no `labels.json` in the fixture at all, so nothing failed when custom
   folders were silently dropped. The tests could not see a case they did not model.
3. Archive files in the test helper were created without `fil_source`, so the
   retention path treated them as user-owned and refused to reclaim them —
   exercising the opposite branch from production.

Each was found only by running against real data. The fixtures now mirror a real
export: numeric system ids, an opaque custom-folder id, a `labels.json` manifest,
a message with a genuine attachment, and archives tagged as production tags them.

**A fixture's job is to be inconveniently realistic.** One that is convenient tests
the code against itself.

## 6. Operational requirements

Both scheduled tasks must be **activated** — they are discovered from their
manifests but not switched on:

| Task | Frequency | Purpose |
|---|---|---|
| `RunMailImports` | every run | Scans and imports, one bounded batch per pass |
| `PurgeMailImportArchives` | daily | Reclaims archives past the retention window; sweeps orphaned working directories |

Until `RunMailImports` is active every import sits at *Waiting to start*. The
import surface now says so explicitly, names the fix, and confirms nothing is lost
— because a queue that silently never drains is indistinguishable from a broken
feature.

**Settings:** `mailbox_import_enabled`, `mailbox_import_batch_size` (measured at
~150ms per message, so this also sets how long a pass holds the cron runner),
`mailbox_import_max_concurrent`, `mailbox_import_archive_retention_days`.

## 7. Verified against a real archive

142 MB Proton export, 2,222 messages, uploaded through the member surface past a
2 MB `upload_max_filesize`:

| | |
|---|---|
| Stored | 1,932 |
| Already present (dedup) | 2 |
| Skipped (Spam + Trash, unticked by default) | 288 |
| Failed | 0 |
| Reconciliation | 2,222 / 2,222 |
| Folders | All mail 1,675 · Sent 201 · Inbox 40 · Drafts 18 |
| Custom folders | Meditation (4), by name |
| Date range | 2016-03-18 → 2026-07-27, from each message's own `Date` |

Undo was exercised twice on real data: 1,932 messages removed each time, with the
now-empty labels swept alongside them.

## 8. Still open

- **One batch per cron pass.** Raising `mailbox_import_batch_size` was chosen over
  a per-pass time budget. Adequate for archives of this size; a 100,000-message
  Gmail export would still want the task to keep working while it has time.
- **Search indexing at scale** (carried from the original spec). The mailbox index
  is incremental and user-scoped, and was not exercised by 1,932 messages.
- **Attachment storage growth** (carried). Imported mail is stored whole, so
  attachments land on platform disk. The scan preview should probably show an
  estimated size; this archive was not large enough to force the question.
- **Should an import archive count against any quota?** Drive's is the wrong meter
  — the file is not in their Drive — but no limit at all invites a full disk.
