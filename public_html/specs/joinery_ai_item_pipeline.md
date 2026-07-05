# Joinery AI — Item pipeline mode for recipes

**Status:** Ready to implement
**Plugin:** `joinery_ai`
**Consumers:** `joinery_ai_email_security_scan.md` (first job, ready),
`joinery_ai_email_triage.md` (triage half rides this when it lands)

## Goal

A second execution mode for recipes, for the job shape both email specs
independently arrived at: **work through a stream of items, judge one item at a
time, record a structured verdict per item.**

In agent mode the model drives — it decides what to call and when to stop, and
one conversation carries every item it touches. That is the right shape for
open-ended jobs and the wrong shape for per-item judgment: a 4B local model
asked to juggle a fetch/record tool rhythm across ten emails degrades, while the
same model given one item in a fresh context with a fixed output contract is
reliable (measured: 0/10 → 10/10 on the same phishing sample). In pipeline mode
**PHP drives**: deterministic item selection, one bounded LLM exchange per item,
platform-validated output, platform-managed idempotency. The model judges;
everything else is code.

What a job author writes shrinks to four things: how to enumerate unhandled
items, how to render one item as a bounded digest, what the verdict looks like,
and what to do with a valid verdict. Scheduling, budgets, provider selection,
retries, kill switch, run history, delivery — all inherited from the existing
recipe machinery.

## What already exists (and is reused as-is)

- **Recipe/run lifecycle** — `Recipe`, `RecipeRun`, `RecipeDispatcher`,
  `RecipeWorkerSpawner`, the runs UI, `rcr_kill_requested`, delivery email,
  failure-email throttling, `CostGuard` and the token/cost columns.
- **RecipeRunner pre-flights** — owner-active, taint-drift, allow-list
  staleness, actor session. All four run unchanged before either mode.
- **Provider layer** — `LlmProviderFactory::forModel()` +
  `LlmProviderInterface::createMessage()` (blocking, canonical shape). The
  pipeline makes plain non-tool calls; no provider changes.
- **Prompt building** — `AiPromptBuilder::systemBlocks()` /
  `untrustedInputBlock()` and the `<<UNTRUSTED_$nonce>>` wrapping convention.
- **`DescriptorValidator`** — the existing input coercer, extended (§4), which
  its own header anticipates.
- **`rcr_tool_calls`** (jsonb on runs) — repurposed in pipeline mode to store
  the per-item record for the runs UI; no schema change.

## Work to do

### 1. The job interface + registry

`plugins/joinery_ai/includes/PipelineJobInterface.php` and
`PipelineJobRegistry.php` (same registration pattern as `RecipeToolRegistry` /
`ActionRegistry`; plugins register jobs, `checkAllowlistStaleness` learns to
verify the job id still resolves).

```php
interface PipelineJobInterface {
    public function id(): string;            // e.g. 'email_security_scan'
    public function label(): string;

    /** Per-recipe binding config, DescriptorValidator shape. Validated at
     *  recipe save; validateConfig() additionally checks the OWNER's access
     *  (e.g. holds a mailbox grant on the chosen alias). */
    public function configDescriptor(): array;
    public function validateConfig(array $config, Recipe $recipe): void; // throws

    /** Whether item digests are attacker-controlled text (email: yes).
     *  Drives the taint posture (§6). */
    public function untrustedDigest(): bool;

    /** Next unhandled item for this recipe, oldest first, or null.
     *  MUST exclude items already in the processing log (helper provided).
     *  Returns ['item_key' => string, 'digest' => string, 'label' => string].
     *  item_key is the job-scoped identity (e.g. the message id); label is a
     *  short human string for the run tally (e.g. the subject). */
    public function nextItem(array $config, Recipe $recipe): ?array;

    /** The verdict contract, DescriptorValidator shape (§4). */
    public function verdictDescriptor(): array;

    /** The job's built-in instruction prompt — used whenever the recipe's
     *  rcp_prompt is empty, which is the normal case. A non-empty rcp_prompt
     *  replaces it entirely (power-user override). Ships with the job so a
     *  non-technical admin never writes or sees a prompt. */
    public function defaultPrompt(): string;

    /** Persist one validated verdict. The ONLY write path in pipeline mode —
     *  owner/scope fixed by the job, never by model output. */
    public function recordVerdict(string $item_key, array $verdict,
                                  Recipe $recipe, string $model): void;
}
```

The digest is produced by the job (deterministic PHP, bounded size — the job
owns its size cap so digest + prompt fit the smallest intended model's
context). The runner wraps it in the untrusted block; jobs return plain text.

### 2. Recipe model changes

```php
'rcp_mode'          => array('type'=>'varchar(20)', 'default'=>'agent'), // agent | pipeline
'rcp_pipeline_job'  => array('type'=>'varchar(100)'),
'rcp_source_config' => array('type'=>'jsonb'),
```

Validation in `Recipe::prepare()` (validation only, per the prepare() rule):
pipeline mode requires a registered job; `rcp_source_config` must pass the
job's `configDescriptor()` + `validateConfig()`.

Existing fields reinterpreted in pipeline mode — no new budget columns:

- `rcp_max_iterations` = **max items per run** (the batch size).
- `rcp_max_tokens` = total token budget across the run's calls, as today.
- `rcp_allowed_tools` / `rcp_allowed_models` / `rcp_allowed_actions` /
  `rcp_workspace` — unused; the edit form hides them in pipeline mode
  (`visibility_rules` on the mode select). Pipeline recipes are deliberately
  stateless between runs: the processing log is the only carried state, so
  there is no workspace-poisoning surface.

### 3. `PipelineRunner`

`plugins/joinery_ai/includes/PipelineRunner.php`. `RecipeRunner::run()`
branches on `rcp_mode` after the pre-flights and CostGuard — everything before
and after the loop (status transitions, token/cost recording, terminal-state
mapping, delivery) stays in `RecipeRunner`'s existing helpers.

Per run:

```
items_done = 0; consecutive_errors = 0
while items_done < rcp_max_iterations:
    check rcr_kill_requested            -> finish cancelled
    check remaining token budget        -> finish (tally so far, note budget)
    item = job->nextItem(config, recipe) -> null: finish (all caught up)
    exchange:
        system  = preamble + (rcp_prompt if non-empty, else job->defaultPrompt())
                + rendered verdict-JSON instruction (from verdictDescriptor)
                + untrustedInputBlock (when job->untrustedDigest())
        user    = the digest wrapped in <<UNTRUSTED_nonce>> ... <</UNTRUSTED_nonce>>
        provider->createMessage(...)    (temperature/top_p/thinking resolved
                                         exactly as agent mode resolves them)
    parse: strip any <think> remnant, extract first {...} JSON object,
           DescriptorValidator::coerce against verdictDescriptor
        invalid -> ONE retry: same exchange + the specific validator error
        still invalid -> log item as 'error', consecutive_errors++, continue
    job->recordVerdict(...); log item as 'done'; consecutive_errors = 0
    append per-item record to rcr_tool_calls; items_done++
    3 consecutive item errors -> finish failed
finish success: rcr_output = Markdown tally (one line per item: label + verdict
gist + 'error' rows), which feeds the existing dashboard/delivery email
```

Two deliberate contracts: **each item is a fresh exchange** (no conversation
carry-over — this is the measured reliability win, and it makes per-item cost
flat), and **an unparseable verdict skips that item, never wedges the queue**
(the error row in the log stops reselection; an admin can clear it to retry).

### 4. `DescriptorValidator` extensions

The verdict contract needs slightly more than the v1 input coercer has. Add,
keeping the same descriptor style:

- `enum` — list of allowed values (string fields).
- `min` / `max` — bounds for int/float.
- `max_length` — strings.
- type `array` — with `items` holding a nested field descriptor map (array of
  objects), plus `max_items`.

These serve every consumer of the validator, not just the pipeline. From the
verdict descriptor the runner also **renders the output instruction** ("Respond
with ONLY this JSON: {...}" with types, enums, and bounds shown) — schema and
prompt cannot drift apart because the prompt half is generated.

### 5. Processing log (platform idempotency)

New data class, `joinery_ai` plugin:

```php
// aip_recipe_item_log
'aip_log_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
'aip_rcp_recipe_id'  => array('type'=>'int8', 'required'=>true,
                              'unique_with'=>array('aip_item_key')),
'aip_item_key'       => array('type'=>'varchar(100)', 'required'=>true),
'aip_rcr_run_id'     => array('type'=>'int8'),
'aip_status'         => array('type'=>'varchar(10)'),   // done | error
'aip_processed_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
```

Per-recipe, per-item, exactly once — the generalization of the
`aie_email_processing` table the triage draft sketched, owned by the platform
so no job reinvents it. A helper on its Multi gives jobs the NOT-EXISTS
building block for `nextItem()`.

### 6. Taint posture in pipeline mode

A pipeline recipe whose job declares `untrustedDigest()` reads
attacker-controlled text and writes (through `recordVerdict`), so it is
tainted-capable and requires `rcp_allow_tainted_writes` — same acknowledgment,
same gate, evaluated by mode: `TaintGate` for pipeline recipes derives
tainted-capability from the job declaration instead of the tool/model
allow-lists (which are empty). The structural difference from agent mode is
that the acknowledgment covers a far smaller surface: model output can reach
exactly one validated handler, aimed by config, never by the model.

### 7. Recipe edit form

- Mode select (`agent` / `pipeline`) with `visibility_rules` swapping the
  agent-mode fields (tools/models/actions/workspace) for the pipeline fields
  (job select, config fields generated from `configDescriptor()` via
  FormWriter, batch size label on `rcp_max_iterations`).
- In pipeline mode the prompt field is optional, labeled "Custom instructions
  (optional)" and empty by default — the job's `defaultPrompt()` runs. A
  non-technical admin creating a pipeline recipe therefore touches only: job,
  the job's config fields, model, and schedule.
- Runs UI: pipeline runs render the per-item records from `rcr_tool_calls`
  (item label, verdict gist / error) above the output tally. No new pages.

## What does NOT change

- Agent mode, chat, tools, actions, providers, CostGuard, scheduling, workers,
  and every existing recipe row (`rcp_mode` defaults to `agent`).
- No runner-level JSON forcing for agent mode; structured output is a pipeline
  contract, delivered by validation + one retry, provider-agnostic.

## Security & cost

- **The model chooses nothing about scope.** Item selection, digest content,
  write destination, and batch size are PHP/config. Model output influences
  only the validated verdict fields for the one item it was shown.
- **Injection blast radius** = a wrong verdict on the item carrying the
  injection. Jobs whose digests are untrusted must say so (§6) and carry the
  taint acknowledgment.
- **Cost is flat and predictable**: batch × (digest + prompt + verdict) tokens,
  bounded by the existing per-run and monthly caps. Fresh-context items mean a
  stable prompt prefix per run — cacheable on providers that support it, free
  on local.

## Boundaries — what this deliberately does not do

Reference for the day a recipe idea doesn't quite fit. Each entry: the symptom
you'll hit, why the capability is excluded, and what to do instead. None of
these are oversights; each was declined on purpose, and the entry says what
new evidence would justify building it.

**"The model needs to compare items to each other."**
(Find the five most important emails this week; dedupe similar tickets;
cluster feedback.) The pipeline shows the model exactly one item per exchange
— that isolation is the source of its reliability on small models and its
injection containment, so it can't be relaxed per-job. *Instead:* agent mode
with `query_model` handles modest cross-item questions. If a real use case
needs heavy cross-item reasoning at volume, that's a third mode (batch
digest → one exchange over many items), not a pipeline tweak.

**"The model needs to look something up mid-judgment."**
(Check this URL against a blocklist; fetch the sender's order history.) A
pipeline exchange has no tools, by design — tool use is where small models
fail and where injected text gets leverage. *Instead:* enrich the digest —
the job's PHP can look up anything deterministically and include it as
evidence ("sender has 3 prior orders"). If the lookup genuinely depends on
what the model concludes mid-thought, the job doesn't fit the pipeline; use
agent mode and a capable model.

**"This should run the instant the item arrives."**
Recipes poll on a schedule; there is no on-arrival trigger anywhere in the
recipe system. Excluded because the ingest paths (Postfix, webhooks, future
sources) would each need event plumbing, for a latency win no current use
case needs — an hourly scan of new mail is minutes-stale on average.
*Instead:* shorten the schedule. If a use case truly needs seconds-level
response, that's an ingest-side hook spec, and the pipeline job it invokes
can stay exactly as designed.

**"The model's JSON keeps failing validation — why not force it?"**
Some providers can hard-guarantee JSON output (Anthropic tool-forcing, OpenAI
response_format); the pipeline instead validates and retries once. Chosen
because the guarantee doesn't exist uniformly across the local runtimes this
must run on, and one contract everywhere beats two code paths. *Instead:*
fix the verdict descriptor or the prompt (measured failure rates were ~0 on
4B models with the generated instruction). If a provider-native guarantee is
ever wanted, it slots inside the exchange step without touching any job.

**"My job needs state between items or runs."**
(Running totals; "only alert once per sender per week.") Pipeline recipes are
stateless on purpose — the agent-mode workspace is a prompt-injection
carry-over surface, and the pipeline's containment story depends on not
having one. *Instead:* state belongs in the data the handler writes — the
verdict fields and processing log are queryable, so "already alerted this
sender" is a `nextItem()`/handler-side check in PHP, where it's deterministic
and injection-proof.

**"A job wants to reuse another job's digest / verdict shape."**
There's one job interface, not separate pluggable source/presenter/verdict
parts. Two consumers weren't enough to know where the seams go; jobs share
code the ordinary way (the scan job delegates to `EmailSecurityDigest`, which
any job may also use). *Instead:* extract shared classes freely. When a third
job genuinely wants to mix one job's source with another's verdict, decompose
the interface then — the seams will be visible in the duplication.

**"Just add a config field to the job."**
The UX holds only while a pipeline recipe is job → two-or-three config
questions → schedule. Config creep is the failure mode that re-creates the
old expert form one knob at a time. *Rule of thumb:* a job wanting six config
fields is two jobs; a config field only the author understands should be a
constant in the job's code.

## Open questions (resolved)

1. ~~One registered thing or source/presenter/verdict decomposed?~~ **One job
   interface.** The email specs need exactly two jobs; decomposing into
   reusable sources/presenters is speculative until a third job wants to mix
   parts. The interface keeps that door open (a job is free to delegate to a
   shared digest class — the scan job does).
2. ~~Idempotency per-recipe log vs. marker field on the item?~~ **Platform
   log.** Per-recipe is the correct general semantic (two recipes may
   legitimately process one item for different jobs); jobs remain free to
   *also* stamp their output on the item, as the scan job does with its
   verdict fields.
3. ~~Force JSON via tool_use / response_format?~~ **No — JSON-in-text +
   validate + one retry.** Works identically on Anthropic, Fireworks, and
   every OpenAI-compatible runtime; measured reliable on 4B-class models with
   the generated instruction block. Provider-native forcing can be added later
   inside `createMessage` callers without touching jobs.
4. ~~New batch column?~~ **Reuse `rcp_max_iterations`.** Same meaning — how
   many model exchanges a run may make — different label by mode.

## Implementation outline

1. `DescriptorValidator` extensions (§4) + unit tests (enum, bounds,
   array-of-objects, generated instruction rendering).
2. `PipelineJobInterface`, `PipelineJobRegistry`, `aip_recipe_item_log` data
   class + Multi helper; sync schema.
3. `Recipe` fields + `prepare()` validation; sync schema; edit form mode
   switch with `visibility_rules`.
4. `PipelineRunner` + the `RecipeRunner::run()` mode branch; per-item records
   into `rcr_tool_calls`; runs UI rendering.
5. A trivial fixture job under `tests/` exercising the loop end-to-end against
   a mock provider: happy path, invalid-verdict retry, skip-on-error,
   kill-switch, budget stop.
6. `php -l` + `validate_php_file.php` on every touched file; bump
   `plugins/joinery_ai/plugin.json` version.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` in
current-state voice: an "Item pipeline recipes" section — the two modes and
when each fits, the job interface contract, the verdict descriptor + generated
output instruction, the processing log, and the taint posture for
untrusted-digest jobs.

The doc must also carry a **"Pipeline boundaries"** subsection — the
§ Boundaries list above, restated in current-state voice (what the pipeline
does not do and what to use instead, without the design-history rationale).
That list is the diagnostic for "this recipe idea doesn't quite fit"; it
belongs where the next job author will read it, not only in this spec.
