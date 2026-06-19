# Joinery AI — LLM Provider Abstraction (local model support)

## Goal

Decouple the recipe runner from Anthropic so a **locally-hosted model can drive recipes**. The first non-Anthropic target is Google **Gemma 4 E2B** served by Ollama on the same host — used occasionally for the owner's personal recipes and for exercising the AI system without spending API tokens.

This was explicitly deferred in the original build spec — see [`implemented/joinery_ai.md`](implemented/joinery_ai.md) "Out (deferred): Local inference (Ollama, llama.cpp)" and "Custom model providers beyond Anthropic". This spec implements that deferral.

## Current coupling (what we're breaking)

The runner is hardwired to Anthropic in a handful of places:

| Coupling | Location |
|----------|----------|
| Client construction | `RecipeRunner::buildClient()` — `includes/RecipeRunner.php:409` |
| Request body shape (`model`/`max_tokens`/`system`/`messages`/`tools`) | `RecipeRunner::run()` — `RecipeRunner.php:130-140` |
| Response parsing (`usage`, `stop_reason`, `content` blocks: `text` / `tool_use`) | `RecipeRunner.php:142-159` |
| Hardcoded model fallback `'claude-haiku-4-5'` | `RecipeRunner.php:131` |
| Cost estimation | `AnthropicClient::estimateCost()` + `COST_PER_MTOKEN` — `includes/AnthropicClient.php:35-40,116` |
| Cost call site | `RecipeRunner::recordTokens()` — `RecipeRunner.php:865` |
| Error type + classification | `AnthropicException`, `RecipeRunner::classifyAnthropicError()` — `RecipeRunner.php:374` |
| Model dropdown (hardcoded 3 Claude models) | `views/admin/edit.php:137-143` |
| Settings (api key, default model) | `plugin.json:22-32`, `settings_form.php:12,36` |

Everything else in the loop — tool dispatch, the consecutive-error guard, the token budget, kill-switch, workspace persistence, taint/owner/allowlist drift checks — is genuinely provider-agnostic and **does not change**.

## Design

### The boundary: canonical IR == the Anthropic block shape

The runner's loop already manipulates messages and content blocks in the Anthropic Messages shape (`text`, `tool_use{id,name,input}`, `tool_result{tool_use_id,content,is_error}`; a top-level `system` array; `tools[]` with `input_schema`). That shape is the most expressive of the candidate wire formats, so we adopt it **as the runner's canonical internal representation** rather than inventing a third neutral format and rewriting the loop.

The provider interface accepts and returns that canonical shape. Each provider is responsible for translating canonical → its own wire format and its response → canonical, **entirely inside the provider class**. The runner never branches on provider.

Consequence: the Anthropic provider is a near-passthrough; the local provider does real translation. This is the correct place for the messy mapping — contained in an adapter, not scattered as conditionals through the runner.

> Honest tradeoff: the canonical IR is "Anthropic-flavoured" rather than vendor-neutral. We accept this because it makes the Anthropic path zero-risk and confines all complexity to providers that genuinely differ. If a third provider ever needs something the shape can't express, that's the moment to promote to neutral DTOs — not now.

### Interface

`includes/llm/LlmProviderInterface.php`:

```php
interface LlmProviderInterface {
    /**
     * $params is the canonical request: model, max_tokens, system (array of
     * text blocks), messages (canonical content blocks), tools (optional,
     * Anthropic tool-schema shape). Returns the canonical response array:
     * { stop_reason, content: [...blocks], usage: {input_tokens,
     *   output_tokens, cache_creation_input_tokens, cache_read_input_tokens} }.
     * Throws LlmProviderException on failure.
     */
    public function createMessage(array $params): array;

    /** USD estimate from a canonical usage block. Local providers return 0.0. */
    public function estimateCost(string $model, array $usage): float;

    /** Models offered to the recipe-edit dropdown: [model_id => label]. */
    public function models(): array;

    /** Model used when a recipe has no explicit rcp_model. */
    public function defaultModel(): string;

    /** Stable identifier ('anthropic', 'local') for logging/diagnostics. */
    public function id(): string;
}
```

`createMessage()` keeps its current signature exactly, so the runner's call site (`RecipeRunner.php:140`) is untouched apart from the variable being an interface instance.

### Error type

Introduce `includes/llm/LlmProviderException.php` as the base. `AnthropicException` extends it (kept for any in-plugin references). The runner catches the **base** type, and `classifyAnthropicError()` is renamed `classifyProviderError()` (same best-effort string matching — it already keys off generic substrings like `4xx`, `timeout`, `connection`, which work for any provider). Add one local-specific branch: a connection refused to the configured base URL classifies as `api_network_error` with the message *"Local model server not reachable at {url} — is Ollama running?"*.

### Providers

**`includes/llm/AnthropicProvider.php`** — today's `AnthropicClient`, renamed, implementing the interface. `createMessage()` body is unchanged (it already speaks canonical). `estimateCost()` and `COST_PER_MTOKEN` move here verbatim. `models()` returns the three Claude entries currently hardcoded in `edit.php` (with the `$in/$out per Mtok` labels). `defaultModel()` → `claude-haiku-4-5`. Keep a `class_alias('AnthropicProvider','AnthropicClient')` so nothing breaks if a reference is missed.

**`includes/llm/OpenAiCompatibleProvider.php`** — one provider covering **Ollama, llama.cpp server, vLLM, and LM Studio**, since all four expose an OpenAI-compatible `/v1/chat/completions` endpoint with tool-calling. Configured by base URL + model + optional key. Choosing the OpenAI-compatible endpoint over Ollama's native `/api/chat` buys portability across every common local runtime for one class — an intentional up-front decision so we never add a second near-identical local adapter.

Translation canonical → OpenAI request:

| Canonical | OpenAI |
|-----------|--------|
| `system` (array of text blocks, `cache_control` ignored) | concatenated into a single `{role:"system", content}` message, prepended |
| user/assistant `text` block | `{role, content: "<text>"}` |
| assistant `tool_use{id,name,input}` | `{role:"assistant", tool_calls:[{id, type:"function", function:{name, arguments: json_encode(input)}}]}` |
| user `tool_result{tool_use_id,content,is_error}` | `{role:"tool", tool_call_id, content}` — `is_error:true` prefixes `"ERROR: "` to content (OpenAI has no error flag) |
| `tools[]` `{name,description,input_schema}` | `{type:"function", function:{name, description, parameters: input_schema}}` |
| `max_tokens` | `max_tokens` |
| — | `"stream": false` |

Translation OpenAI response → canonical:

| OpenAI | Canonical |
|--------|-----------|
| `choices[0].message.content` (non-empty) | `{type:"text", text}` block |
| `choices[0].message.tool_calls[]` | `{type:"tool_use", id, name, input: json_decode(arguments, true)}` blocks (preserve `id` so it round-trips to the next `tool_call_id`) |
| `finish_reason: "stop"` | `stop_reason: "end_turn"` |
| `finish_reason: "tool_calls"` | `stop_reason: "tool_use"` |
| `finish_reason: "length"` | `stop_reason: "end_turn"` (runner then treats partial text as the answer) |
| `usage.prompt_tokens` / `completion_tokens` | `input_tokens` / `output_tokens`; cache fields = 0 |

`estimateCost()` returns `0.0` (local inference is free). `models()` returns the single configured `joinery_ai_local_model` labeled `"{model} (local · free)"`, plus — defensively — any value already stored on the recipe being edited so switching providers never silently rewrites a recipe's model (see UI below). `defaultModel()` → the configured local model.

Edge handling the adapter must cover:
- **Malformed tool arguments.** Small models sometimes emit `arguments` that aren't valid JSON. `json_decode` failure → pass `input` as `{}` and let the tool's own input validation produce a normal `is_error` tool_result. Do **not** crash the run.
- **Reasoning/thinking leakage.** If the model emits `<think>…</think>` or a separate reasoning field, use only `message.content`; never the reasoning channel, as the final report.
- **Empty content + no tool calls.** Treat as `end_turn` with empty text; the runner already records this as `incomplete` (`RecipeRunner.php:205`).
- **HTTP timeout** uses a separate, larger value than Anthropic (local generation on a constrained CPU box is slow) — see settings.

### Factory + runner wiring

`includes/llm/LlmProviderFactory.php`:

```php
LlmProviderFactory::build(): LlmProviderInterface
// reads joinery_ai_llm_provider; 'local' => OpenAiCompatibleProvider(base,model,key),
// anything else => AnthropicProvider(key). Throws LlmProviderException with a
// configuration-specific message if the active provider's required setting is empty.
```

Runner changes (only these):
- `RecipeRunner::buildClient()` → returns `LlmProviderFactory::build()` (rename to `buildProvider()`).
- `RecipeRunner.php:131` fallback `'claude-haiku-4-5'` → `$provider->defaultModel()`.
- `recordTokens()` (`RecipeRunner.php:861`) takes the provider and calls `$provider->estimateCost(...)` instead of the static `AnthropicClient::estimateCost(...)`. The provider instance is already in scope in `run()`; thread it through (or store on a member for the run).
- Catch `LlmProviderException` (base) where it currently catches `AnthropicException`.

Provider is a **global** setting, not per-recipe — simplest for the stated occasional/personal use, and `rcp_model` is reinterpreted by whatever provider is active. (Decided once; per-recipe provider is out of scope.)

### Settings (add to `plugin.json` + `settings_form.php`)

| Setting | Default | Purpose |
|---------|---------|---------|
| `joinery_ai_llm_provider` | `anthropic` | select: `anthropic` / `local` |
| `joinery_ai_local_base_url` | `http://localhost:11434/v1` | OpenAI-compatible base (Ollama) |
| `joinery_ai_local_model` | `gemma4:e2b` | model id pulled in Ollama |
| `joinery_ai_local_api_key` | `` | dummy key for servers that require one; Ollama ignores it |
| `joinery_ai_local_timeout_seconds` | `300` | per-call HTTP timeout for local generation |

`settings_form.php` gains a "LLM provider" select and the local-config fields. Use `visibility_rules` on the local fields so they show only when provider = `local` (per FormWriter conventions — no hand-rolled JS toggle). `joinery_ai_default_model` stays but is only consulted for the active provider.

### UI: dynamic model dropdown

`views/admin/edit.php:137-143` stops hardcoding Claude models. Build options from `LlmProviderFactory::build()->models()`. If the recipe's stored `rcp_model` isn't in the active provider's list (provider was switched after the recipe was authored), append it as a disabled-looking option labeled `"{value} — unavailable under current provider"` so the value is preserved and the mismatch is visible rather than silently overwritten on save.

## Local model: capability reality

Gemma 4 E2B (~2.3B active params) is the configured default per the project decision that the local model runs **on the existing dev box** (3.8 GB total RAM, ~2 GB free alongside Apache/Postgres/PHP-FPM, 2 CPU cores). Be explicit about what that hardware ceiling buys, because it is a ceiling, not a tuning knob:

- **Reliable tool calling has a parameter cliff around 7–9B** (mid-2026 consensus). Below it, models emit malformed tool calls on real workloads regardless of inference time — slowness tolerance does not buy reliability the parameter count lacks. Every model that fits in ~2 GB (E2B, Qwen3-1.7B, Granite-nano, Llama-3.2-3B, etc.) sits well under that cliff, so **model choice within this footprint does not change the outcome** — it trades one sub-threshold model for another.
- E2B is the chosen default not because it tool-calls well but because, as a MoE, it has the best capability-per-byte at this size and is explicitly recommended for limited-hardware agents. The adapter degrades gracefully on malformed calls (above), but **reliable multi-tool loops are not achievable on this box with any model.**
- Recommended use on this host: exercising the provider plumbing end-to-end, and light single-shot or single-tool recipes. Recipes needing dependable multi-tool loops keep `joinery_ai_llm_provider = anthropic`.
- The lever that *does* change reliability is RAM, not model or speed. The day this moves to a roomier host, `gemma4:e4b` (~8 GB) is the natural next step, and a ~20 GB CPU host unlocks an MoE like `qwen3-30b-a3b` — the smallest configuration that clears the tool-calling cliff while staying CPU-tractable. Both are one-line `joinery_ai_local_model` changes; the OpenAI-compatible base URL means that host can be remote.

No prompt caching exists locally, so `cache_control` is ignored and every call re-sends the full system prompt. Cost-irrelevant (free); it just means the schema block isn't amortized across iterations.

## Operations (local host setup)

Not code, but required for the feature to function — document in the plugin overview:

1. Install Ollama on the host that runs the recipe CLI workers (workers are detached `php` processes on the same box, so `localhost:11434` is reachable).
2. `ollama pull gemma4:e2b`.
3. Manage RAM with the `OLLAMA_KEEP_ALIVE` server env (e.g. `5m`) so the model unloads between occasional runs rather than sitting resident — the dev box is deliberately RAM-constrained. The app does not manage server memory; this stays an ops concern.
4. Local generation is slow on CPU; expect multi-minute runs and keep `rcp_max_iterations` modest for local recipes.

## File layout

```
plugins/joinery_ai/includes/llm/
  LlmProviderInterface.php
  LlmProviderException.php          # base; AnthropicException extends it
  LlmProviderFactory.php
  AnthropicProvider.php             # was AnthropicClient.php (+ class_alias)
  OpenAiCompatibleProvider.php      # Ollama / llama.cpp / vLLM / LM Studio
```

`includes/AnthropicClient.php` is removed once references are migrated (the `class_alias` covers anything missed).

## Documentation (update with the change)

Per repo convention, developer docs describe the end state only. Update **`plugins/joinery_ai/docs/overview.md`**:
- Replace the "AnthropicClient.php — HTTP client for Anthropic Messages API" line in the file map with the `includes/llm/` set.
- Add an **"LLM providers"** section: the interface, the canonical-IR boundary, the two providers, the provider/local settings, and the Ollama local-setup steps.
- Note the local-model capability caveat and the E2B→E4B fallback knob.

No change to the implemented specs (they are immutable history).

## Acceptance checklist

- [ ] `joinery_ai_llm_provider = anthropic` runs both existing acceptance recipes unchanged (zero behavior diff — the Anthropic path is passthrough).
- [ ] Switching to `local` with Ollama + `gemma4:e2b` running: a trivial single-tool recipe (e.g. `web_search` or a `query_model` read) completes a full tool-use loop end-to-end and produces a dashboard card.
- [ ] Tool call → tool_result round-trips correctly (tool_call id preserved both directions).
- [ ] Malformed tool arguments from the local model surface as a normal `is_error` tool_result, not a crashed run.
- [ ] Ollama stopped → run fails with the "Local model server not reachable" message and the `api_network_error` code, throttled failure email (not per-tick).
- [ ] Local runs record `rcr_cost_estimate = 0`; token caps in `CostGuard` still enforce against a runaway local loop.
- [ ] Recipe-edit dropdown shows provider-appropriate models; a recipe authored under one provider keeps its stored `rcp_model` visibly flagged under the other.
- [ ] No changes outside `plugins/joinery_ai/`.

## Build phases

1. **Interface + Anthropic provider.** Extract interface, `LlmProviderException`, rename `AnthropicClient` → `AnthropicProvider`, add factory (anthropic-only). Runner wired to factory. Anthropic recipes run identically. No new settings surfaced yet.
2. **Settings + dynamic dropdown.** Add provider/local settings + `settings_form.php` fields with `visibility_rules`; make `edit.php` model dropdown provider-driven.
3. **OpenAI-compatible provider.** Implement translation both directions + edge handling. Local recipe runs end-to-end against Ollama/Gemma.
4. **Docs + validation.** Update `overview.md`; run the acceptance checklist against a live local Ollama.
