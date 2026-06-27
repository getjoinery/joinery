# Joinery AI — chat capabilities + lazy model discovery

## Goal

Two related changes to what an AI chat can touch and how much it carries in
context:

1. **Per-chat capability toggles.** A new conversation is a plain conversational
   assistant. The admin flips on **Data access** (read/write/act on site data)
   and **Web search** (search/fetch/market data) **independently, per chat**, the
   way you'd expect a feature switch to work. Both default **off**.

2. **Lazy model discovery.** When Data access *is* on, stop dumping every
   readable model's full field schema into the prompt. The prompt carries only a
   one-line **name catalog**; the assistant pulls a specific model's fields with
   a `describe_models` tool when it actually needs to query that model. This
   applies to **recipes too**, not just chat.

Together: a default chat ships **no** model information at all; a data-enabled
chat ships a short catalog and fetches schemas on demand. The ~4k-token
every-turn schema dump goes away in both the common case (off) and the enabled
case (lean).

In plain terms: most chats don't need to touch your data, so they shouldn't pay
to carry its manual around. The few that do get a one-line table of contents and
open only the page they need.

---

## Part 1 — Per-chat capability toggles

### Behavior

Two independent switches on a conversation, **both default off**:

- **Data access** — when on, the assistant can read, write, and act on platform
  data: `query_model`, `describe_models`, `create_model`, `update_model`,
  `delete_model`, `invoke_action`, `describe_actions`, and the note tools
  (`get_my_notes`, `save_note`). All `$ai_readable` models come into scope. When
  off, none of those tools are offered and **no model information enters the
  prompt at all**. (Writes remain gated by the confirmation boundary regardless —
  this toggle controls *whether the tools exist*, not whether writes skip
  confirmation.)
- **Web search** — when on, the web tool group is offered: `web_search`,
  `fetch_url`, `get_stock_data`. When off, none are. `web_search` additionally
  requires the global `joinery_ai_brave_search_api_key`; with the toggle on but
  no key, the tool is withheld and the UI shows it as unavailable (the toggle is
  about *intent*, the key about *capability*).

With both off (the default), the chat is a pure conversational assistant — no
tools, no data, no model catalog.

### Data model

Replace the conversation's three list columns (added in the chat phase but never
the right control for an on/off feature) with two booleans:

- **Remove** `aic_allowed_tools`, `aic_allowed_models`, `aic_allowed_actions`.
- **Add** `aic_data_access` (`bool`, default `false`) and `aic_web_search`
  (`bool`, default `false`).

The effective tool list and model scope for a turn are **derived at runtime**
from these two flags (plus the Brave key for `web_search`) — `ChatRunner`
computes them; there is no stored list to drift. (Pre-launch, no data to migrate;
orphan columns from the removed fields can be dropped directly.)

`ToolContext::allowedModels()` / `allowedActions()` stay as-is — for chat they
return all readable models / all agent-callable actions when Data access is on,
and `[]` when off. Recipes are unaffected (they keep `rcp_allowed_*`).

### Surface

- **Two toggle switches** in the chat view, in the status strip area, labelled
  *Data access* and *Web search*, reflecting the conversation's flags. The strip
  keeps showing the active model.
- **New chat:** toggles render in their default (off) state and are editable
  before the first message; their state is sent with the first `chat_send` and
  stored on the conversation the send creates.
- **Existing chat:** flipping a switch calls a small AJAX endpoint
  (`chat_set_capabilities.php` — conversation id, which flag, on/off) that
  updates the row and returns the new state. Takes effect on the next turn.
- **Web-search switch** is disabled with a hint when the Brave key isn't
  configured.

### Why booleans, not the allowlists

An on/off feature is a boolean. The earlier per-conversation allowlists modelled
"which specific models/tools," which is a *fine-grained scoping* concern, not the
*is this capability on* concern the user actually reaches for. Per-model
narrowing can return later as an optional layer behind the Data-access switch;
v1 doesn't need it.

---

## Part 2 — Lazy model discovery (platform-wide)

Only relevant when a surface has model reads at all (Data access on for chat; a
non-empty `rcp_allowed_models` for a recipe). It changes *how the schema reaches
the model*, never what it can read or how reads are authorized.

### A `describe_models` tool

New read tool (`recipe_tools/DescribeModelsTool.php`, `RecipeToolInterface`):

- **`describe_models(models?: string[])`**
  - No argument → the in-scope **catalog**: each model's class name +
    `$ai_description`, one line each.
  - A list of names → the **full field schema** for each named model (the
    `### Class — desc / Fields: …` block `ModelSchemaBuilder::build()` already
    produces), restricted to the caller's scope. A name out of scope returns a
    per-name error and leaks no other model's schema.

Reads scope through `$ctx->allowedModels()`, so recipe and chat behave
identically. Pure read — no `ai_agent`, no confirmation. Reuses
`ModelSchemaBuilder::build()` verbatim (one definition of "a model's schema to
the LLM").

### The system prompt carries names, not schemas

`RecipeRunner` and `ChatRunner` change their models section from the full
per-model field dump to a **name + description catalog** plus a one-line
instruction:

```
## Data models you can read
Call describe_models(["Product"]) to see a model's fields before querying it.
Available:
  - Product — Items for sale
  - Order — Customer orders
  ... (one line each, from $ai_description)
```

The catalog stays in the cached prefix (stable per run/conversation) but is far
smaller. Empty scope → the section is omitted and `query_model` /
`describe_models` are withheld, exactly as an empty allowlist does today.

### Shared prompt assembly

The catalog renderer and the untrusted-input block are currently **duplicated**
between `RecipeRunner` and `ChatRunner` (`buildModelsBlock()` /
`buildUntrustedInputBlock()`). Factor both into one shared place
(`ModelSchemaBuilder` or a small `AiPromptBuilder`) that both runners call, so
they can't drift, and add the name-catalog renderer there alongside the existing
full-schema one.

### `describe_models` is an implied tool

Like `query_model`, it is not a user-facing checkbox. Both runners grant
`query_model` **and** `describe_models` when model scope is non-empty, and
withhold both when it's empty.

### Prompt guidance

A short instruction in both system prompts: read a model's fields with
`describe_models` before calling `query_model` on it, so the model doesn't guess
field names. `query_model`'s existing unknown-field error already nudges this;
the explicit line makes the first query land correctly more often. Worth a live
check on the local model, where tool-following is weaker — worst case is one
rejected `query_model` call that then self-corrects, not a wrong answer.

---

## Combined behavior

| Chat state | Tools offered | Model info in prompt |
|---|---|---|
| Data off, Web off (default) | none | none |
| Data off, Web on | web group | none |
| Data on, Web off | data group (read/write/act/notes) | name catalog; schemas on demand |
| Data on, Web on | data + web groups | name catalog; schemas on demand |

Recipes: unchanged controls (`rcp_allowed_*`), but a recipe with models now gets
the name catalog + `describe_models` instead of preloaded schemas.

## What does NOT change

- `query_model`, `ModelQueryExecutor`, the read security boundary — untouched.
- Write tools, the risk heuristic, the confirmation boundary — untouched; Data
  access controls tool *availability*, not whether writes confirm.
- `$ai_readable` / `$ai_description` / `$ai_excluded_fields` model-author surface
  — unchanged; `$ai_description` becomes the catalog line, so it should read as a
  useful one-liner.
- Recipe output shape — an existing recipe still runs green (regression target).

## Cost / focus impact

- **Default chat:** zero model tokens — a plain assistant.
- **Data-enabled chat / recipe:** fixed per-turn cost drops from "sum of all
  in-scope schemas" to "one line per in-scope model"; a model's full schema is
  paid once, on first use, only for models actually touched.
- **Focus:** smaller prompts keep smaller local models on-task instead of reading
  the whole catalog to answer about one model.

## Documentation updates

Fold into existing docs as current-state (no migration narration):

- `plugins/joinery_ai/docs/overview.md` — new **Chat capabilities** note (the two
  toggles, default off, what each enables); rewrite the **Generic reads** /
  system-prompt-schema sections to the name-catalog + `describe_models` model;
  add `describe_models` to the tool list as an implied tool.
- `docs/example_class.php` / model-author notes — `$ai_description` is what the
  assistant sees in the catalog; write it as a useful one-liner.

## Testing

**Capability toggles**
- New chat defaults to both off: no tools offered, no model catalog in the
  prompt, a normal conversational reply works.
- Data access on → query/describe/write/action/note tools offered and the name
  catalog appears; off → none and no catalog.
- Web search on → web tools offered; off → none. On with no Brave key → toggle
  disabled, `web_search` withheld.
- Flipping a switch on an existing chat persists and takes effect next turn.

**Lazy discovery**
- The system prompt lists model **names**, not full field lists; the models
  section scales with model *count*, not total field count.
- `describe_models()` returns one line per in-scope model; `describe_models([X])`
  returns X's full schema; an out-of-scope name errors and leaks nothing.
- End-to-end: asked about a model, the assistant calls `describe_models` then
  `query_model` and answers — verified on the local model.
- Empty scope withholds both `query_model` and `describe_models`.
- Recipe regression: an existing recipe with a small model allowlist runs green,
  output shape unchanged.

## Out of scope

- Owner-scoped reads for non-admin members (the chat-assistant spec's Phase 3).
- Per-model narrowing UI behind the Data-access switch (a future fine-grained
  layer; v1 is on/off).
- Cross-request schema caching (the registry rebuilds per request; revisit only
  if measured).
