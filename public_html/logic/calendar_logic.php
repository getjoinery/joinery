<?php

/**
 * Personal calendar logic: authoring native entries (cal_items) for the logged-in
 * owner. The owner enters dates/times in their own timezone; entries are stored
 * as UTC instants. Create / edit / delete all route through here.
 */
function calendar_logic(array $input): LogicResult {

    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
    require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(0);
    $session->set_return();

    $user_id = $session->get_user_id();
    $subject = CalendarSubject::user($user_id);
    $tz = $session->get_timezone();

    $page_vars = array(
        'session' => $session,
        'subject' => $subject,
        'timezone' => $tz,
        'errors' => array(),
        'entry' => new CalendarEntry(NULL),
    );

    $auth = array('current_user_id' => $user_id, 'current_user_permission' => $session->get_permission());

    // --- delete ---
    if (isset($_POST['delete_entry'])) {
        $eid = LibraryFunctions::fetch_variable_local($input, 'entry_id', NULL, 'required', 'Entry id required.', 'safemode', 'int');
        $entry = new CalendarEntry($eid, true);
        if ($entry->key) {
            $entry->authenticate_write($auth);
            $entry->soft_delete();
        }
        return LogicResult::redirect('/profile/calendar?deleted=1');
    }

    // --- create / update ---
    if (isset($_POST['save_entry'])) {
        $eid     = LibraryFunctions::fetch_variable_local($input, 'entry_id', NULL, '', '', 'safemode', 'int');
        $date    = LibraryFunctions::fetch_variable_local($input, 'entry_date', '', 'required', 'A date is required.', 'safemode', NULL);
        $title   = LibraryFunctions::fetch_variable_local($input, 'entry_title', '', '', '', 'safemode', NULL);
        $all_day = !empty($input['entry_all_day']);
        $blocks  = !empty($input['entry_blocks']);
        $start_t = LibraryFunctions::fetch_variable_local($input, 'entry_start', '', '', '', 'safemode', NULL);
        $end_t   = LibraryFunctions::fetch_variable_local($input, 'entry_end', '', '', '', 'safemode', NULL);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $page_vars['errors'][] = 'Enter a valid date.';
        }

        $start_local = null;
        $end_local   = null;

        if (empty($page_vars['errors'])) {
            if ($all_day) {
                $start_local = $date . ' 00:00:00';
                $next        = date('Y-m-d', strtotime($date . ' +1 day'));
                $end_local   = $next . ' 00:00:00';
                $start_utc   = LibraryFunctions::convert_time($start_local, $tz, 'UTC', 'Y-m-d H:i:s');
                $end_utc     = LibraryFunctions::convert_time($end_local,   $tz, 'UTC', 'Y-m-d H:i:s');
            } else {
                if ($start_t === '' || $end_t === '' || $end_t <= $start_t) {
                    $page_vars['errors'][] = 'Enter a start and end time (end after start), or mark it all-day.';
                }
                if (empty($page_vars['errors'])) {
                    $start_local = $date . ' ' . $start_t;
                    $end_local   = $date . ' ' . $end_t;
                    $start_utc   = LibraryFunctions::convert_time($start_local, $tz, 'UTC', 'Y-m-d H:i:s');
                    $end_utc     = LibraryFunctions::convert_time($end_local,   $tz, 'UTC', 'Y-m-d H:i:s');
                }
            }
        }

        if (empty($page_vars['errors'])) {
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
            return LogicResult::redirect('/profile/calendar?saved=1');
        }
    }

    // --- load an entry for editing ---
    $edit_id = LibraryFunctions::fetch_variable_local($input, 'edit_entry', NULL, '', '', 'safemode', 'int');
    if ($edit_id) {
        $entry = new CalendarEntry($edit_id, true);
        if ($entry->key) {
            $entry->authenticate_read($auth);
            $page_vars['entry'] = $entry;
        }
    }

    $page_vars['saved'] = !empty($input['saved']);
    $page_vars['deleted'] = !empty($input['deleted']);

    return LogicResult::render($page_vars);
}
