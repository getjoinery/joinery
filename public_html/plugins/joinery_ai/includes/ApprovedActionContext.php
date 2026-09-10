<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));

/**
 * The context an approved queued action executes under when it was proposed
 * by a RECIPE rather than in a conversation (specs/security_inventory.md
 * S17; specs/implemented/ai_action_queue.md § Recipes and the queue).
 *
 * A chat-sourced action executes under its conversation's ChatTurnContext.
 * A recipe-sourced one has no conversation: its scope is the recipe that
 * proposed it — the recipe's allow-lists, the recipe owner as the acting
 * user — and the owner is present, approving it from their own browser.
 * There is no model in the loop, so nothing here continues, aborts or
 * streams; the tool-call trace is kept in memory for the queue row's result.
 *
 * @version 1.1 - executesInline() and writeProvenance() (S16, S18)
 */
class ApprovedActionContext implements ToolContext {

    /** @var Recipe */
    private $recipe;
    private $acting_user_id;
    private $owner_timezone;
    private $nonce;
    private $tool_calls = [];

    public function __construct(Recipe $recipe, int $acting_user_id) {
        $this->recipe = $recipe;
        $this->acting_user_id = $acting_user_id;
        $this->owner_timezone = self::resolveTimezone($acting_user_id);
        $this->nonce = bin2hex(random_bytes(4));
    }

    public function actingUserId(): int {
        return $this->acting_user_id;
    }

    public function ownerTimezone(): string {
        return $this->owner_timezone;
    }

    public function untrustedNonce(): string {
        return $this->nonce;
    }

    /** The proposing recipe's scope, exactly as a run of it would have. */
    public function allowedModels(): array {
        return self::decodeJsonArray($this->recipe->get('rcp_allowed_models'));
    }

    public function allowedActions(): array {
        return self::decodeJsonArray($this->recipe->get('rcp_allowed_actions'));
    }

    /** An approval IS the execution; nothing queues again. */
    public function queuesWrites(): bool {
        return false;
    }

    public function enqueueProposedAction(array $tool_use): array {
        throw new LogicException('An approved action does not queue again.');
    }

    public function executesInline(string $tool_name): bool {
        return false;
    }

    /** The recipe that proposed it, the click that ran it, and whether the
     *  recipe reads content written by other people. */
    public function writeProvenance(): string {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/TaintGate.php'));
        $line = 'recipe ' . trim((string)$this->recipe->get('rcp_name')) . ', approved by you';
        if (TaintGate::forRecipe($this->recipe)['tainted_capable']) {
            $line .= '; the recipe reads content written by other people';
        }
        return $line;
    }

    /** The owner acts on their own data. */
    public function ownerScopedReads(): bool {
        return true;
    }

    /** The owner is present and approving; a sealed read under their window is theirs to make. */
    public function sealedReadsAllowed(): bool {
        return true;
    }

    public function shouldContinue(): ?array {
        return null;
    }

    public function shouldAbort(): bool {
        return false;
    }

    public function beginToolCall(array $entry): void {
        $this->tool_calls[] = $entry;
    }

    public function finishToolCall(array $entry): void {
        for ($i = count($this->tool_calls) - 1; $i >= 0; $i--) {
            if (($this->tool_calls[$i]['name'] ?? '') === ($entry['name'] ?? '')
                    && ($this->tool_calls[$i]['started_time'] ?? '') === ($entry['started_time'] ?? '')) {
                $this->tool_calls[$i] = $entry;
                return;
            }
        }
        $this->tool_calls[] = $entry;
    }

    public function appendToolCall(array $entry): void {
        $this->tool_calls[] = $entry;
    }

    public function toolCalls(): array {
        return $this->tool_calls;
    }

    public function emitText(string $delta): void {
    }

    public function noteActivity(string $label): void {
    }

    private static function decodeJsonArray($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private static function resolveTimezone(int $user_id): string {
        if ($user_id > 0) {
            require_once(PathHelper::getIncludePath('data/users_class.php'));
            $user = new User($user_id, true);
            $tz = (string)$user->get('usr_timezone');
            if ($tz !== '') return $tz;
        }
        return Globalvars::get_instance()->get_setting('default_timezone') ?: 'UTC';
    }

}
