<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

/**
 * Owner-fixed, provenance-deduped writer for AI-originated personal calendar
 * entries (specs/joinery_ai_calendar_ai_surface.md). The ONLY path an
 * AI-originated entry takes, in every mode: the agent-mode
 * `create_calendar_entry` action and the `EmailScheduleJob` pipeline job both
 * call `upsert()` rather than touching `CalendarEntry` directly.
 *
 * `CalendarEntry::authenticate_write()` allows any permission->=5 caller to
 * write any subject's entry (recipes are typically admin-configured), so
 * trusting model output with a raw write would let a prompt-injected email
 * or a compromised recipe aim at another user's calendar. This class is the
 * one place the subject is fixed by the CALLER's code — never by anything
 * the model produced — for every consumer.
 *
 * @version 1.0
 */
class CalendarEntryImporter {

    /**
     * @param int    $owner_user_id subject; fixed by the CALLER's code, never model output
     * @param array  $fields  title, start_local, end_local, timezone,
     *                        all_day (bool), source, source_ref (nullable)
     * @return CalendarEntry the saved entry
     * @throws InvalidArgumentException on invalid timezone, unparseable
     *         times, end <= start, or empty title
     */
    public static function upsert(int $owner_user_id, array $fields): CalendarEntry {
        $title    = trim((string)($fields['title'] ?? ''));
        $tz       = (string)($fields['timezone'] ?? '');
        $all_day  = !empty($fields['all_day']);
        $start_local = (string)($fields['start_local'] ?? '');
        $end_local   = $fields['end_local'] ?? null;
        $source      = $fields['source'] ?? null;
        $source_ref  = $fields['source_ref'] ?? null;

        if ($title === '') {
            throw new InvalidArgumentException('title is required.');
        }
        $title = mb_substr($title, 0, 255);

        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("timezone ('$tz') is not a recognized IANA timezone.");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_local)) {
            throw new InvalidArgumentException('start_local must be in Y-m-d H:i:s format.');
        }

        if ($all_day) {
            // Bounds derive from the date exactly as the calendar editor does
            // (logic/calendar_logic.php save path) — end_local is ignored entirely.
            $date        = substr($start_local, 0, 10);
            $start_local = $date . ' 00:00:00';
            $end_local   = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
        } else {
            if ($end_local === null || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$end_local)) {
                throw new InvalidArgumentException('end_local is required (Y-m-d H:i:s format) when all_day is false.');
            }
            $end_local = (string)$end_local;
            if ($end_local <= $start_local) {
                throw new InvalidArgumentException('end_local must be after start_local.');
            }
        }

        $start_utc = LibraryFunctions::convert_time($start_local, $tz, 'UTC', 'Y-m-d H:i:s');
        $end_utc   = LibraryFunctions::convert_time($end_local,   $tz, 'UTC', 'Y-m-d H:i:s');

        $entry = null;
        if ($source_ref !== null) {
            $existing = new MultiCalendarEntry([
                'subject_type' => CalendarSubject::TYPE_USER,
                'subject_id'   => $owner_user_id,
                'deleted'      => false,
                'source'       => (string)$source,
                'source_ref'   => (string)$source_ref,
            ]);
            $existing->load();
            if (count($existing)) {
                $entry = $existing->get(0);
            }
        }

        if ($entry === null) {
            $entry = new CalendarEntry(NULL);
            $entry->set('cal_subject_type', CalendarSubject::TYPE_USER);
            $entry->set('cal_subject_id',   $owner_user_id);
            $entry->set('cal_type',         'personal');
            $entry->set('cal_source',            $source !== null ? (string)$source : null);
            $entry->set('cal_source_event_id',   $source_ref !== null ? (string)$source_ref : null);
        }

        $entry->set('cal_status', 'tentative');
        $entry->set_core_fields($title, $all_day, true, $start_local, $end_local, $start_utc, $end_utc, $tz);

        $entry->prepare();
        $entry->save();

        return $entry;
    }

}
