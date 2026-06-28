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
     * This is the one provider call path; the AgentLoop always uses it and passes
     * the turn's ToolContext::emitText as the sink (a no-op for recipes).
     */
    public function createMessageStreamed(array $params, callable $onTextDelta): array;

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

    /** Stable identifier ('anthropic', 'local') for logging/diagnostics. */
    public function id(): string;
}
