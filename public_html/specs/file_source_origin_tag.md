# File origin tag (`fil_source`) — generic file categorization

**Status:** Draft — design in progress
**Layer:** core file model — `data/files_class.php` (`File` + `MultiFile`), the two
core file-creation sites, and the core files browser (`adm/admin_files.php`)
**Depends on:** nothing — this is a foundational file-layer capability.
**Consumed by:** `specs/inbound_email_attachment_storage.md` (so the files browser can
separate — or exclude — email attachments without the core browser knowing the
inbound-email tables exist). Likely future consumers: any subsystem that wants its
files distinguishable in the browser (reports, imports, generated exports).

## The problem

Every uploaded file lands in one flat `File` table. The admin files browser
(`/admin/admin_files`) lists them together with no way to tell *where a file came
from* — an admin document upload, a user avatar, and (soon) an inbound-email
attachment are indistinguishable. Once inbound-email attachments start creating `File`
rows, the browser will fill with machine-received files mixed in with deliberately
uploaded ones, and there is no clean way to hide or focus them.

The obvious per-case fixes are both wrong. Making `File` join to the inbound-email
tables couples core to a plugin. Making `File` grow email-specific columns breaks the
rule that `File` must not know what an email is. And there is already a **half-wired
seam** for exactly this: `MultiFile::getMultiResults()` filters on a `fil_source`
column (via a `source` option) — but that column was never added to the schema, so the
filter is dead code that throws `column fil_source does not exist` the moment anything
uses it.

## In plain terms

Give every file a small neutral tag saying **where it came from** — a general upload, a
profile/entity photo, an email attachment — set by whatever subsystem created the file.
The files browser can then filter on that tag: hide email attachments to keep the list
clean, or focus on one origin. `File` never learns what any of those origins *mean*; it
just stores an opaque label the creator stamps.

## The model

- **`File` gains one column: `fil_source`** — a short, self-describing string key
  (e.g. `user_upload`, `entity_photo`, `email_attachment`). Nullable; `NULL` means
  "unspecified / legacy."
- **The key is opaque to `File`.** `File` stores and filters on the string but attaches
  no behavior to any value. This is the whole reason it does not couple core to email:
  `email_attachment` is just text the inbound-email code chose to write.
- **Core sources are named constants; plugins own their own keys.** `File` defines
  constants for the sources core creates (`File::SOURCE_USER_UPLOAD`,
  `File::SOURCE_ENTITY_PHOTO`). A plugin passes its **own** string (the inbound-email
  plugin passes `email_attachment`) — it does not need a constant in core. This mirrors
  the existing string-key pattern used for email providers and plugins (`getKey()`).
- **The stubbed filter is finished, not deleted.** `MultiFile`'s `source` option stays,
  now backed by a real column, with a **string** bind (the stub's `PDO::PARAM_INT` was a
  guess and is corrected to `PDO::PARAM_STR`).

## What already exists (and is reused)

- **`File` / `MultiFile`** (`data/files_class.php`) — Active Record model + collection.
  The collection already has the `source` filter branch; it just needs a real column
  behind it.
- **The files browser** (`adm/admin_files.php`) — already drives its list through one
  `MultiFile` call plus a `filter` dropdown (`All files` / `Files only` /
  `Images only`). A source scope is a natural addition to that same dropdown.
- **The two core file-creation sites** — `admin_file_upload_process_logic.php` (general
  uploads) and `ajax/entity_photos_ajax.php` (entity photos). Each already does
  `new File(NULL); ->set(...); ->save()`; each gains one `->set('fil_source', …)`.

## What to build

### 1. `fil_source` column on `File`

Add to `$field_specifications`:

```php
'fil_source' => array('type'=>'varchar(64)', 'is_nullable'=>true),
```

Add core source constants to `File`:

```php
const SOURCE_USER_UPLOAD  = 'user_upload';   // deliberate admin/user file upload
const SOURCE_ENTITY_PHOTO = 'entity_photo';  // avatar / event / location / gallery photo
```

`update_database` creates the column automatically (no migration — schema is derived
from the data class). Existing rows get `NULL`.

### 2. Finish the `MultiFile` `source` filter

The `source` branch stays, corrected to a string bind:

```php
if (isset($this->options['source'])) {
    $filters['fil_source'] = [$this->options['source'], PDO::PARAM_STR];
}
```

(If the standalone dead-code removal already landed, this re-adds the branch as a real,
column-backed filter.) Optionally add a `source_not` option for the "everything except
X" case the browser's exclude scope needs:

```php
if (isset($this->options['source_not'])) {
    $filters['fil_source'] = "!= " . $dblink->quote($this->options['source_not']) .
                             " OR fil_source IS NULL";  // via the split-parenthesis form
}
```

(Exact expression to match the Multi-class filter conventions in CLAUDE.md — parameter
array, string condition, or split-parenthesis OR. `NULL` files must survive an exclude.)

### 3. Stamp source at the creation sites

| Site | Source value |
|---|---|
| `admin_file_upload_process_logic.php` (general upload) | `File::SOURCE_USER_UPLOAD` |
| `ajax/entity_photos_ajax.php` (entity photos) | `File::SOURCE_ENTITY_PHOTO` |
| inbound-email attachment creation (its own spec) | `email_attachment` (plugin-owned key) |

The finer entity distinction (avatar vs event vs location) already lives in
`eph_entity_photos.entity_type` — `File` stays at the coarse `entity_photo` grain on
purpose.

### 4. Files browser source scope

Extend the existing `filter` dropdown in `adm/admin_files.php` with source-based scopes.
Minimum viable: an **Exclude email attachments** option (the declutter case) that sets
`source_not => 'email_attachment'`. A focused **Only email attachments** view belongs in
the **inbound_email plugin's own admin**, not core — that keeps the email-specific query
inside the plugin while core only needs to declutter.

## Up-front integration inventory

Every place that creates a `File` today, and every place that lists them:

| Site | Change |
|---|---|
| `File::$field_specifications` | add `fil_source varchar(64)` nullable |
| `File` constants | add `SOURCE_USER_UPLOAD`, `SOURCE_ENTITY_PHOTO` |
| `MultiFile::getMultiResults()` | column-backed `source` filter (+ optional `source_not`) |
| `admin_file_upload_process_logic.php` | stamp `SOURCE_USER_UPLOAD` on create |
| `ajax/entity_photos_ajax.php` | stamp `SOURCE_ENTITY_PHOTO` on create |
| `adm/admin_files.php` | add source scope(s) to the `filter` dropdown |
| inbound-email attachment create (separate spec) | stamp `email_attachment` |

There are exactly **two** core creation sites today (verified by `new File(NULL)`);
everything else in the codebase loads an existing `File`. New creation sites added later
are expected to stamp a source.

## What does NOT change

- **Disk layout.** No subdirectories, no path changes; files stay in the flat
  public/private stores. `fil_source` is a database attribute only.
- **Access model.** `fil_source` is not a permission gate — it does not touch
  `is_viewable()`, `fil_private`, groups, tiers, or events. It only categorizes.
- **Existing files.** Legacy rows keep `fil_source = NULL` and appear in every unfiltered
  view exactly as today. Exclude filters must treat `NULL` as "not that source" so
  legacy files are never hidden.
- **The send/serve paths.** Nothing about how files are stored, offloaded, or served
  changes.

## Security

- **No new exposure.** A source tag is descriptive metadata; it grants and restricts
  nothing. Access continues to flow entirely through `is_viewable()`.
- **Opaque, low-trust value.** `fil_source` is a short internal label written by trusted
  server code at creation time, never user-supplied free text. Treated as a plain
  string; bound as a parameter like every other filter.

## Pre-launch / migration

Pure additive column with a nullable default. No data migration — pre-launch, and legacy
`NULL` is a valid "unspecified" state. Backfilling old rows to a source is optional and
can be a one-off data migration later if ever wanted; it is not required for correctness.

## Out of scope

- **Backfilling existing files** to a source value — not needed; `NULL` is valid.
- **Source as an access control** — deliberately excluded; categorization only.
- **A general file "folders"/collections UI** — this is a single origin tag, not a
  user-managed foldering system.
- **Per-entity-type sources** (avatar vs event photo) — the coarse `entity_photo` grain
  is intentional; finer detail already lives in `eph_entity_photos`.

## Implementation outline (provisional)

1. Add `fil_source` to `$field_specifications` + the two `SOURCE_*` constants; run
   `update_database` to create the column.
2. Column-back the `MultiFile` `source` filter (string bind) and add `source_not`.
3. Stamp source at the two core creation sites.
4. Add the source scope(s) to the `admin_files` filter dropdown.
5. `php -l` + `validate_php_file.php` on every modified file.
6. Test: a created file carries its source; `MultiFile` filters by source and excludes
   by source while keeping `NULL` files; the browser scope hides email attachments.

## Docs

On implementation, add a short "File origin (`fil_source`)" note to the file/photo docs:
the available source keys, that plugins pass their own key, and that it is a
categorization tag with no bearing on access.
