<?php
/**
 * Converts a model's $field_specifications into a JSON-schema-flavoured
 * description used by the recipe runner to inject model schemas into the
 * system prompt. Drops fields caught by the auto-block pattern or listed
 * in $ai_excluded_fields.
 *
 * The output is informational — it tells the LLM what fields exist and what
 * shape they take. The actual query enforcement lives in ModelQueryExecutor.
 */
class ModelSchemaBuilder {

    /**
     * Suffixes auto-stripped from both reads and writes. Single-sourced from the
     * core unreadable floor (SystemBase) so the REST and AI surfaces share one
     * definition of a credential-suffixed field.
     */
    const AUTO_BLOCK_PATTERN = SystemBase::CREDENTIAL_FIELD_PATTERN;

    /**
     * The fields excluded for a model: the core unreadable floor ($api_unreadable_fields,
     * shared with the REST API) plus this surface's relevance/noise trims
     * ($ai_excluded_fields). The regex floor is applied separately in
     * isFieldBlocked(). Single source for "what does the AI never surface."
     */
    public static function excludedFor(string $class): array {
        $ai = self::staticOr($class, 'ai_excluded_fields', []);
        if (!is_array($ai)) $ai = [];
        $unreadable = (isset($class::$api_unreadable_fields) && is_array($class::$api_unreadable_fields))
            ? $class::$api_unreadable_fields : [];
        return array_values(array_unique(array_merge($unreadable, $ai)));
    }

    /**
     * Build the descriptor entry for one opted-in model.
     */
    public static function build(string $class): array {
        $excluded = self::excludedFor($class);
        $field_specs = $class::$field_specifications;

        $fields = [];
        foreach ($field_specs as $field => $spec) {
            if (self::isFieldBlocked($field, $excluded)) continue;
            $fields[$field] = self::fieldToSchema($spec);
        }

        return [
            'class'       => $class,
            'description' => self::staticOr($class, 'ai_description', ''),
            'fields'      => $fields,
        ];
    }

    /**
     * Returns true if a field name is auto-blocked or model-blocked.
     * Used by both the schema builder (for output filtering) and the executor
     * (for filter/sort/output validation) — same function, same answer.
     */
    public static function isFieldBlocked(string $field, array $excluded): bool {
        if (in_array($field, $excluded, true)) return true;
        if (preg_match(self::AUTO_BLOCK_PATTERN, $field)) return true;
        return false;
    }

    /**
     * Names of all fields the executor is allowed to surface for a model.
     * Combines field_specifications with the blocklist + auto-block.
     */
    public static function visibleFields(string $class): array {
        $excluded = self::excludedFor($class);
        $out = [];
        foreach (array_keys($class::$field_specifications) as $f) {
            if (self::isFieldBlocked($f, $excluded)) continue;
            $out[] = $f;
        }
        return $out;
    }

    private static function fieldToSchema(array $spec): array {
        $type = $spec['type'] ?? 'string';
        if (preg_match('/^int/i', $type) || preg_match('/^numeric|^decimal|^float|^double/i', $type)) {
            return ['type' => preg_match('/^int/i', $type) ? 'integer' : 'number'];
        }
        if ($type === 'bool' || $type === 'boolean') {
            return ['type' => 'boolean'];
        }
        if (preg_match('/^json/i', $type)) {
            return ['type' => 'object'];
        }
        if (preg_match('/^timestamp/i', $type)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if ($type === 'date') {
            return ['type' => 'string', 'format' => 'date'];
        }
        return ['type' => 'string'];
    }

    private static function staticOr(string $class, string $prop_name, $default) {
        $rc = new ReflectionClass($class);
        if (!$rc->hasProperty($prop_name)) return $default;
        $prop = $rc->getProperty($prop_name);
        if (!$prop->isStatic() || !$prop->isPublic()) return $default;
        return $class::${$prop_name};
    }

}
