# Joinery AI — Explicit Per-Recipe Model Allowlist (v3)

Part of the Joinery AI plugin. Builds on [`joinery_ai.md`](implemented/joinery_ai.md) (v1) and [`joinery_ai_autodiscovery.md`](implemented/joinery_ai_autodiscovery.md) (v2).

v2 shipped two generic read tools (`describe_models`, `query_model`) gated by a global per-model `$ai_readable` opt-in. In practice, recipe authors always know which models a recipe needs, so leaning on the LLM to "discover" them via a 10–20k-token catalog is wasted overhead. v3 makes model selection explicit at recipe creation time and folds the schema into the cached system prompt.

## Net change

- **Add** a per-recipe model allowlist (`rcp_allowed_models`, JSON array of class names) chosen via checkboxes on the recipe edit form, sourced from `ModelRegistry::all()`.
- **Inject** the chosen models' schemas directly into the system prompt at run start (cacheable prefix, no tool round-trip).
- **Remove** `describe_models` from `recipe_tools/` and from any seeded recipes.
- **Keep** `query_model` as the only read tool. Its executor now intersects the recipe's allowlist with the global `$ai_readable` set; non-allowlisted models return an explicit error.

`$ai_readable` stays as the ceiling — model authors decide what *can* ever be AI-readable; recipe authors pick which subset *this recipe* sees.

## UI

`/admin/joinery_ai/edit` gains a new section, **Allowed Models**, rendered the same way as **Allowed Tools** (`views/admin/edit.php:94-115` is the template):

```
[ ] Event              — Scheduled events with start/end times
[ ] EventRegistrant    — A user registered for an event
[ ] Order              — Customer orders (header rows; line items via OrderItem)
[ ] OrderItem          — Individual purchased items
...
```

Each checkbox shows the class name and `$ai_description`. Submission writes a JSON array to `rcp_allowed_models`. Unchecked = model is invisible to this recipe even if globally `$ai_readable`.

## System prompt schema injection

`RecipeRunner` (or wherever the system prompt is assembled) appends a section like:

```
## Available data models

You can query the following models via query_model. Use field names exactly as shown.

### Event (evt_events)
Description: Scheduled events with start/end times.
Fields:
  evt_event_id (int, pk)
  evt_name (varchar 255)
  evt_start_time (timestamp utc)
  ...

### Order (ord_orders)
...
```

Built from `ModelSchemaBuilder::build($class)` for each class in `rcp_allowed_models`, intersected with `ModelRegistry::all()` (so a model that loses `$ai_readable` later quietly drops out). Empty allowlist → no schema section, and `query_model` always returns "no models allowed for this recipe."

The schema block goes in the system prompt's static prefix and gets a `cache_control: ephemeral` breakpoint so repeated runs of the same recipe within the 5-minute cache window pay near-zero input tokens for it.

**Token cost** with a typical 1–3 model allowlist: ~500–3,000 tokens, vs. 10–20k for the unfiltered `describe_models` payload.

## `query_model` enforcement order

Updated:

1. **Per-recipe allowlist** — `rcp_allowed_models` must contain the requested class. Otherwise return `"Model '<X>' is not allowed for this recipe."`
2. Global `$ai_readable = true` (unchanged)
3. Field blocklist + auto-block regex (unchanged)
4. Soft-delete exclusion (unchanged)
5. PDO SELECT against `$field_specifications`-validated names (unchanged)

Step 1 is new; everything else is the v2 behavior.

## Data class change

`Recipe` (`plugins/joinery_ai/data/recipe_class.php`) gets one new field:

```php
'rcp_allowed_models' => array('type' => 'jsonb', 'is_nullable' => true),
```

Plus an entry in `$json_vars` so `get('rcp_allowed_models')` returns an array. No migration needed — `update_database` adds the column on next sync.

## Removal of `describe_models`

- Delete `plugins/joinery_ai/recipe_tools/DescribeModelsTool.php`.
- Delete `plugins/joinery_ai/includes/ModelSchemaBuilder.php`'s public consumer? No — the schema injector still uses it. Keep the class.
- Any existing recipe with `"describe_models"` in `rcp_allowed_tools` will silently skip it on next run (the runner already tolerates unknown tool names). No data cleanup needed.

## Breaking change for existing recipes

Recipes that previously used `query_model` worked because the global `$ai_readable` set acted as an implicit allowlist. After this change, those recipes have an empty `rcp_allowed_models` and will get "no models allowed" errors until the author opens the edit page and checks the boxes.

Joinery AI is days old and has very few recipes; we accept the breakage rather than building a one-time backfill. The recipe edit page makes it obvious what to do (the new section is right there).

## What stays the same

- `$ai_readable`, `$ai_description`, `$ai_excluded_fields` on data model classes
- Auto-block regex (`/_(password|secret|key|token|hash)$/i`) at the executor layer
- Admin-only assumption; no owner-scoping
- `RecipeToolInterface`, `RecipeToolRegistry`, drop-in tool discovery
- All hand-written tools (`GetMyNotesTool`, `GetStockDataTool`, etc.)

## Files touched

```
plugins/joinery_ai/
  data/recipe_class.php                # add rcp_allowed_models field
  views/admin/edit.php                 # add Allowed Models checkbox group
  logic/admin_edit_logic.php           # validate + persist rcp_allowed_models
  includes/RecipeRunner.php            # inject schema block into system prompt
  includes/ModelQueryExecutor.php      # enforce per-recipe allowlist (new step 1)
  recipe_tools/DescribeModelsTool.php  # DELETE
```

Plus a docs update to `docs/joinery_ai.md`: rewrite the "Auto-discovery" section to describe the allowlist + injection model and remove `describe_models` references.

## Out of scope

- Per-field allowlist UI on the recipe (`$ai_excluded_fields` and the auto-block regex remain the field-level controls).
- Model selection at recipe-run time (e.g. an interactive recipe that asks the user which model to query). Not a current use case.
- Re-introducing `describe_models` as a runtime tool for "exploratory" recipes. If a real use case for ad-hoc model discovery shows up, revisit; until then YAGNI.
