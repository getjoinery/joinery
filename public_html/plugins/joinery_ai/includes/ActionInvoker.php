<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/DescriptorValidator.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

/**
 * Security boundary for invoke_action. All AI-driven action calls flow
 * through here; there is no path that calls _logic() directly from a tool.
 *
 * Enforcement order:
 *   1. Per-recipe allowlist — action must appear in rcp_allowed_actions.
 *      Refuses any name not on the list, regardless of whether the
 *      underlying descriptor has mutates: true.
 *   2. Action registered — descriptor and _logic() both exist.
 *   3. Agent-exposed — the descriptor declares ai_agent (default-deny). An
 *      action that does not opt in is never callable, even if allowlisted.
 *   4. Input validation/coercion via the descriptor's input schema.
 *   5. Call _logic() and coerce the LogicResult into the response envelope.
 *
 * The full validation gauntlet (cross-record invariants, hooks, external
 * effects) runs by construction inside _logic() — that's the entire reason
 * Path 2 routes through this layer instead of skipping to model writes.
 */
class ActionInvoker {

    public static function invoke(string $name, array $input, RecipeRunContext $ctx): array {
        self::checkAllowlist($name, $ctx);

        $info = ActionRegistry::get($name);
        if ($info === null) {
            throw new InvalidArgumentException("Action '$name' is not registered. "
                . "It may have been removed since this recipe was saved.");
        }

        $descriptor = $info['descriptor'];
        $logic_fn = $info['logic_function'];

        if (!ActionRegistry::isAgentCallable($descriptor)) {
            throw new InvalidArgumentException(
                "Action '$name' is not exposed to the AI agent. Add "
                . "'ai_agent' => 'confirm' (or 'auto') to its "
                . "{$name}_logic_descriptor() to make it callable."
            );
        }

        $coerced = DescriptorValidator::coerce($descriptor, $input);

        try {
            $result = call_user_func($logic_fn, $coerced);
        } catch (SystemAuthenticationError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException("Action '$name' failed: " . $e->getMessage(), 0, $e);
        }

        return self::envelope($name, $result);
    }

    private static function checkAllowlist(string $name, RecipeRunContext $ctx): void {
        $allowed = $ctx->recipe->get('rcp_allowed_actions');
        if (is_string($allowed)) {
            $decoded = json_decode($allowed, true);
            $allowed = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($allowed)) $allowed = [];
        if (!in_array($name, $allowed, true)) {
            throw new InvalidArgumentException(
                "Action '$name' is not allowed for this recipe. Allowed actions: "
                . (empty($allowed) ? '(none)' : implode(', ', $allowed))
            );
        }
    }

    /**
     * Coerce a LogicResult into the AI tool response envelope. Strips
     * page_vars, redirect, and validation_errors at the boundary — those
     * are view-internal and never reach the LLM.
     *
     * On error: returns an error envelope that tools turn into is_error=true.
     * On success: returns success envelope with summary, data, and any
     * REST-API-shaped data payload from LogicResult.data.
     */
    private static function envelope(string $name, $result): array {
        if (!($result instanceof LogicResult)) {
            // Unexpected return — treat as success with stringified payload.
            return [
                'status'  => 'success',
                'action'  => $name,
                'summary' => 'completed',
                'data'    => is_array($result) ? $result : ['value' => $result],
            ];
        }

        if ($result->error) {
            return [
                'status'  => 'error',
                'action'  => $name,
                'code'    => 'action_error',
                'message' => (string)$result->error,
            ];
        }

        $data = is_array($result->data) ? $result->data : [];
        // Strip view-internal keys that Logic Path conventionally puts in
        // data (notably page_vars-style 'session' / 'settings' references).
        // The contract is the data payload; bare object refs are not safe
        // to serialize and aren't part of the AI/REST surface.
        unset($data['session'], $data['settings'], $data['validation_errors']);

        $summary = 'completed';
        if (isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
            $summary = $data['message'];
        } elseif (isset($data['success_message']) && is_string($data['success_message']) && $data['success_message'] !== '') {
            $summary = $data['success_message'];
        }

        return [
            'status'  => 'success',
            'action'  => $name,
            'summary' => $summary,
            'data'    => $data,
        ];
    }

}
