<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));

/**
 * Read-only introspection of actions in the recipe's allow-list. Returns
 * each action's descriptor (name, description, mutates, input schema).
 * The LLM never sees actions outside the allow-list, even for discovery —
 * same scoping principle as describe_models on the read side.
 *
 * Optional `mutates` filter: 'mutates_only' or 'reads_only'.
 */
class DescribeActionsTool implements RecipeToolInterface {

    public static function name(): string {
        return 'describe_actions';
    }

    public static function description(): string {
        return 'List the actions this recipe is allowed to invoke. Returns '
             . 'each action\'s name, description, whether it mutates state, '
             . 'and its input schema. Use this to discover what is callable '
             . 'before constructing an invoke_action call. Optional `filter` '
             . 'parameter: "mutates_only" or "reads_only".';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'enum' => ['mutates_only', 'reads_only', 'all'],
                    'description' => 'Optional filter. Default "all".',
                ],
            ],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $filter = (string)($input['filter'] ?? 'all');

        $allowed = $ctx->recipe->get('rcp_allowed_actions');
        if (is_string($allowed)) {
            $decoded = json_decode($allowed, true);
            $allowed = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($allowed)) $allowed = [];

        if (empty($allowed)) {
            return 'No actions are allowed for this recipe.';
        }

        $registry = ActionRegistry::all();
        $out = [];
        foreach ($allowed as $name) {
            if (!isset($registry[$name])) continue;
            $d = $registry[$name]['descriptor'];
            // Default-deny: never advertise an action that isn't agent-exposed,
            // even if it slipped onto the allow-list — invoke_action would
            // refuse it anyway.
            if (!ActionRegistry::isAgentCallable($d)) continue;
            $mutates = !empty($d['mutates']);
            if ($filter === 'mutates_only' && !$mutates) continue;
            if ($filter === 'reads_only' && $mutates) continue;
            $out[] = [
                'name'        => $name,
                'description' => $d['description'] ?? '',
                'mutates'     => $mutates,
                'input'       => $d['input'] ?? [],
            ];
        }

        if (empty($out)) {
            return 'No actions match the filter.';
        }

        $count = count($out);
        $header = $count . ' action' . ($count === 1 ? '' : 's') . ' available:';
        return $header . "\n\n" . json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

}
