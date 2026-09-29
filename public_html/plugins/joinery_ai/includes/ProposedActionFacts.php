<?php
/**
 * Shared fact-line rendering for QueueableToolInterface implementations: the
 * literal argument values, one line per field, values flattened and bounded.
 * Lives once so every tool's card states its arguments the same way.
 *
 * Beside the lines, a tool may also state its card as labelled fields
 * (ProposalFieldsInterface): field() and text() build those values.
 *
 * @version 1.3 - revealed() split out of verbatim(); field() and text() for
 *   labelled card fields
 */
class ProposedActionFacts {

    const VALUE_MAX = 160;

    /** verbatim() wraps at this width — under ActionQueue::FACT_LINE_MAX so a
     *  chunk is never itself truncated by the card renderer. */
    const VERBATIM_WIDTH = 180;

    /** Longest free-text field value (text()) — a note shown in full on request. */
    const TEXT_MAX = 4000;

    /** How a card field's value is laid out: as written, on one line the owner
     *  can expand (a link), or its first three lines with a 'more'. */
    const DISPLAY_TEXT  = 'text';
    const DISPLAY_LINE  = 'line';
    const DISPLAY_CLAMP = 'clamp';

    /** One labelled card field. */
    public static function field(string $label, string $value, string $display = self::DISPLAY_TEXT): array {
        return ['label' => $label, 'value' => $value, 'display' => $display];
    }

    /**
     * Free text shown whole: its line breaks kept (a note is often a list),
     * runs of spaces and blank lines closed up, bounded at TEXT_MAX.
     */
    public static function text($value): string {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string)$value));
        $value = preg_replace('/[^\S\n]+/u', ' ', $value);
        $value = preg_replace('/ *\n */', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);
        if ($value === '') return '(empty)';
        return mb_strlen($value) > self::TEXT_MAX
            ? mb_substr($value, 0, self::TEXT_MAX - 1) . '…' : $value;
    }

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

    /**
     * A literal value rendered COMPLETELY — wrapped across continuation lines,
     * never truncated. For arguments whose whole value is the point of the
     * card (an outbound URL, a search query: hot-turn egress approval), where
     * scalar()'s bound could hide the tail — exactly where smuggled data
     * would sit.
     */
    public static function verbatim(string $label, $value): array {
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        $value = trim((string)$value);
        if ($value === '') return [$label . ': (empty)'];

        $value = self::revealed($value);

        $lines = [];
        $first = true;
        while ($value !== '') {
            // Bound each line — prefix included — under VERBATIM_WIDTH, itself
            // under ActionQueue::FACT_LINE_MAX, so the card renderer can never
            // truncate a chunk (which would hide exactly where smuggled data
            // sits). Subtracting the prefix keeps the guarantee for any label,
            // not just today's short ones.
            $prefix = $first ? $label . ': ' : '↳ ';
            $room = self::VERBATIM_WIDTH - mb_strlen($prefix);
            if ($room < 1) $room = 1;
            $chunk = mb_substr($value, 0, $room);
            $value = mb_substr($value, $room);
            $lines[] = $prefix . $chunk;
            $first = false;
        }
        return $lines;
    }

    /**
     * A value with every whitespace difference made visible — the reveal
     * verbatim() applies, without its wrapping. For a value shown whole on
     * one expandable line (a link on a card).
     */
    public static function revealed(string $value): string {
        // Reveal whitespace instead of collapsing it. This card is the literal
        // record of what will leave the box, so two payloads that differ only in
        // their spacing — data smuggled in single-vs-double spaces, tabs,
        // newlines, or an invisible character — MUST render differently.
        // Merely preserving the bytes is not enough: the browser renders each
        // fact in a <p> whose default white-space collapses runs, so only
        // visible glyphs survive that layer. Single ASCII spaces (a normal word
        // gap) are left readable; any run of two or more is dotted, and every
        // other whitespace / control / format character is shown explicitly.
        $value = preg_replace_callback('/ {2,}/', function ($m) {
            return str_repeat('·', strlen($m[0]));
        }, $value);
        $value = preg_replace_callback('/[\p{Cc}\p{Cf}\p{Zs}\p{Zl}\p{Zp}]/u', function ($m) {
            switch ($m[0]) {
                case ' ':  return ' ';   // lone space — runs already dotted above
                case "\t": return '⇥';
                case "\n": return '⏎';
                case "\r": return '␍';
                default:   return '⟨U+' . sprintf('%04X', mb_ord($m[0], 'UTF-8')) . '⟩';
            }
        }, $value);
        return $value;
    }

}
