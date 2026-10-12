<?php
/**
 * RecurrenceRule - which dates a repeating thing lands on.
 *
 * One copy of the arithmetic for every repeating thing on the platform: a calendar
 * entry (CalendarEntry), an event series (Event), and whatever a plugin adds. It is
 * a plain value class: built from the five recurrence values and the date the series
 * starts, it reads no database and knows no column prefix. Each model builds one
 * from its own columns and keeps its own public methods, which delegate here.
 *
 * Dates are Y-m-d in the series' own wall-clock calendar (the zone it was written
 * in), never UTC: a series that starts at 9pm on a Tuesday repeats on Tuesdays.
 *
 * The values:
 *   type          'daily' | 'weekly' | 'monthly' | 'yearly'
 *   interval      every N of those, at least 1
 *   days_of_week  weekly: 'N,N,...' (0 = Sunday); monthly by weekday: one digit; else null
 *   week_of_month monthly by weekday: 1 to 4, or -1 for the last; null for monthly by day
 *   end_date      the last date a match is allowed, or null
 *
 * A day that does not exist in a month (the 31st in November, February 29 in a common
 * year) is the one place the two users differ on purpose, so the caller says which:
 * MONTH_END_SKIP matches nothing that month (a personal calendar entry: Google
 * Calendar does the same), MONTH_END_CLAMP matches the last day of the month (an event
 * series: the series does not lose a month, and people already registered for the
 * 28th are not surprised).
 *
 * @version 1.0
 */

class RecurrenceRule {

	const MONTH_END_SKIP  = 'skip';
	const MONTH_END_CLAMP = 'clamp';

	/** The most days a range walk looks at (about ten years of daily matches). */
	const RANGE_WALK_LIMIT = 3650;

	private $type;
	private $interval;
	private $days_of_week;
	private $week_of_month;
	private $end_date;
	private $month_end;

	private $start_date;
	private $start_dt;
	private $start_dow;
	private $start_day;
	private $start_month;
	private $start_year;
	private $start_week;
	private $epoch;

	/**
	 * @param string|null     $type          the recurrence type; empty means the thing does not repeat
	 * @param int|string|null $interval
	 * @param string|null     $days_of_week
	 * @param int|string|null $week_of_month
	 * @param string|null     $end_date      Y-m-d
	 * @param string          $start_date    Y-m-d (a longer timestamp is cut to its date)
	 * @param string          $month_end     MONTH_END_SKIP or MONTH_END_CLAMP
	 * @return RecurrenceRule|null null when there is no type or no usable start date
	 */
	public static function fromArray($type, $interval, $days_of_week, $week_of_month, $end_date, $start_date, $month_end = self::MONTH_END_SKIP) {
		$start = substr((string)$start_date, 0, 10);
		if ((string)$type === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
			return null;
		}
		return new self((string)$type, $interval, $days_of_week, $week_of_month, $end_date, $start, $month_end);
	}

	private function __construct($type, $interval, $days_of_week, $week_of_month, $end_date, $start_date, $month_end) {
		$this->type          = $type;
		$this->interval      = max(1, (int)$interval);
		$this->days_of_week  = $days_of_week;
		$this->week_of_month = $week_of_month;
		$this->end_date      = $end_date ?: null;
		$this->month_end     = $month_end === self::MONTH_END_CLAMP ? self::MONTH_END_CLAMP : self::MONTH_END_SKIP;

		$this->start_date  = $start_date;
		$this->start_dt    = new DateTime($start_date);
		$this->epoch       = new DateTime('1970-01-05'); // a Monday
		$this->start_dow   = (int)$this->start_dt->format('w');
		$this->start_day   = (int)$this->start_dt->format('j');
		$this->start_month = (int)$this->start_dt->format('n');
		$this->start_year  = (int)$this->start_dt->format('Y');
		$this->start_week  = (int)floor($this->epoch->diff($this->start_dt)->days / 7);
	}

	public function startDate() {
		return $this->start_date;
	}

	/**
	 * Does this date (Y-m-d) fall on the rule? The start date itself matches when
	 * the pattern lands on it.
	 */
	public function matches($date) {
		if ($date < $this->start_date) {
			return false;
		}
		if ($this->end_date && $date > $this->end_date) {
			return false;
		}

		$interval = $this->interval;
		$check_dt = new DateTime($date);

		switch ($this->type) {
			case 'daily':
				$diff_days = (int)$this->start_dt->diff($check_dt)->days;
				return ($diff_days % $interval) === 0;

			case 'weekly':
				$check_dow = (int)$check_dt->format('w'); // 0 = Sunday
				if ($this->days_of_week !== null && $this->days_of_week !== '') {
					$allowed = array_map('intval', explode(',', $this->days_of_week));
					if (!in_array($check_dow, $allowed)) {
						return false;
					}
				} elseif ($check_dow !== $this->start_dow) {
					return false;
				}

				// Week interval: count weeks since the epoch Monday (1970-01-05).
				$check_week = (int)floor($this->epoch->diff($check_dt)->days / 7);
				return (abs($check_week - $this->start_week) % $interval) === 0;

			case 'monthly':
				$month_diff = ((int)$check_dt->format('Y') - $this->start_year) * 12
				            + ((int)$check_dt->format('n') - $this->start_month);
				if ($month_diff < 0 || ($month_diff % $interval) !== 0) {
					return false;
				}

				if ($this->week_of_month !== null && $this->week_of_month !== '') {
					// By weekday: "the 2nd Tuesday". The weekday is the one digit in
					// days_of_week, else the weekday the series starts on.
					$target_dow = ($this->days_of_week !== null && $this->days_of_week !== '')
						? (int)$this->days_of_week
						: $this->start_dow;
					if ((int)$check_dt->format('w') !== $target_dow) {
						return false;
					}
					$target_wom = (int)$this->week_of_month;
					if ($target_wom === -1) {
						// The last of this weekday in the month.
						$next_week = clone $check_dt;
						$next_week->modify('+7 days');
						return $next_week->format('m') !== $check_dt->format('m');
					}
					return self::weekOfMonth($check_dt) === $target_wom;
				}
				return $this->dayOfMonthMatches($check_dt);

			case 'yearly':
				$year_diff = (int)$check_dt->format('Y') - $this->start_year;
				if ($year_diff < 0 || ($year_diff % $interval) !== 0) {
					return false;
				}
				if ((int)$check_dt->format('n') !== $this->start_month) {
					return false;
				}
				return $this->dayOfMonthMatches($check_dt);

			default:
				return false;
		}
	}

	/** The start day in this month, or none, or the last day of a short month, by month_end. */
	private function dayOfMonthMatches(DateTime $check_dt) {
		$days_in_month = (int)$check_dt->format('t');
		if ($this->start_day > $days_in_month) {
			return $this->month_end === self::MONTH_END_CLAMP
				&& (int)$check_dt->format('j') === $days_in_month;
		}
		return (int)$check_dt->format('j') === $this->start_day;
	}

	/** Week-of-month ordinal (1 = first, 2 = second, ...). */
	private static function weekOfMonth(DateTime $dt) {
		return (int)ceil((int)$dt->format('j') / 7);
	}

	/**
	 * All dates in [$from, $to] (Y-m-d, inclusive) on the rule. Walks a day at a
	 * time, so it is for a bounded window (a month, a quarter), and stops after
	 * RANGE_WALK_LIMIT days.
	 *
	 * @return string[] Y-m-d
	 */
	public function datesInRange($from, $to) {
		$dates   = [];
		$current = new DateTime($from);
		$end_dt  = new DateTime($to);
		$i       = 0;

		while ($current <= $end_dt && $i++ < self::RANGE_WALK_LIMIT) {
			$d = $current->format('Y-m-d');
			if ($this->end_date && $d > $this->end_date) {
				break;
			}
			if ($this->matches($d)) {
				$dates[] = $d;
			}
			$current->modify('+1 day');
		}
		return $dates;
	}

	/**
	 * The date (Y-m-d) of the Nth match, counting from $from inclusive (the start
	 * date when omitted). This is how "ends after N times" becomes a stored end date:
	 * the rule is the one authority on which dates it lands on.
	 *
	 * The walk is bounded: about four years per occurrence (the widest realistic gap,
	 * a February 29 yearly series only lands on leap years), capped near 1100 years. It
	 * stops the moment the Nth match is found, so a dense rule is cheap; if the cap is
	 * reached first (only for a nonsensical interval and count) the last match found is
	 * returned.
	 *
	 * @param int         $count 1-based
	 * @param string|null $from  Y-m-d
	 * @return string|null null when $count < 1 or nothing matched
	 */
	public function nthOccurrence($count, $from = null) {
		if ($count < 1) {
			return null;
		}
		$current  = new DateTime($from ?: $this->start_date);
		$max_days = min($count * 1500, 400000) + 366;
		$found    = 0;
		$last     = null;
		for ($i = 0; $i < $max_days; $i++) {
			$d = $current->format('Y-m-d');
			if ($this->matches($d)) {
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
	 * The next $count dates on the rule, from $from (Y-m-d) or the start date,
	 * whichever is later. A series with an end date stops there.
	 *
	 * @return string[] Y-m-d
	 */
	public function nextDates($from, $count) {
		if ($count <= 0) {
			return [];
		}
		$dates   = [];
		$current = new DateTime(max($from, $this->start_date));

		// Every type but yearly is walked a day at a time, so the budget has to cover
		// the longest stride a pattern can ask for: a monthly series with an interval
		// of 6 leaves about 186 days between occurrences. A budget that is too small
		// raises nothing; it returns a short list, which reads as "the series ended".
		$max_iterations = ($this->type === 'yearly')
			? ($count * $this->interval + 10)
			: ($count * 31 * $this->interval + 366);
		$iterations = 0;

		while (count($dates) < $count && $iterations < $max_iterations) {
			$iterations++;
			$date_str = $current->format('Y-m-d');
			if ($this->end_date && $date_str > $this->end_date) {
				break;
			}
			if ($this->matches($date_str)) {
				$dates[] = $date_str;
			}

			// A yearly rule only ever matches in its start month, so step straight to the
			// next anniversary: a day at a time would spend a year of iterations per
			// match, and a fixed jump of months drifts out of the start month.
			if ($this->type === 'yearly') {
				$year = (int)$current->format('Y');
				$candidate = self::anniversaryIn($year, $this->start_month, $this->start_day);
				if ($candidate <= $current->format('Y-m-d')) {
					$candidate = self::anniversaryIn($year + 1, $this->start_month, $this->start_day);
				}
				$current = new DateTime($candidate);
			} else {
				$current->modify('+1 day');
			}
		}
		return $dates;
	}

	/** The anniversary in a year, held to the length of the month (Feb 29 is Feb 28 in a common year). */
	private static function anniversaryIn($year, $month, $day) {
		$days_in_month = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
		return sprintf('%04d-%02d-%02d', $year, $month, min($day, $days_in_month));
	}

	/**
	 * Plain words for the rule: "Every Monday and Wednesday", "Every month on the
	 * 2nd Tuesday until Dec 31, 2026".
	 */
	public function describe() {
		$interval = $this->interval;
		$parts    = [];
		$start    = $this->start_date;

		switch ($this->type) {
			case 'daily':
				$parts[] = $interval === 1 ? 'Every day' : 'Every ' . $interval . ' days';
				break;

			case 'weekly':
				$prefix = $interval === 1 ? 'Every week' : 'Every ' . $interval . ' weeks';
				if ($this->days_of_week !== null && $this->days_of_week !== '') {
					$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
					$labels    = array_map(function ($d) use ($day_names) { return $day_names[$d]; },
						array_map('intval', explode(',', $this->days_of_week)));
					if (count($labels) > 1) {
						$last = array_pop($labels);
						$parts[] = $prefix . ' on ' . implode(', ', $labels) . ' and ' . $last;
					} else {
						$parts[] = $prefix . ' on ' . $labels[0];
					}
				} else {
					$parts[] = $prefix . ' on ' . date('l', strtotime($start));
				}
				break;

			case 'monthly':
				$prefix = $interval === 1 ? 'Every month' : 'Every ' . $interval . ' months';
				if ($this->week_of_month !== null && $this->week_of_month !== '') {
					$ordinals  = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'last'];
					$ordinal   = $ordinals[(int)$this->week_of_month] ?? ((int)$this->week_of_month . 'th');
					$full_days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
					$dow       = ($this->days_of_week !== null && $this->days_of_week !== '' && isset($full_days[(int)$this->days_of_week]))
						? $full_days[(int)$this->days_of_week]
						: date('l', strtotime($start));
					$parts[]   = $prefix . ' on the ' . $ordinal . ' ' . $dow;
				} else {
					$parts[] = $prefix . ' on the ' . date('jS', strtotime($start));
				}
				break;

			case 'yearly':
				$prefix  = $interval === 1 ? 'Every year' : 'Every ' . $interval . ' years';
				$parts[] = $prefix . ' on ' . date('F jS', strtotime($start));
				break;
		}

		if ($this->end_date) {
			$parts[] = 'until ' . date('M j, Y', strtotime($this->end_date));
		}
		return implode(' ', $parts);
	}
}
