<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

/**
 * Turns a subject's working hours (minus the time they are already busy) into a
 * list of open booking slots. Pure computation — no HTTP, no DB — so it is
 * unit-testable in isolation and reusable by any feature that needs "when is
 * this subject free."
 *
 * Working hours are wall-clock ("9am Monday") in the schedule's timezone, so
 * they survive DST transitions; the generator converts each concrete date's
 * windows to UTC instants per date. Everything it emits is UTC.
 */
class SlotGenerator {

    /**
     * @param array $params {
     *   timezone:               IANA tz the windows are defined in
     *   windows:                [ ['day_of_week'=>0..6, 'start'=>'HH:MM:SS', 'end'=>'HH:MM:SS'], ... ]
     *   overrides:              [ ['date'=>'Y-m-d', 'start'=>'HH:MM:SS'|null, 'end'=>'HH:MM:SS'|null], ... ]
     *   range_start_utc:        'Y-m-d H:i:s' — earliest instant to consider
     *   range_end_utc:          'Y-m-d H:i:s' — latest instant to consider
     *   duration_minutes:       slot length
     *   increment_minutes:      start-time granularity (default: duration)
     *   buffer_before_minutes:  padding required before a booking (default 0)
     *   buffer_after_minutes:   padding required after a booking (default 0)
     *   min_notice_minutes:     earliest bookable offset from now (default 0)
     *   busy:                   [ ['start'=>UTC, 'end'=>UTC], ... ] — the busy projection
     *   now_utc:                override "now" for testing (default gmdate)
     * }
     * @return array[] list of ['start'=>UTC, 'end'=>UTC]
     */
    public static function generate(array $params): array {
        $tz          = $params['timezone'] ?? 'UTC';
        $windows     = $params['windows'] ?? [];
        $overrides   = $params['overrides'] ?? [];
        $range_start = $params['range_start_utc'];
        $range_end   = $params['range_end_utc'];
        $duration    = max(1, (int)($params['duration_minutes'] ?? 30));
        $increment   = max(1, (int)($params['increment_minutes'] ?? $duration));
        $buf_before  = max(0, (int)($params['buffer_before_minutes'] ?? 0));
        $buf_after   = max(0, (int)($params['buffer_after_minutes'] ?? 0));
        $min_notice  = max(0, (int)($params['min_notice_minutes'] ?? 0));
        $busy        = $params['busy'] ?? [];
        $now         = $params['now_utc'] ?? gmdate('Y-m-d H:i:s');

        $range_start_e = self::epoch($range_start);
        $range_end_e   = self::epoch($range_end);
        $earliest_e    = max($range_start_e, self::epoch($now) + $min_notice * 60);

        // 1. Build available UTC intervals from each concrete local date's windows.
        $available = self::availabilityRanges($tz, $windows, $overrides, $range_start, $range_end);
        if (!$available) {
            return [];
        }

        // 2. Pad busy blocks by the buffers; these are the regions a slot may not touch.
        $blocked = self::blockedRanges($busy, $buf_before, $buf_after);

        // 3. Anchor the increment grid to each availability window's start, emit a
        //    duration-long slot at each grid point, and keep it only if it stays
        //    inside the window, clears the blocked regions, and passes notice/range.
        //    Window-anchored (not busy-anchored) keeps start times on a predictable
        //    grid rather than drifting to wherever a busy block happens to end.
        $slots = [];
        foreach ($available as $interval) {
            list($ws, $we) = $interval;
            for ($start = $ws; $start + $duration * 60 <= $we; $start += $increment * 60) {
                $end = $start + $duration * 60;
                if ($start < $earliest_e || $start < $range_start_e || $end > $range_end_e) {
                    continue;
                }
                if (self::overlapsAny($start, $end, $blocked)) {
                    continue;
                }
                $slots[] = ['start' => self::fmt($start), 'end' => self::fmt($end)];
            }
        }
        return $slots;
    }

    /**
     * The subject's free availability as merged UTC intervals: working hours
     * minus busy (buffer-padded), with no slot-walking. Drives the availability
     * editor's "this is when you're open" preview, and any consumer that wants
     * blocks rather than discrete bookable slots.
     *
     * @return array[] list of ['start'=>UTC, 'end'=>UTC]
     */
    public static function availableIntervals(array $params): array {
        $available = self::availabilityRanges(
            $params['timezone'] ?? 'UTC',
            $params['windows'] ?? [],
            $params['overrides'] ?? [],
            $params['range_start_utc'],
            $params['range_end_utc']
        );
        $blocked = self::blockedRanges(
            $params['busy'] ?? [],
            max(0, (int)($params['buffer_before_minutes'] ?? 0)),
            max(0, (int)($params['buffer_after_minutes'] ?? 0))
        );
        $free = self::subtract($available, $blocked);
        $out = [];
        foreach ($free as $r) {
            $out[] = ['start' => self::fmt($r[0]), 'end' => self::fmt($r[1])];
        }
        return $out;
    }

    /** Availability windows as merged epoch ranges (no busy subtraction). */
    private static function availabilityRanges(string $tz, array $windows, array $overrides, string $range_start, string $range_end): array {
        $available = [];
        foreach (self::localDatesInRange($range_start, $range_end, $tz) as $date) {
            foreach (self::windowsForDate($date, $windows, $overrides) as $w) {
                $ws = self::epoch(LibraryFunctions::convert_time($date . ' ' . $w[0], $tz, 'UTC', 'Y-m-d H:i:s'));
                $we = self::epoch(LibraryFunctions::convert_time($date . ' ' . $w[1], $tz, 'UTC', 'Y-m-d H:i:s'));
                if ($we > $ws) {
                    $available[] = [$ws, $we];
                }
            }
        }
        return self::mergeRanges($available);
    }

    /** Busy blocks padded by buffers, as merged epoch ranges. */
    private static function blockedRanges(array $busy, int $buf_before, int $buf_after): array {
        $blocked = [];
        foreach ($busy as $b) {
            if (empty($b['start']) || empty($b['end'])) {
                continue;
            }
            $blocked[] = [self::epoch($b['start']) - $buf_before * 60, self::epoch($b['end']) + $buf_after * 60];
        }
        return self::mergeRanges($blocked);
    }

    /**
     * Convenience wrapper that loads a Schedule's windows + overrides and the
     * busy projection is supplied by the caller (kept out so this stays pure of
     * the registry dependency for testing).
     */
    public static function forSchedule($schedule, string $range_start_utc, string $range_end_utc, array $opts, array $busy): array {
        require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
        require_once(PathHelper::getIncludePath('data/schedule_override_class.php'));

        $windows = [];
        $mw = new MultiScheduleWindow(['schedule_id' => $schedule->key, 'deleted' => false]);
        $mw->load();
        foreach ($mw as $w) {
            $windows[] = [
                'day_of_week' => (int)$w->get('scw_day_of_week'),
                'start'       => $w->get('scw_start_time'),
                'end'         => $w->get('scw_end_time'),
            ];
        }

        $overrides = [];
        $mo = new MultiScheduleOverride(['schedule_id' => $schedule->key, 'deleted' => false]);
        $mo->load();
        foreach ($mo as $o) {
            $overrides[] = [
                'date'  => substr($o->get('sco_date'), 0, 10),
                'start' => $o->get('sco_start_time'),
                'end'   => $o->get('sco_end_time'),
            ];
        }

        return self::generate(array_merge([
            'timezone'        => $schedule->get('sch_timezone'),
            'windows'         => $windows,
            'overrides'       => $overrides,
            'range_start_utc' => $range_start_utc,
            'range_end_utc'   => $range_end_utc,
            'busy'            => $busy,
        ], $opts));
    }

    // ------------------------------------------------------------------
    // Window resolution
    // ------------------------------------------------------------------

    /**
     * The windows that apply to one local date. Overrides replace the weekly
     * template entirely for that date: if any override row exists for the date,
     * only the time'd ones contribute windows (a null/null row = closed).
     *
     * @return array[] list of ['HH:MM:SS' start, 'HH:MM:SS' end]
     */
    public static function windowsForDate(string $date, array $windows, array $overrides): array {
        $date_overrides = array_values(array_filter($overrides, function ($o) use ($date) {
            return substr($o['date'], 0, 10) === $date;
        }));

        if ($date_overrides) {
            $out = [];
            foreach ($date_overrides as $o) {
                if (!empty($o['start']) && !empty($o['end'])) {
                    $out[] = [$o['start'], $o['end']];
                }
            }
            return $out;   // empty = explicitly unavailable that date
        }

        $dow = (int)date('w', strtotime($date));   // 0=Sun..6=Sat
        $out = [];
        foreach ($windows as $w) {
            if ((int)$w['day_of_week'] === $dow && !empty($w['start']) && !empty($w['end'])) {
                $out[] = [$w['start'], $w['end']];
            }
        }
        return $out;
    }

    /** Local dates the UTC range touches, padded a day each side for tz edges. */
    public static function localDatesInRange(string $range_start_utc, string $range_end_utc, string $tz): array {
        $start_local = LibraryFunctions::convert_time($range_start_utc, 'UTC', $tz, 'Y-m-d');
        $end_local   = LibraryFunctions::convert_time($range_end_utc, 'UTC', $tz, 'Y-m-d');

        $cursor = strtotime($start_local . ' -1 day');
        $stop   = strtotime($end_local . ' +1 day');
        $dates = [];
        while ($cursor <= $stop) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }
        return $dates;
    }

    // ------------------------------------------------------------------
    // Interval math (epoch seconds)
    // ------------------------------------------------------------------

    /** Merge overlapping/adjacent [start,end] epoch ranges; returns sorted. */
    public static function mergeRanges(array $ranges): array {
        if (!$ranges) {
            return [];
        }
        usort($ranges, function ($a, $b) { return $a[0] <=> $b[0]; });
        $merged = [$ranges[0]];
        for ($i = 1; $i < count($ranges); $i++) {
            $last = &$merged[count($merged) - 1];
            if ($ranges[$i][0] <= $last[1]) {
                if ($ranges[$i][1] > $last[1]) {
                    $last[1] = $ranges[$i][1];
                }
            } else {
                $merged[] = $ranges[$i];
            }
            unset($last);
        }
        return $merged;
    }

    /** available minus blocked — both lists are merged & sorted epoch ranges. */
    public static function subtract(array $available, array $blocked): array {
        $free = [];
        foreach ($available as $av) {
            $segments = [$av];
            foreach ($blocked as $bl) {
                $next = [];
                foreach ($segments as $seg) {
                    list($s, $e) = $seg;
                    // no overlap
                    if ($bl[1] <= $s || $bl[0] >= $e) {
                        $next[] = $seg;
                        continue;
                    }
                    // left remainder
                    if ($bl[0] > $s) {
                        $next[] = [$s, min($bl[0], $e)];
                    }
                    // right remainder
                    if ($bl[1] < $e) {
                        $next[] = [max($bl[1], $s), $e];
                    }
                }
                $segments = $next;
            }
            foreach ($segments as $seg) {
                if ($seg[1] > $seg[0]) {
                    $free[] = $seg;
                }
            }
        }
        return $free;
    }

    /** True if [s,e] intersects any merged blocked range. */
    public static function overlapsAny(int $s, int $e, array $blocked): bool {
        foreach ($blocked as $bl) {
            if ($s < $bl[1] && $e > $bl[0]) {
                return true;
            }
        }
        return false;
    }

    private static function epoch(string $utc): int {
        return strtotime($utc . ' UTC');
    }

    private static function fmt(int $epoch): string {
        return gmdate('Y-m-d H:i:s', $epoch);
    }
}
