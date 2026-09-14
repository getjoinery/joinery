<?php
/**
 * Opt-in contract for a tool whose calls may be DEFERRED to the owner's
 * approval queue instead of executing (specs/implemented/ai_action_queue.md § Rendering).
 *
 * The card the owner approves is built by the platform from the tool name and
 * the literal call arguments — never from the model's description of what it
 * wants to do; if the card showed model prose as its substance, injected
 * instructions would simply move into the prose. So a tool can only be queued
 * if it can render itself: a mutating tool that does not implement this cannot
 * enqueue, and its call is refused rather than run — an unrenderable action is
 * impossible, not just unlikely.
 *
 * @version 1.1
 */
interface QueueableToolInterface {

    /**
     * The card's facts lines, from the LITERAL arguments: first line the
     * headline (what would happen, to what), following lines the argument
     * details. Plain text; the queue truncates over-long values itself.
     *
     * The queue also passes the owner's user id as a second argument. A tool
     * whose facts depend on who is reading them (a time shown in their zone,
     * a source record they may or may not be allowed to see) declares it as
     * an optional second parameter, `?int $owner_id = null`; every other tool
     * leaves it out.
     */
    public function renderProposedAction(array $input): array;

}
