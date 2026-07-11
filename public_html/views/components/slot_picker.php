<?php
/**
 * Slot Picker Component
 *
 * The booking time picker: a mini-month on the left, the selected day's open
 * times on the right, and a viewer-timezone selector (auto-detected, override-
 * able). Loads UTC slots from `slots_url` and writes the chosen slot's UTC start
 * into a hidden field for the surrounding booking form to submit. Universal
 * HTML5 + vanilla JS.
 *
 * Config (via ComponentRenderer::render):
 *   'slots_url'    - /api/v1 action endpoint (POST {slug?, start, end}) -> data.slots [{start,end}] (UTC)
 *   'field_name'   - hidden input name receiving the chosen UTC slot start (default 'slot_start')
 *   'initial_date' - Y-m-d to open on (default today)
 *
 * @version 1.2.0
 */

$slots_url    = $component_config['slots_url'] ?? '';
$field_name   = $component_config['field_name'] ?? 'slot_start';
$initial_date = $component_config['initial_date'] ?? gmdate('Y-m-d');

$pid = 'slotpick_' . substr(md5(uniqid('', true)), 0, 8);
?>
<div class="jy-ui joinery-slotpicker" id="<?php echo $pid; ?>"
     data-slots="<?php echo htmlspecialchars($slots_url); ?>"
     data-field="<?php echo htmlspecialchars($field_name); ?>"
     data-initial="<?php echo htmlspecialchars($initial_date); ?>">
    <input type="hidden" name="<?php echo htmlspecialchars($field_name); ?>" class="slotpick-value" value="">
    <div class="slotpick-cols">
        <div class="slotpick-cal">
            <div class="slotpick-calhead">
                <button type="button" class="slotpick-nav" data-dir="-1" aria-label="Previous month">&#8249;</button>
                <span class="slotpick-month"></span>
                <button type="button" class="slotpick-nav" data-dir="1" aria-label="Next month">&#8250;</button>
            </div>
            <div class="slotpick-grid"></div>
        </div>
        <div class="slotpick-times">
            <div class="slotpick-tzrow">
                <label>Times in
                    <select class="slotpick-tz"></select>
                </label>
            </div>
            <div class="slotpick-daylabel"></div>
            <div class="slotpick-timelist"></div>
        </div>
    </div>
</div>

<script>
(function(){
    if (window.__joinerySlotPickerInit) { window.__joinerySlotPickerInit('<?php echo $pid; ?>'); return; }

    function parseUTC(s){ return s ? new Date(String(s).replace(' ', 'T') + 'Z') : null; }
    function ymdInTz(d, tz){
        // Y-m-d of instant d as seen in tz.
        var parts = new Intl.DateTimeFormat('en-CA', {timeZone: tz, year:'numeric', month:'2-digit', day:'2-digit'}).format(d);
        return parts; // en-CA gives YYYY-MM-DD
    }
    function timeInTz(d, tz){
        return new Intl.DateTimeFormat([], {timeZone: tz, hour:'numeric', minute:'2-digit'}).format(d);
    }
    var DOW = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var COMMON_TZ = ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','UTC','Europe/London','Europe/Paris','Europe/Berlin','Asia/Kolkata','Asia/Singapore','Asia/Tokyo','Australia/Sydney'];

    function SlotPicker(root){
        this.root = root;
        this.slotsUrl = root.dataset.slots;
        this.field = root.querySelector('.slotpick-value');
        var init = root.dataset.initial;
        this.cursor = init ? new Date(init + 'T12:00:00Z') : new Date();
        this.tz = (Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC';
        this.slots = [];          // {start,end} UTC strings for the loaded month
        this.byDay = {};          // 'Y-m-d' (in tz) -> [slot,...]
        this.selectedDay = null;
        this.initTz();
        this.bind();
        this.loadMonth();
    }
    SlotPicker.prototype.initTz = function(){
        var sel = this.root.querySelector('.slotpick-tz');
        var list = COMMON_TZ.slice();
        if (list.indexOf(this.tz) < 0) { list.unshift(this.tz); }
        var self = this;
        list.forEach(function(tz){
            var o = document.createElement('option'); o.value = tz; o.textContent = tz.replace(/_/g,' ');
            if (tz === self.tz) { o.selected = true; }
            sel.appendChild(o);
        });
        sel.addEventListener('change', function(){ self.tz = sel.value; self.regroup(); self.renderMonth(); self.renderTimes(); });
    };
    SlotPicker.prototype.bind = function(){
        var self = this;
        this.root.querySelectorAll('.slotpick-nav').forEach(function(b){
            b.addEventListener('click', function(){ self.cursor.setMonth(self.cursor.getMonth() + parseInt(b.dataset.dir,10)); self.loadMonth(); });
        });
    };
    SlotPicker.prototype.monthRange = function(){
        var first = new Date(Date.UTC(this.cursor.getUTCFullYear(), this.cursor.getUTCMonth(), 1));
        var next = new Date(Date.UTC(this.cursor.getUTCFullYear(), this.cursor.getUTCMonth() + 1, 1));
        function f(d){ return d.getUTCFullYear() + '-' + String(d.getUTCMonth()+1).padStart(2,'0') + '-' + String(d.getUTCDate()).padStart(2,'0') + ' 00:00:00'; }
        return [f(first), f(next)];
    };
    SlotPicker.prototype.loadMonth = function(){
        var self = this;
        var r = this.monthRange();
        // The feed is a sessionless /api/v1 action (POST-only, no CSRF header):
        // any query string on the configured URL (e.g. ?slug=...) folds into the
        // JSON body along with the range. Slots come from data.slots.
        var qpos = this.slotsUrl.indexOf('?');
        var base = qpos === -1 ? this.slotsUrl : this.slotsUrl.slice(0, qpos);
        var body = {};
        if (qpos !== -1) {
            new URLSearchParams(this.slotsUrl.slice(qpos + 1)).forEach(function(v, k){ body[k] = v; });
        }
        body.start = r[0];
        body.end = r[1];
        joineryApi.post(base, body)
            .then(function(j){
                self.slots = (j && j.slots) ? j.slots : []; self.regroup(); self.renderMonth(); self.autoSelect();
            })
            .catch(function(){ self.slots = []; self.regroup(); self.renderMonth(); });
    };
    SlotPicker.prototype.regroup = function(){
        this.byDay = {};
        var self = this;
        this.slots.forEach(function(s){
            var d = parseUTC(s.start); if (!d) return;
            var key = ymdInTz(d, self.tz);
            (self.byDay[key] = self.byDay[key] || []).push(s);
        });
    };
    SlotPicker.prototype.autoSelect = function(){
        if (this.selectedDay && this.byDay[this.selectedDay]) { this.renderTimes(); return; }
        var keys = Object.keys(this.byDay).sort();
        this.selectedDay = keys.length ? keys[0] : null;
        this.renderMonth();
        this.renderTimes();
    };
    SlotPicker.prototype.renderMonth = function(){
        this.root.querySelector('.slotpick-month').textContent = MONTHS[this.cursor.getUTCMonth()] + ' ' + this.cursor.getUTCFullYear();
        var grid = this.root.querySelector('.slotpick-grid');
        grid.innerHTML = '';
        DOW.forEach(function(d){ var h = document.createElement('div'); h.className = 'slotpick-dow'; h.textContent = d; grid.appendChild(h); });
        var year = this.cursor.getUTCFullYear(), month = this.cursor.getUTCMonth();
        var first = new Date(Date.UTC(year, month, 1));
        var startPad = first.getUTCDay();
        var daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        for (var i = 0; i < startPad; i++) { grid.appendChild(document.createElement('div')); }
        var self = this;
        for (var day = 1; day <= daysInMonth; day++) {
            var key = year + '-' + String(month+1).padStart(2,'0') + '-' + String(day).padStart(2,'0');
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'slotpick-day'; btn.textContent = day;
            if (this.byDay[key] && this.byDay[key].length) {
                btn.className += ' has-slots';
                if (key === this.selectedDay) { btn.className += ' is-selected'; }
                (function(k){ btn.addEventListener('click', function(){ self.selectedDay = k; self.renderMonth(); self.renderTimes(); }); })(key);
            }
            grid.appendChild(btn);
        }
    };
    SlotPicker.prototype.renderTimes = function(){
        var label = this.root.querySelector('.slotpick-daylabel');
        var list = this.root.querySelector('.slotpick-timelist');
        list.innerHTML = '';
        if (!this.selectedDay || !this.byDay[this.selectedDay]) {
            label.textContent = '';
            var em = document.createElement('div'); em.className = 'slotpick-empty'; em.textContent = 'No open times this month.';
            list.appendChild(em);
            return;
        }
        var d0 = parseUTC(this.byDay[this.selectedDay][0].start);
        label.textContent = new Intl.DateTimeFormat([], {timeZone: this.tz, weekday:'long', month:'long', day:'numeric'}).format(d0);
        var self = this;
        var chosen = this.field.value;
        this.byDay[this.selectedDay].sort(function(a,b){ return a.start.localeCompare(b.start); }).forEach(function(s){
            var d = parseUTC(s.start);
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'slotpick-slot'; btn.textContent = timeInTz(d, self.tz);
            if (s.start === chosen) { btn.className += ' is-chosen'; }
            btn.addEventListener('click', function(){
                self.field.value = s.start;
                list.querySelectorAll('.slotpick-slot').forEach(function(x){ x.classList.remove('is-chosen'); });
                btn.classList.add('is-chosen');
                self.root.dispatchEvent(new CustomEvent('slotchosen', {detail: s, bubbles: true}));
            });
            list.appendChild(btn);
        });
    };

    var registry = {};
    window.__joinerySlotPickerInit = function(id){
        var el = document.getElementById(id);
        if (el && !registry[id]) { registry[id] = new SlotPicker(el); }
    };
    window.__joinerySlotPickerInit('<?php echo $pid; ?>');
})();
</script>
