<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

/**
 * Context passed to every tool's execute(). Carries the in-flight Recipe
 * and RecipeRun plus owner/timezone so tools can read/write owner-scoped
 * data and append to the run's tool-call trace without reaching for a
 * global session.
 */
class RecipeRunContext implements ToolContext {

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

    /** Hard wall-clock timeout for a recipe run (sync mode). */
    const WALL_CLOCK_SECONDS = 90;

    /** Monotonic clock captured when the context is built; the wall-clock
     *  guard in shouldContinue() measures elapsed time from here. */
    public $loop_started_at;

    public function __construct(Recipe $recipe, RecipeRun $run) {
        $this->recipe = $recipe;
        $this->run = $run;
        $this->owner_user_id = (int)$recipe->get('rcp_owner_user_id');
        $this->owner_timezone = self::resolveTimezone($this->owner_user_id);
        $this->untrusted_input_nonce = bin2hex(random_bytes(4));
        $this->loop_started_at = microtime(true);
    }

    /** The user whose identity the loop acts under (owner-or-staff writes,
     *  SessionControl-reading logic files, the risk heuristic's owner check). */
    public function actingUserId(): int {
        return $this->owner_user_id;
    }

    public function ownerTimezone(): string {
        return $this->owner_timezone;
    }

    public function untrustedNonce(): string {
        return $this->untrusted_input_nonce;
    }

    /** Models in scope for this recipe (decoded rcp_allowed_models). */
    public function allowedModels(): array {
        return self::decodeJsonArray($this->recipe->get('rcp_allowed_models'));
    }

    /** Actions in scope for this recipe (decoded rcp_allowed_actions). */
    public function allowedActions(): array {
        return self::decodeJsonArray($this->recipe->get('rcp_allowed_actions'));
    }

    /** Decode a jsonb column that may arrive as a JSON string or an array. */
    private static function decodeJsonArray($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Per-iteration continuation guard consulted by AgentLoop. Returns a
     * {stop_reason, detail} pair to halt the turn, or null to continue. Recipe
     * guards: the admin's mid-run Stop (kill flag) and the hard wall clock.
     */
    public function shouldContinue(): ?array {
        if ($this->isKillRequested()) {
            return ['stop_reason' => 'cancelled', 'detail' => 'cancelled by admin'];
        }
        if (microtime(true) - $this->loop_started_at > self::WALL_CLOCK_SECONDS) {
            return ['stop_reason' => 'wall_clock', 'detail' => 'wall-clock timeout'];
        }
        return null;
    }

    /**
     * Has the admin requested cancellation of this run? Re-reads the row's
     * kill flag from the database (rather than the in-memory copy) — the Stop
     * button updates the DB directly, so the in-memory row can't be trusted.
     */
    private function isKillRequested(): bool {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare("SELECT rcr_kill_requested FROM rcr_recipe_runs WHERE rcr_run_id = ?");
        $q->execute([(int)$this->run->key]);
        return (bool)$q->fetchColumn();
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

    /** Recipes produce a one-shot report, not a live transcript — streamed text
     *  has nowhere to go, so this is a no-op. */
    public function emitText(string $delta): void {}

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
