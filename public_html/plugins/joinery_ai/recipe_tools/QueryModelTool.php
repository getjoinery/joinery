<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelQueryExecutor.php'));

/**
 * Run a filtered read against a model in the recipe's allowlist. The
 * available models and their field schemas are listed in the recipe's
 * system prompt. Soft-deleted rows are excluded automatically.
 *
 * Filter operators (suffixed on the field name):
 *   field          equality
 *   field_like     ILIKE '%value%'
 *   field_after    >= (timestamps)
 *   field_before   <=
 *   field_min      >= (numerics)
 *   field_max      <=
 */
class QueryModelTool implements RecipeToolInterface {

    public static function name(): string {
        return 'query_model';
    }

    public static function description(): string {
        return 'Query rows from a model in the recipe\'s allowlist. The '
             . 'allowed models and their field schemas are listed in the '
             . 'system prompt — use those field names exactly. Default '
             . 'operator is equality; suffix the field name with _like '
             . '(substring), _after / _min (>=), or _before / _max (<=) '
             . 'for ranges. Soft-deleted rows are excluded automatically. '
             . 'Default limit is 50, max is 200.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['model'],
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'The model class name (e.g. "EventRegistrant", "Order"). Must be in the recipe\'s allowlist as shown in the system prompt.',
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Field => value pairs. Suffix the field name with _like, _after, _before, _min, _max for non-equality operators.',
                ],
                'sort' => [
                    'type' => 'object',
                    'description' => 'Field => "ASC" or "DESC". Order in the object is significant.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1–200, default 50).',
                    'minimum' => 1,
                    'maximum' => 200,
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of fields to include. Default is all readable fields. Unknown or blocked fields are silently dropped.',
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $model = (string)($input['model'] ?? '');
        if ($model === '') {
            return ['content' => "Missing 'model' parameter.", 'is_error' => true];
        }

        $filters = isset($input['filters']) && is_array($input['filters']) ? $input['filters'] : [];
        $sort    = isset($input['sort'])    && is_array($input['sort'])    ? $input['sort']    : [];
        $limit   = isset($input['limit'])   ? (int)$input['limit']         : null;
        $fields  = isset($input['fields'])  && is_array($input['fields'])  ? $input['fields']  : null;

        try {
            $rows = ModelQueryExecutor::query($model, $filters, $sort, $limit, $fields, $ctx);
        } catch (InvalidArgumentException $e) {
            return ['content' => $e->getMessage(), 'is_error' => true];
        } catch (Throwable $e) {
            error_log('[joinery_ai] query_model failed: ' . $e->getMessage());
            return ['content' => 'Query failed: ' . $e->getMessage(), 'is_error' => true];
        }

        // Locked (sealed, no open window) rows are excluded from AI results and
        // stay pending for post-unlock catch-up (specs/mailbox_security_levels.md
        // § AI Processing). Report the count so the model knows results are partial.
        $locked_excluded = ModelQueryExecutor::lastLockedExcluded();
        $partial_note = $locked_excluded > 0
            ? ' (' . $locked_excluded . ' sealed row' . ($locked_excluded === 1 ? '' : 's')
                . ' excluded while locked — results are partial)'
            : '';

        if (empty($rows)) {
            return $locked_excluded > 0
                ? 'No readable rows match' . $partial_note . '.'
                : 'No rows match.';
        }
        $count = count($rows);
        $header = $count . ' row' . ($count === 1 ? '' : 's') . ' returned' . $partial_note . ':';
        return $header . "\n\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

}
