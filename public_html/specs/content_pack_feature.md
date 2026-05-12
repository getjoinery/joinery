---
# Content Pack Feature

## Problem

Joinery's "content" lives in two places: theme files (filesystem) and DB rows (settings, pages,
products, pricing tiers, navigation). This split means there is no lightweight way to snapshot,
share, or restore a specific site configuration. Preserving a site's look+content today requires
either a full DB backup (heavy, not portable) or manually reconstructing the DB state from scratch.

This is a recurring friction point when:
- Pivoting a deployment to a new audience (the original org-facing getjoinery.com content needs
  to be preserved when the site is rebuilt for developers)
- Standing up a new sister-brand deployment that should start from a known content baseline
- Sharing a "starter kit" with a new Joinery operator
- Demoing the platform with realistic content that looks like a real site

## Proposed Solution: Content Packs

A content pack is a maintenance artifact for moving DB content between sites as a file —
similar to a backup, but scoped to "the site's content" rather than "everything in the
DB." Packs are **not** a platform primitive: there is no in-DB tracking, no admin UI, no
auto-discovery in the running system, no registered pack location in the repo. They are
operator tools on the same plane as backup files.

### Pack format

A pack is a directory of JSON files, typically distributed as a zip:

```
mypack/
  pack.json          ← manifest: name, version, description, table selection,
                       FK pins
  settings.json      ← stg_settings rows
  pages.json         ← page/post content rows (if page system exists)
  products.json      ← products and pricing tiers
  navigation.json    ← nav items (if stored in DB)
```

Packs are files. They are not part of the application repo, not registered in the DB,
and have no fixed location on disk — operators store them wherever backups go or
wherever else is convenient.

### Pack specification

`pack.json` is the manifest — small, declarative, no embedded SQL. Three fields:

```json
{
  "name": "orgcontent",
  "version": "1.0.0",
  "description": "Org-facing content baseline",
  "tables": ["stg_settings", "pag_pages", "pro_products"],
  "settings_include": [
    "site_name",
    "tagline",
    "og_description",
    "hero_text"
  ],
  "fk_pins": {
    "pag_pages.pag_author_user_id": 1
  }
}
```

**`tables`** — list of tables to include in the pack. All non-soft-deleted rows are
exported from each named table. Tables not listed are excluded entirely.

**`settings_include`** — allowlist of `stg_name` values, required when `stg_settings`
appears in `tables`. Settings whose name isn't on this list are not exported.
`stg_settings` mixes credentials with marketing copy, so the operator must enumerate
what travels (see "Content scope").

**`fk_pins`** — map of `<table>.<column>` → literal target PK, for FK columns whose
target row isn't in the pack. The typical use is attaching pack content to the
install-pinned admin (`usr_user_id=1`), which is stable across all Joinery installs.

**Export-time enforcement.** The exporter walks every FK column on every included row.
A reference is either resolved to an `_export_id` (target is in the pack), pinned via
`fk_pins`, or — if the column is nullable — emitted as NULL. A non-nullable FK with no
in-pack target and no `fk_pin` fails export with a clear error:

```
ERROR: pag_pages.pag_author_user_id references rows not in pack:
  - pag_id=42 → usr_user_id=10
  - pag_id=87 → usr_user_id=10
Fix: include usr_users in `tables` (with the referenced rows) or add an entry to `fk_pins`.
```

A pack cannot be produced in a knowingly-broken state.

### Apply behavior

Built-in rules — nothing to configure per table:

- **`stg_settings`** — upserts by `stg_name`. Pack values overwrite target values when
  the name matches; new rows insert otherwise. This is how pack-shipped marketing copy
  replaces the factory defaults seeded by the install SQL.
- **All other tables** — hash-dedup, skip on match (see "Row identity and references").
  Rows whose content hash already exists in the target are skipped; everything else is
  inserted.
- **Soft-deleted rows** — excluded at export when the table has a `delete_time` column.
- **Generated columns** — excluded at export. PG-`GENERATED ALWAYS AS ... STORED`
  columns reject any non-DEFAULT INSERT value, so the exporter detects them via
  `information_schema.columns.is_generated` and omits them from the pack. The target
  recomputes via its own generation expression on insert. (Identity-on-PK columns are
  already covered by the auto-PK assignment rule.)

These rules cover the v1 use cases: install bootstrap (empty target) and site-to-site
copy (empty or wiped target). Applying onto a live target with overlapping unique
constraints will fail the transaction cleanly — handling that case needs the deferred
filter / identity-matching capabilities.

### Type encoding

Pack JSON is the canonical interchange format, so each PostgreSQL type maps to a defined
JSON encoding. The exporter and importer agree on:

- **Text, varchar, char** — JSON string. UTF-8 required end-to-end.
- **Integer, smallint, serial** — JSON number.
- **Bigint** — JSON number when within IEEE-754 safe-integer range (±2^53); otherwise
  JSON string. The importer parses either form.
- **Numeric, decimal** — JSON string, to preserve precision.
- **Boolean** — JSON `true` / `false`.
- **Timestamp, timestamptz** — JSON string in ISO-8601 UTC form (`YYYY-MM-DDTHH:MM:SSZ`).
- **Date, time** — JSON string in ISO-8601 form.
- **JSON, JSONB** — embedded as native JSON values, not escaped strings. The importer
  re-serializes for insertion.
- **Array types** — JSON arrays of the appropriate element encoding.
- **Bytea** — JSON string, base64-encoded.
- **NULL** — JSON `null` or key omitted; treated as equivalent everywhere (including in
  the content hash).

Types not in this list (geometric, range, enum, custom domains) are out of scope for v1 —
the exporter fails with a clear error if a row contains a column of an unsupported type,
so the operator knows to either skip the table or wait for type support.

### Row identity and references

Pack JSON cannot use source-DB primary keys directly — they are auto-increment integers tied
to the source database and meaningless on any other deployment. The format uses indirection
via export-time identifiers:

**Export.** Each row is assigned a random identifier (`_export_id`) at export time. FK
columns are encoded one of three ways: as the parent row's `_export_id` (target is in the
same pack), as a pin marker carrying the literal target PK from `fk_pins` (target is
external and pinned), or as NULL (target is external and the column is nullable with no
pin).

**Import.** Rows are inserted in dependency order. As each row is inserted, the importer
captures the new auto-PK assigned by the target DB and adds an entry to an
`{_export_id → target_pk}` map. For each FK column on a dependent row, `_export_id`
references are resolved through this map; pin markers are emitted as the pinned literal
PK; NULLs are emitted as NULL.

This keeps the pack format self-contained: no schema changes, no required natural-key columns
on underlying tables, works on any table the seeder supports.

**Duplicate prevention.** Before inserting a row, the importer hashes its content and
checks whether the target already has a row in the same table with an identical content
hash. If so, the existing row's PK is added to the export-id map and the insert is skipped.

The content hash is computed over a canonical serialization that:
- Excludes the primary key column.
- Excludes audit-timestamp columns the platform manages (`*_created_time`,
  `*_updated_time`, `*_delete_time`), since they fluctuate without semantic change.
- For FK columns, hashes the *resolved target PK*, not the `_export_id` string. The same
  function is applied to pack-side rows (after resolving FKs through the export-id map
  and `fk_pins`) and to target-side candidate rows. Without this, the two sides would
  never match.
- Treats `null` and an omitted key as equivalent.
- Canonicalizes `json` / `jsonb` column values before hashing — object keys sorted
  recursively, numbers in shortest decimal form, insignificant whitespace stripped.
  Without this, semantically equal JSON values can hash differently due to key-order
  or whitespace drift (especially for `json` columns, which preserve insertion order
  verbatim).

Dedup is per-target, not per-pack-history — the importer does not record "this hash came
from that pack." If a row was hand-edited in the target between applies, the next apply
sees a hash mismatch and inserts a parallel row. For v1, the operator's escape valve is
to wipe the target (or the affected table) before re-applying. Identity matching by
column for non-`stg_settings` tables is deferred.

Content-hash dedup does not track logical identity across edits: if the source renames a row
between exports, a target dedup'd by hash sees the new version as a new row alongside the old
one. This is an accepted limitation for hash-dedup tables — content packs do not promise
change tracking. Tables that need stable identity (e.g., `stg_settings`, whose `stg_name` is
unique and where pack-side values should overwrite target-side values) declare an
`identity_column` in their `tables` entry in `pack.json` — see "Pack specification." The
importer then matches by that column and applies `on_conflict` semantics on match. No model
or schema impact; just configuration in the pack manifest.

**Future enhancement: deterministic export IDs.** If stable identity across re-exports of the
same source ever becomes a requirement (e.g., to keep target-side FK references from non-pack
rows pointing at the same logical row through refreshes), the export ID can be derived
deterministically from `(source_site_id, table, source_pk)` instead of randomly. No schema
change required, just a different function at export time. Deferred until a use case demands it.

### Export and import

Export and import are CLI scripts in `utils/`, run by operators directly:

- `php utils/export_content_pack.php <output-path>` — dumps the current site's content
  tables into a pack file.
- `php utils/apply_content_pack.php <pack-path>` — applies a pack file to the current
  site, inserting rows in dependency order, resolving FKs through the export-id map, and
  skipping content-hash matches that already exist.

These two CLI scripts are the entire interface. No admin UI; no auto-discovery; no
"installed pack" registry. Operators run them by hand, the same way they run backup
and restore scripts.

**Soft-deleted rows.** When an included table has a `delete_time` column, the exporter
filters out rows where `delete_time IS NOT NULL`. Operators who want soft-deleted rows
in a pack should restore them in the source DB before exporting.

### Apply transactionality

The entire apply runs inside a single PostgreSQL transaction with FK constraints set
`DEFERRED`. Either the whole pack lands or none of it does.

Deferring FK constraints also handles **cyclic and self-referencing FKs**: a row pointing
at another row inserted later in the same pack (or at itself) is inserted with the FK
column populated normally; the constraint isn't checked until COMMIT. This covers
tree-shaped tables like `cat_categories.cat_parent_id` and mutual references between
rows in the same pack.

The single-transaction approach holds locks and buffers for the duration of the apply.
Acceptable for v1; chunked or resumable applies are deferred.

### Schema compatibility

A pack does not embed a Joinery version. At apply time, the importer compares pack columns
to the target schema:

- Column present in pack but missing in target → apply fails with a clear error naming
  the table and column. Operator either upgrades the target or removes the column from
  the pack.
- Column present in target but missing in pack → target's default value (or NULL) is
  used. This is how a pack survives forward upgrades that add new columns.
- Type mismatch on a shared column → apply fails. The importer does not coerce.

The schema itself is the compatibility contract — no version comparison logic. Newer
target with older pack works as long as new columns are nullable or have defaults; older
target with newer pack fails fast.

### Install-time application

The install pipeline splits seed data by where it's authored:

| Tier | Mechanism | Contents |
|---|---|---|
| Schema + system seed data | `joinery-install.sql.gz` (from `create_install_sql.php`, unchanged) | CREATE TABLE/sequence/index DDL, hardcoded `usr_users` INSERTs, `stg_settings` defaults from `settings.json`, `amu_admin_menus` from `admin_menus.json`, `mig_migrations` baseline. All of this has existing canonical sources (code or JSON files), so packs do not duplicate it. |
| Operator content (optional) | `install.pack.zip` | Products, tier definitions, page copy, custom navigation entries, and setting overrides for marketing copy or branding. Only shipped when a release needs to seed deployment-specific content. |

`_site_init.sh` applies these in order: restore the install SQL, then apply the install
pack if one is present in `maintenance_scripts/install_tools/`. A vanilla Joinery
release may ship no pack at all; a branded deployment (e.g., getjoinery.com pivot,
sister-brand site) ships a pack containing its content.

Setting overrides in a pack upsert by `stg_name` (built-in behavior for `stg_settings`)
to overwrite the defaults the install SQL just inserted — that's how marketing copy and
branding travel between sites without duplicating rows.

Once installed, the pack is not retained or tracked in the running system. It is an
install-time artifact, like the install SQL file itself.

### Flavored install packs (planned extension)

The pack format supports multiple install packs per release with no changes to the
format itself. Each flavor ships as its own zip in the release archive
(`install-vibecoding.pack.zip`, `install-nonprofit.pack.zip`, etc.). The install
pipeline picks which to apply via an env var read by `_site_init.sh`:

```
INSTALL_PACK=vibecoding ./install.sh
```

Unset means no pack (vanilla install). Documented in `install.sh --help` so operators
can see which flavors a release offers.

Two authoring approaches:
- **Per-flavor reference DB.** Maintain one Joinery instance per flavor;
  `publish_upgrade.php` runs against each in turn and ships the resulting packs
  together. Clean separation, more ops overhead.
- **Shared reference DB with per-flavor `pack.json`.** One ref site holds all flavors'
  content; each flavor's `pack.json` selects a different subset via `tables` and
  `settings_include`. Lighter ops, but each pack's config has to deliberately exclude
  the other flavors' content.

Plugin and theme activation are not in pack scope — flavors differ in content only
(pages, products, settings, navigation, tier definitions). If a flavor needs different
plugins active, that's set up per deployment outside the pack flow (admin UI,
deployment scripts) before applying the pack.

Code additions to make this usable: `_site_init.sh` reads the env var and resolves the
filename (~10 lines); `publish_upgrade.php` generates multiple packs per release when
multiple flavor configs are present (~30 lines). No pack-format changes.

### Content scope

What goes in a content pack vs. what stays deployment-specific:
- **In pack:** settings that describe the site's identity and copy (site_name, tagline,
  og_description, marketing copy settings, nav structure, tier features copy, product names/prices)
- **NOT in pack:** credentials, API keys, Mailgun config, Stripe keys, per-deployment
  secrets, user data, transactional data, uploaded files / asset content (images,
  attachments, theme uploads — packs move DB rows, not files; file sync is a separate
  operator concern, treated like backup restore), plugin and theme activation state
  (which extensions a site runs is deployment/operator configuration, not content —
  managed via admin UI or deployment scripts, not via packs)

`stg_settings` is where this matters most — it stores both marketing copy and
credentials, identified only by `stg_name`. The required `settings_include` allowlist in
`pack.json` forces the operator to enumerate which names ship. Settings not on the list
are excluded automatically; new credential keys added to the platform later can't sneak
into the next export.

### Deferred for v1

Capabilities considered and deferred to keep v1 lean. Each is reachable later without
breaking the v1 format:

- **Per-table row filters.** v1 exports all non-soft-deleted rows from listed tables.
  Operators curate the reference site (delete or soft-delete rows they don't want) rather
  than configuring SQL filters in `pack.json`. A declarative filter mechanism can be
  added later.
- **`fk_resolutions` with lookup SQL.** v1 has `fk_pins` (literal target PK) and the
  implicit NULL fallback. Dynamic resolution via operator-authored SQL was prototyped and
  pulled — it added attack surface (arbitrary SQL running against the target DB at apply
  time) without serving the v1 use cases, since both bootstrap and site-to-site copy land
  on the install-pinned admin at `usr_user_id=1`.
- **Identity matching on non-`stg_settings` tables.** v1 dedups by content hash for
  everything except `stg_settings` (which always upserts by `stg_name`). Per-table
  `identity_column` / `on_conflict` configuration was considered and pulled — for
  bootstrap and site-to-site onto an empty or wiped target, hash dedup is sufficient.
  Adding it back is a localized change to `pack.json` and the apply logic.
- **Composite primary keys and PK-less tables.** v1 only supports tables with a single
  auto-increment primary key. The exporter fails with a clear error on other shapes.
- **Unsupported column types.** Geometric, range, enum, and custom-domain columns are
  rejected at export. Add encodings as needed.
- **Prune-to-pack mode.** v1 apply is additive — rows in target but not in pack are left
  alone. No "make target exactly match pack" semantics.
- **Pack signing and integrity verification.** v1 packs are unsigned zips. With arbitrary
  SQL gone from `pack.json`, the attack surface is small (a tampered pack can insert
  weird data, not run code), but signing is a future option.
- **Dry-run mode.** v1 apply has no preview; the single transaction means failures roll
  back cleanly, but "show me what would change" is a v2 feature.
- **Cross-pack composition.** Each pack is self-contained. There's no way to say "this
  FK points at a row from pack X."
- **Upgrade-time pack application.** `install.pack.zip` runs only at fresh install via
  `_site_init.sh`. `upgrade.php` does not apply packs. Applying a pack to a running
  site needs operator-driven `apply_content_pack.php` so the operator can reason about
  overlap.
- **Replacing the install SQL data emission entirely.** The original direction was to
  move `stg_settings`/`amu_admin_menus`/`mig_migrations` seeding out of
  `create_install_sql.php` and into the install pack. Pulled back: those three have
  existing canonical sources (`settings.json`, `admin_menus.json`, the migrations
  directory), and routing them through a pack would create a second source of truth.
  Install SQL emission stays.

### Known limitations

v1 is built for install bootstrap and site-to-site copy where exactness on the target
isn't required. The limitations below are acceptable for those cases but should be
understood before reaching for content packs in adjacent scenarios (live-target overlay,
incremental sync, etc.). Cross-references to "Deferred for v1" mark items reachable
without breaking the pack format.

**Updates to existing content on a live target fail.** When a pack row has the same
natural key as a target row (e.g., same `pag_slug`) but different content, the importer
issues an INSERT, the unique constraint rejects it, and the transaction rolls back.
`stg_settings` is the exception — it always upserts by `stg_name`. For other tables,
operators wipe conflicting rows in target before re-applying, or accept that
already-present content stays untouched. *Fix path*: per-table `identity_column` +
`on_conflict` config (Deferred).

**Hash dedup is per-target, not per-pack-history.** The importer doesn't record "this
hash came from that pack." A row hand-edited on the target after an earlier apply sees
a hash mismatch on the next apply and creates a parallel row (or fails the unique
constraint, depending on the table). No fix planned — tracking pack apply history is a
larger architectural change.

**Text columns are copied byte-for-byte.** References embedded in TEXT/HTML
columns — a hardcoded link like `<a href="/user/10">`, an absolute path to
`/uploads/...`, an environment-specific URL — copy as-is. The pack format does not
parse or rewrite column contents, so deployment-specific references will keep pointing
at the source after apply. Files themselves are out of scope by design (see Content
scope); operators sync uploads separately.

**Sequence values diverge between source and target.** Auto-PKs assigned during apply
have no relationship to source PKs. External systems holding cached source PKs are not
migrated.

**Apply runs raw SQL, not application code paths.** The importer issues PDO INSERTs
directly. PostgreSQL-level features still fire — CHECK constraints, FK constraints
(deferred to commit), and any DB triggers on target tables (audit, NOTIFY,
denormalization, etc.). PHP-level application lifecycle does *not* fire — `prepare()`
validation, `SystemBase::save()`, plugin event hooks, post-purchase hooks, notification
senders, search-indexers, and cache invalidators are all bypassed. This is
*intentional* for bulk apply (no notification flood when seeding 200 pages) but means
anything downstream that depends on the PHP lifecycle running needs a manual post-apply
step (cache flush, search reindex, counter recalc). Two concrete instances worth
calling out separately:

- **Settings caches don't auto-invalidate.** `Globalvars` caches settings in-process. A
  mid-life apply that updates `stg_settings` won't be visible to a running PHP-FPM
  worker until the worker restarts or the cache is otherwise cleared.
- **DB triggers fire per-row, not per-batch.** If a target table has a trigger written
  for transactional inserts (one row at a time from the app), a pack apply with
  hundreds of rows fires it hundreds of times. Plugin-authored triggers especially —
  they may be unaware of pack apply as a bulk-insert path.

**Soft-deleted rows are excluded with no per-pack override.** Operators restore them in
the source before exporting if they need them in the pack. *Fix path*: per-table row
filter (Deferred).

**Schema match is strict.** A column present in pack but missing on target fails the
apply; a type mismatch on a shared column fails the apply. No coercion, no version
compatibility layer (see "Schema compatibility").

**Composite PKs, PK-less tables, and exotic column types are rejected at export.**
Geometric, range, enum, and custom-domain columns fail with a clear error. Operators
omit the table or wait for type support (Deferred).

**Apply is all-or-nothing.** The single-transaction model holds locks and buffers for
the duration of the apply. Large packs (tens of thousands of rows across many tables)
work but tax the target DB during the apply window. *Fix path*: chunked or resumable
applies (Deferred).

**Hash-dedup scans target candidates.** For each pack row inserted into a non-settings
table, the importer hashes existing target rows to look for a match. Cost is O(target
rows × pack rows) per table. Acceptable for the v1 use cases (target empty or small);
problematic for big-table overlays.

**No dry-run.** Operators see what an apply would do by running it inside a transaction
and rolling back, or by trial-and-error. *Fix path*: preview mode (Deferred).

**No prune-to-pack.** Rows in target but not in pack are left alone — apply is
additive only. *Fix path*: prune mode (Deferred).

**Unsigned packs.** No integrity verification at apply. With operator-authored SQL gone
from `pack.json`, a tampered pack's blast radius is limited to inserting weird data,
but pack distribution should still be treated like backup distribution. *Fix path*:
signing (Deferred).

**No upgrade-time pack apply.** `install.pack.zip` runs only at fresh install via
`_site_init.sh`. `upgrade.php` does not apply packs (Deferred).

**`pack.json` is hand-authored.** No schema validation tool, no admin UI, no
auto-suggestions. Errors surface at export or apply time with clear messages. Pack
authoring is operator workflow rather than a user-facing feature.

**Plugin and theme activation are out of pack scope.** Packs do not manage which
plugins or themes are installed/active on a deployment — that's operator/deployment
configuration (admin UI, deployment scripts). If a pack includes content rows from a
plugin-owned table, the plugin must be installed and its tables present on the target
before apply; otherwise apply fails under "schema match is strict." Activation itself
is never carried in a pack.

**Source DB curation is the operator's job.** With per-table filters deferred, what's
in the reference site is what's in the pack. Stale drafts, test data, or experimental
rows in the source will ship with the pack unless removed (or soft-deleted) first.

### Implementation surface

Two existing files gain new behavior. No model, schema, routing, or admin UI surface.
`utils/create_install_sql.php` is **unchanged** — it continues to render `settings.json`,
`admin_menus.json`, and the migrations baseline into install SQL as today.

- **`maintenance_scripts/install_tools/_site_init.sh`** — After the `psql` restore of
  `joinery-install.sql.gz`, check for an `install.pack.zip` in the same directory and
  invoke `apply_content_pack.php` against it if present. Skipped silently if the file
  isn't there (vanilla releases ship no pack).
- **`plugins/server_manager/includes/publish_upgrade.php`** — When a release author wants
  to ship a pack with the upgrade archive, the publish flow runs the new export script
  against the publishing site's reference DB to produce `install.pack.zip` and includes
  it. Vanilla releases skip this step.

New files: two CLI entry points (`utils/export_content_pack.php`,
`utils/apply_content_pack.php`) and one implementation class (`includes/ContentPack.php`)
with two static methods — `ContentPack::export($pack_spec_path, $output_zip_path)` and
`ContentPack::apply($pack_zip_path)`. The CLI scripts are thin wrappers; internal helpers
stay private to the class.

The reference DB on the publishing site needs operator discipline to stay clean enough
to export from. With per-table filters deferred in v1, what's in the reference site is
what's in the pack — curate carefully.

## Open Questions

1. **Versioning** — pack format version vs. Joinery version compatibility. Should `pack.json`
   declare a minimum Joinery version?

2. **Plugin content** — plugins can declare their own settings. Should plugin-owned settings be
   exportable in a pack, or is that the plugin's responsibility?

3. **Page/post system** — the current platform has minimal CMS-style page storage. Content packs
   may be more valuable once that system is more developed.

4. **Hosted starter kits** — longer term, could getjoinery.com list downloadable starter packs
   (org starter, developer framework starter, etc.)? This feature would enable that.

## Immediate Use Case (trigger for this spec)

getjoinery.com is being pivoted from org-facing to developer-facing. The current org-focused
theme + content needs to be preserved. Short-term: spin up `orgs.getjoinery.com` as a sister
deployment before the pivot (see sister_brand_deployment.md pattern). Longer-term: the content
pack feature would let the org site be exported as a reusable starter kit.

## Related Specs

- `specs/implemented/multiple_domain_capability.md` — sister-brand deployment pattern
- `specs/sister_brand_deployment.md` — NetworkSentry deployment runbook
