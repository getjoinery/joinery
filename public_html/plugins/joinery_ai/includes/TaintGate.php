<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelWriteExecutor.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RiskHeuristic.php'));

/**
 * Static taint gate — the predicate behind the write-side defense against
 * prompt injection via untrusted text. A recipe is *tainted-capable* if its
 * allowed tools can write something the world sees AND it can read content
 * written by other people: an allowed model with $ai_untrusted_fields, a
 * web tool (a fetched page is a stranger's text), or LLM-curated workspace
 * state carried across runs.
 *
 * What the predicate DOES differs by mode (specs/security_inventory.md S16):
 *   - agent mode: a tainted-capable recipe QUEUES its writes for the owner's
 *     approval instead of executing them (RecipeRunContext::queuesWrites()).
 *     No acknowledgment is asked for, because nothing changes without a
 *     click. The editor says so.
 *   - pipeline mode: the model returns one verdict and the job writes one
 *     fixed field — a bounded menu, which is what keeps routine volume out of
 *     the queue — so the owner's standing approval (rcp_allow_tainted_writes)
 *     is asked for at save and re-checked at run start (drift: the job began
 *     declaring untrustedDigest() after the recipe was saved).
 *
 * One-way tightening: a predicate that becomes false again does not
 * auto-clear a recipe's opt-in. The opt-in is admin acknowledgment,
 * not derived state.
 *
 * Returns an evaluation object so callers can render targeted text.
 *   { tainted_capable: bool, write_tools: string[], untrusted_models: string[],
 *     untrusted_web: string[], workspace_present: bool }
 *
 * @version 2.0 - memory and note writes count as writes, web tools as an
 *   untrusted source; the agent-mode consequence is queuing, not a gate (S16)
 */
class TaintGate {

    /**
     * The tools whose writes reach somewhere other than the recipe itself:
     * the generic model writes and actions, and the memory and note writes
     * the next conversation reads. set_workspace is left out on purpose — the
     * workspace is the recipe's own scratchpad, read only by that recipe and
     * always wrapped as untrusted (RecipeRunContext::OWN_STATE_TOOLS).
     */
    public static function writeTools(): array {
        return array_values(array_unique(array_diff(
            array_merge(ModelWriteExecutor::WRITE_TOOL_NAMES, RiskHeuristic::STATE_WRITE_TOOLS),
            RecipeRunContext::OWN_STATE_TOOLS
        )));
    }

    /** The evaluation for a saved recipe, in either mode, from its own columns. */
    public static function forRecipe(Recipe $recipe): array {
        if ((string)$recipe->get('rcp_mode') === Recipe::MODE_PIPELINE) {
            $job = PipelineJobRegistry::get((string)$recipe->get('rcp_pipeline_job'));
            return self::evaluate([], [], '', $job !== null && $job->untrustedDigest());
        }
        return self::evaluate(
            self::decodeList($recipe->get('rcp_allowed_tools')),
            self::decodeList($recipe->get('rcp_allowed_models')),
            (string)$recipe->get('rcp_workspace')
        );
    }

    private static function decodeList($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * $pipeline_untrusted_digest is the pipeline-mode substitute for the
     * tool/model allow-list surface, which is empty in pipeline mode (there
     * are no tools or models to check). Pass true when the recipe is in
     * pipeline mode and its job declares untrustedDigest() — the write path
     * is then recordVerdict() rather than a checked tool, aimed by config,
     * never by the model. See specs/joinery_ai_item_pipeline.md § Taint
     * posture. Both existing (agent-mode) callers are unaffected: the
     * default preserves prior behavior exactly.
     */
    public static function evaluate(array $allowed_tools, array $allowed_models, string $workspace,
            bool $pipeline_untrusted_digest = false): array {
        $allowed_tools = array_map('strval', $allowed_tools);
        $write_tools = array_values(array_intersect($allowed_tools, self::writeTools()));
        $untrusted_web = empty($write_tools) ? []
            : array_values(array_intersect($allowed_tools, RiskHeuristic::WEB_EGRESS_TOOLS));

        $untrusted_models = [];
        if (!empty($write_tools)) {
            $registry = ModelRegistry::all();
            foreach ($allowed_models as $class) {
                if (!is_string($class) || $class === '') continue;
                if (!isset($registry[$class])) continue;
                $u = $registry[$class]['untrusted_fields'] ?? [];
                if (is_array($u) && !empty($u)) $untrusted_models[] = $class;
            }
        }

        $workspace_present = trim($workspace) !== '';

        if ($pipeline_untrusted_digest) {
            $write_tools[] = 'record_verdict';
            $untrusted_models[] = 'pipeline item digest';
        }

        $tainted = !empty($write_tools)
            && (!empty($untrusted_models) || !empty($untrusted_web) || $workspace_present);

        return [
            'tainted_capable'   => $tainted,
            'write_tools'       => $write_tools,
            'untrusted_models'  => $untrusted_models,
            'untrusted_web'     => $untrusted_web,
            'workspace_present' => $workspace_present,
        ];
    }

    /**
     * The plain-language text a person reads before giving a recipe its
     * standing approval to act on other people's content. Every surface that
     * shows the gate (the recipes dashboard, the AI panel's confirm dialog)
     * renders this same wording. The UI vocabulary rule
     * (specs/implemented/ai_action_queue.md § UI vocabulary): speak of standing approval
     * and outside influence — the word "taint" never reaches a person.
     */
    public static function explain(array $eval): string {
        // Pipeline mode gets its own wording because the generic one overstates
        // what the person is agreeing to. In pipeline mode the model cannot
        // choose what to write: it returns one verdict for one item and the job
        // writes a fixed field on that same item. There is no tool belt to steer.
        if (in_array('record_verdict', $eval['write_tools'], true)) {
            return 'This recipe reads content written by other people — an email body, say — '
                 . 'and a message could try to steer the AI. What that can affect is narrow: '
                 . 'the model returns one verdict for one item from a fixed menu, and the '
                 . 'recipe writes a fixed field on that same item. It cannot pick a different '
                 . 'record, a different field, or a different action — the most a message can '
                 . 'do is mis-pick from that menu. Agreeing here is your standing approval for '
                 . 'exactly that.';
        }

        $tools = implode(', ', $eval['write_tools']);
        return "This recipe can change things ($tools) while it " . self::reasons($eval)
             . ', so outside text could try to steer what it changes. Its changes are '
             . 'therefore queued for your approval instead of applied on their own: each '
             . 'one becomes a card you approve or decline.';
    }

    /** "reads content written by other people (…) and …" — the untrusted sources, named. */
    public static function reasons(array $eval): string {
        $reasons = [];
        if (!empty($eval['untrusted_models'])) {
            $reasons[] = 'reads content written by other people (' . implode(', ', $eval['untrusted_models']) . ')';
        }
        if (!empty($eval['untrusted_web'])) {
            $reasons[] = 'reads pages from the web (' . implode(', ', $eval['untrusted_web']) . ')';
        }
        if (!empty($eval['workspace_present'])) {
            $reasons[] = 'carries notes from its own earlier runs';
        }
        return implode(' and ', $reasons);
    }

    /**
     * Drift-detection text for a pipeline run start: the job began declaring
     * untrustedDigest() after the recipe was saved with the flag off. Person-
     * facing (failure email, run detail page): same vocabulary rule as
     * explain() — standing approval and outside influence, never the
     * internal gate terms. Agent mode has no drift stop: a recipe that drifts
     * into reading outside content starts queuing its writes, which needs
     * nobody's acknowledgment.
     */
    public static function describeDrift(array $eval): string {
        return 'since this recipe was last saved, its job began reading content '
             . "written by other people, and a message could try to steer the AI's "
             . 'verdicts. Runs stay stopped until you open the recipe and give it '
             . 'your standing approval to act on that content.';
    }

}
