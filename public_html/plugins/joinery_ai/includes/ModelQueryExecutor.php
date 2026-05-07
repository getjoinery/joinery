<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));

/**
 * Read-side security boundary for query_model.
 *
 * Enforcement order (fixed):
 *   1. Model opt-in (via ModelRegistry).
 *   2. Filter, sort, and output-field validation — every name must be in
 *      $field_specifications, must NOT be auto-blocked, must NOT be in
 *      $ai_excluded_fields. Same blocklist function used by the schema
 *      builder, so the LLM can never see a field it can't filter on (or
 *      vice versa).
 *   3. Soft-delete exclusion ({prefix}_delete_time IS NULL) when the model
 *      has that column.
 *   4. Direct PDO SELECT against the table. Multi-class option-key
 *      vocabulary is per-model and inappropriate to drive from outside;
 *      direct SELECT against $field_specifications-validated names is safer.
 *
 * Owner-scoping is intentionally NOT enforced. Joinery AI v2 is admin-only;
 * admins legitimately need cross-user views ("show me all unpaid orders"),
 * and forced owner-scoping breaks those use cases. The $ai_owner_field
 * declarations on opted-in models remain as inert forward-compat metadata
 * — when end-user recipes ship, owner-scoping returns and the executor
 * starts honouring the field. Until then, the real defenses are model
 * opt-in, the auto-block regex, and per-model $ai_excluded_fields.
 *
 * Operator vocabulary on filter keys:
 *   field           -> field = value
 *   field_like      -> field ILIKE '%value%'
 *   field_after     -> field >= value     (timestamps)
 *   field_before    -> field <= value
 *   field_min       -> field >= value     (numerics)
 *   field_max       -> field <= value
 */
class ModelQueryExecutor {

    const MAX_LIMIT = 200;
    const DEFAULT_LIMIT = 50;

    private const OP_SUFFIXES = ['_like', '_after', '_before', '_min', '_max'];

    public static function query(
        string $class,
        array $filters,
        array $sort,
        ?int $limit,
        ?array $output_fields,
        RecipeRunContext $ctx
    ): array {
        $info = ModelRegistry::get($class);
        if ($info === null) {
            throw new InvalidArgumentException("Model '$class' is not AI-readable.");
        }

        $excluded = $info['excluded_fields'];
        $field_specs = $class::$field_specifications;
        $all_fields = array_keys($field_specs);

        $select_fields = self::resolveOutputFields($output_fields, $all_fields, $excluded);
        if (empty($select_fields)) {
            throw new InvalidArgumentException("No selectable fields for '$class'.");
        }

        $where_parts = [];
        $params = [];

        foreach ($filters as $key => $value) {
            [$base_field, $op] = self::parseFilterKey((string)$key);
            if (!in_array($base_field, $all_fields, true)) {
                throw new InvalidArgumentException("Unknown filter field: $base_field");
            }
            if (ModelSchemaBuilder::isFieldBlocked($base_field, $excluded)) {
                throw new InvalidArgumentException("Cannot filter on blocked field: $base_field");
            }
            switch ($op) {
                case 'eq':
                    $where_parts[] = "$base_field = ?";
                    $params[] = $value;
                    break;
                case 'like':
                    $where_parts[] = "$base_field ILIKE ?";
                    $params[] = '%' . $value . '%';
                    break;
                case 'after':
                case 'min':
                    $where_parts[] = "$base_field >= ?";
                    $params[] = $value;
                    break;
                case 'before':
                case 'max':
                    $where_parts[] = "$base_field <= ?";
                    $params[] = $value;
                    break;
            }
        }

        $delete_field = $class::$prefix . '_delete_time';
        if (in_array($delete_field, $all_fields, true)) {
            $where_parts[] = "$delete_field IS NULL";
        }

        $order_parts = [];
        foreach ($sort as $field => $direction) {
            if (!in_array($field, $all_fields, true)) continue;
            if (ModelSchemaBuilder::isFieldBlocked($field, $excluded)) continue;
            $direction = strtoupper((string)$direction) === 'ASC' ? 'ASC' : 'DESC';
            $order_parts[] = "$field $direction";
        }

        $effective_limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $table = $class::$tablename;
        $sql = "SELECT " . implode(', ', $select_fields) . " FROM $table";
        if ($where_parts) $sql .= " WHERE " . implode(' AND ', $where_parts);
        if ($order_parts) $sql .= " ORDER BY " . implode(', ', $order_parts);
        $sql .= " LIMIT ?";
        $params[] = $effective_limit;

        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function resolveOutputFields(?array $requested, array $all, array $excluded): array {
        $visible = array_values(array_filter(
            $all,
            fn($f) => !ModelSchemaBuilder::isFieldBlocked($f, $excluded)
        ));
        if ($requested === null || empty($requested)) return $visible;

        $out = [];
        foreach ($requested as $f) {
            if (!is_string($f)) continue;
            if (!in_array($f, $all, true)) continue;
            if (ModelSchemaBuilder::isFieldBlocked($f, $excluded)) continue;
            $out[] = $f;
        }
        return $out ?: $visible;
    }

    private static function parseFilterKey(string $key): array {
        foreach (self::OP_SUFFIXES as $suffix) {
            if (substr($key, -strlen($suffix)) === $suffix) {
                return [substr($key, 0, -strlen($suffix)), substr($suffix, 1)];
            }
        }
        return [$key, 'eq'];
    }

}
