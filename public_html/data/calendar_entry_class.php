<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItem.php'));

class CalendarEntryException extends SystemBaseException {}

class CalendarEntry extends SystemBase {
	public static $prefix = 'cal';
	public static $tablename = 'cal_entries';
	public static $pkey_column = 'cal_calendar_entry_id';

	// AI model surface (joinery_ai) — plugins/joinery_ai/docs/overview.md
	public static $ai_readable = true;
	public static $ai_description = 'Native personal calendar entries: appointments and blocked-out time created directly on a subject\'s calendar.';

	public static $field_specifications = array(
		'cal_calendar_entry_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'cal_subject_type' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
		'cal_subject_id' => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true),
		'cal_start_utc' => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'required'=>true),
		'cal_end_utc' => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'required'=>true),
		'cal_start_local' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'cal_end_local' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'cal_timezone' => array('type'=>'varchar(64)', 'is_nullable'=>true),
		'cal_tzdata_version' => array('type'=>'varchar(10)', 'is_nullable'=>true),
		'cal_all_day' => array('type'=>'bool', 'default'=>false),
		'cal_title' => array('type'=>'varchar(255)'),
		'cal_blocks_availability' => array('type'=>'bool', 'default'=>true),
		'cal_visibility' => array('type'=>'varchar(16)', 'default'=>'details'),
		'cal_type' => array('type'=>'varchar(16)', 'default'=>'personal'),
		'cal_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cal_update_time' => array('type'=>'timestamp(6)'),
		'cal_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),

		// Recurrence (null = non-recurring; non-null = this is a recurring parent)
		'cal_recurrence_type'         => array('type' => 'varchar(20)',  'is_nullable' => true),
		// Values: 'daily' | 'weekly' | 'monthly' | 'yearly'
		'cal_recurrence_interval'     => array('type' => 'int4',         'default' => 1),
		'cal_recurrence_days_of_week' => array('type' => 'varchar(20)',  'is_nullable' => true),
		// Weekly only: comma-separated 0=Sun…6=Sat, e.g. "1,3,5"
		'cal_recurrence_week_of_month'=> array('type' => 'int4',         'is_nullable' => true),
		// Monthly by-week: 1=first…4=fourth, -1=last. NULL + monthly = same day-of-month as cal_start_local.
		'cal_recurrence_end_date'     => array('type' => 'date',         'is_nullable' => true),

		// Exception replacement link (set on standalone entries that replace one skipped occurrence)
		'cal_parent_entry_id'         => array('type' => 'int8',         'is_nullable' => true),
		'cal_parent_entry_date'       => array('type' => 'date',         'is_nullable' => true),

		// External calendar interop (iCalendar import / future sync)
		'cal_uid'                     => array('type' => 'varchar(255)', 'is_nullable' => true),
		'cal_rrule_raw'               => array('type' => 'text',         'is_nullable' => true),
		'cal_source'                  => array('type' => 'varchar(50)',  'is_nullable' => true),
		'cal_source_event_id'         => array('type' => 'varchar(255)', 'is_nullable' => true),
	);

	// The owner column (cal_subject_id) is polymorphic, so it does not match the
	// FK auto-detection convention and no generic cascade can be built for it.
	// Owner cleanup is handled subject-aware in CalendarSubject::purge() — see
	// docs/calendar.md and docs/deletion_system.md.
	protected static $foreign_key_actions = array();

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md
	public static $permanent_delete_actions = array();

	// Polymorphic ownership: a native entry is owned by a CalendarSubject, not a
	// real usr_users FK. Only a user-typed subject maps to a user id; reserved
	// subject types (resource/team/venue) have no per-user owner yet and are
	// staff-only. Mirrors Schedule's owner-or-staff scope.
	function authenticate_read($data) {
		if (!$this->user_owns($data['current_user_id'])
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}

	function authenticate_write($data) {
		if (!$this->user_owns($data['current_user_id'])
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/** True when this entry's subject is the given user. */
	private function user_owns($user_id) {
		return $this->get('cal_subject_type') === CalendarSubject::TYPE_USER
			&& (int)$this->get('cal_subject_id') === (int)$user_id;
	}

	/** The CalendarSubject this entry belongs to. */
	function subject() {
		return new CalendarSubject($this->get('cal_subject_type'), $this->get('cal_subject_id'));
	}

	/** True when this entry is a recurring parent. */
	public function is_recurring_parent(): bool {
		return !empty($this->get('cal_recurrence_type'));
	}

	/**
	 * Check whether a specific date (Y-m-d) matches the recurrence pattern.
	 * Uses cal_start_local as the anchor — wall-clock is the source of truth.
	 * Non-existent dates (e.g. "every 31st" in November) return false; no clamping.
	 */
	public function date_matches_pattern(string $date): bool {
		$anchor = $this->_recurrence_anchor();
		return $anchor !== null && $this->_date_matches($date, $anchor);
	}

	/**
	 * Precompute the anchor date and recurrence parameters once, so a day-by-day
	 * walk (compute_dates_in_range) doesn't rebuild DateTimes on every iteration.
	 * Returns null when this entry isn't a usable recurring parent.
	 */
	private function _recurrence_anchor(): ?array {
		if (!$this->is_recurring_parent()) {
			return null;
		}
		// Anchor date from local wall-clock (not UTC), falling back to UTC if local not set.
		$local_start = $this->get('cal_start_local') ?: $this->get('cal_start_utc');
		if (!$local_start) {
			return null;
		}
		$start_date = substr($local_start, 0, 10); // Y-m-d
		$start_dt   = new DateTime($start_date);
		$epoch_mon  = new DateTime('1970-01-05'); // 1970-01-05 is a Monday
		return array(
			'start_date'    => $start_date,
			'start_dt'      => $start_dt,
			'start_dow'     => (int)$start_dt->format('w'),
			'start_day'     => (int)$start_dt->format('j'),
			'start_month'   => (int)$start_dt->format('n'),
			'start_year'    => (int)$start_dt->format('Y'),
			'start_week'    => (int)floor($epoch_mon->diff($start_dt)->days / 7),
			'epoch'         => $epoch_mon,
			'type'          => $this->get('cal_recurrence_type'),
			'interval'      => max(1, (int)$this->get('cal_recurrence_interval')),
			'days_of_week'  => $this->get('cal_recurrence_days_of_week'),
			'week_of_month' => $this->get('cal_recurrence_week_of_month'),
			'end_date'      => $this->get('cal_recurrence_end_date'),
		);
	}

	/** Per-date pattern test against a precomputed anchor (see _recurrence_anchor). */
	private function _date_matches(string $date, array $a): bool {
		if ($date < $a['start_date']) {
			return false;
		}
		if ($a['end_date'] && $date > $a['end_date']) {
			return false;
		}

		$interval = $a['interval'];
		$check_dt = new DateTime($date);

		switch ($a['type']) {
			case 'daily':
				$diff_days = (int)$a['start_dt']->diff($check_dt)->days;
				return ($diff_days % $interval) === 0;

			case 'weekly':
				$check_dow = (int)$check_dt->format('w'); // 0=Sun
				if ($a['days_of_week'] !== null && $a['days_of_week'] !== '') {
					$allowed = array_map('intval', explode(',', $a['days_of_week']));
					if (!in_array($check_dow, $allowed)) {
						return false;
					}
				} elseif ($check_dow !== $a['start_dow']) {
					return false;
				}

				// Week interval: count weeks since the epoch Monday (1970-01-05 = Mon).
				$check_week = (int)floor($a['epoch']->diff($check_dt)->days / 7);
				return (abs($check_week - $a['start_week']) % $interval) === 0;

			case 'monthly':
				$month_diff = ((int)$check_dt->format('Y') - $a['start_year']) * 12
				            + ((int)$check_dt->format('n') - $a['start_month']);
				if ($month_diff < 0 || ($month_diff % $interval) !== 0) {
					return false;
				}

				$week_of_month = $a['week_of_month'];
				if ($week_of_month !== null && $week_of_month !== '') {
					// By weekday: e.g. "2nd Tuesday".
					// DOW from cal_recurrence_days_of_week (single digit), fallback to start date.
					$target_dow = ($a['days_of_week'] !== null && $a['days_of_week'] !== '')
						? (int)$a['days_of_week']
						: $a['start_dow'];
					if ((int)$check_dt->format('w') !== $target_dow) {
						return false;
					}
					$target_wom = (int)$week_of_month;
					if ($target_wom === -1) {
						// Last occurrence of this weekday in the month.
						$next_week = clone $check_dt;
						$next_week->modify('+7 days');
						return $next_week->format('m') !== $check_dt->format('m');
					}
					return $this->_get_week_of_month($check_dt) === $target_wom;
				} else {
					// By day-of-month. Skip months that don't have this day (no clamping).
					$days_in_month = (int)$check_dt->format('t');
					if ($a['start_day'] > $days_in_month) {
						return false; // e.g. "every 31st" in November
					}
					return (int)$check_dt->format('j') === $a['start_day'];
				}

			case 'yearly':
				$year_diff = (int)$check_dt->format('Y') - $a['start_year'];
				if ($year_diff < 0 || ($year_diff % $interval) !== 0) {
					return false;
				}
				if ((int)$check_dt->format('n') !== $a['start_month']) {
					return false;
				}
				$days_in_month = (int)$check_dt->format('t');
				if ($a['start_day'] > $days_in_month) {
					return false; // e.g. Feb 29 in a non-leap year
				}
				return (int)$check_dt->format('j') === $a['start_day'];

			default:
				return false;
		}
	}

	/** Week-of-month ordinal (1 = first, 2 = second, etc.). */
	private function _get_week_of_month(DateTime $dt): int {
		return (int)ceil((int)$dt->format('j') / 7);
	}

	/**
	 * All dates in [start_date, end_date] (Y-m-d, inclusive) that match the pattern.
	 * Walks day-by-day; safe for bounded calendar windows (months/quarters).
	 * @return string[]  Y-m-d
	 */
	public function compute_dates_in_range(string $start_date, string $end_date): array {
		$anchor = $this->_recurrence_anchor();
		if ($anchor === null) {
			return [];
		}
		$dates   = [];
		$current = new DateTime($start_date);
		$end_dt  = new DateTime($end_date);
		$rec_end = $anchor['end_date'];
		$limit   = 3650; // safety cap (~10 years of daily)
		$i       = 0;

		while ($current <= $end_dt && $i++ < $limit) {
			$d = $current->format('Y-m-d');
			// Early exit if past the series end.
			if ($rec_end && $d > $rec_end) {
				break;
			}
			if ($this->_date_matches($d, $anchor)) {
				$dates[] = $d;
			}
			$current->modify('+1 day');
		}
		return $dates;
	}

	/**
	 * Date (Y-m-d) of the Nth matching occurrence, walking forward from
	 * $anchor_date inclusive. Used to convert an "ends after N occurrences"
	 * choice into a stored end date — the recurrence engine is the single
	 * source of truth for which dates the pattern lands on, so the conversion
	 * lives here rather than being reimplemented in the browser.
	 *
	 * The recurrence fields and cal_start_local must already be set on this
	 * entry. Returns null if the entry isn't a recurring parent or $count < 1.
	 *
	 * The walk is bounded so a sparse pattern can't loop unbounded. The bound
	 * allows ~4 years per occurrence (covering the widest realistic gap — a
	 * Feb-29 yearly series only lands on leap years), capped at ~1100 years so
	 * even a max count terminates quickly. The loop early-exits the instant the
	 * Nth match is found, so dense patterns are cheap; if the cap is somehow
	 * reached first (only with nonsensical interval/count combinations), the
	 * last match found is returned.
	 *
	 * @param string $anchor_date Y-m-d to start counting from (the entry's start date)
	 * @param int    $count       1-based occurrence number to find
	 */
	public function nth_occurrence_date(string $anchor_date, int $count): ?string {
		if ($count < 1) {
			return null;
		}
		$anchor = $this->_recurrence_anchor();
		if ($anchor === null) {
			return null;
		}
		$current   = new DateTime($anchor_date);
		$max_days  = min($count * 1500, 400000) + 366; // ~4y/occurrence, capped
		$found     = 0;
		$last      = null;
		for ($i = 0; $i < $max_days; $i++) {
			$d = $current->format('Y-m-d');
			if ($this->_date_matches($d, $anchor)) {
				$last = $d;
				if (++$found >= $count) {
					return $d;
				}
			}
			$current->modify('+1 day');
		}
		return $last;
	}

	/**
	 * Compute CalendarItem instances for the given UTC window.
	 * Loads exceptions from the DB, skips those dates, and builds CalendarItem
	 * value objects with DST-safe times (wall-clock H:i:s + per-instance UTC conversion).
	 *
	 * @param string $start_utc   Y-m-d H:i:s UTC
	 * @param string $end_utc     Y-m-d H:i:s UTC
	 * @param string $visibility  'details' | 'busy'
	 * @param array|null $exceptions  Pre-loaded exception dates keyed by Y-m-d (true).
	 *                                Pass to avoid a per-parent query when a caller
	 *                                expands many parents (see NativeCalendarItemSource).
	 *                                When null, this method loads its own exceptions.
	 * @return CalendarItem[]
	 */
	public function get_instances_for_range(string $start_utc, string $end_utc, string $visibility, ?array $exceptions = null): array {
		if (!$this->is_recurring_parent()) {
			return [];
		}

		$tz = $this->get('cal_timezone') ?: 'UTC';

		// Convert UTC window to local dates for the date-pattern walk.
		$start_date = LibraryFunctions::convert_time($start_utc, 'UTC', $tz, 'Y-m-d');
		$end_date   = LibraryFunctions::convert_time($end_utc,   'UTC', $tz, 'Y-m-d');

		$dates = $this->compute_dates_in_range($start_date, $end_date);
		if (empty($dates)) {
			return [];
		}

		// Exception dates: use the caller-supplied set, or load this parent's own.
		if ($exceptions === null) {
			require_once(PathHelper::getIncludePath('data/calendar_entry_exception_class.php'));
			$exc_rows = new MultiCalEntryException(['cal_entry_id' => $this->key]);
			$exc_rows->load();
			$exceptions = [];
			foreach ($exc_rows as $row) {
				$exceptions[$row->get('cex_exception_date')] = true;
			}
		}

		// Extract local wall-clock times from cal_start_local / cal_end_local.
		$local_start = $this->get('cal_start_local') ?: $this->get('cal_start_utc');
		$local_end   = $this->get('cal_end_local')   ?: $this->get('cal_end_utc');
		$start_time  = $local_start ? substr($local_start, 11) : '00:00:00'; // H:i:s
		$end_time    = $local_end   ? substr($local_end,   11) : '00:00:00';

		// Does the entry span multiple days? Compute the day offset from start to end.
		$local_start_date = $local_start ? substr($local_start, 0, 10) : null;
		$local_end_date   = $local_end   ? substr($local_end,   0, 10) : null;
		$day_offset       = 0;
		if ($local_start_date && $local_end_date && $local_end_date > $local_start_date) {
			$day_offset = (int)(new DateTime($local_start_date))->diff(new DateTime($local_end_date))->days;
		}

		$items = [];
		$event_tz = new DateTimeZone($tz);
		$utc_tz   = new DateTimeZone('UTC');
		$all_day  = (bool)$this->get('cal_all_day');
		$parent_id = $this->key;

		foreach ($dates as $date) {
			if (isset($exceptions[$date])) {
				continue;
			}

			if ($all_day) {
				$inst_start_utc = LibraryFunctions::convert_time($date . ' ' . $start_time, $tz, 'UTC', 'Y-m-d H:i:s');
				$end_date_str   = $day_offset > 0
					? date('Y-m-d', strtotime($date . ' +' . $day_offset . ' days'))
					: $date;
				$inst_end_utc = LibraryFunctions::convert_time($end_date_str . ' ' . $end_time, $tz, 'UTC', 'Y-m-d H:i:s');
			} else {
				// DST-safe: combine instance date with wall-clock H:i:s, convert fresh to UTC.
				$inst_start = new DateTime($date . ' ' . $start_time, $event_tz);
				$inst_start->setTimezone($utc_tz);
				$inst_start_utc = $inst_start->format('Y-m-d H:i:s');

				$end_date_str = $day_offset > 0
					? date('Y-m-d', strtotime($date . ' +' . $day_offset . ' days'))
					: $date;
				$inst_end = new DateTime($end_date_str . ' ' . $end_time, $event_tz);
				$inst_end->setTimezone($utc_tz);
				$inst_end_utc = $inst_end->format('Y-m-d H:i:s');
			}

			// Skip if the instance doesn't actually overlap the requested UTC window.
			if (!($inst_start_utc < $end_utc && $inst_end_utc > $start_utc)) {
				continue;
			}

			$items[] = new CalendarItem([
				'start_utc'           => $inst_start_utc,
				'end_utc'             => $inst_end_utc,
				'all_day'             => $all_day,
				'type'                => $this->get('cal_type') ?: CalendarItem::TYPE_PERSONAL,
				'title'               => $visibility === CalendarItem::VIS_DETAILS ? ($this->get('cal_title') ?: 'Busy') : null,
				'url'                 => $visibility === CalendarItem::VIS_DETAILS
					? '/profile/calendar/entry/' . $parent_id . '/occurrence/' . $date
					: null,
				'blocks_availability' => (bool)$this->get('cal_blocks_availability'),
				'visibility'          => $visibility,
				'source'              => 'native',
				'source_key'          => 'native:cal-' . $parent_id . '-' . $date,
				'entry_id'            => (int)$parent_id,
				'occurrence_date'     => $date,
			]);
		}

		return $items;
	}

	/**
	 * Human-readable recurrence description.
	 * e.g. "Every Monday and Wednesday" or "Every month on the 15th"
	 */
	public function get_recurrence_description(): string {
		if (!$this->is_recurring_parent()) {
			return '';
		}

		$type     = $this->get('cal_recurrence_type');
		$interval = max(1, (int)$this->get('cal_recurrence_interval'));
		$parts    = [];

		$local_start = $this->get('cal_start_local') ?: $this->get('cal_start_utc');

		switch ($type) {
			case 'daily':
				$parts[] = $interval === 1 ? 'Every day' : 'Every ' . $interval . ' days';
				break;

			case 'weekly':
				$prefix = $interval === 1 ? 'Every week' : 'Every ' . $interval . ' weeks';
				$days_of_week = $this->get('cal_recurrence_days_of_week');
				if ($days_of_week !== null && $days_of_week !== '') {
					$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
					$day_nums  = array_map('intval', explode(',', $days_of_week));
					$labels    = array_map(function($d) use ($day_names) { return $day_names[$d]; }, $day_nums);
					if (count($labels) > 1) {
						$last = array_pop($labels);
						$parts[] = $prefix . ' on ' . implode(', ', $labels) . ' and ' . $last;
					} else {
						$parts[] = $prefix . ' on ' . $labels[0];
					}
				} else {
					$dow = $local_start ? date('l', strtotime($local_start)) : '';
					$parts[] = $prefix . ($dow ? ' on ' . $dow : '');
				}
				break;

			case 'monthly':
				$prefix = $interval === 1 ? 'Every month' : 'Every ' . $interval . ' months';
				$week_of_month = $this->get('cal_recurrence_week_of_month');
				if ($week_of_month !== null && $week_of_month !== '') {
					$ordinals = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'last'];
					$ordinal  = $ordinals[(int)$week_of_month] ?? ((int)$week_of_month . 'th');
					$full_days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
					$dow_raw   = $this->get('cal_recurrence_days_of_week');
					$dow       = ($dow_raw !== null && $dow_raw !== '' && isset($full_days[(int)$dow_raw]))
						? $full_days[(int)$dow_raw]
						: ($local_start ? date('l', strtotime($local_start)) : '');
					$parts[]  = $prefix . ' on the ' . $ordinal . ($dow ? ' ' . $dow : '');
				} else {
					$day     = $local_start ? date('jS', strtotime($local_start)) : '';
					$parts[] = $prefix . ($day ? ' on the ' . $day : '');
				}
				break;

			case 'yearly':
				$prefix  = $interval === 1 ? 'Every year' : 'Every ' . $interval . ' years';
				$date_str = $local_start ? date('F jS', strtotime($local_start)) : '';
				$parts[] = $prefix . ($date_str ? ' on ' . $date_str : '');
				break;
		}

		$end_date = $this->get('cal_recurrence_end_date');
		if ($end_date) {
			$parts[] = 'until ' . date('M j, Y', strtotime($end_date));
		}

		return implode(' ', $parts);
	}

	/**
	 * Model test. Runs the generic CRUD/validation pass, then pins the recurrence
	 * date math — it's pure, edge-dense (DST, month lengths, week intervals, leap
	 * years), and silently feeds the booking availability projection, so a wrong
	 * expansion quietly un-blocks time a user meant to protect. Runs in read-only
	 * mode too (no DB writes), so it executes on the deploy-time model test pass.
	 */
	static function test($debug = false, $verbose = false, $read_only = false): bool {
		$ok = parent::test($debug, $verbose, $read_only);

		// Build a transient recurring parent (no save) and assert its expanded dates.
		$check = function($label, array $spec, $start, $from, $to, array $expected) use (&$ok, $verbose) {
			$e = new CalendarEntry(NULL);
			$e->set('cal_recurrence_type',     $spec['type']);
			$e->set('cal_recurrence_interval', $spec['interval'] ?? 1);
			if (isset($spec['days']))  { $e->set('cal_recurrence_days_of_week',  $spec['days']); }
			if (isset($spec['week']))  { $e->set('cal_recurrence_week_of_month', $spec['week']); }
			$e->set('cal_start_local', $start . ' 09:00:00');
			$e->set('cal_start_utc',   $start . ' 09:00:00');
			$got = $e->compute_dates_in_range($from, $to);
			if ($got !== $expected) {
				$ok = false;
				echo 'CalendarEntry recurrence FAIL [' . $label . ']: got '
				   . json_encode($got) . ' expected ' . json_encode($expected) . "<br>\n";
			} elseif ($verbose) {
				echo 'CalendarEntry recurrence PASS [' . $label . "]<br>\n";
			}
		};

		// daily every 2 days
		$check('daily/2', ['type' => 'daily', 'interval' => 2], '2026-06-01', '2026-06-01', '2026-06-07',
			['2026-06-01', '2026-06-03', '2026-06-05', '2026-06-07']);
		// weekly every 2 weeks on Mon+Wed
		$check('weekly/2 Mon,Wed', ['type' => 'weekly', 'interval' => 2, 'days' => '1,3'], '2026-06-01', '2026-06-01', '2026-06-30',
			['2026-06-01', '2026-06-03', '2026-06-15', '2026-06-17', '2026-06-29']);
		// monthly 2nd Tuesday
		$check('monthly 2nd Tue', ['type' => 'monthly', 'days' => '2', 'week' => 2], '2026-06-09', '2026-06-01', '2026-08-31',
			['2026-06-09', '2026-07-14', '2026-08-11']);
		// monthly last Friday
		$check('monthly last Fri', ['type' => 'monthly', 'days' => '5', 'week' => -1], '2026-06-26', '2026-06-01', '2026-07-31',
			['2026-06-26', '2026-07-31']);
		// monthly by day-of-month 31 — months without a 31st are skipped, not clamped
		$check('monthly 31st skip', ['type' => 'monthly'], '2026-01-31', '2026-01-01', '2026-04-30',
			['2026-01-31', '2026-03-31']);
		// yearly on Feb 29 — only leap years produce an occurrence
		$check('yearly Feb29 leap-only', ['type' => 'yearly'], '2024-02-29', '2024-01-01', '2028-12-31',
			['2024-02-29', '2028-02-29']);

		return $ok;
	}
}

class MultiCalendarEntry extends SystemMultiBase {
	protected static $model_class = 'CalendarEntry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['subject_type'])) {
			$filters['cal_subject_type'] = [$this->options['subject_type'], PDO::PARAM_STR];
		}
		if (isset($this->options['subject_id'])) {
			$filters['cal_subject_id'] = [$this->options['subject_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['deleted'])) {
			$filters['cal_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}
		if (!empty($this->options['recurring_only'])) {
			$filters['cal_recurrence_type'] = "IS NOT NULL";
		}
		if (!empty($this->options['non_recurring_only'])) {
			$filters['cal_recurrence_type'] = "IS NULL";
		}
		// Recurring-parent window pre-filter: parent must start before window end.
		// Value must be a UTC timestamp from server code — format validated, never user input.
		if (isset($this->options['start_utc_before'])) {
			$ts = $this->options['start_utc_before'];
			if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2})?$/', $ts)) {
				$filters['cal_start_utc'] = "<= '" . str_replace("'", '', $ts) . "'";
			}
		}
		// Recurring-parent window pre-filter: series must reach window start.
		// Value must be a date from server code — format validated, never user input.
		if (isset($this->options['end_date_null_or_gte'])) {
			$d = $this->options['end_date_null_or_gte'];
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
				$filters['(cal_recurrence_end_date'] = "IS NULL OR cal_recurrence_end_date >= '" . $d . "')";
			}
		}

		return $this->_get_resultsv2('cal_entries', $filters, $this->order_by, $only_count, $debug);
	}
}
?>