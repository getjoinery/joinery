<?php
/**
 * Coerces and validates input against a logic-file descriptor's `input`
 * schema. Each input entry can declare:
 *   - type: 'string' | 'int' | 'float' | 'bool' | 'email' | 'text' |
 *           'password' | 'date' | 'datetime'
 *   - required: bool
 *   - label: string (for error messages)
 *   - default: scalar (substituted when value is absent and not required)
 *
 * Returns a coerced value map. Throws InvalidArgumentException with a
 * specific message naming the failing field on a hard failure (missing
 * required, wrong type that can't be coerced).
 *
 * This is a v1 minimal validator scoped to AI write tools. It overlaps
 * conceptually with the broader DescriptorValidator described in
 * FUTURE_descriptor_consumers.md. When that lands, this can become a
 * thin wrapper or be replaced outright.
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

            if (!$present || $value === null || $value === '') {
                if ($required) {
                    throw new InvalidArgumentException("Missing required field: $label ($field).");
                }
                if (array_key_exists('default', $spec)) {
                    $out[$field] = $spec['default'];
                }
                continue;
            }

            $out[$field] = self::coerceValue($value, $type, $field, $label);
        }

        return $out;
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
