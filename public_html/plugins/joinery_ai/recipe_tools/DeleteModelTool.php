<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelWriteExecutor.php'));

/**
 * Soft-delete a row. authenticate_write() runs with the recipe owner's
 * identity. Idempotent — soft-deleting an already-deleted row is observably
 * a no-op. Safe to retry after error responses.
 */
class DeleteModelTool implements RecipeToolInterface {

    public static function name(): string {
        return 'delete_model';
    }

    public static function description(): string {
        return 'Soft-delete a row. Pass `model` (class name) and `key` '
             . '(primary key value). The row is marked as deleted (sets '
             . '{prefix}_delete_time); the row is not permanently removed. '
             . 'Returns { status, model, key } on success. Idempotent — '
             . 'safe to retry after errors.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['model', 'key'],
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'The model class name. Must be in the recipe\'s allowlist.',
                ],
                'key' => [
                    'description' => 'Primary key value of the row to soft-delete.',
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

        try {
            $envelope = ModelWriteExecutor::delete($model, $key, $ctx);
            return ['content' => json_encode($envelope, JSON_UNESCAPED_SLASHES)];
        } catch (SystemAuthenticationError $e) {
            return self::error($model, 'authentication_failed', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return self::error($model, 'invalid_input', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[joinery_ai] delete_model failed: ' . $e->getMessage());
            return self::error($model, 'delete_failed', $e->getMessage());
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
