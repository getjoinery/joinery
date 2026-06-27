# Joinery AI — selectable thinking level

**Status:** Active — awaiting implementation
**Plugin:** `joinery_ai`
**Touches:** the canonical LLM request, both providers, `AgentLoop`, the chat and
recipe runners, the chat UI and recipe-edit UI, plugin settings.

## Goal

Let the operator choose **how much the model reasons before it answers** —
from none to a lot — per chat, per recipe, and as a system-wide default.

Reasoning-capable models (Qwen3 locally today, Anthropic extended thinking if the
provider switches) can spend extra output generating a private chain of thought
before the real answer. That trade is sometimes worth it (a hard multi-step ask)
and usually not (a quick lookup or a tool call). On the local 14B model the cost
is paid in wall-clock seconds the user waits through; on a paid API it is paid in
output tokens. Right now there is no control: the model reasons as much as it
feels like, and that reasoning is silently discarded from the displayed answer
after the time and tokens are already spent.

In plain terms: a knob that says "think hard," "think a little," or "don't think,
just answer" — and it means the same thing whichever model is behind the chat.

## Why one knob, not a per-model flag

The control belongs at the **canonical request** layer, not in any one model's
dialect. The runners already build a provider-independent request and hand it to
`LlmProviderInterface::createMessage()`; each provider translates canonical →
its own wire format and the runner never branches on provider
(see [`joinery_ai_llm_providers.md`](implemented/joinery_ai_llm_providers.md)).
A thinking control added there is set once and honored by whatever model is
behind the surface — Qwen today, Claude tomorrow — which is the whole point of
that boundary. A Qwen-specific `enable_thinking` flag would have to be re-solved
the day the provider changes; a canonical level never does.

## Design

### The level ladder

A small, provider-agnostic enum — the same four values everywhere a thinking
level is chosen:

- **`off`** — no reasoning; answer directly. The snappy default.
- **`low`** — a little reasoning.
- **`medium`** — moderate reasoning.
- **`high`** — extensive reasoning.

Four levels, not a raw token number, because the user is choosing *intent*
("think harder"), and the right token figure differs per model. The provider maps
the intent onto its own mechanism (below). `off` and "on" are the universal floor
every reasoning model can honor; the three graded levels are best-effort —
a provider that only supports on/off collapses `low|medium|high` to "on" and is
free to ignore the gradation.

### Canonical request: a `thinking` block

`AgentLoop` adds an optional `thinking` key to the canonical params it already
assembles (alongside `model`, `max_tokens`, `system`, `messages`, `tools`):

```php
$params['thinking'] = ['level' => 'medium'];   // or omitted entirely for 'off'
```

`AgentLoop::run()` gains one parameter (`string $thinking_level = 'off'`) that the
runners pass through. When the level is `off`, the key is omitted so nothing
changes for the non-thinking path. Each provider reads `$params['thinking']` and
translates; the loop itself never branches on provider.

### Provider translation

Each provider owns its mapping inside `createMessage()`:

- **`AnthropicProvider`** — emit the `thinking` request field:
  `{ type: "enabled", budget_tokens: N }`, with the level mapped to a budget
  (e.g. `low ≈ 1024`, `medium ≈ 4096`, `high ≈ 12000`). `off` omits the field.
  **Constraint:** Anthropic requires `max_tokens > budget_tokens`, so when
  thinking is enabled the provider must raise the per-call `max_tokens` to leave
  room for the budget *plus* a real answer (see *Token-budget interaction*).
- **`OpenAiCompatibleProvider`** (Ollama / Qwen3) — prefer the server's native
  reasoning switch when present (Ollama's `think` option / a `reasoning_effort`
  field); always fall back to the **prompt-control method** Qwen3 honors
  reliably: append `/think` or `/no_think` to the request. Map `off → /no_think`;
  `low|medium|high → /think` (graded budget if the server exposes one, else plain
  on). The provider already strips `<think>…</think>` from the returned text, so
  the *displayed* answer is unchanged; this only governs whether those tokens are
  **generated** in the first place.

A provider declares which levels it can honor; an unsupported graded level snaps
to the nearest one it supports. The runner stays oblivious.

### Where the level is chosen

Three places, most-specific-wins, mirroring how model selection already resolves:

1. **Per chat** — a new `aic_thinking_level` (`varchar(10)`, default `off`) on
   `AiConversation`, next to `aic_data_access` / `aic_web_search`. `ChatRunner`
   reads it and passes it to `AgentLoop`.
2. **Per recipe** — a new `rcp_thinking_level` (`varchar(10)`, default `off`) on
   `Recipe`, next to `rcp_model` / `rcp_max_iterations`. `RecipeRunner` reads it.
3. **Global default** — a new setting `joinery_ai_default_thinking_level`
   (default `off`) in `plugin.json`, used when a chat/recipe has no explicit
   value (e.g. a chat created before this lands, or a recipe left at the default).

Resolution: the row's own value if set, else the global default, else `off`.

### UI

- **Chat** — a small labeled `<select>` (Off / Low / Medium / High) in the status
  strip beside the Data access and Web search toggles. On an existing chat a
  change persists immediately through the existing capability-update endpoint
  (`chat_set_capabilities.php`, extended to accept `thinking_level`); on a new
  chat the selected value rides along with the first `chat_send`, exactly like the
  capability toggles do today. No explainer prose — the four labels are
  self-describing.
- **Recipe edit** — a matching Off/Low/Medium/High dropdown beside the model
  selector. Saved to `rcp_thinking_level`.
- **Settings** — `joinery_ai_default_thinking_level` is editable on the admin
  settings page like the plugin's other defaults.

### Token-budget interaction

Thinking tokens are **output** tokens. They therefore:

- Count against the per-turn `token_budget` enforced in `AgentLoop` (the
  output-only budget). A high thinking level on a tight budget can exhaust the
  budget on reasoning before the answer lands — so when thinking is enabled the
  provider must give the per-call `max_tokens` enough headroom for the reasoning
  budget *and* the answer, and the operator should size the turn budget
  accordingly. Document this; do not silently clamp.
- Accrue in `CostGuard` like any other output (per-run caps and the monthly
  ceiling already count output tokens) — no CostGuard change needed.
- Persist in the existing `aim_output_tokens` / `aic_total_*` / `rcr_*` token
  columns with no schema change beyond the two level columns above.

This is the lever discussed for the local-model latency problem: on Qwen3 the
default `off` keeps tool-using turns from spending tens of seconds on discarded
`<think>` output, while `high` stays available for a genuinely hard ask.

## What does NOT change

- The canonical request/response contract except the additive `thinking` key.
- `AgentLoop`'s loop logic, the risk heuristic / confirmation boundary, the
  capability toggles, lazy model discovery, and reader-mode fetch.
- The displayed answer: providers already discard reasoning text; this controls
  whether it is generated, not whether it is shown.

## Security & cost

- **No new trust surface.** Thinking output is model-internal and already
  stripped from the answer; nothing new is shown to the user or fed to tools.
- **Cost is bounded by existing guards.** More thinking = more output tokens =
  more time and (on a paid provider) more spend, all already metered by
  `CostGuard` and the per-turn budget. The control makes the cost a deliberate
  choice rather than the model's whim.

## Out of scope

- **Showing the reasoning to the user.** The `<think>` trace stays discarded; a
  "show thinking" disclosure is a separate later enhancement.
- **Streaming reasoning tokens** (SSE) — tied to the async-turns work, not here.
- **Auto-selecting the level** from the prompt's difficulty — always operator-set
  for v1.
- **A raw token-budget field** in the UI — the four-level ladder is the surface;
  the per-provider token mapping is internal.

## Implementation outline

1. Add `aic_thinking_level` to `AiConversation` and `rcp_thinking_level` to
   `Recipe` (`varchar(10)`, default `off`); add
   `joinery_ai_default_thinking_level` to `plugin.json` (default `off`).
2. Add `string $thinking_level = 'off'` to `AgentLoop::run()`; when not `off`, set
   `$params['thinking'] = ['level' => $thinking_level]`.
3. `ChatRunner` and `RecipeRunner`: resolve level (row value → global default →
   `off`) and pass it to `AgentLoop::run()`.
4. `AnthropicProvider::createMessage()`: map level → `thinking` field + raise
   `max_tokens` for the budget; `off` omits it.
5. `OpenAiCompatibleProvider::createMessage()`: map level → native `think` /
   `reasoning_effort` when available, else `/think` ÷ `/no_think` prompt control.
6. Chat UI: Off/Low/Medium/High select in the status strip; persist via
   `chat_set_capabilities.php` (existing chat) or the first `chat_send` (new chat).
7. Recipe-edit UI: matching dropdown beside the model selector → `rcp_thinking_level`.
8. Run `php -l` + `validate_php_file.php` on every modified PHP file; bump the
   plugin version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md`: document the
thinking-level ladder and where it is set (chat, recipe, global default) in the
Chat and provider sections, and note the token-budget interaction. Describe only
the end state, per the docs rule.
