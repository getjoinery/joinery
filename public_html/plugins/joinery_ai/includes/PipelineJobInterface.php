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
     * The next unhandled item for this recipe, oldest first, or null when
     * the recipe is caught up. MUST exclude items already present in the
     * processing log (aip_recipe_item_log) — MultiAipRecipeItemLog provides
     * the NOT-EXISTS building block for this.
     *
     * Return shape: ['item_key' => string, 'digest' => string, 'label' => string]
     *   - item_key: the job-scoped item identity (e.g. a message id)
     *   - digest: deterministic, bounded-size plain text rendering of the
     *     item for the model to judge. The job owns the size cap so the
     *     digest plus prompt fit the smallest intended model's context. The
     *     runner wraps it in the untrusted block when untrustedDigest() is true.
     *   - label: a short human string for the run tally (e.g. the subject)
     */
    public function nextItem(array $config, Recipe $recipe): ?array;

    /**
     * The verdict contract, DescriptorValidator shape. The runner renders
     * this into the model-facing output instruction (via
     * DescriptorValidator::renderOutputInstruction()) and validates the
     * model's answer against it — schema and prompt can't drift apart
     * because the prompt half is generated from this.
     */
    public function verdictDescriptor(): array;

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
