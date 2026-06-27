<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));

/**
 * Shared assembly of the data-model portions of an AI system prompt, used by
 * both RecipeRunner and ChatRunner so the two surfaces can't drift.
 *
 * Lazy discovery: the system prompt carries only a one-line **catalog** of the
 * in-scope models (name + $ai_description), not their full field schemas. The
 * model fetches a specific model's fields on demand via the describe_models
 * tool, which renders them with schemaSection() below. This keeps the fixed
 * per-turn cost proportional to the model *count*, not the total field count.
 *
 * Scope ($allowed) is the caller's allow-list of class names; entries not in the
 * registry are silently skipped (a stale name can't surface a schema).
 */
class AiPromptBuilder {

    /**
     * The cached-prefix catalog block: a one-line entry per in-scope model plus
     * the instruction to fetch fields before querying. '' when scope is empty
     * (the caller then also withholds query_model / describe_models).
     */
    public static function modelCatalogBlock(array $allowed): string {
        $lines = self::catalogLines($allowed);
        if ($lines === '') return '';
        return "## Data models you can read\n\n"
             . "Call describe_models([\"ModelName\"]) to see a model's fields before "
             . "querying it with query_model — don't guess field names. Models not "
             . "listed here cannot be read.\n\n"
             . "Available:\n" . $lines;
    }

    /**
     * One "  - Class — description" line per in-scope model (no header), for the
     * catalog block and for describe_models() called with no argument.
     */
    public static function catalogLines(array $allowed): string {
        $registry = ModelRegistry::all();
        $out = [];
        foreach ($allowed as $class) {
            if (!isset($registry[$class])) continue;
            $desc = (string)($registry[$class]['description'] ?? '');
            $out[] = "  - $class" . ($desc !== '' ? " — $desc" : '');
        }
        return empty($out) ? '' : implode("\n", $out);
    }

    /**
     * Full field schema for one model — the "### Class — desc / Fields: …" block
     * the prompt used to preload for every model and that describe_models now
     * returns on demand. '' if the class isn't registered.
     */
    public static function schemaSection(string $class): string {
        $registry = ModelRegistry::all();
        if (!isset($registry[$class])) return '';
        $schema = ModelSchemaBuilder::build($class);
        $section = "### " . $schema['class'];
        if (!empty($schema['description'])) $section .= " — " . $schema['description'];
        $section .= "\nFields:\n";
        foreach ($schema['fields'] as $field => $spec) {
            $type = $spec['type'] ?? 'string';
            if (isset($spec['format'])) $type .= " (" . $spec['format'] . ")";
            $section .= "  - $field: $type\n";
        }
        return $section;
    }

    /**
     * Whether any in-scope model declares untrusted fields — drives whether the
     * untrusted-input delimiter contract needs to appear in the prompt.
     */
    public static function anyUntrusted(array $allowed): bool {
        $registry = ModelRegistry::all();
        foreach ($allowed as $class) {
            if (!isset($registry[$class])) continue;
            $u = $registry[$class]['untrusted_fields'] ?? [];
            if (is_array($u) && !empty($u)) return true;
        }
        return false;
    }

}
