<?php
/**
 * Availability preview feed — for the availability editor's calendar preview.
 * Returns the owner's OPEN availability (working hours minus busy) as green
 * blocks, alongside their existing calendar commitments, so they can see the
 * shape of their availability against what's already on the calendar.
 *
 * Owner-only. GET: start, end (UTC). Returns { items: [ ... ] }.
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('includes/scheduling/SlotGenerator.php'));
require_once(PathHelper::getIncludePath('data/schedule_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_override_class.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
$user_id = $session->get_user_id();
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['items' => []]);
    exit;
}
$subject = CalendarSubject::user($user_id);

$today = gmdate('Y-m-d');
$start = isset($_GET['start']) ? $_GET['start'] : gmdate('Y-m-d 00:00:00', strtotime($today . ' -7 days'));
$end   = isset($_GET['end'])   ? $_GET['end']   : gmdate('Y-m-d 00:00:00', strtotime($today . ' +45 days'));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start .= ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   { $end   .= ' 00:00:00'; }

$items = array();

// Existing commitments (owner sees details).
foreach (CalendarItemSourceRegistry::getItems($subject, $start, $end, CalendarItem::VIS_DETAILS) as $it) {
    $items[] = $it->toArray();
}

// Open availability as green blocks.
$schedule = Schedule::for_subject($subject);
if ($schedule) {
    $windows = array();
    $mw = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
    $mw->load();
    foreach ($mw as $w) {
        $windows[] = ['day_of_week' => (int)$w->get('scw_day_of_week'), 'start' => $w->get('scw_start_time'), 'end' => $w->get('scw_end_time')];
    }
    $overrides = array();
    $mo = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
    $mo->load();
    foreach ($mo as $o) {
        $overrides[] = ['date' => substr($o->get('sco_date'), 0, 10), 'start' => $o->get('sco_start_time'), 'end' => $o->get('sco_end_time')];
    }

    $busy = CalendarItemSourceRegistry::getBusyBlocks($subject, $start, $end);
    $free = SlotGenerator::availableIntervals([
        'timezone' => $schedule->get('sch_timezone'),
        'windows' => $windows,
        'overrides' => $overrides,
        'range_start_utc' => $start,
        'range_end_utc' => $end,
        'busy' => $busy,
    ]);
    foreach ($free as $iv) {
        $items[] = [
            'start' => $iv['start'],
            'end'   => $iv['end'],
            'title' => 'Available',
            'url'   => null,
            'color' => '#16a34a',
            'type'  => 'available',
            'source_key' => 'availability:' . $iv['start'],
        ];
    }
}

echo json_encode(['items' => $items]);
