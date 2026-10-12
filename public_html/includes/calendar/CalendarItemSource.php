<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItem.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));

/**
 * The single contract every feature implements to put things on calendars.
 *
 * Read-only by design: getItems() is the whole surface. The calendar is a
 * system of record only for native entries; everything else is a projection it
 * renders and links out to, never edits. A source therefore never mutates — it
 * only reports what its own system already owns.
 */
interface CalendarItemSource {

    /** Stable key for this source, e.g. 'events', 'bookings', 'native'. */
    public static function getKey(): string;

    /**
     * @return CalendarItem[] for the subject within [start_utc, end_utc],
     *         produced at the requested visibility ('details' | 'busy').
     */
    public function getItems(
        CalendarSubject $subject,
        string $start_utc,
        string $end_utc,
        string $visibility
    ): array;

    // Optional: public function priority(): int. A source that has it is asked in
    // descending order, and when two sources produce the same source_key the one
    // asked first is kept. The default is 0; a source that only echoes another
    // system's copy of something the platform owns returns a negative number.
}
