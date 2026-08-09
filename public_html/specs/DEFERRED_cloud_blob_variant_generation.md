# DEFERRED — Thumbnails for Images Whose Bytes Live in a Bucket

**Status: DEFERRED — design record, not for implementation.** Captured so the analysis
doesn't have to be re-derived when a deployment actually turns on cloud offload and
notices it. Nothing here is scheduled.

## What this is

On a site that keeps its uploaded bytes in a cloud bucket, an image that arrived without
anyone asking for thumbnails will never get one. It shows as a blank tile in every listing,
forever, and nothing in the system will fix it on its own.

Locally this is already solved: the first time someone looks at a thumbnail that was never
built, the server builds that one size and serves it. The bytes are right there on disk, so
it costs a read and a resize. In a bucket they are not — producing one variant means pulling
the whole original down, resizing it, and pushing the result back up. That is not work a
page request should be doing while a browser waits, so the on-demand path declines it and
the tile stays blank.

Nobody is hitting this yet: it needs a deployment with offload turned on **and** a
subsystem that stores images without asking for sizes (inbound mail attachments, chat
uploads). That combination is why this is written down rather than built.

**A missing thumbnail is no longer a broken page** — it renders a file-type icon (see
*Resolved*, below). So everything here is about showing a picture where an icon currently
stands, which is worth doing but is not a defect anyone is waiting on.

## How it stands today

| Piece | Where | Behavior |
|---|---|---|
| On-demand generation | `data/file_blobs_class.php` — `FileBlob::ensure_variant()` | Returns `false` unless `fbb_storage_driver === 'local'`. The decline is deliberate and commented. |
| Serving | `serve.php`, the `/uploads/*` route | Missing variant → `ensure_variant()` → still missing → 404. |
| Cloud resize machinery | `FileBlob::_resize_cloud()` | **Already exists and works.** Downloads the original to a temp dir, generates the requested sizes, `put()`s each one back, cleans up. Reachable through `resize()`, just never called for a cloud blob outside an explicit admin action. |
| Offload of a partial set | `BlobStorageProfile::itemsForRow()` | Uploads the original plus **whatever variants happen to exist**, skipping the rest (`file_exists` guard per slot). |

That last row matters more than it looks. On-demand generation produces exactly a partial
variant set — an `avatar` and nothing else — and offload already handles that without
complaint. So the two features do not fight; the only gap is that once a blob is in the
bucket, its variant set is frozen at whatever it had on the way out.

The build is therefore small. `_resize_cloud()` is the whole engine. What's missing is a
caller that isn't a page request, and a way to know a variant is absent without a bucket
round trip on every miss.

## Why not just call it in the request

Three reasons, in order of how much they hurt:

1. **Latency is unbounded and not ours.** A GET of the original from the bucket, a resize,
   and a PUT back — for an original that may be tens of megabytes. The browser holds an
   image slot open the whole time, and a listing page fires one request per row.
2. **A miss costs a round trip even when the answer is "yes it's there."** Locally,
   "does this variant exist" is a `file_exists`. In a bucket it's a HEAD, so the *fast* path
   gets slower too, for every image on every page, to serve a rare case.
3. **It is trivially amplifiable.** The size key comes from the URL. One authorized viewer
   walking `/uploads/hero/<name>` across a mailbox of attachments would drive a full-size
   download-resize-upload cycle per file. The local path is gated behind `is_viewable()` for
   this reason; in a bucket the same gate protects far more expensive work.

## Sketch

Two halves, and the order matters — the first one is most of the value.

### Half one: generate the browse set on the way out (the primary approach)

**Build variants during offload, while the bytes are still local and already in hand.**
Offload is batch work that has the original open on disk at the moment it is about to push
it; producing the browse set there costs a local resize and one extra `put()` per size, and
it means no variant ever has to be pulled back down. Every blob offloaded after this ships
arrives in the bucket already complete, and the problem stops being created.

This is both the cheaper build and the better design. It attaches to
`BlobStorageProfile::itemsForRow()` (`includes/cloud_storage/BlobStorageProfile.php:63`),
which already enumerates the original plus whatever variants exist — the change is to
generate the wanted set first, then enumerate. The engine's ordering invariant
(PUT → reload → flip → delete) is untouched, because the new files exist locally before the
first PUT and are deleted with the rest afterwards.

Note what it does **not** do: it cannot help a blob that is already in a bucket. That is the
whole reason the second half exists, and the reason to build these in this order — ship the
generator and the population stops growing while you decide whether the backlog is worth
touching at all.

### Half two: a backfill for blobs already up there

**A scheduled task, not a request path.** `tasks/CloudVariantBackfill.php` + `.json`,
following the ordinary two-file convention in [Scheduled Tasks](../docs/scheduled_tasks.md).
It selects cloud image blobs missing a wanted variant, and for each one calls the existing
`resize($size_key)` — which routes to `_resize_cloud()` and does the right thing already.

This is the expensive half (every fix is a full download) and the optional one. With the
file-type icon shipped, an un-thumbnailed cloud image is cosmetic, so a site can reasonably
decide its backlog is not worth the egress and run this never, once, or only over a subset.

Three things need deciding before either half can be written, and they are the actual
content of this spec:

### 1. Knowing what's missing without asking the bucket

There is no cheap "does this object exist" — that's a HEAD per slot per blob, every pass.
The blob table already carries the precedent for solving this: `fbb_encrypted_variant_key`
records a variant's existence in a column precisely because *cloud-side lifecycle ops can't
scan a disk*. The same reasoning applies here, one step further — record the set.

A `fbb_variant_keys` column (a small array or comma list of size keys known to exist) makes
the query a plain SQL predicate, makes the backfill idempotent without probing, and lets
`ensure_variant()` answer "already there" for a cloud blob without any network call. It has
to be maintained by every path that writes or deletes a variant — `resize()`,
`_resize_cloud()`, `delete_resized()`, the offload and pull-back paths — which is the real
cost of this option and the main thing to get right.

The alternative is to derive it: enumerate with HEADs during the task's own pass and accept
the request cost there, where nobody is waiting. Simpler, no schema change, no invariant to
maintain — but it can't help `ensure_variant()` short-circuit, and it re-probes every pass.

**Recommendation: the column.** The derived version re-answers the same question forever,
and the column is the pattern the table already uses for exactly this problem.

### 2. Which sizes to build

Building all five registered sizes for every cloud image is the thing the local design
deliberately refused — it's how attachments balloon, just in a bucket where the bytes cost
money instead of disk. Options:

- **A named subset** (`avatar` only, or a small "browse set"), so backfill produces what
  listings actually ask for and nothing else. Predictable spend.
- **Demand-recorded**: the `/uploads` route, on a cloud miss, records the requested
  `(blob, size_key)` and returns 404 as it does now; the task builds what was actually asked
  for and the tile fills in on a later view. Spends nothing on images nobody opens, at the
  cost of a queue table and a tile that stays blank until the next pass.

**Recommendation: start with the named subset.** It's the smaller build, and it matches the
local behavior a site would already be used to. Demand-recording is the better answer if a
deployment turns out to have a large archive of images nobody ever looks at — decide it with
a real bucket in front of you, not now.

### 3. Bounding a pass

`_resize_cloud()` moves whole originals through a temp dir. A pass must be bounded by
**bytes**, not row count — the same choice `drive_level_batch` makes for the same reason
(one row can be enormous). It also needs the per-row advisory lock the offload engine uses
(`CloudOffloadEngine`, `ADVISORY_LOCK_NAMESPACE`) so a backfill and an offload drain can
never work the same blob at once, and `fbb_sync_failed_count` / `fbb_sync_last_attempt`
should carry retry backoff rather than a second mechanism being invented.

## Acceptance criteria

- A blob offloaded after this ships arrives in the bucket with its browse set already
  present — no backfill pass needed to make its listings look right.
- A cloud image blob with no variants gets the wanted size(s) built and readable at
  `/uploads/<size>/<name>` after a task pass, with no page request having done the work.
- A second pass over the same blob does no bucket work — it is idempotent.
- A pass is bounded by bytes and survives a mid-pass kill without leaving a temp dir behind
  (`_resize_cloud()`'s `finally` cleanup already covers the in-call case).
- A blob being offloaded or drained concurrently is skipped, not raced.
- Sealed and client-encrypted blobs are never touched — their stored bytes are a container,
  and resizing them yields garbage. `File::ensure_variant()` and `File::resize()` already
  carry this skip-list; the task must not reach past it by calling `FileBlob` directly.
- Turning offload on for a site that already has local variants changes nothing: those
  variants ride up with the original on the existing `itemsForRow()` path.

## Docs to update when this is built

- [`docs/drive.md`](../docs/drive.md) — the variant-generation section currently states that
  the on-demand path is local-only and says why. That sentence becomes wrong the moment this
  lands and must be rewritten to describe the cloud path as it then exists.
- [`docs/cloud_storage.md`](../docs/cloud_storage.md) — add the task to the offload picture.
- [`docs/scheduled_tasks.md`](../docs/scheduled_tasks.md) — only if the task needs config
  keys worth documenting.

Per the documentation rule, none of these change while this spec sits deferred: they
describe the system as it is now, and right now the cloud path genuinely declines.

## Resolved

- **Is a blank tile the right failure? No — and it's already fixed.** A file whose
  thumbnail can't be shown now renders a file-type icon instead of a broken-image glyph:
  `File::type_icon_url()` / `File::thumbnail_html()` pick the icon, and
  `assets/js/file-thumb.js` swaps it in on load failure. The swap is client-side because
  "does this variant exist" is not a question the server can answer cheaply per row — on a
  cloud blob it would be a HEAD per image per page — and because every cause of a miss looks
  identical to a browser.

  **This changes what the rest of this spec is for.** A cloud image with no thumbnail is now
  a cosmetic shortfall — an icon where a picture would be nicer — not a broken page. Nothing
  here is urgent, and the "build the browse set at offload time" half is worth doing on its
  own merits, whether or not anyone ever backfills the older blobs.

## Open questions

- **Is the backlog worth any egress at all?** With the icon in place, half two buys
  prettier listings for images already in a bucket, paid for in full downloads. That may
  simply never be worth it, and "we ship half one and never run half two" is a legitimate
  end state for this spec rather than a failure to finish it.
