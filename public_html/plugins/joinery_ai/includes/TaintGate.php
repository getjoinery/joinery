<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelWriteExecutor.php'));

/**
 * Static taint gate — the primary write-side defense against prompt
 * injection via untrusted user-generated text. A recipe is *tainted-
 * capable* if its allowed tools can write AND it can read any
 * user-generated content (or carries LLM-curated workspace state across
 * runs).
 *
 * The same predicate fires at two points:
 *   - admin_edit_logic save: rejects a tainted-capable save without
 *     rcp_allow_tainted_writes set.
 *   - RecipeRunner run-start: re-evaluates against current model class
 *     state. Catches drift — a developer added $ai_untrusted_fields to
 *     a model after the recipe was last saved.
 *
 * One-way tightening: a predicate that becomes false again does not
 * auto-clear the recipe's opt-in. The opt-in is admin acknowledgment,
 * not derived state.
 *
 * Returns an evaluation object so callers can render targeted errors.
 *   { tainted_capable: bool, write_tools: string[], untrusted_models: string[],
 *     workspace_present: bool }
 */
class TaintGate {

    public static function evaluate(array $allowed_tools, array $allowed_models, string $workspace): array {
        $write_tools = array_values(array_intersect(
            array_map('strval', $allowed_tools),
            ModelWriteExecutor::WRITE_TOOL_NAMES
        ));

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

        $tainted = !empty($write_tools) && (!empty($untrusted_models) || $workspace_present);

        return [
            'tainted_capable'   => $tainted,
            'write_tools'       => $write_tools,
            'untrusted_models'  => $untrusted_models,
            'workspace_present' => $workspace_present,
        ];
    }

    /**
     * Return a plain-language explanation of why a tainted-capable recipe
     * was rejected. Names the offending tool(s) and which trigger fired.
     */
    public static function explain(array $eval): string {
        $tools = implode(', ', $eval['write_tools']);
        $reasons = [];
        if (!empty($eval['untrusted_models'])) {
            $reasons[] = 'reads user-generated text from: ' . implode(', ', $eval['untrusted_models']);
        }
        if (!empty($eval['workspace_present'])) {
            $reasons[] = 'has non-empty workspace from prior runs';
        }
        return "This recipe can perform writes ($tools) and " . implode(' and ', $reasons)
             . ". Confirm the prompt is robust to injection from those sources, "
             . "then check 'Allow tainted writes' to save.";
    }

    /**
     * Drift-detection variant for run-start. Same predicate, but framed as
     * "what newly triggered the gate since save?" — names the specific
     * model + field that became untrusted, since that's the actionable
     * detail for the admin.
     */
    public static function describeDrift(array $eval): string {
        $parts = [];
        if (!empty($eval['untrusted_models'])) {
            $parts[] = 'allowed model(s) now declare untrusted fields: '
                     . implode(', ', $eval['untrusted_models']);
        }
        if (!empty($eval['workspace_present'])) {
            $parts[] = 'workspace from prior runs is non-empty';
        }
        return implode('; ', $parts)
             . '. Re-acknowledge rcp_allow_tainted_writes on the recipe to allow continued operation.';
    }

}
