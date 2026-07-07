<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * AI action: create_calendar_entry — the agent-mode door onto the personal
 * calendar (specs/joinery_ai_calendar_ai_surface.md § 2). The acting user is
 * always the session user; the model never supplies an owner, a UTC time, or
 * provenance — CalendarEntryImporter fixes all three. Pipeline mode (the
 * email_schedule job) does not use this action; it calls the importer
 * directly the way EmailTriageJob writes InboundEmailMessage directly.
 */
function create_calendar_entry_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/calendar/CalendarEntryImporter.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(0);

    $user_id = $session->get_user_id();

    $title       = (string)($input['title'] ?? '');
    $start_local = (string)($input['start_local'] ?? '');
    $end_local   = $input['end_local'] ?? null;
    $timezone    = (string)($input['timezone'] ?? '');
    $all_day     = !empty($input['all_day']);

    if (($end_local === null || $end_local === '') && $start_local !== '') {
        $end_local = LibraryFunctions::time_shift($start_local, '1 hour', 'Y-m-d H:i:s');
    }

    try {
        $entry = CalendarEntryImporter::upsert($user_id, [
            'title'       => $title,
            'start_local' => $start_local,
            'end_local'   => $end_local,
            'timezone'    => $timezone,
            'all_day'     => $all_day,
            'source'      => 'assistant',
            'source_ref'  => null,
        ]);
    } catch (InvalidArgumentException $e) {
        return LogicResult::error($e->getMessage());
    }

    return LogicResult::render([
        'entry_id'  => (int)$entry->key,
        'start_utc' => $entry->get('cal_start_utc'),
        'status'    => 'tentative',
    ]);
}

function create_calendar_entry_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Add an entry to the current user\'s personal calendar (tentative until confirmed)',
    ];
}

function create_calendar_entry_logic_descriptor(): array {
    return [
        'description'      => 'Add an entry to the current user\'s personal calendar. '
                            . 'It is created tentative — the owner confirms or deletes it in the calendar UI.',
        'requires_session' => true,
        'mutates'          => true,
        'ai_agent'         => 'confirm',
        'input'            => [
            'title'       => ['type' => 'string', 'required' => true,  'max_length' => 255, 'label' => 'Title'],
            'start_local' => ['type' => 'string', 'required' => true,  'label' => 'Start (Y-m-d H:i:s, wall clock)'],
            'end_local'   => ['type' => 'string', 'required' => false, 'label' => 'End (Y-m-d H:i:s, wall clock; default 1 hour after start)'],
            'timezone'    => ['type' => 'string', 'required' => true,  'label' => 'IANA timezone (e.g. America/New_York)'],
            'all_day'     => ['type' => 'bool',   'required' => false, 'label' => 'All-day entry'],
        ],
    ];
}
?>
