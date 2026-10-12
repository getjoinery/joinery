<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSource.php'));

/**
 * Discovers every CalendarItemSource and is the only coupling point between the
 * calendar and the features that feed it. A new source appears on every calendar
 * and gates every availability calculation with no change to the grid, the slot
 * generator, or any other source — which is the whole reason this lives in core.
 *
 * An item whose source_key was already produced by an earlier source is dropped, so the
 * same meeting never shows twice (a booking and its copy on an external calendar).
 *
 * Two derived views come out of the one upstream contract (items):
 *   - getItems()      — everything to render, at a visibility.
 *   - getBusyBlocks() — the merged busy projection the availability engine consumes.
 */
class CalendarItemSourceRegistry {

    /** @var CalendarItemSource[]|null keyed by source key; cached per request */
    private static $sources = null;

    /**
     * Scan core includes/calendar/item_sources/ and every active plugin's
     * includes/calendar_item_sources/ for implementations. EmailSender-style.
     */
    private static function discover(): array {
        if (self::$sources !== null) {
            return self::$sources;
        }
        self::$sources = [];

        $dirs = [PathHelper::getIncludePath('includes/calendar/item_sources/')];

        foreach (PluginHelper::getActivePlugins() as $name => $plugin) {
            $dirs[] = PathHelper::getIncludePath('plugins/' . $name . '/includes/calendar_item_sources/');
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '*Source.php') as $file) {
                require_once($file);
                $class = basename($file, '.php');
                if (class_exists($class)
                    && in_array('CalendarItemSource', class_implements($class) ?: [])) {
                    self::$sources[$class::getKey()] = new $class();
                }
            }
        }

        return self::$sources;
    }

    /** Reset the discovery cache (tests). */
    public static function resetCache(): void {
        self::$sources = null;
    }

    /** @return CalendarItemSource[] keyed by source key */
    public static function getSources(): array {
        return self::discover();
    }

    /**
     * Aggregate items across all sources for the subject and window.
     *
     * Visibility is enforced HERE, at the projection boundary: when the caller
     * asks for 'busy', every item is reduced to an opaque block before it leaves
     * this method — the title/url never depend on a source having behaved.
     *
     * @return CalendarItem[]
     */
    public static function getItems(
        CalendarSubject $subject,
        string $start_utc,
        string $end_utc,
        string $visibility = CalendarItem::VIS_DETAILS
    ): array {
        $items = [];
        $seen = [];   // source_key => true: the same thing from a second source is not a second item
        foreach (self::ordered() as $source) {
            $produced = $source->getItems($subject, $start_utc, $end_utc, $visibility);
            foreach ($produced as $item) {
                if (!($item instanceof CalendarItem)) {
                    continue;
                }
                $item = $item->atVisibility($visibility);
                // Visibility has been reduced before the key is looked at, so a
                // 'busy' request never leaks a title through it. An item with no
                // key is never merged with anything.
                $key = (string)$item->source_key;
                if ($key !== '') {
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                }
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * The sources in the order they are asked: higher priority() first, and
     * discovery order (core, then each active plugin) among equals. A source with
     * no priority() has 0, so the native record outranks an external echo of it
     * (which returns a negative number) and the first source discovered wins a tie.
     *
     * @return CalendarItemSource[]
     */
    private static function ordered(): array {
        $sources = array_values(self::discover());
        $rank = [];
        foreach ($sources as $i => $source) {
            $rank[$i] = method_exists($source, 'priority') ? (int)$source->priority() : 0;
        }
        $order = array_keys($sources);
        usort($order, function ($a, $b) use ($rank) {
            return ($rank[$b] <=> $rank[$a]) ?: ($a <=> $b);
        });
        return array_map(function ($i) use ($sources) { return $sources[$i]; }, $order);
    }

    /** Use exactly these sources (tests), keyed by source key; null returns to discovery. */
    public static function useSources(?array $sources): void {
        self::$sources = $sources;
    }

    /**
     * The busy projection: items requested at `busy` visibility, kept only when
     * they block availability, reduced to {start,end} and merged. This is the
     * single thing the availability engine and SlotGenerator consume.
     *
     * $include is an optional caller policy on top of blocks_availability — e.g.
     * a consumer that wants to treat tentative (cal_status) entries differently
     * can filter on $item->status here, since raw {start,end} blocks have no
     * per-item metadata left to post-filter on. Default null = every busy item
     * counts, exactly as before this parameter existed — zero behavior change
     * for a caller that doesn't pass one (specs/joinery_ai_calendar_ai_surface.md § 4).
     *
     * @return array[] list of ['start'=>UTC, 'end'=>UTC], sorted and merged
     */
    public static function getBusyBlocks(
        CalendarSubject $subject,
        string $start_utc,
        string $end_utc,
        ?callable $include = null
    ): array {
        $blocks = [];
        foreach (self::getItems($subject, $start_utc, $end_utc, CalendarItem::VIS_BUSY) as $item) {
            if (!$item->blocks_availability) {
                continue;
            }
            if ($include !== null && !$include($item)) {
                continue;
            }
            $blocks[] = ['start' => $item->start_utc, 'end' => $item->end_utc];
        }
        return self::mergeBlocks($blocks);
    }

    /**
     * Pure overlap-merge over {start,end} UTC string ranges. Adjacent or
     * overlapping ranges collapse into one. Unit-testable in isolation.
     */
    public static function mergeBlocks(array $blocks): array {
        $blocks = array_values(array_filter($blocks, function ($b) {
            return !empty($b['start']) && !empty($b['end']) && $b['end'] > $b['start'];
        }));
        if (!$blocks) {
            return [];
        }
        usort($blocks, function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });

        $merged = [];
        $current = $blocks[0];
        for ($i = 1; $i < count($blocks); $i++) {
            $b = $blocks[$i];
            if ($b['start'] <= $current['end']) {
                // overlap or touch — extend
                if ($b['end'] > $current['end']) {
                    $current['end'] = $b['end'];
                }
            } else {
                $merged[] = $current;
                $current = $b;
            }
        }
        $merged[] = $current;
        return $merged;
    }
}
