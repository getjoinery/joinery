<?php

/**
 * Personal calendar logic: authoring native entries (cal_entries) for the logged-in
 * owner. Handles non-recurring and recurring entries (with edit-scope choices).
 */
function calendar_logic(array $input): LogicResult {

    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
    require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
    require_once(PathHelper::getIncludePath('data/calendar_entry_exception_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(0);
    $session->set_return();

    $user_id = $session->get_user_id();
    $subject = CalendarSubject::user($user_id);
    $tz      = $session->get_timezone();

    $page_vars = [
        'session'         => $session,
        'subject'         => $subject,
        'timezone'        => $tz,
        'errors'          => [],
        'entry'           => new CalendarEntry(NULL),
        'is_occurrence'   => false,
        'parent_entry'    => null,
        'occurrence_date' => null,
        'show_scope_modal'=> false,
    ];

    $auth = ['current_user_id' => $user_id, 'current_user_permission' => $session->get_permission()];

    // -------------------------------------------------------------------------
    // DELETE (scope-aware)
    // -------------------------------------------------------------------------
    if (isset($_POST['delete_entry'])) {
        $eid   = LibraryFunctions::fetch_variable_local($input, 'entry_id', NULL, 'required', 'Entry id required.', 'safemode', 'int');
        $scope = LibraryFunctions::fetch_variable_local($input, 'scope', 'all', '', '', 'safemode', NULL);
        $odate = LibraryFunctions::fetch_variable_local($input, 'occurrence_date', '', '', '', 'safemode', NULL);

        $entry = new CalendarEntry($eid, true);
        if ($entry->key) {
            $entry->authenticate_write($auth);
            if ($entry->is_recurring_parent()) {
                _calendar_delete_recurring($entry, $scope, $odate);
            } else {
                $entry->soft_delete();
            }
        }
        return LogicResult::redirect('/profile/calendar?deleted=1');
    }

    // -------------------------------------------------------------------------
    // IMPORT (.ics upload)
    // -------------------------------------------------------------------------
    if (isset($_POST['import_entries'])) {
        require_once(PathHelper::getIncludePath('includes/calendar/IcsImporter.php'));

        $error = null;
        $tmp   = isset($_FILES['ics_file']['tmp_name']) ? $_FILES['ics_file']['tmp_name'] : '';

        if (empty($_FILES['ics_file']) || $tmp === '' || !is_uploaded_file($tmp)) {
            $error = 'Choose an .ics file to import.';
        } elseif ($_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'The file could not be uploaded. Please try again.';
        } elseif ($_FILES['ics_file']['size'] > 5 * 1024 * 1024) {
            $error = 'That file is too large (the limit is 5 MB).';
        } else {
            $contents = file_get_contents($tmp);
            if ($contents === false || stripos($contents, 'BEGIN:VCALENDAR') === false) {
                $error = 'That does not look like a calendar (.ics) file.';
            } else {
                $parsed  = IcsImporter::parse($contents);
                $summary = IcsImporter::import($parsed, $subject, $tz);
                $session->save_session_item('calendar_import_summary', $summary);
                return LogicResult::redirect('/profile/calendar?imported=1');
            }
        }

        $session->save_session_item('calendar_import_summary', ['error' => $error]);
        return LogicResult::redirect('/profile/calendar?imported=1');
    }

    // -------------------------------------------------------------------------
    // SAVE (create / update, scope-aware)
    // -------------------------------------------------------------------------
    if (isset($_POST['save_entry'])) {
        $eid   = LibraryFunctions::fetch_variable_local($input, 'entry_id', NULL, '', '', 'safemode', 'int');
        $date  = LibraryFunctions::fetch_variable_local($input, 'entry_date', '', 'required', 'A date is required.', 'safemode', NULL);
        $title = LibraryFunctions::fetch_variable_local($input, 'entry_title', '', '', '', 'safemode', NULL);
        $all_day = !empty($input['entry_all_day']);
        $blocks  = !empty($input['entry_blocks']);
        $start_t = LibraryFunctions::fetch_variable_local($input, 'entry_start', '', '', '', 'safemode', NULL);
        $end_t   = LibraryFunctions::fetch_variable_local($input, 'entry_end',   '', '', '', 'safemode', NULL);
        $scope   = LibraryFunctions::fetch_variable_local($input, 'scope', '', '', '', 'safemode', NULL);
        $odate   = LibraryFunctions::fetch_variable_local($input, 'occurrence_date', '', '', '', 'safemode', NULL);
        // '' = use my default (stored NULL); 0 = no reminder; else minutes before start.
        $reminder = _calendar_parse_reminder(LibraryFunctions::fetch_variable_local($input, 'entry_reminder', '', '', '', 'safemode', NULL));

        // Recurrence fields — read from the declarative FormWriter inputs.
        // The "Repeats" checkbox gates everything; frequency must be a known type.
        $rec_type     = null;
        $rec_interval = 1;
        $rec_days     = null;   // weekly: comma DOW list; monthly-by-weekday: single DOW digit
        $rec_week     = null;   // monthly-by-weekday ordinal (1-4, -1)
        $rec_end_date = null;
        $rec_ends     = 'never';
        $rec_count    = 0;

        if (!empty($input['entry_repeats'])) {
            $rec_freq = LibraryFunctions::fetch_variable_local($input, 'rec_frequency', '', '', '', 'safemode', NULL);
            if (in_array($rec_freq, array('daily', 'weekly', 'monthly', 'yearly'), true)) {
                $rec_type = $rec_freq;
            }
        }
        if ($rec_type) {
            $rec_interval = (int)(LibraryFunctions::fetch_variable_local($input, 'rec_interval', 1, '', '', 'safemode', NULL) ?: 1);
            if ($rec_interval < 1) { $rec_interval = 1; }

            if ($rec_type === 'weekly') {
                // rec_days[] is an array of weekday digits (0=Sun…6=Sat).
                $days = (isset($input['rec_days']) && is_array($input['rec_days']))
                    ? array_values(array_filter(
                        array_map('intval', $input['rec_days']),
                        function ($d) { return $d >= 0 && $d <= 6; }
                      ))
                    : array();
                sort($days);
                $rec_days = $days ? implode(',', array_unique($days)) : null;
            } elseif ($rec_type === 'monthly') {
                $mode = LibraryFunctions::fetch_variable_local($input, 'rec_monthly_mode', 'day', '', '', 'safemode', NULL);
                if ($mode === 'week') {
                    $rec_week = (int)LibraryFunctions::fetch_variable_local($input, 'rec_week', 1, '', '', 'safemode', NULL);
                    $dow      = LibraryFunctions::fetch_variable_local($input, 'rec_dow', '', '', '', 'safemode', NULL);
                    $rec_days = ($dow !== '' && $dow !== null) ? (string)(int)$dow : null;
                }
            }

            // Ends: never / on date / after N occurrences. "count" is converted
            // to a stored end date below, once the start time is known.
            $rec_ends = LibraryFunctions::fetch_variable_local($input, 'rec_ends', 'never', '', '', 'safemode', NULL);
            if ($rec_ends === 'date') {
                $d = LibraryFunctions::fetch_variable_local($input, 'rec_end_date', '', '', '', 'safemode', NULL);
                $rec_end_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null;
            } elseif ($rec_ends === 'count') {
                $rec_count = (int)LibraryFunctions::fetch_variable_local($input, 'rec_count', 0, '', '', 'safemode', NULL);
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $page_vars['errors'][] = 'Enter a valid date.';
        }

        $start_local = null;
        $end_local   = null;
        $start_utc   = null;
        $end_utc     = null;

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

        // "Ends after N occurrences" → stored end date. Walk the pattern on a
        // throwaway entry carrying the same recurrence fields and start; the
        // recurrence engine is the single source of truth for the dates.
        if (empty($page_vars['errors']) && $rec_type && $rec_ends === 'count' && $rec_count >= 1) {
            $probe = new CalendarEntry(NULL);
            _calendar_set_recurrence($probe, $rec_type, $rec_interval, $rec_days, $rec_week, null);
            $probe->set('cal_start_local', $start_local);
            $rec_end_date = $probe->nth_occurrence_date($date, $rec_count);
        }

        if (empty($page_vars['errors'])) {
            // An occurrence edit is identified by the occurrence_date, NOT by the
            // scope field (which is set by modal JS and can be absent). If JS didn't
            // run, default to the safe 'this occurrence only' rather than falling
            // through to a whole-series rewrite.
            $is_occurrence_edit = false;
            if ($eid && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$odate)) {
                $parent = new CalendarEntry($eid, true);
                if ($parent->key && $parent->is_recurring_parent()) {
                    $is_occurrence_edit = true;
                    $parent->authenticate_write($auth);
                    _calendar_save_recurring_scope(
                        $parent, ($scope ?: 'this'), $odate, $title, $all_day, $blocks,
                        $start_local, $end_local, $start_utc, $end_utc, $tz,
                        $rec_type, $rec_interval, $rec_days, $rec_week, $rec_end_date,
                        $subject, $reminder
                    );
                }
            }
            if (!$is_occurrence_edit) {
                // Create new or update a standalone / plain-edit of a recurring parent.
                $entry = $eid ? new CalendarEntry($eid, true) : new CalendarEntry(NULL);
                if ($eid && $entry->key) {
                    $entry->authenticate_write($auth);
                }
                if (!$eid) {
                    $entry->set('cal_subject_type', $subject->type);
                    $entry->set('cal_subject_id',   $subject->id);
                    $entry->set('cal_type',          'personal');
                }
                _calendar_set_fields($entry, $title, $all_day, $blocks, $start_local, $end_local, $start_utc, $end_utc, $tz);
                _calendar_set_recurrence($entry, $rec_type, $rec_interval, $rec_days, $rec_week, $rec_end_date);
                $entry->set('cal_reminder_minutes', $reminder);
                $entry->save();
            }
            return LogicResult::redirect('/profile/calendar?saved=1');
        }
    }

    // -------------------------------------------------------------------------
    // LOAD for editing
    // -------------------------------------------------------------------------
    $parent_id    = LibraryFunctions::fetch_variable_local($input, 'parent_id',      NULL, '', '', 'safemode', 'int');
    $occ_date     = LibraryFunctions::fetch_variable_local($input, 'occurrence_date', '',   '', '', 'safemode', NULL);
    $edit_id      = LibraryFunctions::fetch_variable_local($input, 'entry_id',        NULL, '', '', 'safemode', 'int');
    $edit_entry   = LibraryFunctions::fetch_variable_local($input, 'edit_entry',      NULL, '', '', 'safemode', 'int');

    if ($parent_id && preg_match('/^\d{4}-\d{2}-\d{2}$/', $occ_date)) {
        // Virtual occurrence: /profile/calendar/entry/{parent_id}/occurrence/{date}
        $parent = new CalendarEntry($parent_id, true);
        if ($parent->key) {
            $parent->authenticate_read($auth);
            if ($parent->is_recurring_parent() && $parent->date_matches_pattern($occ_date)) {
                $page_vars['parent_entry']     = $parent;
                $page_vars['occurrence_date']  = $occ_date;
                $page_vars['is_occurrence']    = true;
                $page_vars['show_scope_modal'] = true;
                $page_vars['entry']            = $parent; // pre-fill form from parent values
            }
        }
    } elseif ($edit_id) {
        // Standalone entry via /profile/calendar/entry/{entry_id}
        $entry = new CalendarEntry($edit_id, true);
        if ($entry->key) {
            $entry->authenticate_read($auth);
            $page_vars['entry'] = $entry;
        }
    } elseif ($edit_entry) {
        // Existing ?edit_entry=ID query-string path
        $entry = new CalendarEntry($edit_entry, true);
        if ($entry->key) {
            $entry->authenticate_read($auth);
            $page_vars['entry'] = $entry;
        }
    }

    $page_vars['saved']   = !empty($input['saved']);
    $page_vars['deleted'] = !empty($input['deleted']);

    // The owner's default reminder lead, so the entry form's "Use my default"
    // option can say what it currently means.
    require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
    $page_vars['reminder_default_minutes'] = (int)CalendarPreference::get_for($user_id)->get('cpr_reminder_default_minutes');

    // Has this subject authored (or imported) anything of its own yet? Drives the
    // first-run import prompt, which retires permanently on the first entry.
    // Counted account-wide rather than per visible month, so paging to an empty
    // month does not bring the prompt back.
    $own_entries = new MultiCalendarEntry([
        'subject_type' => $subject->type,
        'subject_id'   => $subject->id,
        'deleted'      => false,
    ]);
    $page_vars['has_own_entries'] = ($own_entries->count_all() > 0);

    // Import summary flash (set by the import branch before its redirect).
    $page_vars['import_summary'] = null;
    if (!empty($input['imported'])) {
        $sum = $session->get_saved_item('calendar_import_summary');
        $page_vars['import_summary'] = (is_array($sum) && !empty($sum)) ? $sum : null;
        $session->save_session_item('calendar_import_summary', array()); // clear
    }

    return LogicResult::render($page_vars);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function _calendar_set_fields(
    CalendarEntry $entry,
    string $title,
    bool $all_day,
    bool $blocks,
    ?string $start_local,
    ?string $end_local,
    ?string $start_utc,
    ?string $end_utc,
    string $tz
): void {
    $entry->set_core_fields($title, $all_day, $blocks, $start_local, $end_local, $start_utc, $end_utc, $tz);
}

/**
 * Normalize a submitted reminder choice for cal_reminder_minutes.
 * '' (use my default) → null; a valid lead choice (0 = no reminder, else
 * minutes before start) → int; anything else → null.
 */
function _calendar_parse_reminder($raw): ?int {
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return null;
    }
    require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
    $v = (int)$raw;
    return in_array($v, CalendarPreference::REMINDER_MINUTE_CHOICES, true) ? $v : null;
}

function _calendar_set_recurrence(
    CalendarEntry $entry,
    ?string $type,
    int $interval,
    ?string $days_of_week,
    ?int $week_of_month,
    ?string $end_date
): void {
    $entry->set('cal_recurrence_type',      $type);
    $entry->set('cal_recurrence_interval',  $type ? max(1, $interval) : 1);
    // Weekly: store comma-separated DOW list. Monthly-by-week: store single DOW digit.
    // Clear for all other types.
    $store_days = null;
    if ($type === 'weekly') {
        $store_days = $days_of_week;
    } elseif ($type === 'monthly' && $week_of_month !== null) {
        $store_days = $days_of_week; // single digit like "2" for Tuesday
    }
    $entry->set('cal_recurrence_days_of_week',  $store_days);
    $entry->set('cal_recurrence_week_of_month', ($type === 'monthly') ? $week_of_month : null);
    $entry->set('cal_recurrence_end_date',       $end_date);
}

function _calendar_delete_recurring(CalendarEntry $entry, string $scope, string $odate): void {
    switch ($scope) {
        case 'this':
            // Skip this single occurrence.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $odate)) {
                return;
            }
            $exc = new CalEntryException(NULL);
            $exc->set('cex_cal_entry_id',    $entry->key);
            $exc->set('cex_exception_date',  $odate);
            $exc->save();
            break;

        case 'future':
            // End series one day before this occurrence.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $odate)) {
                return;
            }
            $prev = date('Y-m-d', strtotime($odate . ' -1 day'));
            $entry->set('cal_recurrence_end_date', $prev);
            $entry->set('cal_update_time', gmdate('Y-m-d H:i:s'));
            $entry->save();
            break;

        case 'all':
        default:
            $entry->soft_delete();
            break;
    }
}

function _calendar_save_recurring_scope(
    CalendarEntry $parent,
    string $scope,
    string $odate,
    string $title,
    bool $all_day,
    bool $blocks,
    ?string $start_local,
    ?string $end_local,
    ?string $start_utc,
    ?string $end_utc,
    string $tz,
    ?string $rec_type,
    int $rec_interval,
    ?string $rec_days,
    ?int $rec_week,
    ?string $rec_end_date,
    $subject,
    $reminder = false
): void {
    // $reminder: false = not submitted (keep/copy what the row has),
    // null = "use my default", int = explicit choice (0 = no reminder).
    switch ($scope) {
        case 'this':
            // Add exception for the original date.
            $exc = new CalEntryException(NULL);
            $exc->set('cex_cal_entry_id',   $parent->key);
            $exc->set('cex_exception_date', $odate);
            $exc->save();

            // Create a standalone replacement entry.
            $rep = new CalendarEntry(NULL);
            $rep->set('cal_subject_type', $subject->type);
            $rep->set('cal_subject_id',   $subject->id);
            $rep->set('cal_type',         $parent->get('cal_type') ?: 'personal');
            $rep->set('cal_parent_entry_id',   $parent->key);
            $rep->set('cal_parent_entry_date', $odate);
            _calendar_set_fields($rep, $title, $all_day, $blocks, $start_local, $end_local, $start_utc, $end_utc, $tz);
            $rep->set('cal_reminder_minutes', ($reminder === false) ? $parent->get('cal_reminder_minutes') : $reminder);
            $rep->save();
            break;

        case 'future':
            // Truncate original series one day before the split date.
            $prev = date('Y-m-d', strtotime($odate . ' -1 day'));
            $parent->set('cal_recurrence_end_date', $prev);
            $parent->set('cal_update_time', gmdate('Y-m-d H:i:s'));
            $parent->save();

            // Copy exceptions on/after split date to the new parent.
            $exc_rows = new MultiCalEntryException(['cal_entry_id' => $parent->key]);
            $exc_rows->load();
            $future_exceptions = [];
            foreach ($exc_rows as $ex) {
                if ($ex->get('cex_exception_date') >= $odate) {
                    $future_exceptions[] = $ex->get('cex_exception_date');
                }
            }

            // Create new recurring parent starting from the split date.
            $new_parent = new CalendarEntry(NULL);
            $new_parent->set('cal_subject_type', $subject->type);
            $new_parent->set('cal_subject_id',   $subject->id);
            $new_parent->set('cal_type',         $parent->get('cal_type') ?: 'personal');
            _calendar_set_fields($new_parent, $title, $all_day, $blocks, $start_local, $end_local, $start_utc, $end_utc, $tz);
            _calendar_set_recurrence($new_parent, $rec_type, $rec_interval, $rec_days, $rec_week, $rec_end_date);
            $new_parent->set('cal_reminder_minutes', ($reminder === false) ? $parent->get('cal_reminder_minutes') : $reminder);
            $new_parent->save();

            foreach ($future_exceptions as $ex_date) {
                $new_exc = new CalEntryException(NULL);
                $new_exc->set('cex_cal_entry_id',   $new_parent->key);
                $new_exc->set('cex_exception_date', $ex_date);
                $new_exc->save();
            }
            break;

        case 'all':
        default:
            // Update the parent in place; preserve existing exceptions.
            _calendar_set_fields($parent, $title, $all_day, $blocks, $start_local, $end_local, $start_utc, $end_utc, $tz);
            _calendar_set_recurrence($parent, $rec_type, $rec_interval, $rec_days, $rec_week, $rec_end_date);
            if ($reminder !== false) {
                $parent->set('cal_reminder_minutes', $reminder);
            }
            $parent->save();
            break;
    }
}
