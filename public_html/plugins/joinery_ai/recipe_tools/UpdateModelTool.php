<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelWriteExecutor.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/QueueableToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ProposedActionFacts.php'));

/**
 * Update allowlisted fields on an existing row. authenticate_write() runs
 * with the recipe owner's identity (admin reach). Idempotent — same input
 * produces the same end state. Safe to retry after error responses.
 *
 * Queueable: in a surface that defers writes, a call renders as an approval
 * card built from these literal arguments (specs/implemented/ai_action_queue.md).
 */
class UpdateModelTool implements RecipeToolInterface, QueueableToolInterface {

    public function renderProposedAction(array $input): array {
        $lines = ['Update ' . ProposedActionFacts::scalar($input['model'] ?? '?')
                . ' #' . ProposedActionFacts::scalar($input['key'] ?? '?')];
        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        return array_merge($lines, ProposedActionFacts::fieldLines($fields));
    }

    public static function name(): string {
        return 'update_model';
    }

    public static function description(): string {
        return 'Update allowlisted fields on an existing row. Pass `model` '
             . '(class name), `key` (primary key value), and `fields` '
             . '(column => value map). Only fields in the model\'s '
             . '$ai_writable_fields allowlist are applied. Returns '
             . '{ status, model, key, fields_set } on success. Idempotent.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['model', 'key', 'fields'],
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'The model class name. Must be in the recipe\'s allowlist.',
                ],
                'key' => [
                    'description' => 'Primary key value of the row to update.',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Field => value pairs to update.',
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $model = (string)($input['model'] ?? '');
        $key   = $input['key'] ?? null;
        if ($model === '') return self::error($model, 'invalid_input', "Missing 'model' parameter.");
        if ($key === null || $key === '') {
            return self::error($model, 'invalid_input', "Missing 'key' parameter.");
        }
        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];

        try {
            $envelope = ModelWriteExecutor::update($model, $key, $fields, $ctx);
            return ['content' => json_encode($envelope, JSON_UNESCAPED_SLASHES)];
        } catch (SystemAuthenticationError $e) {
            return self::error($model, 'authentication_failed', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return self::error($model, 'invalid_input', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[joinery_ai] update_model failed: ' . $e->getMessage());
            return self::error($model, 'update_failed', $e->getMessage());
        }
    }

    private static function error(string $model, string $code, string $message): array {
        return [
            'content'  => json_encode([
                'status'  => 'error',
                'model'   => $model,
                'code'    => $code,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES),
            'is_error' => true,
        ];
    }

}
