# Joinery AI: Add Fireworks as an Inference Provider

Add Fireworks AI as a third inference provider for `joinery_ai`, sitting between
the local Ollama host (private, free, low quality) and Anthropic Claude (remote,
priced, highest quality). Fireworks gives mid-tier quality (70B-and-up) at
per-token serverless pricing, with a no-train posture on open models.

Plus a chat-only, **real-time sensitivity warning**: while a user composes a chat
message, if the text looks like it contains personal data *and* the selected
model's provider is **not private**, a banner appears live in the composer. It is
passive — it never blocks sending and requires no click or confirmation; it shows
and hides as the text and model selection change. It does not apply to recipes.

> **Verify before merge:** Fireworks pricing, model IDs, and API paths move
> frequently. Every price and model string in this spec is *indicative* — confirm
> against the live Fireworks catalog and docs before committing. See the checklist
> at the end.

---

## Decisions already made (do not relitigate)

- **Reuse the OpenAI-compatible adapter, don't write a bespoke client.** Fireworks
  speaks the OpenAI `/chat/completions` wire format, which `OpenAiCompatibleProvider`
  already translates to/from the canonical (Anthropic-shaped) IR. Fireworks rides
  that engine; the vendor-specific bits (id, model catalog, pricing, reasoning
  control, error text) are extracted into overridable seams.
- **Routing stays model-ID-driven.** The model ID already implies its provider
  (`claude-*` → Anthropic, everything else → local). Add a third branch:
  Fireworks model IDs are namespaced `accounts/fireworks/models/...`, so they route
  to Fireworks; anything else still falls through to local.
- **No hard privacy gate, no new DB column, no per-request sensitivity tag.** The
  privacy control is the existing manual model choice. The only addition is a
  passive advisory banner in the chat composer. Recipes are out of scope — they're
  configured deliberately at creation time, so the person picking a model for a
  recipe has already made the call.
- **The gate is "is this provider private?", not "is it remote?".** Privacy is a
  property each provider declares, not a function of where it runs. Local Ollama is
  private (your hardware); Fireworks is private (no-train contract on open models);
  Anthropic is not. The warning fires only when the selected model's provider is
  **not** private. A future remote-but-private provider will declare itself private
  and automatically suppress the warning — no per-provider special-casing in the UI.
- **The warning is chat-only, real-time, and client-side.** The heuristic needs the
  live message text, which only exists while composing a chat message. It runs as
  the user types. Recipes run asynchronously with no human watching, so there is
  nowhere to show it — recipes get nothing.

---

## Privacy posture (informs labels and warning copy)

Tiers, classified by **privacy** (which is what the warning gates on), not by
network location:

- **Local Ollama (Mac Mini) — private.** The floor. On your own hardware; nothing
  leaves the device.
- **Fireworks (this work) — private.** Remote, but a vetted third party with a
  no-train promise on open models (SOC 2 Type II, HIPAA, TLS in transit, AES-256 at
  rest), running on Fireworks' own infrastructure — *not* a router to an upstream
  vendor. "Zero retention" means not retained for training; inputs may be
  transiently processed and may be retained if an abuse classifier fires. Good for
  work that needs quality above the local 8B (general reasoning, RAG over
  non-sensitive docs, synthetic data generation). Because it's classified private,
  it does **not** trigger the warning.
- **Anthropic Claude — not private.** Remote, highest quality, used where its edge
  justifies the data exposure. Triggers the warning when the text looks sensitive.

A further remote-but-private provider is planned; it will be classified private and
behave like Fireworks for the warning.

The warning exists so a user doesn't *accidentally* paste identifying data into a
non-private model. It is a nudge, not a wall.

---

## Workstream 1 — Generalize the OpenAI-compatible provider, add Fireworks

### 1a. Extract vendor seams in `OpenAiCompatibleProvider`

File: `plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php`

The class currently hardcodes local-only behavior. Pull the vendor-specific
pieces into `protected` methods so a subclass can override them, keeping all the
wire translation (`toOpenAiRequest`, `appendCanonicalBlocks`, `consumeStream`,
the `<think>` filter, tool-call assembly) shared and unchanged:

- `id()` — currently returns `'local'`. Leave as the local default; Fireworks
  overrides.
- **Reasoning control.** Today `toOpenAiRequest()` appends a Qwen `/think` or
  `/no_think` token to the system text. That is Qwen-specific and would inject
  literal garbage into a GLM/Llama prompt. Extract it:
  - `protected function systemThinkingSuffix(string $level): string` — local
    returns `"/think"` / `"/no_think"`; Fireworks returns `''`.
  - `protected function applyReasoning(array &$request, string $level): void` —
    local no-op; Fireworks maps the level to the OpenAI `reasoning_effort`
    parameter (`low`/`medium`/`high`) for models that support it, and omits it for
    those that don't. **Verify** which Fireworks models accept `reasoning_effort`.
- **Unreachable message.** The `ConnectException` handler hardcodes
  "is Ollama running?". Extract `protected function unreachableMessage(): string`
  so Fireworks can say "Fireworks API not reachable …". Keep classification
  working — `LlmProviderException::classify()` keys off "not reachable"/network
  wording, so preserve that phrasing in both.
- **Cached-input usage.** In `consumeStream()`, also read
  `usage.prompt_tokens_details.cached_tokens` (the standard OpenAI field) and map
  it to `cache_read_input_tokens` in the canonical usage. Harmless for Ollama
  (never sends it); required for Fireworks' 50% cached-input discount to show up
  in cost. This goes in the **shared** method, not an override.

Behavior for the local provider must be byte-for-byte unchanged after the refactor.

### 1b. Add `FireworksProvider`

New file: `plugins/joinery_ai/includes/llm/FireworksProvider.php`, extending
`OpenAiCompatibleProvider`. Follow `AnthropicProvider` for the catalog + cost shape.

- `id()` → `'fireworks'`.
- `unreachableMessage()` → Fireworks-flavored, still containing "not reachable".
- `systemThinkingSuffix()` → `''`; `applyReasoning()` → set `reasoning_effort`
  where supported.
- **Model catalog** (curated in code, like Anthropic). Verified against the live
  Fireworks catalog and API on 2026-06-28 — three tiers, no coding bias:

  | Tier | Model ID | $/1M in→out |
  |---|---|---|
  | Cheap but good | `accounts/fireworks/models/gpt-oss-120b` | 0.15 → 0.60 |
  | Cheapish + reasoning/tools | `accounts/fireworks/models/qwen3p7-plus` | 0.40 → 1.60 |
  | Sonnet-class for less | `accounts/fireworks/models/glm-5p2` | 1.40 → 4.40 |

  Labels carry the price annotation, matching `AnthropicProvider::MODELS` style.
  (The earlier draft listed GLM 4.6 and Llama 3.3 70B; both were stale by mid-2026
  — GLM had moved to 5.x and Llama 3.3 was outclassed. The current open-weight
  frontier is overwhelmingly Chinese-lab; the only strong Western open weights on
  Fireworks are gpt-oss and Nemotron, so gpt-oss carries the provenance role.)
- `defaultModel()` → the value default (gpt-oss-120B).
- `estimateCost(string $model, array $usage): float` — per-model in/out pricing
  from the catalog; bill `cache_read_input_tokens` at 50% of the input rate. Return
  `0.0` for an unknown model rather than throwing.

### 1c. Construction

The base constructor is `($base_url, $model, $api_key, $timeout, $http)`. Fireworks
is multi-model, so the "current model" is whatever the caller routes to. Mirror how
the factory builds the local provider — pass the resolved model in, or have the
subclass default `model` from `defaultModel()` when none is given. Keep the Guzzle
client injectable for tests.

---

## Workstream 2 — Factory routing & settings

### 2a. `LlmProviderFactory`

File: `plugins/joinery_ai/includes/llm/LlmProviderFactory.php`

- `forModel()` — add the Fireworks branch *before* the local fallback:
  - `claude*` → `anthropic()`
  - Fireworks-owned ID (matches `accounts/fireworks/`) → `fireworks()`
  - else → `local()`
  Express ownership with a small predicate (a static `FireworksProvider::owns($id)`
  or an inline prefix check) so the rule lives in one place.
- `build()` — accept `'fireworks'` as a value of `joinery_ai_llm_provider` and
  return `fireworks()`.
- `allModels()` — when the Fireworks API key is set, merge in
  `fireworks()->models()` (alongside the existing Anthropic/local blocks).
- New `private static function fireworks(): LlmProviderInterface` — read
  `joinery_ai_fireworks_api_key` and `joinery_ai_fireworks_base_url` (default
  `https://api.fireworks.ai/inference/v1`); throw `LlmProviderException` with a
  clear "set the Fireworks API key" message when the key is empty and a Fireworks
  model is in use, matching how `local()` throws on a missing model.

### 2b. Settings

Files: `plugins/joinery_ai/plugin.json` (declarations + factory defaults) and
`plugins/joinery_ai/settings_form.php` (admin fields).

Add:

- `joinery_ai_fireworks_api_key` — password, default `''`.
- `joinery_ai_fireworks_base_url` — text, default
  `https://api.fireworks.ai/inference/v1`.
- Extend the `joinery_ai_llm_provider` select options to include `fireworks`
  alongside `anthropic` and `local`.

Render the two Fireworks fields in `settings_form.php` next to the Anthropic key,
following the existing password/text field pattern. No per-model setting — the
catalog lives in `FireworksProvider`, so the recipe/chat dropdown surfaces the
models automatically once the key is set. The key is a secret: never echo it, load
it only via the settings singleton (same as the Anthropic key).

---

## Workstream 3 — Chat-only sensitivity warning

A passive banner in the chat composer that appears in real time while the user
types, whenever the message text looks like it contains personal data *and* the
selected model's provider is **not private**. No click, no confirmation, no
"send anyway" — it is purely informational and never interrupts sending. It hides
again automatically when the text stops matching or a private model is selected.
Implemented client-side (text and model are both in the DOM as the user types).

File: `plugins/joinery_ai/views/admin/chat.php` (composer markup + inline JS).

### 3a. Provider privacy as a first-class property

Add `isPrivate(): bool` to `LlmProviderInterface` and implement it:

- `OpenAiCompatibleProvider::isPrivate()` → `true` (local Ollama). `FireworksProvider`
  inherits `true` (no-train private remote).
- `AnthropicProvider::isPrivate()` → `false`.

This is the single source of truth for the privacy gate, and the next remote-private
provider just returns `true`.

### 3b. Tell the client which models are private

Classification stays server-authoritative — JS must not guess from ID patterns.
Have the chat logic build a per-model privacy map by asking the factory
(`LlmProviderFactory::forModel($id)->isPrivate()`, or add a
`LlmProviderFactory::modelPrivacy(): array` returning `[id => bool]`). Render
`data-private="1"` on the `<option>` for each private model. The warning condition
is simply: selected option **lacks** `data-private`.

### 3c. The heuristic (in the chat JS)

A small scoring function over the textarea string. Fire on any strong signal, or two
or more weak ones. Indicative signals:

- **Strong:** US SSN (`\b\d{3}-\d{2}-\d{4}\b`); credit-card-like 13–16 digit
  groups passing a Luhn check; email-header lines (`/^(From|To|Subject|Date):/m`,
  catches pasted email).
- **Weak:** email address; phone number (NANP-ish); street address (number +
  street-type suffix like St/Ave/Rd/Blvd/Dr/Lane).

It will have false positives and misses — fine, because it only warns. Keep the
pattern set small and readable; this is the one piece of logic that lives in JS.

### 3d. Wire it as a live re-evaluation

Add an `input` listener on the textarea and a `change` listener on the model select
that both call one `updateSensitivityNotice()` function:

1. Read the selected option; treat it as non-private if it lacks `data-private`.
2. If non-private **and** the heuristic matches the current textarea text → show the
   banner; otherwise hide it.
3. The banner names the selected model and states plainly that this provider is not
   private and will process the text off-device. No buttons.

No ack state, no persistence, no DB column — the banner is a pure function of
(current text, selected model) and re-renders on every keystroke and model change.
`send()` is untouched. Style with existing `joai-` classes; vanilla JS/CSS only
(admin theme is HTML5/vanilla — no frameworks).

---

## Out of scope

- Recipe-side warnings or any recipe routing changes (recipes are deliberate at
  creation time).
- A request-level `sensitive` tag, a "keep local" lock, or any hard provider gate.
- Any confirmation/acknowledgement step — the banner is passive and never blocks.
- Server-side sensitivity scanning or new DB columns.
- Fine-tuning, batch, or Fireworks-specific non-chat endpoints.

---

## Files touched

| File | Change |
|---|---|
| `includes/llm/OpenAiCompatibleProvider.php` | Extract reasoning / unreachable-message / id seams; read cached-token usage. Local behavior unchanged. |
| `includes/llm/FireworksProvider.php` | **New.** Catalog, pricing, reasoning_effort, Fireworks error text. |
| `includes/llm/LlmProviderFactory.php` | Route Fireworks IDs; `build()`/`allModels()` aware of Fireworks; `fireworks()` builder. |
| `plugin.json` | Settings: `joinery_ai_fireworks_api_key`, `joinery_ai_fireworks_base_url`; add `fireworks` to provider select. |
| `settings_form.php` | Render the two Fireworks fields + the new provider option. |
| `includes/llm/LlmProviderInterface.php` | Add `isPrivate(): bool`. |
| `includes/llm/AnthropicProvider.php` | `isPrivate()` → `false`. |
| `views/admin/chat.php` | `data-private` on model options; real-time heuristic + passive banner (no `send()` change). |
| `logic/admin_chat_logic.php` | Pass per-model privacy flag to the view. |
| `docs/overview.md` (joinery_ai) | Document the Fireworks provider, the privacy-tier split, and the chat warning (current-state only). |

`OpenAiCompatibleProvider` and `FireworksProvider` also implement `isPrivate()`
(both `true`) per Workstream 3a.

No migrations (settings come from `plugin.json`; no schema changes). No changes to
`AgentLoop`, `RecipeRunner`, or `ChatRunner`.

---

## Verification checklist

- [x] Confirm the Fireworks base URL and `/chat/completions` path against live docs.
- [x] Confirm exact model IDs and current in/out prices for every catalogued model.
      (gpt-oss-120b, qwen3p7-plus, glm-5p2 — verified 2026-06-28.)
- [x] Confirm which catalogued models accept `reasoning_effort`; all three do via the
      uniform Fireworks knob. gpt-oss is always-on and rejects `none`, so `off` maps
      to `low` there (`REASONING_NO_OFF`); Qwen/GLM take `none`. No `/think` token on
      Fireworks. Verified live (gpt-oss off→low fixed after a 400 on `none`).
- [ ] Confirm the no-train default applies to the account and nothing is opted into
      logging/training. *(account-side; owner to confirm in the Fireworks console.)*
- [x] Streaming (SSE) parses correctly for the chosen models, including reasoning —
      answer text preserved at thinking off and high (live test, all three models).
- [x] Cached-input tokens (`prompt_tokens_details.cached_tokens`) are captured and
      cost reflects the 50% discount (unit-tested; live gpt-oss showed cachedRead=3).
- [x] Factory routing: a `accounts/fireworks/...` ID resolves to Fireworks; `claude-*`
      still → Anthropic; an unknown/local ID still → local. Missing key → clear error.
- [x] Local provider behavior unchanged after the seam refactor (suffix `/no_think`↔`/think`
      and `isPrivate()` true verified; wire translation untouched).
- [x] Chat banner appears/clears in real time as text and model change; fires only
      for **non-private** models (Anthropic, not Fireworks/local) with
      sensitive-looking text; never blocks and needs no confirmation. (Browser-tested.)
- [x] `FIREWORKS_API_KEY` is never logged or echoed; loaded only via settings.
- [x] End-to-end smoke test: GLM 5.2 answered a real chat turn through the async UI
      pipeline (conversation persisted `aic_model = …/glm-5p2`).
- [ ] `php -l` and `validate_php_file.php` clean on every changed PHP file.
