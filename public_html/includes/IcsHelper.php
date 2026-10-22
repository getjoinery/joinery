<?php
/**
 * IcsHelper - RFC 5545 iCalendar generation utility
 *
 * Generates .ics files for single event downloads and multi-event calendar feeds.
 *
 * @version 1.4 - buildVevent() writes all-day dates, a zone's wall clock (TZID), RRULE, EXDATE,
 *                RECURRENCE-ID, TRANSP and TENTATIVE; vtimezone() writes the VTIMEZONE a TZID needs.
 * @version 1.3 - foldLines() never folds inside a UTF-8 character; the vCard writer folds with it too.
 * @version 1.2 - buildVevent() is the one VEVENT writer and uid() the one UID rule; generateVevent()
 *                maps an Event onto them, and the Bookings invite does the same. foldLines() is public.
 * @version 1.1 - the calendar name reads site_name, the declared setting. It
 *                read siteName, which nothing declares, so every feed was
 *                titled with the "Events" fallback.
 * @version 1.0
 */

class IcsHelper {

	/**
	 * Generate a VEVENT block from an Event object or virtual stdClass instance.
	 *
	 * @param Event|stdClass $event Event object or virtual instance
	 * @param string|null $instance_date Date string for recurring instances (YYYY-MM-DD)
	 * @return string VEVENT block or empty string if event has no start time
	 */
	public static function generateVevent($event, $instance_date = null) {
		$start_time = self::getField($event, 'evt_start_time');
		if (!$start_time) {
			return '';
		}

		$event_id = self::getField($event, 'evt_event_id');
		// For virtual instances, use parent_event_id if evt_event_id is null
		if (!$event_id && isset($event->parent_event_id)) {
			$event_id = $event->parent_event_id;
		}

		$description = self::getField($event, 'evt_short_description');
		if ($description) {
			// Strip HTML tags and truncate to 500 chars
			$description = strip_tags($description);
			if (mb_strlen($description) > 500) {
				$description = mb_substr($description, 0, 497) . '...';
			}
		}

		return self::buildVevent([
			'uid'         => self::uid('event', $event_id, $instance_date),
			'start_utc'   => $start_time,
			'end_utc'     => self::getField($event, 'evt_end_time'),
			'summary'     => self::getField($event, 'evt_name'),
			'description' => $description,
			'location'    => self::getLocationString($event),
			'url'         => self::getEventUrl($event, $instance_date),
			// 1 (active) and 2 (completed) are CONFIRMED, 3 (canceled) is CANCELLED
			'status'      => self::getField($event, 'evt_status') == 3 ? 'CANCELLED' : 'CONFIRMED',
		]);
	}

	/**
	 * The one VEVENT writer. Every invite, feed and calendar download on the platform
	 * goes through it, so text is escaped and times are written the same way everywhere.
	 *
	 * @param array $f 'uid' and a start are required. The times take one of three forms:
	 *   - 'start_utc' / 'end_utc' (Y-m-d H:i:s, UTC): UTC instants;
	 *   - 'tzid' with 'start_local' / 'end_local' (Y-m-d H:i:s): the zone's wall clock,
	 *     for a thing that keeps its local time (a repeating entry across a clock
	 *     change). The file then carries a VTIMEZONE for the zone (vtimezone());
	 *   - 'all_day' true with 'start_date' / 'end_date' (Y-m-d, the end exclusive): dates.
	 *   Optional: 'summary', 'description', 'location', 'url' (written as is), 'status'
	 *   ('CONFIRMED' by default, 'TENTATIVE' or 'CANCELLED'), 'transparent' (true: the
	 *   time stays free), 'rrule' (written as is), 'exdates' (skipped occurrences, each
	 *   a date or wall clock in the start's form) and 'recurrence_id' (['value',
	 *   'all_day', 'tzid']: the occurrence of a series this one replaces, in the
	 *   series' form). A field that is empty is left out.
	 * @return string one VEVENT block, or '' when the uid or the start is missing
	 */
	public static function buildVevent(array $f) {
		if (!empty($f['all_day'])) {
			$form = array(true, null);
			$start = $f['start_date'] ?? '';
			$end = $f['end_date'] ?? '';
		} elseif (!empty($f['tzid'])) {
			$form = array(false, $f['tzid']);
			$start = $f['start_local'] ?? '';
			$end = $f['end_local'] ?? '';
		} else {
			$form = array(false, null);
			$start = $f['start_utc'] ?? '';
			$end = $f['end_utc'] ?? '';
		}
		if (empty($f['uid']) || empty($start)) {
			return '';
		}
		$lines = [];
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $f['uid'];
		$lines[] = 'DTSTAMP:' . self::formatUtcDateTime(gmdate('Y-m-d H:i:s'));
		$lines[] = self::timeProperty('DTSTART', $start, $form[0], $form[1]);
		if (!empty($end)) {
			$lines[] = self::timeProperty('DTEND', $end, $form[0], $form[1]);
		}
		if (!empty($f['recurrence_id']['value'])) {
			$rid = $f['recurrence_id'];
			$lines[] = self::timeProperty('RECURRENCE-ID', $rid['value'], !empty($rid['all_day']), $rid['tzid'] ?? null);
		}
		if (!empty($f['rrule'])) {
			$lines[] = 'RRULE:' . $f['rrule'];
		}
		foreach ($f['exdates'] ?? [] as $exdate) {
			$lines[] = self::timeProperty('EXDATE', $exdate, $form[0], $form[1]);
		}
		foreach (['summary' => 'SUMMARY', 'description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $name) {
			if (!empty($f[$key])) {
				$lines[] = $name . ':' . self::escapeText($f[$key]);
			}
		}
		if (!empty($f['url'])) {
			$lines[] = 'URL:' . $f['url'];
		}
		$status = $f['status'] ?? 'CONFIRMED';
		$lines[] = 'STATUS:' . (in_array($status, ['TENTATIVE', 'CANCELLED'], true) ? $status : 'CONFIRMED');
		if (!empty($f['transparent'])) {
			$lines[] = 'TRANSP:TRANSPARENT';
		}
		$lines[] = 'END:VEVENT';
		return implode("\r\n", $lines);
	}

	/**
	 * One date or time property: NAME;VALUE=DATE:20261012 for a date,
	 * NAME;TZID=zone:20261012T090000 for a zone's wall clock, else NAME:20261012T130000Z.
	 */
	private static function timeProperty($name, $value, $is_date, $tzid) {
		$value = (string)$value;
		if ($is_date) {
			return $name . ';VALUE=DATE:' . str_replace('-', '', substr($value, 0, 10));
		}
		if ($tzid) {
			return $name . ';TZID=' . $tzid . ':' . str_replace(['-', ':', ' '], ['', '', 'T'], substr($value, 0, 19));
		}
		return $name . ':' . self::formatUtcDateTime($value);
	}

	/**
	 * The VTIMEZONE a file carries for each TZID it names (RFC 5545 3.6.5). A calendar
	 * program that knows the zone by name uses its own copy; one that does not
	 * (Outlook) reads this.
	 *
	 * Written the way Google, Apple and Outlook write theirs: while the zone has changed
	 * its clocks by the same rule every year (the second Sunday of March at 2:00), one
	 * DAYLIGHT and one STANDARD part repeat yearly from the first year the rule held.
	 * A rule is taken only when it holds for 28 years running, every way the weekdays
	 * fall on the dates, so Israel's (the Friday before the last Sunday of March) is
	 * never mistaken for the fourth Friday. Changes before the rule, and those of a zone
	 * that follows no such rule, are listed one by one, at least 30 years ahead. A zone
	 * that does not change its clocks has one part.
	 *
	 * @param string $tzid an IANA zone
	 * @param int $from the earliest instant the file needs (Unix time)
	 * @param int $to the latest instant it needs
	 */
	public static function vtimezone($tzid, $from, $to) {
		$begin = gmmktime(0, 0, 0, 1, 1, (int)gmdate('Y', $from) - 1);
		$end = max($to, gmmktime(0, 0, 0, 1, 1, (int)gmdate('Y') + 30));
		$transitions = (new DateTimeZone($tzid))->getTransitions($begin, $end);
		$state = array_shift($transitions);   // where the zone stands at $begin, not a change
		$changes = [];
		$now = ['offset' => $state['offset'], 'dst' => (bool)$state['isdst'], 'abbr' => $state['abbr']];
		foreach ($transitions as $t) {
			$next = ['offset' => $t['offset'], 'dst' => (bool)$t['isdst'], 'abbr' => $t['abbr']];
			// PHP reports a change that changes nothing at 2038-01-19, where 32-bit time ends.
			if ($next !== $now) {
				$changes[] = ['ts' => $t['ts'], 'from' => $now['offset'], 'to' => $next['offset'], 'dst' => $next['dst'], 'abbr' => $next['abbr']];
				$now = $next;
			}
		}

		// The yearly rule: walk back from the last change while every change matches the
		// last one of its kind (month, weekday, time, offsets, name), a year apart, and on
		// a week of the month (2nd, or last) they all share.
		$rules = [];
		$tail = count($changes);
		for ($i = count($changes) - 1; $i >= 0; $i--) {
			$c = $changes[$i];
			$local = $c['ts'] + $c['from'];
			$day = (int)gmdate('j', $local);
			$weeks = $day <= 28 ? [(int)ceil($day / 7)] : [];
			if ($day > (int)gmdate('t', $local) - 7) {
				$weeks[] = -1;
			}
			$key = gmdate('n|w|His', $local) . '|' . $c['from'] . '|' . $c['to'] . '|' . $c['abbr'];
			$year = (int)gmdate('Y', $local);
			$kind = (int)$c['dst'];
			if (isset($rules[$kind])) {
				$weeks = array_values(array_intersect($rules[$kind]['weeks'], $weeks));
				if ($rules[$kind]['key'] !== $key || $rules[$kind]['year'] !== $year + 1 || !$weeks) {
					break;
				}
			} elseif (count($rules) === 1 && abs(reset($rules)['year'] - $year) > 1) {
				break;
			}
			$rules[$kind] = ['key' => $key, 'year' => $year, 'weeks' => $weeks, 'first' => $c];
			$tail = $i;
		}
		if (count($rules) < 2 || count($changes) - $tail < 56) {
			$rules = [];
			$tail = count($changes);
		}

		$lines = ['BEGIN:VTIMEZONE', 'TZID:' . $tzid];
		if ($tail > 0 || !$rules) {
			$lines = array_merge($lines, self::observance((bool)$state['isdst'], $begin, $state['offset'], $state['offset'], $state['abbr']));
		}
		foreach (array_slice($changes, 0, $tail) as $c) {
			$lines = array_merge($lines, self::observance($c['dst'], $c['ts'], $c['from'], $c['to'], $c['abbr']));
		}
		$days = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
		foreach ([1, 0] as $kind) {
			if (!isset($rules[$kind])) {
				continue;
			}
			$c = $rules[$kind]['first'];
			$local = $c['ts'] + $c['from'];
			$rrule = 'FREQ=YEARLY;BYMONTH=' . gmdate('n', $local) . ';BYDAY=' . $rules[$kind]['weeks'][0] . $days[(int)gmdate('w', $local)];
			$lines = array_merge($lines, self::observance($c['dst'], $c['ts'], $c['from'], $c['to'], $c['abbr'], $rrule));
		}
		$lines[] = 'END:VTIMEZONE';
		return implode("\r\n", $lines);
	}

	/** One STANDARD or DAYLIGHT part: from $ts (Unix time) the zone's offset is $to. */
	private static function observance($dst, $ts, $from, $to, $abbr, $rrule = null) {
		$kind = $dst ? 'DAYLIGHT' : 'STANDARD';
		$lines = [
			'BEGIN:' . $kind,
			'DTSTART:' . gmdate('Ymd\THis', $ts + $from),
			'TZOFFSETFROM:' . self::utcOffset($from),
			'TZOFFSETTO:' . self::utcOffset($to),
		];
		if ($abbr !== '') {
			$lines[] = 'TZNAME:' . self::escapeText($abbr);
		}
		if ($rrule !== null) {
			$lines[] = 'RRULE:' . $rrule;
		}
		$lines[] = 'END:' . $kind;
		return $lines;
	}

	/** A UTC offset in seconds as +HHMM, or +HHMMSS when it has seconds. */
	private static function utcOffset($seconds) {
		$sign = $seconds < 0 ? '-' : '+';
		$seconds = abs($seconds);
		$out = sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
		return $seconds % 60 ? $out . sprintf('%02d', $seconds % 60) : $out;
	}

	/**
	 * The UID of a thing on a calendar: {kind}-{id}[-{instance}]@{site host}. The same
	 * thing always gets the same UID, and the kind and id can be read back from it, which
	 * is how a copy of it on another calendar is recognised. An instance is a date or
	 * other tag; its dashes are dropped (2026-10-12 is written 20261012).
	 *
	 * @param string $kind 'event', 'booking', ...
	 * @param int|string $id the record's primary key
	 * @param string|null $instance for one occurrence of a repeating thing
	 */
	public static function uid($kind, $id, $instance = null) {
		$settings = Globalvars::get_instance();
		$domain = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'];
		$domain = preg_replace('#^https?://#', '', $domain);
		$domain = rtrim($domain, '/');

		$uid = $kind . '-' . $id;
		if ($instance) {
			$uid .= '-' . str_replace('-', '', $instance);
		}
		return $uid . '@' . $domain;
	}

	/**
	 * Wrap VEVENT(s) in a VCALENDAR envelope.
	 *
	 * @param string $vevents_string One or more VEVENT blocks
	 * @param bool $include_calname Whether to include X-WR-CALNAME (for feeds)
	 * @return string Complete VCALENDAR string
	 */
	public static function wrapInVcalendar($vevents_string, $include_calname = false) {
		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Joinery//Events//EN';
		$lines[] = 'CALSCALE:GREGORIAN';

		if ($include_calname) {
			$settings = Globalvars::get_instance();
			$site_name = $settings->get_setting('site_name') ?: 'Events';
			$lines[] = 'X-WR-CALNAME:' . self::escapeText($site_name);
		}

		$header = implode("\r\n", $lines);
		$footer = 'END:VCALENDAR';

		if (!empty(trim($vevents_string))) {
			$result = $header . "\r\n" . $vevents_string . "\r\n" . $footer;
		} else {
			$result = $header . "\r\n" . $footer;
		}

		return $result;
	}

	/**
	 * Output iCal content with proper headers and exit.
	 *
	 * @param string $ics_string Complete iCal content
	 * @param string $filename Filename for Content-Disposition
	 * @param bool $inline If true, use inline disposition (for feeds); if false, use attachment (for downloads)
	 */
	public static function outputIcs($ics_string, $filename, $inline = false) {
		// Fold long lines before output
		$ics_string = self::foldLines($ics_string);

		header('Content-Type: text/calendar; charset=utf-8');
		$disposition = $inline ? 'inline' : 'attachment';
		header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');

		if ($inline) {
			header('Cache-Control: public, max-age=300');
		} else {
			header('Cache-Control: no-cache, no-store, must-revalidate');
		}

		echo $ics_string;
		exit();
	}

	/**
	 * Escape text per RFC 5545: backslashes, semicolons, commas, newlines.
	 *
	 * @param string $text Input text
	 * @return string Escaped text
	 */
	private static function escapeText($text) {
		if ($text === null) return '';
		$text = str_replace('\\', '\\\\', $text);
		$text = str_replace(';', '\\;', $text);
		$text = str_replace(',', '\\,', $text);
		$text = str_replace("\r\n", '\\n', $text);
		$text = str_replace("\n", '\\n', $text);
		$text = str_replace("\r", '\\n', $text);
		return $text;
	}

	/**
	 * Convert a DB timestamp (Y-m-d H:i:s) to iCal UTC format (YYYYMMDDTHHMMSSZ).
	 *
	 * @param string $datetime_string DB timestamp string
	 * @return string iCal formatted UTC datetime
	 */
	private static function formatUtcDateTime($datetime_string) {
		$dt = new DateTime($datetime_string, new DateTimeZone('UTC'));
		return $dt->format('Ymd\THis\Z');
	}

	/**
	 * Build a location string from the event's location fields.
	 *
	 * 1. Start with evt_location text (if set)
	 * 2. If evt_loc_location_id is set, load Location and append loc_address (if different)
	 * 3. Combine with comma separator
	 *
	 * @param Event|stdClass $event
	 * @return string Location string or empty
	 */
	private static function getLocationString($event) {
		$parts = [];

		$evt_location = self::getField($event, 'evt_location');
		if ($evt_location) {
			$parts[] = trim($evt_location);
		}

		$location_id = self::getField($event, 'evt_loc_location_id');
		// Location enrichment depends on event_manager (which owns locations).
		// Degrade to no-location output when the plugin is absent.
		if ($location_id && !class_exists('Location')) {
			if (PluginHelper::isPluginActive('event_manager')) {
				require_once(PathHelper::getThemeFilePath('locations_class.php', 'data', 'system', null, 'event_manager', false));
			} elseif (file_exists(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'))) {
				require_once(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'));
			}
		}
		if ($location_id && class_exists('Location')) {
			if (Location::check_if_exists($location_id)) {
				$location = new Location($location_id, TRUE);
				$loc_address = $location->get('loc_address');
				if ($loc_address && $loc_address !== $evt_location) {
					$parts[] = trim($loc_address);
				}
			}
		}

		return implode(', ', $parts);
	}

	/**
	 * Unified field accessor for Event objects and virtual stdClass instances.
	 *
	 * @param Event|stdClass $obj
	 * @param string $field Field name
	 * @return mixed Field value or null
	 */
	private static function getField($obj, $field) {
		if ($obj instanceof SystemBase) {
			return $obj->get($field);
		}
		return isset($obj->$field) ? $obj->$field : null;
	}

	/**
	 * Build the absolute URL for an event page.
	 *
	 * @param Event|stdClass $event
	 * @param string|null $instance_date Date for recurring instances
	 * @return string Absolute URL
	 */
	private static function getEventUrl($event, $instance_date = null) {
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

		if ($event instanceof SystemBase) {
			$path = $event->get_url();
			if ($instance_date) {
				$path .= '/' . $instance_date;
			}
			return LibraryFunctions::get_absolute_url($path);
		}

		// Virtual stdClass instance
		$link = isset($event->evt_link) ? $event->evt_link : null;
		if ($link) {
			$path = '/event/' . $link;
			$date = $instance_date ?: (isset($event->instance_date) ? $event->instance_date : null);
			if ($date) {
				$path .= '/' . $date;
			}
			return LibraryFunctions::get_absolute_url($path);
		}

		return '';
	}

	/**
	 * Fold lines longer than 75 octets per RFC 5545 §3.1 (the same rule as vCard,
	 * RFC 2425 §5.8.1). Lines are folded with CRLF followed by a single space.
	 *
	 * A fold never lands inside a UTF-8 character: a line that would start on a
	 * continuation byte (10xxxxxx) is cut a byte or three earlier instead, so every
	 * physical line is valid UTF-8 on its own.
	 *
	 * @param string $ics_string Raw iCal string
	 * @return string Folded iCal string
	 */
	public static function foldLines($ics_string) {
		$lines = explode("\r\n", $ics_string);
		$folded = [];

		foreach ($lines as $line) {
			$prefix = '';
			$room = 75;
			while (strlen($line) > $room) {
				$cut = $room;
				while ($cut > $room - 3 && (ord($line[$cut]) & 0xC0) === 0x80) {
					$cut--;
				}
				$folded[] = $prefix . substr($line, 0, $cut);
				$line = substr($line, $cut);
				// A continuation line starts with a space, so 74 octets of content.
				$prefix = ' ';
				$room = 74;
			}
			$folded[] = $prefix . $line;
		}

		return implode("\r\n", $folded);
	}
}
