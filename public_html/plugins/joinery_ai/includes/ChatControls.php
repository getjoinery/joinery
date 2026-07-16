<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

/**
 * Validation + persistence for the per-chat controls (capability toggles and
 * model controls). Shared by chat_set_capabilities (edits to an existing chat)
 * and chat_send (seeding a brand-new chat) so both validate identically — one
 * place owns the field list, types, and bounds.
 */
class ChatControls {

    /** field name → AiConversation column. */
    const COLUMNS = [
        'data_access'    => 'aic_data_access',
        'web_search'     => 'aic_web_search',
        'history_access' => 'aic_history_access',
        'memory_access'  => 'aic_memory_access',
        'model'          => 'aic_model',
        'temperature'    => 'aic_temperature',
        'top_p'          => 'aic_top_p',
        'max_tokens'     => 'aic_max_tokens',
        'instructions'   => 'aic_instructions',
        'thinking_level' => 'aic_thinking_level',
        'attachment_mode'=> 'aic_attachment_mode',
    ];

    const INSTRUCTIONS_MAX = 8000;

    /**
     * Validate one field's raw value and return [column, storedValue] ready for
     * AiConversation::set(). Throws InvalidArgumentException on an unknown field
     * or an out-of-range value. Empty numeric values store NULL (fall back to the
     * setting default at resolution time).
     */
    public static function validate(string $field, $value): array {
        if (!isset(self::COLUMNS[$field])) {
            throw new InvalidArgumentException('Unknown field.');
        }
        $col = self::COLUMNS[$field];

        switch ($field) {
            case 'data_access':
            case 'web_search':
            case 'history_access':
            case 'memory_access':
                return [$col, self::truthy($value) ? 't' : 'f'];

            case 'model':
                $m = trim((string)$value);
                if ($m !== '' && !array_key_exists($m, LlmProviderFactory::allModels())) {
                    throw new InvalidArgumentException('Unknown model.');
                }
                return [$col, $m];

            case 'temperature':
                return [$col, self::numOrNull($value, 0.0, 2.0)];

            case 'top_p':
                return [$col, self::numOrNull($value, 0.0, 1.0)];

            case 'max_tokens':
                $v = trim((string)$value);
                if ($v === '') return [$col, null];
                $n = (int)$v;
                if ($n < 1000) throw new InvalidArgumentException('Max tokens must be at least 1000.');
                return [$col, $n];

            case 'instructions':
                return [$col, mb_substr((string)$value, 0, self::INSTRUCTIONS_MAX)];

            case 'thinking_level':
                $v = strtolower(trim((string)$value));
                if (!in_array($v, ['off', 'low', 'medium', 'high'], true)) {
                    throw new InvalidArgumentException('Invalid thinking level.');
                }
                return [$col, $v];

            case 'attachment_mode':
                $v = strtolower(trim((string)$value));
                if (!in_array($v, ['extract', 'on_demand', 'original'], true)) {
                    throw new InvalidArgumentException('Invalid attachment mode.');
                }
                return [$col, $v];
        }
        throw new InvalidArgumentException('Unknown field.'); // unreachable
    }

    /**
     * Seed a brand-new conversation from a request array (chat_send). Applies any
     * recognized field present in $post; silently skips invalid values so a bad
     * seed never blocks creating the chat (it just falls back to the default).
     */
    public static function seedNewConversation(AiConversation $conversation, array $post): void {
        foreach (array_keys(self::COLUMNS) as $field) {
            if (!array_key_exists($field, $post)) continue;
            try {
                [$col, $stored] = self::validate($field, $post[$field]);
                $conversation->set($col, $stored);
            } catch (InvalidArgumentException $e) {
                // skip invalid seed value
            }
        }
    }

    private static function truthy($value): bool {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    /** '' → null; otherwise a float clamped to [min,max]. */
    private static function numOrNull($value, float $min, float $max): ?float {
        $v = trim((string)$value);
        if ($v === '') return null;
        $n = (float)$v;
        if ($n < $min) $n = $min;
        if ($n > $max) $n = $max;
        return $n;
    }
}
