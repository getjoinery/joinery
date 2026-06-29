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
 *   - requiresConfirmation(): the interactive confirmation boundary — false
 *     for recipes, true for chat;
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

    /** Does a mutating tool call need a live human sign-off before it runs?
     *  False for autonomous recipes; true for the interactive chat. */
    public function requiresConfirmation(): bool;

    /** Are reads contained to the acting user's own rows? True for a non-admin
     *  member caller (the read executor adds an owner filter and hides
     *  ambiguously-owned models); false for an admin, who reads cross-user. */
    public function ownerScopedReads(): bool;

    /** Per-iteration continuation guard. Returns {stop_reason, detail} to halt
     *  the turn, or null to continue. */
    public function shouldContinue(): ?array;

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

}
