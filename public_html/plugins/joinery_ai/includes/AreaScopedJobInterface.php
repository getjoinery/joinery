<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));

/**
 * Opt-in contract for pipeline jobs whose recipes belong to one area page —
 * the mail reader, later the calendar and drive pages — and whose binding can
 * be toggled from that page's AI panel (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md).
 *
 * The panel never edits rcp_source_config itself: it asks the job whether the
 * page's current context (e.g. the mailbox selected in the reader's rail) is
 * covered, and asks the job for an updated config with that context bound or
 * unbound. The result always goes back through validateConfig() before it is
 * saved, so the panel's toggle and the dashboard's edit form are the same
 * write path with the same rules.
 *
 * Optional deliberately: a job that does not implement this simply never
 * appears in any panel — existing and third-party jobs are untouched.
 *
 * @version 1.0
 */
interface AreaScopedJobInterface {

    /** Which area page this job's recipes belong to: 'mailbox', 'calendar', ... */
    public function area(): string;

    /** Does this recipe's config cover the given context right now? */
    public function coversContext(array $config, array $context, Recipe $recipe): bool;

    /**
     * Return updated config with the context bound or unbound.
     * The caller runs the result through validateConfig() before saving.
     * Throws InvalidArgumentException when the context names nothing usable.
     */
    public function bindContext(array $config, array $context, bool $on): array;

    /** How many contexts this config binds — the panel's "also on N other
     *  mailboxes" line, without the panel reading the config's shape itself. */
    public function contextCount(array $config): int;

}
