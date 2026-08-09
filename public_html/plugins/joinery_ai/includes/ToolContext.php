<?php
/**
 * The surface-independent contract every AI tool and executor needs from its
 * run context. Two contexts implement it: RecipeRunContext (autonomous,
 * confirmation-free) and ChatTurnContext (interactive, confirmation-gated).
 * Tools and executors type-hint this interface so neither surface drags the
 * other's bookkeeping into the shared code path.
 *
 * What lives here is exactly the shared surface:
 *   - identity / locale: who the loop acts as, and their timezone, for
 *     owner-scoped reads/writes and time formatting;
 *   - capability allowlists: which models and actions are in scope (a recipe's
 *     rcp_allowed_* or a conversation's aic_allowed_*);
 *   - the untrusted-input nonce used to wrap externally-authored text;
 *   - queuesWrites() / enqueueProposedAction(): the deferred-write boundary —
 *     an interactive surface queues every mutating call for the owner's
 *     approval (specs/implemented/ai_action_queue.md); recipes answer false and keep
 *     their own single write door (the verdict handler);
 *   - shouldContinue(): the per-iteration continuation guard (recipe: kill
 *     flag + wall clock; chat: per-turn timeout);
 *   - the begin/finish/append tool-call audit hooks (recipe persists each call
 *     immediately for the reaper; chat accumulates and saves with the message).
 *
 * Recipe-only concepts (the Recipe row, the workspace) stay off this interface;
 * the handful of recipe-only tools reach them through the concrete
 * RecipeRunContext and are never listed in a chat conversation's tools.
 */
interface ToolContext {

    /** The user whose identity the loop acts under (owner-or-staff writes,
     *  SessionControl-reading logic files, the risk heuristic's owner check). */
    public function actingUserId(): int;

    /** IANA timezone for formatting times in tool output. */
    public function ownerTimezone(): string;

    /** Per-run/per-turn hex nonce wrapping externally-authored text in tool
     *  results (the <<UNTRUSTED_nonce>>…>> delimiters). */
    public function untrustedNonce(): string;

    /** Model class names in scope for query_model (decoded allowlist). */
    public function allowedModels(): array;

    /** Action names in scope for invoke_action (decoded allowlist). */
    public function allowedActions(): array;

    /** Does this surface defer AI-initiated writes to the owner's approval
     *  queue? True for the interactive chat — a mutating tool call never
     *  executes in the turn; it becomes a pending action the owner approves
     *  or declines (specs/implemented/ai_action_queue.md). False for autonomous recipes,
     *  whose one write door is their own verdict handler. */
    public function queuesWrites(): bool;

    /** Queue one mutating tool call for the owner's approval and return the
     *  tool_result block the model sees ("queued for approval", or the
     *  refusal when the call cannot be queued). Called only when
     *  queuesWrites() is true. */
    public function enqueueProposedAction(array $tool_use): array;

    /** Are reads contained to the acting user's own rows? True for a non-admin
     *  member caller (the read executor adds an owner filter and hides
     *  ambiguously-owned models); false for an admin, who reads cross-user. */
    public function ownerScopedReads(): bool;

    /** May this surface open sealed content (protected mail, sealed drive)? A
     *  standard chat answers FALSE: pulling sealed content into a plaintext
     *  transcript would make the turn hot, and a hot standard turn can neither
     *  persist its next reply nor keep the content protected — so sealed content
     *  is confined to protected chats, where the whole conversation seals. A
     *  protected chat answers true; recipes answer true (their sealed-run
     *  machinery is built to read and re-seal). The read executor excludes
     *  sealed rows when this is false, exactly as it does for a locked vault. */
    public function sealedReadsAllowed(): bool;

    /** Per-iteration continuation guard. Returns {stop_reason, detail} to halt
     *  the turn, or null to continue. */
    public function shouldContinue(): ?array;

    /** Mid-generation abort predicate, polled per stream chunk by the provider.
     *  True means stop this generation NOW and hand back whatever text has
     *  streamed so far. Reads the same cancel/kill flag shouldContinue() reads,
     *  so one flag drives both the between-steps and the in-stream stop; recipes
     *  wire it to their kill flag, chat to aim_cancel_requested. Must be cheap —
     *  it is called throttled but frequently. */
    public function shouldAbort(): bool;

    /** Record a tool call that has started but not completed. */
    public function beginToolCall(array $entry): void;

    /** Update the matching started-but-not-completed entry with completion data. */
    public function finishToolCall(array $entry): void;

    /** Append a one-shot trace entry with no start/end lifecycle. */
    public function appendToolCall(array $entry): void;

    /** Sink for streamed assistant answer text. The AgentLoop hands the provider
     *  this method so each text fragment arrives as it is generated. Chat
     *  forwards it to a live sink (partial-row writer); recipes no-op it. */
    public function emitText(string $delta): void;

    /** One-line stage label for whatever the loop is doing right now
     *  ("Waiting for {model}…", "Running tool: {name}…"). Chat forwards it to
     *  the running row so pollers can show a live status; recipes no-op it. */
    public function noteActivity(string $label): void;

}
