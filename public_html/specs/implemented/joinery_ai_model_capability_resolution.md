# Joinery AI — Endpoint catalog and capability-based model resolution

**Status:** IMPLEMENTED 2026-08-20. Code-reviewed with fixes applied; safe and
db tiers green; verified end-to-end with a live pipeline run against the local
host (run 4266: resolve, consent fold, dispatch, verdicts, cost record). Every
open fork is settled and recorded in §Settled and §Decisions; do not reopen
those without new evidence. Two refinements were made during the build and are
recorded in §Settled: a stated floor refuses where the fallback floor only
prefers, and equally-priced survivors break ties toward the least capable model
that still clears the floor.
**Plugin:** `joinery_ai`
**Touches:** `LlmProviderFactory`, `LlmProviderInterface`, `AnthropicProvider`,
`FireworksProvider`, `OpenAiCompatibleProvider`, `ChatLevel`, `AgentLoop`,
`RecipeRunner`, `PipelineRunner`, `RecipeVaultScope`, `RecipeSeeder`,
`PipelineJobInterface` (+ its four implementations: `EmailTriageJob`,
`EmailSecurityScanJob`, `EmailScheduleJob`, `MarkAdvertisementsJob`),
`recipes.json`, `plugin.json` (settings), `data/recipes_class.php`,
`data/recipe_runs_class.php`, `data/ai_conversations_class.php`,
`views/admin/edit.php`, `logic/admin_edit_logic.php`, `logic/chat_controls_logic.php`,
`tests/lib/llm_fixtures.php`; consent widening (§9a):
`plugins/mailbox/data/inbound_email_domain_class.php`,
`plugins/mailbox/includes/MailboxAliasConfig.php`,
`plugins/mailbox/admin/admin_mailbox_domains.php` (+ its logic file),
`plugins/persona_browser/pipeline_jobs/MarkAdvertisementsJob.php`,
`plugins/joinery_ai/tests/in_window_email_test.php`
**New files:** `plugins/joinery_ai/ai_endpoints.json`,
`plugins/joinery_ai/ai_model_reference.json`, `AiEndpointRegistry`,
`AiModelResolver`, `AiModelRequirement`, `AiModelResolution`

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
off), the local host to a request-level `reasoning_effort` with no model names
involved. (Two stale comments in `OpenAiCompatibleProvider` and `AgentLoop`
still describe a qwen `/think` prompt token the code no longer emits — correct
them in this pass.) Nothing says **whether a job needs
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
      "probe": "ollama",
      "models_setting": "joinery_ai_local_model",
      "_models_note": "Whatever ids the host serves, comma-separated, exactly as today. Each is graded from ai_model_reference.json by its tag - no per-install binding."
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
| `enabled_setting` | optional; lets an operator switch an endpoint off without clearing the key. Like every `*_setting` here, it must name a setting declared in `plugin.json` — `Setting::put` refuses undeclared names |
| `models` / `models_setting` | the catalog it serves — a fixed list, or a setting naming whatever the operator's host serves (graded from the reference file) |
| `probe` | optional; `ollama` \| absent — this endpoint can report its models' mechanical facts (capabilities, context) live; see §3b. Replaces the `method_exists()` duck-typing in `AgentLoop` |

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
| `retired` | optional; still resolvable for cost history, never selected for new work. A pin naming a retired model is treated as **unavailable** (falls back to requirement resolution, recorded), not as a configuration error |

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
| `standard` | reads a document-length input, fills a multi-field schema, holds a few constraints at once | extracting a calendar entry |
| `capable` | adversarial or consequential judgement on **one item**; resists manipulation planted in the input | phishing and security scanning, email triage |
| `frontier` | sustained **multi-step tool use**, long-horizon reasoning, subtle drafting | agent-mode recipes, composing mail the owner sends under their own name |

**The line between `capable` and `frontier` is the line the system already
draws.** `capable` is pipeline mode — PHP picks the item, the model judges it in
one bounded exchange, nothing carries over. `frontier` is agent mode — the model
drives, holds a conversation, and each tool call compounds on the last. That is
`rcp_mode`, which already exists, which is a good sign the distinction is real
rather than invented.

An earlier draft bundled "resists manipulation" and "multi-step tool use" into
one rung. Measurement killed it: `gemma2:9b` and `qwen3.5:9b-nvfp4` both do
adversarial email judgement well (see the reference file) and neither would
survive a long tool loop. They are different capabilities and they belong on
different rungs.

**Rule: a request for tier N is satisfied by any model of tier ≥ N.** That is
what makes it a minimum rather than a selector.

A consequence worth stating so it does not read as a mistake: under the current
ladder **no local model grades `standard`** — the band runs ≤4B `basic` then
straight to `capable` at 7B. That is fine. A rung with no models is still a
valid floor, because anything above it satisfies it; `standard` exists for
recipes whose honest requirement sits there, and it will populate as cloud and
future models are graded.

**Grade generously.** Where a model sits on a boundary it gets the HIGHER rung,
and where a recipe's floor is arguable it gets the LOWER one. A floor exists to
stop work reaching a model that cannot do it, not to reserve work for the
biggest model available — and the measurements below show that within a wide
band, size buys very little on the judgement tasks recipes actually run.

The tier is a property of the model in the catalog, so re-grading the fleet
after a vendor release is a catalog edit and a publish.

### 3a. `ai_model_reference.json` — how a local model gets its tier

The cloud endpoints ship graded; an operator never touches them. The open
question is only ever **the model on their own box**, and no operator should be
asked to grade one.

So a second shipped file answers it. It is deliberately separate from
`ai_endpoints.json`: the catalog is authoritative for routing (an entry there
means "you may send here"), the reference is advisory for grading. Merging them
would let a reference entry become routable by accident.

```jsonc
{
  "_comment": "Advisory gradings for local models. Never routable — see ai_endpoints.json for that.",
  "ladder": [
    { "max_params_b": 4,  "tier": "basic" },
    { "max_params_b": 32, "tier": "capable" },
    { "max_params_b": null, "tier": "frontier" }
  ],
  "models": [
    { "match": "qwen3.5:9b*", "tier": "capable", "basis": "measured",
      "evidence": "email_security_corpus 2026-08-20, 100 msgs: 96% recall / 4% FP @>=7; mean phish 8.9 vs ham 0.3" },
    { "match": "qwen3.6:35b-a3b*", "tier": "capable", "basis": "measured",
      "evidence": "email_security_corpus 2026-08-20, 100 msgs: 92% recall / 2% FP @>=7; mean phish 8.8 vs ham 0.1; 783s vs 1024s for the 9B" },
    { "match": "gemma2:9b", "tier": "capable", "basis": "measured",
      "evidence": "email_security_corpus 2026-07: 90% recall / 8% FP @>=7" },
    { "match": "qwen3:4b*", "tier": "basic", "basis": "measured",
      "evidence": "email_security_corpus: scored phish, ham and hard negatives all in the 5.9-8.7 band - cannot separate" }
  ]
}
```

**Named entries win; the ladder is the fallback.** Most Ollama tags announce
their own size (`:9b`, `:4b`, `:27b`, `:35b-a3b`), so an unrecognized model is
graded by parsing the parameter count out of the tag. Where a tag carries more
than one count, the parser takes the **largest** — `35b-a3b` grades as 35B
total, not the 3B active-parameter suffix. Only a tag with nothing readable in
it falls to `basic` — which is the one genuinely unknown case, not a punishment
for being unlisted.

**`basis` is `measured` or `research`.** A `measured` entry names the run behind
it. Cloud gradings are `research` and say so. This is how you know whether to
trust a grading when a recipe misbehaves, and it keeps the file from silently
becoming a pile of guesses.

**Grade by family and size class, not by exact tag.** Quantizations and instruct
variants multiply endlessly; a per-tag list would go stale weekly. Hence the
glob `match`.

### 3b. Tier is the only fact the reference file owns

A requirement has five axes, and tier is the only one no probe can measure —
it is a judgement about judgement. The other, *mechanical* facts of a local
model (`thinking`, `tools`, `context`, `attachments`) come from the host
itself: an endpoint declaring `probe: "ollama"` is asked via `/api/show`, which
reports a `capabilities` array (`tools`, `thinking`, `vision`, …) and the
model's `context_length`. The host knows these better than any shipped file —
swap in a vision model or a new quantization and the facts are simply right,
no publish needed. Probe results are cached per model tag.

**Precedence, per fact:** a named reference entry that carries the field
(rare — for a host that mis-reports) → the probe → the stated defaults.
Defaults, for a host with no probe (a vLLM or LM Studio behind the same OpenAI
dialect): `thinking: optional`, `tools: true`, no attachments, context
**unknown** — and an unknown context fails a `min_context` requirement
**closed**, with the refusal naming the host's silence rather than guessing a
number. The defaults are load-bearing: `tools: true` on a host that cannot
drive them surfaces as a runtime failure, not a resolution refusal, which is
the price of not making every operator fill in a fact table.

This is the same design move as the live-context probe (Decision 3, Branch B),
applied uniformly: the probe capability is declared on the endpoint once, and
serves both the mechanical fact set at resolve time and the live window check
at run time.

#### What the measurements actually showed

Two models a size class apart were scored on the same 100-message corpus
(50 phish, 30 ham, 20 hard negatives) on the same host:

| | `qwen3.5:9b-nvfp4` | `qwen3.6:35b-a3b-q4_K_M` |
|---|---|---|
| recall @>=7 | **96%** (48/50) | 92% (46/50) |
| false positives @>=7 | 4% (2/50) | **2%** (1/50) |
| mean phish / ham / hard | 8.9 / 0.3 / 1.1 | 8.8 / 0.1 / 0.5 |
| total wall clock | 1024s | **783s** |

Three conclusions the design rests on:

1. **They are not distinguishable.** Two messages of recall against one false
   positive, on 50 per class, is noise. A model a size class larger did not
   judge better — it judged *more conservatively*, scoring everything lower.
2. **So the buckets must be coarse.** If 9B and 35B perform identically, a
   fine-grained ladder would be inventing precision that the underlying reality
   does not have. This is the strongest argument for four loose rungs.
3. **Mixture-of-experts breaks size intuition, in the useful direction.** The
   35B decodes ~24% faster end to end than the dense 9B, because only ~3B
   parameters are active per token. Its one real cost is cold load — minutes to
   page in 24GB — which dominates a recipe that wakes hourly to judge two items
   and is irrelevant to a drain of fifty.

`tests/tools/score_email_corpus.php` (v1.3) is the instrument. Re-running it is
how a `research` entry becomes a `measured` one.

### 4. A recipe states a requirement, not a model

New columns on `rcp_recipes` — and on `rcp_recipes` only. Chat gains **none**
of these (Decision 6): a conversation has no requirement, only its pick
(`aic_model`), and Fortress's `local` constraint is a property of the chat
level, enforced by the resolver from the level rather than stored per row.

| Column | Type | Default | Meaning |
|---|---|---|---|
| `rcp_min_tier` | `varchar(20)` | `NULL` | the capability floor; NULL = inherit (§4a) |
| `rcp_trust_floor` | `varchar(20)` | `NULL` | `local` \| `trusted` \| `any`; NULL = inherit |
| `rcp_thinking_required` | `bool` | `NULL` | TRUE excludes `thinking: none` models; NULL = inherit |
| `rcp_min_context` | `int4` | `NULL` | optional floor for large digests; checked against the catalog's **nominal** window at resolve time — the live-probed window surfaces as the usable-context warning, never a refusal |
| `rcp_model` | `varchar(100)` | `NULL` | **kept, not renamed** — reinterpreted as a rare explicit pin; column default drops from `claude-haiku-4-5` to null |

(An earlier draft also had a per-recipe `rcp_selection_policy`. Cut — Decision
8: the site policy sets posture, the pin is the per-recipe lever.)

`rcp_model` deliberately keeps its name. `update_database` builds schema from
`$field_specifications` and does not rename: a rename would add the new column
and leave the old one in place, holding stale values and enforcing nothing.
Reinterpreting the existing column costs one comment and no migration. Same for
`aic_model` on the chat side.

`rcp_thinking_required` and the existing `rcp_thinking_level` are different
questions and both stay: the requirement says **must be able to reason**, the
level says **how hard**. The requirement is a plain boolean — an earlier draft
had three values (`off`/`allowed`/`required`), but `off` merely duplicated
`thinking_level: off` and the middle value bought nothing (Decision 7). TRUE
excludes any model whose catalog `thinking` is `none`; a level of `off` does
not exclude an `always` model — it is satisfied by mapping to the model's
lowest effort, exactly as `FireworksProvider` already does for `gpt-oss-120b`.
The one residual mismatch — a level above `off` resolving onto a model that
cannot reason — is handled with visibility, not machinery: the edit page's
resolution line says *"this model cannot reason — level ignored."*

Attachment needs (`vision`, `document`) and tool-driving are derived, not
stored: agent mode implies `tools`, and chat ingress asks the resolver whether
the resolved model can take the file — which is what `capabilitiesForModel()`
does today, through one door instead of two.

### 4a. Nobody should have to fill these in

The requirement columns are a power-user surface. The normal case is an operator
who never opens them, and the design has to make that the *good* path rather
than the neglected one.

**The job declares the floor, not the recipe.** `EmailSecurityScanJob` knows it
reads attacker-controlled mail and needs `capable`; `MarkAdvertisementsJob` knows
it is a yes/no on a short feed item and needs `basic`. The operator who binds a
mailbox to a recipe knows none of that and should not be asked.

This is not a new idea in this codebase — it is what `PipelineJobInterface`
already does. `untrustedDigest()`, `requiresVaultScope()` and
`cloudProcessingAllowed()` are all safety properties declared by the **job**,
consulted by the runner, and invisible on the recipe form. A capability floor is
exactly the same kind of fact, so it gets exactly the same treatment:

```php
/** The capability floor this job's judgement needs. */
public function minTier(): string;

/** Whether this job's content may be sent off the operator's hardware
 *  by default (a floor, not a permission — the domain consent still gates). */
public function defaultTrustFloor(): string;
```

Agent-mode recipes have no job. A declared one takes its requirement from its
`recipes.json` declaration (§10); an operator-authored one takes the plugin
default, which is `frontier` — the model is driving a tool loop, which is what
that rung means.

**Resolution chain, most specific first:**

| Source | Set by | How often |
|---|---|---|
| `rcp_model` (pin) | an operator who insists | rare |
| `rcp_min_tier` / `rcp_trust_floor` / … | a power user overriding the job | rare |
| the job's `minTier()` / `defaultTrustFloor()` (pipeline recipes) | the developer who wrote the job | **the normal case** |
| the declaration in `recipes.json`, via `declarationByKey()` (agent-mode declared recipes, which have no job) | whoever ships the recipe | the normal case for declared agent recipes |
| plugin settings default | the platform | fallback |

NULL at every recipe-row level means "inherit" — the same pattern
`rcp_temperature`, `rcp_top_p` and `rcp_thinking_level` already use, where a
NULL column falls through to a plugin setting. Nothing new to learn.

**The chain is walked at resolve time, and an inherited value is never written
into a row.** This is load-bearing: `RecipeSeeder` is create-only — it has no
update path and never touches an existing row — so any requirement materialized
at seed time would be frozen at install and a floor raised in a later release
would never reach an existing install. Keeping the columns NULL is what makes a
job's floor cascade with the code that declares it. A non-NULL requirement
column therefore always means exactly one thing: an operator overrode it.

The final rung's fallback values: `min_tier` `standard` (`frontier` for
agent-mode recipes, per above), `trust_floor` `any`, thinking not required,
no `min_context`.

#### So: JSON or database?

Both, and the split is not arbitrary — it follows what has to cascade.

| Thing | Lives in | Why |
|---|---|---|
| endpoints, model gradings, cost, capabilities | **JSON in the release tree** | fleet knowledge; must reach every node on publish without touching a database |
| a job's declared floor | **PHP, in the job class** | it is a property of the code that does the work, and it ships with that code |
| a recipe's override or pin | **database columns** | per-recipe, per-install, operator-owned; it is exactly the kind of thing a database is for |

The rule of thumb: **if changing it should reach the whole fleet, it is a file;
if it is one operator's decision about one recipe, it is a column.** Today's
design has model identity in the second bucket when it belongs in the first,
which is the entire problem this spec exists to fix.

#### What the edit form shows

One line, closed by default:

> **Model** — Automatic · currently Qwen 3.6 35B (local)

Opening *Advanced* reveals the floor, the trust floor, the thinking requirement
and the pin, each showing what it inherits and from where. An operator who wants
a specific model can still have one; an operator who does not never learns these
fields exist. The resolution is rendered live either way (§8), so "Automatic"
never means "unknowable".

### 5. The resolver — one function, one answer

```php
AiModelResolver::resolve(AiModelRequirement $req): AiModelResolution
```

Returns the endpoint key, the model id, and the built provider — or throws with
a message naming the gap.

Selection order:

1. **Filter** to catalog models that are *available* (endpoint key setting set,
   endpoint not disabled, the local host serving at least one model), *not retired*, and
   satisfy every field of the requirement.
2. **Prefer local, always, unless something says otherwise.** The policy is
   the site setting `joinery_ai_selection_policy`, which ships as
   `prefer_local` (per-recipe policy was cut — Decision 8). Local is
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
> endpoint serves only a **basic** model. Either serve a larger model on your
> local host, or lower the recipe's minimum.

#### Resolve once. This is the load-bearing rule.

`resolve()` returns an **immutable** `AiModelResolution` carrying the chosen
endpoint and catalog model, the built provider, the normalized thinking
directive — and the **ordered remainder of approved candidates**: every other
model that passed the same filters, in selection order, truncated by the
cost-nonincreasing rule (no candidate costing more than the first choice).
For a pinned first choice whose capability floor nobody stated, the pin's own
tier is the accepted level: no candidate sits below it, so a pinned capable
model degrades sideways or up, never to a weaker sibling.
Every consumer of a run — the consent gates, the dispatch, the cost record, the
run history — reads **that same object**. Nothing re-resolves.

This is not a style preference. Today the guard in front of decrypted mail
leaving the box works because `routeFor()` is one decision that both
`forModel()` and `isCloudModel()` read, so what receives the request and what
the gate believes receives it provably cannot disagree — the class comment says
exactly that, and it is the property sink zero rests on. A resolver introduces a
shape that does not exist today: check, then resolve again. If the gate resolves
to test trust and the dispatch resolves to run, a catalog change, a changed
local model list or a cleared API key landing between them silently moves the work to a
different endpoint than the one that was approved. Resolving once and passing
the result closes that gap by construction rather than by discipline.

Two corollaries:

- **Fail closed.** An unparseable catalog, an unknown pin, or an id present in
  no endpoint is a refusal — never a fall-through to "whatever is available".
  This preserves today's behaviour, where an empty model id classifies toward
  cloud and the gate therefore refuses.
- **The re-check at run start re-resolves deliberately.** Save time and run
  start each resolve once, for their own decision; that is what makes a
  withdrawn consent or a removed local model stop the *next* run. Within a single run,
  one resolution.
- **Failover walks the list, never re-resolves.** On a transport failure
  **before the first token** — connection refused, model failed to load —
  dispatch advances to the next candidate in the resolution. Every candidate
  passed the same requirement, trust and consent filters at resolve time, so
  nothing outside the approved set is ever reachable, which is what keeps
  "one resolution per run" true. A stream that dies *after* the first token is
  a **failed run**, not a retry — a second dispatch would double-spend and
  re-fire side effects. The run records both what was resolved and what
  actually served it (§8).

#### The resolver owns the thinking directive

Thinking translation currently lives inside providers and is keyed on model
names in `FireworksProvider::REASONING_MODELS` / `REASONING_NO_OFF` (the local
provider already translates without model names, via a request-level
`reasoning_effort`). Once the catalog declares `thinking:
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
is still validated against the requirement**. This closes today's failure mode
where a pinned name and the recipe's real needs disagree silently.

**Two different ways a pin can fail, and they must not be treated alike:**

**A pin is checked against a floor somebody STATED, never against the
fallback.** An operator's override column, a job's `minTier()`, a shipped
declaration — those are statements, and a pin below one of them is a mistake
worth refusing. The final rung of §4a's chain is not a statement; it is what the
platform assumes when nobody had an opinion. Letting an assumption veto the one
thing the operator did say would invert the chain, in which the pin is the most
specific source of all — and it would refuse every hand-made agent-mode recipe
on any fleet without a `frontier` model, including this dev install's local
smoke-test recipe, for a floor nobody chose. The safety case is untouched:
`EmailSecurityScanJob` states `capable`, so a `basic` pin under it still fails.

| Situation | Meaning | Behaviour |
|---|---|---|
| Pin is **below a stated floor** at save time — a `basic` model on a `capable` recipe | someone configured it wrong | **refuse at save**, naming the gap. It is a mistake and it should be fixed, not worked around. |
| Pin **becomes** below the floor with no save — a release raised the job's floor or re-graded the catalog model down | the world changed under a saved row | **refuse at run start**, fail closed, recorded on the run and shown on the edit page. Save-time checking alone would read as "runs are never checked". |
| Pin is **unavailable** — its endpoint has no key, its model is `retired`, or the local host stopped serving it | this install cannot reach it *today* | **fall back to normal resolution** and record that it did, on the run and on the edit page |

Collapsing these would be a real upgrade-day hazard: a genuine pin (this dev
install has three, to a local qwen model) must survive its host being briefly
unavailable without either hard-failing or being silently forgotten. An
unavailable pin is an availability fact, not a configuration error — the recipe
still has a requirement, and the requirement is enough to run on.

Neither case is silent. The run records what actually served it (§8), so
"my pin isn't being used" is always answerable.

### 7. What stays in the database, and why

Only genuinely instance-local facts:

- **Endpoint keys** — already settings, already secret.
- **The local model list** — `joinery_ai_local_model`, the ollama tags this host
  actually serves, comma-separated, exactly as today. Each is graded from the
  shipped reference file by its tag, so there is nothing to bind and no slots to
  keep straight. Swapping a model on a host is one settings edit on that host,
  and every recipe follows automatically because none of them names it.
- **`joinery_ai_selection_policy`** — one select, shipping `prefer_local`.
  There is deliberately no per-recipe override (Decision 8); the pin is the
  per-recipe lever.
- **The chat default** — `joinery_ai_default_model` survives, redefined as
  **chat-only**: the model a new conversation starts on when the user has not
  picked one. Recipes never read it — they have requirements. It behaves as a
  site-wide chat pin under §6's rules: resolves to that catalog model, and
  when unavailable falls back to normal resolution and says so. The chat
  default is one operator's taste about the most visible AI surface — exactly
  the "one operator's decision" bucket the rule of thumb in §4a assigns to the
  database.
- **`joinery_ai_endpoint_overrides`** *(optional, JSON textarea)* — a narrow
  escape hatch for a site that must disable an endpoint or re-grade a tier
  locally. Merged over the shipped catalog; empty on every normal install.

`joinery_ai_llm_provider` is the one setting this design retires — the
resolver answers the question it existed to answer. (`joinery_ai_local_model`
and `joinery_ai_default_model` both survive, as above: the first unchanged,
the second narrowed to chat.)

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
re-resolve, so withdrawing a consent or removing a local model stops the next
run rather than leaving an armed recipe.

### 9a. Domain consent becomes three-valued

The distinction §9 promises is not free: consent today is
`ied_inbound_email_domains.ied_ai_cloud_enabled`, a `bool NOT NULL DEFAULT
false`, folded through `cloudProcessingAllowed(array $config): bool`. A boolean
cannot say "trusted yes, cloud no", so the store, the interface, and the admin
control all widen — in this build, in one pass, matching the no-deprecation
rule (§Migration item 8).

**Storage.** New column `ied_ai_processing_consent varchar(20) NOT NULL DEFAULT
'local'`, holding the **most permissive trust class the domain's decrypted mail
may reach**: `local` | `trusted` | `cloud` — the same vocabulary as endpoint
trust, so the gate is a direct comparison. A data migration seeds it from the
bool (`false → 'local'`, `true → 'cloud'`), then `ied_ai_cloud_enabled` leaves
`$field_specifications`; the physical column lingers harmlessly until a later
cleanup, per the platform's no-schema-drops rule.

**Interface.** `PipelineJobInterface::cloudProcessingAllowed(array $config):
bool` is replaced by:

```php
/** The most permissive trust class this run's decrypted content may reach:
 *  'local' | 'trusted' | 'cloud'. The strictest sealed address wins. */
public function processingConsent(array $config): string;
```

`EmailPipelineJobBase` implements it as the **minimum across the listed sealed
addresses** (an address with nothing sealed contributes no constraint — today's
all-must-consent fold, generalized). `MarkAdvertisementsJob`'s unconditional
`return true` becomes `return 'cloud'` — its feed items are not sealed mail.

**The gate.** `RecipeVaultScope::assertModelAllowed()` asserts the resolved
endpoint's trust class is at or below the consent — `local` always passes,
`trusted` needs `trusted` or `cloud` consent, `cloud` needs `cloud` consent.
Equivalently: the consent acts as one more trust floor on the requirement, and
the strictest of the recipe's floor and the domains' consent wins.

**The control.** The domain admin page's checkbox becomes a three-way select
(FormWriter, with the endpoint's `trust_note` text explaining what `trusted`
means on this install). Default for new domains stays the strictest value,
`local` — sealed mail never travels until someone says so.

### 10. Shipped recipes carry their requirement — without the seeder writing it

`NON_TRAVELLING_FIELDS` keeps stripping the pin — a model name still cannot
travel — and the requirement travels, but **never as a seeded row value**. The
seeder is create-only: a value it wrote at install would be frozen there, and a
floor raised in a later release would silently miss every existing install. So
the requirement columns of a seeded recipe stay NULL and the floor is read live
through §4a's chain:

- **Pipeline recipes** carry no requirement fields in `recipes.json` at all.
  The floor is the job's `minTier()` / `defaultTrustFloor()` — it ships with
  the code that does the work, and there is exactly one source for it.
- **Agent-mode declared recipes** (no job) carry `min_tier`, `trust_floor`,
  `thinking_required`, `min_context` in their `recipes.json` declaration, read
  at resolve time via the existing `declarationByKey()`. `DECLARED_KEYS` does
  **not** gain these fields — they are resolved, not seeded.

Either way, changing a floor in a release changes the effective floor of every
existing seeded recipe on every install, with no reseed and no migration —
because no row holds a copy.

```jsonc
{
  "key": "email_security_scan_default",
  "name": "Email security scan",
  "pipeline_job": "email_security_scan",   // the job declares capable/any - not repeated here
  "requires_plugin": "mailbox",
  "thinking_level": "off",
  "max_iterations": 25,
  "max_tokens": 5000,
  "monthly_token_cap": 200000
}
```

---

## The permutation space

The requirement space is 4 tiers × 3 trust floors × 2 thinking requirements =
**24 shapes**, times the attachment/tool/context flags. Enumerating all of it is
not useful. Two tables are.

### Resolution matrix — what today's fleet can serve

Against the dev fleet's actual bindings: Anthropic and Fireworks keys set, and
the Studio serving `qwen3.6:35b-a3b-q4_K_M`, `qwen3.6:35b-a3b-nvfp4`,
`qwen3.8:27b-q4_K_M` and `qwen3.5:9b-nvfp4`.

| Requirement | `local` | `trusted` (or better) | `any` |
|---|---|---|---|
| `basic` | 9B | 9B | 9B (policy `prefer_local`) |
| `standard` | 9B | 9B | 9B |
| `capable` | 9B or 35B MoE — both measured | 35B MoE, else gpt-oss 120B | 35B MoE, else Haiku 4.5 |
| `frontier` | **unsatisfiable on today's bindings** | GLM 5.2 ($1.40/$4.40) | GLM 5.2, else Sonnet 4.6 |

Only one cell is unsatisfiable, and it is a hardware fact rather than a
categorical one: `frontier` means sustained multi-step tool use, and the largest
model bound on the Studio is a 35B MoE. A 128GB-class box running a 70B dense or
a 120B MoE would fill it. The refusal message says exactly that, so the gap is
visible instead of silent.

### Assignment table — every surface's requirement

Floors set as low as the job honestly tolerates, per the permissive rule.

| Surface | `min_tier` | `trust_floor` | `thinking_required` | Why |
|---|---|---|---|---|
| `mark_advertisements` | `basic` | `any` | no | one yes/no on a short feed item |
| `email_triage` | `standard` | `any` | no | document-length input, multi-field verdict |
| `email_schedule` | `standard` | `any` | no | structured extraction from a mail body |
| `email_security_scan` | `capable` | `any` | no | adversarial input, consequential verdict — but a measured 9B clears it, so the floor is `capable`, not `frontier`, and thinking is not required (both measured runs scored with thinking off) |
| Chat — Standard | — | `any` | — | chat carries no floor; the user picks, or the chat default applies (Decision 6) |
| Chat — Private | — | `any` | — | storage is sealed; inference is not restricted |
| Chat — Fortress | — | `local` | — | its whole point; the floor comes from the chat level, not a column |
| Sealed-mail recipe, domain consent withheld | as declared | `local` | as declared | sink zero |
| Sealed-mail recipe, domain consented to trusted | as declared | `trusted` | as declared | the distinction the binary cannot express today |
| Composer (drafting under the owner's name) | `frontier` | `any` | no | subtle drafting |

---

## Migration

No production users, so no data migration is required — but existing dev and
fleet rows should keep working:

1. **No column rename.** `rcp_model` and `aic_model` keep their names,
   reinterpreted as pins and now validated against the requirement. The schema
   change is dropping `rcp_model`'s `claude-haiku-4-5` default to null, so a
   new recipe expresses a requirement rather than inheriting a name — **and a
   one-time migration nulls every `rcp_model` that still equals that old
   default.** Those values are column-default residue, not decisions (the dev
   install has 24 such rows against 3 deliberate pins; the seeder writes `''`,
   so only the default ever minted them). Left in place they would read as
   deliberate pins to a paid vendor the moment an Anthropic key is set,
   permanently defeating `prefer_local`. `aic_model` needs no such sweep — it
   has no column default, so every value there is a real pick.
2. New requirement columns are added NULL everywhere and stay NULL — every
   existing recipe inherits from its job, its declaration, or the plugin
   fallback (§4a), which reproduces today's behaviour for anything that had no
   opinion.
3. `joinery_ai_local_model` is unchanged — same setting, same comma-separated
   format. It stops being an ungraded list only because the reference file now
   grades each entry. Nothing for an operator to migrate.
4. `joinery_ai_selection_policy` seeds as `prefer_local` on every install,
   including existing ones — it is the platform's standing posture, not a
   carried-forward preference. `joinery_ai_llm_provider` is retired outright.
   `joinery_ai_default_model` keeps its row and its value (the dev install's
   `qwen3.6:35b-a3b-q4_K_M` carries straight over); only its `plugin.json`
   description changes, to say it is the chat default and nothing else.
5. `LlmProviderFactory::forModel()` / `isCloudModel()` / `ChatLevel::isLocalModel()`
   become thin wrappers over the registry during the transition, then go.
6. `rcr_model` / `rcr_endpoint` are added empty. History predating this design
   genuinely does not know which model ran, and a guess written into the column
   would be worse than a blank — leave it blank.
7. **Consent widening (§9a):** a data migration seeds
   `ied_ai_processing_consent` from `ied_ai_cloud_enabled` (`false → 'local'`,
   `true → 'cloud'`), the bool leaves `$field_specifications`, and
   `cloudProcessingAllowed()` is replaced by `processingConsent()` across its
   implementations in the same pass. Note the plugin-column ordering gotcha:
   plugin tables sync *after* migrations, so the seeding migration must handle
   the column not existing yet on first run (a second `update_database` pass
   completes it).
8. The five provider methods the catalog absorbs (`models()`, `defaultModel()`,
   `isPrivate()`, `modelCapabilities()`, `estimateCost()`) are removed from
   `LlmProviderInterface` in one pass, not deprecated in place: leaving a second
   answerable source is exactly the drift this design exists to end.
   `FakeLlmProvider` in `tests/lib/llm_fixtures.php` sheds them at the same time.

## Tests

- **Catalog schema gate** — `ai_endpoints.json` parses, every model has a valid
  tier / thinking / trust, no duplicate model ids across endpoints, every
  `api_key_setting`, `base_url_setting` and `enabled_setting` names a declared
  setting.
- **Resolver matrix** — a table-driven suite over the 24 requirement shapes
  against a fixture catalog, asserting the chosen model and asserting that
  unsatisfiable cells refuse with a message naming the gap.
- **Determinism** — the same requirement resolves identically across repeated
  calls and process restarts.
- **Local by default** — with every endpoint configured and a local model that
  clears the floor, resolution picks the local one; it reaches a vendor only
  when the floor cannot be met locally.
- **Gate agreement** — for every catalog model, the chat warning and the sealed
  egress gate read the same trust class (the regression the current
  `isPrivate()` / `isCloudModel()` split allows).
- **One resolution per run** — the gate and the dispatch receive the same
  resolution object. Asserted by mutating the catalog (or clearing an endpoint
  key) *after* the gate passes and confirming the run still dispatches within
  the resolution's approved candidate list or fails — never outside it. This is
  the sink-zero regression test.
- **Fail closed** — an unparseable catalog, an unknown pin, and an id in no
  endpoint each refuse rather than falling through to any available model.
- **Run provenance** — a completed run records the `rcr_model` and
  `rcr_endpoint` it actually used, and its `rcr_cost_estimate` is derived from
  `rcr_model`. Includes the case the current code gets wrong: an unpinned
  recipe running on a paid endpoint must record a non-zero cost.
- **Thinking directive** — a `thinking: none` model is excluded when the
  requirement is TRUE; an `always` model under `thinking_level: off` resolves
  and dispatches at the lowest effort rather than being refused; a level above
  `off` on a `none` model resolves, and the edit page states the level is
  ignored.
- **Failover never spends money** — with the local host made unreachable and
  every cloud endpoint configured, a `prefer_local` recipe FAILS; it does not
  resolve onto a paid endpoint. The inverse also holds: failover between two
  free local models is allowed — and only on before-first-token failure; a
  stream that dies mid-response is a failed run, never a second dispatch.
- **Control resolution order** — a control set on the recipe wins; unset, the
  catalog model's default wins; unset there, the plugin setting. Asserted with a
  model whose catalog default differs from the plugin setting, so a silent
  reordering is caught.
- **Pin failure modes** — a pin below the floor is refused at save; a saved pin
  that a release later puts below the floor refuses at run start, fail closed;
  a pin whose endpoint has no key falls back to requirement resolution and the
  run records the substitution. These must not collapse into one behaviour.
- **Usable context** — when the host reports a smaller live window than the
  catalog's nominal one, the smaller is what a job is told, and the gap raises
  a warning.
- **Seeder round trip** — a seeded recipe's requirement columns are NULL, its
  effective floor equals the job's (pipeline) or the declaration's (agent
  mode), and the pin is stripped. Then the cascade itself: raising the job's or
  declaration's floor changes the effective floor of the already-seeded row
  with no reseed — the regression that would recreate frozen per-install
  requirements.
- **Availability** — clearing an endpoint's key removes its models from
  resolution without an error anywhere else.
- **Chat default as pin** — a conversation with no pick starts on
  `joinery_ai_default_model`; when that model is unavailable the chat resolves
  normally and the substitution is visible; a Fortress conversation refuses a
  non-local default rather than silently using it.
- **Local model facts** — precedence per fact: a named reference-file override
  beats the probe beats the defaults; a probing host's vision model resolves a
  vision requirement with no reference entry; a non-probing host takes the
  stated defaults; and a `min_context` requirement against a host that did not
  report context refuses, naming the silence — never a guessed number.
- **Three-valued consent** — a domain at `trusted` consent permits a `trusted`
  endpoint and refuses a `cloud` one; at `local` it refuses both; the fold
  across a recipe's bound domains takes the strictest; and the seeding
  migration maps `false → 'local'`, `true → 'cloud'`. Sealed content on a
  `local`-consent domain reaching any off-box endpoint is the sink-zero
  regression.

## Docs

`docs/` has no LLM-provider page today; provider behaviour is documented inside
`plugins/joinery_ai/docs/overview.md`. This lands as a new section there
covering the catalog file, the trust classes, the tier ladder, and how to change
a model fleet-wide. `CLAUDE.md`'s documentation index needs no new line unless
the section is split into its own page.

## Settled

- **Trust classes are `local` / `trusted` / `cloud`.** Three, because two cannot
  express an off-box endpoint the operator has decided is safe.
- **Tiers are `basic` / `standard` / `capable` / `frontier`.** Four rungs, with
  `capable` = single-item adversarial judgement (pipeline mode) and `frontier` =
  sustained multi-step tool use (agent mode).
- **Err toward allowing less capable models.** A model on a boundary gets the
  higher rung; a recipe's arguable floor gets the lower one. A floor stops work
  reaching a model that cannot do it — it does not reserve work for the biggest
  model available. The 9B-vs-35B measurement is the evidence: a size class of
  difference bought nothing on the judgement task.
- **Local models can be `frontier`.** It is a hardware question, not a
  categorical one — a 128GB-class box running a 70B dense or a 120B MoE fills
  the rung. Today's Studio bindings top out at a 35B MoE, so the cell is empty
  on this fleet and the refusal says so.
- **Local gradings come from a shipped reference file**, not from the operator.
  `ai_model_reference.json`, named entries over a size-parsed ladder, each
  carrying `measured` or `research` provenance. The file owns **tier only**;
  a local model's mechanical facts (thinking, tools, context, attachments)
  come from the endpoint's declared probe, with reference-entry overrides and
  stated defaults for non-probing hosts (§3b).
- **Local is the standing policy.** `prefer_local` everywhere; the site
  setting is the only policy knob, and a single recipe's lever is the pin
  (Decision 8). Note this is a visible
  behaviour change on any site with a local box: a recipe that reaches a cloud
  vendor today (or fails trying — the dev install's 24 default-residue pins
  currently throw at run start because no Anthropic key is set) moves to the
  local host the day this ships, provided the local host clears its floor.
- **The catalog is plugin-local** — `plugins/joinery_ai/ai_endpoints.json`.
- **A stated floor and the fallback floor are different things.** A floor
  somebody stated — an operator's override, a job's `minTier()`, a shipped
  declaration — both filters and refuses. The platform's last-resort assumption
  filters, but never refuses:
  - it does not veto an explicit **pin** (the agent-mode fallback of `frontier`
    would otherwise refuse an operator's deliberate pin to a 9B on a box that
    serves nothing larger — punishing them for a default they never chose), and
  - when **nothing at all** clears it, resolution relaxes to the most capable
    model available and says so on the run and the edit page, rather than
    refusing. Caught by `taint_gate` during the build: without this, an
    agent-mode recipe could not be *saved* on a fleet whose largest local model
    grades `capable`, which is not what a default is for. A **stated** floor
    nothing meets still refuses, because someone asked for something this
    install cannot do and needs to know. §5, §6.
- **Among equally-priced survivors, take the LEAST capable that clears the
  floor.** On a local box every model is free, so dollars separate nothing, and
  running a 35B to answer "is this an advertisement" spends GPU a qualifying 9B
  would not. Catalog order — which for a local endpoint is the operator's own
  list order — breaks the remaining ties. §5.
- **The visibility trade is made knowingly.** Which model runs a recipe stops
  being a name an operator typed and becomes a consequence of a grading in a
  shipped file. Fleet-wide cascade is bought with that, and it is only safe
  because the resolution is stated back on the edit page and recorded on every
  run (§8). A mis-graded catalog is the failure mode to watch for, and the run
  record is how it gets caught.
- **Domain consent is three-valued, built in this pass.** `local` / `trusted` /
  `cloud`, same vocabulary as endpoint trust, stored per domain, strictest
  sealed address wins, seeded from the old boolean. §9a.
- **Failover never spends money.** A resolver may only fall back to a candidate
  costing no more than its first choice, so a sleeping local host never becomes
  a cloud bill. It fails instead, and an hourly recipe simply runs next hour.
  The fallback set is the resolution's own candidate list (§5), walked only on
  before-first-token failures.
- **Sampling defaults are per model, in the catalog.** One global temperature of
  0.3 for every model is a Claude-shaped number handed to qwen. Controls resolve
  recipe row → catalog model default → plugin setting → floor.
- **No local slots.** The operator's existing comma-separated
  `joinery_ai_local_model` list is graded from the reference file by tag; there
  is nothing to bind.
- **Chat picks its own model.** No job, no floor, no requirement columns on
  `aic_conversations` — the user chooses, or `joinery_ai_default_model` (kept,
  chat-only, site-wide-pin semantics) applies. Fortress's `local` trust floor
  is the only constraint chat carries, and it lives on the chat level, not in
  a column.
- **The operative context window is the minimum** of the catalog's nominal value
  and the host's live one, with a large gap surfaced as a warning. Pipeline jobs
  are told that number and size their digests against it.
- **A declaration cannot ship a selection policy.** An agent-mode declaration
  carries `min_tier`, `trust_floor`, `thinking_required` and `min_context`
  only (a pipeline declaration carries none — its job declares them, §10). A
  shipped recipe that must not reach a vendor says so with
  `trust_floor: local`, which is a statement about the work; a policy is a
  statement about the operator's preference and is not the publisher's to make.

## Decisions taken during design

Every item below was raised as an open question and resolved. Kept rather than
deleted because each records why the design is shaped the way it is.

**1. Local slots: CUT.** An earlier draft had the local endpoint binding
`small`/`mid`/`large` setting slots. That mechanism existed only because grading
was assumed to be manual per install. It is not: `ai_model_reference.json` grades
a local model from its tag, so the existing comma-separated
`joinery_ai_local_model` list is already a graded catalog with nothing to bind.
Slots and their three settings are gone; the Studio's four models grade
themselves. Applied throughout this spec.

**2. Failover never spends money. SETTLED.**

A timeout must never be the reason a bill appears. The rule is **failover may
never increase cost**: never free to paid, and never cheap to expensive. Local
inference is free, so a recipe resolved onto the local box that finds it asleep
does **not** quietly move to Fireworks or Anthropic — it fails, and being an
hourly recipe, it tries again next hour.

Concretely, the resolver may only walk to a candidate whose cost is less than or
equal to the first choice's. A free first choice can therefore only fail over to
another free candidate.

The mechanism is the candidate list inside the resolution (§5): the approved,
cost-truncated set is fixed at resolve time, dispatch walks it only on
before-first-token failures, and a mid-stream death is a failed run rather than
a retry. On this fleet that buys something real: the Studio's recurring memory
pressure can fail the 35B's load while the 9B still serves, and the hour's
`capable` work degrades to a measured sibling instead of failing — free, local,
and inside the approved set.

Note the trust floor needs no separate failover rule: it is a hard filter on the
candidate set, so a `local`-floored recipe never has an off-box candidate to
fall to in the first place. Cost is the axis that was actually unguarded.

**3. `AgentLoop` branches on provider identity in two places — and they are not
the same problem.**

*Branch A, line 147 — the per-call output cap:*

```php
'max_tokens' => $provider->id() === 'local' ? self::LOCAL_PER_CALL_MAX_TOKENS : self::PER_CALL_MAX_TOKENS,
```

Both constants are **16000**. The branch computes the same number either way —
it is dead today, kept only so the two could diverge. And the comment explains
why the value is what it is: *"Within every offered model's output limit (Claude
Haiku/Sonnet 64K, Opus 128K)"*. That is a lowest-common-denominator guess made
**because the code cannot see which model it is talking to**. The catalog can.
Recommend a per-model `max_output_tokens`, and delete both constants and the
branch.

*Branch B, line 313 — the live context probe:*

```php
if ($provider->id() !== 'local' || !method_exists($provider, 'hostContextWindow')) return null;
```

This one must NOT simply move into the catalog, because it asks a different
question. The catalog knows a model's **nominal** window; this probes Ollama for
what the host is **actually serving right now**, which is a different number —
the local memory guard spec records a real incident where Ollama defaulted to a
4,096-token window and broke turns on a model rated far higher.

So both values are wanted, and the operative one is the **minimum** of them —
SETTLED. A large gap between them is surfaced as a warning: *"this 256k model is
being served with a 24k window."* What goes is the `method_exists()` duck-typing
— "can this endpoint report its live context?" becomes the endpoint's declared
`probe` capability, the same declaration that serves the mechanical fact set at
resolve time (§3b), rather than a guess about a method name.

**3a. Per-model default controls in the catalog. SETTLED — add.**

There is one global `joinery_ai_default_temperature` (0.3) applied to every
model on the platform. That is a Claude-shaped number being handed to qwen,
which wants roughly 0.6–0.7; the platform is quietly mis-tuning its own local
models. Sampling defaults are a property of the model, so they belong beside it:

```jsonc
{ "id": "qwen3.6:35b-a3b-q4_K_M", "tier": "capable",
  "defaults": { "temperature": 0.7, "top_p": 0.95, "max_output_tokens": 16000, "thinking": "low" } }
```

Resolution for any control becomes: **recipe/chat row → catalog model default →
plugin setting → hard floor.** One more rung on a fallback chain that already
exists, and it makes "the right settings for this model" ship with the model
instead of being one number for all of them.

**4. Jobs are told how much room they got. SETTLED.**

`PipelineJobInterface` says the job owns the size cap so the digest "plus prompt
fit the smallest intended model's context", and `MarkAdvertisementsJob` hardcodes
`DIGEST_MAX = 1500` — a number chosen blind, then handed to a model that may
have a 200k window.

Since the resolver picks the model *before* the run, the job can simply be told.
`nextItem()` gains the resolution:

```php
public function nextItem(array $config, Recipe $recipe, AiModelResolution $model): ?array;
```

and a job sizes its digest against `$model->usableContext()` — the minimum of the
catalog's nominal window and the host's live one (item 3, Branch B) — instead of
a constant. A job that does not care ignores the argument and keeps its cap.

This is the mirror of `min_context` on the requirement: that is the job
*demanding* room before a model is chosen, this is the job *being told* how much
it got after. Both directions are wanted, and neither substitutes for the other.

**5. Drop `joinery_ai_endpoint_overrides`. SETTLED — cut.**

It was proposed as an escape hatch for three things, and every one is already
covered without it:

| Wanted | Already possible |
|---|---|
| disable an endpoint | clear its API key — the endpoint drops out of resolution by definition |
| re-grade a model locally | pin it on the recipe, or add a named entry to the reference file and publish |
| point at a different host | `joinery_ai_local_base_url` / `joinery_ai_fireworks_base_url` are already settings |

So it buys nothing, and it costs an unvalidatable free-form JSON blob in a
settings textarea — a shape this platform has been bitten by before. Cut until a
real install demonstrates a need.

**6. Chat inherits nothing, by design. SETTLED.**

A chat has no job and takes no capability floor — `aic_conversations` gains
none of the requirement columns. The user picks a model from the dropdown, or
the chat starts on `joinery_ai_default_model`, which survives as a chat-only
setting behaving like a site-wide pin (§7): unavailable → normal resolution,
recorded. The only constraint chat carries is the Fortress trust floor
(`local`), which is a property of the chat level — enforced by the resolver
from the level, stored nowhere per conversation. §4a's "nobody fills these in"
story is about recipes; chat is a deliberate pick-your-own surface and stays
that way.

**7. Thinking requirement collapsed to a boolean. SETTLED.**

An earlier draft had `off`/`allowed`/`required` — 3 values against 4 levels,
12 combinations with no interaction rules. `off` merely duplicated
`thinking_level: off`, and `allowed` still permitted the headline defect (a
high level silently ignored by a non-reasoning model). Now: the boolean
filters candidates, the level tunes the survivor, and the one residual
mismatch is stated on the edit page instead of being legislated.

**8. Per-recipe selection policy: CUT.**

`rcp_selection_policy` was a third override axis beside the pin and the
requirement. The site policy sets posture; a recipe that must have a specific
model pins it. No named use case survived: "this one recipe should be
`cheapest` but I refuse to pin it" describes nobody. Same call as Decision 5 —
cut until a real install demonstrates a need; it can return as a pure column
addition.

## Deferred

- **Per-owner trust floors** — a member raising their own floor (never lowering
  it) above the recipe's, so one user's recipes stay on their own hardware while
  another's may use a vendor. Not in this build.
