<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

/**
 * Run context for one interactive chat turn. The interactive counterpart to
 * RecipeRunContext: it answers requiresConfirmation() = true, so the shared
 * AgentLoop applies the risk heuristic and halts a CONFIRM-verdict mutating
 * call with a pending action instead of running it.
 *
 * Identity, timezone, and capability allowlists come from the conversation and
 * its owning admin. The tool-call audit accumulates in memory and is handed to
 * the send/confirm endpoint via toolCalls() to persist on the assistant message
 * (aim_tool_calls) — unlike a recipe there is no hang-and-reap path, so no
 * mid-turn DB flush is needed. The continuation guard is a per-turn wall clock
 * (no kill flag — a chat turn is a single synchronous request the user can
 * simply abandon).
 */
class ChatTurnContext implements ToolContext {

    /** Hard per-turn wall-clock ceiling. Generous because a local model turn
     *  with several tool calls is slow; the composer shows a thinking
     *  indicator meanwhile. */
    const PER_TURN_SECONDS = 240;

    /** @var AiConversation */
    private $conversation;

    /** @var int */
    private $acting_user_id;

    /** @var string */
    private $owner_timezone;

    /** @var string */
    private $nonce;

    /** @var float */
    private $turn_started_at;

    /** @var array  accumulated tool-call trace entries for this turn */
    private $tool_calls = [];

    /** @var callable|null  live sink for streamed answer text (set by the endpoint) */
    private $stream_sink = null;

    public function __construct(AiConversation $conversation, int $acting_user_id) {
        $this->conversation = $conversation;
        $this->acting_user_id = $acting_user_id;
        $this->owner_timezone = self::resolveTimezone($acting_user_id);
        $this->nonce = bin2hex(random_bytes(4));
        $this->turn_started_at = microtime(true);
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

    /** Data access on → every readable model in scope; off → none. */
    public function allowedModels(): array {
        if (!$this->conversation->get('aic_data_access')) return [];
        return array_keys(ModelRegistry::all());
    }

    /** Data access on → every agent-callable action in scope; off → none. */
    public function allowedActions(): array {
        if (!$this->conversation->get('aic_data_access')) return [];
        return ActionRegistry::agentCallableActionNames();
    }

    /** The interactive confirmation boundary: mutating calls the risk
     *  heuristic flags are held for the admin's live sign-off. */
    public function requiresConfirmation(): bool {
        return true;
    }

    /** Per-turn continuation guard: a hard wall clock. No kill flag — a chat
     *  turn is one synchronous request. */
    public function shouldContinue(): ?array {
        if (microtime(true) - $this->turn_started_at > self::PER_TURN_SECONDS) {
            return ['stop_reason' => 'wall_clock', 'detail' => 'per-turn timeout'];
        }
        return null;
    }

    public function beginToolCall(array $entry): void {
        $this->tool_calls[] = $entry;
    }

    public function finishToolCall(array $entry): void {
        $name = $entry['name'] ?? '';
        $started_time = $entry['started_time'] ?? '';
        for ($i = count($this->tool_calls) - 1; $i >= 0; $i--) {
            if (($this->tool_calls[$i]['name'] ?? '') !== $name) continue;
            if (($this->tool_calls[$i]['started_time'] ?? '') !== $started_time) continue;
            $this->tool_calls[$i] = $entry;
            return;
        }
        // No matching start (shouldn't happen) — append so nothing is lost.
        $this->tool_calls[] = $entry;
    }

    public function appendToolCall(array $entry): void {
        $this->tool_calls[] = $entry;
    }

    /** Install the live text sink (a throttled writer onto the assistant row).
     *  Until set, emitText is a no-op — e.g. the non-fpm synchronous path. */
    public function setStreamSink(callable $sink): void {
        $this->stream_sink = $sink;
    }

    /** Forward a streamed answer-text fragment to the live sink, if any. */
    public function emitText(string $delta): void {
        if ($this->stream_sink !== null) {
            ($this->stream_sink)($delta);
        }
    }

    /** The accumulated per-turn trace, for the endpoint to store on the
     *  assistant message (aim_tool_calls). */
    public function toolCalls(): array {
        return $this->tool_calls;
    }

    private static function resolveTimezone(int $user_id): string {
        if ($user_id <= 0) {
            return Globalvars::get_instance()->get_setting('default_timezone') ?: 'UTC';
        }
        require_once(PathHelper::getIncludePath('data/users_class.php'));
        $user = new User($user_id, true);
        $tz = $user->get('usr_timezone');
        return $tz ?: 'UTC';
    }

}
