<?php
/**
 * Shared fact-line rendering for QueueableToolInterface implementations: the
 * literal argument values, one line per field, values flattened and bounded.
 * Lives once so every tool's card states its arguments the same way.
 *
 * @version 1.0
 */
class ProposedActionFacts {

    const VALUE_MAX = 160;

    /** One "name: value" line per entry of a field => value map. */
    public static function fieldLines(array $fields): array {
        $lines = [];
        foreach ($fields as $name => $value) {
            if (!is_string($name)) continue;
            $lines[] = $name . ': ' . self::scalar($value);
        }
        return $lines;
    }

    /** A literal value as bounded display text. */
    public static function scalar($value): string {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return '(empty)';
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        $value = trim((string)$value);
        if ($value === '') return '(empty)';
        $value = preg_replace('/\s+/', ' ', $value);
        return mb_strlen($value) > self::VALUE_MAX
            ? mb_substr($value, 0, self::VALUE_MAX - 1) . '…' : $value;
    }

}
