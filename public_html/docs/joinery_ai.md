# Joinery AI Plugin

The `joinery_ai` plugin runs LLM-driven recipes against the platform: scheduled or on-demand prompts that call Claude with a curated set of tools and persist the results. It is **admin-only** in the current state — recipes are configured by admins through the admin UI, and the recipe runner executes with the recipe owner's identity.

This doc covers what plugin authors and model authors need to know. For original design rationale, see [`specs/joinery_ai.md`](../specs/joinery_ai.md), [`specs/implemented/joinery_ai_autodiscovery.md`](../specs/implemented/joinery_ai_autodiscovery.md), and [`specs/joinery_ai_write_tools.md`](../specs/joinery_ai_write_tools.md).

## What's in the plugin

```
plugins/joinery_ai/
  data/
    recipes_class.php          # Recipe model — prompt, schedule, allowed tools, owner
    recipe_runs_class.php      # RecipeRun model — per-execution log with tool-call trace
    recipe_notes_class.php     # RecipeNote model — agent ↔ human feedback channel
  includes/
    RecipeRunner.php           # Tool-use loop driver
    RecipeRunContext.php       # Per-run context passed to every tool's execute()
    RecipeToolInterface.php    # Tool contract
    RecipeToolRegistry.php     # Auto-discovers tools across plugins
    AnthropicClient.php        # HTTP client for Anthropic Messages API
    CostGuard.php              # Per-run token/dollar ceilings
    UrlSafetyValidator.php     # SSRF guard for fetch_url tool
    ModelRegistry.php          # Auto-discovery: finds models with $ai_readable
    ModelSchemaBuilder.php     # Auto-discovery: field_specifications -> JSON schema
    ModelQueryExecutor.php     # Auto-discovery: read security boundary
  recipe_tools/                # Each PHP file declares one RecipeToolInterface class
    DescribeModelsTool.php     # describe_models — auto-discovery
    QueryModelTool.php         # query_model — auto-discovery
    GetMyNotesTool.php
    SaveNoteTool.php
    GetWorkspaceTool.php
    SetWorkspaceTool.php
    GetRecentOutputsTool.php
    FetchUrlTool.php
    WebSearchTool.php
    GetStockDataTool.php
  tasks/
    RecipeDispatcher.php       # Cron entry — picks up scheduled recipes
  cli/
    run_recipe.php             # CLI entry to fire a single recipe by ID
```

## Recipes

A recipe is a row in `rcp_recipes` with:

- **prompt** — the system + user message text the LLM sees
- **owner** (`rcp_owner_user_id`) — the user the run executes as. `RecipeRunContext::owner_user_id` and `owner_timezone` are derived from this.
- **allowed tools** (`rcp_allowed_tools`) — JSON array of tool names. Only listed tools are exposed to the LLM. Unknown names are silently skipped (the runner logs them to the trace).
- **schedule** — cron expression, "manual only", or "interactive only".

Recipes are configured at `/admin/joinery_ai` (dashboard) and edited at `/admin/joinery_ai/edit`.

## Recipe runs

Each invocation creates an `rcr_recipe_runs` row with:

- **status** — `running`, `completed`, `error`
- **tool calls** (`rcr_tool_calls`) — JSON array, one entry per `tool_use` block; written by `RecipeRunContext::appendToolCall()` and persisted at run end
- **token / cost totals** — for the cost guard and admin reporting
- **output** — the final assistant message

`RecipeRunner::run($recipe)` drives the tool-use loop: send the conversation to Anthropic, dispatch any `tool_use` blocks back through `RecipeToolRegistry::get($name)->execute($input, $ctx)`, append the `tool_result`, repeat until the model emits a final text response or the cost guard trips.

The `CostGuard` enforces per-run input/output token and dollar ceilings configured in plugin settings; trips raise an exception that the runner logs as `error`.

## Tool architecture

Tools implement `RecipeToolInterface`:

```php
interface RecipeToolInterface {
    public static function name(): string;        // snake_case identifier
    public static function description(): string; // shown to the LLM
    public static function inputSchema(): array;  // JSON Schema for input
    public function execute(array $input, RecipeRunContext $ctx);
}
```

`RecipeToolRegistry` scans every plugin's `recipe_tools/` directory at first use, requires each PHP file, and indexes classes by `name()`. **Drop a new file in any plugin's `recipe_tools/` and it works** — no central registration. Duplicates (same `name()` from two classes) keep the first scanned and log a warning.

`execute()` returns either a string (becomes `tool_result.content`) or `['content' => string, 'is_error' => bool]` for explicit error reporting.

`RecipeRunContext` carries `$recipe`, `$run`, `$owner_user_id`, `$owner_timezone`, plus `appendToolCall()` for trace entries.

## Auto-discovery: `describe_models` + `query_model`

Two generic tools let opted-in data models become readable by recipes without writing a per-model PHP class:

- **`describe_models(prefix?)`** — returns the schema of every model with `$ai_readable = true`. Optional case-insensitive prefix filter (e.g. `"Event"` returns `Event`, `EventRegistrant`, `EventType`).
- **`query_model(model, filters, sort, limit, fields)`** — runs a SELECT against the named model. Filter operators: equality (default), `_like`, `_after` / `_min` (>=), `_before` / `_max` (<=). Soft-deleted rows are excluded automatically.

A recipe gets these tools by listing `describe_models` and/or `query_model` in `rcp_allowed_tools`.

### Opting a model into AI reads

Add four static properties to any `SystemBase`-derived class (typically right after `$pkey_column`):

```php
class UserNote extends SystemBase {
    public static $prefix = 'unt';
    public static $tablename = 'unt_user_notes';
    public static $pkey_column = 'unt_user_note_id';

    // AI auto-discovery (read)
    public static $ai_readable        = true;
    public static $ai_description     = 'User-created notes attached to events.';
    public static $ai_owner_field     = 'unt_usr_user_id';  // forward-compat metadata; see "Owner-scoping" below
    public static $ai_excluded_fields = [];                 // blocklist; merges with auto-block patterns

    // ... existing $field_specifications, etc.
}
```

`ModelRegistry` auto-discovers it on next request. No registration step.

### What `describe_models` returns

For each opted-in model: `class`, `description`, and a `fields` map derived from `$field_specifications`. PostgreSQL types are translated to JSON Schema types (`int4` → integer, `varchar` → string, `timestamp` → string with `date-time` format, `jsonb` → object, etc.).

Fields are filtered through two layers before exposure:

1. **Auto-block regex** — any field matching `/_(password|secret|key|token|hash)$/i` is stripped from both the schema and query output. Catches future mistakes — a new sensitive column years later, with the model still opted in, is still hidden.
2. **`$ai_excluded_fields`** — explicit per-model blocklist. Use for columns the regex misses: raw payment blobs, internal IDs, PINs, "private" notes.

Both layers apply to **all three surfaces** — schema output, filter inputs, and result rows. The LLM cannot see, filter on, or sort by a field on either blocklist. Attempting to filter on an excluded field raises `InvalidArgumentException`, which the tool reports as `is_error: true`.

### Owner-scoping (currently inert)

Joinery AI is admin-only today. Admins can already see every row in the database through the admin UI, and admin recipes legitimately need cross-user views ("show me all unpaid orders", "find users at risk of churn"). Owner-scoping would break those use cases, so `ModelQueryExecutor` does **not** inject any owner filter.

The `$ai_owner_field` property is declared on user-data models anyway as forward-compat metadata. When end-user recipes ship — at which point `recipe-owner ≠ admin` — the executor will start enforcing the field, with a per-recipe escape hatch for admin recipes that need cross-user views. Re-enabling it is a one-line executor change rather than a 19-file sweep.

If you're authoring a new user-owned model and want the declaration to do the right thing later, set `$ai_owner_field` to the user-id column. If the model has no user-id column or represents public/admin metadata, set it to `null`.

### Default-deny posture

- Models without `$ai_readable = true` are invisible to `describe_models` and rejected by `query_model`. The `User`, `Setting`, `ApiKey`, `RequestLog`, `WebhookLog`, `EventLog`, `ChangeTracking`, all email-infrastructure models, all plugin-system models, etc. are deliberately not opted in.
- Adding a column to an opted-in model surfaces it by default (read posture matches the admin UI). If the column is sensitive, add it to `$ai_excluded_fields`.

### Write side (deferred)

`$ai_writable_fields` is reserved for direct-to-model writes on self-contained models (notes, bookmarks, simple records). It is **currently a no-op** — declaring it does nothing until `ModelWriteExecutor` and the `create_model` / `update_model` / `delete_model` tools ship. See [`specs/implemented/joinery_ai_autodiscovery.md`](../specs/implemented/joinery_ai_autodiscovery.md) for the design and the gauntlet test for when a model qualifies.

Any write that needs cross-record invariants (capacity, payment effects, hooks, external system calls) belongs in a logic file with a write-capable descriptor — see [`specs/joinery_ai_write_tools.md`](../specs/joinery_ai_write_tools.md).

## Adding a new hand-written tool

Drop a file into any plugin's `recipe_tools/` directory:

```php
<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));

class MyCustomTool implements RecipeToolInterface {

    public static function name(): string {
        return 'my_custom_tool';
    }

    public static function description(): string {
        return 'One paragraph explaining what this tool does and when to use it. The LLM reads this verbatim.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['some_param'],
            'properties' => [
                'some_param' => [
                    'type' => 'string',
                    'description' => 'What this parameter is for.',
                ],
            ],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $param = (string)($input['some_param'] ?? '');
        // ... do work, optionally using $ctx->owner_user_id, $ctx->owner_timezone, etc.
        return "Result text the LLM will see.";
    }

}
```

Then add `my_custom_tool` to a recipe's `rcp_allowed_tools` list. The next request rebuilds the registry and exposes it.

### When to write a hand-written tool vs. opt the model in

Reach for a hand-written tool when:

- The action spans multiple models (a join the LLM shouldn't be doing in two queries)
- Business rules apply that field-spec validation can't enforce
- The result needs hand-tuned formatting for token efficiency (e.g. `GetMyNotesTool`'s markdown rendering)
- The tool wraps an external API (`FetchUrlTool`, `WebSearchTool`, `GetStockDataTool`)

Opt the model into auto-discovery when:

- The query is "show me rows of X matching Y" with no business rules
- The result is reasonable to ship as JSON
- No cross-table reasoning is needed

Both can coexist — a recipe can use `query_model` for ad-hoc reads and `GetMyNotesTool` for the polished notes view.

## Cost protection

`CostGuard` is initialized per run from plugin settings (`max_input_tokens_per_run`, `max_output_tokens_per_run`, `max_dollars_per_run`). Each Anthropic response includes usage metrics; the guard accumulates them and raises if the next call would exceed any ceiling. The runner catches the exception, marks the run as `error`, and persists the partial trace.

For recipes that are expected to be expensive, raise the ceilings on the recipe row. For recipes that should be cheap, lower them.

## Tracing & debugging

Every tool call appends to `rcr_tool_calls` with `name`, `input`, `output`, `started`, `completed`, `is_error`. The admin run-detail view (`/admin/joinery_ai/run`) renders the trace inline. For ad-hoc debugging, query directly:

```sql
SELECT rcr_tool_calls FROM rcr_recipe_runs WHERE rcr_run_id = ?;
```

## See also

- [`specs/joinery_ai.md`](../specs/joinery_ai.md) — original system spec
- [`specs/implemented/joinery_ai_autodiscovery.md`](../specs/implemented/joinery_ai_autodiscovery.md) — auto-discovery design and threat model
- [`specs/joinery_ai_write_tools.md`](../specs/joinery_ai_write_tools.md) — write-tool design (deferred)
- [`specs/FUTURE_descriptor_consumers.md`](../specs/FUTURE_descriptor_consumers.md) — Step 7: API + AI consume `_logic_descriptor()` natively
- [Plugin Developer Guide](plugin_developer_guide.md) — plugin architecture and routing
- [Logic Architecture](logic_architecture.md) — business logic layer
