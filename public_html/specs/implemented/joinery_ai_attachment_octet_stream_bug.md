# Bug: AI chat attachments — valid PDFs/images stored as `application/octet-stream`

**Status:** Implemented and verified live (2026-07-04). All four fixes applied;
the repro PDF that previously failed now returns its figures to the model, stored
`fil_type = application/pdf`, no drift. Ready to move to `specs/implemented/`.
**Reported:** A financial-statement PDF uploaded in AI chat was rejected with an
"octet stream instead of a pdf" message; after a partial detection tweak the file
was accepted but the model replied that the PDF "was omitted" and it had no
access to the contents.
**Related:** [[joinery_ai_file_uploads]] (the attachment feature this bug lives in).

## Plain-language summary

When someone attaches a PDF (or image) whose file signature isn't in the first
chunk of the file, the platform's type-sniffer shrugs and labels it "unknown
binary" (`application/octet-stream`). Two things then go wrong:

1. **At upload**, the attachment is rejected outright — "this is an
   application/octet-stream file, which can't be read" — even though it is a
   perfectly good PDF.
2. **If it gets past upload**, the file is *stored* with that wrong type. Later,
   when the message is sent to the model, the send-side encoder looks at the
   stored type, doesn't recognize it, and replaces the attachment with a note
   saying "An unsupported attachment was omitted." The model faithfully reports
   that it received nothing — so the user sees "I don't have access to the PDF."

The file itself is fine end to end (its text extracts correctly). The failure is
purely in **type detection and how the detected type is persisted.**

## How to reproduce

Any PDF whose `%PDF-` signature sits past libmagic's scan window triggers it. A
clean minimal PDF is detected correctly; a PDF with a binary preamble, leading
padding, or a linearization block before the header is not. Real-world exports
from banks/scanners commonly have this shape.

Deterministic repro used during investigation:

```bash
# A VALID, parseable PDF whose header sits past libmagic's window:
{ printf '%*s' 2000 '' ; cat any_real.pdf ; } > octet.pdf
php -r '$f=finfo_open(FILEINFO_MIME_TYPE); echo finfo_file($f,"octet.pdf");'
# => application/octet-stream   (but smalot/pdfparser still extracts its text fine)
```

Attach `octet.pdf` in `/admin/joinery_ai/chat` on a non-vision model (e.g. local
qwen or Fireworks GLM) in extract-text mode and ask a question about it.

## Cause analysis — proximate trigger, then the structure that let it through

### Proximate trigger — libmagic's scan window

`File::detect_mime_bytes()` / `detect_mime_file()` (`data/files_class.php`)
return finfo's answer verbatim. libmagic (`file-5.45` here) only matches a
signature within a limited window from the start of the file, so a valid
PDF/image with leading bytes comes back as `application/octet-stream`. That is
the specific input that exposed the bug — but two structural defects are what
turned one library quirk into a rejected-then-silently-omitted attachment.

### Structural defect 1 — the detection contract misstates its own failure mode

`detect_mime_*` documents "returns null when finfo cannot determine a type."
That never happens: finfo never returns null/false for readable bytes — when it
can't identify them it returns `application/octet-stream`, which is its "I don't
recognize this" **sentinel, not a type**. The code treats that sentinel as a
positive fact everywhere:

- The ingress fallback is dead code — it only fires on `null`/empty, which
  finfo never produces for real bytes:

  ```php
  $mime = File::detect_mime_bytes($bytes);
  if ($mime === null || $mime === '') $mime = (string)$u['client_type'];
  ```

  So `AiAttachment::validateRaw()` validates the sentinel as if it were the
  file's type and rejects: *"…is a application/octet-stream file, which can't
  be read."* — the message the user first saw.
- `File::apply_detected_type()` (`data/files_class.php:581`) persists the
  sentinel into `fil_type` — "detection wins" logic overwrites the caller's
  value with "I don't know," stored as if it were knowledge.

### Structural defect 2 — the validated type never flows to storage or send

The pipeline answers "what type is this file?" **three separate times**, by
three pieces of code that never reconcile, and the answer that passed
validation is the one that gets thrown away:

1. **Ingress** (`ChatAttachmentIngest::prepare()`): detects a MIME from the
   bytes, validates against it, extracts against it. This is the *validated*
   type — and it is not placed into the `$prepared` payload.
2. **Storage** (`ChatAttachmentIngest::commit()` → `File::createFromBytes()`):
   commit passes the raw browser-supplied `client_type` — not the validated
   type — and then `File::save()` re-detects from the written file and
   overwrites whatever the caller said (`apply_detected_type()`).
3. **Send** (`AiAttachment::blocksForAttachment()`): derives the routing
   category from the stored `fil_type`, from scratch, and when the category is
   unrecognized it degrades to a note:

   ```php
   $mime = self::normalizeMime($file->get('fil_type'));   // application/octet-stream
   $category = self::categoryForMime($mime);              // null
   if ($category === null) {
       return [self::note("An unsupported attachment ($label) was omitted.")];
   }
   ```

No invariant anywhere says *an attachment that passed prepare() is sendable*.
The two-phase prepare/commit design exists precisely to "fail loud instead of
storing an attachment the model will never see" (its own comment) — and this
bug demonstrates the design doesn't enforce that promise. Any disagreement
between derivation 1 and derivation 3 — today's finfo window, a future finfo
upgrade, a `finfo_buffer` vs `finfo_file` discrepancy, the cloud-storage path
where re-detection is skipped — reproduces this same class of bug: validated
at upload, silently omitted at send.

## Evidence gathered

- `finfo` correctly detects a clean PDF (`application/pdf`) and correctly detected
  the user's *image* in the same request path — so finfo is functional; the PDF
  itself is the finfo blind spot.
- Padding a valid PDF so `%PDF-` lands past ~1–2 KB reproduces
  `application/octet-stream` from both `finfo_file` and `finfo_buffer`, while
  `smalot/pdfparser` still extracts the text (exit 0).
- End-to-end repro: the attachment row stored `aia_extract_status = ok` with the
  full extracted text (81 chars incl. "Net Income: 270,000"), `aia_in_context =
  t`, owner matched the conversation owner — yet the send-side block built for it
  was exactly `{"type":"text","text":"An unsupported attachment
  (octet_statement.pdf) was omitted."}` because the stored `fil_type` was
  `application/octet-stream`.

## Blast radius beyond AI chat

This is a general `File` defect, not AI-specific. Any ingest path that stores a
PDF/image finfo can't place in its scan window persists a dishonest `fil_type`,
which also means:

- **Download serving** sends `Content-Type: application/octet-stream` for a real
  PDF/image (`File::serve*` reads `fil_type`).
- The **inline-safe allowlist** (`INLINE_SAFE_TYPES`, images only) treats a real
  PNG/JPEG mis-detected as octet-stream as non-inline, forcing a download.

Fix 1 below corrects all of these at once. Note PDF is *not* on the inline
allowlist, so correcting PDF detection does **not** change PDF serving (still a
download) — it only lets genuinely-image files serve inline as intended. No
SVG/HTML is ever sniffed, so the stored-XSS protection is unaffected.

## Fixes — one per layer

Four fixes, ordered from the shared `File` layer up to the send side. Fix 1
resolves the reported symptom on its own; fixes 2–4 close the structural gap so
the *class* of bug (validated at upload, degraded at send) can't recur from a
different trigger.

### Fix 1 — File layer: treat octet-stream as inconclusive; sniff signatures

Make `detect_mime_file()` / `detect_mime_bytes()` fall back to a **signature
sniff** whenever finfo is inconclusive — returns `null`, empty, **or
`application/octet-stream`**. A magic-byte signature is exactly as trustworthy
as finfo (it reads the same bytes), so this does not weaken the "never trust
the client extension/Content-Type" doctrine; it only covers finfo's scan-window
gap. Sniff only the binary types the platform accepts:

| Type | Signature |
|------|-----------|
| PDF   | `%PDF-` within the leading ~4 KB (spec allows a small preamble) |
| PNG   | `\x89PNG\r\n\x1a\n` at offset 0 |
| JPEG  | `\xFF\xD8\xFF` at offset 0 |
| GIF   | `GIF87a` / `GIF89a` at offset 0 |
| WEBP  | `RIFF` + `WEBP` at offset 8 |
| AVIF  | `ftyp` box brand `avif`/`avis` |

A genuinely-unrecognized binary still returns `application/octet-stream` and is
still rejected loudly downstream — the sniff adds positive detections for known
signatures only; it never invents a type.

Also **correct the contract docblocks** on both functions: `null` means finfo
is unavailable or the path is unreadable (a broken-server condition, not a
property of the file); `application/octet-stream` means "recognized by neither
finfo nor the signature table" and is the fail-closed answer callers should
reject on. The existing `null → client_type` fallback in
`ChatAttachmentIngest::prepare()` stays, now covering only the
finfo-unavailable case it was written for.

### Fix 2 — Ingress→storage: the validated type is the stored type

`prepare()` resolved and validated a MIME; that value must be the one that
reaches the `File` row. Two changes in `ChatAttachmentIngest`:

- `prepare()` adds the resolved `$mime` to each `$prepared` entry
  (`'mime' => $mime`, alongside the existing keys).
- `commit()` passes `$p['mime']` — not `$p['client_type']` — as the
  `$content_type` argument to `File::createFromBytes()`.

After this, the browser's `client_type` has no role past `prepare()`'s
last-resort fallback. `File::save()`'s insert-time re-detection still runs and
still wins (it is the `File` model's own invariant, and with Fix 1 both
derivations return the same answer) — but its job becomes *confirming* the
validated type, not deciding a type the AI layer never saw. `fil_source =
ai_chat_upload` and the private restriction are unchanged.

### Fix 3 — Commit: enforce "accepted means sendable," and stop failing silently

`commit()` currently logs and skips a failed file — the user sees their
attachment on the message, the model never receives it, and nothing on screen
says so. Two changes:

- **Verify the invariant after save.** After `createFromBytes()` returns,
  check that `AiAttachment::categoryForMime($file->get('fil_type'))` equals the
  category validated in `prepare()`. On mismatch, permanently delete the
  just-minted File row and treat the attachment as failed — a mismatch here is
  cross-layer drift (the exact shape of this bug), and it must surface as an
  error at commit time, not as a quiet "omitted" note at send time.
- **Report failures to the caller.** `commit()` returns the list of display
  names that failed (empty array = all stored). Both call surfaces
  (`views/admin/chat_send.php`, `logic/chat_send_logic.php`) already run after
  the user message exists; on a non-empty failure list they surface a visible
  error for those files in the response the same way other send-path errors
  surface, instead of leaving the user to discover the model can't see the
  file. Storage failures remain per-file (one bad file doesn't kill the turn).

### Fix 4 — Send side: an unroutable stored type is an invariant violation, not a shrug

With fixes 1–3, every attachment reaching `blocksForAttachment()` has a
`fil_type` that maps to an accepted category **by construction**. The
`$category === null` branch therefore no longer means "user attached something
unsupported" (ingress rejects that before a row exists) — it means stored state
violates the pipeline's invariant. Keep the branch (fail-safe for pre-fix rows
and future drift), but:

- `error_log()` it as an invariant violation (file id, stored `fil_type`,
  message id) so it is observable in monitoring rather than only in the model's
  reply.
- Keep emitting the visible note (never a silent drop), reworded to be honest
  about whose fault it is: "An attachment ($label) could not be included due to
  a server-side type error." — not "unsupported," which blames the file.

### Alternative considered (rejected)

Patch only the AI ingress (resolve the MIME in `ChatAttachmentIngest` / a new
`AiAttachment::resolveUploadMime()`). This makes the upload pass but leaves the
File persisted as octet-stream, so `blocksForAttachment()` still emits the
"unsupported… omitted" note — an *inconsistent intermediate state that is worse
than the original clean rejection*. It also leaves the download/serving and
inline-allowlist bugs unfixed and duplicates signature logic in two layers.
This is the band-aid; the layered fixes above address the cause.

## Test plan

1. **Unit — detection (Fix 1):** `detect_mime_bytes()` / `detect_mime_file()`
   return `application/pdf` for a padded-header PDF, correct image types for
   each sniffed signature, and still `application/octet-stream` for random
   binary (rejection preserved).
2. **Ingest (Fixes 1–2):** attach the repro `octet.pdf` on a non-vision model in
   extract mode → upload accepted, stored `fil_type = application/pdf`,
   `aia_extract_status = ok`, and the `$prepared` mime (not the client
   Content-Type) is what `createFromBytes()` received.
3. **Send (Fixes 1–4):** the model receives the extracted text and answers a
   question about the figures (no "omitted" note).
4. **Invariant enforcement (Fix 3):** simulate a type mismatch at commit (stub
   detection to return octet-stream at save time only) → the File row is
   deleted, no link row is written, and the send surface reports the named file
   failed. Nothing silent.
5. **Send-side fail-safe (Fix 4):** hand-set an existing attachment's `fil_type`
   to `application/octet-stream` → the turn still completes, the model gets the
   server-side-error note, and an invariant-violation line appears in the error
   log.
6. **Serving regression (Fix 1 blast radius):** a normal PNG/JPEG/PDF still
   detects and serves as before; a padded PNG now serves inline with an honest
   Content-Type; an SVG is still forced to download (never inline).

## Documentation

On implementation, update the existing docs in place (current-state voice, per
docs rules):

- The file-handling doc that describes magic-byte detection (the `File`
  type-detection contract): detection resolves the real type for known
  signatures even when libmagic's scan window misses them;
  `application/octet-stream` is the fail-closed "unrecognized binary" answer;
  `null` means finfo itself is unavailable.
- The joinery_ai attachment doc: the resolved-at-ingress MIME is the single
  type authority through storage and send; commit verifies it and reports
  per-file failures to the send surfaces.

## Cleanup note

Investigation left test artifacts on dev: conversation 54, a few test messages in
the pinned "Dovetail guide" conversation (29), File rows 644/645, and
attachment-link rows 5/6. Safe to delete on request (DB writes — needs
confirmation). Pre-existing orphan from earlier testing: attachment row 4 / File
642 (`aia_extract_status = failed`).
