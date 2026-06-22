<?php

/**
 * Availability editor logic — the subject's single schedule: weekly windows,
 * date overrides, and the timezone the windows are defined in.
 *
 * One schedule per subject. Saving rewrites the whole window/override set from
 * the posted rows (soft-deleting the old set), which keeps the editor stateless
 * and avoids per-row id bookkeeping in the form.
 */
function availability_logic(array $input): LogicResult {

    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
    require_once(PathHelper::getIncludePath('data/schedule_class.php'));
    require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
    require_once(PathHelper::getIncludePath('data/schedule_override_class.php'));

    $settings = Globalvars::get_instance();
    if (!$settings->get_setting('bookings_active')) {
        return LogicResult::redirect('/profile');
    }

    $session = SessionControl::get_instance();
    $session->check_permission(0);
    $session->set_return();

    $page_vars = array();
    $page_vars['settings'] = $settings;
    $page_vars['session'] = $session;

    $subject = CalendarSubject::user($session->get_user_id());
    $schedule = Schedule::get_or_create_for_subject($subject);
    $page_vars['schedule'] = $schedule;
    $page_vars['errors'] = array();

    if (isset($_POST['save_availability'])) {
        // --- timezone ---
        $timezone = LibraryFunctions::fetch_variable_local($input, 'sch_timezone', $schedule->get('sch_timezone'), '', '', 'safemode', NULL);
        if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $schedule->set('sch_timezone', $timezone);
            $schedule->save();
        }

        // --- weekly windows: rewrite the whole set ---
        $days   = isset($input['win_day'])   && is_array($input['win_day'])   ? $input['win_day']   : array();
        $starts = isset($input['win_start']) && is_array($input['win_start']) ? $input['win_start'] : array();
        $ends   = isset($input['win_end'])   && is_array($input['win_end'])   ? $input['win_end']   : array();

        $existing = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
        $existing->load();
        foreach ($existing as $row) {
            $row->soft_delete();
        }
        foreach ($days as $i => $dow) {
            $start = isset($starts[$i]) ? trim($starts[$i]) : '';
            $end   = isset($ends[$i]) ? trim($ends[$i]) : '';
            if ($dow === '' || $start === '' || $end === '') {
                continue;
            }
            if ($end <= $start) {
                $page_vars['errors'][] = "A window's end time must be after its start time.";
                continue;
            }
            $win = new ScheduleWindow(NULL);
            $win->set('scw_sch_schedule_id', $schedule->key);
            $win->set('scw_day_of_week', (int)$dow);
            $win->set('scw_start_time', $start);
            $win->set('scw_end_time', $end);
            $win->save();
        }

        // --- date overrides: rewrite the whole set ---
        $o_dates  = isset($input['ovr_date'])        && is_array($input['ovr_date'])        ? $input['ovr_date']        : array();
        $o_unavail= isset($input['ovr_unavailable']) && is_array($input['ovr_unavailable']) ? $input['ovr_unavailable'] : array();
        $o_starts = isset($input['ovr_start'])       && is_array($input['ovr_start'])       ? $input['ovr_start']       : array();
        $o_ends   = isset($input['ovr_end'])         && is_array($input['ovr_end'])         ? $input['ovr_end']         : array();

        $existing_o = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
        $existing_o->load();
        foreach ($existing_o as $row) {
            $row->soft_delete();
        }
        foreach ($o_dates as $i => $date) {
            $date = trim($date);
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $unavailable = !empty($o_unavail[$i]);
            $ov = new ScheduleOverride(NULL);
            $ov->set('sco_sch_schedule_id', $schedule->key);
            $ov->set('sco_date', $date);
            if ($unavailable) {
                $ov->set('sco_start_time', NULL);
                $ov->set('sco_end_time', NULL);
            } else {
                $s = isset($o_starts[$i]) ? trim($o_starts[$i]) : '';
                $e = isset($o_ends[$i]) ? trim($o_ends[$i]) : '';
                if ($s === '' || $e === '' || $e <= $s) {
                    $page_vars['errors'][] = "Override $date needs a valid start/end time, or mark it unavailable.";
                    continue;
                }
                $ov->set('sco_start_time', $s);
                $ov->set('sco_end_time', $e);
            }
            $ov->save();
        }

        if (empty($page_vars['errors'])) {
            return LogicResult::redirect('/profile/bookings/availability?saved=1');
        }
    }

    // --- load current state for display ---
    $windows = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
    $windows->order_by = ['scw_day_of_week' => 'ASC', 'scw_start_time' => 'ASC'];
    $windows->load();
    $page_vars['windows'] = $windows;

    $overrides = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
    $overrides->order_by = ['sco_date' => 'ASC'];
    $overrides->load();
    $page_vars['overrides'] = $overrides;

    $page_vars['saved'] = !empty($input['saved']);

    return LogicResult::render($page_vars);
}
