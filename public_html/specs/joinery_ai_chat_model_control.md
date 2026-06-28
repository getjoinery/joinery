# Joinery AI — Chat model control

**Status:** Active — awaiting implementation
**Plugin:** `joinery_ai`
**Touches:** `AiConversation`, `Recipe`, the canonical LLM request, both providers,
`AgentLoop`, `ChatRunner`, `RecipeRunner`, the chat status strip + composer JS,
the recipe-edit form, `chat_set_capabilities`, plugin settings.
**Consolidates:** the standalone thinking-level spec
(`joinery_ai_thinking_level.md`), folded in as the **Thinking level** knob below.

## Goal

Give the operator the controls a mature chat client has for steering the model,
without changing what the model can *do*. Today the chat surface lists the model
as static text and exposes only two capability toggles; everything else — which
model answers, how creative it is, how long a reply may run, how hard it thinks,
and any standing instructions for the chat — is fixed by code and settings.

In plain terms: a small **model-control panel** on each chat that lets you pick
the model, set its temperature, cap a reply's length, give the chat standing
instructions, and choose how hard it reasons — each one remembered per chat and
honored the same way whatever model is behind it. Recipes get the same knobs so
a scheduled run and an interactive chat are steered identically.

This is the "Model control" cluster from the Chatbox-parity inventory. The
backend already routes a provider-independent request through one boundary
(`LlmProviderInterface::createMessage()`); these controls all live at that
boundary, set once and translated per provider — so a knob added for Qwen today
is honored by Claude tomorrow with no rework.

## The knobs (one inventory, one pattern)

Five per-conversation controls. Four are new; **Model** already has a column and
just lacks a picker. They all follow the **same resolution and persistence
pattern** (next section), so we decide that pattern once and apply it five times
rather than re-solving plumbing per knob.

| Knob | What it does (plain) | Chat column | Recipe column | Canonical param | Resolution |
|---|---|---|---|---|---|
| **Model** | Which model answers — and, implicitly, which provider | `aic_model` *(exists)* | `rcp_model` *(exists)* | `model` *(exists)* | row → provider default |
| **Temperature** | How creative vs. deterministic the wording is | `aic_temperature` | `rcp_temperature` | `temperature` *(new)* | row → setting → provider default (omit) |
| **Max tokens** | The longest a single turn may run before it's cut off | `aic_max_tokens` | `rcp_max_tokens` *(exists)* | per-turn `$token_budget` *(exists)* | row → setting `joinery_ai_chat_max_tokens` |
| **Instructions** | Standing instructions for this chat (persona / context) | `aic_instructions` | `rcp_prompt` *(exists)* | appended `system` block *(exists shape)* | row value or none |
| **Thinking level** | How hard the model reasons before answering: off / low / medium / high | `aic_thinking_level` | `rcp_thinking_level` | `thinking` *(new)* | row → setting `joinery_ai_default_thinking_level` → `off` |

**Why these columns and not a single JSON blob:** each knob has its own type,
its own validation, and its own resolution default; flat columns let
`update_database` enforce types and keep the recipe-edit FormWriter inputs
trivial. The set is small and closed — the up-front inventory above is the whole
surface, not a growing bag.

## The shared pattern

Every knob resolves and persists the same way, mirroring how `aic_model` and the
capability toggles already work:

1. **Resolution — most-specific-wins.** The conversation's own value if set, else
   the plugin-setting default, else a hardcoded floor. `ChatRunner::drive()` and
   `RecipeRunner` already resolve `model`, `max_iterations`, and `token_budget`
   exactly this way (`ChatRunner.php:150`, `RecipeRunner.php:83`); the new knobs
   slot into the same block.
2. **Persistence — existing chat vs. new chat.** On an existing chat a change
   persists immediately through `chat_set_capabilities` (extended to accept the
   new fields — see below). On a brand-new chat (no id yet) the chosen values
   ride along with the first `chat_send`, exactly as `data_access` / `web_search`
   do today (`chat.php:204`, `chat_send`).
3. **Pass-through — runner → loop → provider.** The runner reads the resolved
   value and hands it to `AgentLoop::run()`, which folds it into the canonical
   `$params` it already assembles (`AgentLoop.php:85`). Each provider translates
   canonical → its own wire format inside `createMessage()`; the loop never
   branches on provider.

### Extending the persistence endpoint

`chat_set_capabilities.php` today flips one boolean from a two-entry `$column_map`.
Generalize it to a per-chat **settings** writer that validates each field by
type, then writes it to the conversation row:

- `model` (string) — must be a key of `LlmProviderFactory::allModels()`, else reject.
- `temperature` (float, 0.0–2.0) — clamp/validate; empty string clears to NULL.
- `max_tokens` (int, ≥ 1000) — empty clears to NULL (fall back to the setting).
- `instructions` (text, ≤ a sane cap e.g. 8000 chars).
- `thinking_level` (enum `off|low|medium|high`).
- `data_access` / `web_search` (bool) — unchanged.

Ownership and permission checks are unchanged (`aic_owner_user_id === uid`,
permission ≥ 5). New chats keep seeding their initial state on the first
`chat_send`, which gains the same fields alongside `data_access` / `web_search`.

## Canonical request additions

`AgentLoop::run()` assembles `['model','max_tokens','system','messages','tools']`
today (`AgentLoop.php:85`). Two additive keys; everything else is reuse:

- **`temperature`** — `AgentLoop::run()` gains `?float $temperature = null`; when
  non-null it sets `$params['temperature']`. Null omits the key (no behavior
  change for the default path). Both providers already pass `$params` straight to
  their wire payload, so each just forwards `temperature` when present
  (Anthropic and the OpenAI-compatible host both accept it natively).
- **`thinking`** — `AgentLoop::run()` gains `string $thinking_level = 'off'`; when
  not `off` it sets `$params['thinking'] = ['level' => $thinking_level]`. Provider
  translation is detailed under **Thinking level** below.

**Max tokens** needs no new canonical key: it *is* the existing per-turn
`$token_budget` the loop already enforces. The per-chat `aic_max_tokens`
(or `rcp_max_tokens`, which already does this) simply overrides the
`joinery_ai_chat_max_tokens` setting in the runner's resolution step. This is the
"Chat max tokens" limit the `stopReasonNote()` text already refers to
(`ChatRunner.php:131`).

**Instructions** needs no new canonical key either: `ChatRunner::buildSystemPrompt()`
already composes the `system` array. The per-chat instructions become **one
additional system block appended after** the built-in scaffolding — never a
replacement for it. The tool framing, untrusted-input markers, and model-schema
blocks stay authoritative; the operator's text is layered on as standing
guidance. (Recipes already work this way: `rcp_prompt` is appended as
"## Recipe instructions" in `RecipeRunner::buildSystemPrompt()`.) Replacing the
scaffolding would strip the safety framing, so augment is the only correct layer.

## Model picker (the one that's purely UI)

The backend already stores `aic_model` per conversation and
`LlmProviderFactory::allModels()` already returns `[id => label]` across every
configured provider — the recipe-edit form uses exactly this dropdown
(`edit.php:145`). The chat surface just never grew the control: it renders
`active_model` as a static `<span>` (`chat.php:59`).

- Replace that span with a labeled `<select>` populated from
  `LlmProviderFactory::allModels()`, current value `aic_model` (empty ⇒ the
  provider default, shown selected). On change, persist through the extended
  endpoint; on a new chat the value rides the first send.
- **No separate provider picker.** The model id implies its provider
  (`claude-*` → Anthropic, anything else → the local host —
  `LlmProviderFactory::forModel()`), so picking the model picks the provider
  automatically. A second control would be redundant and could contradict the
  model. Surface this in the label/helptext as "Model (provider follows the
  model)".
- If a stored model's provider isn't configured, preserve and flag it rather than
  silently overwrite — same treatment the recipe form already gives an
  unavailable model (`edit.php:152`).

## UI layout

Keep the frequently-touched controls inline and tuck the rest behind a
disclosure so the strip stays uncluttered (per the self-documenting-pages rule —
guided controls, no explainer prose):

- **Status strip (always visible):** Model `<select>` · Data access · Web search ·
  Thinking `<select>` (Off / Low / Medium / High). These are the per-turn,
  glance-and-change controls.
- **"⚙ Settings" disclosure (collapsed by default):** Temperature (number or
  slider, 0–2), Max tokens (number, blank = use the default), and Instructions
  (textarea). These are set-once-and-forget, so they don't earn permanent strip
  space.
- Every control persists through the same endpoint on change (existing chat) or
  rides the first `chat_send` (new chat). The four labels of the thinking ladder
  and the model labels are self-describing; helptext stays minimal.

**Recipe-edit parity:** the recipe form already has Model and Max tokens. Add
Temperature and a Thinking-level dropdown beside the model selector; the
"editable instructions" knob is the existing `rcp_prompt` body, so nothing new
there. Same FormWriter `dropinput` / numeric-input patterns already in `edit.php`.

## Thinking level (consolidated from `joinery_ai_thinking_level.md`)

Let the operator choose **how much the model reasons before it answers** — from
none to a lot. Reasoning-capable models (Qwen3 locally today, Anthropic extended
thinking if the provider switches) can spend extra output generating a private
chain of thought before the real answer. That trade is sometimes worth it (a hard
multi-step ask) and usually not (a quick lookup or tool call). On the local 14B
model the cost is wall-clock seconds the user waits through; on a paid API it is
output tokens. The reasoning is then silently discarded from the displayed answer
after the time and tokens are already spent — so an off-by-default knob that says
"think hard / a little / don't think, just answer" is a real latency and cost
lever, not cosmetics.

### The level ladder

A provider-agnostic enum — the same four values everywhere a level is chosen:

- **`off`** — no reasoning; answer directly. The snappy default.
- **`low`** — a little reasoning.
- **`medium`** — moderate reasoning.
- **`high`** — extensive reasoning.

Four levels, not a raw token number, because the user is choosing *intent*; the
right token figure differs per model and the provider maps intent → mechanism.
`off` and "on" are the universal floor every reasoning model honors; the graded
levels are best-effort — a provider that only supports on/off collapses
`low|medium|high` to "on".

### Provider translation

Each provider owns its mapping inside `createMessage()`, reading
`$params['thinking']`:

- **`AnthropicProvider`** — emit the `thinking` request field
  `{ type: "enabled", budget_tokens: N }`, level mapped to a budget
  (`low ≈ 1024`, `medium ≈ 4096`, `high ≈ 12000`). `off` omits the field.
  **Constraint:** Anthropic requires `max_tokens > budget_tokens`, so when
  thinking is enabled the provider must raise the per-call `max_tokens`
  (`PER_CALL_MAX_TOKENS`) to leave room for the budget *plus* a real answer.
- **`OpenAiCompatibleProvider`** (Ollama / Qwen3) — prefer the server's native
  reasoning switch when present (Ollama's `think` option / a `reasoning_effort`
  field); always fall back to the prompt-control method Qwen3 honors reliably:
  append `/think` or `/no_think` to the request. Map `off → /no_think`;
  `low|medium|high → /think`. The provider already strips `<think>…</think>` from
  the returned text, so the *displayed* answer is unchanged — this governs only
  whether those tokens are **generated**.

### Token-budget interaction

Thinking tokens are **output** tokens. They count against the per-turn
`$token_budget` the loop enforces and accrue in `CostGuard` like any other output
(no CostGuard change). A high level on a tight budget can exhaust the budget on
reasoning before the answer lands — so when thinking is enabled the provider must
give the per-call `max_tokens` headroom for the reasoning budget *and* the
answer, and the operator should size the turn's **Max tokens** knob accordingly.
Document this; do not silently clamp. Tokens persist in the existing
`aim_output_tokens` / `aic_total_*` / `rcr_*` columns — no schema change beyond
the level column.

## Schema changes

On `AiConversation` (`aic_conversations`), beside `aic_data_access` /
`aic_web_search`:

```php
'aic_temperature'    => array('type'=>'numeric(3,2)'),            // NULL = provider default
'aic_max_tokens'     => array('type'=>'int4'),                    // NULL = use the setting
'aic_instructions'   => array('type'=>'text'),                    // appended system block
'aic_thinking_level' => array('type'=>'varchar(10)', 'default'=>'off'),
```

On `Recipe` (`rcp_recipes`), beside `rcp_model` / `rcp_max_tokens`:

```php
'rcp_temperature'    => array('type'=>'numeric(3,2)'),
'rcp_thinking_level' => array('type'=>'varchar(10)', 'default'=>'off'),
```

Plugin settings in `plugin.json` (factory defaults, seeded automatically — no
migration): `joinery_ai_default_thinking_level` (default `off`) and
`joinery_ai_default_temperature` (default empty = provider default). The existing
`joinery_ai_chat_max_tokens` stays the max-tokens default. `update_database` adds
the columns from the field specs.

## What does NOT change

- The canonical request/response contract except the additive `temperature` and
  `thinking` keys.
- `AgentLoop`'s loop logic, the risk heuristic / confirmation boundary, the
  capability toggles, lazy model discovery, reader-mode fetch, and CostGuard.
- The model→provider routing (`forModel()` / `build()`), unchanged — the picker
  just exposes the existing `aic_model` value.
- The displayed answer: providers already discard reasoning text; the thinking
  knob controls whether it is generated, not whether it is shown. The instructions
  block augments, never replaces, the safety scaffolding.

## Security & cost

- **No new trust surface.** The instructions block is operator-authored and
  appended *after* the untrusted-input markers, so it can't be used to smuggle
  authority to tainted content. Thinking output is model-internal and stripped.
  The model picker only chooses among already-configured providers.
- **Cost stays bounded by existing guards.** Higher temperature, longer max
  tokens, and more thinking all mean more output tokens — already metered by the
  per-turn budget and `CostGuard`'s per-run and monthly ceilings. These controls
  make cost a deliberate choice rather than the model's whim.

## Out of scope

- **Streaming, stop / regenerate / edit-resend, copy buttons, syntax
  highlighting** — the interaction-polish cluster; streaming is specced
  separately in `joinery_ai_chat_streaming.md`.
- **Conversation management** (rename / delete / pin / search / export),
  **attachments / multimodal**, **rich rendering** (LaTeX / Mermaid / artifacts),
  and **saved personas / prompt templates** — later parity clusters. Note: saved
  personas would build naturally on the per-chat **Instructions** knob landed here
  (a saved persona is a named, reusable instructions blob), but the reuse layer
  is its own spec.
- **Showing the reasoning trace** to the user, **auto-selecting** the thinking
  level from prompt difficulty, and a **raw token-budget field** for thinking
  (the four-level ladder is the surface) — all deferred.
- **Per-message parameter overrides** — parameters are per-conversation (and
  per-recipe) for v1, not per-turn.

## Implementation outline

1. Add the four columns to `AiConversation` and the two to `Recipe`; add
   `joinery_ai_default_thinking_level` and `joinery_ai_default_temperature` to
   `plugin.json`. Run `update_database` / plugin sync.
2. `AgentLoop::run()`: add `?float $temperature = null` and
   `string $thinking_level = 'off'`; set `$params['temperature']` /
   `$params['thinking']` only when present/non-`off`.
3. `AnthropicProvider` / `OpenAiCompatibleProvider` `createMessage()`: forward
   `temperature`; translate `thinking` (Anthropic budget field + `max_tokens`
   headroom; Ollama native switch or `/think`÷`/no_think`).
4. `ChatRunner::drive()` and `RecipeRunner`: resolve each knob (row → setting →
   floor) and pass `temperature`, `thinking_level`, the overridden
   `$token_budget`, and the appended instructions block to `AgentLoop::run()`.
5. Generalize `chat_set_capabilities.php` to validate and write `model`,
   `temperature`, `max_tokens`, `instructions`, `thinking_level` (plus the
   existing bools); extend `chat_send` to seed the same fields on a new chat.
6. Chat UI: model `<select>` + thinking `<select>` in the status strip; a
   "⚙ Settings" disclosure with temperature / max tokens / instructions; wire
   each to the endpoint (existing chat) or the first send (new chat).
7. Recipe-edit UI: add Temperature and Thinking-level controls beside the model
   selector (instructions already = `rcp_prompt`).
8. Run `php -l` + `validate_php_file.php` on every modified PHP file; bump the
   plugin version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice, per the docs rule): document the model-control panel and each knob, where
each is set (chat status strip / settings disclosure, recipe edit, global
default), the model→provider implication, the instructions-block augmentation
order, and the thinking-level ladder with its token-budget interaction. Add
`temperature` and `thinking` to the canonical-request / provider-interface
description.
