<?php
/**
 * FormWriter v2 Bootstrap Implementation
 *
 * Bootstrap-themed form field output
 *
 * @version 2.3.0
 * @changelog 2.3.0 - Phase 2: shared AJAX script, visibility moved to base, buildCommonAttributes in renderTextInput
 * @changelog 2.2.0 - Refactored to prepare/render split: output*() in base, render*() here
 * @changelog 2.1.1 - outputTextInput: support 'type' option; outputCheckboxInput: unified checked logic; outputDateInput: isset for min/max; outputPasswordInput: conditional placeholder
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2Bootstrap extends FormWriterV2Base {

    /**
     * Track if Trumbowyg script has already been output to prevent double initialization
     */
    private static $trumbowyg_script_output = false;

    // ── Render methods (pure HTML generation) ────────────────────────────────

    /**
     * Render a text input field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareTextData()
     * @return string HTML output
     */
    protected function renderTextInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        if ($data['prepend']) {
            $html .= '<div class="input-group">';
            $html .= '<div class="input-group-text">' . htmlspecialchars($data['prepend']) . '</div>';
        }

        $html .= '<input type="' . htmlspecialchars($data['type']) . '"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        $html .= $this->buildCommonAttributes($data);

        $html .= '>';

        if ($data['prepend']) {
            $html .= '</div>';  // Close input-group
        }

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a number input field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareNumberData()
     * @return string HTML output
     */
    protected function renderNumberInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<input type="number"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';
        $html .= ' inputmode="numeric"';

        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        if (isset($data['min'])) {
            $html .= ' min="' . htmlspecialchars($data['min']) . '"';
        }
        if (isset($data['max'])) {
            $html .= ' max="' . htmlspecialchars($data['max']) . '"';
        }
        if (isset($data['step'])) {
            $html .= ' step="' . htmlspecialchars($data['step']) . '"';
        }
        if (!empty($data['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($data['required'])) {
            $html .= ' required';
        }

        $html .= '>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a password input field with Bootstrap styling
     *
     * @param array $data Prepared field data from preparePasswordData()
     * @return string HTML output
     */
    protected function renderPasswordInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<input type="password"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        if (!empty($data['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($data['autocomplete'])) {
            $html .= ' autocomplete="' . htmlspecialchars($data['autocomplete']) . '"';
        }

        $html .= '>';

        if (!empty($data['strength_meter'])) {
            $html .= '<div class="password-strength-meter mt-2">';
            $html .= '<div class="progress" style="height: 5px;">';
            $html .= '<div class="progress-bar" role="progressbar" style="width: 0%"></div>';
            $html .= '</div>';
            $html .= '<small class="strength-text text-muted"></small>';
            $html .= '</div>';
        }

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a select dropdown field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareDropData()
     * @return string HTML output
     */
    protected function renderDropInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<select';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if (!empty($data['multiple'])) {
            $html .= ' multiple';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($data['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        }

        $html .= '>';

        if ($data['empty_option'] !== null) {
            $html .= '<option value="">' . htmlspecialchars($data['empty_option']) . '</option>';
        }

        $compare_value = $data['value'];
        foreach ($data['options_list'] as $opt_value => $opt_label) {
            $html .= '<option value="' . htmlspecialchars($opt_value) . '"';
            if ((string)$compare_value === (string)$opt_value) {
                $html .= ' selected';
            }
            $html .= '>' . htmlspecialchars($opt_label) . '</option>';
        }

        $html .= '</select>';

        // AJAX dropdown support
        if (!empty($data['ajaxendpoint'])) {
            $html .= $this->buildAjaxSelectScript($data['id'], $data['ajaxendpoint']);
        }

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a checkbox input field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareCheckboxData()
     * @return string HTML output
     */
    protected function renderCheckboxInput($data) {
        $html = '';
        $html .= '<div class="form-group mb-3">';
        $html .= '<div class="form-check">';

        $html .= '<input type="checkbox"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="form-check-input' . ($data['has_errors'] ? ' is-invalid' : '') . '"';
        $html .= ' value="' . htmlspecialchars($data['checked_value']) . '"';

        if ($data['is_checked']) {
            $html .= ' checked';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($data['label']) {
            $html .= '<label class="form-check-label" for="' . htmlspecialchars($data['id']) . '">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '</div>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render radio input fields with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareRadioData()
     * @return string HTML output
     */
    protected function renderRadioInput($data) {
        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label>' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<div class="errorplacement">';

        foreach ($data['options_list'] as $opt_value => $opt_label) {
            $id = $data['name'] . '_' . $opt_value;

            $html .= '<div class="form-check">';
            $html .= '<input type="radio"';
            $html .= ' name="' . htmlspecialchars($data['name']) . '"';
            $html .= ' id="' . htmlspecialchars($id) . '"';
            $html .= ' class="form-check-input' . ($data['has_errors'] ? ' is-invalid' : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$data['value'] === (string)$opt_value) {
                $html .= ' checked';
            }
            if (!empty($data['disabled'])) {
                $html .= ' disabled';
            }

            $html .= '>';

            $html .= '<label class="form-check-label" for="' . htmlspecialchars($id) . '">';
            $html .= htmlspecialchars($opt_label);
            $html .= '</label>';

            $html .= '</div>';
        }

        $html .= '</div>'; // End errorplacement

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a date input field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareDateData()
     * @return string HTML output
     */
    protected function renderDateInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if (isset($data['min'])) {
            $html .= ' min="' . htmlspecialchars($data['min']) . '"';
        }
        if (isset($data['max'])) {
            $html .= ' max="' . htmlspecialchars($data['max']) . '"';
        }
        if (!empty($data['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a time input field with Bootstrap styling
     * (stub — outputTimeInput is overridden and handles everything directly)
     *
     * @param array $data Prepared field data from prepareTimeData()
     * @return string HTML output
     */
    protected function renderTimeInput($data) {
        $class = $data['class'] ?: 'form-control';
        $id = $data['id'];
        $hour_id = $id . '_hour';
        $minute_id = $id . '_minute';
        $ampm_id = $id . '_ampm';

        $input_class = $class;
        if ($data['has_errors']) {
            $input_class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label>' . htmlspecialchars($data['label']) . '</label>';
        }

        // The data-time-* attributes wire this widget to the shared
        // outputTimeInputJavaScript() handler, which keeps the hidden input in
        // sync with the per-part inputs so consumers can read just
        // $post_vars[$field_name] regardless of widget flavor.
        $html .= '<div class="row g-2"'
              . ' data-time-hour="' . htmlspecialchars($hour_id) . '"'
              . ' data-time-minute="' . htmlspecialchars($minute_id) . '"'
              . ' data-time-ampm="' . htmlspecialchars($ampm_id) . '"'
              . ' data-time-hidden="' . htmlspecialchars($id) . '">';

        // Hour input
        $html .= '<div class="col-auto">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '" style="width: 120px;"';
        $html .= ' min="1" max="12"';
        $html .= ' placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($data['hour']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        if ($data['disabled']) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';

        // Colon separator
        $html .= '<div class="col-auto" style="display: flex; align-items: center;">';
        $html .= '<strong>:</strong>';
        $html .= '</div>';

        // Minute input
        $html .= '<div class="col-auto">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($minute_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_minute') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '" style="width: 120px;"';
        $html .= ' min="0" max="59"';
        $html .= ' placeholder="MM"';
        $html .= ' value="' . htmlspecialchars($data['minute']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        if ($data['disabled']) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';

        // AM/PM selector
        $html .= '<div class="col-auto">';
        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_ampm') . '"';
        $html .= ' class="form-select"';
        if ($data['readonly']) $html .= ' disabled';
        if ($data['disabled']) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($data['ampm'] === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($data['ampm'] === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';
        $html .= '</div>';

        // Hidden field to store the actual time value
        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';
        $html .= '>';

        $html .= '</div>';

        if ($data['has_errors']) {
            $html .= '<div class="invalid-feedback d-block">';
            foreach ($data['errors'] as $error) {
                $html .= htmlspecialchars($error) . '<br>';
            }
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        // Capture and append the shared time input JS
        ob_start();
        $this->outputTimeInputJavaScript();
        $html .= ob_get_clean();

        // Sync div for JS
        $html .= '<div data-time-hour="' . htmlspecialchars($hour_id) . '"';
        $html .= ' data-time-minute="' . htmlspecialchars($minute_id) . '"';
        $html .= ' data-time-ampm="' . htmlspecialchars($ampm_id) . '"';
        $html .= ' data-time-hidden="' . htmlspecialchars($id) . '"';
        $html .= ' style="display:none;"></div>';

        return $html;
    }

    /**
     * Render separate date and time input fields with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareDateTimeData()
     * @return string HTML output
     */
    protected function renderDateTimeInput($data) {
        $class = $data['class'] ?: 'form-control';

        $date_name = $data['date_name'];
        $time_name = $data['time_name'];
        $date_value = $data['date_value'];
        $time_value = $data['time_value'];
        $hour = $data['hour'];
        $minute = $data['minute'];
        $ampm = $data['ampm'];

        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label>' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';

        // Date input
        $date_class = $class;
        if (!empty($data['date_errors'])) {
            $date_class .= ' is-invalid';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($date_name) . '"';
        $html .= ' id="' . htmlspecialchars($data['date_name']) . '"';
        $html .= ' class="' . htmlspecialchars($date_class) . '"';
        $html .= ' value="' . htmlspecialchars($date_value) . '"';
        if (!empty($data['readonly'])) {
            $html .= ' readonly';
        }
        $html .= '>';

        if (!empty($data['date_errors'])) {
            foreach ($data['date_errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        $html .= '</div>';
        $html .= '<div class="col-md-6">';

        // Time input - AM/PM format
        $time_class = $class;
        if (!empty($data['time_errors'])) {
            $time_class .= ' is-invalid';
        }

        $time_hour_id = $time_name . '_hour';
        $time_minute_id = $time_name . '_minute';
        $time_ampm_id = $time_name . '_ampm';

        $html .= '<div class="row g-2">';
        $html .= '<div class="col-auto">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 120px;"';
        $html .= ' min="1" max="12" placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($data['readonly'])) $html .= ' readonly';
        $html .= '>';
        $html .= '</div>';
        $html .= '<div class="col-auto" style="display: flex; align-items: center;"><strong>:</strong></div>';
        $html .= '<div class="col-auto">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_minute_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_minute') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 120px;"';
        $html .= ' min="0" max="59" placeholder="MM"';
        $html .= ' value="' . htmlspecialchars($minute) . '"';
        if (!empty($data['readonly'])) $html .= ' readonly';
        $html .= '>';
        $html .= '</div>';
        $html .= '<div class="col-auto">';
        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($time_ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_ampm') . '"';
        $html .= ' class="form-select"';
        if (!empty($data['readonly'])) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($ampm === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($ampm === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<input type="hidden"';
        $html .= ' id="' . htmlspecialchars($time_name) . '"';
        $html .= ' value="' . htmlspecialchars($time_value) . '"';
        $html .= '>';

        if (!empty($data['time_errors'])) {
            $html .= '<div class="invalid-feedback d-block">';
            foreach ($data['time_errors'] as $error) {
                $html .= htmlspecialchars($error) . '<br>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a file input field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareFileData()
     * @return string HTML output
     */
    protected function renderFileInput($data) {
        $class = $data['class'] ?: 'form-control-file';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<input type="file"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if (!empty($data['accept'])) {
            $html .= ' accept="' . htmlspecialchars($data['accept']) . '"';
        }
        if (!empty($data['multiple'])) {
            $html .= ' multiple';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a hidden input field
     * Note: Bootstrap override uses $options['value'] ?? '' (no values[] fallback)
     *
     * @param array $data Prepared field data from prepareHiddenData()
     * @return string HTML output
     */
    protected function renderHiddenInput($data) {
        $html = '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';
        $html .= '>';
        return $html;
    }

    /**
     * Render a submit button with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareSubmitData()
     * @return string HTML output
     */
    protected function renderSubmitButton($data) {
        $class = $data['class'] ?: 'btn btn-primary';

        $html = '<button type="submit"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($data['onclick'])) {
            $html .= ' onclick="' . htmlspecialchars($data['onclick']) . '"';
        }

        $html .= '>';
        $html .= htmlspecialchars($data['label']);
        $html .= '</button>';

        return $html;
    }

    /**
     * Render a textarea field with Bootstrap styling
     *
     * @param array $data Prepared field data from prepareTextareaData()
     * @return string HTML output
     */
    protected function renderTextarea($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<textarea';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' rows="' . intval($data['rows']) . '"';
        $html .= ' cols="' . intval($data['cols']) . '"';

        if (!empty($data['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($data['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';
        $html .= htmlspecialchars($data['value']);
        $html .= '</textarea>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a textbox (rich text editor) field
     * Delegates to the public textbox() method (which handles Trumbowyg loading)
     *
     * @param array $data Prepared field data from prepareTextboxData()
     */
    protected function renderTextbox($data) {
        // Delegate to the public textbox() method, capturing its echoed output
        $options = [
            'rows' => $data['rows'],
            'value' => $data['value'],
            'htmlmode' => $data['htmlmode'] ? 'yes' : 'no',
        ];
        ob_start();
        $this->textbox($data['name'], $data['label'], $options);
        return ob_get_clean();
    }

    /**
     * Render an image input field (placeholder implementation)
     *
     * @param array $data Prepared field data from prepareImageData()
     * @return string HTML output
     */
    protected function renderImageInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group mb-3">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="image-input-hidden"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';
        $html .= '>';

        if ($data['value']) {
            $html .= '<div class="mt-2">';
            $html .= '<img src="' . htmlspecialchars($data['value']) . '" alt="Preview" style="max-width: 200px; max-height: 200px;" class="img-thumbnail">';
            $html .= '</div>';
        }

        $html .= '<div class="mt-2">';
        $html .= '<button type="button" class="btn btn-secondary btn-sm" onclick="alert(\'Image selection not implemented\')">';
        $html .= 'Select Image';
        $html .= '</button>';
        $html .= '</div>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($data['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a checkbox list (stub — outputCheckboxList is overridden)
     *
     * @param array $data Prepared field data from prepareCheckboxListData()
     * @return string HTML output
     */
    protected function renderCheckboxList($data) {
        $id = $data['id'];

        if (empty($data['options_list'])) {
            return '<div class="alert alert-warning">No options available for ' . htmlspecialchars($data['name']) . '</div>';
        }

        $html = '';
        $html .= '<div id="' . htmlspecialchars($id) . '_container" class="mb-3 errorplacement">';
        if ($data['label']) {
            $html .= '<label class="form-label">' . htmlspecialchars($data['label']) . '</label>';
        }

        foreach ($data['options_list'] as $key => $value) {
            $uniqid = $id . '_' . htmlspecialchars($key);
            $is_checked = in_array($key, $data['checked']) ? 'checked="checked"' : '';
            $is_disabled = in_array($key, $data['disabled']) ? 'disabled="disabled"' : '';

            if (in_array($key, $data['readonly'])) {
                if (in_array($key, $data['checked'])) {
                    $html .= '<input type="hidden" name="' . htmlspecialchars($data['name']) . '[]" value="' . htmlspecialchars($key) . '" />';
                }
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" type="' . htmlspecialchars($data['type']) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($data['name']) . '[]" value="' . htmlspecialchars($key) . '" ' . $is_checked . ' disabled="disabled" />';
                $html .= '<label class="form-check-label" for="' . htmlspecialchars($uniqid) . '">' . htmlspecialchars($value) . '</label>';
                $html .= '</div>';
            } else {
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" type="' . htmlspecialchars($data['type']) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($data['name']) . '[]" value="' . htmlspecialchars($key) . '" ' . $is_checked . ' ' . $is_disabled . ' />';
                $html .= '<label class="form-check-label" for="' . htmlspecialchars($uniqid) . '">' . htmlspecialchars($value) . '</label>';
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    // ── Public overrides (unchanged from original) ───────────────────────────

    /**
     * Output a textarea field with optional rich text editor (Trumbowyg)
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (rows, value, placeholder, htmlmode, etc)
     */
    public function textbox($name, $label = '', $options = []) {
        $rows = $options['rows'] ?? 5;
        $cols = $options['cols'] ?? 80;
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $placeholder = $options['placeholder'] ?? '';
        $htmlmode = $options['htmlmode'] ?? 'no';

        if ($htmlmode === 'yes' && !self::$trumbowyg_script_output) {
            self::$trumbowyg_script_output = true;
            // Load Trumbowyg CSS
            echo '<link rel="stylesheet" href="/assets/vendor/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">';
            // Conditionally load jQuery (if not already present) then load Trumbowyg
            echo '<script type="text/javascript">
            (function() {
                var trumbowygScripts = [
                    "/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"
                ];

                function initTrumbowyg() {
                    if (typeof jQuery.fn.trumbowyg === "function") {
                        jQuery(".html_editable").trumbowyg({
                            svgPath: "/assets/vendor/Trumbowyg-2-26/dist/ui/icons.svg",
                            autogrow: false,
                            autogrowOnEnter: false,
                            btns: [
                                ["viewHTML"],
                                ["undo", "redo"],
                                ["formatting"],
                                ["strong", "em", "del"],
                                ["superscript", "subscript"],
                                ["link"],
                                ["insertImage"],
                                ["preformatted"],
                                ["justifyLeft", "justifyCenter", "justifyRight", "justifyFull"],
                                ["unorderedList", "orderedList"],
                                ["horizontalRule"],
                                ["removeformat"],
                                ["fullscreen"]
                            ],
                            semantic: {
                                "div": "div"
                            },
                            plugins: {
                                allowTagsFromPaste: {
                                    allowedTags: ["p", "br", "blockquote", "b", "i", "strong", "em", "ul", "li", "ol", "a", "code", "pre", "h1", "h2", "h3", "h4", "h5", "embed", "table", "tr", "td", "th", "img", "video"]
                                }
                            }
                        });
                        jQuery(".trumbowyg-editor").attr("name", "trumbobox");
                    }
                }

                // Load a script and call callback when done
                function loadScript(url, callback) {
                    var script = document.createElement("script");
                    script.type = "text/javascript";
                    script.src = url;
                    script.onload = callback;
                    script.onerror = function() {
                        console.error("Failed to load script: " + url);
                    };
                    document.head.appendChild(script);
                }

                // Load scripts sequentially
                function loadScriptsSequentially(scripts, index, callback) {
                    if (index >= scripts.length) {
                        callback();
                        return;
                    }
                    loadScript(scripts[index], function() {
                        loadScriptsSequentially(scripts, index + 1, callback);
                    });
                }

                // Main initialization
                function initEditor() {
                    // Temporarily disable module detection to force browser global approach
                    var originalDefine = window.define;
                    var originalExports = window.exports;
                    delete window.define;
                    delete window.exports;

                    loadScriptsSequentially(trumbowygScripts, 0, function() {
                        // Restore module detection
                        if (originalDefine) window.define = originalDefine;
                        if (originalExports) window.exports = originalExports;
                        initTrumbowyg();
                    });
                }

                // Check if jQuery is loaded, if not load it first
                if (typeof jQuery === "undefined") {
                    loadScript("https://code.jquery.com/jquery-3.7.1.min.js", function() {
                        if (document.readyState === "loading") {
                            document.addEventListener("DOMContentLoaded", initEditor);
                        } else {
                            initEditor();
                        }
                    });
                } else {
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", initEditor);
                    } else {
                        initEditor();
                    }
                }
            })();
            </script>';
            echo '<style>
            .trumbowyg-box,
            .trumbowyg-editor,
            .trumbowyg-textarea {
                height: 500px;
            }
            .trumbowyg-box.trumbowyg-fullscreen,
            .trumbowyg-box.trumbowyg-fullscreen .trumbowyg-editor,
            .trumbowyg-box.trumbowyg-fullscreen .trumbowyg-textarea {
                height: 100%;
            }
            </style>';
        }

        // Output textarea
        echo '<div id="' . htmlspecialchars($name) . '_container" class="mb-3 errorplacement">';
        echo '<label class="form-label" for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
        echo '<textarea name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($name) . '" class="form-control' . ($htmlmode === 'yes' ? ' html_editable' : '') . '" rows="' . (int)$rows . '" cols="' . (int)$cols . '" placeholder="' . htmlspecialchars($placeholder) . '">' . htmlspecialchars($value) . '</textarea>';
        echo '</div>';
    }

    /**
     * Output an image input field (styled image dropdown with radio buttons)
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (options array of images, value, showdefault, forcestrict)
     */
    public function imageinput($name, $label = '', $options = []) {
        $optionvals = $options['options'] ?? [];
        $value = $options['value'] ?? ($this->values[$name] ?? null);
        $showdefault = $options['showdefault'] ?? true;
        $forcestrict = $options['forcestrict'] ?? true;
        $id = $name;

        $output = '';

        $output .= '
        <style>
        .image-dropdown {
            /*style the "box" in its minimzed state*/
            border:1px solid black; width:600px; height:80px; overflow:hidden;
            /*animate the dropdown collapsing*/
            transition: height 0.1s;
        }
        .image-dropdown:hover {
            /*when expanded, the dropdown will get native means of scrolling*/
            height:400px; overflow-y:scroll;
            /*animate the dropdown expanding*/
            transition: height 0.5s;
        }
        .image-dropdown input {
            /*hide the nasty default radio buttons!*/
            position:absolute;top:0;left:0;opacity:0;
        }
        .image-dropdown label {
            /*style the labels to look like dropdown options*/
            display:none; margin:2px; height:80px; opacity:0.8;  overflow:hidden;
            /*background:url("http://www.google.com/images/srpr/logo3w.png") 50% 50%;*/
        }
        .image-dropdown:hover label{
            /*this is how labels render in the "expanded" state.
             we want to see only the selected radio button in the collapsed menu,
             and all of them when expanded*/
            display:block;
        }
        .image-dropdown input:checked + label {
            /*tricky! labels immediately following a checked radio button
              (with our markup they are semantically related) should be fully opaque
              and visible even in the collapsed menu*/
            opacity:1 !important; font-weight: bold; display:block;
        }
        .dropimagewidth {
            display: inline-block;
            width: 80px;
            padding-right: 5px;
        }
        </style>
        ';

        $output .= '<div id="' . htmlspecialchars($id) . '_container" class="mb-3 errorplacement">';
        $output .= '<h5>' . htmlspecialchars($label) . '</h5>';
        $output .= '<div class="image-dropdown">';

        if ($showdefault) {
            if (is_null($value)) {
                $output .= '<input type="radio" id="default_id" name="' . htmlspecialchars($id) . '" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/assets/images/image_placeholder_thumbnail.png"></span> No Image</label>';
            } else {
                $output .= '<input type="radio" id="default_id" name="' . htmlspecialchars($id) . '" value="" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/assets/images/image_placeholder_thumbnail.png"></span> No Image</label>';
            }
        }

        // Options format: [value => label] where label contains HTML (image with <img> tag)
        foreach ($optionvals as $optval => $optlabel) {
            if ($forcestrict && $value === $optval) {
                // Note: $optlabel contains HTML (image label with <img> tag), do NOT escape it
                $output .= '<input type="radio" id="' . htmlspecialchars($optval) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($optval) . '" checked="checked" /><label for="' . htmlspecialchars($optval) . '_id"> ' . $optlabel . '</label>';
            } elseif ($value == $optval) {
                // Note: $optlabel contains HTML (image label with <img> tag), do NOT escape it
                $output .= '<input type="radio" id="' . htmlspecialchars($optval) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($optval) . '" checked="checked" /><label for="' . htmlspecialchars($optval) . '_id"> ' . $optlabel . '</label>';
            } else {
                // Note: $optlabel contains HTML (image label with <img> tag), do NOT escape it
                $output .= '<input type="radio" id="' . htmlspecialchars($optval) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($optval) . '" /><label for="' . htmlspecialchars($optval) . '_id"> ' . $optlabel . '</label>';
            }
        }

        $output .= '</div></div>';

        echo $output;
    }

}
