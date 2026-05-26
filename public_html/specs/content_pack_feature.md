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
what travels (see "Content scope"). A name listed in `settings_include` that doesn't
exist in the source's `stg_settings` fails export with a clear error naming the missing
key — silently shipping a partial allowlist is the worse failure mode than aborting,
since the typical cause is a typo or a renamed setting.

**`fk_pins`** — map of `<table>.<column>` → literal target PK, for FK columns whose
target row isn't in the pack. The typical use is attaching pack content to the
install-pinned admin (`usr_user_id=1`), which is stable across all Joinery installs.
At apply time, the importer verifies each pinned row exists on the target before any
inserts run; a missing pin target aborts the apply with a clear error rather than
producing FK violations row-by-row.

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

- **Hash-dedup, skip on match.** If a pack row's content hash matches an existing
  target row's hash, the importer skips the insert and maps the row's `_export_id` to
  that target row's PK. See "Row identity and references."
- **Unique-constraint overwrite.** If the INSERT hits a unique-constraint violation,
  the importer rolls back to a per-row savepoint, parses the violated constraint name
  from the PG error, and UPDATEs the conflicting target row with the pack row's
  content (every column the pack carries — PK, audit, and generated columns are
  already excluded at export, so they don't appear in the SET clause). The target
  row's PK is preserved, so target-side FK references that point at it (comments,
  attachments, likes, etc.) survive the apply. The pack row's `_export_id` maps to the
  preserved target PK.
- **`stg_settings`** is a specific instance of the overwrite rule, since `stg_name` is
  its only meaningful unique constraint. Pack-shipped marketing copy overwrites the
  factory defaults the install SQL just inserted.
- **Soft-deleted rows** — excluded at export when the table has a `delete_time` column.
- **Generated columns** — excluded at export. PG-`GENERATED ALWAYS AS ... STORED`
  columns reject any non-DEFAULT INSERT value, so the exporter detects them via
  `information_schema.columns.is_generated` and omits them from the pack. The target
  recomputes via its own generation expression on insert. (Identity-on-PK columns are
  already covered by the auto-PK assignment rule.)
- **Audit columns** — `*_created_time`, `*_updated_time`, and `*_delete_time` are
  excluded at export. The target's column defaults populate them at INSERT
  (`created_time = NOW()`, `updated_time = NOW()`), and the UPDATE path naturally has
  nothing to set. Source-side audit history is not preserved — audit timestamps on the
  target reflect when the apply happened, not when the source row was originally
  written. Audit metadata is operational state, not content.

**Tables with multiple unique constraints.** If a pack INSERT collides on one unique
constraint and the resulting UPDATE collides on a *different* constraint (against a
different target row), the secondary violation bubbles up and aborts the apply. Rare
for content tables — most have a single natural-key constraint — but worth knowing if
it happens.

These rules cover both v1 use cases: install bootstrap (empty target, INSERT path
always succeeds, overwrite never fires) and prod→dev content pulls (overlapping rows
overwrite in place, preserving target-side FK references).

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

**Row JSON format.** Each row in a pack's table file is a JSON object keyed by column
name. The row carries its own `_export_id` (a string assigned at export time) as a
top-level key. FK columns use type-tagged objects so reference semantics are
unambiguous; all other columns hold their values directly per "Type encoding" below.

```json
{
  "_export_id": "abc123",
  "pag_slug": "about-us",
  "pag_title": "About Us",
  "pag_body": "<p>...</p>",
  "pag_author_user_id": {"_ref": "xyz789"},
  "pag_category_id": {"_pin": 1},
  "pag_template_id": null
}
```

The four FK-column cases:

- `{"_ref": "<export_id>"}` — link to another row in the same pack; resolved via the
  export-id map at apply time.
- `{"_pin": <literal_pk>}` — link to an external row whose PK is fixed by `fk_pins`.
- `null` — link is empty (column is nullable, target is external, no pin).
- Any other value is rejected — FK columns may only carry one of the three forms above.

Non-FK columns hold their values directly (string, number, boolean, JSON, etc.) per
"Type encoding." Type-tagged objects keep link semantics structurally distinct from
content, so a content column may hold any string — including ones that look like
`"_ref:..."` or `"_pin:..."` — with no collision risk.

This keeps the pack format self-contained: no schema changes, no required natural-key columns
on underlying tables, works on any table the seeder supports.

**Duplicate prevention.** Before inserting a row, the importer hashes its content and
checks whether the target already has a row in the same table with an identical content
hash. If so, the existing row's PK is added to the export-id map and the insert is skipped.

Dedup serves two distinct roles. For tables with a unique constraint (most content
tables — `pag_slug`, `pro_sku`, `stg_name`, etc.), it's an *optimization*: without it, a
byte-identical INSERT would just hit the unique constraint and trigger a redundant
UPDATE via the overwrite path. For tables without any unique constraint other than PK,
it's a *correctness requirement*: without it, every re-apply creates fresh duplicates
of every row. Both roles are needed in v1 — dedup is not a feature that can be quietly
dropped once overwrite is in place.

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

Dedup and overwrite are both keyed on the target's *current* state — neither pack-apply
history nor source-side row identity is tracked. Two consequences worth knowing:

- **Target hand-edits are clobbered on re-apply.** If a target row was hand-edited
  between applies, the next apply sees a hash mismatch, INSERTs, hits the unique
  constraint, and overwrites the row with pack content. The hand-edit is lost.
  Operators who want to preserve local changes should treat pack-managed rows as
  read-only in the target.
- **Source renames produce parallel rows.** If a row's natural key changes between
  source exports (e.g., a slug renamed from "about" to "about-us"), the new export
  looks like a brand-new row to the target — the INSERT succeeds against the new key,
  and the target ends up with both old and new versions. Stable identity across source
  edits would require deterministic export IDs (see "Future enhancement" below).

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

**Apply output.** On success, the apply CLI prints a per-table summary showing
inserted / overwritten / skipped counts:

```
Applied orgcontent.pack.zip
  stg_settings:  3 inserted, 2 overwritten, 4 skipped (identical)
  pag_pages:    12 inserted, 1 overwritten, 0 skipped
  pro_products:  4 inserted, 0 overwritten, 0 skipped
```

If any overwrites happened, a WARNING line follows the summary noting that pre-existing
target rows with matching unique keys were replaced. Overwrite is potentially
destructive on a live target (clobbers local edits on rows the pack also touches), so
the operator gets explicit acknowledgement that it occurred.

`--verbose` lists the specific rows overwritten — e.g., `pag_pages: overwrote
pag_slug='about-us' (id=12)` — so the operator can audit changes and, if needed,
recover from a pre-apply backup.

**Error reporting.** PG errors raised during apply are caught and re-raised with
context: table name, the row's `_export_id`, the operation stage (INSERT or UPDATE),
and the violated constraint name if applicable. A raw PG error without context forces
the operator to dig through logs to map "constraint violation" back to "which row in
which table" — the wrapper does that mapping at the source.

**Soft-deleted rows.** When an included table has a `delete_time` column, the exporter
filters out rows where `delete_time IS NOT NULL`. Operators who want soft-deleted rows
in a pack should restore them in the source DB before exporting.

### Apply transactionality

The entire apply runs inside a single PostgreSQL transaction with FK constraints set
`DEFERRED`. Either the whole pack lands or none of it does.

Within the outer transaction, each row's INSERT is wrapped in a `SAVEPOINT` so a
unique-constraint violation can be recovered from (rolled back to the savepoint, then
UPDATEd in place per the overwrite rule). Any other error during row processing
escapes the savepoint and rolls back the whole apply.

Deferring FK constraints handles **self-referencing trees with NULL at the root** —
tables like `cat_categories.cat_parent_id` where the root row has no parent. Roots
insert first, children insert in dependency order, and the per-row FK check is
postponed until COMMIT.

Dependency order is computed at apply time by topologically sorting the included
tables on their FK relationships in the target schema. Within a table that has a
self-referencing FK, rows are further sorted parent-first.

Genuine reference loops between rows (row A links to row B, B links back to A) are
not supported — see Known limitations.

The single-transaction approach holds locks and buffers for the duration of the apply.
Acceptable for v1; chunked or resumable applies are deferred.

### Schema compatibility

A pack does not embed a Joinery version. At apply time, the importer compares pack columns
to the target schema:

- Table listed in pack but missing on target → apply fails with a clear error naming
  the table. Typical cause: a plugin-owned table whose plugin hasn't been installed on
  the target (see "Plugin and theme activation are out of pack scope" in Known
  limitations).
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

Setting overrides in a pack overwrite the defaults the install SQL just inserted via
the general unique-constraint overwrite path (`stg_name` is the unique constraint that
fires). That's how marketing copy and branding travel between sites without duplicating
rows.

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

**Pack apply history is not tracked.** The importer doesn't record "this hash came
from that pack." A target row hand-edited after an earlier apply is treated like any
other target row on re-apply — hash dedup may match (no-op) or the unique-constraint
overwrite path may fire (clobbering the hand-edit). No fix planned — tracking pack
apply history is a larger architectural change.

**Text columns are copied byte-for-byte.** References embedded in TEXT/HTML
columns — a hardcoded link like `<a href="/user/10">`, an absolute path to
`/uploads/...`, an environment-specific URL — copy as-is. The pack format does not
parse or rewrite column contents, so deployment-specific references will keep pointing
at the source after apply. Files themselves are out of scope by design (see Content
scope); operators sync uploads separately.

**`json`-typed columns are canonicalized on apply.** PostgreSQL's `json` type
preserves insertion order and whitespace; the importer re-serializes from canonical
form, so the target ends up storing the canonical version rather than the source's
original byte sequence. Required for reliable hash-dedup; `jsonb` is unaffected
(PG normalizes server-side). The semantic value is preserved either way.

**Sequence values diverge between source and target.** Auto-PKs assigned during apply
have no relationship to source PKs. External systems holding cached source PKs are not
migrated.

**Apply runs raw SQL, not application code paths.** The importer issues PDO INSERTs
directly. PostgreSQL-level features still fire — CHECK constraints, FK constraints
(deferred to commit), and any DB triggers on target tables (audit, NOTIFY,
denormalization, etc.). PHP-level application lifecycle does *not* fire — `prepare()`
validation, `SystemBase::save()`, plugin event hooks, post-purchase hooks, notification
senders, search-indexers, and cache invalidators are all bypassed. This is
*intentional* for bulk apply (no notification flood when seeding 200 pages), with two
concrete consequences worth calling out:

- **Settings caches don't auto-invalidate.** `Globalvars` caches settings in-process. A
  mid-life apply that updates `stg_settings` won't be visible to a running PHP-FPM
  worker until the worker restarts or the cache is otherwise cleared. After any apply
  that touches `stg_settings`, reload PHP-FPM (`systemctl reload php8.x-fpm`).
- **DB triggers fire per-row, not per-batch.** If a target table has a trigger written
  for transactional inserts (one row at a time from the app), a pack apply with
  hundreds of rows fires it hundreds of times. Plugin-authored triggers especially —
  they may be unaware of pack apply as a bulk-insert path.

Operators applying a pack to a live (post-install) site should treat denormalized
counters, search indexes, and similar materialized state as potentially stale until
the platform-specific recalc paths are run. None exist in core today — this is a
forward-looking note for plugins that maintain such state.

**Soft-deleted rows are excluded with no per-pack override.** Operators restore them in
the source before exporting if they need them in the pack. *Fix path*: per-table row
filter (Deferred).

**Schema match is strict.** A column present in pack but missing on target fails the
apply; a type mismatch on a shared column fails the apply. No coercion, no version
compatibility layer (see "Schema compatibility").

**Composite PKs, PK-less tables, and exotic column types are rejected at export.**
Geometric, range, enum, and custom-domain columns fail with a clear error. Operators
omit the table or wait for type support (Deferred).

**Reference loops between pack rows are not supported.** The importer inserts rows in
dependency order and resolves links through the export-id map as it goes, so a row
needs its target to be inserted first. Self-referencing trees with empty roots work;
genuine loops (A → B → A) do not. Out of scope, not deferred — loops are a schema
shape Joinery's content tables don't have, and would be a schema-level fix if they
ever appeared.

**Apply is all-or-nothing.** The single-transaction model holds locks and buffers for
the duration of the apply. Large packs (tens of thousands of rows across many tables)
work but tax the target DB during the apply window. *Fix path*: chunked or resumable
applies (Deferred).

**Hash-dedup adds an upfront scan per table.** Target rows are hashed once into a
PHP-side set when the importer enters a table; each pack row is then looked up in O(1).
Total cost is O(target rows + pack rows) per table — not O(target rows × pack rows). For
prod→dev pulls onto a target with a few thousand rows in some content table, this is the
difference between "fast" and "noticeable wait." Tables with very large row counts still
pay the upfront hashing cost; if that ever becomes prohibitive, a stored-hash column with
an index would be the fix.

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
