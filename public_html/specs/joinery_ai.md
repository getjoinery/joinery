# Joinery AI Plugin — Build Spec

A Joinery plugin that runs scheduled LLM "recipes" — bounded tool-use loops that gather information, write to a workspace, and deliver results to a dashboard and/or email.

For the vision, philosophy, and scope rationale, see [`FUTURE_personal_ai_recipes.md`](FUTURE_personal_ai_recipes.md). This doc is the buildable subset.

## Acceptance use cases

The MVP is "done" when both of these run end-to-end on a schedule, deliver useful output, and remember context across runs.

1. **Weekly music digest.** Every Monday 7am, fetch a configured list of Nashville venue calendar pages, identify upcoming shows for the week, filter against a workspace of "artists I'd like" and "artists I've already seen," email a ranked list with links, and update the workspace.
2. **Weekly stock research.** Every Sunday evening, pull the week's top movers, look up fundamentals + recent news for each, score continuation likelihood, skip tickers researched in the last 30 days (per workspace), email a ranked list, log the prediction to workspace for next week's grading pass.

Anything not required by these two cases is out of scope for v1.

## Scope

**In (v1):**
- Recipes: name, prompt, schedule, allowed tools, model, delivery, workspace, enabled
- Recipe runner with bounded tool-use loop (max iterations, max tokens, timeout)
- Run history with output, tool-call trace, token usage, cost estimate
- Admin UI: CRUD recipes, run-now button, run viewer
- Dashboard cards rendering latest output per recipe
- Email delivery via `SystemMailer`
- Hardcoded **+ plugin-extensible** tool registry (six MVP tools + market data)
- Anthropic API only (Sonnet/Haiku/Opus selectable per recipe)

**Out (deferred):**
- Local inference (Ollama, llama.cpp)
- Email-as-UI / inbound email triage
- Cross-run memory beyond the workspace blob
- Approval queues
- MCP server support
- Multi-user / household mode
- Custom model providers beyond Anthropic
- Tier gating, complex cost caps (per-run token budget is enough for v1)

## Data models

| Table | Purpose |
|-------|---------|
| `rcp_recipes` | One row per recipe definition |
| `rcr_recipe_runs` | Append-only execution log |
| `rcn_notes` | Owner-visible notes (read/written by `save_note` / `get_my_notes` tools) |

### `rcp_recipes` key fields

`rcp_name`, `rcp_prompt` (text), `rcp_schedule_frequency` (`hourly`/`daily`/`weekly`), `rcp_schedule_day_of_week` (int, weekly only), `rcp_schedule_time` (time, daily/weekly), `rcp_allowed_tools` (jsonb array of tool names), `rcp_model` (varchar — `claude-sonnet-4-7`, `claude-haiku-4-5`, etc.), `rcp_delivery_email` (nullable; falls back to owner), `rcp_delivery_dashboard` (bool, default true), `rcp_enabled` (bool), `rcp_max_iterations` (int, default 10), `rcp_max_tokens` (int, default 20000), `rcp_monthly_token_cap` (int, default 1000000 — safety brake; see **Cost protection**), `rcp_workspace` (text — LLM-curated scratchpad), `rcp_owner_user_id` (single-user mode = always permission-10 admin in v1), `rcp_create_time`, `rcp_modified_time`, `rcp_delete_time`.

Reuse the existing scheduled-task frequency vocabulary so users see one schedule mental model across the system.

### `rcr_recipe_runs` key fields

`rcr_rcp_recipe_id`, `rcr_started_time`, `rcr_completed_time`, `rcr_status` (`pending`, `running`, `success`, `failed`, `timeout`), `rcr_input_tokens`, `rcr_output_tokens`, `rcr_cost_estimate` (numeric), `rcr_output` (text/markdown), `rcr_error` (nullable), `rcr_tool_calls` (jsonb — full trace of tool name, input, output, duration), `rcr_workspace_before` and `rcr_workspace_after` (text — diff-able for debugging).

Index on `(rcr_rcp_recipe_id, rcr_started_time DESC)` for "latest run" queries.

### Workspace size cap

`rcp_workspace` is hard-capped at **8000 characters**. The cap is enforced in two places:

- **`set_workspace` tool** rejects writes over the cap with an error result, telling the LLM "your workspace would exceed 8000 chars; rewrite it more concisely or summarize older entries first." The LLM gets one chance to retry within the same run; persistent failure is logged but doesn't fail the run.
- **System prompt** instructs the LLM to treat workspace as a curated rolling note — when approaching the cap, summarize/compact older content rather than appending forever.

Cap value lives in setting `joinery_ai_workspace_max_chars` (default 8000) so it's tunable without a code change.

### `rcn_notes` schema

| Column | Type | Notes |
|--------|------|-------|
| `rcn_note_id` | bigserial PK | |
| `rcn_owner_user_id` | int8 | FK to users; v1 single-user but the column is right thing forward |
| `rcn_title` | varchar(255) | Unique per owner — `save_note` upserts by `(owner, title)` |
| `rcn_content` | text | Markdown |
| `rcn_tags` | jsonb | Optional array of strings |
| `rcn_create_time` | timestamp | |
| `rcn_modified_time` | timestamp | |
| `rcn_delete_time` | timestamp | Soft delete |

Index on `(rcn_owner_user_id, rcn_modified_time DESC)` for "my recent notes" queries.

`save_note(title, content, tags?)` — upsert by `(rcn_owner_user_id, rcn_title)`. Same title = update; new title = insert.
`get_my_notes(search?, limit=20)` — ILIKE match across title and content (full-text search is overkill for v1), returned in modified-time-desc order. Single-user mode means "owner" is implicit.

## Tool registry

Tools are PHP classes implementing a single interface:

```php
interface RecipeToolInterface {
    public static function name(): string;            // 'web_search'
    public static function description(): string;     // shown to the LLM
    public static function inputSchema(): array;      // JSON schema for Anthropic tool use
    public function execute(array $input, RecipeRunContext $ctx): array;  // returns tool result
}
```

`RecipeRunContext` carries the current `Recipe`, the `RecipeRun` row being written, and the owner — so tools can read/write workspace, log telemetry, and access owner-scoped data without a fragile global.

### v1 tool set

| Tool | Purpose | Backed by |
|------|---------|-----------|
| `web_search` | General web search | Brave Search API (free tier) — abstracted via `WebSearchProvider` for later swaps |
| `fetch_url` | Retrieve a URL, return readable text | Guzzle + Readability (or `php-readability` lib if added) |
| `get_workspace` | Read this recipe's workspace blob | `rcp_workspace` |
| `set_workspace` | Overwrite this recipe's workspace blob (subject to size cap — see below) | `rcp_workspace` |
| `get_recent_outputs` | Read recent runs of any recipe | `rcr_recipe_runs` |
| `save_note` / `get_my_notes` | Read/write owner-visible notes table | New `rcn_notes` table (single owner v1) |
| `get_stock_data` | Top movers + fundamentals + news for a ticker | Finnhub free tier (more generous rate limits than Alpha Vantage), behind a `MarketDataProvider` interface |

The market-data tool is the only addition over the FUTURE doc — required by use case #2.

### Plugin extensibility from day one

Any plugin can register tools by placing classes implementing `RecipeToolInterface` in `plugins/{plugin}/recipe_tools/`. The registry scans on boot, same pattern as scheduled tasks. This is cheap to build now and avoids a refactor later — also makes the "Joinery as a toolset" future direction natural rather than retrofitted.

## Cost protection

Two layers of brake, both token-based (token usage is reported on every Anthropic response; dollars are an estimation downstream).

**Per-recipe monthly cap.** `rcp_monthly_token_cap` (default 1,000,000 input + output combined per calendar month). Before each run starts, the runner sums `rcr_input_tokens + rcr_output_tokens` for that recipe over the current calendar month. If at or above cap, the run is recorded as `status='skipped'`, `rcr_error='monthly_token_cap_reached'`, and a throttled email goes to the owner (same throttle as failure emails).

**Plugin-wide monthly cap.** Setting `joinery_ai_global_monthly_token_cap` (default 5,000,000). Same check, summed across all recipes. Hit either cap and the run is skipped.

**Soft alert at 80%.** When *any* cap crosses 80% during a run, send a one-shot email "you've used 80% of your monthly cap" — once per cap per month, gated by a row in `stg_settings` like `joinery_ai_alert_sent_2026_04_recipe_42` (cleared monthly).

These are deliberately coarse — token count, not dollars; calendar-month buckets, not rolling windows. The point is a hard ceiling that prevents a runaway from costing real money, not precise spend reporting.

## Failure & retry policy

Kept deliberately simple. Three layers:

**Anthropic API call** — retry up to 2 times with 1s, 3s backoff on 5xx or transport errors. 4xx (auth, validation) fails immediately. After exhausted retries, run is marked `failed` with the last error message captured to `rcr_error`.

**Tool call** — errors do *not* hard-stop the run. The error is wrapped as a `tool_result` block with `is_error: true` and returned to the LLM, which can decide to retry, try a different tool, or give up. This is how Anthropic's tool-use protocol expects errors to flow.

**Runaway-tool-error guard** — if 3 consecutive tool calls in the same run return errors, the runner aborts with `status='failed'`, `rcr_error='consecutive_tool_failures'`. Prevents an LLM from looping forever on broken tools.

`fetch_url` and `web_search` use a 15-second per-call timeout, no inner retry — the LLM can call again if it wants. The tool's job is to return success-or-error fast, not to be clever.

## URL safety / SSRF protection

`fetch_url` is the highest-risk tool — an LLM can be tricked or hallucinate URLs that probe internal infrastructure. A `UrlSafetyValidator` helper gates every `fetch_url` call before any network is touched.

**Validator behavior** — `UrlSafetyValidator::check(string $url): void` (throws `UnsafeUrlException` on rejection):

1. **Scheme allowlist:** only `http://` and `https://`. Reject `file://`, `gopher://`, `ftp://`, `data:`, etc.
2. **Port allowlist:** only 80 and 443. Reject `:22`, `:3306`, `:5432`, `:6379`, etc.
3. **Resolve and check ALL IPs.** Use `gethostbynamel()` (or `dns_get_record` for IPv6) — a hostname can resolve to multiple addresses. Reject if *any* resolved IP falls in:
   - **IPv4 private:** `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`
   - **IPv4 loopback:** `127.0.0.0/8`
   - **IPv4 link-local:** `169.254.0.0/16` (catches AWS/GCP/Azure metadata `169.254.169.254`)
   - **IPv4 reserved:** `0.0.0.0/8`, `100.64.0.0/10` (CGNAT), `224.0.0.0/4` (multicast), `240.0.0.0/4`
   - **IPv6 loopback / link-local / unique-local:** `::1`, `fe80::/10`, `fc00::/7`
4. **Reject hostname literals:** `localhost`, `localhost.localdomain`.

**Guzzle config for the request itself** — even after validation, defense in depth:

- `allow_redirects` enabled but each redirect target is re-validated through the same checker (DNS rebinding mitigation).
- 15-second total timeout, 5-second connect timeout.
- Response size cap (e.g. 2 MB) to prevent OOM from a hostile content-length.

The validator is a small, well-tested helper — about 60 lines. Worth a unit test exercising each rejected category.

## Concurrency

**Setting:** `joinery_ai_max_concurrent_workers` (default 3).

**Where it's enforced** — every place that's about to spawn a worker (manual trigger, dispatcher, worker self-chain) checks `SELECT count(*) FROM rcr_recipe_runs WHERE rcr_status IN ('running')` first. If at cap, no spawn — the row stays `pending` until something below picks it up.

**Three things drain the pending queue:**

1. **Dispatcher tick** (every 15 min) — after reaping and after scheduling-due-recipes, drain pending rows oldest-first up to the concurrency cap.
2. **Manual trigger** — inserts pending row, then attempts immediate spawn if under cap. If at cap, UI shows "Queued — N runs ahead."
3. **Worker self-chain** — when a worker finishes, before exit it queries for the oldest `pending` row and spawns it (still subject to the cap, though the just-finishing worker frees a slot). Keeps the queue draining without waiting for cron.

The race between `count(*)` and `INSERT` is benign — at-cap-plus-one occasionally is fine for a 3-worker cap. If precision becomes important, wrap the check + spawn in a postgres advisory lock.

## Recipe runner

`RecipeRunner::run(RecipeRun $run): void` — operates on an already-inserted run row (status `pending` or `running`). Always invoked out-of-process; never blocks a web request. See **Run lifecycle** below.

1. Mark `rcr_status=running`, snapshot workspace into `rcr_workspace_before`.
2. Build the system prompt: recipe's `rcp_prompt` + workspace contents + standard preamble (current date, owner timezone, workspace-cap reminder, "you are a recipe runner" framing).
3. Loop, up to `rcp_max_iterations`:
   - Call Anthropic Messages API with allowed tools' schemas.
   - If response has `tool_use` blocks: execute each, append `tool_result` blocks, continue.
   - If response is `end_turn`: capture text, break.
   - If token budget exceeded or wall-clock timeout hit: break with `status=timeout`.
4. On success: write final text to `rcr_output`, persist `rcp_workspace` if a `set_workspace` call happened, update token counts and cost estimate.
5. On failure: write `rcr_error`, leave workspace unchanged.
6. Deliver per `rcp_delivery_*` flags (dashboard always; email if configured and run succeeded).

Token cost is estimated from input/output token counts × the model's published $/Mtoken (table in `AnthropicClient::COST_PER_MTOKEN`). Off by a few percent is fine; this is for the dashboard, not billing.

## Run lifecycle (always async)

A run is always two-phase: a **trigger** that inserts a pending row and a **worker** that executes it. Web requests never wait for an LLM round-trip.

**Triggers:**
- **Scheduled trigger** — `RecipeDispatcher` (cron-driven) inserts a pending row when a recipe is due, then spawns a worker for it.
- **Manual trigger** — admin "Run Now" button posts to a logic handler that inserts a pending row with `rcr_trigger='manual'`, spawns a worker, and immediately redirects back to the runs page where the row appears with status `pending` → `running` → `success`/`failed`.

**Worker process** — `plugins/joinery_ai/cli/run_recipe.php <run_id>` is a CLI entrypoint that bootstraps PathHelper, loads the run, calls `RecipeRunner::run()`, exits. Spawned via:

```php
exec('php ' . escapeshellarg(PathHelper::getIncludePath('plugins/joinery_ai/cli/run_recipe.php'))
    . ' ' . (int)$run_id . ' > /dev/null 2>&1 &');
```

The detached background process runs without blocking the originating request. On Apache + mod_php this is the most portable async pattern; it doesn't require `fastcgi_finish_request()`. If a future deployment needs PHP-FPM-style request finishing, the runner is unchanged — only the spawn helper swaps.

**Polling for status** — the runs admin page auto-refreshes every 5s while any visible run is in `pending` or `running` state, then stops once all visible rows are terminal. Simple meta-refresh is enough; no AJAX endpoint needed for v1.

**Crash recovery** — if a worker dies mid-run, the row stays in `running` indefinitely. The dispatcher includes a sweep step: any `rcr_status='running'` row whose `rcr_started_time` is older than `(rcp_max_iterations * 60s)` plus margin is marked `timeout` with an error note. This is the reaper.

## Anthropic client

Thin Guzzle wrapper at `plugins/joinery_ai/includes/AnthropicClient.php`. No SDK dependency.

```php
$client = new AnthropicClient($settings->get_setting('anthropic_api_key'));
$response = $client->createMessage([
    'model' => $recipe->get('model'),
    'max_tokens' => $remaining_budget,
    'system' => $system_prompt,
    'messages' => $messages,
    'tools' => $tool_schemas,
]);
```

API key stored in `stg_settings` as `anthropic_api_key` (set via admin settings UI; never hardcoded).

## Scheduler integration

One scheduled task: `RecipeDispatcher` (in `plugins/joinery_ai/tasks/`).

- Frequency: `every_run` (fires every cron tick, currently 15 min)
- Per tick:
  1. **Reaper:** sweep `rcr_recipe_runs` for stuck `running` rows past their max-runtime budget; mark them `timeout`.
  2. **Scheduler:** `SELECT * FROM rcp_recipes WHERE rcp_enabled = true AND rcp_delete_time IS NULL`. For each recipe, compute next-due time from its schedule fields and the latest `rcr_recipe_runs.rcr_started_time`. If due and no run is currently `pending`/`running`, insert a `pending` row and spawn a worker (same exec pattern as manual trigger).

The 15-min granularity is fine for v1 — the target use cases run weekly. If finer scheduling is needed later, the dispatcher can poll more frequently independently of the cron interval.

## Admin UI

**`/adm/admin_recipes`** — list page:
- Table: name, schedule, last run status, last cost, enabled toggle, edit / run-now / view-runs / delete
- "Create Recipe" button → edit form

**`/adm/admin_recipes_edit?id=N`** — edit form:
- Name, prompt (textarea, large), schedule (reuse scheduled-tasks frequency picker), model dropdown, allowed-tools checkboxes, delivery email, dashboard-card toggle, max-iterations, max-tokens, enabled
- Workspace shown read-only with "Edit raw" toggle (you can hand-edit when debugging)

**`/adm/admin_recipes_runs?recipe_id=N`** — run history:
- Table: started, status, duration, tokens in/out, cost, output preview, view-trace
- Trace view: full JSON of `rcr_tool_calls`, before/after workspace diff

All admin pages permission-10 in v1. Standard FormWriterBootstrap.

## Dashboard

**`/joinery_ai`** — owner-only landing page, public-theme rendered:
- Each recipe with `rcp_delivery_dashboard = true` shows a card
- Card content = latest successful `rcr_output` rendered as Markdown
- Card header: recipe name, "ran X ago", run-now button, link to history

Implemented as a single view with one helper that fetches latest run per recipe. Card styling lives in the active public theme.

## Settings (declared in `plugin.json`)

```json
{
  "settings": {
    "joinery_ai_anthropic_api_key": {"type": "password", "default": "", "label": "Anthropic API Key"},
    "joinery_ai_brave_search_api_key": {"type": "password", "default": "", "label": "Brave Search API Key"},
    "joinery_ai_market_data_provider": {"type": "select", "default": "finnhub", "options": ["finnhub", "alphavantage"]},
    "joinery_ai_market_data_api_key": {"type": "password", "default": "", "label": "Market Data API Key"},
    "joinery_ai_default_model": {"type": "text", "default": "claude-sonnet-4-7"},
    "joinery_ai_failure_email_throttle_seconds": {"type": "number", "default": 86400},
    "joinery_ai_workspace_max_chars": {"type": "number", "default": 8000},
    "joinery_ai_max_concurrent_workers": {"type": "number", "default": 3},
    "joinery_ai_global_monthly_token_cap": {"type": "number", "default": 5000000}
  }
}
```

## File layout

Reflects what shipped — modern plugins put admin pages under `views/admin/`
(URL: `/admin/{plugin}/{slug}`) and logic in `logic/`, not in a top-level
`admin/` directory.

```
plugins/joinery_ai/
  plugin.json
  settings_form.php                # API keys + caps; included by /admin/admin_settings
  data/
    recipes_class.php              # Recipe + MultiRecipe
    recipe_runs_class.php          # RecipeRun + MultiRecipeRun
    recipe_notes_class.php         # RecipeNote + MultiRecipeNote
  includes/
    AnthropicClient.php
    RecipeRunner.php
    RecipeRunContext.php
    RecipeToolInterface.php
    RecipeToolRegistry.php
    RecipeWorkerSpawner.php        # concurrency cap + detached exec spawn
    UrlSafetyValidator.php
    CostGuard.php
    MarketDataProviderInterface.php
    market_data/
      FinnhubProvider.php
  recipe_tools/
    WebSearchTool.php              # Brave Search (free tier)
    FetchUrlTool.php
    GetWorkspaceTool.php
    SetWorkspaceTool.php
    GetRecentOutputsTool.php
    SaveNoteTool.php
    GetMyNotesTool.php
    GetStockDataTool.php
  tasks/
    RecipeDispatcher.php           # reaper + scheduler + queue drain
    RecipeDispatcher.json
  views/
    index.php                      # public dashboard at /joinery_ai
    admin/
      index.php                    # /admin/joinery_ai (recipes list)
      edit.php                     # /admin/joinery_ai/edit (recipe edit)
      run.php                      # /admin/joinery_ai/run (single run viewer)
      run_now.php                  # /admin/joinery_ai/run_now (manual trigger)
      runs.php                     # /admin/joinery_ai/runs (run history)
      notes.php                    # /admin/joinery_ai/notes (note list)
      note.php                     # /admin/joinery_ai/note (note edit)
  logic/
    admin_edit_logic.php
    admin_note_logic.php
    joinery_ai_dashboard_logic.php
  cli/
    run_recipe.php                 # CLI worker entrypoint, spawned via exec()
```

The plugin is fully self-contained — no files outside `plugins/joinery_ai/`.
The CLI worker bootstraps PathHelper at the top of the script (the one
explicit `require_once` allowed for CLI scripts running outside the normal
serve.php flow, per CLAUDE.md).

**Brave web-search lives directly in `WebSearchTool.php`** rather than behind
its own provider interface — the spec earlier called for one, but with a
single implementation it was YAGNI noise. Adding a provider abstraction is
a 10-minute refactor when a second implementation actually shows up.

## Acceptance checklist

- [ ] Create both recipes via admin UI
- [ ] Each runs on schedule and produces dashboard cards + emails
- [ ] Music recipe avoids re-listing artists in workspace's "already seen" list across two consecutive runs
- [ ] Stock recipe skips a ticker researched in the prior run (workspace echo)
- [ ] Run-now button works asynchronously: page returns immediately, status transitions visible via auto-refresh, output identical to scheduled run
- [ ] Trace view shows every tool call with input/output for debugging
- [ ] Killing the API key disables runs gracefully (failed status, throttled failure email — not on every tick)
- [ ] No core file changes — entirely contained in `plugins/joinery_ai/`

## Decisions

- **Plugin name:** `joinery_ai`. Dashboard at `/joinery_ai`.
- **Workspace:** 8000-char hard cap, enforced in `set_workspace`, tunable via setting.
- **Failure-email throttle:** per-recipe, `joinery_ai_failure_email_throttle_seconds` setting (default 24h).
- **Run-now:** always async — every run is dispatched to a CLI worker via background `exec()`. Admin UI auto-refreshes for terminal status.
- **Web search provider:** Brave Search free tier, behind `WebSearchProvider` interface.
- **Market data provider:** Finnhub free tier, behind `MarketDataProvider` interface.
- **Cost protection:** per-recipe and global token caps (calendar-month buckets) with 80%-of-cap soft alert. See **Cost protection** section.
- **SSRF protection:** `UrlSafetyValidator` gates every `fetch_url` call. Scheme + port allowlist, blocklist of private/loopback/link-local IPs, redirect re-validation, response size cap. See **URL safety / SSRF protection** section.
- **Failure / retry:** API 5xx → retry 2x with backoff. Tool errors → returned to LLM via `is_error: true`, not a hard stop. 3 consecutive tool errors → abort. See **Failure & retry policy** section.
- **Concurrency:** `joinery_ai_max_concurrent_workers` cap (default 3). Pending queue drained by dispatcher, manual trigger, and worker self-chain. See **Concurrency** section.
- **Notes:** simple table `rcn_notes` with upsert-by-title `save_note` and ILIKE-search `get_my_notes`. See **`rcn_notes` schema** section.

## Deferred / open

1. **Tool registry boot cost.** Scanning every plugin's `recipe_tools/` on every page load is wasteful but tolerable at v1 plugin counts. Add APCu / file cache + plugin-sync invalidation when this becomes measurable. Not v1.

## Build phases

Roughly in priority order. Each phase ends with something demoable.

1. **Schema + models** — `rcp_recipes`, `rcr_recipe_runs`, `rcn_notes`. CRUD admin page with no runner.
2. **Anthropic client + minimal runner + `UrlSafetyValidator`** — single tool (`web_search`), no workspace, sync run-now only. Hello-world recipe runs. SSRF validator unit-tested even before `fetch_url` lands.
3. **Tool registry + remaining MVP tools** (except market data). Music recipe runs end-to-end manually.
4. **Workspace + run history + trace view.** Music recipe schedule-runs and remembers state.
5. **Async run lifecycle + dispatcher + concurrency cap + reaper.** Music recipe fully autonomous on cron, manual triggers don't block, queue drains.
6. **Cost protection (`CostGuard`) + failure-retry policy + throttled alert emails.**
7. **Email delivery + dashboard cards.**
8. **Market data provider + `get_stock_data` tool.** Stock recipe runs end-to-end.
9. **Plugin extensibility hook + edge cases + polish.**

Phases 1–7 deliver the music recipe. Phase 8 unlocks the stock recipe. Phase 9 is hardening.
