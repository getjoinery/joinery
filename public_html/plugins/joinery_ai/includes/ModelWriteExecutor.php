<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));

/**
 * Write-side security boundary for create_model / update_model / delete_model.
 *
 * Enforcement order (fixed):
 *   1. Per-recipe allowlist — model must appear in rcp_allowed_models.
 *   2. Model opt-in — registry knows the class AND has a non-empty
 *      writable_fields after scan-time strip ($ai_excluded_fields, auto-block).
 *   3. Field allowlist — only fields in the post-strip writable_fields are
 *      passed to set(). Unknown / excluded / auto-blocked fields are silently
 *      dropped; the response envelope's fields_set tells the LLM what was
 *      applied so it can adapt without retrying.
 *   4. set() each surviving field, prepare(), authenticate_write() with the
 *      recipe owner's identity, save().
 *
 * Soft-delete uses the same model opt-in + allowlist gate, runs
 * authenticate_write(), and calls soft_delete() — no field allowlist
 * involved (it sets delete_time, not user fields).
 */
class ModelWriteExecutor {

    /**
     * Tool names that constitute writes for the save-time taint gate.
     * Listed here so the gate's predicate doesn't drift if a fifth write
     * tool is added later — update this list, the gate picks it up.
     */
    const WRITE_TOOL_NAMES = ['create_model', 'update_model', 'delete_model', 'invoke_action'];

    /** Fields that constitute writes via Path 1 specifically. The gate uses
     *  this subset when the write surface is just the model tools. */
    const MODEL_WRITE_TOOL_NAMES = ['create_model', 'update_model', 'delete_model'];

    public static function create(string $class, array $fields, RecipeRunContext $ctx): array {
        self::checkAllowlist($class, $ctx);
        $info = self::checkOptIn($class);

        $applied = self::applyFields(new $class(NULL), $fields, $info['writable_fields']);
        /** @var SystemBase $obj */
        $obj = $applied['obj'];

        $obj->prepare();
        self::authenticate($obj, $ctx);
        $obj->save();

        $created_field = $class::$prefix . '_create_time';
        $created = method_exists($obj, 'get') && in_array($created_field, array_keys($class::$field_specifications), true)
            ? $obj->get($created_field) : null;

        return [
            'status'       => 'success',
            'model'        => $class,
            'key'          => $obj->key,
            'fields_set'   => $applied['fields_set'],
            'created_time' => $created,
        ];
    }

    public static function update(string $class, $key, array $fields, RecipeRunContext $ctx): array {
        self::checkAllowlist($class, $ctx);
        $info = self::checkOptIn($class);

        $obj = new $class($key, true);
        if (!$obj->key) {
            throw new InvalidArgumentException("Row not found: $class key=$key");
        }

        $applied = self::applyFields($obj, $fields, $info['writable_fields']);
        $obj = $applied['obj'];

        $obj->prepare();
        self::authenticate($obj, $ctx);
        $obj->save();

        return [
            'status'     => 'success',
            'model'      => $class,
            'key'        => $obj->key,
            'fields_set' => $applied['fields_set'],
        ];
    }

    public static function delete(string $class, $key, RecipeRunContext $ctx): array {
        self::checkAllowlist($class, $ctx);
        // Delete uses opt-in (writable_fields non-empty) but skips field-allowlist
        // since soft_delete() touches delete_time only.
        self::checkOptIn($class);

        $obj = new $class($key, true);
        if (!$obj->key) {
            throw new InvalidArgumentException("Row not found: $class key=$key");
        }

        self::authenticate($obj, $ctx);

        if (!method_exists($obj, 'soft_delete')) {
            throw new RuntimeException("$class does not support soft_delete().");
        }
        $obj->soft_delete();

        return [
            'status' => 'success',
            'model'  => $class,
            'key'    => $obj->key,
        ];
    }

    // --- helpers ---

    private static function checkAllowlist(string $class, RecipeRunContext $ctx): void {
        $allowed = $ctx->recipe->get('rcp_allowed_models');
        if (is_string($allowed)) {
            $decoded = json_decode($allowed, true);
            $allowed = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($allowed)) $allowed = [];
        if (!in_array($class, $allowed, true)) {
            throw new InvalidArgumentException(
                "Model '$class' is not allowed for this recipe. Allowed models: "
                . (empty($allowed) ? '(none)' : implode(', ', $allowed))
            );
        }
    }

    /**
     * Returns the registry entry for the model. Throws if the model isn't
     * registered or doesn't have a non-empty writable_fields allowlist after
     * registry-scan strip.
     */
    private static function checkOptIn(string $class): array {
        $info = ModelRegistry::get($class);
        if ($info === null) {
            throw new InvalidArgumentException("Model '$class' is not AI-readable.");
        }
        $writable = isset($info['writable_fields']) && is_array($info['writable_fields'])
            ? $info['writable_fields'] : [];
        if (empty($writable)) {
            throw new InvalidArgumentException(
                "Model '$class' is not AI-writable. Add a non-empty \$ai_writable_fields "
                . "allowlist on the model class to opt in."
            );
        }
        return $info;
    }

    /**
     * Apply allowlisted fields to the object. Returns:
     *   ['obj' => SystemBase, 'fields_set' => string[]]
     * Unknown / non-allowed fields are silently dropped — the LLM sees the
     * applied list in the envelope and can adapt without retrying.
     */
    private static function applyFields(SystemBase $obj, array $fields, array $writable): array {
        $fields_set = [];
        foreach ($fields as $name => $value) {
            if (!is_string($name)) continue;
            if (!in_array($name, $writable, true)) continue;
            $obj->set($name, $value);
            $fields_set[] = $name;
        }
        return ['obj' => $obj, 'fields_set' => $fields_set];
    }

    private static function authenticate(SystemBase $obj, RecipeRunContext $ctx): void {
        $session = SessionControl::get_instance();
        $obj->authenticate_write([
            'current_user_id'         => $ctx->owner_user_id,
            'current_user_permission' => (int)$session->get_permission(),
        ]);
    }

}
