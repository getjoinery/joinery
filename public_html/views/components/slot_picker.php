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
 * @version 1.3.0 - its script is assets/js/slot-picker.js (no inline script)
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

<?php echo PageScript::src('assets/js/slot-picker.js'); ?>
