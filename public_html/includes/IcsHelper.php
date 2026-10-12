<?php
/**
 * IcsHelper - RFC 5545 iCalendar generation utility
 *
 * Generates .ics files for single event downloads and multi-event calendar feeds.
 *
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
	 * The one VEVENT writer. Every invite and feed on the platform goes through it,
	 * so text is escaped and times are written the same way everywhere.
	 *
	 * @param array $f 'uid' and 'start_utc' (Y-m-d H:i:s, UTC) are required. Optional:
	 *                 'end_utc', 'summary', 'description', 'location', 'url' (written as is),
	 *                 'status' ('CONFIRMED' by default, or 'CANCELLED'). A field that is empty
	 *                 is left out. Times are UTC instants; there is no zone to carry.
	 * @return string one VEVENT block, or '' when the uid or the start is missing
	 */
	public static function buildVevent(array $f) {
		if (empty($f['uid']) || empty($f['start_utc'])) {
			return '';
		}
		$lines = [];
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $f['uid'];
		$lines[] = 'DTSTAMP:' . self::formatUtcDateTime(gmdate('Y-m-d H:i:s'));
		$lines[] = 'DTSTART:' . self::formatUtcDateTime($f['start_utc']);
		if (!empty($f['end_utc'])) {
			$lines[] = 'DTEND:' . self::formatUtcDateTime($f['end_utc']);
		}
		foreach (['summary' => 'SUMMARY', 'description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $name) {
			if (!empty($f[$key])) {
				$lines[] = $name . ':' . self::escapeText($f[$key]);
			}
		}
		if (!empty($f['url'])) {
			$lines[] = 'URL:' . $f['url'];
		}
		$lines[] = 'STATUS:' . (($f['status'] ?? 'CONFIRMED') === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED');
		$lines[] = 'END:VEVENT';
		return implode("\r\n", $lines);
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
	 * Fold lines longer than 75 octets per RFC 5545 §3.1.
	 * Lines are folded with CRLF followed by a single space.
	 *
	 * @param string $ics_string Raw iCal string
	 * @return string Folded iCal string
	 */
	public static function foldLines($ics_string) {
		$lines = explode("\r\n", $ics_string);
		$folded = [];

		foreach ($lines as $line) {
			if (strlen($line) <= 75) {
				$folded[] = $line;
				continue;
			}

			// Fold at 75 octets
			$remaining = $line;
			$first = true;
			while (strlen($remaining) > 0) {
				if ($first) {
					$folded[] = substr($remaining, 0, 75);
					$remaining = substr($remaining, 75);
					$first = false;
				} else {
					// Continuation lines start with a space, so 74 chars of content
					$folded[] = ' ' . substr($remaining, 0, 74);
					$remaining = substr($remaining, 74);
				}
			}
		}

		return implode("\r\n", $folded);
	}
}
