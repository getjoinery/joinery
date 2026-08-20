<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));

/**
 * Contract for a pipeline job — the per-item unit of work a pipeline-mode
 * recipe runs. Where an agent-mode recipe hands the model a tool belt and
 * lets it drive, a pipeline job hands PipelineRunner four things: how to find
 * the next unhandled item, how to render it as a bounded digest, what a valid
 * verdict looks like, and what to do with one. Everything else — scheduling,
 * budgets, provider selection, retries, the kill switch, run history,
 * delivery — is inherited unchanged from the recipe machinery.
 *
 * Jobs live in `plugins/{plugin}/pipeline_jobs/` (a sibling of
 * `recipe_tools/`) and are discovered by PipelineJobRegistry the same way
 * RecipeToolRegistry discovers tools.
 *
 * See specs/joinery_ai_item_pipeline.md for the full design.
 */
interface PipelineJobInterface {

    /**
     * Posture subsets for hasWork()/countWork(). A binding can span sources
     * with different security postures (the email jobs: sealed and standard
     * mailboxes on one list), and the two schedulers each own one subset —
     * cron drains what needs no vault window, the in-window drain handles the
     * sealed remainder — so each asks about its own subset only.
     */
    const POSTURE_SEALED   = 'sealed';
    const POSTURE_STANDARD = 'standard';

    /** Stable identifier stored in rcp_pipeline_job (snake_case, unique). */
    public function id(): string;

    /** Human-readable label for the job-select dropdown. */
    public function label(): string;

    /**
     * Per-recipe binding config, DescriptorValidator shape (the `input` map
     * consumed by DescriptorValidator::coerce()). Rendered on the edit form
     * via FormWriter's fromDescriptor(); validated at recipe save time.
     */
    public function configDescriptor(): array;

    /**
     * Additional validation beyond the descriptor's type/shape checks —
     * notably confirming the recipe OWNER has access to what the config
     * names (e.g. holds a mailbox grant on the chosen alias). Throws on
     * failure; the message surfaces as the save-time form error.
     */
    public function validateConfig(array $config, Recipe $recipe): void;

    /**
     * Whether item digests carry attacker-controlled text (an inbound email
     * body, a user-submitted message, etc.). Drives the taint posture: a job
     * that declares true makes its recipes tainted-capable regardless of the
     * (empty, in pipeline mode) tool/model allow-lists — see TaintGate.
     */
    public function untrustedDigest(): bool;

    /**
     * The vault scope this job's items require, or null when it can read them
     * without one (specs/in_window_deferred_work.md).
     *
     * Answered from $config because the same job can need a window for one
     * binding and not another — the email jobs need one only when the mailbox
     * they point at is on a sealed domain.
     *
     * A job returning non-null cannot read its sealed sources from cron AT
     * ALL: the vault secret lives in APCu keyed to the browser session, so a
     * command-line worker can never hold a window. The sealed part of such a
     * binding is executed only in slices inside its owner's open window, via
     * the VaultDeferredWork consumer. Whether the recipe still runs from cron
     * for the REST of its binding is hasUnsealedBinding()'s answer.
     */
    public function requiresVaultScope(array $config): ?string;

    /**
     * Can a cron worker make progress on this binding at all — is any part of
     * it readable without a vault window?
     *
     * Only consulted when requiresVaultScope() is non-null: a mixed binding
     * (sealed and standard sources on one list) is scheduled normally, and on
     * a cron worker the sealed sources fail closed out of the candidate set
     * while the standard remainder drains. A job with nothing sealed must
     * return true.
     */
    public function hasUnsealedBinding(array $config): bool;

    /**
     * How far may this binding's content travel to be read by a model? The
     * most permissive endpoint trust class it may reach: 'local' | 'trusted' |
     * 'cloud'.
     *
     * Asked only when requiresVaultScope() is non-null — a binding with nothing
     * sealed has nothing to protect, so a job with no sealed source returns
     * 'cloud'. For the email jobs this is the domain's explicit second consent,
     * separate from and narrower than its consent to AI reading at all
     * (specs/implemented/sealed_content_egress.md, resolved decision 5), folded
     * to the STRICTEST answer across every sealed address in the binding.
     *
     * Three values rather than a boolean because "a vendor I have accepted
     * terms with, but not a general cloud" is a distinction an operator can
     * reasonably want and a yes/no cannot express. Same vocabulary an endpoint
     * uses for its own trust class, so the gate is a comparison.
     *
     * Checked when the recipe is saved AND again at run start, so withdrawing
     * consent stops a recipe at its next run instead of letting it continue
     * silently — the same one-way-tightening rule as the taint gate.
     */
    public function processingConsent(array $config): string;

    /**
     * The capability floor this job's judgement needs — one of
     * AiModelRequirement::TIER_*.
     *
     * The job declares this, not the recipe, because the job is the only party
     * that knows: a security scan reads attacker-controlled mail and needs a
     * model that resists manipulation; marking advertisements is a yes/no on a
     * short feed item and almost anything can do it. The operator who binds a
     * mailbox to a recipe knows neither and should never be asked.
     *
     * This is the same kind of fact as untrustedDigest() and
     * requiresVaultScope() — declared by the job, consulted by the runner,
     * invisible on the recipe form — so it gets the same treatment. Read live
     * at resolve time and never copied into a row, which is what lets a floor
     * raised in a later release reach every existing install with no reseed.
     *
     * Grade the floor as LOW as the work honestly tolerates. A floor exists to
     * stop work reaching a model that cannot do it; it does not reserve work
     * for the biggest model available.
     */
    public function minTier(): string;

    /**
     * How far this job's content may travel by default, as a trust floor:
     * 'local' | 'trusted' | 'any'.
     *
     * A floor, not a permission. processingConsent() still gates what the
     * bound sources actually allow, and the STRICTER of the two wins — so a job
     * declaring 'any' never widens what a domain refused.
     */
    public function defaultTrustFloor(): string;

    /**
     * The next unhandled item for this recipe, or null when the recipe is
     * caught up. The job chooses the order; the email jobs take the newest
     * first, so fresh arrivals are judged ahead of a backlog rather than
     * behind one. MUST exclude items already present in the
     * processing log (aip_recipe_item_log) — MultiAipRecipeItemLog provides
     * the NOT-EXISTS building block for this.
     *
     * Return shape: ['item_key' => string, 'digest' => string, 'label' => string]
     *   - item_key: the job-scoped item identity (e.g. a message id)
     *   - digest: deterministic, bounded-size plain text rendering of the
     *     item for the model to judge. The runner wraps it in the untrusted
     *     block when untrustedDigest() is true.
     *   - label: a short human string for the run tally (e.g. the subject)
     *
     * $model is the resolution this run is dispatching on, so a job can size
     * its digest against $model->usableContext() — the smaller of the catalog's
     * nominal window and what the host is actually serving — instead of a
     * constant chosen blind. This is the mirror of the min_context requirement:
     * that is the job DEMANDING room before a model is chosen, this is the job
     * being TOLD how much it got. A job that does not care ignores the argument.
     */
    public function nextItem(array $config, Recipe $recipe, AiModelResolution $model): ?array;

    /**
     * Is there at least one unhandled item? Same rules as nextItem(), asked
     * without building an item.
     *
     * It must stay CHEAP — a single indexed query, no decryption, no digest
     * construction, no model call. The vault heartbeat calls this for every
     * in-window recipe on every beat to decide whether a drain is worth firing
     * (specs/in_window_deferred_work.md).
     *
     * $posture narrows the question to one subset of the binding (the
     * POSTURE_* constants); null asks about everything readable right now.
     * The heartbeat asks POSTURE_SEALED — standard-source work belongs to
     * cron's schedule, and answering for it here would turn every open window
     * into a drain-on-arrival bypass of the recipe's schedule.
     */
    public function hasWork(array $config, Recipe $recipe, ?string $posture = null): bool;

    /**
     * How many unhandled items remain. Separate from hasWork() because the two
     * have different costs and different callers: hasWork() is an EXISTS on the
     * heartbeat path, this is a COUNT used to tell someone how far behind they
     * are. Never call this per beat. $posture as in hasWork().
     */
    public function countWork(array $config, Recipe $recipe, ?string $posture = null): int;

    /**
     * Anything a run should say about what its binding does NOT cover right
     * now — one plain-language line per gap, rendered into the run tally. The
     * email jobs name a listed address that has stopped resolving (grant
     * revoked, mailbox disabled) or whose domain sealed itself without the AI
     * opt-in, so coverage never shrinks silently. Empty array when the whole
     * binding is covered.
     */
    public function coverageNotes(array $config, Recipe $recipe): array;

    /**
     * The verdict contract, DescriptorValidator shape. The runner renders
     * this into the model-facing output instruction (via
     * DescriptorValidator::renderOutputInstruction()) and validates the
     * model's answer against it — schema and prompt can't drift apart
     * because the prompt half is generated from this.
     */
    public function verdictDescriptor(): array;

    /**
     * Extra validation beyond verdictDescriptor()'s schema — a cross-field
     * consistency rule a type/enum/range check can't express (e.g. "the
     * verdict field must agree with the score field"). Throw
     * InvalidArgumentException to reject an otherwise schema-valid verdict;
     * the runner catches it exactly where it catches a schema failure, so a
     * rejection gets the same one retry (with this message fed back to the
     * model) before the item is skipped as an error. No-op
     * (`function validateVerdict(array $verdict): void {}`) for a job with
     * no cross-field rule.
     */
    public function validateVerdict(array $verdict): void;

    /**
     * The job's built-in instruction prompt, used whenever the recipe's
     * rcp_prompt is empty (the normal case — a non-technical admin never
     * writes or sees a prompt). A non-empty rcp_prompt replaces this
     * entirely (power-user override).
     */
    public function defaultPrompt(): string;

    /**
     * Persist one validated verdict for $item_key. The ONLY write path in
     * pipeline mode — owner/scope is fixed by the job, never by model
     * output. $model is the model id that produced the verdict, in case the
     * job wants to record provenance.
     */
    public function recordVerdict(string $item_key, array $verdict, Recipe $recipe, string $model): void;

}
