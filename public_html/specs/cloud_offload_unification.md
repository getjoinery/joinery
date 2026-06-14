# Unified cloud offload — one engine + lifecycle, with public/private as a storage dimension

## Overview

The cloud-storage feature abstracted the **driver** (`CloudStorageDriver`:
`put`/`get`/`delete`/`ping`) — the byte primitives are generic. But two things above
the driver were never abstracted, and this spec fixes both:

1. **The offload lifecycle is hardcoded to `fil_files`** — the descriptor
   (`fil_storage_driver` + counters), the forward task (`CloudStorageSync`), the
   reverse task (`CloudStorageReverseSync`), and the admin flow
   (`admin_cloud_storage_logic`). A second consumer has nothing to reuse and must
   **copy** all of it. This spec extracts the orchestration into one table-agnostic
   engine + lifecycle, parameterized by a small per-consumer `StorageProfile`.

2. **Public-vs-private is treated as a per-consumer concern.** It is not — it is a
   property of the **store**. This spec makes **visibility (`public` / `private`) a
   first-class storage dimension**: the platform offers a public store and a
   (verified-private) private store, and a consumer just declares which it needs. The
   privacy *guarantee* lives in the storage layer, verified once — not re-implemented
   per feature.

The existing public-files path becomes the engine's first consumer
(`visibility = public`), with **identical behavior**. The private store is built and
verified here as a platform capability with **no byte-consumer yet** — it is
configured and gate-tested on the storage admin page on its own. Inbound mail
([inbound_raw_message_storage.md](inbound_raw_message_storage.md)) is then the first
private consumer: one profile declaring `visibility = private`, nothing more.

This refactor touches working, tested code, so its overriding constraint is
**regression safety**: relocate the proven public-files logic behind the seam without
rewriting it; the private store is purely **additive**. See *Scope discipline*.

## The principle

**The driver is the byte backend. The engine + lifecycle are the one shared
orchestration. Visibility (`public`/`private`) is a property of the store, owned by
the storage layer — which bucket, how it's read, and the privacy guarantee all follow
from it. A consumer differs only in a `StorageProfile`: which table, what an
object-per-row looks like, and whether its bytes are public or private.**

## Scope discipline (regression safety)

The acceptance bar is **behavior parity for the public-files path**; the private store
is additive and must not alter it.

| Code | Treatment |
|------|-----------|
| `CloudStorageDriver`, `CloudStorageS3Driver` | **untouched** — already generic; the private store reuses the S3 driver against a different bucket |
| `File` model storage methods (`get_filesystem_path`, `remote_key_for`, `is_public`, `is_image`) | **untouched** — `FileStorageProfile` *calls* them |
| `fil_` descriptor columns + meaning | **untouched** — no schema change |
| `CloudStorageSync::_sync_row` / `_record_failure` bodies | **relocated** into the engine verbatim; `$file->...` → `$profile->...` of the same shape (same SQL, same ordering, same advisory-lock) |
| `admin_cloud_storage_logic` helpers (`_test_connection`, `_persist_settings`, `_activate_task`, `_deactivate_task`, `_health`) | **relocated** into the shared lifecycle, parameterized by store/profile |
| `CloudStorageDriverFactory` | **extended** with `forVisibility()`; existing `default()` stays as the public path |
| `adm/admin_cloud_storage.php` (view) | **+1 field** — the private bucket name; the rest unchanged |
| The two guards + the private store (config, gate, `forVisibility('private')`) | **new** — the only behavior changes; the guards fix existing latent bugs, the private store is additive |

"Relocated" means moved without logic edits beyond the indirection.

### Implementation sequence (characterization-first — not optional)

There is **no existing automated coverage** of the offload path, and the relocated
code includes a **destructive step** (the engine deletes local files after flipping a
row to `cloud`). A transcription slip there loses real user files silently. So the
order of work is fixed:

1. **Pin the current behavior first.** Write
   `tests/integration/cloud_storage_characterization_test.php` against the
   **unrefactored** `CloudStorageSync` / `CloudStorageReverseSync` and
   `admin_cloud_storage_logic`, and get it green **before touching any of that code.**
   It must cover, against real `fil_files` rows + a mock driver: forward push → flip to
   `cloud` → local files deleted; a missing-on-disk file → counter increments →
   excluded after the cap; a row that goes ineligible mid-flight → push undone, row
   stays `local`; reverse pull-back → `local` with the inverse ordering; and the admin
   activate/pause/disable task transitions.
2. **Relocate against the pin.** Extract the engine/lifecycle/profile and re-point the
   shims and admin logic. The characterization test must stay **green unchanged** —
   that green is the parity proof. Diff the old `_sync_row` against the new engine
   method to confirm only the indirection changed.
3. **Then add the new-surface tests** (engine over a mock profile, the two guards, the
   private store) and the additive private machinery.

Steps 1→2 are the regression net; do not invert them. The characterization test is
written to pass on today's code, so if it ever needs editing to pass post-refactor,
that edit *is* the regression and must be justified, not absorbed.

### The two latent bugs this fixes (both currently live on the public path)

1. **Bucket rename strands `cloud` rows.** `_persist_settings` overwrites the bucket
   unconditionally; Save only tests the *new* bucket. The per-row key is
   bucket-invariant, so the bucket setting is the only record of where a `cloud` row
   lives. **Fix:** the binding identity `(endpoint, bucket)` of a store is
   **immutable while that store has any `cloud` rows**; to switch, disable + pull back
   to local first. Access-key rotation (same binding) stays allowed.
2. **Enable-while-reverse-running.** `disable_and_pull` activates the reverse task; a
   later enable activates forward but does **not** deactivate reverse, so both run and
   a row ping-pongs. **Fix:** activating forward deactivates reverse and vice versa —
   one mutual-exclusion in the shared lifecycle, applied per store.

## Visibility — the storage dimension

Two stores, owned by the storage layer. A profile names one; the layer maps it to a
bucket binding, a read posture, and a privacy guarantee.

| Visibility | Bucket | Read posture | Guarantee |
|------------|--------|--------------|-----------|
| `public` | `cloud_storage_bucket` | public URL (PHP-bypassed) | none — world-readable by key |
| `private` | `cloud_storage_private_bucket` (same endpoint/region/keys) | **server-side gated stream** — `get()` to temp, stream behind the consumer's permission check; never `url()` | bucket **verified non-public** before any byte lands |

The factory resolves a driver by visibility:

```php
CloudStorageDriverFactory::forVisibility('public'): ?CloudStorageDriver
        // null when cloud_storage_enabled is off (current default() behavior, kept)
CloudStorageDriverFactory::forVisibility('private'): ?CloudStorageDriver
        // null until a private bucket is configured AND the privacy gate has passed
        // (cloud_storage_private_enabled latched true). Built via the existing
        // fromOptions() path: shared endpoint/region/keys + the private bucket name.
```

`default()` is retained as the public path. `forVisibility('private')` returning
non-null is the single signal "there is a usable, proven-private store." Read mode is
**derived** from visibility, not a separate knob: `private` ⇒ gated stream, always.

**Reverse runs against a *disabled* store.** Pull-back is triggered right after an
admin disables a store, so `forVisibility()` — which returns null when the store's
enabled-latch is off — is the wrong resolver for `reverseBatch`. The engine resolves
the reverse driver from the visibility's **raw bindings via `fromOptions()`** (the
fallback already living in `CloudStorageReverseSync.php:35-51`), so a draining store
still has a driver with its latch off. Forward (`syncBatch`) keeps the
`forVisibility()` null-⇒-skip behavior. Relocating this fallback is part of the parity
bar — losing it silently no-ops every pull-back.

### Private store configuration + the privacy hard-gate (owned by the storage layer)

The private bucket is **not a separate provider/account** — it is one more bucket on
the *existing* cloud-storage credentials. The only new input is a bucket name, one
field on `/admin/admin_cloud_storage`:

```
cloud_storage_private_bucket   = ''     bucket on the SAME provider/account as public storage;
                                        reuses its endpoint/region/keys.
cloud_storage_private_enabled  = false  internal latch; set true ONLY after a Save whose
                                        privacy gate passed.
```

**Privacy is a hard gate, verified before the store is usable.** Unlike the public
test (verifies public read *works*), the private test verifies public read is
*denied*:

1. PUT a scratch probe with platform credentials; an authenticated HEAD confirms the
   write.
2. Fetch the same key **anonymously** via the bucket's direct URL — formed by the
   driver's `url()` for that key (the exact URL a misconfigured-public bucket would
   serve), fetched with **no credentials**. The "never `url()`" rule governs *serving*
   private bytes to users; this one-time gate probe is the sole sanctioned `url()` call
   on a private store.
3. **Anonymous 200 ⇒ bucket is public ⇒ gate FAILS**, `cloud_storage_private_enabled`
   is not set, and the admin sees: "This bucket is publicly readable; it cannot be
   used for private files. Make it private and re-test." Any non-200
   (403/401/404/refused) ⇒ anonymous read denied ⇒ gate passes.
4. DELETE the probe.

Until the gate passes, `forVisibility('private')` returns null and no private bytes
can be written. Empty `cloud_storage_private_bucket` ⇒ no private store; degrades
cleanly. Each store's Save is validated **independently** — a private-bucket failure
never blocks the public bucket's Save, and vice versa.

## The seam — `StorageProfile`

The profile declares only what differs; visibility hands the rest to the storage
layer. It knows nothing about buckets, URLs, or the privacy gate.

```php
interface StorageProfile {
    // identity
    public function table(): string;
    public function pkeyColumn(): string;
    public function driverColumn(): string;        // e.g. 'fil_storage_driver'
    public function failedCountColumn(): string;
    public function lastAttemptColumn(): string;

    // visibility — the only public/private signal a consumer gives
    public function visibility(): string;          // 'public' | 'private'

    // batch selection: extra AND-conditions for an offload-eligible 'local' row
    public function eligibilityWhere(): string;    // File: the is_public() gates; '' = always

    // per-row
    public function rowExists(int $id): bool;
    public function isEligibleRow(int $id): bool;       // re-check under lock (File: is_public())
    public function itemsForRow(int $id): ?array;       // FORWARD: [{local_path, remote_key, content_type}] present on disk; null if bytes missing
    public function reverseItemsForRow(int $id): array; // REVERSE: [{remote_key, local_path, content_type}] from the row's key scheme + placement,
                                                        //          computed WITHOUT requiring local bytes (on pull-back they never exist yet)

    // task identity (per consumer, for scheduler tracking)
    public function forwardTaskClass(): string;
    public function reverseTaskClass(): string;
}
```

### `StorageProfileRegistry` — declarative enumeration

Profiles are **declared, not self-registered at runtime**, so the registry sees them
regardless of whether the owning plugin is active — matching how the platform already
declares plugin settings and menus.

- **Core profiles** are listed in `storage_profiles.json` at the `public_html/` root
  (the core-manifest analogue of `settings.json` / `admin_menus.json`). Spec A declares
  one: `FileStorageProfile`.
- **Plugin profiles** are listed under a `storage_profiles` key in the plugin's
  `plugin.json`. (Inbound mail declares its private profile there in spec B.)

Each entry is just a class name — no visibility is restated in the manifest:

```json
"storage_profiles": ["InboundRawStorageProfile"]
```

`StorageProfileRegistry` builds its set by reading the core manifest and scanning every
plugin's `plugin.json` **on disk — active or not** — then instantiating each class and
grouping by `$profile->visibility()` (the single source of truth for which store an
object belongs to).

This is what lets the lifecycle and guard 1 operate over "every profile of a given
visibility": the private store's `cloud`-row count is summed across all `private`
profiles, and a Save that would change the bucket is refused while *any* of them holds
cloud rows — **including a deactivated plugin's**, because deactivation leaves the files
(and so the declaration and the class) on disk, so the guard can still see them.

**Uninstall is the one gap, closed by policy, not code.** Uninstalling a plugin removes
its files, so its declaration disappears and the guard can no longer see its rows.
Uninstalling a plugin that owns a `private` profile therefore **requires the store
drained back to local first** — the same disable-and-pull flow guard 1 already points
admins at. (Spec A's only profile is core, so this bites no earlier than spec B.)

## The engine — `CloudOffloadEngine`

The relocated bodies of `CloudStorageSync` / `CloudStorageReverseSync`, table-agnostic
and **visibility-blind** (it just uses `forVisibility($profile->visibility())`):

```php
CloudOffloadEngine::syncBatch(StorageProfile $p): array      // local -> cloud
CloudOffloadEngine::reverseBatch(StorageProfile $p): array   // cloud -> local
```

Same bounded batch (`BATCH_LIMIT` / `TIME_BUDGET_SECONDS` / `FAILED_COUNT_CAP`), same
per-row advisory lock, same ordering invariant. Per-row forward (unchanged from
`_sync_row`):

1. Load; skip if gone (`rowExists`).
2. Re-check eligibility under the lock (`isEligibleRow`); skip if not.
3. Build items (`itemsForRow`); record-failure if bytes missing on disk.
4. `putMany` the items; on partial failure best-effort `delete()` pushed keys and
   record-failure.
5. **Reload + re-check eligibility**; if changed mid-flight, undo the push, stay
   `local`.
6. `UPDATE <table> SET <driverColumn>='cloud', <failedCountColumn>=0,
   <lastAttemptColumn>=now() WHERE <pkeyColumn>=?`.
7. Only then `unlink` the local files.

Reverse mirrors with the opposite ordering (pull to temp, commit `local`, then
best-effort bucket `delete()`). Its key list + local destinations come from
`reverseItemsForRow` — distinct from `itemsForRow` because pull-back enumerates remote
keys from the row's scheme, *not* from files on disk (there are none yet). Failure path
is the relocated `_record_failure`.

## The shared lifecycle — `CloudStorageLifecycle`

The relocated admin helpers, parameterized by **store** (visibility) and profile:

```php
CloudStorageLifecycle::testConnection(array $opts, string $visibility): array
        // public  policy: anonymous read must WORK
        // private policy: anonymous read must be DENIED (the privacy hard-gate)
CloudStorageLifecycle::persistSettings(array $opts, string $visibility, $session): array
        // calls assertBindingMutable() first; latches the visibility's enabled flag on success
CloudStorageLifecycle::activateForward(StorageProfile $p): void   // deactivates reverse  (guard 2)
CloudStorageLifecycle::activateReverse(StorageProfile $p): void   // deactivates forward  (guard 2)
CloudStorageLifecycle::deactivate(StorageProfile $p): void
CloudStorageLifecycle::health(StorageProfile $p): array
CloudStorageLifecycle::assertBindingMutable(array $opts, string $visibility): array  // guard 1
```

- The storage layer holds the per-visibility setting bindings (public →
  `cloud_storage_endpoint`/`cloud_storage_bucket`/`cloud_storage_enabled`; private →
  shared endpoint/`cloud_storage_private_bucket`/`cloud_storage_private_enabled`), so
  the lifecycle resolves them from a visibility string.
- `assertBindingMutable()` rejects a Save that changes `(endpoint, bucket)` for a
  store that has any `cloud` row (summed across that visibility's profiles): "This
  store holds N offloaded objects; pull them back to local before changing the
  bucket." Same binding ⇒ key rotation allowed.
- `testConnection` branches on visibility for the read-policy assertion; the
  PUT/HEAD/DELETE probe mechanics are shared.

`admin_cloud_storage_logic` becomes a thin caller that, per store present in the form,
dispatches to `CloudStorageLifecycle`. Its action set and redirects are unchanged; it
gains handling for the private bucket field.

## `FileStorageProfile` — the first (public) consumer

A thin adapter over the **existing** `File` methods — no File code moves:

- `visibility()` → `'public'`.
- `itemsForRow($id)` — the relocated item-building block from `_sync_row`: original +
  image variants (`ImageSizeRegistry::get_sizes()` when `is_image()`), filtered to what
  exists on disk.
- `reverseItemsForRow($id)` — the relocated key/placement block from `_pull_row`:
  `remote_key_for($size_key)` for original + variants, each mapped to its local
  destination (`is_public() ? fast_dir : restricted_dir`), computed from the row alone.
- `isEligibleRow($id)` → `(new File($id, true))->is_public()`.
- `eligibilityWhere()` → the existing permission-gate fragment, verbatim.
- column getters return `fil_*`; task classes return
  `CloudStorageSync` / `CloudStorageReverseSync`.

## Task classes become shims

`CloudStorageSync` / `CloudStorageReverseSync` keep their names, JSON, and scheduler
registration; their `run()` bodies collapse to:

```php
return CloudOffloadEngine::syncBatch(new FileStorageProfile());      // CloudStorageSync
return CloudOffloadEngine::reverseBatch(new FileStorageProfile());   // CloudStorageReverseSync
```

## What stays per-consumer (not unified here)

Request-time byte I/O is **not** this layer: File upload (`UploadHandler`/`File`) and
serving (URL / local read) are untouched; a private consumer's request-time gated read
is the consumer's code (it calls `forVisibility('private')->get()` and streams behind
its own permission check). What's unified is the **offload orchestration + admin
lifecycle + the store/visibility concept** — what was actually duplicated.

## Files

### To create
| File | Purpose |
|------|---------|
| `includes/cloud_storage/StorageProfile.php` | the seam interface |
| `includes/cloud_storage/StorageProfileRegistry.php` | reads `storage_profiles.json` + every plugin's `plugin.json` `storage_profiles` (on disk, active or not); instantiates and groups by `visibility()` |
| `storage_profiles.json` (at `public_html/` root) | core profile manifest; declares `FileStorageProfile` |
| `includes/cloud_storage/CloudOffloadEngine.php` | relocated forward/reverse batch + per-row logic, table-agnostic |
| `includes/cloud_storage/CloudStorageLifecycle.php` | relocated admin save/test/activate/health + the two guards + per-visibility bindings |
| `includes/cloud_storage/FileStorageProfile.php` | adapter over existing `File` methods (`visibility=public`) |
| `tests/integration/cloud_storage_characterization_test.php` | **written first, against unrefactored code** — pins current public-files offload/reverse/admin behavior; stays green unchanged through the refactor as the parity proof |
| `tests/integration/cloud_offload_engine_test.php` | engine forward/reverse parity (mock driver + mock profile); ordering; failure cap |
| `tests/integration/cloud_storage_guards_test.php` | binding-immutability + enable/disable mutual-exclusion |
| `tests/integration/cloud_private_store_test.php` | `forVisibility('private')` null until configured+gated; the privacy gate passes/fails correctly |

### To modify
| File | Change |
|------|--------|
| `includes/cloud_storage/CloudStorageDriverFactory.php` | add `forVisibility()` (public → `default()`; private → shared creds + `cloud_storage_private_bucket`, gated on `cloud_storage_private_enabled`); `reset()` clears both caches; bump `@version` |
| `tasks/CloudStorageSync.php` | body → `CloudOffloadEngine::syncBatch(new FileStorageProfile())`; bump `@version` |
| `tasks/CloudStorageReverseSync.php` | body → `CloudOffloadEngine::reverseBatch(new FileStorageProfile())`; bump `@version` |
| `adm/logic/admin_cloud_storage_logic.php` | call `CloudStorageLifecycle` per store; add private-bucket field handling (independent Save + privacy gate); bump `@version` |
| `adm/admin_cloud_storage.php` | add the `cloud_storage_private_bucket` field + its test/status display; bump `@version` |
| `settings.json` | declare `cloud_storage_private_bucket`, `cloud_storage_private_enabled` |
| `docs/cloud_storage.md` | document the engine/profile/lifecycle, visibility/stores, the privacy gate, the binding-immutability rule, and the **declarative profile registry** (`storage_profiles.json` + `plugin.json`); include the "add a new offload consumer" recipe (implement `StorageProfile`, declare it, pick a visibility) — all as the current design |
| `docs/plugin_developer_guide.md` | document the `storage_profiles` `plugin.json` key alongside the existing `settings`/menu declarations: how a plugin contributes a `StorageProfile`, that visibility comes from the profile, and the **drain-before-uninstall** rule for a plugin owning a `private` profile |

### No `fil_` schema, no driver-class internals, no File-model, no offload-logic changes.

## Migration / backward compatibility

- **No DB migration; no data migration.** `fil_` columns and their meaning are
  unchanged; NULL `fil_storage_driver` still reads as `local`.
- **Behavior parity** for public files: same offload/reverse/admin results. The only
  observable differences are the two bug-fixes (rename now rejected; enable no longer
  leaves reverse running) and the additive private-bucket field.
- The private store ships **inert** until an admin configures and gates a private
  bucket; nothing references it until spec B.

## Testing

- **Engine parity** — mock `StorageProfile` over a temp table + mock driver:
  `syncBatch` pushes eligible `local` rows, flips to `cloud`, deletes locals;
  missing-on-disk increments the counter and excludes after the cap; mid-flight
  ineligibility is undone; `reverseBatch` pulls back with inverse ordering.
- **Guard 1** — with ≥1 `cloud` row, a Save changing `bucket`/`endpoint` is rejected;
  same bucket + rotated key allowed; 0 `cloud` rows ⇒ change allowed. Verified for
  both the public and private stores.
- **Guard 2** — activating forward deactivates reverse and vice versa.
- **Private store** — `forVisibility('private')` is null with no bucket / before the
  gate; the gate fails on an anonymous-200 bucket (enabled stays false) and passes on
  an anonymous-denied bucket (latches enabled); a private failure doesn't block the
  public Save.
- **Public-path regression** — the `cloud_storage_characterization_test` (written
  first against the unrefactored code) passes **unchanged** against the shimmed tasks
  and the relocated lifecycle. This is the parity proof; there is no prior automated
  coverage, so this test is the net (see *Implementation sequence*).
- `php -l` + `validate_php_file.php` on every created/modified PHP file.

## Security

- Public-files posture unchanged (public bucket, URL serving, existing gates).
- The private store is reachable only server-side, behind the consumer's permission
  check, never via `url()` — and only after the **anonymous-read-denied** gate has
  proven the bucket private. Mail-grade privacy becomes a platform guarantee, written
  once.
- The binding-immutability guard is a data-integrity fix, not a permission change.
- Never log bucket credentials or private bytes; the engine/lifecycle deal in opaque
  settings and opaque objects.

## Documentation

Per the docs-in-specs rule, docs are updated to describe the end state — no
"previously"/"replaces" framing:

- **`docs/cloud_storage.md`** — the driver as the byte backend; `CloudOffloadEngine` +
  `CloudStorageLifecycle` as the one orchestration; visibility + the two stores; the
  privacy hard-gate; the binding-immutability rule; and the declarative
  `StorageProfileRegistry`. It carries the **"add a new offload consumer" recipe**:
  implement `StorageProfile` (including `reverseItemsForRow` for pull-back), declare the
  class in `storage_profiles.json` or a plugin's `plugin.json`, and choose a visibility
  — the bucket, read posture, privacy guarantee, and reverse-driver fallback all follow
  from it.
- **`docs/plugin_developer_guide.md`** — the `storage_profiles` `plugin.json` key as a
  plugin extension point next to `settings` and menus, plus the drain-before-uninstall
  rule for any plugin owning a `private` profile.

## Versioning

- `@version 1.0` on each new file; bump `@version` on each modified file.
- New core settings via `settings.json` (seeded automatically); no plugin version
  change.

## Out of scope / future

- **Inbound mail as the first private consumer.** Its profile
  (`visibility = private`), `iem_` descriptor, accessor, ingest manifest, remote
  driver, and `storage/` provisioning live in
  [inbound_raw_message_storage.md](inbound_raw_message_storage.md), rebased on this
  layer. It adds **no** bucket config, gate, or offload logic.
- **Other private consumers** (ID/KYC uploads, private exports, document vaults) — each
  is one `StorageProfile` with `visibility = private`, reusing the verified-private
  store. Not built here.
- **Presigned-URL reads for the private store.** Server-side streaming is sufficient;
  presigning is a future egress optimization, additive to the driver.
- **A separate provider/account for the private bucket** — out of scope; one bucket on
  the shared credentials.
- **Unifying request-time byte I/O** (upload/ingest/serve) — genuinely per-consumer.
- **Multi-bucket per profile** — each profile binds one store; the immutability guard
  depends on it.
