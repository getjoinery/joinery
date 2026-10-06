# Decode a photo once, small, and inside a budget

**Status:** Implemented 2026-10-06, WP1–WP4. Verified by the unit and
blob-layer suites and an admin-uploader walk on dev (upright sizes; a
refusal on the row). The 256 MB container run, the ceiling walk and the
package convergence on an upgraded node are in the live verification queue.
Companion to
`multi_tenant_docker_hosts` WP2 item 3, which is proving a 256 MB site
container and found that resizing one 24-megapixel photo costs more memory
than the container has to spare. That spec's `ImageWorkLock` (one decode at a
time on a site) stays; this spec makes the one decode small. Implemented in
parallel with that work — see § Coordination.

## What this does

When someone uploads a photo, the site makes the smaller copies pages
actually show: an 80 px avatar, an 800 px content image, a 1920 px hero, and
so on (five sizes on a stock install). To make them, the site unpacks the
photo into raw pixels. Raw pixels are big: a 24-megapixel photo from a
current phone is about 2 MB as a JPEG and **92 MB** unpacked, and PHP's
memory limit cannot see those 92 MB because the image library allocates them
outside PHP. Today the site unpacks that photo **six times** per upload,
keeps one unpacked copy around while it makes the rest (so two are resident
at once, 184 MB), rotates a sideways phone photo by making a second
full-size copy, and has no ceiling on any of it: a photo that is too big is
found out by the kernel killing the worker.

After this spec:

- a photo is unpacked **once** per upload, and all sizes are cut from that
  one copy;
- a JPEG is unpacked **already shrunk** to no more than twice the largest
  size the site needs, so a 24 MP photo costs about 15 MB instead of 92;
- a sideways photo is turned upright **after** shrinking, on the small copy,
  on every upload path (today only the admin uploader turns photos upright;
  Drive and entity-photo uploads get sideways thumbnails);
- there is a **ceiling**: a photo whose unpacking would exceed it is refused
  with a logged, user-visible reason before any memory is spent, and the
  original is kept;
- the stored original is left **byte-for-byte as uploaded** (today the admin
  uploader re-saves a rotated original at JPEG quality 75).

## The numbers

Measured on dev (PHP 8.3, libgd 2.3.3, libjpeg-turbo 2.1.5) with a generated
6000×4000 JPEG, reading the process's resident memory from `/proc`. PHP's own
`memory_get_peak_usage()` read 2 MB throughout: none of this is visible to
`memory_limit`.

| Operation | Memory above idle | Time |
|---|---|---|
| Unpack a baseline JPEG | 92 MB (6000 × 4000 × 4 bytes) | 0.17 s |
| Unpack a **progressive** JPEG | **229 MB** (the decoder keeps every coefficient until the last scan: ~137 MB more) | 0.48 s |
| Two unpacked copies held at once | 184 MB | |
| `imagerotate` of an unpacked copy | 184 MB (source + rotated) | |
| Resample 6000 → 1920 from the full copy | +8 MB | 0.52 s |
| Resample 6000 → 80 from the full copy | | 0.27 s |
| Resample 1920 → 80 from a 1920 copy | | 0.03 s |
| Unpack a 2250×1500 image (a 3/8-shrunk 24 MP photo) | 13 MB | |

Pixel memory is 4 bytes per pixel in every format. Progressive JPEG adds up
to 6 bytes per pixel on top, in any libjpeg-based decoder, shrunk or not;
phone cameras write baseline, "optimized for web" exports are often
progressive.

Where the upload path stands today at 24 MP, admin uploader
(`adm/logic/admin_file_upload_process_logic.php`): the uploader unpacks the
photo for orientation, then `FileBlob::resize()` unpacks it again for each of
five sizes: six decodes, peak **92 MB** baseline, **229 MB** progressive,
held about 4 seconds under the lock. (Until 2026-10-06 the uploader also kept
its copy resident through the five — 184 / 320 MB — because
`UploadHandler::gd_destroy_image_object()` dropped nothing; the
`multi-tenant` session fixed that in its working tree, pinned in
`tests/unit/image_work_lock_test.php`.) Drive and entity-photo uploads skip
the uploader's pass: five decodes.

Expected after this spec, same photo:

| Case | Peak above idle |
|---|---|
| 24 MP baseline JPEG | ~15–20 MB |
| 24 MP progressive JPEG | ~140 MB (the coefficient buffer; unavoidable) |
| 12 MP phone photo, baseline | ~8 MB |
| 24 MP PNG (no shrink-on-decode exists for PNG) | 92 MB, or refused by the ceiling |

## Design

### One decoder

A new core class, `ImageDecoder` (`includes/ImageDecoder.php`), is the only
place the platform turns a file into a GD image. It answers one question:
*give me this image, upright, at no more than W×H pixels, or tell me why
not.*

```php
$decoded = ImageDecoder::open($path, $max_w, $max_h);   // ImageDecoded or throws ImageDecodeRefused
$decoded->image;        // GdImage, upright, dimensions >= every registered size where the source allows
$decoded->type;         // IMAGETYPE_* of the source
$decoded->source_w/h;   // the source's upright pixel size, for callers that record it
```

`$max_w`/`$max_h` are the **largest width and the largest height across the
registered sizes** (`ImageSizeRegistry::get_sizes()`; 1920 × 1080 on a stock
install). The decoder promises the returned image is at least that big in
each dimension unless the source itself is smaller, so every registered
size, cropped or not, resamples down from it exactly as it would from the
full source. The registry gains `max_dimensions()` returning that pair.

What `open()` does, in order:

1. **Read the header** (`getimagesize`, and for JPEG `exif_read_data` for
   the orientation and a scan of the first 64 KB for the SOF2 marker that
   marks progressive encoding). No pixels yet.
2. **Estimate the cost** in bytes: 4 × the pixel count the decode will
   produce, plus 6 × the source pixel count for a progressive JPEG. Compare
   with the ceiling (§ The ceiling). Over it: throw `ImageDecodeRefused`
   with the estimate and the ceiling in the message. Nothing allocated.
3. **JPEG: shrink while unpacking.** Pick the smallest M in 1..8 such that
   `source_w × M/8 ≥ max_w` and `source_h × M/8 ≥ max_h`, using the upright
   dimensions (orientations 5–8 swap them). If M < 8, run
   `djpeg -scale M/8 -bmp` on the file into a temp BMP and unpack that with
   `imagecreatefrombmp`. libjpeg's DCT-domain scaling is lossless at the
   output size and costs the decoder only the output rows (plus the
   coefficient buffer for progressive). If M = 8, or `djpeg` is not on the
   machine (logged once per process), unpack with `imagecreatefromjpeg` as
   today.
4. **Other formats** unpack in full with the GD function for the type.
5. **Orient.** For a JPEG with EXIF orientation 2–8, flip and/or rotate the
   *decoded* image — which is already small — and free the un-rotated one
   before returning. The returned image never carries orientation metadata,
   so every variant written from it is upright by its pixels.

The temp BMP lives in the site's cache directory under a private name and is
removed in `finally`. It is at most ~4 × (2·max_w) × (2·max_h) bytes — about
25 MB on a stock install — never the source's size.

### The child process

`djpeg` is the decoder half of libjpeg-turbo, the same library GD already
links for `imagecreatefromjpeg`, exposed as a program. It parses nothing GD
does not already parse, so it adds no format surface — the objection to
ImageMagick (`UploadHandler.php` § image handling) was its coders and
delegates, which libjpeg has none of. As a separate process it can be run
under an address-space limit (`ulimit -v`, through `sh -c`), which is the
one hard cap on decode memory available anywhere in this stack; the limit is
the ceiling plus 32 MB of headroom for the program itself. A `djpeg` that
dies under the limit, or exits non-zero, or produces no file, is treated the
same as step 2's refusal, with its stderr in the log.

It is invoked with `proc_open`, an argument array (no shell interpolation of
the path), stdin closed, a 60-second wall clock, and the lock already held by
the caller.

### The ceiling

One core setting, `image_decode_max_mb` (`settings.json`, default **160**):
the most memory one decode may take, counting pixels and the progressive
coefficient buffer. 160 MB admits a 24 MP progressive JPEG (~140 MB) on a
256 MB container only because the lock makes it the only decode running;
the multi-tenant work may lower it for the starter tier once WP2's budget is
proven. On a machine with no budget pressure it is still the right default:
a 100 MP scan is not something the site should try to thumbnail in a page
request.

A refused decode is not retried on every view. `ensure_variant()` is called
once per thumbnail request, so without a marker a refused photo would spend
a header read and an exception on each of twenty rows of a listing. The blob
records the refusal in a new nullable column `fbb_variant_refused`
(varchar 255: the reason), set when `resize()` is refused and cleared by any
successful `resize()`. `resize()` and `ensure_variant()` return false at once
when it is set. `utils/regenerate_image_sizes.php` clears it before trying
again, so raising the ceiling and regenerating is the recovery.

The user sees it: the upload response carries the reason (`image_resize`
error text already exists in `UploadHandler`; the drive and entity-photo
paths add the same string to their JSON), worded as *"This photo is too
large to make thumbnails for (estimated 230 MB, limit 160 MB). It was saved
as uploaded."*

### The variant pipeline decodes once

`FileBlob::resize($size_key)` becomes:

```
take ImageWorkLock
  $decoded = ImageDecoder::open(original, max_w, max_h)     (or record refusal, return false)
  for each wanted size: _render_size($decoded, $config, $dest)   — resample from $decoded->image, write atomically
  free $decoded
release lock
```

`_generate_resized()`'s geometry (crop box, scale factor, alpha handling,
GIF palette, temp-name-then-rename) moves unchanged into `_render_size()`,
which takes the decoded image instead of a path. `render_variant_to()` (the
sealed thumbnail) and `_resize_cloud()` call the same two steps. Nothing
outside `ImageDecoder` calls an `imagecreatefrom*` function; the mechanical
test (`tests/unit/core_api_mechanical_test.php`) gains that assertion, the
way it enumerates `server_initiated_write()` callers today.

The lock is taken once around the whole of `resize()`, not once per size:
with one decode the hold is one decode plus N resamples from a ≤ 6 MP image,
well under a second for a stock install, and the decoded image is released
before the lock is.

### The uploader stops decoding

`UploadHandler` no longer opens images at all. Its `''` image version
(`auto_orient`) and `gd_create_scaled_image()` go, along with the
`image_objects` cache and the orientation code; `handle_image_file()` keeps
only the "is it a valid image" check and the file size. Orientation is the
decoder's job, applied to every variant on every path.

The stored original is therefore exactly the uploaded bytes, EXIF and all.
Browsers, the OS image viewers Drive sync hands files to, and the native
apps' image stacks honour the orientation tag; a consumer that reads raw
pixels from the original must orient them itself, and the platform has no
such consumer today (`Photo.php`, the last GD user outside the pipeline, has
no callers and is deleted).

### What is not in scope

- PNG, GIF, WebP and AVIF have no shrink-on-decode; they unpack in full under
  the ceiling. Large ones are rare uploads and the ceiling bounds them.
- Already-generated sideways variants from Drive and entity-photo uploads are
  not regenerated by this spec; `regenerate_image_sizes.php` fixes any site
  that wants them (§ Decisions).
- `read_bytes()` and the mail attachment paths hold whole files in PHP
  strings; that is PHP-allocator memory, bounded by `memory_limit`, and
  fails loudly. Not this spec.

## Work packages

### WP1 — The decoder

Built 2026-10-06: `includes/ImageDecoder.php` 1.0, `ImageSizeRegistry` 1.1.0,
the setting, and `tests/unit/image_decoder_test.php` (32 checks with `djpeg`,
24 and one skip without; `JOINERY_DJPEG=/path/to/djpeg` runs the shrink path
on a box that has the program unpacked but not installed). Measured through
the decoder on dev: a 24 MP baseline JPEG opens at +13 MB in the worker with
`djpeg` peaking at 12 MB (0.25 s), against +93 MB in full; the progressive
one opens at +13 MB in the worker with the 149 MB coefficient buffer in the
child, and is refused at 160 without `djpeg` (229 MB in full). Orientation
was checked against ImageMagick's `autoOrient` for all eight tags before
the test pinned the expected corners. The child under `ulimit -v` ends with
"Insufficient memory", exit 1, not a kill.

- `includes/ImageDecoder.php` 1.0: `open()`, the cost estimate, the JPEG
  shrink step, orientation, the `djpeg` runner with its limit and timeout,
  `ImageDecodeRefused` (extends `RuntimeException`, carries `estimate_mb` and
  `ceiling_mb`).
- `includes/ImageSizeRegistry.php`: `max_dimensions()`.
- `settings.json`: `image_decode_max_mb`, default 160, group with the other
  file/upload settings, helptext in plain words (what it bounds, what
  happens over it).
- Tests, `tests/unit/image_decoder_test.php` (tier `safe`; GD only, generates
  its own images in the harness temp dir):
  - a 6000×4000 baseline JPEG opens at ≤ 2× the registered maximum in each
    dimension when `djpeg` is present, and the process's resident memory
    grows by less than 40 MB across the call (read `/proc/self/status`; the
    check is skipped, not passed, when `djpeg` is absent);
  - the same photo progressive (`imageinterlace`) is refused at a 100 MB
    ceiling and opens at 160;
  - a JPEG with a hand-written APP1 segment carrying Orientation 6 comes
    back upright (the test paints a mark in one corner and asserts where it
    lands), with the swapped dimensions; orientation 3 and 8 likewise;
  - a 4000×6000 portrait source gets M = 4/8, not 3/8 (the rule uses both
    dimensions);
  - a source smaller than the registered maximum is not shrunk and not
    enlarged;
  - PNG and WebP open in full; a 24 MP PNG is refused at a 60 MB ceiling;
  - a file that is not an image, and a truncated JPEG, raise
    `ImageDecodeRefused` with a reason, not a GD warning.

### WP2 — The pipeline

Built 2026-10-06: `file_blobs_class` 1.3.0 (`resize()` → `_render_sizes()` →
`_render_size()`, `fbb_variant_refused`, `variant_refusal()` /
`clear_variant_refusal()`), `files_class` 1.15.0 (`variant_refusal()`),
`UploadHandler` 2.0 (decodes nothing), `regenerate_image_sizes` 1.1.0,
`Photo.php` deleted, the three upload paths carry `warning` (admin uploader
row via `FormWriterV2Base` 2.29.0, entity photos via `PhotoHelper` 1.2.0,
Drive in the render payload). `core_api_mechanical_test` pins the decoder as
the only reader and lists the blob class's page-view write;
`image_work_lock_test` 1.1 pins one hold per resize; `blob_layer_test` 1.4.0
covers one decode, upright variants, untouched original, refusal record.
Walked on dev through the admin uploader: a 24 MP orientation-6 baseline
JPEG came out upright in every size (`content` 800×1200), and the progressive
one was saved with the refusal on its row.

- `data/file_blobs_class.php`: `resize()` as in § The variant pipeline,
  `_render_size()`, the refusal column and its handling in `resize()` /
  `ensure_variant()`, `render_variant_to()` and `_resize_cloud()` on the
  same path. Bump the class version with a one-line note.
- `includes/UploadHandler.php`: remove the GD code as in § The uploader stops
  decoding; the `image_resize` error text carries the decoder's reason.
- `adm/logic/admin_file_upload_process_logic.php`,
  `logic/drive_upload_complete_logic.php`, `ajax/entity_photos_ajax.php`:
  surface a refusal in the response.
- `utils/regenerate_image_sizes.php`: clear `fbb_variant_refused` first;
  report refusals separately from errors.
- Delete `includes/Photo.php`.
- `tests/unit/core_api_mechanical_test.php`: no `imagecreatefrom*` outside
  `ImageDecoder`.
- `tests/functional/files/blob_layer_test.php`: extend with one 3000×2000
  JPEG carrying Orientation 6: every registered variant is upright and the
  original's bytes are unchanged after upload (sha256 equal); a refused
  decode leaves the original, sets the column, and a second
  `ensure_variant()` does not decode (assert by the lock file's mtime or an
  `ImageDecoder` call counter exposed for tests).

### WP3 — The program on every machine

Built 2026-10-06. The package is declared in root `composer.json`
`extra.joinery-system-packages`, and `utils/list_dependencies.php` 1.2 emits
declared system packages (core, and plugin `requires.packages`) on its
`--apt` list as `name|name`, so the one resolver installs it at every root
moment: Docker image build, every container start
(`_install_declared_dependencies.sh` 1.2), `install.sh site`, and
`upgrade.php` 1.7 on a node installed before it was declared. `install.sh`
2.97's server step installs it beside `php-gd` as well, so the base image
carries it (`BASE_IMAGE_VERSION` 2.2). `tests/unit/list_dependencies_test.php`
pins the list. The dev box is the one machine none of those moments run on;
the owner installs it there by hand.

- `libjpeg-turbo-progs` (~330 KB installed) declared as a platform system
  package so the dependency resolver installs it everywhere, and installed
  beside `php-gd` in `install.sh`'s server step so the base image carries it
  (`BASE_IMAGE_VERSION` 2.1 → 2.2 in `install.sh` and `Dockerfile.template`;
  `installer_contract_test` pins that the two agree). The decoder degrades to
  a full decode without it, logged once.
- `docs/installation.md`: one line in the package list.
- The dev box: the owner installs the package (`sudo apt install
  libjpeg-turbo-progs`).

### WP4 — Docs

Built 2026-10-06: `docs/photo_system.md` § How sizes are made,
`docs/installation.md`, `docs/deploy_and_upgrade.md`, and the
`requires.packages` tier in `docs/plugin_developer_guide.md`. No `CLAUDE.md`
line: the rule is enforced by `core_api_mechanical_test` and explained in the
photo doc (owner, 2026-10-06).

- `docs/photo_system.md`: the resize section describes the decoder, the
  ceiling, the refusal column, and that originals are stored as uploaded.

## Coordination

The `multi-tenant` session owns `includes/ImageWorkLock.php`,
`includes/UploadHandler.php` and `data/file_blobs_class.php` until its lock
wiring is committed; it has said it is done editing them. WP1 and WP3 touch
none of those and start now. WP2 starts when that commit lands, after
telling that session, and keeps the lock exactly where it is in spirit (one
decode at a time): one `ImageWorkLock::run()` around the whole of
`resize()` — decode, every size, free — instead of one per size. `run()`
lets a nested call straight through, so a caller already holding it is
safe. `tests/unit/image_work_lock_test.php`'s check that `UploadHandler`
holds no image after an upload is kept and becomes trivially true once the
uploader stops decoding.

## Testing

`php tests/run.php --changed` for the loop; `php tests/run.php db --changed`
before check-in. Live verification queue, on the scratch Nanode at 256 MB
after WP3's image rebuild: upload the 24 MP baseline and progressive test
photos through the admin uploader, Drive and an entity photo, with two
uploads in flight; record the container's peak from `docker stats` and
confirm no kill in `dmesg`; confirm the variants are upright for an
Orientation-6 phone photo on all three paths; lower the ceiling to 100 and
confirm the progressive upload is refused with the message, its original
kept, and a listing page with that file does not log a decode per row.

## Decisions

- **Originals are stored as uploaded** (owner, 2026-10-06). Lossless, one
  decode fewer, and the pipeline orients every path the same way. A
  consumer that reads raw pixels from an original must honour EXIF itself;
  none exists today.
- **No regeneration of existing variants** (owner, 2026-10-06). Drive and
  entity-photo variants of sideways phone photos uploaded before this spec
  stay as they are; `regenerate_image_sizes.php` exists for any site that
  wants them redone.
