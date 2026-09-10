<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/QueueableToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ProposedActionFacts.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarEntryImporter.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

/**
 * Put an entry on the acting user's own calendar — through the one
 * owner-fixed door every AI-originated entry takes, CalendarEntryImporter
 * (specs/joinery_ai_calendar_ai_surface.md).
 *
 * Queueable, and that is the point of it: the email schedule job PROPOSES an
 * entry it found in a stranger's mail by enqueueing this call for the owner
 * (specs/security_inventory.md S17), and the card the owner approves is
 * rendered from these literal arguments. The entry is created only when they
 * approve, and then exactly as the importer always creates one: tentative,
 * on their own calendar, deduped on its provenance so a second proposal for
 * the same message updates the same entry.
 *
 * The subject is always the acting user. Nothing in the input can aim the
 * entry at anyone else's calendar.
 *
 * @version 1.0
 */
class CreateCalendarEntryTool implements RecipeToolInterface, QueueableToolInterface {

    public function renderProposedAction(array $input): array {
        $when = ProposedActionFacts::scalar($input['start_local'] ?? '');
        if (!empty($input['all_day'])) {
            $when = substr($when, 0, 10) . ' (all day)';
        } elseif (!empty($input['end_local'])) {
            $when .= ' to ' . ProposedActionFacts::scalar($input['end_local']);
        }
        if (!empty($input['timezone'])) {
            $when .= ' ' . ProposedActionFacts::scalar($input['timezone']);
        }
        $lines = ['Add to your calendar: ' . ProposedActionFacts::scalar($input['title'] ?? '')];
        $lines[] = 'when: ' . $when;
        if (!empty($input['source_ref'])) {
            $lines[] = 'from: email #' . ProposedActionFacts::scalar($input['source_ref']);
        }
        return $lines;
    }

    public static function name(): string {
        return 'create_calendar_entry';
    }

    public static function description(): string {
        return 'Add an entry to the owner\'s own personal calendar. It is created '
             . 'tentative; the owner confirms or removes it in the calendar. '
             . 'Give wall-clock times and an IANA timezone.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['title', 'start_local'],
            'properties' => [
                'title'       => ['type' => 'string', 'description' => 'Event title (max 255 chars).'],
                'start_local' => ['type' => 'string', 'description' => 'Start, Y-m-d H:i:s wall clock.'],
                'end_local'   => ['type' => 'string', 'description' => 'End, Y-m-d H:i:s wall clock. Defaults to one hour after the start.'],
                'timezone'    => ['type' => 'string', 'description' => 'IANA timezone (e.g. America/New_York). Defaults to the owner\'s.'],
                'all_day'     => ['type' => 'boolean', 'description' => 'True for a date with no time (a deadline, a due date).'],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $title       = trim((string)($input['title'] ?? ''));
        $start_local = trim((string)($input['start_local'] ?? ''));
        $end_local   = isset($input['end_local']) && trim((string)$input['end_local']) !== ''
            ? trim((string)$input['end_local']) : null;
        $all_day     = !empty($input['all_day']);
        $tz          = trim((string)($input['timezone'] ?? ''));
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = $ctx->ownerTimezone();
        }
        if ($end_local === null && !$all_day && $start_local !== '') {
            $end_local = LibraryFunctions::time_shift($start_local, '1 hour', 'Y-m-d H:i:s');
        }

        // Provenance is a dedup key scoped to the acting user's own calendar.
        // The schedule job sets it to the message id so a re-judged message
        // updates its entry rather than adding a second; a model can name one
        // too, and the worst it can do with that is update its own earlier entry.
        $source_ref = isset($input['source_ref']) && trim((string)$input['source_ref']) !== ''
            ? trim((string)$input['source_ref']) : null;

        try {
            $entry = CalendarEntryImporter::upsert($ctx->actingUserId(), [
                'title'       => $title,
                'start_local' => $start_local,
                'end_local'   => $end_local,
                'timezone'    => $tz,
                'all_day'     => $all_day,
                'source'      => $source_ref !== null ? 'email' : 'assistant',
                'source_ref'  => $source_ref,
            ]);
        } catch (InvalidArgumentException $e) {
            return ['content' => 'create_calendar_entry error: ' . $e->getMessage(), 'is_error' => true];
        }

        return json_encode([
            'status'   => 'success',
            'summary'  => 'Added to the calendar as a tentative entry: ' . $title,
            'entry_id' => (int)$entry->key,
        ], JSON_UNESCAPED_SLASHES);
    }

}
