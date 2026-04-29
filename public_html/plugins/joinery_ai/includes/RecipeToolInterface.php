<?php
/**
 * Contract for recipe tools.
 *
 * A tool exposes a JSON Schema of its input shape and an execute() method.
 * The runner registers tools by name with the Anthropic API and dispatches
 * tool_use blocks back to execute() during the tool-use loop.
 *
 * Plugin authors can drop new implementations into either the core
 * plugin's recipe_tools/ or any other plugin's recipe_tools/ — the
 * registry scans both.
 */
interface RecipeToolInterface {

    /** Tool identifier passed to the LLM (snake_case, unique). */
    public static function name(): string;

    /** Human/LLM-readable description of what the tool does. */
    public static function description(): string;

    /**
     * JSON Schema for the tool's input. Must be a valid JSON Schema object
     * with at least 'type' and 'properties' keys; matches Anthropic's
     * input_schema field exactly.
     */
    public static function inputSchema(): array;

    /**
     * Execute the tool with $input (validated against inputSchema by caller)
     * and the run context. Return either a string (becomes tool_result text)
     * or an array with shape:
     *   ['content' => string, 'is_error' => bool]
     * The runner wraps the return value into a tool_result block.
     */
    public function execute(array $input, RecipeRunContext $ctx);

}
