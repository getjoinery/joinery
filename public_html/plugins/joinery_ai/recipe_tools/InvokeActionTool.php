<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionInvoker.php'));

/**
 * Call an action defined in a logic file. The full validation gauntlet
 * (cross-record invariants, payment effects, hooks, external system calls)
 * runs by construction — this tool routes through the action's _logic()
 * function rather than touching models directly.
 *
 * The recipe's rcp_allowed_actions allow-list scopes which actions are
 * callable; describe_actions exposes the descriptors. invoke_action
 * refuses any name not on the list.
 */
class InvokeActionTool implements RecipeToolInterface {

    public static function name(): string {
        return 'invoke_action';
    }

    public static function description(): string {
        return 'Call an action by name with structured input. The action\'s '
             . 'business logic runs the full validation gauntlet. Use '
             . 'describe_actions first to see what is callable and the '
             . 'expected input shape. Returns { status, action, summary, '
             . 'data } on success; data matches the action\'s REST API shape.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The action name (as listed by describe_actions).',
                ],
                'input' => [
                    'type' => 'object',
                    'description' => 'Field => value pairs for the action input. Schema is in describe_actions output.',
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $name = (string)($input['name'] ?? '');
        if ($name === '') {
            return self::error($name, 'invalid_input', "Missing 'name' parameter.");
        }
        $args = isset($input['input']) && is_array($input['input']) ? $input['input'] : [];

        try {
            $envelope = ActionInvoker::invoke($name, $args, $ctx);
            $is_error = ($envelope['status'] ?? '') === 'error';
            $block = ['content' => json_encode($envelope, JSON_UNESCAPED_SLASHES)];
            if ($is_error) $block['is_error'] = true;
            return $block;
        } catch (SystemAuthenticationError $e) {
            return self::error($name, 'authentication_failed', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return self::error($name, 'invalid_input', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[joinery_ai] invoke_action failed: ' . $e->getMessage());
            return self::error($name, 'invoke_failed', $e->getMessage());
        }
    }

    private static function error(string $name, string $code, string $message): array {
        return [
            'content'  => json_encode([
                'status'  => 'error',
                'action'  => $name,
                'code'    => $code,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES),
            'is_error' => true,
        ];
    }

}
