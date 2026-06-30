# File — private-store offload + gated serving

**Status:** Implemented. (This file in `specs/implemented/` was edited post-implementation
— an explicit exception to the "never modify implemented specs" rule — to record an
as-built deviation from the offload task model; see *Implementation note — single-task
offload model* below.)
**Layer:** core (`File` / `fil_files`, the cloud-storage offload layer, `serve.php`
file route)
**Depends on:** `specs/implemented/cloud_offload_unification.md` — the
table-agnostic offload engine, the `StorageProfile` seam, the verified-private
store + its privacy gate, `CloudStorageDriverFactory::forVisibility('private')`,
and the defined private read posture (`get()` to temp → stream behind the
consumer's permission check → never `url()`). All of that exists; this spec gives
`File` the wiring to use it.
**First consumer:** inbound email attachments (its own spec) — but every
permission-/group-/event-/tier-gated file benefits, so this is a platform
capability, not an email feature.

## Goal

A restricted file on this platform is **pinned to local disk forever**. The
offload system can only move a file to the *public* bucket — every cloud code
path in `File` resolves the public `default()` driver, and the moment a file
becomes non-public the bytes are pulled back to local (`_pull_back_from_cloud_to_private`),
because the public bucket is world-readable. So a site running on a small VPS
watches its private uploads (group docs, event handouts, tier-gated downloads,
and soon email attachments) accumulate on the box with no way to drain them.

The verified-private store already exists for exactly this — it's built, gated,
and proven non-public — but `File` was never taught to use it. This spec teaches
`File` to offload restricted bytes to the private store and serve them back
through a permission-checked stream.

In plain terms: let private files live in the bucket too — safely — so a small
box doesn't fill up with them.

## What already exists (and is reused as-is)

- **The offload engine + lifecycle** — `CloudOffloadEngine::syncBatch(profile)` /
  `reverseBatch(profile)`, parameterized by a `StorageProfile`. Visibility-blind:
  it resolves its driver from `forVisibility($profile->visibility())`.
- **The verified-private store** — a second bucket on the same account, with a
  privacy hard-gate that proves it non-public before any byte lands;
  `forVisibility('private')` returns non-null only once that latch is set.
- **The private read posture** — defined in the offload spec as: never `url()`;
  `get()` the object to a temp path and stream it behind the consumer's
  permission check. The raw-message store already consumes the private store this
  way. This spec applies the same posture to `File`.
- **`File`'s own access gate** — `File::is_viewable($session)` already answers
  "may this session see these bytes?" across min-permission, group, event, and
  tier. The gated stream reuses it verbatim; no new authorization logic.
- **The per-row `fil_storage_driver`** — already the source of truth for *where*
  a file's bytes live (`local` / `cloud`). Unchanged in meaning.

## The gap, precisely

Two places assume "cloud == public," and one capability is missing:

1. **`File`'s cloud methods are hardwired to the public store.** `get_url()`,
   `_resize_cloud()`, `_permanent_delete_cloud()`, and
   `_pull_back_from_cloud_to_private()` all call `CloudStorageDriverFactory::default()`
   (the public driver). `_pull_back_from_cloud_to_private()` exists *only* because
   there's nowhere private to put bytes — a non-public file is dragged back to
   local rather than moved to a private bucket.
2. **The serve route has no private-cloud branch.** `serve.php` `/uploads/*`:
   - `cloud` driver → **302-redirect to the public bucket URL** (`driver->url()`).
   - local → `is_viewable($session)` then `serveStaticFile()`.
   A restricted file in a bucket would be 302'd to a URL that is (correctly)
   forbidden/absent. Local restricted serving already works; **private cloud
   serving does not exist.**
3. **There is no private `File` consumer of the engine.** `FileStorageProfile` is
   the public-files adapter; its `eligibilityWhere()` selects public rows. Nothing
   offloads restricted `File` rows anywhere.

## What to build

### 1. A file's store follows from its visibility

`File::is_public()` already returns the visibility: a file with **no** restriction
(min-permission, group, event, tier) is public; any restriction makes it private.
Map that to a store:

- **public file → public store** (today's behavior, untouched).
- **restricted file → private store** (new; today forced local).

`fil_storage_driver` keeps recording the *physical* location (`local` / `cloud`);
visibility decides *which bucket* `cloud` means for that row. No new column —
visibility is derived from the existing permission fields, the single source of
truth `is_public()` already reads.

### 2. A private `File` storage profile

Add a private-visibility profile so the engine drains restricted files. Either a
second profile class or a visibility-parameterized `FileStorageProfile` registered
twice — decide at implementation, but the contract is:

- **public profile:** `visibility = public`, `eligibilityWhere()` = public,
  local, under the failure cap (today's `FileStorageProfile`, unchanged).
- **private profile:** `visibility = private`, `eligibilityWhere()` = **restricted**,
  local, under the cap. Its `itemsForRow` / `reverseItemsForRow` reuse `File`'s
  existing key scheme (`remote_key_for()`), so the engine's proven forward/reverse
  ordering applies unchanged.

Both are declared in `storage_profiles.json` (core). The engine and the
binding-immutability guard then see private `File` rows the same way they see
public ones — no engine changes.

### 3. `File` cloud methods branch on visibility

Every `File` method that touches the bucket resolves its driver from the file's
visibility instead of always `default()`:

- `forVisibility('public')` for a public file, `forVisibility('private')` for a
  restricted one. (`default()` remains the public path it already is.)
- `get_url()` for a **private** file **never** returns a bucket URL. It returns
  the gated serve path (§4) — the "never `url()`" rule. Public files keep
  returning the direct bucket URL.
- `_pull_back_from_cloud_to_private()` becomes the *visibility-change* mover, not
  the only refuge for private bytes (§5).

### 4. Gated stream serving for private cloud files

Give `serve.php` `/uploads/*` a private-cloud branch (the local and public-cloud
branches are unchanged):

- Resolve the `File`, run **`is_viewable($session)`** — same gate as today's local
  path. Fail → 404 (not 403, to avoid confirming existence), same as now.
- Pass → resolve `forVisibility('private')`, `get()` the object to a temp path,
  stream it with `readfile()`, then unlink the temp. **No 302, ever** — the bytes
  flow through PHP so the permission check is enforced on every request and the
  private bucket URL is never exposed.
- Serve with `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`
  for non-image types, matching the existing hardened download posture.

This is the same posture the raw-message private consumer already uses; it is new
only for `File`.

### 5. Visibility changes on an already-offloaded file

When a file's permissions change so its visibility flips, its bytes are in the
wrong store. Handle conservatively by reusing the reverse machinery rather than
inventing a bucket-to-bucket move:

- On a visibility flip of a `cloud` row, **pull the bytes back to local** (the
  existing three-phase `_pull_back` flow, generalized to pull from whichever store
  currently holds them), leaving the row `local`.
- The next cron tick re-evaluates eligibility and offloads to the **now-correct**
  store via the matching profile.

Bytes briefly touch local disk during a flip — acceptable, and far simpler than a
cross-bucket transfer with its own partial-failure matrix. The first consumer
(email attachments) never flips visibility, so this path is for general `File`
correctness, not the email case.

## What does NOT change

- **The public-files path** — public uploads still offload to the public bucket
  and serve via 302 to the bucket URL. Behavior parity is the acceptance bar, the
  same discipline the offload-unification refactor held.
- **The driver, the engine, the lifecycle, the privacy gate** — reused. This spec
  adds a profile and teaches `File` to pick the right store; it does not touch the
  orchestration.
- **`File::is_viewable()` and the permission model** — reused verbatim as the
  stream gate. No new authorization surface.
- **The raw-message private consumer** — independent; it already works this way.

## Security & cost

- **Private bytes never get a public URL.** `get_url()` on a private file returns
  the gated path; the bucket URL is emitted only by the one sanctioned gate probe,
  never for serving. The private bucket is proven non-public before any byte lands
  (existing gate).
- **Every private fetch is permission-checked** — the stream runs `is_viewable()`
  per request; there's no cacheable redirect that outlives a permission change.
- **Honest cost:** a private cloud serve is a bucket `GET` + a pass-through stream
  on every hit — heavier than a public file's one-time 302-to-CDN. That's inherent
  to "never expose the URL," and fine for private, lower-volume files. Short-lived
  signed URLs could cut the per-hit cost later (out of scope) without weakening the
  gate.
- **Temp files are unlinked** after streaming; a failed stream cleans up.

## Implementation note — single-task offload model (as built)

While implementing this spec, the offload layer's task model was consolidated, so the
as-built behaviour differs from what `cloud_offload_unification.md` describes. That spec
gave **each store its own forward + reverse scheduled-task pair** and an enforced
forward/reverse mutual-exclusion guard. Adding the private `File` store would have made
that six tasks (public files, private files, inbound-mail raw × 2), growing by two per
future consumer.

Instead, all stores are now driven by **one platform task, `CloudOffloadRun`**. Each tick
it walks every declared `StorageProfile` (the registry) and runs each store in its current
**mode**:

- **offload** — store enabled: push eligible local rows → bucket.
- **drain** — store disabled with its draining flag set (Disable-and-Pull): pull cloud rows
  → local until none remain, then clear the flag.
- **idle** — store disabled, not draining (paused / unconfigured): do nothing; existing
  cloud rows keep serving.

The mode is derived per store (`CloudStorageLifecycle::modeForVisibility()`) from the
store's enabled latch plus a new draining flag (`cloud_storage_draining` /
`cloud_storage_private_draining`). The tick self-deactivates when no store is offloading or
draining. Consequences:

- A new offload consumer adds a `StorageProfile` and **zero** tasks.
- The forward/reverse mutual-exclusion guard is gone — it is now **structural** (a store
  has exactly one mode per tick, so a row can never ping-pong).
- **Three modes are deliberate, not redundant.** The tick has only two *actions* (push /
  pull); idle is the store at rest. The third state exists so "turn it off" can mean either
  **pause** (stop offloading, keep serving from the bucket — the safe off-switch for a
  disk-constrained VPS) or **drain** (evacuate back to local). Collapsing them would make
  the only off-button the one a low-disk box can't afford.

The private `File` store rides this tick like any other; it has no dedicated task. Migration
`migrate_offload_single_task.php` removes the obsolete per-store task rows and re-activates
`CloudOffloadRun` where a store is in use. `cloud_offload_unification.md` itself was left
unchanged (frozen); this note is the authoritative as-built record.

## Out of scope

- **Signed-URL serving** for private files — stream-through-PHP only for v1; signed
  URLs are a later optimization.
- **The inbound email attachment model** — lean-record message storage,
  attachments-as-`File`, the MIME manifest linking a `File` — is the *first
  consumer* and gets its own spec; this one only makes `File` private-capable.
- **At-rest encryption of file bytes** — `inbound_email_encryption_at_rest.md`'s
  concern. Noted only because the gated stream (§4) is the natural place in-session
  decryption will hook: a sealed file is opaque in the bucket and decrypted in the
  stream after `is_viewable()` passes. This spec does not implement sealing.
- **Bucket-to-bucket transfer** on visibility flips — handled via pull-back +
  re-offload (§5).

## Implementation outline (provisional)

1. **Private `File` profile** — add a private-visibility profile (second class or
   visibility-parameterized `FileStorageProfile`) selecting restricted local rows;
   declare both public and private in `storage_profiles.json`.
2. **Visibility-aware `File` cloud methods** — `get_url()`, `_resize_cloud()`,
   `_permanent_delete_cloud()`, and the pull-back resolve
   `forVisibility($visibility)`; private `get_url()` returns the gated path, never
   a bucket URL.
3. **Gated stream branch** in `serve.php` `/uploads/*` for private cloud files:
   `is_viewable()` → `get()` to temp → `readfile()` → unlink; nosniff +
   attachment disposition; never 302.
4. **Visibility-flip handling** — generalize `_pull_back` to pull from the current
   store to local on a flip; let the next tick re-offload to the correct store.
5. **Regression check** — public-files offload + serve unchanged (302 path
   intact); run the existing cloud-storage tests.
6. `php -l` + `validate_php_file.php` on every modified PHP file; bump version
   numbers on touched files.

## Docs

On implementation, update `docs/cloud_storage.md`: a "Private files" section
covering how a restricted `File` offloads to the private store and is served
through the permission-gated stream (never a public URL), and noting that any
gated file — group, event, tier, permission — now drains to the bucket instead of
pinning to local disk. Cross-reference the inbound email attachment spec as the
first consumer once it lands.
