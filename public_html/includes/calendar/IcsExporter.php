<?php
/**
 * IcsExporter — a member's own calendar as one .ics file, the write-side mirror of
 * IcsImporter. It is what "Download my calendar" on /profile/calendar hands over
 * (the calendar_export action): a one-time file, not a feed.
 *
 * Every live native entry the subject owns goes in as it stands: title, notes,
 * location and link; its times in its own zone (an all-day entry as dates); busy or
 * free; tentative, confirmed or cancelled; a repeating entry's rule and skipped
 * dates; an entry imported with a rule the calendar cannot repeat, its rule as it
 * came in; and an occurrence edited on its own as an override of its series
 * (RECURRENCE-ID). The file reads back through IcsImporter as the same entries.
 * Events and bookings have their own .ics and are not in it. See docs/calendar.md
 * § Downloading the calendar.
 *
 * A Private calendar's content is read in the owner's window. With it closed the
 * file is refused as locked, never written with placeholders in it.
 *
 * @version 1.0
 */

class IcsExporter {

	/**
	 * The subject's calendar as an .ics file.
	 *
	 * @return array{ics: string, count: int, locked: bool} count is the entries written;
	 *   locked is true (and ics empty) when a sealed entry could not be read
	 */
	public static function build(CalendarSubject $subject): array {
		$entries = array();
		foreach (new MultiCalendarEntry(array('subject_type' => $subject->type, 'subject_id' => $subject->id, 'deleted' => false)) as $e) {
			$entries[(int)$e->key] = $e;
		}
		// Oldest first: a series claims its UID before a copy of it imported later can.
		ksort($entries);

		$content = array();
		foreach ($entries as $id => $e) {
			$content[$id] = $e->content();
			if ($content[$id]['locked']) {
				return array('ics' => '', 'count' => 0, 'locked' => true);
			}
		}

		// The skipped dates of every series, in one query.
		$skipped = array();
		$series = array_keys(array_filter($entries, function ($e) { return $e->is_recurring_parent(); }));
		if ($series) {
			foreach (new MultiCalEntryException(array('cal_entry_ids' => $series)) as $x) {
				$skipped[(int)$x->get('cex_cal_entry_id')][] = substr((string)$x->get('cex_exception_date'), 0, 10);
			}
		}

		// An occurrence edited on its own replaces that date of its series. One override
		// per date; a second one for the same date goes in as an entry of its own.
		$overrides = array();   // entry id => [series id, date]
		$replaced = array();    // series id => [date => true]
		foreach ($entries as $id => $e) {
			$pid = (int)$e->get('cal_parent_entry_id');
			$date = substr((string)$e->get('cal_parent_entry_date'), 0, 10);
			if ($e->is_recurring_parent() || $date === '' || !isset($entries[$pid])
					|| !$entries[$pid]->is_recurring_parent() || isset($replaced[$pid][$date])) {
				continue;
			}
			$overrides[$id] = array($pid, $date);
			$replaced[$pid][$date] = true;
		}

		// UIDs. An entry keeps the UID it was imported with, so the calendar it came from
		// still recognises it; any other entry, or a second one with the same UID, is
		// cal-{id}@{site}, which IcsImporter takes as already present on a re-import.
		$uids = array();
		$taken = array();
		foreach ($entries as $id => $e) {
			if (isset($overrides[$id])) {
				continue;
			}
			$uid = (string)$e->get('cal_uid');
			if ($uid === '' || isset($taken[$uid])) {
				$uid = IcsHelper::uid('cal', $id);
			}
			$uids[$id] = $uid;
			$taken[$uid] = true;
		}

		$vevents = array();
		$zones = array();   // zone => [earliest, latest] Unix time the file needs it for
		foreach ($entries as $id => $e) {
			$times = self::times($e);
			$f = $times + array(
				'uid'         => isset($overrides[$id]) ? $uids[$overrides[$id][0]] : $uids[$id],
				'summary'     => $content[$id]['title'],
				'description' => $content[$id]['notes'],
				'location'    => $content[$id]['location'],
				'url'         => $content[$id]['link'],
				'status'      => strtoupper((string)($e->get('cal_status') ?: 'confirmed')),
				'transparent' => !$e->get('cal_blocks_availability'),
			);

			$start_local = $times['start_local'] ?? ($times['start_date'] . ' 00:00:00');
			$tz = $times['tzid'] ?? null;
			if ($e->is_recurring_parent()) {
				$f['rrule'] = IcsImporter::nativeToRrule(array(
					'type'          => $e->get('cal_recurrence_type'),
					'interval'      => $e->get('cal_recurrence_interval'),
					'days_of_week'  => $e->get('cal_recurrence_days_of_week'),
					'week_of_month' => $e->get('cal_recurrence_week_of_month'),
					'end_date'      => $e->get('cal_recurrence_end_date'),
				), $start_local, $tz);
				// A date with an override is that override's, not skipped.
				$f['exdates'] = array();
				foreach (array_unique($skipped[$id] ?? array()) as $date) {
					if (!isset($replaced[$id][$date])) {
						$f['exdates'][] = $tz === null ? $date : $date . substr($start_local, 10);
					}
				}
				sort($f['exdates']);
			} elseif (!isset($overrides[$id]) && trim((string)$e->get('cal_rrule_raw')) !== '') {
				// Imported with a rule the calendar cannot repeat (the first and third Monday),
				// untouched since: the file gives it back, so another calendar repeats it as the
				// one it came from did. Changing the repeat drops it (_calendar_set_recurrence()).
				$f['rrule'] = trim((string)$e->get('cal_rrule_raw'));
			}

			if (isset($overrides[$id])) {
				// The occurrence it replaces, in its series' form.
				$s = self::times($entries[$overrides[$id][0]]);
				$f['recurrence_id'] = isset($s['tzid'])
					? array('value' => $overrides[$id][1] . substr($s['start_local'], 10), 'tzid' => $s['tzid'])
					: array('value' => $overrides[$id][1], 'all_day' => true);
			}

			if ($tz !== null) {
				// An open-ended series needs nothing more: vtimezone() always writes 30 years ahead.
				$from = strtotime($e->get('cal_start_utc') . ' UTC');
				$to = strtotime($e->get('cal_end_utc') . ' UTC');
				if ($e->is_recurring_parent() && $e->get('cal_recurrence_end_date')) {
					$to = max($to, strtotime(substr((string)$e->get('cal_recurrence_end_date'), 0, 10) . ' 23:59:59 UTC') + 86400);
				}
				$zones[$tz] = isset($zones[$tz]) ? array(min($zones[$tz][0], $from), max($zones[$tz][1], $to)) : array($from, $to);
			}

			$vevents[] = IcsHelper::buildVevent($f);
		}

		$parts = array();
		foreach ($zones as $tz => $span) {
			$parts[] = IcsHelper::vtimezone($tz, $span[0], $span[1]);
		}
		$ics = IcsHelper::foldLines(IcsHelper::wrapInVcalendar(implode("\r\n", array_merge($parts, $vevents)))) . "\r\n";
		return array('ics' => $ics, 'count' => count($entries), 'locked' => false);
	}

	/**
	 * An entry's times in a form buildVevent() writes. All-day: dates, the end exclusive.
	 * Timed: the wall clock in the entry's own zone, so a repeating entry keeps its local
	 * time across a clock change. The UTC instant is the truth for a timed entry: a row
	 * with a zone and no wall clock has its instant read in the zone, and a row with no
	 * zone, or one PHP does not know, is written in UTC.
	 */
	private static function times(CalendarEntry $e): array {
		$tz = (string)$e->get('cal_timezone');
		$known = $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
		$all_day = (bool)$e->get('cal_all_day');
		$clock = function ($local_column, $utc_column) use ($e, $tz, $known, $all_day) {
			$stored = (string)$e->get($local_column);
			if ($stored !== '' && ($known || $all_day)) {
				return $stored;
			}
			$utc = (string)$e->get($utc_column);
			return $known ? LibraryFunctions::convert_time($utc, 'UTC', $tz, 'Y-m-d H:i:s') : $utc;
		};
		$start_local = $clock('cal_start_local', 'cal_start_utc');
		$end_local = $clock('cal_end_local', 'cal_end_utc');
		if ($all_day) {
			$start = substr($start_local, 0, 10);
			$end = substr($end_local, 0, 10);
			// An end at midnight is the exclusive end date; any other time ends the day after.
			if (substr($end_local, 11, 8) !== '00:00:00' || $end <= $start) {
				$end = date('Y-m-d', strtotime(max($start, $end) . ' +1 day'));
			}
			return array('all_day' => true, 'start_date' => $start, 'end_date' => $end);
		}
		return array('tzid' => $known ? $tz : 'UTC', 'start_local' => substr($start_local, 0, 19), 'end_local' => substr($end_local, 0, 19));
	}
}
?>
