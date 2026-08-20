<?php

/**
 * The boundary between the recipe runner and whatever model actually drives it.
 *
 * A provider is TRANSPORT ONLY. It knows how to speak one wire format; it does
 * not know what models exist, what they cost, what they can do, or whether they
 * are safe. All of that lives in the shipped catalog (AiEndpointRegistry) and is
 * decided once by AiModelResolver, so there is exactly one answer to each of
 * those questions and no second source to drift from it.
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
     * (optional, Anthropic tool-schema shape), and `thinking` — a concrete
     * DIRECTIVE from the resolver, ['enabled' => bool, 'effort' => 'low'|
     * 'medium'|'high'|null], not a level to interpret. The provider translates
     * it into its own wire field and never consults a table of model names. $onTextDelta is invoked with each
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

    /** The endpoint key this transport was built for ('anthropic', 'local',
     *  'fireworks'), for logging and diagnostics. Never a routing decision and
     *  never a trust classification — the catalog owns both. */
    public function id(): string;

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
}
