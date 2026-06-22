<?php
/**
 * Calendar quick-save endpoint — creates, updates, or deletes a native calendar
 * entry for the logged-in user. Used by the calendar popover.
 *
 * POST (form-encoded or JSON):
 *   action        'save' | 'delete'
 *   entry_id      int   (optional — present for update/delete)
 *   entry_date    Y-m-d
 *   entry_title   string
 *   entry_all_day truthy (absent when unchecked)
 *   entry_blocks  truthy (absent when unchecked)
 *   entry_start   HH:MM:SS (required when not all-day)
 *   entry_end     HH:MM:SS (required when not all-day)
 *
 * Returns: {ok: bool, error?: string, entry_id?: int}
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
$user_id = $session->get_user_id();
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($ct, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$tz      = $session->get_timezone();
$subject = CalendarSubject::user($user_id);
$auth    = ['current_user_id' => $user_id, 'current_user_permission' => $session->get_permission()];
$action  = $input['action'] ?? 'save';

try {

    if ($action === 'delete') {
        $eid = (int)($input['entry_id'] ?? 0);
        if (!$eid) { echo json_encode(['ok' => false, 'error' => 'No entry ID']); exit; }
        $entry = new CalendarEntry($eid, true);
        if (!$entry->key) { echo json_encode(['ok' => false, 'error' => 'Entry not found']); exit; }
        $entry->authenticate_write($auth);
        $entry->soft_delete();
        echo json_encode(['ok' => true]);
        exit;
    }

    $eid     = (int)($input['entry_id'] ?? 0);
    $date    = trim($input['entry_date'] ?? '');
    $title   = trim($input['entry_title'] ?? '');
    $all_day = !empty($input['entry_all_day']) && $input['entry_all_day'] !== '0';
    $blocks  = !isset($input['entry_blocks']) || (!empty($input['entry_blocks']) && $input['entry_blocks'] !== '0');
    $start_t = trim($input['entry_start'] ?? '');
    $end_t   = trim($input['entry_end'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date.']);
        exit;
    }

    if ($all_day) {
        $start_local = $date . ' 00:00:00';
        $next        = date('Y-m-d', strtotime($date . ' +1 day'));
        $end_local   = $next . ' 00:00:00';
        $start_utc   = LibraryFunctions::convert_time($start_local, $tz, 'UTC', 'Y-m-d H:i:s');
        $end_utc     = LibraryFunctions::convert_time($end_local,   $tz, 'UTC', 'Y-m-d H:i:s');
    } else {
        if ($start_t === '' || $end_t === '' || $end_t <= $start_t) {
            echo json_encode(['ok' => false, 'error' => 'Enter a start and end time (end must be after start), or mark as all-day.']);
            exit;
        }
        $start_local = $date . ' ' . $start_t;
        $end_local   = $date . ' ' . $end_t;
        $start_utc   = LibraryFunctions::convert_time($start_local, $tz, 'UTC', 'Y-m-d H:i:s');
        $end_utc     = LibraryFunctions::convert_time($end_local,   $tz, 'UTC', 'Y-m-d H:i:s');
    }

    $entry = $eid ? new CalendarEntry($eid, true) : new CalendarEntry(NULL);
    if ($eid && $entry->key) {
        $entry->authenticate_write($auth);
    }
    if (!$eid) {
        $entry->set('cal_subject_type', $subject->type);
        $entry->set('cal_subject_id', $subject->id);
        $entry->set('cal_type', 'personal');
    }
    $entry->set('cal_start_utc', $start_utc);
    $entry->set('cal_end_utc', $end_utc);
    $entry->set('cal_start_local', $start_local);
    $entry->set('cal_end_local', $end_local);
    $entry->set('cal_timezone', $tz);
    $entry->set('cal_tzdata_version', '2026a');
    $entry->set('cal_all_day', $all_day);
    $entry->set('cal_title', $title);
    $entry->set('cal_blocks_availability', $blocks);
    $entry->set('cal_visibility', 'details');
    $entry->set('cal_update_time', gmdate('Y-m-d H:i:s'));
    $entry->save();
    echo json_encode(['ok' => true, 'entry_id' => $entry->key]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again.']);
}
