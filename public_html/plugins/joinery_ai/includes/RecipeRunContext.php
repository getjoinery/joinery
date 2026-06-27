<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

/**
 * Context passed to every tool's execute(). Carries the in-flight Recipe
 * and RecipeRun plus owner/timezone so tools can read/write owner-scoped
 * data and append to the run's tool-call trace without reaching for a
 * global session.
 */
class RecipeRunContext {

    /** @var Recipe */
    public $recipe;

    /** @var RecipeRun */
    public $run;

    /** @var int */
    public $owner_user_id;

    /** @var string */
    public $owner_timezone;

    /**
     * Per-run hex nonce used to wrap user-generated text in tool results
     * (see $ai_untrusted_fields on data models). 32 bits is enough that an
     * attacker can't pre-embed a closing tag in their content; rotated each
     * run so seeing one prior run's nonce doesn't help guess the next.
     *
     * @var string
     */
    public $untrusted_input_nonce;

    public function __construct(Recipe $recipe, RecipeRun $run) {
        $this->recipe = $recipe;
        $this->run = $run;
        $this->owner_user_id = (int)$recipe->get('rcp_owner_user_id');
        $this->owner_timezone = self::resolveTimezone($this->owner_user_id);
        $this->untrusted_input_nonce = bin2hex(random_bytes(4));
    }

    /**
     * Does a mutating tool call need a live human sign-off before it runs?
     * Recipes are autonomous — the author signed off at save time via the
     * taint gate — so the answer is always false here. The interactive chat
     * surface (a future context) answers true and the shared AgentLoop then
     * halts the turn with a pending action for the user to confirm.
     */
    public function requiresConfirmation(): bool {
        return false;
    }

    /**
     * Record a tool call that has started but not completed, then flush it
     * immediately to the run row. The dispatcher reaper reads rcr_tool_calls
     * from the DB, so a hung run that never returns still names the last call
     * that started — that's the offender. Best-effort: a DB failure during
     * audit logging logs a warning and proceeds (the action's effect on the
     * touched models is the source of truth).
     *
     * Entry shape: { name, input, started_time, completed_time, is_error,
     * output, duration_ms } with the completion fields null until finish.
     */
    public function beginToolCall(array $entry): void {
        $this->appendToolCall($entry);
        $this->flushToolCalls();
    }

    /**
     * Update the matching started-but-not-completed entry with its completion
     * data, then flush. Matched by name + started_time, which is
     * high-resolution enough to be unique within a run.
     */
    public function finishToolCall(array $entry): void {
        $name = $entry['name'] ?? '';
        $started_time = $entry['started_time'] ?? '';
        $entries = $this->currentToolCalls();
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            if (($entries[$i]['name'] ?? '') !== $name) continue;
            if (($entries[$i]['started_time'] ?? '') !== $started_time) continue;
            $entries[$i] = $entry;
            break;
        }
        $this->run->set('rcr_tool_calls', $entries);
        $this->flushToolCalls();
    }

    /**
     * Append a tool-call trace entry to the run's rcr_tool_calls JSON column
     * in memory. Used for one-shot notes (e.g. an unknown tool name) that
     * have no start/end lifecycle; the start/end audit path goes through
     * beginToolCall()/finishToolCall().
     */
    public function appendToolCall(array $entry): void {
        $existing = $this->currentToolCalls();
        $existing[] = $entry;
        $this->run->set('rcr_tool_calls', $existing);
    }

    /** Decode the run's rcr_tool_calls column to an array (handles the
     *  JSON-string or already-array cases). */
    private function currentToolCalls(): array {
        $existing = $this->run->get('rcr_tool_calls');
        if (is_string($existing)) {
            $decoded = json_decode($existing, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($existing) ? $existing : [];
    }

    /** Persist the in-memory trace to the run row. Best-effort. */
    private function flushToolCalls(): void {
        try {
            $db = DbConnector::get_instance()->get_db_link();
            $q = $db->prepare("UPDATE rcr_recipe_runs SET rcr_tool_calls = ? WHERE rcr_run_id = ?");
            $q->execute([
                json_encode($this->run->get('rcr_tool_calls'), JSON_UNESCAPED_SLASHES),
                (int)$this->run->key,
            ]);
        } catch (Throwable $e) {
            error_log('[joinery_ai] flushToolCalls failed: ' . $e->getMessage());
        }
    }

    private static function resolveTimezone(int $user_id): string {
        if ($user_id <= 0) {
            $settings = Globalvars::get_instance();
            return $settings->get_setting('default_timezone') ?: 'UTC';
        }
        require_once(PathHelper::getIncludePath('data/users_class.php'));
        $user = new User($user_id, true);
        $tz = $user->get('usr_timezone');
        return $tz ?: 'UTC';
    }

}
