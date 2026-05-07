<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));

/**
 * List the data models opted into AI reads. Returns each model's class name,
 * description, owner-scope status, and field schema. Optional case-insensitive
 * prefix filter trims the response when the recipe already knows what it
 * needs.
 *
 * Pair with query_model to actually pull rows.
 */
class DescribeModelsTool implements RecipeToolInterface {

    public static function name(): string {
        return 'describe_models';
    }

    public static function description(): string {
        return 'List the data models that can be queried with query_model. '
             . 'Returns each model\'s class name, human description, '
             . 'owner-scope status, and field schema. Provide an optional '
             . 'case-insensitive prefix to scope the response when you '
             . 'already know which models you need (e.g. "Event" returns '
             . 'Event, EventRegistrant, EventType).';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'prefix' => [
                    'type' => 'string',
                    'description' => 'Optional case-insensitive prefix on the model class name. Empty string returns all readable models.',
                ],
            ],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $prefix = strtolower(trim((string)($input['prefix'] ?? '')));

        $result = [];
        foreach (ModelRegistry::all() as $class => $info) {
            if ($prefix !== '' && strpos(strtolower($class), $prefix) !== 0) continue;
            $result[$class] = ModelSchemaBuilder::build($class);
        }

        if (empty($result)) {
            return $prefix === ''
                ? 'No models are opted into AI reads.'
                : "No readable models match prefix '$prefix'.";
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

}
