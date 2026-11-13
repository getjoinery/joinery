<?php
/**
 * Calendar Grid Component
 *
 * Month/week grid of timed items — the personal calendar's primary rendering
 * surface. Universal HTML5 + vanilla JS (no framework). All item times are UTC
 * strings; the client renders them in the calendar's timezone — the one the
 * config names, or the one the feed reply carries — never the browser's, so a
 * profile set to New York reads the same from a laptop in London.
 *
 * Config (via ComponentRenderer::render):
 *   'items'        - array of ['start','end','title','url','color','type','all_day']
 *   'view'         - 'month' | 'week' (default 'month')
 *   'feed_url'     - optional JSON endpoint; when set, paging refetches per range
 *   'initial_date' - Y-m-d to open on (default: today)
 *   'timezone'     - IANA zone items render in (default: the browser's; a feed
 *                    reply's `timezone` overrides)
 *
 * @version 1.6.1 - the items block is written with PageScript::encode(), so an item's text cannot close it
 * @version 1.6.0 - its script is assets/js/calendar-grid.js (no inline script)
 * @version 1.5.0 - items render in the calendar's timezone, not the browser's
 * @version 1.5.0 - a locked item (feed `locked`) renders as a lock chip
 * @version 1.4.1 - the chip tooltip carries the location
 * @version 1.4.0 - high-contrast restyle: blue default chips with a left
 *                  handle, past items grey with the colour on the handle
 */

$items        = $component_config['items'] ?? [];
$view         = $component_config['view'] ?? 'month';
$feed_url      = $component_config['feed_url'] ?? '';
$initial_date = $component_config['initial_date'] ?? gmdate('Y-m-d');
$timezone     = $component_config['timezone'] ?? '';

// Normalise items to plain arrays (accept CalendarItem objects or arrays).
$norm = [];
foreach ($items as $it) {
    if (is_object($it) && method_exists($it, 'toArray')) {
        $norm[] = $it->toArray();
    } elseif (is_array($it)) {
        $norm[] = $it;
    }
}

$cid = 'calgrid_' . substr(md5(uniqid('', true)), 0, 8);
?>
<div class="jy-ui joinery-calgrid" id="<?php echo $cid; ?>"
     data-view="<?php echo htmlspecialchars($view); ?>"
     data-feed="<?php echo htmlspecialchars($feed_url); ?>"
     data-initial="<?php echo htmlspecialchars($initial_date); ?>"
     data-timezone="<?php echo htmlspecialchars($timezone); ?>">
    <script type="application/json" class="calgrid-items"><?php echo PageScript::encode($norm); ?></script>
    <div class="calgrid-toolbar">
        <button type="button" class="calgrid-nav" data-dir="-1" aria-label="Previous">&#8249;</button>
        <button type="button" class="calgrid-today">Today</button>
        <button type="button" class="calgrid-nav" data-dir="1" aria-label="Next">&#8250;</button>
        <span class="calgrid-title"></span>
        <span class="calgrid-views">
            <button type="button" class="calgrid-view" data-v="month">Month</button>
            <button type="button" class="calgrid-view" data-v="week">Week</button>
        </span>
    </div>
    <div class="calgrid-body"></div>
</div>

<?php echo PageScript::src('assets/js/calendar-grid.js'); ?>
