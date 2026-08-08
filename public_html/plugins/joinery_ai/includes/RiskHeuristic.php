<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));

/**
 * The one question the deferred-write boundary asks of a tool call: does it
 * mutate? On a surface that queues writes (interactive chat), every mutating
 * call becomes a pending action for the owner to approve or decline
 * (specs/implemented/ai_action_queue.md); read tools and read-only actions flow inline.
 *
 * Classification uses only signals that already exist — the generic write
 * tool names and the action descriptor's `mutates` flag — so there is no new
 * per-tool marking. An unknown or unresolvable action fails safe to mutating.
 *
 * @version 2.0
 */
class RiskHeuristic {

    /**
     * Is this tool_use a mutating call the deferred-write boundary must
     * queue? Generic writes always are; an invoke_action is when the action's
     * descriptor declares mutates — or cannot be resolved at all, which fails
     * safe to mutating rather than executing an unknown.
     */
    public static function isMutating(array $tool_use): bool {
        $name = $tool_use['name'] ?? '';
        if (in_array($name, ['create_model', 'update_model', 'delete_model'], true)) {
            return true;
        }
        if ($name === 'invoke_action') {
            $action = (string)($tool_use['input']['name'] ?? '');
            $info = ActionRegistry::get($action);
            return $info === null || !empty($info['descriptor']['mutates']);
        }
        return false;
    }

}
