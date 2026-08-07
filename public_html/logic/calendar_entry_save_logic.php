<?php
/**
 * API action: calendar_entry_save — create or update a native calendar entry.
 *
 * POST /api/v1/action/calendar_entry_save (session key). Params:
 *   entry_id         int, optional — update when present, create otherwise
 *   occurrence_date  Y-m-d, optional — present when editing one occurrence of
 *                    a recurring series (entry_id is then the parent)
 *   scope            'this' | 'future' | 'all' — recurring edits only;
 *                    defaults to 'this' for occurrence edits
 *   date             Y-m-d (required)
 *   title            string
 *   all_day          bool
 *   blocks           bool (default true) — blocks booking availability
 *   start_time / end_time  'HH:MM' or 'HH:MM:SS' (required unless all_day)
 *   timezone         IANA id the wall-clock values are in; defaults to the
 *                    profile timezone
 *   reminder_minutes optional reminder override; only applied when present.
 *                    '' = use my default, 0 = no reminder, else 60|30|15|5
 *                    minutes before start
 *   recurrence       null, or { type: daily|weekly|monthly|yearly,
 *                    interval, days_of_week: [0-6] (weekly) or single 0-6
 *                    (monthly by-weekday), week_of_month: 1-4|-1,
 *                    ends: never|date|count, end_date: Y-m-d, count: int }
 *
 * Same write path as the web calendar form — the shared helpers in
 * logic/calendar_logic.php do the field/recurrence writes and the
 * scope-aware series splits.
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function calendar_entry_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
	require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
	require_once(PathHelper::getIncludePath('data/calendar_entry_exception_class.php'));
	require_once(PathHelper::getIncludePath('logic/calendar_logic.php')); // shared _calendar_* helpers

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$subject = CalendarSubject::user($user_id);
	$auth    = ['current_user_id' => $user_id, 'current_user_permission' => $session->get_permission()];

	$eid   = intval($input['entry_id'] ?? 0);
	$date  = trim((string)($input['date'] ?? ''));
	$title = trim((string)($input['title'] ?? ''));
	$all_day = !empty($input['all_day']) && $input['all_day'] !== '0';
	$blocks  = !isset($input['blocks']) || (!empty($input['blocks']) && $input['blocks'] !== '0');
	$scope   = (string)($input['scope'] ?? '');
	$odate   = trim((string)($input['occurrence_date'] ?? ''));

	// Reminder override: only touched when the caller sends the field (the
	// quick-entry popover omits it, which must not clobber a stored choice).
	// '' = use my default (NULL); 0 = no reminder; else minutes before start.
	$reminder = array_key_exists('reminder_minutes', $input)
		? _calendar_parse_reminder($input['reminder_minutes'])
		: false;

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		return LogicResult::error('Enter a valid date.');
	}

	// Wall-clock timezone: the client's declared zone, defaulting to the
	// profile timezone. Validated so convert_time never sees garbage.
	$tz = trim((string)($input['timezone'] ?? ''));
	if ($tz === '') {
		$tz = $session->get_timezone();
	} else {
		try {
			new DateTimeZone($tz);
		} catch (Exception $e) {
			return LogicResult::error('Unknown timezone.');
		}
	}

	$start_t = _calendar_api_normalize_time($input['start_time'] ?? '');
	$end_t   = _calendar_api_normalize_time($input['end_time'] ?? '');

	if ($all_day) {
		$start_local = $date . ' 00:00:00';
		$next        = date('Y-m-d', strtotime($date . ' +1 day'));
		$end_local   = $next . ' 00:00:00';
	} else {
		if ($start_t === '' || $end_t === '' || $end_t <= $start_t) {
			return LogicResult::error('Enter a start and end time (end after start), or mark it all-day.');
		}
		$start_local = $date . ' ' . $start_t;
		$end_local   = $date . ' ' . $end_t;
	}
	$start_utc = LibraryFunctions::convert_time($start_local, $tz, 'UTC', 'Y-m-d H:i:s');
	$end_utc   = LibraryFunctions::convert_time($end_local,   $tz, 'UTC', 'Y-m-d H:i:s');

	// ── Recurrence ──────────────────────────────────────────────────────────
	$rec_type     = null;
	$rec_interval = 1;
	$rec_days     = null;
	$rec_week     = null;
	$rec_end_date = null;

	$rec = (isset($input['recurrence']) && is_array($input['recurrence'])) ? $input['recurrence'] : null;
	if ($rec && in_array(($rec['type'] ?? ''), array('daily', 'weekly', 'monthly', 'yearly'), true)) {
		$rec_type     = $rec['type'];
		$rec_interval = max(1, intval($rec['interval'] ?? 1));

		if ($rec_type === 'weekly') {
			$days = array();
			foreach ((array)($rec['days_of_week'] ?? array()) as $d) {
				$d = intval($d);
				if ($d >= 0 && $d <= 6) {
					$days[] = $d;
				}
			}
			$days = array_values(array_unique($days));
			sort($days);
			$rec_days = $days ? implode(',', $days) : null;
		} elseif ($rec_type === 'monthly' && isset($rec['week_of_month']) && $rec['week_of_month'] !== null && $rec['week_of_month'] !== '') {
			$rec_week = intval($rec['week_of_month']);
			$dow_raw = $rec['days_of_week'] ?? null;
			if (is_array($dow_raw)) {
				$dow_raw = count($dow_raw) ? $dow_raw[0] : null;
			}
			if ($dow_raw !== null && $dow_raw !== '') {
				$rec_days = (string)max(0, min(6, intval($dow_raw)));
			}
		}

		$ends = (string)($rec['ends'] ?? 'never');
		if ($ends === 'date') {
			$d = (string)($rec['end_date'] ?? '');
			$rec_end_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
		} elseif ($ends === 'count') {
			$count = intval($rec['count'] ?? 0);
			if ($count >= 1) {
				$probe = new CalendarEntry(NULL);
				_calendar_set_recurrence($probe, $rec_type, $rec_interval, $rec_days, $rec_week, null);
				$probe->set('cal_start_local', $start_local);
				$rec_end_date = $probe->nth_occurrence_date($date, $count);
			}
		}
	}

	try {
		// An occurrence edit is identified by the occurrence_date; default to
		// the safe 'this occurrence only' when no scope was sent.
		if ($eid && preg_match('/^\d{4}-\d{2}-\d{2}$/', $odate)) {
			$parent = new CalendarEntry($eid, true);
			if ($parent->key && $parent->is_recurring_parent()) {
				$parent->authenticate_write($auth);
				_calendar_save_recurring_scope(
					$parent, ($scope !== '' ? $scope : 'this'), $odate, $title, $all_day, $blocks,
					$start_local, $end_local, $start_utc, $end_utc, $tz,
					$rec_type, $rec_interval, $rec_days, $rec_week, $rec_end_date,
					$subject, $reminder
				);
				return LogicResult::render(array('saved' => true, 'entry_id' => (int)$parent->key));
			}
		}

		$entry = $eid ? new CalendarEntry($eid, true) : new CalendarEntry(NULL);
		if ($eid) {
			if (!$entry->key || $entry->get('cal_delete_time')) {
				return LogicResult::error('Entry not found.');
			}
			$entry->authenticate_write($auth);
		} else {
			$entry->set('cal_subject_type', $subject->type);
			$entry->set('cal_subject_id',   $subject->id);
			$entry->set('cal_type',         'personal');
		}
		_calendar_set_fields($entry, $title, $all_day, $blocks, $start_local, $end_local, $start_utc, $end_utc, $tz);
		_calendar_set_recurrence($entry, $rec_type, $rec_interval, $rec_days, $rec_week, $rec_end_date);
		if ($reminder !== false) {
			$entry->set('cal_reminder_minutes', $reminder);
		}
		$entry->save();

		return LogicResult::render(array('saved' => true, 'entry_id' => (int)$entry->key));
	} catch (SystemAuthenticationError $e) {
		return LogicResult::error('Entry not found.');
	}
}

/** 'HH:MM' or 'HH:MM:SS' → 'HH:MM:SS'; anything else → ''. */
function _calendar_api_normalize_time($raw): string {
	$t = trim((string)$raw);
	if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
		return $t . ':00';
	}
	if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $t)) {
		return $t;
	}
	return '';
}

function calendar_entry_save_logic_descriptor(): array {
	// Inputs are declared but left optional: the logic already validates date,
	// times, and ownership and returns friendly ActionErrors, so boundary
	// validation stays out of the way (no new rejections for existing callers).
	return [
		'description' => 'Create or update a native calendar entry (recurrence and scope aware).',
		'mutates'     => true,
		'auth'        => [
			'requires_session' => true,
		],
		'input'       => [
			'entry_id'        => ['type' => 'int',    'required' => false, 'label' => 'Entry ID (update when present)'],
			'date'            => ['type' => 'string', 'required' => false, 'label' => 'Entry date (Y-m-d)'],
			'title'           => ['type' => 'string', 'required' => false, 'label' => 'Title'],
			'all_day'         => ['type' => 'bool',   'required' => false, 'label' => 'All-day'],
			'blocks'          => ['type' => 'bool',   'required' => false, 'label' => 'Blocks booking availability'],
			'start_time'      => ['type' => 'string', 'required' => false, 'label' => 'Start time (HH:MM)'],
			'end_time'        => ['type' => 'string', 'required' => false, 'label' => 'End time (HH:MM)'],
			'timezone'        => ['type' => 'string', 'required' => false, 'label' => 'IANA timezone of the wall-clock values'],
			'occurrence_date' => ['type' => 'string', 'required' => false, 'label' => 'Occurrence date (recurring edit)'],
			'reminder_minutes'=> ['type' => 'string', 'required' => false, 'label' => 'Reminder override: empty = use my default, 0 = none, else 60|30|15|5 minutes before'],
			'scope'           => ['type' => 'string', 'required' => false, 'enum' => ['this', 'future', 'all'], 'label' => 'Recurring edit scope'],
			// 'recurrence' is deliberately not declared: it is a single object
			// ({type, interval, days_of_week, ...}), and the schema's 'array'
			// type accepts only lists — declaring it rejects every recurring
			// save with a 422. Undeclared fields pass through untouched and
			// the logic validates the rule itself.
		],
	];
}

?>
