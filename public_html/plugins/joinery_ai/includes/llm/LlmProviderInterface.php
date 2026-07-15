<?php

/**
 * The boundary between the recipe runner and whatever model actually drives it.
 *
 * The canonical request/response shape is the Anthropic Messages block shape —
 * the runner's loop already speaks it (text / tool_use{id,name,input} /
 * tool_result{tool_use_id,content,is_error}; a top-level system array;
 * tools[] with input_schema). Each provider translates canonical <-> its own
 * wire format entirely inside the provider class. The runner never branches on
 * provider.
 *
 * See specs/joinery_ai_llm_providers.md for the full canonical-IR rationale.
 */
interface LlmProviderInterface {

    /**
     * Stream one message. $params is the canonical request: model, max_tokens,
     * system (array of text blocks), messages (canonical content blocks), tools
     * (optional, Anthropic tool-schema shape). $onTextDelta is invoked with each
     * fragment of assistant answer text as it arrives (reasoning/think output is
     * never emitted). Returns the same canonical response array a blocking call
     * would, assembled from the stream:
     *   { stop_reason, content: [...blocks], usage: {input_tokens,
     *     output_tokens, cache_creation_input_tokens, cache_read_input_tokens} }
     * Throws LlmProviderException on failure.
     *
     * $shouldAbort, when supplied, is polled (throttled) as the stream is read;
     * the first true return stops the read cleanly, closes the upstream, and
     * returns the partial content assembled so far with stop_reason 'aborted' —
     * a normal finish reason, not an exception. Default null = never abort, so
     * every existing caller is unaffected.
     *
     * This is the one provider call path; the AgentLoop always uses it and passes
     * the turn's ToolContext::emitText as the sink (a no-op for recipes) and
     * ToolContext::shouldAbort as the abort predicate.
     */
    public function createMessageStreamed(array $params, callable $onTextDelta,
        ?callable $shouldAbort = null): array;

    /**
     * Blocking convenience over createMessageStreamed (no-op delta sink). Kept
     * for callers that don't want incremental text; behaves identically.
     */
    public function createMessage(array $params): array;

    /** USD estimate from a canonical usage block. Local providers return 0.0. */
    public function estimateCost(string $model, array $usage): float;

    /** Models offered to the recipe-edit dropdown: [model_id => label]. */
    public function models(): array;

    /** Model used when a recipe has no explicit rcp_model. */
    public function defaultModel(): string;

    /** Stable identifier ('anthropic', 'local', 'fireworks') for logging/diagnostics. */
    public function id(): string;

    /**
     * Whether traffic to this provider stays private. True for on-device hosts
     * (local Ollama) and vetted no-train remotes (Fireworks); false for general
     * cloud providers (Anthropic). The chat UI uses this to warn — only — before
     * sending sensitive-looking text to a non-private model. It is never a gate.
     */
    public function isPrivate(): bool;

    /**
     * Fast pre-turn reachability check. Returns null when the provider is
     * reachable — or when no cheap probe applies, as for cloud providers whose
     * own call path handles transport errors — and a short user-facing message
     * when the host is definitively unreachable. Must be cheap and short-timeout:
     * it runs on the turn's critical path so a sleeping/offline local model
     * server fails the turn in a couple of seconds instead of stalling the full
     * streaming call. The returned string is for logs; the caller maps the turn
     * to the standard network-error message.
     */
    public function reachabilityProbe(): ?string;

    /**
     * What attachment block kinds this model can consume, as
     *   ['vision' => bool, 'document' => bool]
     * where 'vision' means it accepts image blocks and 'document' means it accepts
     * native PDF `document` blocks (Anthropic only today). A model missing the
     * needed flag rejects the upload at ingress with a clear message rather than
     * sending a block the model will ignore or error on — the file-upload spec's
     * fail-loud rule. Unknown models return both false (the safe side).
     */
    public function modelCapabilities(string $model): array;
}
