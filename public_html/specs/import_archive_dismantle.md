# Dismantling the archive as it imports

**Status:** proposed
**Supersedes consideration of:** an `archive` raw-storage driver (referencing
bytes in place). The two are mutually exclusive — you cannot reference bytes you
have released — and this is the better end state. See *Why not reference it
instead*.

## Why

Importing an archive currently requires disk for the archive **and** everything
unpacked out of it, at the same time. The archive is dead weight the moment a
message has been stored from it, yet it is carried to the end of the run.

Measured on a real Gmail Takeout: a 6.3GB archive produces ~6.3GB of per-message
raw, 3.3GB of extracted attachments and ~2GB of database rows. The end state is
11.6GB; the *peak* is ~18GB, and it is the peak that decides whether a site can
import its own mail.

Releasing each region of the archive once it has been consumed makes the archive
drain as the store fills. At progress *p* the disk holds `A(1−p) + S·p`, which
never exceeds the larger of the two endpoints. The peak becomes the end state:
**11.6GB instead of 18GB, with no cloud storage and no new storage driver.**

## Mechanism: process backwards, truncate

Entries are imported in **descending** offset order, and the archive is truncated
to the lowest offset processed after each batch.

Every message still to be read lives *before* the cut, so no recorded locator is
ever invalidated. That is the whole trick, and it is why this needs nothing more
exotic than `ftruncate()` — no hole punching, no sparse files, no filesystem
dependency, and no syscall PHP lacks a binding for.

`MailArchiveImporter::importBatch()` orders by `mie_ordinal ASC`. That single
line is what decides the direction; reversing it is the substantive change.

### Per-format release

Release is the reader's business, because only the reader knows what a locator
means. `MailArchiveReader` gains a method that defaults to doing nothing, so a
format that cannot release stays correct by omission:

```php
/** Release every byte at or after $offset. Default: nothing is reclaimable. */
public function release(string $path, int $offset): void {}
```

- **mbox** (`offset:length`) — `ftruncate($handle, $offset)`. The common large
  case, and the only one that matters for a Takeout.
- **file-per-message** (`f|member`) — unlink each consumed member. Simpler than
  truncation and reclaims whole files.
- **expanded work-dir copies** — a zip holding an mbox, a tar. The expanded copy
  is the importer's own temporary, so it is released the same way an mbox is.
  The *source* archive is never touched by this spec.

### Ordering is a correctness requirement

Outcomes are committed first, the region is released second. A crash between the
two costs nothing — the next pass simply releases it. Releasing first would
discard bytes for entries still `pending`, which is unrecoverable.

## Failed entries keep their bytes

A `failed` entry inside a released region would otherwise be unretryable and
uninvestigable, which is exactly the property the loss-proof work exists to
protect.

**Before releasing a region, the bytes of every `failed` entry in it are copied
to a per-run quarantine file**, and the entry's locator is rewritten to point
there. Failures are exceptional, so this costs almost nothing in practice, and it
keeps a failure diagnosable and retryable after the archive is gone. A quarantine
that cannot be written blocks release of that region rather than discarding it.

`pending` blocks release outright. `stored`, `dedup` and `skipped` are
disposable.

## Whose archive it is

Most archives are already the importer's own. An archive uploaded on the import
page arrives through the platform's chunked transport under the
`mail_import_archive` purpose and is tagged `File::SOURCE_MAIL_IMPORT_ARCHIVE` —
private working material for one run, deliberately kept out of the member's Drive
listing and off their Drive quota. Consuming it needs no special permission: it
is what the retention sweep already deletes on a timer.

**A Drive-picked archive is the exception**, and it is the user's own file, which
the importer never touches. Two defensible answers, and this spec takes the
second:

1. Refuse to dismantle a Drive-picked archive. Simple, and costs nothing in the
   common case.
2. Offer it, with consent, because a user who has the original elsewhere would
   rather have the disk space.

So the gate is consent, taken at start-run, off by default, and shown only when
the chosen archive is one the importer does not already own:

> **Reclaim space as it imports.** The archive is consumed as its messages are
> stored, and will not exist when the run finishes. Without this, the import
> needs about *N* free; with it, about *M*.

The estimate shown is the real one (see *Disk estimate*, below).

**Nothing may be left holding a lie.** When a run completes having consumed its
archive, the File row is deleted, with the reason recorded on the run — not left
listed at its original size pointing at nothing. That applies to an
importer-owned archive as much as a Drive-picked one; the difference is only
whether anyone was asked first.

**Uploading should be guarded too.** `drive_upload_init` already knows
`fup_expected_bytes` before a single chunk arrives, and checks nothing against
free space — so a large archive can fill a disk on the way in, before the import
guard ever runs. That is a gap independent of this spec and its natural fix is
the same `DiskSpace` call the importer makes.

## What this costs

Stated plainly, because these are real losses and the consent copy depends on
them being understood:

- **Restart stops existing.** Forward resume still works — that is what the entry
  outcomes are for — but a run cannot be started again from the beginning,
  because the beginning is gone.
- **Undo cannot re-import.** Undo already deletes only what the run created, and
  it keeps working. What it can no longer do is put the mail back afterwards.
  The undo confirmation must say so for a dismantled run.
- **A second run against the same file is impossible.** A file being consumed by
  a live run is refused to any other run.

## Why not reference it instead

The alternative was to leave the archive whole and have imported mail reference
byte ranges within it. It reaches the same 11.6GB and preserves the source — but
it makes one 6.3GB file load-bearing for 98,000 messages, changes the retention
contract (the archive can never be discarded), needs a new storage driver, and
gives every message a shared single point of failure.

Dismantling ends in the platform's ordinary state: independent per-message raw
objects that the existing cloud-offload engine can move, that back up
individually, and that fail individually. The peak is avoided rather than the
duplication being made permanent.

## Disk estimate

`MailArchiveImporter::estimatedStorageBytes()` currently returns twice the
archive, which is deliberately conservative and, with this change, wrong in
shape: a dismantling run's peak is the *end state*, not the archive plus the end
state.

The estimate becomes conditional on the release mode, and both branches should
stop guessing: the scan already walks every message, so it can record what it
measured — total message bytes and total attachment bytes — and the import can
size itself from the archive in front of it rather than from a constant.

## What must not change

- **Reconciliation.** `reconcile_mail_import.php` compares the inventory against
  `mie_locator` values held in the database, not against the archive. It is
  unaffected, and must stay unaffected — locators are never rewritten except for
  quarantined failures, which the reconciliation already reports by identifier.
- **Dedup, direction and labelling** are untouched. Reversing the order changes
  which message is stored first, nothing about what is stored.
- **The disk hold.** A dismantling run still checks free space per batch; it will
  simply hold far less often.
- **Default behaviour.** Without consent, an import behaves exactly as it does
  today.

## Acceptance

1. An archive larger than the free space beside it imports to completion, with
   peak additional disk never exceeding the end state.
2. Every message in the archive is stored, deduped or skipped — the reconciliation
   reports no shortfall against an inventory taken beforehand.
3. A run killed mid-batch resumes and completes, losing at most the batch in
   flight, with no message unaccounted for.
4. A message that fails is retrievable from quarantine after the archive is gone,
   and its recorded locator resolves to those bytes.
5. Truncation never advances past a `pending` entry, under a crash injected
   between the outcome commit and the release.
6. A consumed archive's File row does not survive the run.
7. A run without consent leaves the archive byte-identical.

## Documentation

On landing, `plugins/mailbox/docs/overview.md` § *Room to put it* gains the
account of release mode: what consent means, what it costs, how failed entries
are preserved, and why the import runs backwards. Written as current state.

## Out of scope

- **Releasing the source of an expanded format** (the zip or tar itself). Those
  already need their own space to expand; a separate question.
- **Streaming to or from cloud storage.** See `specs/streamed_backups.md` for the
  backup half; the import half is a later step that composes with this one rather
  than competing with it.
- **Changing what is imported.** This moves the same messages.
