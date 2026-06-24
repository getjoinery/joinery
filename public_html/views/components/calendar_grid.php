<?php
/**
 * Calendar Grid Component
 *
 * Month/week grid of timed items — the personal calendar's primary rendering
 * surface. Universal HTML5 + vanilla JS (no framework). All item times are UTC
 * strings; the client renders them in the viewer's own timezone.
 *
 * Config (via ComponentRenderer::render):
 *   'items'        - array of ['start','end','title','url','color','type','all_day']
 *   'view'         - 'month' | 'week' (default 'month')
 *   'feed_url'     - optional JSON endpoint; when set, paging refetches per range
 *   'initial_date' - Y-m-d to open on (default: today)
 *
 * @version 1.1.0
 */

$items        = $component_config['items'] ?? [];
$view         = $component_config['view'] ?? 'month';
$feed_url      = $component_config['feed_url'] ?? '';
$initial_date = $component_config['initial_date'] ?? gmdate('Y-m-d');

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
     data-initial="<?php echo htmlspecialchars($initial_date); ?>">
    <script type="application/json" class="calgrid-items"><?php echo json_encode($norm); ?></script>
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

<script>
(function(){
    if (window.__joineryCalGridInit) { window.__joineryCalGridInit('<?php echo $cid; ?>'); return; }

    function parseUTC(s){ return s ? new Date(String(s).replace(' ', 'T') + 'Z') : null; }
    function fmtTime(d){ return d.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}); }
    function ymd(d){ return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
    function startOfWeek(d){ var x = new Date(d); x.setDate(x.getDate() - x.getDay()); x.setHours(0,0,0,0); return x; }
    var DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function CalGrid(root){
        this.root = root;
        this.view = root.dataset.view || 'month';
        this.feed = root.dataset.feed || '';
        var init = root.dataset.initial;
        this.cursor = init ? new Date(init + 'T12:00:00') : new Date();
        var raw = root.querySelector('.calgrid-items');
        this.items = raw ? JSON.parse(raw.textContent || '[]') : [];
        this.bind();
        // With a feed, fetch the current range on load; otherwise render static items.
        this.refresh();
    }
    CalGrid.prototype.bind = function(){
        var self = this;
        this.root.querySelectorAll('.calgrid-nav').forEach(function(b){
            b.addEventListener('click', function(){ self.shift(parseInt(b.dataset.dir,10)); });
        });
        this.root.querySelector('.calgrid-today').addEventListener('click', function(){ self.cursor = new Date(); self.refresh(); });
        this.root.querySelectorAll('.calgrid-view').forEach(function(b){
            b.addEventListener('click', function(){ self.view = b.dataset.v; self.refresh(); });
        });
        // Native chip clicks open the popover instead of navigating.
        this.root.addEventListener('click', function(ev){
            var chip = ev.target.closest('.calgrid-chip[data-native]');
            if (!chip) return;
            ev.preventDefault();
            var it; try { it = JSON.parse(chip.dataset.item || '{}'); } catch(e) { it = {}; }
            self.root.dispatchEvent(new CustomEvent('calendarchipclick', {
                detail: { item: it, targetRect: chip.getBoundingClientRect() },
                bubbles: true
            }));
        });
        // Refresh the grid when a popover save/delete completes.
        window.addEventListener('calendarentrychanged', function(){ self.refresh(); });
    };
    CalGrid.prototype.shift = function(dir){
        if (this.view === 'month') { this.cursor.setMonth(this.cursor.getMonth() + dir); }
        else { this.cursor.setDate(this.cursor.getDate() + dir * 7); }
        this.refresh();
    };
    CalGrid.prototype.rangeFor = function(){
        if (this.view === 'month') {
            var first = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1);
            var gridStart = startOfWeek(first);
            var gridEnd = new Date(gridStart); gridEnd.setDate(gridEnd.getDate() + 42);
            return [gridStart, gridEnd];
        }
        var ws = startOfWeek(this.cursor);
        var we = new Date(ws); we.setDate(we.getDate() + 7);
        return [ws, we];
    };
    CalGrid.prototype.refresh = function(){
        var self = this;
        if (this.feed) {
            var r = this.rangeFor();
            var url = this.feed + (this.feed.indexOf('?') >= 0 ? '&' : '?') +
                'start=' + encodeURIComponent(ymd(r[0]) + ' 00:00:00') +
                '&end=' + encodeURIComponent(ymd(r[1]) + ' 00:00:00');
            fetch(url, {credentials: 'same-origin'}).then(function(res){ return res.json(); })
                .then(function(j){ self.items = (j && j.items) ? j.items : []; self.render(); })
                .catch(function(){ self.render(); });
        } else {
            this.render();
        }
    };
    CalGrid.prototype.eventsForDay = function(dayStr){
        return this.items.filter(function(it){
            var s = parseUTC(it.start); if (!s) return false;
            return ymd(s) === dayStr;
        }).sort(function(a,b){ return (a.start||'').localeCompare(b.start||''); });
    };
    CalGrid.prototype.chip = function(it){
        var s = parseUTC(it.start);
        var label = (it.all_day ? '' : (s ? fmtTime(s) + ' ' : '')) + (it.title || 'Busy');
        var color = it.color || '#6b7280';
        var isNative = it.source_key && String(it.source_key).indexOf('native:') === 0;
        var el = document.createElement(isNative ? 'button' : (it.url ? 'a' : 'span'));
        el.className = 'calgrid-chip';
        el.style.background = color;
        el.title = label;
        el.textContent = label;
        if (!isNative && it.url) { el.href = it.url; }
        if (isNative) {
            el.type = 'button';
            el.dataset.native = '1';
            el.dataset.item = JSON.stringify(it);
        }
        return el;
    };
    CalGrid.prototype.render = function(){
        var titleEl = this.root.querySelector('.calgrid-title');
        var body = this.root.querySelector('.calgrid-body');
        body.innerHTML = '';
        this.root.querySelectorAll('.calgrid-view').forEach(function(b){
            b.classList.toggle('is-active', b.dataset.v === this.view);
        }, this);
        if (this.view === 'month') { this.renderMonth(titleEl, body); }
        else { this.renderWeek(titleEl, body); }
    };
    CalGrid.prototype.renderMonth = function(titleEl, body){
        titleEl.textContent = MONTHS[this.cursor.getMonth()] + ' ' + this.cursor.getFullYear();
        var grid = document.createElement('div'); grid.className = 'calgrid-grid';
        DOW.forEach(function(d){ var h = document.createElement('div'); h.className = 'calgrid-dow'; h.textContent = d; grid.appendChild(h); });
        var r = this.rangeFor(); var day = new Date(r[0]); var todayStr = ymd(new Date());
        var month = this.cursor.getMonth();
        for (var i = 0; i < 42; i++) {
            var cell = document.createElement('div'); cell.className = 'calgrid-cell';
            if (day.getMonth() !== month) { cell.className += ' is-other'; }
            var ds = ymd(day);
            if (ds === todayStr) { cell.className += ' is-today'; }
            var num = document.createElement('span'); num.className = 'calgrid-daynum'; num.textContent = day.getDate(); cell.appendChild(num);
            this.eventsForDay(ds).forEach(function(it){ cell.appendChild(this.chip(it)); }, this);
            (function(self, key){
                cell.addEventListener('click', function(ev){
                    if (ev.target.closest('.calgrid-chip')) { return; }
                    self.root.dispatchEvent(new CustomEvent('calendardayclick', {
                        detail: { date: key, targetRect: ev.currentTarget.getBoundingClientRect() },
                        bubbles: true
                    }));
                });
            })(this, ds);
            cell.style.cursor = 'pointer';
            grid.appendChild(cell);
            day.setDate(day.getDate() + 1);
        }
        body.appendChild(grid);
    };
    CalGrid.prototype.renderWeek = function(titleEl, body){
        var r = this.rangeFor();
        titleEl.textContent = 'Week of ' + r[0].toLocaleDateString([], {month:'short', day:'numeric', year:'numeric'});
        var todayStr = ymd(new Date());
        var day = new Date(r[0]);
        for (var i = 0; i < 7; i++) {
            var ds = ymd(day);
            var rowEl = document.createElement('div'); rowEl.className = 'calgrid-week-day';
            var dlabel = document.createElement('div'); dlabel.className = 'calgrid-week-date';
            dlabel.textContent = DOW[day.getDay()] + ' ' + day.getDate();
            if (ds === todayStr) { dlabel.style.fontWeight = '700'; }
            var evs = document.createElement('div'); evs.className = 'calgrid-week-events';
            var dayEvents = this.eventsForDay(ds);
            if (!dayEvents.length) { var em = document.createElement('span'); em.className = 'calgrid-empty'; em.textContent = '—'; evs.appendChild(em); }
            dayEvents.forEach(function(it){ evs.appendChild(this.chip(it)); }, this);
            rowEl.appendChild(dlabel); rowEl.appendChild(evs);
            body.appendChild(rowEl);
            day.setDate(day.getDate() + 1);
        }
    };

    var registry = {};
    window.__joineryCalGridInit = function(id){
        var el = document.getElementById(id);
        if (el && !registry[id]) { registry[id] = new CalGrid(el); }
    };
    window.__joineryCalGridInit('<?php echo $cid; ?>');
})();
</script>
