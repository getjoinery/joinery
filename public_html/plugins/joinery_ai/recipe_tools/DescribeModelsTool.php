<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPromptBuilder.php'));

/**
 * Lazy schema discovery for the read side. The system prompt carries only the
 * in-scope model names + descriptions; this tool returns a specific model's
 * full field schema on demand, so the prompt's fixed cost doesn't grow with
 * every model's field count.
 *
 *   - no argument        → the in-scope catalog (one line per model)
 *   - models: ["Class"]  → full field schema for each named model
 *
 * Scope comes from the context's allow-list, so a name outside scope returns a
 * per-name error and never leaks another model's schema — same boundary as
 * query_model.
 */
class DescribeModelsTool implements RecipeToolInterface {

    public static function name(): string {
        return 'describe_models';
    }

    public static function description(): string {
        return 'Discover the data models you can read. Call with no arguments to '
             . 'list the available models (name + description); call with '
             . '`models` set to one or more class names to get their full field '
             . 'schemas. Always describe a model before querying it with '
             . 'query_model so you use real field names.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'models' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Class names to fetch field schemas for. Omit to list available models.',
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $allowed = $ctx->allowedModels();
        if (empty($allowed)) {
            return 'No data models are in scope here.';
        }

        $models = isset($input['models']) && is_array($input['models']) ? $input['models'] : [];
        $models = array_values(array_filter(array_map('strval', $models), 'strlen'));

        // No argument → the catalog.
        if (empty($models)) {
            return "Available models:\n" . AiPromptBuilder::catalogLines($allowed);
        }

        // Named models → full schema each, scoped.
        $sections = [];
        foreach ($models as $name) {
            if (!in_array($name, $allowed, true)) {
                $sections[] = "### $name\n(not in scope — cannot be read here)";
                continue;
            }
            $section = AiPromptBuilder::schemaSection($name);
            $sections[] = $section !== '' ? rtrim($section) : "### $name\n(unknown model)";
        }
        return implode("\n\n", $sections);
    }

}
