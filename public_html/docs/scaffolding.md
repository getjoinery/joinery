# Scaffolding / Code Generator

Standing up a new CRUD resource means writing a data class, its Multi collection, public logic + view, and admin list + edit pages — mostly identical boilerplate. The scaffolding generator takes one declarative JSON manifest and emits that whole file set, following the platform's existing patterns so the output is indistinguishable from hand-written code. You then edit only the parts that carry real decisions.

```bash
php utils/scaffold.php <manifest.json> [--force] [--dry-run]
```

The generator is **creation-only**: it writes new files and never edits an existing one (including `serve.php`). Re-running does not re-sync or merge — after generation the files are yours.

---

## What gets generated

For an entity `Product` (prefix `prd`, plural `products`), the full set is:

```
data/product_class.php                  # data        — Product + MultiProduct
logic/products_logic.php                # public_list — list logic
views/products.php                      # public_list — public list view
logic/product_edit_logic.php            # public_edit — create/edit logic + descriptor
views/product_edit.php                  # public_edit — public edit view
adm/logic/admin_products_logic.php      # admin_list  — admin list logic
adm/admin_products.php                  # admin_list  — admin list view
adm/logic/admin_product_edit_logic.php  # admin_edit  — admin edit logic + descriptor
adm/admin_product_edit.php              # admin_edit  — admin edit view
```

Singular names derive from `entity` (`product_edit.php`, the `Product` class); plural names derive from the required `plural` slug (`products.php`, the `prd_products` table, the `/products` and `/admin/admin_products` URLs). No pluralization is inferred — `plural` is the single source for every plural-derived name.

No `serve.php` edits are needed: public views resolve by auto-discovery, admin pages by the `/admin/*` catch-all. A route that needs a `serve.php` entry (URL placeholder, feature flag, permission gate) is added by hand.

When `into:` targets a plugin, every path is rooted at `plugins/<plugin>/` and the entity is registered for the plugin's normal sync.

### Selecting what gets generated — `surfaces:`

The selectable unit is a **page** — a logic+view pair — never a single file. Five tokens:

| Token | Emits |
|---|---|
| `data` | the data class (`Product` + `MultiProduct`) |
| `public_list` | `logic/products_logic.php` + `views/products.php` |
| `public_edit` | `logic/product_edit_logic.php` + `views/product_edit.php` |
| `admin_list` | `adm/admin_products.php` + its logic |
| `admin_edit` | `adm/admin_product_edit.php` + its logic |

`public` and `admin` are aliases: `public` = `[public_list, public_edit]`, `admin` = `[admin_list, admin_edit]`. The default is `["public", "admin"]` (all five). `data` is emitted automatically whenever any page token is present.

- **Model-only** (an entity with bespoke presentation, e.g. a calendar): `"surfaces": ["data"]` — just the data class; you write the views.
- **Read-only catalog**: `["data", "public_list", "admin_list", "admin_edit"]`.

Generated list pages cross-link to their edit page (an "Edit" link per row, a "New" button). When the edit page is not in the selected set, the generator omits the link rather than emitting a dead one — every valid selection produces coherent, working output.

---

## The manifest

A JSON file — the same format as every other declarative manifest in the platform (`plugin.json`, `settings.json`, `theme.json`), parsed with `json_decode`.

```json
{
  "entity": "Product",
  "prefix": "prd",
  "plural": "products",
  "into": "core",

  "surfaces": ["public", "admin"],

  "api": {
    "readable": false,
    "writable": false,
    "public_read": false,
    "unwritable_fields": [],
    "derived_fields": []
  },

  "ai": {
    "readable": false,
    "description": "",
    "owner_field": false,
    "writable_fields": [],
    "untrusted_fields": [],
    "excluded_fields": []
  },

  "owner_field": "prd_usr_user_id",
  "admin_permission": 5,
  "public_permission": 0,

  "delete": {
    "strategy": "soft",
    "foreign_key_actions": {},
    "permanent_delete_actions": {}
  },

  "fields": [
    { "name": "name",   "type": "varchar(255)", "required": true, "unique": true },
    { "name": "email",  "type": "varchar(255)", "as": "email" },
    { "name": "price",  "type": "numeric(10,2)" },
    { "name": "status", "type": "int2", "default": 0, "zero_on_create": true,
      "as": "select", "options": { "0": "Draft", "1": "Published" } },
    { "name": "body",   "type": "text" },
    { "name": "created","type": "timestamp", "default": "now()" }
  ],

  "filters": [
    { "option": "status", "column": "prd_status", "bind": "int" },
    { "option": "active", "column": "prd_status", "condition": "= 1" },
    { "option": "search", "column": "prd_name",   "match": "ilike" }
  ]
}
```

### Required vs. optional

Only `entity`, `prefix`, `plural`, and `fields:` are required. Everything else has a sensible default: `into:` → `core`, `surfaces:` → all five page tokens, `delete.strategy` → `soft`, and the whole `api:`/`ai:` blocks default to off — omit them and the entity simply isn't exposed to the REST/AI surfaces. Field-level `api:`/`ai:` lists are optional too; declare them only when tuning a surface that's on.

### Field naming

Field `name` values are written **without** the prefix; the generator prepends `{prefix}_`. The primary key (`{prefix}_{singular}_id`, an `int8` column with `'serial'=>true`) and, for `strategy: soft`, the `{prefix}_delete_time` column (`timestamp(6)`) are added automatically and must not be listed. **Serial types (`serial`/`bigserial`) cannot be declared on a field** — auto-increment is the generator-injected primary key's job; use plain `int8` for ordinary integer columns. Everything else that references a column — `filters[].column`, `owner_field`, `api`/`ai` field lists, `delete` keys — uses the full prefixed column name verbatim.

### The `as:` hint — semantic form type

A database type can't reveal intent: a `varchar(255)` might be a plain string, an email, a password, or a select. `as:` is how you say which:

- `as: "email"` — email input (text input + email validation)
- `as: "password"` — password input
- `as: "select"` with `options: { "value": "Label" }` — a select
- `as: "text"` — a multiline textbox

When `as:` is absent, the form type falls back to a mechanical DB-type default (below). No semantic type is ever guessed from the column.

### Field-level keys

| Key | Effect |
|---|---|
| `required` | `required` in the field spec + the form field's required attribute |
| `unique` | single-column unique constraint |
| `unique_with` | multi-column unique constraint (array of column names) |
| `default` | default value applied on INSERT (e.g. `0`, `"now()"`) |
| `zero_on_create` | set to 0 on create when null |
| `nullable` | overrides the `is_nullable` default (which is `!required`) |
| `as` / `options` | semantic form type and select options (above) |
| `placeholder` / `help` | passed through to the rendered form field |

---

## Derived vs. declared vs. stubbed

Every part of the output is one of three things — no part is guessed:

- **Derived** — the generator writes it fully from conventions + the field list: prefix/pkey/table, filenames, URLs, requires, permission checks, the `isFormSubmission()` guard, load-or-create, the editable-fields loop, `prepare()`+`save()`, redirect-after-POST, `process_logic()` wrappers, table/header/footer rendering, the `_logic_descriptor()` and its `fromDescriptor()` call.
- **Declared** — comes verbatim (lightly transformed) from the manifest: field specs, the `api:`/`ai:` surface, `delete` actions, `getMultiResults()` filter branches.
- **Stubbed** — a labelled `// TODO:` placeholder a human fills in: cross-field validation, relationship loading, computed `export_as_array()`, and fields with no descriptor type (file uploads, rich text, custom widgets).

### Form generation

Generated views don't hand-list fields. Each form-bearing logic file emits a `_logic_descriptor()`, and the view renders the form with one call to [`fromDescriptor()`](formwriter.md#descriptor-driven-forms-fromdescriptor). Field rendering crosses two mappings, each owned by one place:

1. **DB column type → descriptor type** (owned by the generator, overridable with `as:`):

   | DB type | descriptor `type` |
   |---|---|
   | `varchar(n)`, `character(n)` | `string` |
   | `text` | `text` |
   | `int2/int4/int8`, `integer`, `bigint`, `smallint` | `int` |
   | `numeric(p,s)` | `string` (numeric validation) |
   | `bool`/`boolean` | `bool` |
   | `date` | `date` |
   | `timestamp*`, `json`/`jsonb` | omitted from the form (system-managed / no default input) |

2. **descriptor type → FormWriter field** (owned by `fromDescriptor()`): see the [FormWriter docs](formwriter.md#descriptor-driven-forms-fromdescriptor).

Fields with no descriptor type are emitted as a `// TODO:` stub right after the `fromDescriptor()` call, where you hand-add the `$fw->...()` call.

### Authorization

When `owner_field` is **omitted**, or set to the standard `{prefix}_usr_user_id`, the generator emits no custom auth — SystemBase's default owner-or-staff scope applies. Only a **non-standard** owner column produces an `authenticate_read()/write()` pair: a working owner-check against that column, flagged with a `// TODO: confirm this row-scope rule is correct` comment for you to harden (the polymorphic-owner case uses this path). See [docs/api.md](api.md) for the row-scope model.

### AI member read-scope (`ai.owner_field`)

Separate from the top-level `owner_field` (which governs SystemBase auth), `ai.owner_field` controls how a **non-admin member's** AI reads are contained when the chat assistant is open to members — admins always read cross-user. Emitted as `$ai_owner_field` only when present in the manifest:

- **omitted** — the resolver infers the owner column (the lone `*_usr_user_id` / `*_owner_user_id` column). Zero or 2+ candidates ⇒ the model is hidden from members (ambiguous ownership is never guessed).
- **a column** (`"prd_usr_user_id"`) — name the owner column when it can't be inferred (e.g. a primary key) or to disambiguate two candidates.
- **a list** (`["msg_usr_user_id_sender", "msg_usr_user_id_recipient"]`) — OR-match; a member sees a row if they own it via any column.
- **`false`** — ownerless catalog/config; members read every row.

Run `php plugins/joinery_ai/cli/owner_scope_report.php` after generating to confirm the model resolves the way you intend. See [plugins/joinery_ai/docs/overview.md](../plugins/joinery_ai/docs/overview.md) for the full read-scope model.

---

## CLI

```bash
php utils/scaffold.php <manifest.json> [--force] [--dry-run]
```

- `--force` — overwrite existing files (default: refuse and report collisions). `--force` replaces a whole file outright (no merge); expect it only for greenfield iteration before business logic is added. It also **relaxes the existence guards**: the "table already exists" and "prefix already used" checks become warnings instead of hard errors, so you can regenerate a class whose table is already created (e.g. after fixing a template bug). All other validation stays hard.
- `--dry-run` — render and validate, print the plan, write nothing.

Before writing, the CLI prints the resolved file list and derived names (table, URLs) so the `plural`-driven derivations can be confirmed, then puts its own output through three guarantees:

1. **`php -l`** — every generated file parses.
2. **`validate_php_file.php`** — zero pattern violations (correct requires, no anti-patterns).
3. **Database roundtrip** (data classes only) — the generated class's table is built from its real `$field_specifications` via the production `update_database` path, one synthesized row is inserted, its primary key is retrieved through the canonical `{table}_{pkey}_seq` sequence (the way `SystemBase::save()` does), the row is read back, and the whole thing is rolled back inside a transaction so nothing persists. This catches the "compiles but fails at the database" class of bug — a column type `update_database` can't create, or a primary key whose sequence `save()` can't find. It runs only when a live database is reachable; in a pure-preview context (no DB) it is skipped with a notice.

Any failure aborts the write with nothing written — generated code that fails a guarantee is a generator bug.

### After generation — what you still own

1. **Create the table.** Core entities: run `update_database` from admin utilities. Plugin entities: run "Sync with Filesystem" on the plugin (admin Plugins page). The generator never touches the database.
2. **Add a menu entry** (core entities only). The generated admin pages reference `menu-id: '{plural-with-dashes}'` (e.g. `plural: coupon_codes` → `menu-id: coupon-codes`). Add a matching entry to `admin_menus.json` at the repo root so the sidebar highlights correctly. Plugin menus are declared in the plugin's `plugin.json` instead.
3. **Fill in `// TODO:` stubs** — business rules in the data class, fields with no descriptor type in the views.

---

## Manifest validation

The engine fails fast with actionable errors on: a prefix that isn't exactly 3 lowercase letters or collides with an existing table prefix; a `plural` that isn't a bare snake slug or resolves to an existing table; a `surfaces:` token outside the allowed set or one that resolves (after alias expansion) to an empty set; a field `type` outside the supported set; a field name that includes the prefix or duplicates the PK/soft-delete column; a `filters:` column not defined in `fields:`; `api.public_read` without `api.readable`; an `ai.writable_fields` column caught by the credential regex or also listed in `api.unwritable_fields`; or an `into:` plugin directory that doesn't exist.

---

## Engine

The CLI is a thin wrapper over `ScaffoldGenerator` (`includes/scaffold/ScaffoldGenerator.php`), which keeps a clean pure/impure split so the engine can later back an admin wizard or AI tool:

```php
$gen = new ScaffoldGenerator($manifest);
$gen->validate();        // string[] of errors; empty means generatable
$gen->files();           // [relative_path => rendered_contents] — pure, previewable
$gen->write($force);     // writes to disk, sets 0666/0777 per the file-permissions rule
```

Rendering uses plain PHP templates under `includes/scaffold/templates/` (one per output file type) — no template-engine dependency. The templates are the single source of truth for generated output; they are ordinary hand-maintained system files, version-controlled and trusted like any other source. Whole-file optionality (`surfaces:`) is handled by the engine choosing which templates to run.
