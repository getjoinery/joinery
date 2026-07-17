<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));

/**
 * Read-side security boundary for query_model.
 *
 * Enforcement order (fixed):
 *   1. Per-recipe allowlist — the requested model must appear in the
 *      recipe's rcp_allowed_models. The recipe edit form picks this
 *      explicitly; absence means "this recipe was never granted access."
 *   2. Global model opt-in (via ModelRegistry / $ai_readable). The recipe
 *      allowlist is the user-facing knob; $ai_readable is the model
 *      author's ceiling. Both must agree.
 *   3. Filter, sort, and output-field validation — every name must be in
 *      $field_specifications, must NOT be auto-blocked, must NOT be in
 *      $ai_excluded_fields. Same blocklist function used by the schema
 *      builder, so the LLM can never see a field it can't filter on (or
 *      vice versa).
 *   4. Soft-delete exclusion ({prefix}_delete_time IS NULL) when the model
 *      has that column.
 *   5. Direct PDO SELECT against the table. Multi-class option-key
 *      vocabulary is per-model and inappropriate to drive from outside;
 *      direct SELECT against $field_specifications-validated names is safer.
 *   6. Wrap any value from $ai_untrusted_fields with per-run sentinel
 *      delimiters keyed to the run's nonce. Probabilistic defense against
 *      prompt injection — paired system-prompt language tells the LLM to
 *      treat content between the markers as data, not instructions.
 *
 * Owner-scoping is caller-dependent. An admin reads cross-user — admins
 * legitimately need views like "show me all unpaid orders," which a forced
 * owner filter would break. A non-admin member ($ctx->ownerScopedReads())
 * reads only their own rows: an owner WHERE clause is appended from the
 * model's resolved owner column(s) (OwnerScopeResolver), and a model whose
 * ownership can't be resolved is refused outright (it is also absent from a
 * member's allowedModels()). The other defenses — model opt-in, the auto-block
 * regex, per-model $ai_excluded_fields — apply to every caller.
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
        ToolContext $ctx
    ): array {
        $allowed = $ctx->allowedModels();
        if (!in_array($class, $allowed, true)) {
            throw new InvalidArgumentException(
                "Model '$class' is not in scope here. Allowed models: "
                . (empty($allowed) ? '(none)' : implode(', ', $allowed))
            );
        }

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

        // Member fence: a non-admin reads only their own rows. Resolve the
        // model's owner column(s) and OR-match them against the acting user.
        // A model that can't be contained (ambiguous/undeclared ownership) is
        // refused — defense in depth behind allowedModels(), which already
        // drops it from a member's scope. Admins skip this entirely.
        if ($ctx->ownerScopedReads()) {
            $scope = $info['owner_scope'] ?? ['mode' => 'hidden'];
            if ($scope['mode'] === 'hidden') {
                throw new InvalidArgumentException("Model '$class' is not available.");
            }
            if ($scope['mode'] === 'owner') {
                $uid = $ctx->actingUserId();
                $or = [];
                foreach ($scope['columns'] as $col) {
                    $or[] = "$col = ?";
                    $params[] = $uid;
                }
                $where_parts[] = count($or) > 1 ? '(' . implode(' OR ', $or) . ')' : $or[0];
            }
            if ($scope['mode'] === 'polymorphic_owner') {
                $where_parts[] = "{$scope['type_column']} = ? AND {$scope['id_column']} = ?";
                $params[] = $scope['type_value'];
                $params[] = $ctx->actingUserId();
            }
            // mode 'all' (ownerless catalog) adds no clause — members read all rows.
        }

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

        // A model may exclude some of its own rows from every AI read via a fixed,
        // input-free SQL predicate declared as static $ai_read_filter (e.g. mailbox
        // drafts — compose scratch that must never be summarized or query_model'd).
        if (property_exists($class, 'ai_read_filter')) {
            $filter_sql = $class::$ai_read_filter;
            if (is_string($filter_sql) && $filter_sql !== '') {
                $where_parts[] = $filter_sql;
            }
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
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $rows = self::decryptSealedFields($rows, $class);

        return self::wrapUntrustedFields($rows, $info, $select_fields, $ctx);
    }

    /** Rows dropped from the most recent query() because their vault was locked. */
    private static $last_locked_excluded = 0;

    /** How many rows the last query() excluded as locked (partial-result signal). */
    public static function lastLockedExcluded(): int {
        return self::$last_locked_excluded;
    }

    /**
     * Sealed Vault generic read hook (docs/sealed_vault.md) for the raw-row
     * path: this reads models by SQL, never instantiating them, so it cannot
     * go through SystemBase::get()'s decrypt hook. A model declaring
     * $sealed_fields gets each one run through its own
     * decryptSealedFieldStatic() override.
     *
     * A locked row is EXCLUDED from AI results, never substituted with
     * placeholder text (specs/mailbox_security_levels.md § AI Processing): an
     * AI recipe must not "process" a placeholder as if it were content. The row
     * stays pending — durable, ciphertext at rest — and catches up after the
     * next unlock. The count of dropped rows is surfaced so the model knows the
     * result set is partial.
     */
    private static function decryptSealedFields(array $rows, string $class): array {
        self::$last_locked_excluded = 0;
        $sealed = $class::$sealed_fields;
        if (empty($sealed)) return $rows;

        require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
        $out = [];
        foreach ($rows as $row) {
            $locked = false;
            foreach ($sealed as $field) {
                if (!array_key_exists($field, $row) || $row[$field] === null) continue;
                try {
                    $row[$field] = $class::decryptSealedFieldStatic($field, $row[$field], $row);
                } catch (VaultLockedException $e) {
                    $locked = true;
                    break;
                }
            }
            if ($locked) {
                self::$last_locked_excluded++;
                continue; // drop the whole row — it stays pending for post-unlock catch-up
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Wrap any returned values from $ai_untrusted_fields with the run's
     * untrusted-input nonce. The system prompt instructs the LLM to treat
     * anything between the markers as data, never as instructions — defense
     * in depth against indirect prompt injection from user-generated text
     * (DM bodies, inbound mail, public bios, etc.). Per-run nonce so an
     * attacker can't pre-embed a closing tag.
     *
     * Excluded fields are already absent from $select_fields, so an
     * untrusted-and-excluded field never reaches the wrap step. JSONB
     * values are wrapped as the serialized blob whole — recipes needing
     * finer granularity can opt the field out.
     */
    private static function wrapUntrustedFields(array $rows, array $info, array $select_fields, ToolContext $ctx): array {
        $untrusted = isset($info['untrusted_fields']) && is_array($info['untrusted_fields'])
            ? $info['untrusted_fields'] : [];
        if (empty($untrusted)) return $rows;

        $effective = array_values(array_intersect($untrusted, $select_fields));
        if (empty($effective)) return $rows;

        $nonce = $ctx->untrustedNonce();
        $open  = "<<UNTRUSTED_$nonce>>";
        $close = "<</UNTRUSTED_$nonce>>";

        foreach ($rows as &$row) {
            foreach ($effective as $field) {
                if (!array_key_exists($field, $row)) continue;
                $val = $row[$field];
                if ($val === null) continue;
                if (!is_string($val)) $val = json_encode($val, JSON_UNESCAPED_SLASHES);
                $row[$field] = $open . $val . $close;
            }
        }
        return $rows;
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
