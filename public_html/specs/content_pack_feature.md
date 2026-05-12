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
                       FK resolution strategies
  settings.json      ← stg_settings rows
  pages.json         ← page/post content rows (if page system exists)
  products.json      ← products and pricing tiers
  navigation.json    ← nav items (if stored in DB)
```

Packs are files. They are not part of the application repo, not registered in the DB,
and have no fixed location on disk — operators store them wherever backups go or
wherever else is convenient.

### Pack specification

`pack.json` is the single manifest file consumed by both export and import. The operator
authors it; the export script uses it to decide what to dump; the import script uses it
to resolve FKs at apply time. Example:

```json
{
  "name": "orgcontent",
  "version": "1.0.0",
  "description": "Org-facing content baseline",
  "tables": {
    "stg_settings": { "filter": "stg_name NOT LIKE 'mailgun_%'" },
    "pag_pages": {},
    "pro_products": {}
  },
  "fk_resolutions": {
    "pag_pages.pag_author_user_id": {
      "strategy": "lookup",
      "sql": "SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 LIMIT 1"
    }
  }
}
```

**`tables`** — which tables are included and how to filter rows within each. Optional
`filter` accepts a SQL WHERE/ORDER/LIMIT clause. Unlisted tables are excluded entirely.

**`fk_resolutions`** — strategy for FK columns whose target row falls outside the pack:
- `lookup` — run the declared SQL against the target DB at import time to find a
  substitute PK (e.g., "first user with permission ≥ 10", or `SELECT 1` for a row whose
  PK is known stable across installs)
- `null` — emit NULL (only valid if the column is nullable)

**Export-time enforcement.** The exporter walks every FK column on every included row.
For each reference, it either emits an `_export_id` (target is in the pack) or applies
an `fk_resolutions` strategy (target is external). If a dangling FK has no resolution
and the column is non-nullable, export fails with a clear error naming the offending
source rows. A pack cannot be produced in a knowingly-broken state.

```
ERROR: pag_pages.pag_author_user_id references rows not in pack:
  - pag_id=42 → usr_user_id=10
  - pag_id=87 → usr_user_id=10
Fix: broaden the usr_users filter or declare an fk_resolution for the column.
```

### Row identity and references

Pack JSON cannot use source-DB primary keys directly — they are auto-increment integers tied
to the source database and meaningless on any other deployment. The format uses indirection
via export-time identifiers:

**Export.** Each row is assigned a random identifier (`_export_id`) at export time. FK
columns are encoded one of two ways: as the parent row's `_export_id` (target is in the
same pack) or as a resolution marker that the importer evaluates against the target DB
(target is external and declared in `fk_resolutions`).

**Import.** Rows are inserted in dependency order. As each row is inserted, the importer
captures the new auto-PK assigned by the target DB and adds an entry to an
`{_export_id → target_pk}` map. For each FK column on a dependent row: `_export_id`
references are resolved through this map; resolution markers are evaluated against the
target DB to find a substitute PK.

This keeps the pack format self-contained: no schema changes, no required natural-key columns
on underlying tables, works on any table the seeder supports.

**Duplicate prevention.** Before inserting a row, the importer hashes its content (non-PK
column values) and checks whether the target already has a row in the same table with an
identical content hash. If so, the existing row's PK is added to the export-id map and the
insert is skipped. Re-importing an unchanged pack is a no-op; re-importing a slightly-changed
pack inserts only the changed rows.

Content-hash dedup does not track logical identity across edits: if the source renames a row
between exports, the target sees the new version as a new row alongside the old one. This is
an accepted limitation — content packs do not promise change tracking. If a particular table
produces nuisance duplicates in practice, the per-table seeder may override the dedup strategy
by declaring an identity column to match on instead (e.g., match `tbl_group` rows by
`grp_name`). This is seeder-level configuration only, with no model or schema impact.

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

### Install-time application

The install pipeline splits seed data into two tiers:

| Tier | Mechanism | Contents |
|---|---|---|
| Schema + PK-pinned rows | `joinery-install.sql.gz` (from `create_install_sql.php`) | CREATE TABLE/sequence/index DDL plus rows whose PKs are hardcoded in application code (bootstrap admin user, etc.) |
| Everything else | `install.pack.zip` (produced at release time by running the standard export against a reference DB) | `stg_settings`, `amu_admin_menus`, `mig_migrations` baseline, products, tier definitions, page copy, navigation, marketing settings |

`_site_init.sh` applies these in order: restore the install SQL, then apply the install
pack. By the time the pack runs, every PK-pinned row already exists, so its FK
references (resolved via `fk_resolutions` lookup queries like `SELECT 1`) succeed.

This replaces the current practice of hand-writing INSERT blocks into
`create_install_sql.php` for everything beyond CREATE TABLE statements. The install SQL
shrinks to schema + the small PK-pinned subset (today, the three default admin user
INSERTs and the sequence-reset for `usr_users`). Everything else moves to the install
pack and can be regenerated from a reference site at release time without editing SQL.

Once installed, the pack is not retained or tracked in the running system. It is an
install-time artifact, like the install SQL file itself.

### Content scope

What goes in a content pack vs. what stays deployment-specific:
- **In pack:** settings that describe the site's identity and copy (site_name, tagline,
  og_description, marketing copy settings, nav structure, tier features copy, product names/prices)
- **NOT in pack:** credentials, API keys, Mailgun config, Stripe keys, per-deployment secrets,
  user data, transactional data

### Deferred for v1

Three capabilities were considered and deferred to keep v1 lean. Each is reachable later
without breaking the v1 format:

- **`omit_row` FK strategy.** Would drop a source row from the pack if its FK can't be
  resolved. v1 operators broaden the filter or use `null` instead.
- **`pinned_external_pks` literal-PK encoding.** Would let FK columns pointing at
  install-stable PKs (e.g., the bootstrap admin) be written as literal integers in pack
  JSON instead of going through an `fk_resolutions` lookup. v1 uses lookup queries like
  `SELECT 1` for those references — slightly more import-time work, but no third FK
  encoding to learn.
- **Bootstrap/content pack split.** Would separate release-generated platform defaults
  from operator-authored deployment identity into two pack files. v1 ships a single
  install pack containing both. Revisit if managing one file becomes clumsy.

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
