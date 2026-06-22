<?php

/**
 * One thing on a timeline.
 *
 * A value object — NOT necessarily a stored row. Most items are projected live
 * from their owning system (an event, a booking) and exist only for the length
 * of a request; native personal entries are the exception and are persisted in
 * cal_items, but they too travel through the system as CalendarItem instances.
 *
 * All instants are UTC strings (Y-m-d H:i:s) — every wire value in the platform
 * is UTC; the viewer's timezone is applied only at render time.
 */
class CalendarItem {

    // type — drives colour + icon in the UI
    const TYPE_EVENT    = 'event';
    const TYPE_BOOKING  = 'booking';
    const TYPE_EXTERNAL = 'external';
    const TYPE_PERSONAL = 'personal';

    // visibility — how much of the item a viewer may see
    const VIS_DETAILS = 'details';   // owner sees title/url
    const VIS_BUSY    = 'busy';      // opaque block, no title/url

    public $start_utc;
    public $end_utc;
    public $all_day = false;
    public $type = self::TYPE_PERSONAL;
    public $title = null;               // owner-visible only
    public $url = null;                 // owner-visible only
    public $blocks_availability = true;
    public $visibility = self::VIS_DETAILS;
    public $source = null;              // CalendarItemSource key that produced it
    public $source_key = null;          // stable id per item: "{source}:{record-id}"

    /** Default colour per type; a source may override via the `color` key. */
    private $color = null;

    const TYPE_COLORS = [
        self::TYPE_EVENT    => '#2563eb',  // blue
        self::TYPE_BOOKING  => '#059669',  // green
        self::TYPE_EXTERNAL => '#9333ea',  // purple
        self::TYPE_PERSONAL => '#6b7280',  // grey
    ];

    public function __construct(array $data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
        if (isset($data['color'])) {
            $this->color = $data['color'];
        }
    }

    public function getColor(): string {
        if ($this->color) {
            return $this->color;
        }
        return self::TYPE_COLORS[$this->type] ?? self::TYPE_COLORS[self::TYPE_PERSONAL];
    }

    /**
     * Return a copy reduced to the given visibility. At `busy` level the title
     * and url are stripped so nothing leaks to a stranger — enforced here at the
     * projection boundary, never by trusting a source to have done it.
     */
    public function atVisibility(string $visibility): CalendarItem {
        if ($visibility === self::VIS_BUSY) {
            $copy = clone $this;
            $copy->title = null;
            $copy->url = null;
            $copy->visibility = self::VIS_BUSY;
            return $copy;
        }
        return $this;
    }

    /** Shape the calendar_grid component consumes. */
    public function toArray(): array {
        return [
            'start'               => $this->start_utc,
            'end'                 => $this->end_utc,
            'all_day'             => $this->all_day,
            'title'               => $this->title,
            'url'                 => $this->url,
            'color'               => $this->getColor(),
            'type'                => $this->type,
            'source_key'          => $this->source_key,
            'blocks_availability' => $this->blocks_availability,
        ];
    }
}
