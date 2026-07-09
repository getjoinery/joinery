<?php
/**
 * Coerces and validates input against a logic-file descriptor's `input`
 * schema. Each input entry can declare:
 *   - type: 'string' | 'int' | 'float' | 'bool' | 'email' | 'text' |
 *           'password' | 'date' | 'datetime' | 'array'
 *   - required: bool
 *   - label: string (for error messages)
 *   - default: scalar (substituted when value is absent and not required)
 *   - enum: string[] (string/int/float fields — value must be one of these)
 *   - min / max: number bounds (int/float fields)
 *   - max_length: int (string/text fields)
 *   - items: field-descriptor map (type 'array' only — the shape of each
 *     element, recursively coerced the same way as a top-level schema)
 *   - max_items: int (type 'array' only)
 *
 * Returns a coerced value map. Throws InvalidArgumentException with a
 * specific message naming the failing field on a hard failure (missing
 * required, wrong type that can't be coerced, out of bounds, not in enum).
 *
 * Consumers: the REST API action boundary (ApiLogicEndpoint validates a
 * request body against the action's _logic_descriptor() input schema before
 * the logic runs) and the joinery_ai plugin (ActionInvoker, PipelineRunner).
 * The logic file's own validation still runs as the backstop — this is the
 * fast first-pass at the boundary, not a replacement.
 *
 * @version 1.1
 * @changelog 1.1 - Promoted to core includes/: consumed by the REST API
 *   action endpoint as well as joinery_ai.
 */
class DescriptorValidator {

    public static function coerce(array $descriptor, array $input): array {
        $schema = isset($descriptor['input']) && is_array($descriptor['input'])
            ? $descriptor['input'] : [];
        $out = [];

        foreach ($schema as $field => $spec) {
            if (!is_array($spec)) continue;

            $type = $spec['type'] ?? 'string';
            $required = !empty($spec['required']);
            $label = $spec['label'] ?? $field;
            $present = array_key_exists($field, $input);
            $value = $present ? $input[$field] : null;

            if (!$present || $value === null || $value === '' || ($type === 'array' && $value === [])) {
                if ($required) {
                    throw new InvalidArgumentException("Missing required field: $label ($field).");
                }
                if (array_key_exists('default', $spec)) {
                    $out[$field] = $spec['default'];
                }
                continue;
            }

            if ($type === 'array') {
                $out[$field] = self::coerceArray($value, $spec, $field, $label);
                continue;
            }

            $coerced = self::coerceValue($value, $type, $field, $label);
            self::checkBounds($coerced, $spec, $type, $field, $label);
            $out[$field] = $coerced;
        }

        return $out;
    }

    /**
     * type 'array' — a list of objects, each coerced against `items` (a
     * nested field-descriptor map, same shape as a top-level `input` schema).
     */
    private static function coerceArray($value, array $spec, string $field, string $label): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("$label ($field) must be an array.");
        }
        if (isset($spec['max_items']) && count($value) > (int)$spec['max_items']) {
            throw new InvalidArgumentException("$label ($field) must have at most {$spec['max_items']} items.");
        }
        $item_schema = isset($spec['items']) && is_array($spec['items']) ? $spec['items'] : [];
        $out = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException("$label ($field)[$i] must be an object.");
            }
            $out[] = self::coerce(['input' => $item_schema], $item);
        }
        return $out;
    }

    /**
     * Post-coercion checks that apply to a scalar's already-typed value:
     * enum membership, numeric min/max, string max_length.
     */
    private static function checkBounds($value, array $spec, string $type, string $field, string $label): void {
        if (isset($spec['enum']) && is_array($spec['enum']) && !empty($spec['enum'])) {
            if (!in_array($value, $spec['enum'], true)) {
                throw new InvalidArgumentException(
                    "$label ($field) must be one of: " . implode(', ', $spec['enum']) . '.');
            }
        }
        if (in_array($type, ['int', 'integer', 'float', 'number'], true)) {
            if (isset($spec['min']) && $value < $spec['min']) {
                throw new InvalidArgumentException("$label ($field) must be at least {$spec['min']}.");
            }
            if (isset($spec['max']) && $value > $spec['max']) {
                throw new InvalidArgumentException("$label ($field) must be at most {$spec['max']}.");
            }
        }
        if (in_array($type, ['string', 'text', 'password'], true) && isset($spec['max_length'])) {
            if (mb_strlen((string)$value) > (int)$spec['max_length']) {
                throw new InvalidArgumentException("$label ($field) must be at most {$spec['max_length']} characters.");
            }
        }
    }

    /**
     * Render the "respond with ONLY this JSON" output instruction from a
     * verdict descriptor — the generated half of the pipeline exchange (see
     * specs/joinery_ai_item_pipeline.md § DescriptorValidator extensions).
     * Keeping this next to coerce() means the instruction shown to the model
     * and the schema that validates its answer can never drift apart.
     */
    public static function renderOutputInstruction(array $descriptor): string {
        $schema = isset($descriptor['input']) && is_array($descriptor['input'])
            ? $descriptor['input'] : $descriptor;
        $lines = [];
        foreach ($schema as $field => $spec) {
            if (!is_array($spec)) continue;
            $lines[] = '  "' . $field . '": ' . self::describeField($spec);
        }
        return "Respond with ONLY a single JSON object, no other text before or after it:\n\n"
             . "{\n" . implode(",\n", $lines) . "\n}";
    }

    private static function describeField(array $spec): string {
        $type = $spec['type'] ?? 'string';
        $bits = [$type];
        if (!empty($spec['required'])) $bits[] = 'required';
        if (isset($spec['enum']) && is_array($spec['enum'])) {
            $bits[] = 'one of: ' . implode(' | ', $spec['enum']);
        }
        if (isset($spec['min']) || isset($spec['max'])) {
            $bits[] = 'range ' . ($spec['min'] ?? '-inf') . '..' . ($spec['max'] ?? '+inf');
        }
        if (isset($spec['max_length'])) {
            $bits[] = 'max ' . $spec['max_length'] . ' chars';
        }
        if ($type === 'array') {
            $item_schema = isset($spec['items']) && is_array($spec['items']) ? $spec['items'] : [];
            $item_bits = [];
            foreach ($item_schema as $item_field => $item_spec) {
                if (!is_array($item_spec)) continue;
                $item_bits[] = $item_field . ': ' . self::describeField($item_spec);
            }
            $bits[] = 'items {' . implode(', ', $item_bits) . '}';
            if (isset($spec['max_items'])) $bits[] = 'max_items ' . $spec['max_items'];
        }
        $desc = isset($spec['label']) ? ' — ' . $spec['label'] : '';
        return implode(', ', $bits) . $desc;
    }

    private static function coerceValue($value, string $type, string $field, string $label) {
        switch ($type) {
            case 'int':
            case 'integer':
                if (is_int($value)) return $value;
                if (is_numeric($value) && (string)(int)$value === (string)$value) return (int)$value;
                if (is_string($value) && preg_match('/^-?\d+$/', $value)) return (int)$value;
                throw new InvalidArgumentException("$label ($field) must be an integer.");

            case 'float':
            case 'number':
                if (is_int($value) || is_float($value)) return (float)$value;
                if (is_string($value) && is_numeric($value)) return (float)$value;
                throw new InvalidArgumentException("$label ($field) must be a number.");

            case 'bool':
            case 'boolean':
                if (is_bool($value)) return $value;
                if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') return true;
                if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off') return false;
                throw new InvalidArgumentException("$label ($field) must be a boolean.");

            case 'email':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException("$label ($field) must be a valid email address.");
                }
                return $value;

            case 'date':
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new InvalidArgumentException("$label ($field) must be a date in YYYY-MM-DD format.");
                }
                return $value;

            case 'datetime':
                if (!is_string($value) || strtotime($value) === false) {
                    throw new InvalidArgumentException("$label ($field) must be a valid datetime.");
                }
                return $value;

            case 'string':
            case 'text':
            case 'password':
            default:
                if (is_string($value)) return $value;
                if (is_scalar($value)) return (string)$value;
                throw new InvalidArgumentException("$label ($field) must be a string.");
        }
    }

}
