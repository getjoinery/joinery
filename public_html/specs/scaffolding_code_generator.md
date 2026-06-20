# Scaffolding / Code Generator

## Problem

Standing up a new CRUD resource means hand-writing six-plus files that are ~70–80% identical boilerplate: a data class, its Multi collection class, public logic + view, and admin list + edit pages. The repetitive parts (requires, permission checks, the `isFormSubmission()` guard, load-or-create, `prepare()`/`save()`, `process_logic()` wrappers, table/header/footer rendering) are mechanical and error-prone to copy by hand. Only a minority of each file is genuine business logic.

A generator that consumes one declarative manifest and emits the full file set lets a developer skip the boilerplate and edit only the parts that carry decisions. This is also a prerequisite for the hosted **Plugin Builder** product (`plugin_builder_hosted_product.md`).

This spec also absorbs the previously-deferred **FormWriter `fromDescriptor()`** work (Step 6 of `implemented/logic_code_refactor.md`). That work had no forcing function on its own; the generator is it. Descriptor-driven forms are how the generated views render fields from a single declaration, so `fromDescriptor()` is now in scope here rather than a separate future spec.

## Goals

- One declarative manifest is the single source of truth for a new entity.
- `php utils/scaffold.php <manifest>` emits a complete, syntactically valid, validator-clean file set into core or a plugin.
- Generated code follows existing platform patterns exactly — it must be indistinguishable from hand-written files and pass `validate_php_file.php`.
- Everything derivable is derived; everything that needs a human decision is declared in the manifest; everything that is genuine business logic is emitted as a clearly-marked stub.
- Generated views render their forms through `FormWriter::fromDescriptor()` reading a generated `_logic_descriptor()` — one field declaration drives the form, client-side validation, and (with the descriptor-consumer work) the API/AI surfaces. Adding `fromDescriptor()` to `FormWriterV2Base` (inherited by all V2 themes) is part of this spec.

## Non-Goals

- **Not a round-trip framework.** The generator writes files once. After generation the files are owned by the developer; re-running does not re-sync or merge. (See *Safety & idempotency*.)
- **Not a schema manager.** Generated `$field_specifications` feed the existing `update_database` pipeline; the generator never touches the database itself.
- **Not an admin UI** (this iteration). The CLI + manifest is the platform primitive. An admin wizard and AI scaffold tool are downstream consumers of the same engine, specced separately.
- **No read-only detail/show view.** The generator emits list + edit per surface, not a public single-record page (`views/product.php`). A detail page's value is almost entirely custom presentation — layout, related data, SEO/OG tags — so a generated field dump would be rewritten immediately. It's lower-value boilerplate than the list table and edit form, which genuinely save work. Developers write detail views by hand.

## What gets generated

For an entity `Product` (prefix `prd`, plural `products`) the generator emits — singular file/class names derive from `entity`, plural ones from `plural`:

```
data/product_class.php                  # data        — Product + MultiProduct
logic/products_logic.php                # public_list — list logic
views/products.php                      # public_list — public list view
logic/product_edit_logic.php            # public_edit — create/edit logic
views/product_edit.php                  # public_edit — public edit view
adm/admin_products.php                  # admin_list  — admin list view
adm/logic/admin_products_logic.php      # admin_list  — admin list logic
adm/admin_product_edit.php              # admin_edit  — admin edit view
adm/logic/admin_product_edit_logic.php  # admin_edit  — admin edit logic
```

When `into:` targets a plugin, paths are rooted at `plugins/<plugin>/` instead and the entity is registered for the plugin's normal sync. Which of these files are written is controlled by `surfaces:` (see below).

### Selecting what gets generated — `surfaces:`

The selectable unit is a **page** — a logic+view pair — never a single file: a view without its logic is broken and a logic file with no view is pointless, so the generator never emits one half alone. The nine files group into five tokens:

| Token | Emits |
|---|---|
| `data` | the data class (`Product` + `MultiProduct`) |
| `public_list` | `logic/products_logic.php` + `views/products.php` |
| `public_edit` | `logic/product_edit_logic.php` + `views/product_edit.php` |
| `admin_list` | `adm/admin_products.php` + its logic |
| `admin_edit` | `adm/admin_product_edit.php` + its logic |

`public` and `admin` are convenience aliases — `public` = `[public_list, public_edit]`, `admin` = `[admin_list, admin_edit]`. The default is all five (`["public", "admin"]`). `data` is always emitted whenever any page token is present (every page instantiates the model); listing `data` **alone** is the model-only case — exactly what an entity with bespoke presentation (e.g. a calendar) wants: `"surfaces": ["data"]`. A read-only catalog is `["data", "public_list", "admin_list", "admin_edit"]`.

**Link-omission coherence rule.** Generated pages cross-link — a list view renders an "Edit" link per row and a "New" button, both pointing at the corresponding edit page. When a referenced page is not in the selected set, the generator omits the link to it rather than emitting a dead one (a `<?php if ?>` guard in the list template keyed off the selected surfaces). Every valid selection therefore produces a coherent, working output with no dangling links.

No `serve.php` edits are required: public views resolve by auto-discovery and admin pages by the `/admin/*` catch-all. The rare route that does need a `serve.php` entry (URL placeholder, feature flag, permission gate) is added by the developer by hand — the generator never touches `serve.php`.

## The manifest — the starting info we need

The manifest carries exactly the information the system cannot infer. Everything else is derived. It is a JSON file — the same format as every other declarative manifest in the platform (`plugin.json`, `settings.json`, `theme.json`, `admin_menus.json`), parsed with `json_decode`, so no new dependency is introduced.

```json
{
  "entity": "Product",                  // PascalCase single-row class name (drives singular artifacts)
  "prefix": "prd",                      // 3-char field/table prefix
  "plural": "products",                 // REQUIRED bare plural slug; drives table + list files + URLs
  "into": "core",                       // 'core' or 'plugins/<name>'

  "surfaces": ["public", "admin"],      // page sets to emit; tokens: data, public_list, public_edit, admin_list, admin_edit (+ aliases public, admin); default all

  "api": {
    "readable": false,                  // $api_readable
    "writable": false,                  // $api_writable
    "public_read": false,               // $api_public_read (catalog-only)
    "unwritable_fields": [],            // privileged columns (non-credential)
    "derived_fields": []                // computed keys allowed out of export_as_array()
  },

  "ai": {
    "readable": false,                  // $ai_readable
    "description": "",                  // $ai_description
    "writable_fields": [],              // opt-in AI-writable columns
    "untrusted_fields": [],             // user-supplied text → injection markers
    "excluded_fields": []               // noise hidden from the AI surface
  },

  "owner_field": "prd_usr_user_id",     // optional; enables default owner-or-staff auth
  "admin_permission": 5,                // permission floor for admin pages
  "public_permission": 0,               // permission floor for public pages (0 = logged-in, null = anonymous)

  "delete": {
    "strategy": "soft",                 // 'soft' (adds {prefix}_delete_time) or 'hard'
    "foreign_key_actions": {},          // e.g. { "prd_category_id": { "action": null } }
    "permanent_delete_actions": {}      // e.g. { "delete_files": ["prd_image_path"] }
  },

  "fields": [
    { "name": "name",    "type": "varchar(255)", "required": true, "unique": true },
    { "name": "email",   "type": "varchar(255)", "as": "email" },             // 'as' = semantic form type
    { "name": "price",   "type": "numeric(10,2)" },
    { "name": "status",  "type": "int2", "default": 0, "zero_on_create": true,
      "as": "select", "options": { "0": "Draft", "1": "Published" } },
    { "name": "body",    "type": "text" },                                    // → textarea automatically
    { "name": "created", "type": "timestamp", "default": "now()" }
  ],

  "filters": [                          // MultiProduct getMultiResults() option keys
    { "option": "status", "column": "prd_status", "bind": "int" },
    { "option": "active", "column": "prd_status", "condition": "= 1" },
    { "option": "search", "column": "prd_name",   "match": "ilike" }
  ]
}
```

The `//` annotations above are for this spec only; the manifest itself is strict JSON and carries no comments.

Field `name` values are written **without** the prefix in the manifest; the generator prepends `{prefix}_`. The primary key (`{prefix}_id`, `bigserial`, PK) and, for `strategy: soft`, the `{prefix}_delete_time` column are added automatically and must not be listed.

Only `entity`, `prefix`, `plural`, and `fields:` are required. Everything else is optional with a sensible default: `into:` defaults to `core`, `surfaces:` to all five page tokens, `delete.strategy` to `soft`, and the whole `api:` and `ai:` blocks default to off — omit them entirely and the entity simply isn't exposed to the REST/AI surfaces. The field-level `api:`/`ai:` lists (`unwritable_fields`, `writable_fields`, etc.) are likewise optional; declare them only when tuning a surface that's turned on.

The optional **`as:`** hint sets the field's *semantic form type* — the thing a database type genuinely cannot reveal. A `varchar(255)` might be a plain string, an email, a password, or a select; the column type is identical for all of them. So `as:` is how the author says "this varchar is an email input" or "this smallint is a select with these `options:`". When `as:` is absent, the generator falls back to a mechanical DB-type → form-type default (see *Form generation*). No semantic type is ever guessed from the column.

**No pluralization is inferred.** The required `plural` slug is the single source for every plural-derived name — the table (`{prefix}_{plural}`), the list view/logic (`{plural}.php`, `{plural}_logic.php`), the admin list pages, and the public/admin URLs (`/{plural}`, `/admin/admin_{plural}`). Singular artifacts (the `Product` class, `product_edit.php`) derive from `snake_case(entity)`, which is unambiguous. There is no inflector and no irregulars map: a developer who wants `prd_product_catalog` writes `plural: product_catalog` and all four derivations stay consistent. The CLI echoes the resulting paths for confirmation before any file is written.

## Derivation rules — derived vs. declared vs. stubbed

The generator classifies every part of the output into three buckets. This table is the contract.

| Output element | Bucket | Source |
|---|---|---|
| `$prefix`, `$pkey_column` | **Derived** | prefix + entity |
| `$tablename`, list/admin filenames, URLs | **Derived** | prefix + `plural` (no inference) |
| PK field spec, soft-delete column | **Derived** | conventions |
| Per-field `type` / `is_nullable` / `required` / `default` / `unique` / `unique_with` / `zero_on_create` | **Declared** | `fields:` |
| Timestamp & JSON field handling | **Derived** | field type (auto-detected at runtime) |
| `$api_readable/_writable/_public_read`, field floors | **Declared** | `api:` |
| Custom `authenticate_read/write()` | **Derived or stubbed** | omitted when `owner_field` set (default owner-or-staff applies); stubbed with a TODO when ownership is non-standard |
| `$ai_*` surface | **Declared** | `ai:` |
| `$foreign_key_actions`, `$permanent_delete_actions` | **Declared** | `delete:` |
| Multi class shell, `$model_class`, constructor | **Derived** | conventions |
| `getMultiResults()` filter branches | **Declared** | `filters:` |
| Logic: requires, permission check, singletons, `isFormSubmission()` guard, load-or-create, editable-fields loop, `prepare()`+`save()`, redirect-after-POST, `LogicResult::render()` | **Derived** | conventions + field list |
| View: `process_logic()` wrapper, header/footer, `$page_vars` extraction | **Derived** | conventions |
| `_logic_descriptor()` `input` array + the `fromDescriptor()` form call | **Derived** | field list + `as:`/`options:` hints (see *Form generation*) |
| Admin list: Pager, `tableheader()`/`disprow()`/`endtable()`, search/sort wiring | **Derived** | conventions + field list |
| Cross-field validation, relationship loading, computed `export_as_array()`, custom business rules | **Stubbed** | emitted as a commented `// TODO:` block at the documented extension point |

**Derived** = generator writes it fully. **Declared** = comes verbatim (lightly transformed) from the manifest. **Stubbed** = generator emits a labelled placeholder; a human fills it in. No part of the output is guessed.

## Form generation (descriptor-driven)

Generated views do not hand-list form fields. The generator emits a `_logic_descriptor()` in each form-bearing logic file and the view renders the form with one call:

```php
$fw = $page->getFormWriter('product_edit', ['model' => $model]);
$fw->fromDescriptor(product_edit_logic_descriptor());   // every field, from one declaration
$fw->submitbutton('submit', 'Save');
```

This makes the field list a single declaration shared by the form, its client-side validation attributes, and — once `FUTURE_descriptor_consumers.md` lands — the REST/AI surfaces. It also means the generator emits *less* per-view code, not more.

**`fromDescriptor()` is delivered as part of this spec.** It is added once to `FormWriterV2Base`, the shared parent of every V2 FormWriter — so all themes inherit it: `FormWriterV2HTML5` (vanilla/public default), `FormWriterV2Bootstrap` (admin + Bootstrap themes), and `FormWriterV2Tailwind`. This is the correct layer because the method is pure loop-and-dispatch over field methods (`textinput`, `numberinput`, `dropinput`, `checkboxinput`, `dateinput`, `textarea`, etc.) that already live on `FormWriterV2Base`; no theme-specific rendering is involved, so there is nothing to override per subclass. The method iterates the descriptor's `input` array and emits one field per entry, dispatching on `type`; unknown types are skipped silently so hand-added fields can coexist.

### Two-layer type mapping

Field rendering crosses two boundaries, each owned by one place — neither guesses across the other's responsibility:

1. **DB column type → descriptor `type`** — owned by the **generator**. Mechanical default when `as:` is absent:

   | DB type | descriptor `type` |
   |---|---|
   | `varchar(n)`, `character(n)` | `string` |
   | `text` | `text` (textarea) |
   | `int2/int4/int8`, `integer`, `bigint` | `int` |
   | `numeric(p,s)` | `string` (numeric validation) |
   | `bool`/`boolean` | `bool` |
   | `date` | `date` |
   | `timestamp*` | omitted from the form (system-managed) |
   | `json`/`jsonb` | omitted (no sane default input) |

   The `as:` hint overrides this for the semantic types a column type can't express — `email`, `password`, `select` (with `options:`), `text`. `timestamp`/`json` fields can be surfaced explicitly with an `as:` if the author really wants an input for them.

2. **Descriptor `type` → FormWriter field** — owned by **`fromDescriptor()`**:

   | descriptor `type` | FormWriter field |
   |---|---|
   | `string` | text input |
   | `email` | email input |
   | `password` | password input |
   | `int` | number input |
   | `bool` | checkbox |
   | `select` | select (`options` from descriptor) |
   | `text` | textarea |
   | `date` | date input |

Adding a new input type later means touching exactly these two mappings — nothing in the templates.

### Field-level extras and fields that don't fit

Descriptor entries may carry FormWriter-only hints the API/AI consumers ignore — `placeholder`, `help` — plus the shared `label` and `required`. The generator populates `label` from a title-cased field name and `required` from the field spec; `placeholder`/`help` are passed through from optional manifest field keys.

Fields that have no descriptor type — file uploads, rich-text, custom widgets, conditional visibility — are emitted as a labelled `// TODO:` stub immediately after the `fromDescriptor()` call, where the developer hand-adds the `$fw->...` call. `fromDescriptor()` and hand-added fields interleave freely; the developer controls order by call order.

## Generator engine

A single core class — `ScaffoldGenerator` — is the reusable primitive; the CLI is a thin wrapper so the engine can later back an admin wizard or AI tool. It keeps a clean pure/impure split as two methods: `files()` computes the output with no side effects, `write()` puts it on disk.

```
$gen = new ScaffoldGenerator(array $manifest);
$gen->files(): array          // [relative_path => rendered_contents] — pure, inspectable/previewable
$gen->write(bool $force)      // writes to disk, chmod per CLAUDE.md
```

`files()` returns a plain array, which is all a preview consumer (admin wizard, AI tool) needs to render without writing — no separate plan object is required. Rendering uses simple PHP templates (one per output file type) under `includes/scaffold/templates/`.

These are real PHP files (`*.tpl.php`), not token-substitution strings — "simple" refers to having no template-engine dependency, not to being a search/replace pass. They use native `<?php foreach ?>` for the parts that vary in cardinality (`$field_specifications`, `getMultiResults()` filter branches, the descriptor `input` array, admin `disprow()` columns) and native `<?php if ?>` for the parts that are optional (the soft-delete column, the `$api_*`/`$ai_*` blocks, default-vs-stubbed `authenticate_*()`, `$foreign_key_actions`/`$permanent_delete_actions`, per-field `unique`/`unique_with`/`zero_on_create`). Whole-file optionality (`surfaces:`) is handled one level up, by the engine choosing which templates to run.

The engine:

1. Parses + validates the manifest (see *Manifest validation*).
2. Normalises field names (prefixing), injects PK and soft-delete columns.
3. `files()` maps each target path to rendered content.
4. `write()` refuses to clobber existing files unless `--force`, then sets `0666`/`0777` per the file-permissions rule.

The templates are the single source of truth for generated output — there is no separate "golden" reference set and no regenerate-and-diff fixture. They are ordinary hand-maintained system files: version-controlled, edited by hand, and carrying the same trust model as any other source in the repo. A mistake committed to a template produces wrong output exactly as a bug in any base class or helper would, and is caught the same way — by review, by use, and by the two automated guardrails that run against the *generated output* (see *Post-generation guarantees*).

## CLI

```
php utils/scaffold.php <manifest.json> [--force]
```

- `--force` — overwrite existing files (default: refuse and report collisions).

Which page sets are emitted is controlled declaratively by `surfaces:` in the manifest (five tokens — see *Selecting what gets generated*), not by a CLI flag.

Before writing, it prints the resolved file list and derived names (table, URLs, filenames) so the `plural`-driven derivations can be confirmed. On success it prints the file list and the two follow-up commands the developer still owns: run `update_database` (core) or "Sync with Filesystem" (plugin) to create the table, and fill in any emitted `// TODO:` stubs.

## Manifest validation

Before generating, the engine fails fast with actionable errors on:

- prefix not exactly 3 chars, or collides with an existing table prefix;
- `plural` missing, not a bare lowercase snake slug, or resolving to a table (`{prefix}_{plural}`) that already exists;
- `surfaces:` containing a token outside `{data, public_list, public_edit, admin_list, admin_edit, public, admin}`, or resolving (after alias expansion) to an empty set;
- field `type` not in the supported set (the same types `update_database` accepts);
- a field name that includes the prefix (must be bare) or duplicates the PK/soft-delete column;
- `filters:` referencing a column not in `fields:`;
- `api.public_read: true` without `readable: true`;
- `ai.writable_fields` naming a column caught by the credential regex or listed in `api.unwritable_fields`;
- `into:` naming a plugin directory that does not exist.

## Post-generation guarantees

Every generated PHP file must:

1. Pass `php -l` (syntax).
2. Pass `php maintenance_scripts/dev_tools/validate_php_file.php` (no bad requires, no `__DIR__` navigation, no missing methods).

The generator runs both over its own output before reporting success; a failure aborts the write. This is a hard acceptance criterion — generated code that fails validation is a generator bug.

## Safety & idempotency

The generator is **creation-only — it writes new files and never edits an existing one** (per the no-band-aid principle — we do not bolt on merge/round-trip machinery to paper over the fact that generated files get hand-edited):

- It never overwrites without `--force`.
- It emits whole files; it never patches an existing one, `serve.php` included. The rare entity that needs a `serve.php` route gets it added by the developer by hand.
- `--force` replaces a whole file outright (no merge); it is the developer's explicit choice, expected only for greenfield iteration before business logic is added.

## Documentation

On implementation, add a **Scaffolding / Code Generator** guide to `/docs/` (new file `docs/scaffolding.md`, linked from the `CLAUDE.md` documentation index) covering the manifest format, the derived/declared/stubbed contract, the CLI, and the "what you still own after generation" follow-ups. Also document `fromDescriptor()` in `docs/formwriter.md` and reference it from `docs/logic_architecture.md`. Per the docs rule, write it as the current state with no migration narrative.

## Open questions

_None outstanding._
