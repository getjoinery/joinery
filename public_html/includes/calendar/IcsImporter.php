<?php
/**
 * IcsImporter — RFC 5545 iCalendar reader + importer.
 *
 * The read-side companion to IcsHelper (which writes .ics). Three stages, kept as
 * separate public static methods so each is independently testable and a future
 * sync feature can reuse the parser, but in one file because they are one
 * operation at this scale:
 *
 *   parse()              raw .ics text  → structured events (pure; no DB)
 *   translateRecurrence() parsed RRULE  → native cal_recurrence_* fields, or null
 *   import()             parsed events  → saved CalendarEntry rows + a summary
 *
 * Import is one-directional, manual, owner-scoped: each VEVENT becomes a native
 * cal_entries row owned by the given CalendarSubject. See docs/calendar.md.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_exception_class.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));

class IcsImporter {

	/** Hard cap on events processed from a single file (excess reported, never silent). */
	const MAX_EVENTS = 5000;

	/** iCalendar weekday code → native day-of-week digit (0=Sun…6=Sat). */
	private static $DAY = ['SU'=>0, 'MO'=>1, 'TU'=>2, 'WE'=>3, 'TH'=>4, 'FR'=>5, 'SA'=>6];

	// =========================================================================
	// Stage 1 — parse (pure format reader)
	// =========================================================================

	/**
	 * Parse raw .ics text into a structured array.
	 *
	 * @return array ['calendar' => [name => value], 'events' => [ event, ... ]]
	 *   Each event: ['props' => [NAME => ['value'=>, 'params'=>]], 'exdates' => [ ['value'=>,'params'=>], ... ]]
	 */
	public static function parse(string $ics_text): array {
		// Strip a leading UTF-8 BOM.
		if (substr($ics_text, 0, 3) === "\xEF\xBB\xBF") {
			$ics_text = substr($ics_text, 3);
		}
		// Unfold continuation lines (CRLF or LF followed by space/tab). Inverse of
		// IcsHelper::foldLines().
		$ics_text = preg_replace("/\r\n[ \t]/", '', $ics_text);
		$ics_text = preg_replace("/\n[ \t]/", '', $ics_text);
		// Normalize remaining line endings.
		$ics_text = str_replace("\r\n", "\n", $ics_text);
		$ics_text = str_replace("\r", "\n", $ics_text);
		$lines = explode("\n", $ics_text);

		$calendar   = [];
		$events     = [];
		$cur        = null; // current VEVENT being assembled
		$nested     = 0;    // depth of a component nested inside the VEVENT (e.g. VALARM)
		$skip_depth = 0;    // depth of a top-level non-VEVENT component (e.g. VTIMEZONE)

		foreach ($lines as $line) {
			if ($line === '') { continue; }
			$p = self::_parseContentLine($line);
			if ($p === null) { continue; }
			$name = $p['name'];
			$val  = $p['value'];

			if ($name === 'BEGIN') {
				$comp = strtoupper($val);
				if ($cur !== null) {
					$nested++; // a component inside the event (VALARM)
				} elseif ($comp === 'VEVENT') {
					$cur = ['props' => [], 'exdates' => []];
					$nested = 0;
				} else {
					$skip_depth++; // VTIMEZONE / STANDARD / DAYLIGHT etc.
				}
				continue;
			}
			if ($name === 'END') {
				$comp = strtoupper($val);
				if ($cur !== null && $nested > 0) {
					$nested--;
				} elseif ($cur !== null && $comp === 'VEVENT') {
					$events[] = $cur;
					$cur = null;
				} elseif ($cur === null && $skip_depth > 0) {
					$skip_depth--;
				}
				continue;
			}

			if ($cur !== null && $nested === 0) {
				if ($name === 'EXDATE') {
					$cur['exdates'][] = ['value' => $val, 'params' => $p['params']];
				} else {
					$value = in_array($name, ['SUMMARY', 'DESCRIPTION', 'LOCATION', 'COMMENT'], true)
						? self::unescapeText($val)
						: $val;
					$cur['props'][$name] = ['value' => $value, 'params' => $p['params']];
				}
			} elseif ($cur === null && $skip_depth === 0) {
				$calendar[$name] = $val;
			}
		}

		return ['calendar' => $calendar, 'events' => $events];
	}

	/** Unescape an RFC 5545 TEXT value. Inverse of IcsHelper::escapeText(). */
	public static function unescapeText($t) {
		if ($t === null) { return ''; }
		$out = '';
		$len = strlen($t);
		for ($i = 0; $i < $len; $i++) {
			$c = $t[$i];
			if ($c === '\\' && $i + 1 < $len) {
				$n = $t[$i + 1];
				if ($n === 'n' || $n === 'N') { $out .= "\n"; $i++; }
				elseif ($n === ',' || $n === ';' || $n === '\\') { $out .= $n; $i++; }
				else { $out .= $c; }
			} else {
				$out .= $c;
			}
		}
		return $out;
	}

	/** Split `NAME;PARAM=v;PARAM="q:v":VALUE` into name, params map, value. */
	private static function _parseContentLine($line) {
		$len = strlen($line);
		$inq = false;
		$colon = -1;
		for ($i = 0; $i < $len; $i++) {
			$c = $line[$i];
			if ($c === '"') { $inq = !$inq; }
			elseif ($c === ':' && !$inq) { $colon = $i; break; }
		}
		if ($colon === -1) { return null; }

		$namepart = substr($line, 0, $colon);
		$value    = substr($line, $colon + 1);

		$segs = self::_splitUnquoted($namepart, ';');
		$name = strtoupper(trim(array_shift($segs)));
		if ($name === '') { return null; }

		$params = [];
		foreach ($segs as $seg) {
			$eq = strpos($seg, '=');
			if ($eq === false) { continue; }
			$params[strtoupper(substr($seg, 0, $eq))] = trim(substr($seg, $eq + 1), '"');
		}
		return ['name' => $name, 'params' => $params, 'value' => $value];
	}

	/** Split on $delim, ignoring delimiters inside double quotes. */
	private static function _splitUnquoted($str, $delim) {
		$parts = [];
		$cur = '';
		$inq = false;
		$len = strlen($str);
		for ($i = 0; $i < $len; $i++) {
			$c = $str[$i];
			if ($c === '"') { $inq = !$inq; $cur .= $c; continue; }
			if ($c === $delim && !$inq) { $parts[] = $cur; $cur = ''; continue; }
			$cur .= $c;
		}
		$parts[] = $cur;
		return $parts;
	}

	// =========================================================================
	// Stage 2 — translate recurrence (pure)
	// =========================================================================

	/**
	 * Translate a parsed RRULE into native recurrence fields, or null when the
	 * rule is not expressible in the native model. This is the single source of
	 * truth for "is this rule natively expressible?"
	 *
	 * @param array  $rrule       e.g. ['FREQ'=>'WEEKLY','BYDAY'=>'MO,WE','INTERVAL'=>'2']
	 * @param string $start_local Event local start 'Y-m-d H:i:s' (anchor for by-day / COUNT)
	 * @return array|null ['type','interval','days_of_week','week_of_month','end_date']
	 */
	public static function translateRecurrence(array $rrule, string $start_local): ?array {
		$freq = isset($rrule['FREQ']) ? strtoupper($rrule['FREQ']) : '';
		$type_map = ['DAILY'=>'daily', 'WEEKLY'=>'weekly', 'MONTHLY'=>'monthly', 'YEARLY'=>'yearly'];
		if (!isset($type_map[$freq])) { return null; }
		$type = $type_map[$freq];
		$interval = isset($rrule['INTERVAL']) ? max(1, (int)$rrule['INTERVAL']) : 1;

		// Parts the native model cannot express at all.
		foreach (['BYSETPOS', 'BYWEEKNO', 'BYYEARDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND'] as $u) {
			if (isset($rrule[$u]) && $rrule[$u] !== '') { return null; }
		}

		$start_date  = substr($start_local, 0, 10);
		$start_dt    = new DateTime($start_date);
		$start_day   = (int)$start_dt->format('j');
		$start_month = (int)$start_dt->format('n');

		$days = null;  // weekly: comma DOW list; monthly-by-weekday: single DOW digit
		$week = null;  // monthly-by-weekday ordinal (1-4, -1)

		$has = function ($k) use ($rrule) { return isset($rrule[$k]) && $rrule[$k] !== ''; };

		switch ($type) {
			case 'daily':
				if ($has('BYDAY') || $has('BYMONTHDAY')) { return null; }
				break;

			case 'weekly':
				if ($has('BYDAY')) {
					$nums = [];
					foreach (explode(',', $rrule['BYDAY']) as $t) {
						if (!preg_match('/^([+-]?\d+)?(SU|MO|TU|WE|TH|FR|SA)$/', trim($t), $m)) { return null; }
						if (isset($m[1]) && $m[1] !== '') { return null; } // ordinal invalid for weekly
						$nums[] = self::$DAY[$m[2]];
					}
					sort($nums);
					$days = implode(',', array_values(array_unique($nums)));
				}
				break;

			case 'monthly':
				if ($has('BYDAY') && $has('BYMONTHDAY')) { return null; }
				if ($has('BYDAY')) {
					$toks = explode(',', $rrule['BYDAY']);
					if (count($toks) !== 1) { return null; }
					if (!preg_match('/^([+-]?\d+)(SU|MO|TU|WE|TH|FR|SA)$/', trim($toks[0]), $m)) { return null; }
					$ord = (int)$m[1];
					if (!in_array($ord, [1, 2, 3, 4, -1], true)) { return null; }
					$week = $ord;
					$days = (string)self::$DAY[$m[2]];
				} elseif ($has('BYMONTHDAY')) {
					$md = explode(',', $rrule['BYMONTHDAY']);
					// Native monthly-by-day uses the start date's day; only an exact match fits.
					if (count($md) !== 1 || (int)$md[0] !== $start_day) { return null; }
				}
				if ($has('BYMONTH')) { return null; }
				break;

			case 'yearly':
				if ($has('BYDAY')) { return null; }
				if ($has('BYMONTHDAY')) {
					$md = explode(',', $rrule['BYMONTHDAY']);
					if (count($md) !== 1 || (int)$md[0] !== $start_day) { return null; }
				}
				if ($has('BYMONTH')) {
					$bm = explode(',', $rrule['BYMONTH']);
					if (count($bm) !== 1 || (int)$bm[0] !== $start_month) { return null; }
				}
				break;
		}

		// End condition: UNTIL → end date; COUNT → walk to the Nth occurrence.
		$end_date = null;
		if ($has('UNTIL')) {
			$u = self::_parseDt($rrule['UNTIL']);
			if ($u !== null) { $end_date = $u['date']; }
		} elseif ($has('COUNT')) {
			$count = (int)$rrule['COUNT'];
			if ($count >= 1) {
				$probe = new CalendarEntry(NULL);
				$probe->set('cal_recurrence_type',          $type);
				$probe->set('cal_recurrence_interval',      $interval);
				$probe->set('cal_recurrence_days_of_week',  $days);
				$probe->set('cal_recurrence_week_of_month', $week);
				$probe->set('cal_start_local',              $start_local);
				$end_date = $probe->nth_occurrence_date($start_date, $count);
			}
		}

		return [
			'type'          => $type,
			'interval'      => $interval,
			'days_of_week'  => $days,
			'week_of_month' => $week,
			'end_date'      => $end_date,
		];
	}

	// =========================================================================
	// Stage 3 — import (persist + summarize)
	// =========================================================================

	/**
	 * Persist parsed events as native entries owned by $subject.
	 * Best-effort per event: one bad VEVENT is recorded and skipped.
	 *
	 * @return array summary: created, skipped_duplicate, imported_as_single,
	 *               warnings[], failed[], capped
	 */
	public static function import(array $parsed, CalendarSubject $subject, string $tz): array {
		$summary = [
			'created'            => 0,
			'skipped_duplicate'  => 0,
			'imported_as_single' => 0,
			'warnings'           => [],
			'failed'             => [],
			'capped'             => 0,
		];

		$events = $parsed['events'] ?? [];
		if (count($events) > self::MAX_EVENTS) {
			$summary['capped'] = count($events) - self::MAX_EVENTS;
			$events = array_slice($events, 0, self::MAX_EVENTS);
		}

		$existing     = self::_existingUids($subject); // UIDs already imported for this subject
		$created_uids = [];                            // uid => saved parent (for override matching)

		// Separate modified-occurrence overrides (RECURRENCE-ID) from normal events.
		$normal = [];
		$overrides = [];
		foreach ($events as $ev) {
			if (isset($ev['props']['RECURRENCE-ID'])) { $overrides[] = $ev; }
			else { $normal[] = $ev; }
		}

		// --- Pass A: normal events (series parents and one-off entries) ---
		foreach ($normal as $ev) {
			$uid = isset($ev['props']['UID']) ? $ev['props']['UID']['value'] : null;
			try {
				if ($uid !== null && (isset($existing[$uid]) || isset($created_uids[$uid]))) {
					$summary['skipped_duplicate']++;
					continue;
				}
				$entry = self::_buildEntry($ev, $subject, $tz, $summary, false);
				if ($entry === null) { continue; }
				$entry->save();
				$summary['created']++;
				if ($uid !== null) { $created_uids[$uid] = $entry; }
				if ($entry->is_recurring_parent() && !empty($ev['exdates'])) {
					self::_applyExdates($entry, $ev['exdates']);
				}
			} catch (Throwable $e) {
				$summary['failed'][] = ['uid' => $uid ?? '(none)', 'reason' => $e->getMessage()];
			}
		}

		// --- Pass B: modified occurrences (RECURRENCE-ID) ---
		foreach ($overrides as $ev) {
			$uid = isset($ev['props']['UID']) ? $ev['props']['UID']['value'] : null;
			try {
				$parent = ($uid !== null && isset($created_uids[$uid])) ? $created_uids[$uid] : null;

				// Parent already imported in a prior run → skip the override too.
				if ($parent === null && $uid !== null && isset($existing[$uid])) {
					$summary['skipped_duplicate']++;
					continue;
				}

				$rep = self::_buildEntry($ev, $subject, $tz, $summary, true); // skip recurrence
				if ($rep === null) { continue; }

				$rid    = $ev['props']['RECURRENCE-ID'];
				$rid_dt = self::_parseDt($rid['value']);

				if ($parent !== null && $parent->is_recurring_parent() && $rid_dt !== null) {
					$exc = new CalEntryException(NULL);
					$exc->set('cex_cal_entry_id',   $parent->key);
					$exc->set('cex_exception_date', $rid_dt['date']);
					$exc->save();
					$rep->set('cal_parent_entry_id',   $parent->key);
					$rep->set('cal_parent_entry_date', $rid_dt['date']);
				} else {
					$summary['warnings'][] = 'An edited occurrence had no matching series in the file; it was imported as a standalone entry.';
				}
				$rep->save();
				$summary['created']++;
			} catch (Throwable $e) {
				$summary['failed'][] = ['uid' => $uid ?? '(none)', 'reason' => $e->getMessage()];
			}
		}

		return $summary;
	}

	/**
	 * Build an unsaved CalendarEntry from a parsed VEVENT, or null on failure
	 * (recording the reason into $summary['failed']).
	 */
	private static function _buildEntry($ev, CalendarSubject $subject, $tz, array &$summary, $skip_recurrence) {
		$props = $ev['props'];
		$uid   = isset($props['UID']) ? $props['UID']['value'] : '(none)';

		if (!isset($props['DTSTART'])) {
			$summary['failed'][] = ['uid' => $uid, 'reason' => 'Event has no start time (DTSTART).'];
			return null;
		}
		$map = self::_mapTimes($props, $tz);
		if ($map === null) {
			$summary['failed'][] = ['uid' => $uid, 'reason' => 'Could not read the event start/end time.'];
			return null;
		}
		if (!empty($map['warning'])) { $summary['warnings'][] = $map['warning']; }

		$entry = new CalendarEntry(NULL);
		$entry->set('cal_subject_type', $subject->type);
		$entry->set('cal_subject_id',   $subject->id);
		$entry->set('cal_type',         'personal');
		$entry->set('cal_visibility',   'details');

		$title = isset($props['SUMMARY']) ? trim($props['SUMMARY']['value']) : '';
		if ($title === '') { $title = '(no title)'; }
		$entry->set('cal_title', mb_substr($title, 0, 255));

		$entry->set('cal_all_day',        $map['all_day']);
		$entry->set('cal_start_utc',      $map['start_utc']);
		$entry->set('cal_end_utc',        $map['end_utc']);
		$entry->set('cal_start_local',    $map['start_local']);
		$entry->set('cal_end_local',      $map['end_local']);
		$entry->set('cal_timezone',       $map['timezone']);
		$entry->set('cal_tzdata_version', '2026a');

		// TRANSP: OPAQUE (or absent) blocks availability; TRANSPARENT does not.
		$transp = isset($props['TRANSP']) ? strtoupper(trim($props['TRANSP']['value'])) : 'OPAQUE';
		$entry->set('cal_blocks_availability', $transp !== 'TRANSPARENT');

		if (isset($props['UID'])) {
			$entry->set('cal_uid',             mb_substr($props['UID']['value'], 0, 255));
			$entry->set('cal_source_event_id', mb_substr($props['UID']['value'], 0, 255));
		}
		$entry->set('cal_source', 'ical_import');

		if (!$skip_recurrence && isset($props['RRULE'])) {
			$raw = $props['RRULE']['value'];
			$entry->set('cal_rrule_raw', $raw); // always preserved
			$native = self::translateRecurrence(self::_parseRruleString($raw), $map['start_local']);
			if ($native !== null) {
				$entry->set('cal_recurrence_type',          $native['type']);
				$entry->set('cal_recurrence_interval',      $native['interval']);
				$entry->set('cal_recurrence_days_of_week',  $native['days_of_week']);
				$entry->set('cal_recurrence_week_of_month', $native['week_of_month']);
				$entry->set('cal_recurrence_end_date',      $native['end_date']);
			} else {
				// Not natively expressible: kept as a single entry, raw rule retained.
				$summary['imported_as_single']++;
			}
		}

		return $entry;
	}

	/**
	 * Resolve DTSTART / DTEND / DURATION to UTC instants and local wall-clock.
	 * @return array|null ['all_day','start_local','end_local','start_utc','end_utc','timezone','warning']
	 */
	private static function _mapTimes($props, $user_tz) {
		$ds    = $props['DTSTART'];
		$start = self::_parseDt($ds['value']);
		if ($start === null) { return null; }

		$all_day = $start['is_date']
			|| (!empty($ds['params']['VALUE']) && strtoupper($ds['params']['VALUE']) === 'DATE');
		$warning = null;

		if ($all_day) {
			$tz_store    = $user_tz;
			$start_local = $start['date'] . ' 00:00:00';
			$start_utc   = LibraryFunctions::convert_time($start_local, $user_tz, 'UTC', 'Y-m-d H:i:s');
		} elseif ($start['is_utc']) {
			$tz_store    = $user_tz;
			$start_utc   = $start['date'] . ' ' . $start['time'];
			$start_local = LibraryFunctions::convert_time($start_utc, 'UTC', $user_tz, 'Y-m-d H:i:s');
		} else {
			list($tz_store, $warning) = self::_resolveTz($ds, $user_tz);
			$start_local = $start['date'] . ' ' . $start['time'];
			$start_utc   = LibraryFunctions::convert_time($start_local, $tz_store, 'UTC', 'Y-m-d H:i:s');
		}

		// End time: DTEND, else DURATION, else a sensible default.
		$end_local = null;
		$end_utc   = null;

		if (isset($props['DTEND'])) {
			$de  = $props['DTEND'];
			$end = self::_parseDt($de['value']);
			if ($end !== null) {
				$end_all_day = $end['is_date']
					|| (!empty($de['params']['VALUE']) && strtoupper($de['params']['VALUE']) === 'DATE');
				if ($end_all_day) {
					$end_local = $end['date'] . ' 00:00:00';
					$end_utc   = LibraryFunctions::convert_time($end_local, $user_tz, 'UTC', 'Y-m-d H:i:s');
				} elseif ($end['is_utc']) {
					$end_utc   = $end['date'] . ' ' . $end['time'];
					$end_local = LibraryFunctions::convert_time($end_utc, 'UTC', $tz_store, 'Y-m-d H:i:s');
				} else {
					list($etz) = self::_resolveTz($de, $user_tz);
					$end_local = $end['date'] . ' ' . $end['time'];
					$end_utc   = LibraryFunctions::convert_time($end_local, $etz, 'UTC', 'Y-m-d H:i:s');
				}
			}
		}

		if ($end_utc === null && isset($props['DURATION'])) {
			$secs = self::_durationToSeconds($props['DURATION']['value']);
			if ($secs !== null) {
				$end_utc   = LibraryFunctions::time_shift($start_utc, $secs . ' seconds', 'Y-m-d H:i:s');
				$end_local = LibraryFunctions::convert_time($end_utc, 'UTC', $tz_store, 'Y-m-d H:i:s');
			}
		}

		if ($end_utc === null) {
			if ($all_day) {
				$next      = date('Y-m-d', strtotime($start['date'] . ' +1 day'));
				$end_local = $next . ' 00:00:00';
				$end_utc   = LibraryFunctions::convert_time($end_local, $user_tz, 'UTC', 'Y-m-d H:i:s');
			} else {
				$end_local = $start_local;
				$end_utc   = $start_utc;
			}
		}

		return [
			'all_day'     => $all_day,
			'start_local' => $start_local,
			'end_local'   => $end_local,
			'start_utc'   => $start_utc,
			'end_utc'     => $end_utc,
			'timezone'    => $tz_store,
			'warning'     => $warning,
		];
	}

	/** Resolve a date-time property's timezone (TZID param), falling back to the user's tz. */
	private static function _resolveTz($prop, $user_tz) {
		if (!empty($prop['params']['TZID'])) {
			$tzid = $prop['params']['TZID'];
			if (self::_validTz($tzid)) { return [$tzid, null]; }
			return [$user_tz, 'A time zone in the file ("' . $tzid . '") was not recognized; those times were read in your time zone (' . $user_tz . ').'];
		}
		return [$user_tz, null]; // floating time
	}

	/** Parse an iCal DATE or DATE-TIME value (basic format). */
	private static function _parseDt($value) {
		$value  = trim($value);
		$is_utc = false;
		if ($value !== '' && substr($value, -1) === 'Z') {
			$is_utc = true;
			$value  = substr($value, 0, -1);
		}
		if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
			return ['date' => "$m[1]-$m[2]-$m[3]", 'time' => '00:00:00', 'is_utc' => $is_utc, 'is_date' => true];
		}
		if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
			return ['date' => "$m[1]-$m[2]-$m[3]", 'time' => "$m[4]:$m[5]:$m[6]", 'is_utc' => $is_utc, 'is_date' => false];
		}
		return null;
	}

	/** ISO 8601 duration → seconds (signed), or null if unparseable. */
	private static function _durationToSeconds($d) {
		if (!preg_match('/^([+-]?)P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($d), $m)) {
			return null;
		}
		$sign = ($m[1] === '-') ? -1 : 1;
		$secs = ((int)($m[2] ?? 0) * 7 + (int)($m[3] ?? 0)) * 86400
		      + (int)($m[4] ?? 0) * 3600
		      + (int)($m[5] ?? 0) * 60
		      + (int)($m[6] ?? 0);
		return $sign * $secs;
	}

	/** Parse an RRULE value string into an uppercase-keyed assoc. */
	private static function _parseRruleString($val) {
		$out = [];
		foreach (explode(';', $val) as $pair) {
			if ($pair === '') { continue; }
			$eq = strpos($pair, '=');
			if ($eq === false) { continue; }
			$out[strtoupper(substr($pair, 0, $eq))] = substr($pair, $eq + 1);
		}
		return $out;
	}

	/** True if $tz is a recognized IANA timezone. */
	private static function _validTz($tz) {
		static $list = null;
		if ($list === null) { $list = timezone_identifiers_list(); }
		return in_array($tz, $list, true);
	}

	/** Existing imported UIDs for a subject (skip-duplicate set). */
	private static function _existingUids(CalendarSubject $subject) {
		$set  = [];
		$rows = new MultiCalendarEntry([
			'subject_type' => $subject->type,
			'subject_id'   => $subject->id,
			'deleted'      => false,
		]);
		$rows->load();
		foreach ($rows as $r) {
			if ($r->get('cal_source') === 'ical_import') {
				$sid = $r->get('cal_source_event_id');
				if ($sid !== null && $sid !== '') { $set[$sid] = true; }
			}
		}
		return $set;
	}

	/** Create exception rows for a recurring parent's EXDATE values. */
	private static function _applyExdates(CalendarEntry $entry, $exdates) {
		foreach ($exdates as $ex) {
			foreach (explode(',', $ex['value']) as $v) {
				$dt = self::_parseDt(trim($v));
				if ($dt === null) { continue; }
				$exc = new CalEntryException(NULL);
				$exc->set('cex_cal_entry_id',   $entry->key);
				$exc->set('cex_exception_date', $dt['date']);
				$exc->save();
			}
		}
	}
}
