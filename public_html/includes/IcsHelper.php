<?php
/**
 * IcsHelper - RFC 5545 iCalendar generation utility
 *
 * Generates .ics files for single event downloads and multi-event calendar feeds.
 *
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

		$lines = [];
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . self::generateUid($event_id, $instance_date);
		$lines[] = 'DTSTAMP:' . self::formatUtcDateTime(gmdate('Y-m-d H:i:s'));
		$lines[] = 'DTSTART:' . self::formatUtcDateTime($start_time);

		$end_time = self::getField($event, 'evt_end_time');
		if ($end_time) {
			$lines[] = 'DTEND:' . self::formatUtcDateTime($end_time);
		}

		$name = self::getField($event, 'evt_name');
		if ($name) {
			$lines[] = 'SUMMARY:' . self::escapeText($name);
		}

		$description = self::getField($event, 'evt_short_description');
		if ($description) {
			// Strip HTML tags and truncate to 500 chars
			$description = strip_tags($description);
			if (mb_strlen($description) > 500) {
				$description = mb_substr($description, 0, 497) . '...';
			}
			$lines[] = 'DESCRIPTION:' . self::escapeText($description);
		}

		$location = self::getLocationString($event);
		if ($location) {
			$lines[] = 'LOCATION:' . self::escapeText($location);
		}

		$url = self::getEventUrl($event, $instance_date);
		if ($url) {
			$lines[] = 'URL:' . $url;
		}

		// STATUS mapping: 1 (active) → CONFIRMED, 2 (completed) → CONFIRMED, 3 (canceled) → CANCELLED
		$status = self::getField($event, 'evt_status');
		if ($status == 3) {
			$lines[] = 'STATUS:CANCELLED';
		} else {
			$lines[] = 'STATUS:CONFIRMED';
		}

		$lines[] = 'END:VEVENT';

		return implode("\r\n", $lines);
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
			$site_name = $settings->get_setting('siteName') ?: 'Events';
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
	 * Generate a deterministic UID for a calendar event.
	 *
	 * @param int $event_id Event primary key
	 * @param string|null $instance_date Date for recurring instances (YYYY-MM-DD)
	 * @return string UID string
	 */
	private static function generateUid($event_id, $instance_date = null) {
		$settings = Globalvars::get_instance();
		$domain = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'];
		$domain = preg_replace('#^https?://#', '', $domain);
		$domain = rtrim($domain, '/');

		if ($instance_date) {
			$date_clean = str_replace('-', '', $instance_date);
			return 'event-' . $event_id . '-' . $date_clean . '@' . $domain;
		}
		return 'event-' . $event_id . '@' . $domain;
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
		if ($location_id) {
			require_once(PathHelper::getIncludePath('data/locations_class.php'));
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
	private static function foldLines($ics_string) {
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
