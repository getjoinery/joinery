<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));

/**
 * The two questions the deferred boundary asks of a tool call: does it
 * mutate, and does it send anything out of the box while the turn is hot?
 * On a surface that queues (interactive chat), every such call becomes a
 * pending action for the owner to approve or decline
 * (specs/implemented/ai_action_queue.md, specs/ai_hot_turn_egress_approval.md);
 * everything else flows inline.
 *
 * Classification uses only signals that already exist — the generic write
 * tool names, the action descriptor's `mutates` flag, the web tools'
 * names, and the sealed-egress guard's hot flag — so there is no new
 * per-tool marking. An unknown or unresolvable action fails safe to mutating.
 *
 * @version 2.2
 */
class RiskHeuristic {

    /** Tools whose ARGUMENTS leave the box — a URL, a search query, a symbol
     *  string all reach an external host verbatim. On a hot turn (sealed
     *  plaintext has been opened in this process) that is the injection-
     *  exfiltration channel, so these calls stop flowing inline. */
    const WEB_EGRESS_TOOLS = ['fetch_url', 'web_search', 'get_stock_data'];

    /**
     * Is this call web egress that must be gated? True when the tool sends its
     * arguments out AND sealed content is in play for egress — SealedEgressGuard
     * ::egressGated(), which is true for EITHER reason: this process has opened
     * sealed plaintext (a hot turn), or the conversation is durably egress-
     * restricted because an earlier turn opened sealed content and left it in
     * the transcript (a cold process reasoning over sealed-derived history).
     * The second arm closes the cold-start gap: without it, turn N+1 in a fresh
     * process would fetch inline using a secret turn N left in the transcript.
     * A conversation that has never touched sealed content stays ungated.
     */
    public static function isHotEgress(array $tool_use): bool {
        return in_array($tool_use['name'] ?? '', self::WEB_EGRESS_TOOLS, true)
            && SealedEgressGuard::egressGated();
    }

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
