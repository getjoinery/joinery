<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

/**
 * Run context for one interactive chat turn. The interactive counterpart to
 * RecipeRunContext: it answers queuesWrites() = true, so the shared AgentLoop
 * queues every mutating call for the owner's approval instead of running it
 * (specs/implemented/ai_action_queue.md) — the tool result says "queued" and the turn
 * continues.
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

    /** Permission floor for unscoped (cross-user) reads. At or above this the
     *  caller is an admin and reads everyone's rows; below it the caller is a
     *  member and reads only their own. Matches the 5 = admin convention. */
    const ADMIN_PERMISSION = 5;

    /** @var AiConversation */
    private $conversation;

    /** @var int */
    private $acting_user_id;

    /** @var int  the RUNNING assistant row this turn writes into; 0 when the turn
     *  runs without a pollable row (unit tests). Drives the cancel re-read. */
    private $message_id;

    /** @var int  the acting user's permission level, from their user row */
    private $acting_permission;

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

    /** @var callable|null  live sink for stage labels (set by the endpoint) */
    private $activity_stamper = null;

    public function __construct(AiConversation $conversation, int $acting_user_id, int $message_id = 0) {
        $this->conversation = $conversation;
        $this->acting_user_id = $acting_user_id;
        $this->message_id = $message_id;
        $this->acting_permission = self::resolvePermission($acting_user_id);
        $this->owner_timezone = self::resolveTimezone($acting_user_id);
        $this->nonce = bin2hex(random_bytes(4));
        $this->turn_started_at = microtime(true);
    }

    public function actingUserId(): int {
        return $this->acting_user_id;
    }

    // --- chat-only accessors ------------------------------------------------
    // Surface-specific data a chat-only tool (view_attachment) reaches through
    // the concrete context, exactly as recipe-only tools reach RecipeRunContext.
    // Not on the ToolContext interface — RecipeRunContext has no conversation.

    /** The conversation this turn runs in — scopes an attachment ref lookup. */
    public function conversationId(): int {
        return (int)$this->conversation->key;
    }

    /** The conversation owner, for the send-time File ownership re-check (§5). */
    public function conversationOwnerId(): int {
        return (int)$this->conversation->get('aic_owner_user_id');
    }

    /** The chat's selected model (may be '' → caller resolves the default). */
    public function conversationModel(): string {
        return (string)$this->conversation->get('aic_model');
    }

    public function ownerTimezone(): string {
        return $this->owner_timezone;
    }

    public function untrustedNonce(): string {
        return $this->nonce;
    }

    /** Sealed content is confined to protected chats (see ToolContext). A standard
     *  conversation cannot open protected mail / sealed drive: the read executor
     *  excludes those rows instead of decrypting them, so the turn never goes hot. */
    public function sealedReadsAllowed(): bool {
        return $this->conversation->isProtected();
    }

    /** Set when this turn's history carries an approved web-fetch result, so the
     *  system prompt emits the untrusted-input contract for it (framed content
     *  the model must treat as data). */
    private $has_carried_result = false;
    public function noteCarriedResult(): void { $this->has_carried_result = true; }
    public function hasCarriedResult(): bool { return $this->has_carried_result; }

    /** Data access on → every readable model in scope; off → none. For a member
     *  caller (owner-scoped), models with ambiguous or undeclared ownership are
     *  dropped — a member never sees a model the read fence can't contain. */
    public function allowedModels(): array {
        if (!$this->conversation->get('aic_data_access')) return [];
        $models = ModelRegistry::all();
        if (!$this->ownerScopedReads()) {
            return array_keys($models);
        }
        $out = [];
        foreach ($models as $name => $info) {
            if (($info['owner_scope']['mode'] ?? 'hidden') !== 'hidden') $out[] = $name;
        }
        return $out;
    }

    /** Data access on → every agent-callable action in scope; off → none.
     *  Actions are withheld from a non-admin member: `invoke_action` runs
     *  arbitrary registered actions with no per-action member-authorization yet,
     *  so a member's data-access chat gets owner-scoped model reads/writes but
     *  not the action surface (admins are unaffected). */
    public function allowedActions(): array {
        if (!$this->conversation->get('aic_data_access')) return [];
        if ($this->ownerScopedReads()) return [];
        return ActionRegistry::agentCallableActionNames();
    }

    /** The deferred-write boundary: every mutating call in an interactive
     *  chat is queued for the owner's approval, never executed in the turn. */
    public function queuesWrites(): bool {
        return true;
    }

    /**
     * Queue one proposed mutating call for the conversation owner's approval
     * and hand the model its tool result. The owner — not the acting user —
     * owns the queue entry: resolving is theirs alone. A call that cannot be
     * queued (no renderer, unsealable protected content) is refused, never
     * silently executed.
     */
    public function enqueueProposedAction(array $tool_use): array {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));
        $name = (string)($tool_use['name'] ?? '');
        $id   = (string)($tool_use['id'] ?? '');
        $input = isset($tool_use['input']) && is_array($tool_use['input']) ? $tool_use['input'] : [];

        try {
            $row = ActionQueue::enqueue($this->conversationOwnerId(), $name, $input,
                (int)$this->conversation->key);
        } catch (ActionQueueException $e) {
            $this->appendToolCall([
                'name' => $name, 'input' => $input,
                'started_time' => gmdate('Y-m-d H:i:s.u'),
                'completed_time' => gmdate('Y-m-d H:i:s.u'),
                'is_error' => true, 'output' => 'not queued: ' . $e->getMessage(),
                'duration_ms' => 0,
            ]);
            return ['type' => 'tool_result', 'tool_use_id' => $id,
                'content' => $e->getMessage(), 'is_error' => true];
        }

        $this->appendToolCall([
            'name' => $name, 'input' => $input,
            'started_time' => gmdate('Y-m-d H:i:s.u'),
            'completed_time' => gmdate('Y-m-d H:i:s.u'),
            'is_error' => false,
            'output' => 'queued for the owner\'s approval as action #' . (int)$row->key,
            'duration_ms' => 0,
        ]);
        return ['type' => 'tool_result', 'tool_use_id' => $id,
            'content' => 'Queued for approval as pending action #' . (int)$row->key
                . '. It has NOT run: the owner will approve or decline it from their '
                . 'pending-actions list. Do not retry this call or assume its outcome — '
                . 'tell the user it is waiting for their approval and continue.'];
    }

    /** A non-admin member's reads are contained to their own rows; an admin
     *  reads cross-user, exactly as the admin-only chat always has. */
    public function ownerScopedReads(): bool {
        return $this->acting_permission < self::ADMIN_PERMISSION;
    }

    /** Per-turn continuation guard: the user's mid-flight Cancel (checked first,
     *  it is the responsive path), then a hard wall clock. The cancel flag is
     *  written by the chat_cancel endpoint in another process, so it is re-read
     *  fresh from the DB — the in-memory row can't be trusted. This catches a
     *  cancel that lands BETWEEN tool steps; the mid-generation case goes through
     *  shouldAbort() inside the stream. */
    public function shouldContinue(): ?array {
        if ($this->isCancelRequested()) {
            return ['stop_reason' => 'cancelled', 'detail' => 'cancelled by user'];
        }
        if (microtime(true) - $this->turn_started_at > self::PER_TURN_SECONDS) {
            return ['stop_reason' => 'wall_clock', 'detail' => 'per-turn timeout'];
        }
        return null;
    }

    /**
     * Has the user requested cancellation of this turn? Re-reads the running
     * row's aim_cancel_requested flag straight from the DB (not the in-memory
     * copy) — the chat_cancel endpoint set it in a different process. Modeled on
     * RecipeRunContext::isKillRequested(). No message id (unit-test path) → never
     * cancelled.
     */
    public function isCancelRequested(): bool {
        if ($this->message_id <= 0) return false;
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare('SELECT aim_cancel_requested FROM aim_conversation_messages WHERE aim_message_id = ?');
        $q->execute([$this->message_id]);
        return (bool)$q->fetchColumn();
    }

    /** Mid-generation abort predicate, polled per stream chunk by the provider.
     *  Same flag as shouldContinue() reads, through the same fresh SELECT, so one
     *  signal drives both the between-steps and the in-stream stop. The provider
     *  throttles how often it calls this. */
    public function shouldAbort(): bool {
        return $this->isCancelRequested();
    }

    public function beginToolCall(array $entry): void {
        $this->tool_calls[] = $entry;
        // A tool run is the longest quiet stretch of a turn — name it while
        // it executes. The next provider call re-stamps the waiting label.
        $name = trim((string)($entry['name'] ?? ''));
        if ($name !== '') {
            $this->noteActivity("Running tool: {$name}…");
        }
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

    /** Install the live stage-label sink (a writer onto the assistant row's
     *  aim_activity). Until set, noteActivity is a no-op — e.g. the
     *  synchronous fallback path before the row exists. */
    public function setActivityStamper(callable $stamper): void {
        $this->activity_stamper = $stamper;
    }

    /** Forward a stage label to the live stamper, if any. */
    public function noteActivity(string $label): void {
        if ($this->activity_stamper !== null) {
            ($this->activity_stamper)($label);
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

    /** The acting user's permission, read from their user row. A missing or
     *  anonymous user resolves to 0 — the most contained (member) scope, so an
     *  unresolved identity fails closed rather than reading cross-user. */
    private static function resolvePermission(int $user_id): int {
        if ($user_id <= 0) return 0;
        require_once(PathHelper::getIncludePath('data/users_class.php'));
        $user = new User($user_id, true);
        if (!$user->key) return 0;
        return (int)$user->get('usr_permission');
    }

}
