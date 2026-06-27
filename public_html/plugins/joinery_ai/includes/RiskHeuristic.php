<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));

/**
 * Decides whether a mutating tool call in an interactive (confirmation-
 * required) context runs inline or must be held for a human sign-off.
 * Classification uses only signals that already exist — the action
 * descriptor's ai_agent tier and the owner-column naming convention — so
 * there is no new per-column marking. See the chat-assistant spec, §"the
 * risk heuristic".
 *
 * Dormant for recipes: their context returns requiresConfirmation() = false,
 * so AgentLoop never calls this. It is exercised by the interactive chat
 * surface (a later phase) and by the risk-heuristic unit test.
 *
 * The owner inference is a soft hint, never a security gate — the worst case
 * is one unnecessary confirmation card. Anything ambiguous (zero or 2+ owner
 * columns, an owner that can't be read, an unknown mutating tool) fails safe
 * to CONFIRM.
 */
class RiskHeuristic {

    const INLINE  = 'inline';
    const CONFIRM = 'confirm';

    /**
     * Is this tool_use a mutating call the confirmation hook should weigh?
     * Generic writes always are; an invoke_action is only if the action's
     * descriptor declares mutates. Read tools and read-only actions flow
     * inline regardless and never reach classify().
     */
    public static function isMutating(array $tool_use): bool {
        $name = $tool_use['name'] ?? '';
        if (in_array($name, ['create_model', 'update_model', 'delete_model'], true)) {
            return true;
        }
        if ($name === 'invoke_action') {
            $action = (string)($tool_use['input']['name'] ?? '');
            $info = ActionRegistry::get($action);
            return $info !== null && !empty($info['descriptor']['mutates']);
        }
        return false;
    }

    /**
     * Classify a mutating call: INLINE (run now) or CONFIRM (hold for a live
     * sign-off). Net policy: a create/update to a row the actor owns is
     * inline; deletes, writes beyond the actor, and actions confirm (actions
     * unless their descriptor marks them 'auto').
     */
    public static function classify(array $tool_use, ToolContext $context): string {
        $name = $tool_use['name'] ?? '';
        $input = isset($tool_use['input']) && is_array($tool_use['input']) ? $tool_use['input'] : [];

        switch ($name) {
            case 'delete_model':
                return self::CONFIRM;                       // destructive — always
            case 'create_model':
            case 'update_model':
                return self::classifyWrite($name, $input, $context);
            case 'invoke_action':
                return self::classifyAction($input);
            default:
                return self::CONFIRM;                       // unknown mutating tool — fail safe
        }
    }

    /** An action is inline only if its author explicitly marked it 'auto'. */
    private static function classifyAction(array $input): string {
        $info = ActionRegistry::get((string)($input['name'] ?? ''));
        if ($info === null) return self::CONFIRM;
        return ActionRegistry::agentTier($info['descriptor']) === 'auto'
            ? self::INLINE : self::CONFIRM;
    }

    /**
     * A generic write is inline only when it targets a row the acting user
     * owns, inferred from the lone *_usr_user_id / *_owner_user_id column.
     */
    private static function classifyWrite(string $tool, array $input, ToolContext $context): string {
        $model = (string)($input['model'] ?? '');
        if ($model === '') return self::CONFIRM;

        $owner_cols = self::ownerColumns($model);
        if (count($owner_cols) !== 1) return self::CONFIRM;   // ownerless or multi-owner → confirm
        $owner_col = $owner_cols[0];

        $row_owner = ($tool === 'create_model')
            ? self::ownerFromFields($input, $owner_col)
            : self::ownerFromRow($model, $input['key'] ?? null, $owner_col);

        if ($row_owner === null) return self::CONFIRM;        // can't establish ownership → confirm
        return $row_owner === (int)$context->actingUserId()
            ? self::INLINE : self::CONFIRM;
    }

    /** Field names matching the single-owner naming convention. */
    private static function ownerColumns(string $model): array {
        if (!class_exists($model) || !isset($model::$field_specifications)) return [];
        $out = [];
        foreach (array_keys($model::$field_specifications) as $col) {
            if (preg_match('/(_usr_user_id|_owner_user_id)$/', $col)) $out[] = $col;
        }
        return $out;
    }

    /** Owner of a to-be-created row: the owner column in the proposed fields. */
    private static function ownerFromFields(array $input, string $owner_col): ?int {
        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        return array_key_exists($owner_col, $fields) ? (int)$fields[$owner_col] : null;
    }

    /** Owner of an existing row: read the owner column off the live row. */
    private static function ownerFromRow(string $model, $key, string $owner_col): ?int {
        $key = (int)$key;
        if ($key <= 0) return null;
        try {
            $row = new $model($key, true);
            if (!$row->key) return null;
            $val = $row->get($owner_col);
            return $val === null ? null : (int)$val;
        } catch (Throwable $e) {
            return null;
        }
    }
}
