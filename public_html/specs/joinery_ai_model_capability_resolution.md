# Joinery AI — Endpoint catalog and capability-based model resolution

**Status:** Proposed
**Plugin:** `joinery_ai`
**Touches:** `LlmProviderFactory`, `AnthropicProvider`, `FireworksProvider`,
`OpenAiCompatibleProvider`, `ChatLevel`, `RecipeRunner`, `PipelineRunner`,
`RecipeVaultScope`, `RecipeSeeder`, `recipes.json`, `plugin.json` (settings),
`data/recipes_class.php`, `data/ai_conversations_class.php`,
`views/admin/edit.php`, `logic/admin_edit_logic.php`, `logic/chat_controls_logic.php`

---

## The problem in plain terms

Today, every place the system decides which AI to use holds a **model name typed
into a database row**. Change the model — because a vendor shipped a better one,
because the local box now runs a bigger one, because a name changed — and someone
has to log into each production server and edit rows. Nothing about a model
travels with a release.

At the same time, recipes ask the wrong question. A recipe says *"use
claude-haiku-4-5"*. What it actually means is *"this is a low-stakes yes/no call
about a short piece of text; almost anything can do it"* or *"this one is
consequential, and it must not leave my hardware"*. Because it can only express
the model name, the real requirement lives in the head of whoever configured it,
and no upgrade can act on it.

This spec separates three things that are currently one string:

1. **Where inference happens** — an endpoint, with a trust class. Ships in the
   release tree, so a fleet-wide model change is a file edit and a publish.
2. **What a model is good for** — a capability tier plus flags, declared once per
   model in that same file.
3. **What a job needs** — a floor, not a name. Recipes and chats state a minimum;
   a resolver picks the cheapest model that clears it.

---

## What exists today

### Where a model id is stored — five places, all per-install database rows

| Location | What it holds | Cascades on upgrade? |
|---|---|---|
| `stg_settings.joinery_ai_llm_provider` | default provider: `anthropic` \| `fireworks` \| `local` | No |
| `stg_settings.joinery_ai_local_model` | comma-separated local model ids; first is the default | No |
| `stg_settings.joinery_ai_default_model` | model for newly created recipes | No |
| `rcp_recipes.rcp_model` | per-recipe pinned model id (column default `claude-haiku-4-5`) | No |
| `aic_conversations.aic_model` | per-chat pinned model id | No |

Declared settings seed **create-only**, so editing a default in `plugin.json`
does not change an install that already has the row. That is correct behaviour
for an operator preference — and it is exactly why model changes require
touching databases.

### Where the model catalog lives — hardcoded PHP constants

- `AnthropicProvider::MODELS`, `::COST_PER_MTOKEN`, `::MODEL_CAPABILITIES`
- `FireworksProvider::MODELS`, `::COST_PER_MTOKEN`, `::REASONING_MODELS`,
  `::REASONING_NO_OFF`
- `OpenAiCompatibleProvider::models()` — derived from splitting the
  `joinery_ai_local_model` setting on commas

These do cascade with a release, but only as a whole class file, and they are
unreachable to anything that wants to reason about a model rather than list it.

### How a model picks its provider — string sniffing

`LlmProviderFactory::routeFor()`:

```
''                        → the global default provider setting
/^claude/i                → anthropic
accounts/fireworks/...    → fireworks
anything else             → local
```

`ChatLevel::isLocalModel()` re-implements the same three rules independently.
Two copies of one decision, already drifting in shape.

### "Safe" is expressed three inconsistent ways

| Mechanism | Where | Fireworks counts as | Force |
|---|---|---|---|
| `LlmProviderInterface::isPrivate()` | chat composer | **private** (no warning) | advisory only, never a gate |
| `LlmProviderFactory::isCloudModel()` | Fortress pin, `RecipeVaultScope::assertModelAllowed()` | **cloud** | hard gate |
| Fortress chat level | `forConversation()` | rejected | hard pin to local |

So a vendor the operator deliberately trusted is simultaneously "private enough
not to warn about" and "cloud enough to be refused sealed mail". There is no way
to say *"this endpoint is safe even though it is not on my hardware"* — which is
the flexibility this spec is being asked for.

### Thinking is a knob, never a requirement

`rcp_thinking_level` / `aic_thinking_level` (`off|low|medium|high`) say **how
hard** to reason. Each provider maps it: Anthropic to `budget_tokens`, Fireworks
to `reasoning_effort` (with a per-model table of models that cannot turn it
off), the local host to qwen's `/think` token. Nothing says **whether a job needs
a model capable of reasoning at all**, so a recipe set to `high` that resolves
onto a non-reasoning model silently gets no reasoning.

### Capability today means attachments only

`modelCapabilities()` returns `['vision' => bool, 'document' => bool]`. There is
no expression of judgement quality, context size, or tool-driving ability.

### The system already admits a model id cannot travel

`RecipeSeeder::NON_TRAVELLING_FIELDS` strips `rcp_model` from anything shipped,
with the reason *"names a model the destination may not have"*. Shipped recipes
therefore arrive with **no** model and fall back to whatever the destination's
default resolves to — the requirement is lost in transit because there is
nowhere to write it down. `recipes.json` already carries `thinking_level`,
`max_tokens`, `max_iterations` and `monthly_token_cap`, so it is the natural
place for a capability floor.

### One naming hazard

`rcp_allowed_models` is **not** about LLMs. It is the list of *data model
classes* (`User`, `Product`, …) a recipe may query, consumed by `ModelRegistry`
and `ModelQueryExecutor`. Nothing in this spec may overload that word. New
surfaces use **endpoint**, **catalog model**, **tier**, and **requirement**.

### How anything cascades to production today

Files in the release tree: `settings.json`, `admin_menus.json`,
`vault_scopes.json`, `vault_consumers.json`, `storage_profiles.json`,
`direct_kinds.json`, `install_bundles.json`, `plugins/*/plugin.json`,
`plugins/joinery_ai/recipes.json`. They ship with a publish and take effect on
`utils/upgrade.php`. Database values never cascade. **A shipped manifest read at
runtime is the only mechanism that already does what is being asked for.**

---

## Design

### 1. `plugins/joinery_ai/ai_endpoints.json` — the shipped catalog

One file, read at runtime by a registry, no seeding into the database. Editing
it and publishing is how a model change reaches the fleet.

An **endpoint** is one place a request can be sent.

```jsonc
{
  "_comment": "Inference endpoints and the models each serves. Read at runtime by AiEndpointRegistry; never seeded into the database, so a model change reaches every node with a publish. See specs/joinery_ai_model_capability_resolution.md.",
  "endpoints": [
    {
      "key": "anthropic",
      "label": "Anthropic",
      "dialect": "anthropic",
      "base_url": "https://api.anthropic.com/v1/messages",
      "api_key_setting": "joinery_ai_anthropic_api_key",
      "trust": "cloud",
      "models": [
        { "id": "claude-opus-4-7",   "label": "Claude Opus 4.7",   "tier": "frontier", "thinking": "optional", "tools": true, "context": 200000,
          "attachments": { "vision": true, "document": true },
          "cost": { "input": 5.00, "output": 25.00, "cache_write": 6.25, "cache_read": 0.50 } },
        { "id": "claude-sonnet-4-6", "label": "Claude Sonnet 4.6", "tier": "frontier", "thinking": "optional", "tools": true, "context": 200000,
          "attachments": { "vision": true, "document": true },
          "cost": { "input": 3.00, "output": 15.00, "cache_write": 3.75, "cache_read": 0.30 } },
        { "id": "claude-haiku-4-5",  "label": "Claude Haiku 4.5",  "tier": "capable",   "thinking": "optional", "tools": true, "context": 200000,
          "attachments": { "vision": true, "document": true },
          "cost": { "input": 1.00, "output": 5.00, "cache_write": 1.25, "cache_read": 0.10 } }
      ]
    },
    {
      "key": "fireworks",
      "label": "Fireworks",
      "dialect": "openai",
      "base_url_setting": "joinery_ai_fireworks_base_url",
      "api_key_setting": "joinery_ai_fireworks_api_key",
      "trust": "trusted",
      "trust_note": "Contractual no-train on open-model traffic.",
      "models": [
        { "id": "accounts/fireworks/models/gpt-oss-120b", "label": "gpt-oss 120B", "tier": "capable",   "thinking": "always",   "tools": true, "context": 128000,
          "attachments": { "vision": false, "document": false },
          "cost": { "input": 0.15, "output": 0.60 } },
        { "id": "accounts/fireworks/models/qwen3p7-plus", "label": "Qwen 3.7 Plus", "tier": "standard", "thinking": "optional", "tools": true, "context": 256000,
          "attachments": { "vision": false, "document": false },
          "cost": { "input": 0.40, "output": 1.60 } },
        { "id": "accounts/fireworks/models/glm-5p2",      "label": "GLM 5.2",       "tier": "frontier", "thinking": "optional", "tools": true, "context": 200000,
          "attachments": { "vision": false, "document": false },
          "cost": { "input": 1.40, "output": 4.40 } }
      ]
    },
    {
      "key": "local",
      "label": "Local / self-hosted",
      "dialect": "openai",
      "base_url_setting": "joinery_ai_local_base_url",
      "api_key_setting": null,
      "trust": "local",
      "slots": [
        { "slot": "small", "setting": "joinery_ai_local_model_small", "tier": "basic",    "thinking": "optional", "tools": true },
        { "slot": "mid",   "setting": "joinery_ai_local_model_mid",   "tier": "standard", "thinking": "optional", "tools": true },
        { "slot": "large", "setting": "joinery_ai_local_model_large", "tier": "capable",   "thinking": "optional", "tools": true }
      ]
    }
  ]
}
```

**Endpoint fields**

| Field | Meaning |
|---|---|
| `key` | stable id, used in logs, overrides and run history |
| `dialect` | `anthropic` \| `openai` — which provider class drives the wire format |
| `base_url` / `base_url_setting` | literal, or the setting an operator binds |
| `api_key_setting` | the endpoint is **available** only when this setting is non-empty; `null` means no key needed |
| `trust` | `local` \| `trusted` \| `cloud` — see below |
| `trust_note` | one plain sentence shown in the UI explaining why `trusted` was granted |
| `enabled_setting` | optional; lets an operator switch an endpoint off without clearing the key |
| `models` / `slots` | the catalog it serves (fixed ids, or operator-bound slots) |

**Catalog-model fields**

| Field | Meaning |
|---|---|
| `id` | the wire id sent to the endpoint |
| `label` | UI text |
| `tier` | `basic` \| `standard` \| `capable` \| `frontier` |
| `thinking` | `none` (cannot reason) \| `optional` (has a knob) \| `always` (cannot be turned off) |
| `tools` | can drive tool calls |
| `context` | window in tokens |
| `attachments` | `{vision, document}` |
| `cost` | USD per Mtok; absent means free (local) |
| `retired` | optional; still resolvable for cost history, never selected for new work |

This replaces `MODELS`, `COST_PER_MTOKEN`, `MODEL_CAPABILITIES`,
`REASONING_MODELS` and `REASONING_NO_OFF` — five constant tables in three
classes — with one file. It also replaces the `^claude` regex: a model id is
found in exactly one endpoint, so routing is a lookup and cannot disagree with
classification.

### 2. Trust — three classes, because two cannot say what is needed

| Class | Meaning | Example |
|---|---|---|
| `local` | bytes never leave hardware the operator controls | Ollama on the local box or the operator's own LAN host |
| `trusted` | leaves the box to a named vendor under a contract the operator has accepted | Fireworks (no-train on open-model traffic) |
| `cloud` | a general vendor with ordinary terms | Anthropic |

This collapses `isPrivate()` (advisory) and `isCloudModel()` (gate) into one
value, ending the current contradiction where Fireworks is private for warnings
and cloud for gates.

A requirement states a **trust floor**:

| Floor | Accepts |
|---|---|
| `local` | `local` only |
| `trusted` | `local`, `trusted` |
| `any` | all three |

### 3. Capability tiers — the ladder a recipe asks against

Four rungs. Every rung is a judgement someone must make about every model, so
the ladder stays short.

| Tier | The job it can be trusted with | Examples of work |
|---|---|---|
| `basic` | one short classification against clear instructions, on short text | *is this an advertisement*, is-this-a-receipt, language detection |
| `standard` | reads a document-length input, fills a multi-field schema, holds a few constraints at once | email triage, extracting a calendar entry |
| `capable` | adversarial or consequential judgement; resists manipulation planted in the input; multi-step tool use | phishing and security scanning, anything that writes based on untrusted text |
| `frontier` | long-horizon reasoning, subtle drafting, large context | composing mail the owner sends under their own name |

**Rule: a request for tier N is satisfied by any model of tier ≥ N.** That is
what makes it a minimum rather than a selector.

The tier is a property of the model in the catalog, so re-grading the fleet
after a vendor release is a catalog edit and a publish.

### 4. A recipe states a requirement, not a model

New columns on `rcp_recipes` (and the mirror fields on `aic_conversations`):

| Column | Type | Default | Meaning |
|---|---|---|---|
| `rcp_min_tier` | `varchar(20)` | `standard` | the capability floor |
| `rcp_trust_floor` | `varchar(20)` | `any` | `local` \| `trusted` \| `any` |
| `rcp_thinking_required` | `varchar(10)` | `off` | `off` \| `allowed` \| `required` |
| `rcp_min_context` | `int4` | `NULL` | optional floor for large digests |
| `rcp_selection_policy` | `varchar(20)` | `NULL` | `NULL` inherits the site policy (which is `prefer_local`); set only to opt one recipe out |
| `rcp_model` | `varchar(100)` | `NULL` | **kept, not renamed** — reinterpreted as a rare explicit pin; column default drops from `claude-haiku-4-5` to null |

`rcp_model` deliberately keeps its name. `update_database` builds schema from
`$field_specifications` and does not rename: a rename would add the new column
and leave the old one in place, holding stale values and enforcing nothing.
Reinterpreting the existing column costs one comment and no migration. Same for
`aic_model` on the chat side.

`rcp_thinking_required` and the existing `rcp_thinking_level` are different
questions and both stay: the requirement says **must be able to reason**, the
level says **how hard**. `required` excludes any model whose catalog `thinking`
is `none`. `off` does not exclude an `always` model — it is satisfied by
mapping to the model's lowest effort, exactly as `FireworksProvider` already
does for `gpt-oss-120b`.

Attachment needs (`vision`, `document`) and tool-driving are derived, not
stored: agent mode implies `tools`, and chat ingress asks the resolver whether
the resolved model can take the file — which is what `capabilitiesForModel()`
does today, through one door instead of two.

### 5. The resolver — one function, one answer

```php
AiModelResolver::resolve(AiModelRequirement $req): AiModelResolution
```

Returns the endpoint key, the model id, and the built provider — or throws with
a message naming the gap.

Selection order:

1. **Filter** to catalog models that are *available* (endpoint key setting set,
   endpoint not disabled, a local slot actually bound), *not retired*, and
   satisfy every field of the requirement.
2. **Prefer local, always, unless something says otherwise.** The policy is
   `rcp_selection_policy` when the recipe sets one, else the site setting
   `joinery_ai_selection_policy`, which ships as `prefer_local`. Local is
   therefore the standing behaviour of the platform, and using someone else's
   hardware is a decision someone had to make and can be pointed at.
   - `prefer_local` *(default everywhere)* — lowest trust class that clears the
     floor first (`local` before `trusted` before `cloud`), then cheapest.
     Cloud is reached only when the operator's own hardware cannot meet the floor.
   - `cheapest` — lowest estimated cost per Mtok, ignoring trust beyond the floor.
   - `best` — highest tier that clears the floor, then cheapest.
3. **Tie-break** deterministically: cheaper first, then catalog order. A
   recipe's behaviour must not drift between runs.

A refusal names the gap and the fix:

> This recipe needs a **capable** model that stays on your hardware. Your local
> endpoint serves only a **basic** model. Either bind a larger model to the
> local `large` slot, or lower the recipe's minimum.

#### Resolve once. This is the load-bearing rule.

`resolve()` returns an **immutable** `AiModelResolution` carrying the endpoint,
the catalog model entry, the built provider and the normalized thinking
directive. Every consumer of a run — the consent gates, the dispatch, the cost
record, the run history — reads **that same object**. Nothing re-resolves.

This is not a style preference. Today the guard in front of decrypted mail
leaving the box works because `routeFor()` is one decision that both
`forModel()` and `isCloudModel()` read, so what receives the request and what
the gate believes receives it provably cannot disagree — the class comment says
exactly that, and it is the property sink zero rests on. A resolver introduces a
shape that does not exist today: check, then resolve again. If the gate resolves
to test trust and the dispatch resolves to run, a catalog change, a slot
rebinding or a cleared API key landing between them silently moves the work to a
different endpoint than the one that was approved. Resolving once and passing
the result closes that gap by construction rather than by discipline.

Two corollaries:

- **Fail closed.** An unparseable catalog, an unknown pin, or an id present in
  no endpoint is a refusal — never a fall-through to "whatever is available".
  This preserves today's behaviour, where an empty model id classifies toward
  cloud and the gate therefore refuses.
- **The re-check at run start re-resolves deliberately.** Save time and run
  start each resolve once, for their own decision; that is what makes a
  withdrawn consent or an unbound slot stop the *next* run. Within a single run,
  one resolution.

#### The resolver owns the thinking directive

Thinking translation currently lives inside providers and is keyed on model
names: `FireworksProvider::REASONING_MODELS` / `REASONING_NO_OFF`, and the local
host's qwen `/think` token. Once the catalog declares `thinking:
none|optional|always`, providers cannot keep that knowledge without two
catalogs disagreeing.

So the resolution carries a **concrete directive** — enabled or not, and at what
effort — computed from the catalog entry plus the requirement plus the level.
Providers translate the directive into their own wire field (`budget_tokens`,
`reasoning_effort`, a system suffix) and stop knowing model names entirely.

This is a change to `createMessageStreamed()`, the one interface the whole
subsystem rides on, so it is named here rather than discovered mid-build. It is
also the change that lets the provider layer shrink: `models()`,
`defaultModel()`, `isPrivate()`, `modelCapabilities()` and `estimateCost()` all
become catalog lookups, leaving providers as transport only. Twenty-three files
call those five methods today, plus the `FakeLlmProvider` in
`tests/lib/llm_fixtures.php`. The work is mechanical, and every removal deletes
a place where two answers could disagree.

### 6. Pins still exist, and are still checked

`rcp_model` as a pin (and `aic_model`) resolves to that exact catalog model — **and
are still validated against the requirement**. A pin that cannot meet the floor
is refused at save time with the reason. This closes today's failure mode where
a pinned name and the recipe's real needs disagree silently, and where a pin
names a model the install does not have.

### 7. What stays in the database, and why

Only genuinely instance-local facts:

- **Endpoint keys** — already settings, already secret.
- **Local slot bindings** — `joinery_ai_local_model_small` / `_mid` / `_large`,
  the ollama tags this host actually serves. Swapping a model on a host is one
  settings edit on that host, and every recipe follows automatically because
  none of them names it.
- **`joinery_ai_selection_policy`** — one select, shipping `prefer_local`. A
  recipe overrides it only by setting `rcp_selection_policy`.
- **`joinery_ai_endpoint_overrides`** *(optional, JSON textarea)* — a narrow
  escape hatch for a site that must disable an endpoint or re-grade a tier
  locally. Merged over the shipped catalog; empty on every normal install.

`joinery_ai_llm_provider`, `joinery_ai_default_model` and the single
`joinery_ai_local_model` setting are retired by this design — the first two
because the resolver answers the question they existed to answer, the third
because it becomes the three slot settings.

### 8. Say what ran — the visibility this design would otherwise cost

This design moves a judgement from *per-recipe visible* to *per-catalog
invisible*. Today an operator opens a recipe and reads `claude-haiku-4-5`; after
this, they read "capable, prefers local" and a file they did not open decides
the rest. Mis-grade a model in the catalog and a security scan quietly runs on
something too weak, with nothing anywhere looking wrong.

Fleet-wide cascade is worth that trade, but only if the resolution is stated
back. Two additions, both cheap:

**On the recipe edit page and the chat controls**, render the resolution next to
the requirement — *"Right now this runs on Qwen 3.7 Plus (Fireworks · trusted)"*
— recomputed on load. The operator sees what their floor actually bought before
they save.

**On every run**, record it. `rcr_recipe_runs` has **no** model column today,
because the model was typed on the recipe and sat there in front of you. Once a
resolver picks it, "which model ran this?" becomes unanswerable from history:

| Column | Type | Meaning |
|---|---|---|
| `rcr_model` | `varchar(100)` | catalog model id actually dispatched to |
| `rcr_endpoint` | `varchar(50)` | endpoint key it resolved to |

Both additive; `update_database` adds them from `$field_specifications`. The
chat side records the same on the message row.

This also repairs a live defect. `RecipeRunner::recordTokens()` calls
`estimateCost((string)$recipe->get('rcp_model'), …)`, and `RecipeSeeder` ships
every recipe with an empty `rcp_model` — so the cost table lookup misses and
**every unpinned recipe records $0 regardless of what it actually spent on a
cloud vendor**. Spending caps are unaffected: `CostGuard` counts tokens
(`rcp_monthly_token_cap`, `joinery_ai_global_monthly_token_cap`), never dollars.
It is a reporting error, not an overspend — but it is precisely the number this
design leans on, so costing from `rcr_model` rather than from the recipe's
(possibly empty) pin is part of the work, not a follow-up.

### 9. Where the gates ask their question afterwards

| Gate | Today | After |
|---|---|---|
| Fortress chat pin | `isCloudModel()` refuses any non-local model | requirement gains `trust_floor: local`; the resolver enforces it — one less special case in `forConversation()` |
| `RecipeVaultScope::assertModelAllowed()` | binary: sealed content may not reach a cloud model unless the domain consented | asks whether the resolved endpoint's **trust class** satisfies the domain's consent, so a domain can permit `trusted` processing while still refusing `cloud` — a distinction the current binary cannot express |
| Chat privacy warning | `isPrivate()` | the same `trust` value, so the warning and the gate can no longer disagree |
| Attachment ingress | `capabilitiesForModel()` | requirement flags `needs_vision` / `needs_document`; the resolver refuses before the upload is accepted |

The one-way-tightening rule is unchanged: both save time and run start
re-resolve, so withdrawing a consent or unbinding a local slot stops the next
run rather than leaving an armed recipe.

### 10. Shipped recipes finally carry their requirement

`RecipeSeeder::DECLARED_KEYS` gains `min_tier`, `trust_floor`,
`thinking_required`, `min_context`. `NON_TRAVELLING_FIELDS` keeps stripping the
pin — a model name still cannot travel — but the *requirement* now does, so a
seeded recipe arrives knowing what it needs and resolves correctly against
whatever the destination has.

```jsonc
{
  "key": "email_security_scan_default",
  "name": "Email security scan",
  "pipeline_job": "email_security_scan",
  "requires_plugin": "mailbox",
  "min_tier": "capable",
  "trust_floor": "any",
  "thinking_required": "required",
  "thinking_level": "medium",
  "max_iterations": 25,
  "max_tokens": 5000,
  "monthly_token_cap": 200000
}
```

---

## The permutation space

The requirement space is 4 tiers × 3 trust floors × 3 thinking requirements =
**36 shapes**, times the attachment/tool/context flags. Enumerating all of it is
not useful. Two tables are.

### Resolution matrix — what today's fleet can serve

Assuming Anthropic and Fireworks keys set, and a local host serving a
mid-sized model bound to the `mid` slot.

| Requirement | `local` | `trusted` (or better) | `any` |
|---|---|---|---|
| `basic` | local `small`/`mid` | local, else gpt-oss 120B | local (policy `prefer_local`) |
| `standard` | local `mid` | local `mid`, else Qwen 3.7 Plus | local `mid` |
| `capable` | **unsatisfiable** unless `large` is bound | gpt-oss 120B ($0.15/$0.60) | gpt-oss 120B, else Haiku 4.5 |
| `frontier` | **unsatisfiable on every current host** | GLM 5.2 ($1.40/$4.40) | GLM 5.2, else Sonnet 4.6 |

The two unsatisfiable cells are the useful output of this exercise: they are
real gaps that are invisible today, and the refusal message tells the operator
exactly which slot to bind or which floor to lower.

### Assignment table — every surface's requirement

| Surface | `min_tier` | `trust_floor` | `thinking_required` | Why |
|---|---|---|---|---|
| `mark_advertisements` | `basic` | `any` | `off` | one yes/no on a short feed item |
| `email_triage` | `standard` | `any` | `off` | document-length input, multi-field verdict |
| `email_schedule` | `standard` | `any` | `off` | structured extraction from a mail body |
| `email_security_scan` | `capable` | `any` | `required` | adversarial input, consequential verdict |
| Chat — Standard | `standard` | `any` | `allowed` | general assistant work |
| Chat — Private | `standard` | `any` | `allowed` | storage is sealed; inference is not restricted |
| Chat — Fortress | `standard` | `local` | `allowed` | its whole point |
| Sealed-mail recipe, domain consent withheld | as declared | `local` | as declared | sink zero |
| Sealed-mail recipe, domain consented to trusted | as declared | `trusted` | as declared | the distinction the binary cannot express today |
| Composer (drafting under the owner's name) | `frontier` | `any` | `allowed` | subtle drafting |

---

## Migration

No production users, so no data migration is required — but existing dev and
fleet rows should keep working:

1. **No column rename.** `rcp_model` and `aic_model` keep their names and their
   values, reinterpreted as pins and now validated against the requirement. The
   only schema change on them is dropping `rcp_model`'s `claude-haiku-4-5`
   default to null, so a new recipe expresses a requirement rather than
   inheriting a name.
2. New requirement columns take the defaults above, which reproduce today's
   behaviour for anything that had no opinion.
3. `joinery_ai_local_model` seeds the `mid` slot on first sync; the operator
   distributes across `small`/`large` at leisure.
4. `joinery_ai_selection_policy` seeds as `prefer_local` on every install,
   including existing ones — it is the platform's standing posture, not a
   carried-forward preference. `joinery_ai_llm_provider` and
   `joinery_ai_default_model` are retired outright.
5. `LlmProviderFactory::forModel()` / `isCloudModel()` / `ChatLevel::isLocalModel()`
   become thin wrappers over the registry during the transition, then go.
6. `rcr_model` / `rcr_endpoint` are added empty. History predating this design
   genuinely does not know which model ran, and a guess written into the column
   would be worse than a blank — leave it blank.
7. The five provider methods the catalog absorbs (`models()`, `defaultModel()`,
   `isPrivate()`, `modelCapabilities()`, `estimateCost()`) are removed from
   `LlmProviderInterface` in one pass, not deprecated in place: leaving a second
   answerable source is exactly the drift this design exists to end.
   `FakeLlmProvider` in `tests/lib/llm_fixtures.php` sheds them at the same time.

## Tests

- **Catalog schema gate** — `ai_endpoints.json` parses, every model has a valid
  tier / thinking / trust, no duplicate model ids across endpoints, every
  `api_key_setting` and `base_url_setting` names a declared setting.
- **Resolver matrix** — a table-driven suite over the 36 requirement shapes
  against a fixture catalog, asserting the chosen model and asserting that
  unsatisfiable cells refuse with a message naming the gap.
- **Determinism** — the same requirement resolves identically across repeated
  calls and process restarts.
- **Local by default** — with every endpoint configured and a local model that
  clears the floor, resolution picks the local one; it reaches a vendor only
  when the floor cannot be met locally, or when a recipe set its own policy.
- **Gate agreement** — for every catalog model, the chat warning and the sealed
  egress gate read the same trust class (the regression the current
  `isPrivate()` / `isCloudModel()` split allows).
- **One resolution per run** — the gate and the dispatch receive the same
  resolution object. Asserted by mutating the catalog (or clearing an endpoint
  key) *after* the gate passes and confirming the run still dispatches to the
  approved endpoint or fails, never to a different one. This is the sink-zero
  regression test.
- **Fail closed** — an unparseable catalog, an unknown pin, and an id in no
  endpoint each refuse rather than falling through to any available model.
- **Run provenance** — a completed run records the `rcr_model` and
  `rcr_endpoint` it actually used, and its `rcr_cost_estimate` is derived from
  `rcr_model`. Includes the case the current code gets wrong: an unpinned
  recipe running on a paid endpoint must record a non-zero cost.
- **Thinking directive** — a `thinking: none` model is excluded from a
  `required` requirement; an `always` model under an `off` requirement resolves
  and dispatches at the lowest effort rather than being refused.
- **Pin validation** — a pin below the requirement is refused at save.
- **Seeder round trip** — a declaration's requirement survives publish and
  seeding while the pin is stripped.
- **Availability** — clearing an endpoint's key removes its models from
  resolution without an error anywhere else.

## Docs

`docs/` has no LLM-provider page today; provider behaviour is documented inside
`plugins/joinery_ai/docs/overview.md`. This lands as a new section there
covering the catalog file, the trust classes, the tier ladder, and how to change
a model fleet-wide. `CLAUDE.md`'s documentation index needs no new line unless
the section is split into its own page.

## Settled

- **Trust classes are `local` / `trusted` / `cloud`.** Three, because two cannot
  express an off-box endpoint the operator has decided is safe.
- **Tiers are `basic` / `standard` / `capable` / `frontier`.** Four rungs.
- **Local is the standing policy.** `prefer_local` everywhere unless a recipe
  explicitly says otherwise via `rcp_selection_policy`. Note this is a visible
  behaviour change on any site with a local box: triage that runs on Anthropic
  today moves to the local host the day this ships, provided the local host
  clears the recipe's floor.
- **The catalog is plugin-local** — `plugins/joinery_ai/ai_endpoints.json`.
- **The visibility trade is made knowingly.** Which model runs a recipe stops
  being a name an operator typed and becomes a consequence of a grading in a
  shipped file. Fleet-wide cascade is bought with that, and it is only safe
  because the resolution is stated back on the edit page and recorded on every
  run (§8). A mis-graded catalog is the failure mode to watch for, and the run
  record is how it gets caught.
- **A declaration cannot ship a selection policy.** `recipes.json` carries
  `min_tier`, `trust_floor`, `thinking_required` and `min_context` only. A
  shipped recipe that must not reach a vendor says so with
  `trust_floor: local`, which is a statement about the work; a policy is a
  statement about the operator's preference and is not the publisher's to make.

## Deferred

- **Per-owner trust floors** — a member raising their own floor (never lowering
  it) above the recipe's, so one user's recipes stay on their own hardware while
  another's may use a vendor. Not in this build.
