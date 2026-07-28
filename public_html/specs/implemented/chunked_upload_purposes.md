# Chunked uploads for any purpose

**Status:** Implemented. Built and verified 2026-07-28 — `upload_purposes_test.php`
(29 checks, db tier) plus a live 9 MB archive uploaded through the member import
form, past a 2 MB `upload_max_filesize`, scanned to 20,629 messages.

Drive's own path was not modified, and `drive_upload_api` passes unchanged (22
checks), which is the contract § 4 asks for.

The two items in § 8 remain open: both are policy questions that want a real
deployment rather than a guess.

Large-file upload already exists and works: a resumable, chunked transport that
carries multi-gigabyte files past every single-request limit. It is only wired to
one consumer. This makes it available to any of them, starting with mail archive
import, whose plain form POST caps at `upload_max_filesize` — 2 MB on the dev box,
against archives that routinely run to tens of gigabytes.

Nothing here is a new upload mechanism. It is a seam through the one that exists.

---

## 1. What is already generic

Most of the machinery has no idea Drive exists, despite the naming:

| Piece | Drive-specific? | Change needed |
|---|---|---|
| `fup_file_uploads` + `FileUpload` | No — token, user, expected/received bytes | One new column |
| `DriveUploadTransport` (`PUT /api/v1/drive_upload/{token}`) | No — appends chunks against a `FileUpload` | **None** |
| `DrivePurgeStaleUploads` | No — sweeps by age alone | **None** |
| `drive_upload_init_logic` | **Yes** — `drive_active`, quota, tier size cap, folder, encryption | Add a seam |
| `drive_upload_complete_logic` | **Yes** — same, plus `DriveUsage`, `FileChange`, `SOURCE_DRIVE` | Add a seam |

So the byte path — the part that is hard, security-sensitive and already proven at
multi-GB scale — is reused untouched. Only the policy at each end needs a seam.

## 2. The shape of the seam

Init and complete each do three things: **gate** the request, **stage or assemble**
the bytes, and **finalise** into a `File`. The middle is universal; the ends are the
consumer's business.

That split becomes an **upload purpose**: a registered name carrying the policy for
one kind of upload. Core keeps the transport and the assembly; the consumer supplies
what only it can know.

```php
UploadPurposeRegistry::register('mail_import_archive', [
    'source'       => File::SOURCE_MAIL_IMPORT_ARCHIVE,
    'max_bytes'    => 0,                                                     // 0 = no cap
    'authorize'    => function (int $user_id, array $input): ?string { … },  // null = allowed
    'restrictions' => ['fil_private' => true],
    'on_complete'  => function (File $file, FileUpload $up, int $user_id): void { … },
]);
```

`authorize` returns an error string or null, so a consumer can refuse for its own
reasons (Drive: quota and tier; mail import: the feature switch and mailbox access)
without core knowing what those reasons are.

This is the pattern the codebase already uses for file behaviour keyed on origin —
`File::registerDecryptHook($source, $decryptor)` does exactly this — so a purpose
registry keyed the same way is not a new idea, just the same one applied a layer up.

**Registration lives with the consumer.** Drive registers its purpose in core;
the mailbox plugin registers `mail_import_archive` in its bootstrap. Nothing in
core enumerates the purposes that exist.

### 2.1 `fup_purpose`

One new column on `fup_file_uploads`, `varchar(64)`, defaulting to `drive`. It
records which purpose opened the upload so `complete` can finalise it correctly, and
so an upload cannot be opened as one kind and completed as another.

Defaulting to `drive` is what keeps every existing row and every existing caller
correct without migration.

## 3. Endpoints

`drive_upload_init` and `drive_upload_complete` gain **one optional input**,
`purpose`, defaulting to `drive`. Every existing caller — Drive web, the sync
clients, mobile — is unchanged and unaware.

The endpoint names stay as they are. They are historical rather than descriptive,
and renaming them would break three shipped clients to gain nothing a comment cannot
fix. The docblocks say plainly that these serve every purpose, not only Drive.

An unknown or unregistered purpose is refused at init. A purpose whose `authorize`
returns a string is refused with that string.

## 4. Drive keeps its own path, deliberately

Drive's `complete` does considerably more than store bytes: new versions of existing
files, client-side encryption derived from the destination folder, per-reader file
key grants, encrypted thumbnails, quota under an advisory lock, and the change feed.
None of that is shared with any other purpose, and none of it is optional for Drive.

So Drive is **not** rewritten as a registered purpose. Instead the purpose branch is
taken **early** — before any Drive logic runs — and returns. Drive's code is left
exactly as it is, byte for byte.

That is the right trade. Restructuring a working, security-sensitive path that
handles somebody's encrypted files, in order to make it an instance of an
abstraction it does not need, buys elegance and risks data loss. The registry exists
to serve the SIMPLE case — gate, stage, create a `File`, hand back — which is what
every non-Drive purpose actually is.

The regression test is therefore blunt: `drive_upload_api` (22 checks) must pass
untouched, because Drive's code did not change.

## 5. The mail import purpose

Registered by the mailbox plugin as `mail_import_archive`:

- **authorize** — import must be enabled, and the caller signed in. Mailbox access
  is checked when the run starts, not at upload: a file can be uploaded before its
  destination is chosen.
- **source** — `File::SOURCE_MAIL_IMPORT_ARCHIVE`, so the archive stays out of the
  member's Drive listing and off their Drive quota. It is working material for one
  run, not a file they are keeping.
- **no folder, no encryption, no dedup short-circuit.** An archive has no Drive
  folder to land in; encryption is a Drive-folder property; and dedup would hand
  back a file the caller may not own.
- **on_complete** — nothing. The import run is started separately, by the existing
  `mail_import_start` action with the resulting `file_id`.

Storage cost is deliberately **not** charged to Drive quota. Whether an archive
should count against something else is a real question, and § 8 leaves it open
rather than answering it by accident.

## 6. The import form

The upload field switches from a form POST to the three-step flow:

```
drive_upload_init { purpose: mail_import_archive, name, size_bytes, mime_type }
  → { upload_token, chunk_bytes }
PUT /api/v1/drive_upload/{token}   × ceil(size / chunk_bytes)
  → Content-Range: bytes <start>-<end>/<total>
drive_upload_complete { purpose: mail_import_archive, upload_token }
  → { file }
mail_import_start { alias_id, own_addresses, file_id }
```

The panel shows real progress, because for the first time it has some: chunks
completed against chunks total. A failed chunk resumes from the server's
`received_bytes` rather than starting the archive again — which is the difference
between a recoverable hiccup and losing forty minutes.

**The client-side size refusal goes away**, because there is no longer a size to
refuse. The server-side guards stay: they are the backstop for any other caller.

## 7. Testing

**Tier `db`** — a purpose registers and is honoured; an unknown purpose is refused
at init; a purpose whose `authorize` refuses is refused with that reason; an upload
opened under one purpose cannot be completed under another; a mail-import upload
produces a `File` tagged `mail_import_archive` that does **not** appear in a Drive
listing and does **not** count against Drive quota; a Drive upload still does both.

**Regression** — `drive_upload_api` passes unchanged. That suite is the contract for
everything this touches.

**Explicitly asserted:** an archive larger than `upload_max_filesize` completes,
since that is the entire point.

## 8. Open items

- **Should an import archive count against any quota?** It is real disk held for the
  life of a run. Drive quota is the wrong meter — the file is not in their Drive —
  but "no limit at all" invites a full disk. Needs a policy decision, not a guess.
- **When is the archive deleted?** Today a run holds it indefinitely. Deleting on
  `done` would break re-running a failed import; keeping it forever accumulates.
  A sweep of archives whose runs finished long ago is probably right, but the
  interval wants a real deployment to pick.
