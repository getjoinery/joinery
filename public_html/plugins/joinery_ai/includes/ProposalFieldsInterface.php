<?php
/**
 * Optional companion to QueueableToolInterface: a queued tool that can state
 * its card as labelled fields rather than flat lines, so the card can set a
 * label apart from its value, keep a long link to one line, and show a long
 * note's first lines with a 'more'.
 *
 * The same rule holds as for the lines: every value comes from the LITERAL
 * arguments (or from records looked up by them), never from model prose. The
 * lines stay the canonical statement — they are what the conversation is told
 * when the action resolves — so a tool implementing this states the same
 * facts both ways.
 *
 * @version 1.0
 */
interface ProposalFieldsInterface {

    /**
     * The card as fields:
     *
     *   ['kicker'   => what would happen ('Add to your calendar'),
     *    'headline' => to what (the entry's title),
     *    'fields'   => [ProposedActionFacts::field(label, value, display), ...]]
     *
     * display is ProposedActionFacts::DISPLAY_TEXT, DISPLAY_LINE (one line
     * the owner can expand; the whole value is still on the card) or
     * DISPLAY_CLAMP (the first three lines, then 'more').
     */
    public function proposalFields(array $input, ?int $owner_id = null): array;

}
