<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelWriteExecutor.php'));

/**
 * Create a new row in a model that opts into AI writes via a non-empty
 * $ai_writable_fields allowlist. The LLM passes every value via `fields`,
 * including any user-id / ownership column the model defines.
 *
 * Returns the response envelope on success, or a structured error on
 * failure. Success envelope includes the new row's key and creation time
 * so the LLM can recognize prior success from its conversation history
 * and not re-emit the call.
 */
class CreateModelTool implements RecipeToolInterface {

    public static function name(): string {
        return 'create_model';
    }

    public static function description(): string {
        return 'Create a new row in a model that opts into AI writes. Pass '
             . '`model` as the class name and `fields` as a map of column => '
             . 'value pairs. Only fields in the model\'s $ai_writable_fields '
             . 'allowlist are applied; others are silently dropped (the '
             . 'response\'s fields_set tells you what was applied). Returns '
             . '{ status, model, key, fields_set, created_time } on success.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['model', 'fields'],
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'The model class name (e.g. "UserNote"). Must be in the recipe\'s allowlist.',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Field => value pairs to set on the new row.',
                ],
            ],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $model = (string)($input['model'] ?? '');
        if ($model === '') {
            return self::error($model, 'invalid_input', "Missing 'model' parameter.");
        }
        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];

        try {
            $envelope = ModelWriteExecutor::create($model, $fields, $ctx);
            return ['content' => json_encode($envelope, JSON_UNESCAPED_SLASHES)];
        } catch (SystemAuthenticationError $e) {
            return self::error($model, 'authentication_failed', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return self::error($model, 'invalid_input', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[joinery_ai] create_model failed: ' . $e->getMessage());
            return self::error($model, 'create_failed', $e->getMessage());
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
