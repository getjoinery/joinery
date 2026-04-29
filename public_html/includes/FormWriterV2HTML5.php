<?php
/**
 * FormWriter v2 HTML5 Implementation
 *
 * Pure HTML5 form generation with semantic markup and no CSS framework dependencies.
 * Provides accessible, standards-compliant forms that any theme can style.
 *
 * @version 2.2.0
 * @changelog 2.2.0 - Phase 2: shared AJAX script, visibility moved to base, buildCommonAttributes in renderTextInput
 * @changelog 2.1.0 - Refactored to prepare/render split: output*() in base, render*() here
 * @changelog 2.0.7 - outputCheckboxInput: support 'checked' boolean option as override (same as Bootstrap/Tailwind)
 * @changelog 2.0.6 - Added public textbox() method with Trumbowyg rich text editor support (htmlmode option)
 * @changelog 2.0.5 - Fixed placeholder to only show when field is empty (matches Bootstrap behavior)
 * @changelog 2.0.4 - Added inline flex layout for time and datetime inputs to display fields side-by-side
 * @changelog 2.0.3 - Changed fieldset/legend to div/label for radio and checkbox groups to match Bootstrap styling
 * @changelog 2.0.2 - Fixed outputRadioInput to iterate through options array and display all radio buttons
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2HTML5 extends FormWriterV2Base {

    /**
     * Render a text input field with HTML5 markup
     *
     * @param array $data Prepared field data from prepareTextData()
     * @return string HTML output
     */
    protected function renderTextInput($data) {
        $class = $data['class'] ?: 'form-control';
        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($data['id']) . '" class="form-label">' . htmlspecialchars($data['label']);
            if ($data['required']) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<input type="' . htmlspecialchars($data['type']) . '"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        if ($data['pattern']) {
            $html .= ' pattern="' . htmlspecialchars($data['pattern']) . '"';
        }
        $html .= $this->buildCommonAttributes($data, [
            'min' => 'min', 'max' => 'max', 'step' => 'step',
            'minlength' => 'minlength', 'maxlength' => 'maxlength',
        ]);
        $html .= $this->buildErrorAttributes($data);

        $html .= '>';

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a password input field
     *
     * @param array $data Prepared field data from preparePasswordData()
     * @return string HTML output
     */
    protected function renderPasswordInput($data) {
        // Password is essentially text with type="password"; strength_meter not used in HTML5
        return $this->renderTextInput($data);
    }

    /**
     * Render a number input field
     *
     * @param array $data Prepared field data from prepareNumberData()
     * @return string HTML output
     */
    protected function renderNumberInput($data) {
        return $this->renderTextInput($data);
    }

    /**
     * Render a dropdown select field
     *
     * @param array $data Prepared field data from prepareDropData()
     * @return string HTML output
     */
    protected function renderDropInput($data) {
        $class = $data['class'] ?: 'form-control';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($data['label']);
            if ($data['required']) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<select';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if ($data['multiple']) {
            $html .= ' multiple';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['required']) {
            $html .= ' required';
        }
        if ($data['onchange']) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        }

        if ($data['has_errors']) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
        }

        $html .= '>';

        if ($data['empty_option'] !== null) {
            $html .= '<option value="">' . htmlspecialchars($data['empty_option']) . '</option>';
        }

        foreach ($data['options_list'] as $opt_value => $opt_label) {
            $html .= '<option value="' . htmlspecialchars($opt_value) . '"';
            if ((string)$data['value'] === (string)$opt_value) {
                $html .= ' selected';
            }
            $html .= '>' . htmlspecialchars($opt_label) . '</option>';
        }

        $html .= '</select>';

        // AJAX dropdown support
        if ($data['ajaxendpoint']) {
            $html .= $this->buildAjaxSelectScript($id, $data['ajaxendpoint']);
        }

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single checkbox input
     *
     * @param array $data Prepared field data from prepareCheckboxData()
     * @return string HTML output
     */
    protected function renderCheckboxInput($data) {
        $class = $data['class'];
        $id = $data['id'];

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-check">';

        $html .= '<input type="checkbox"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="form-check-input' . ($class ? ' ' . htmlspecialchars($class) : '') . '"';
        $html .= ' value="' . htmlspecialchars($data['checked_value']) . '"';

        if ($data['is_checked']) {
            $html .= ' checked';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['required']) {
            $html .= ' required';
        }
        if ($data['onchange']) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        }

        if ($data['has_errors']) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
        }

        $html .= '>';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-check-label">' . htmlspecialchars($data['label']) . '</label>';
        }

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render radio button group
     *
     * @param array $data Prepared field data from prepareRadioData()
     * @return string HTML output
     */
    protected function renderRadioInput($data) {
        $class = $data['class'];

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label class="form-label">' . htmlspecialchars($data['label']);
            if ($data['required']) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        foreach ($data['options_list'] as $opt_value => $opt_label) {
            $id = $data['name'] . '_' . $opt_value;

            $html .= '<div class="form-check">';
            $html .= '<input type="radio"';
            $html .= ' name="' . htmlspecialchars($data['name']) . '"';
            $html .= ' id="' . htmlspecialchars($id) . '"';
            $html .= ' class="form-check-input' . ($class ? ' ' . htmlspecialchars($class) : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$data['value'] === (string)$opt_value) {
                $html .= ' checked';
            }
            if ($data['disabled']) {
                $html .= ' disabled';
            }
            if ($data['required']) {
                $html .= ' required';
            }
            if ($data['onchange']) {
                $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
            }

            if ($data['has_errors']) {
                $html .= ' aria-invalid="true"';
                $html .= ' aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
            }

            $html .= '>';

            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-check-label">' . htmlspecialchars($opt_label) . '</label>';

            $html .= '</div>';
        }

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Output a list of checkboxes — HTML5 uses simple value-based checking
     * Overrides base to use original HTML5 logic (value key, not checked key)
     *
     * @param string $name Field name
     * @param string $label Group label
     * @param array $options Field options
     */
    /**
     * Render checkbox list with HTML5 styling
     *
     * @param array $data Prepared data from prepareCheckboxListData()
     * @return string HTML output
     */
    protected function renderCheckboxList($data) {
        $class = $data['class'] ?? '';

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label class="form-label">' . htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        foreach ($data['options_list'] as $opt_value => $opt_label) {
            $checkbox_id = $data['name'] . '_' . $opt_value;

            $html .= '<div class="form-check">';
            $html .= '<input type="' . htmlspecialchars($data['type']) . '"';
            $html .= ' name="' . htmlspecialchars($data['name']) . '[]"';
            $html .= ' id="' . htmlspecialchars($checkbox_id) . '"';
            $html .= ' class="form-check-input' . ($class ? ' ' . htmlspecialchars($class) : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if (in_array((string)$opt_value, array_map('strval', $data['checked']))) {
                $html .= ' checked';
            }
            if (in_array($opt_value, $data['disabled'])) {
                $html .= ' disabled';
            }

            $html .= '>';
            $html .= '<label for="' . htmlspecialchars($checkbox_id) . '" class="form-check-label">' . htmlspecialchars($opt_label) . '</label>';
            $html .= '</div>';
        }

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a date input field
     *
     * @param array $data Prepared field data from prepareDateData()
     * @return string HTML output
     */
    protected function renderDateInput($data) {
        $class = $data['class'] ?: 'form-control';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($data['label']);
            if ($data['required']) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if ($data['readonly']) {
            $html .= ' readonly';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['required']) {
            $html .= ' required';
        }
        if (isset($data['min'])) {
            $html .= ' min="' . htmlspecialchars($data['min']) . '"';
        }
        if (isset($data['max'])) {
            $html .= ' max="' . htmlspecialchars($data['max']) . '"';
        }
        if ($data['onchange']) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        }

        if ($data['has_errors']) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
        }

        $html .= '>';

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render time input with HTML5 styling
     *
     * @param array $data Prepared data from prepareTimeData()
     * @return string HTML output
     */
    protected function renderTimeInput($data) {
        $class = $data['class'] ?: 'form-control';
        $id = $data['id'];
        $format = '12hour';  // HTML5 always uses 12-hour selects

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<input type="hidden" name="' . htmlspecialchars($data['name']) . '" id="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($data['value']) . '">';

        // The data-time-* attributes wire this widget to the shared
        // outputTimeInputJavaScript() handler, which keeps the hidden input
        // in sync with the three select dropdowns. Without these, the dropdowns
        // (which intentionally have no `name` attribute) would never propagate
        // their values to the form POST.
        $html .= '<div class="time-input-group" style="display: flex; align-items: center; gap: 8px;"'
              . ' data-time-hour="' . htmlspecialchars($id . '_hour') . '"'
              . ' data-time-minute="' . htmlspecialchars($id . '_minute') . '"'
              . ' data-time-ampm="' . htmlspecialchars($id . '_ampm') . '"'
              . ' data-time-hidden="' . htmlspecialchars($id) . '">';

        $html .= '<select id="' . htmlspecialchars($id) . '_hour" class="' . htmlspecialchars($class) . '" style="width: auto;">';
        $html .= '<option value="">HH</option>';
        for ($i = 1; $i <= 12; $i++) {
            $selected = ($data['hour'] == $i) ? ' selected' : '';
            $html .= '<option value="' . $i . '"' . $selected . '>' . str_pad($i, 2, '0', STR_PAD_LEFT) . '</option>';
        }
        $html .= '</select>';

        $html .= '<span class="time-separator" style="font-weight: bold;">:</span>';

        $html .= '<select id="' . htmlspecialchars($id) . '_minute" class="' . htmlspecialchars($class) . '" style="width: auto;">';
        $html .= '<option value="">MM</option>';
        for ($i = 0; $i < 60; $i++) {
            $selected = ($data['minute'] == $i) ? ' selected' : '';
            $html .= '<option value="' . $i . '"' . $selected . '>' . str_pad($i, 2, '0', STR_PAD_LEFT) . '</option>';
        }
        $html .= '</select>';

        $html .= '<select id="' . htmlspecialchars($id) . '_ampm" class="' . htmlspecialchars($class) . '" style="width: auto;">';
        $html .= '<option value="AM"' . ($data['ampm'] === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($data['ampm'] === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';

        $html .= '</div>';

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        // Append the shared time JS
        $html .= $this->outputTimeInputJavaScript($id);

        return $html;
    }

    /**
     * Render a datetime input field
     *
     * @param array $data Prepared field data from prepareDateTimeData()
     * @return string HTML output
     */
    protected function renderDateTimeInput($data) {
        $class = $data['class'] ?: 'form-control';
        $date_name = $data['date_name'];
        $time_name = $data['time_name'];
        $date_id = $date_name;
        $time_id = $time_name;

        $html = '';
        $html .= '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label class="form-label">' . htmlspecialchars($data['label']);
            if ($data['readonly']) {
                // readonly has no required indicator
            }
            $html .= '</label>';
        }

        $html .= '<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">';

        $date_class = $class;
        if (!empty($data['date_errors'])) {
            $date_class .= ' is-invalid';
        }
        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($date_name) . '"';
        $html .= ' id="' . htmlspecialchars($date_id) . '"';
        $html .= ' class="' . htmlspecialchars($date_class) . '"';
        $html .= ' value="' . htmlspecialchars($data['date_value']) . '"';
        $html .= ' style="width: auto;"';
        if ($data['readonly']) {
            $html .= ' readonly';
        }
        $html .= '>';

        $time_hour_id = $time_id . '_hour';
        $time_minute_id = $time_id . '_minute';
        $time_ampm_id = $time_id . '_ampm';

        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($class) . '" style="width: 70px;"';
        $html .= ' min="1" max="12" placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($data['hour']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        $html .= '>';

        $html .= '<strong style="line-height: 1;">:</strong>';

        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_minute_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_minute') . '"';
        $html .= ' class="' . htmlspecialchars($class) . '" style="width: 70px;"';
        $html .= ' min="0" max="59" placeholder="MM"';
        $html .= ' value="' . htmlspecialchars($data['minute']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        $html .= '>';

        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($time_ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_ampm') . '"';
        $html .= ' class="' . htmlspecialchars($class) . '" style="width: auto;"';
        if ($data['readonly']) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($data['ampm'] === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($data['ampm'] === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';

        $html .= '</div>';

        if (!empty($data['date_errors'])) {
            $html .= '<div class="form-error">';
            foreach ($data['date_errors'] as $error) {
                $html .= '<div>' . htmlspecialchars($error) . '</div>';
            }
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a file input field
     *
     * @param array $data Prepared field data from prepareFileData()
     * @return string HTML output
     */
    protected function renderFileInput($data) {
        $class = $data['class'] ?: 'form-control';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($data['label']);
            if ($data['required']) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<input type="file"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if ($data['accept']) {
            $html .= ' accept="' . htmlspecialchars($data['accept']) . '"';
        }
        if ($data['multiple']) {
            $html .= ' multiple';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['required']) {
            $html .= ' required';
        }
        if ($data['onchange']) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        }

        if ($data['has_errors']) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
        }

        $html .= '>';

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a hidden input field
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
     * Render a submit button
     *
     * @param array $data Prepared field data from prepareSubmitData()
     * @return string HTML output
     */
    protected function renderSubmitButton($data) {
        $class = $data['class'] ?: 'btn btn-primary';
        $id = $data['id'];

        $html = '<button type="submit"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['onclick']) {
            $html .= ' onclick="' . htmlspecialchars($data['onclick']) . '"';
        }

        $html .= '>';
        $html .= htmlspecialchars($data['label']);
        $html .= '</button>';

        return $html;
    }

    /**
     * Public textbox method - handles both plain and rich text
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (including 'htmlmode' => 'yes' for rich text)
     */
    public function textbox($name, $label = '', $options = []) {
        $rows = $options['rows'] ?? 5;
        $cols = $options['cols'] ?? 80;
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $placeholder = $options['placeholder'] ?? '';
        $htmlmode = $options['htmlmode'] ?? 'no';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        if ($htmlmode === 'yes') {
            // Load Trumbowyg CSS
            echo '<link rel="stylesheet" href="/assets/vendor/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">';
            // Conditionally load jQuery (if not already present) then load Trumbowyg
            echo '<script type="text/javascript">
            (function() {
                var editorId = "' . htmlspecialchars($id) . '";
                var trumbowygScripts = [
                    "/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"
                ];

                function initTrumbowyg() {
                    if (typeof jQuery.fn.trumbowyg === "function") {
                        jQuery("#" + editorId).trumbowyg({
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
        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<textarea';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . ($htmlmode === 'yes' ? ' html_editable' : '') . '"';
        $html .= ' rows="' . intval($rows) . '"';
        $html .= ' cols="' . intval($cols) . '"';

        if ($placeholder && !$value) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }

        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }

        if ($has_errors) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
        }

        $html .= '>';
        $html .= htmlspecialchars($value);
        $html .= '</textarea>';

        if ($has_errors) {
            $html .= '<div id="' . htmlspecialchars($name) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($this->errors[$name] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-help">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        echo $html;
    }

    /**
     * Render a rich text editor (textbox) — delegates to public textbox()
     * Since HTML5 overrides the public textbox() method, this is only called
     * via outputTextbox() which itself is overridden by the public textbox().
     *
     * @param array $data Prepared data from prepareTextboxData()
     */
    protected function renderTextbox($data) {
        // Delegate to the public textbox() method, capturing its echoed output
        $options = [
            'value' => $data['value'],
            'class' => $data['class'],
            'rows' => $data['rows'],
            'htmlmode' => $data['htmlmode'] ? 'yes' : 'no',
            'readonly' => $data['readonly'],
            'disabled' => $data['disabled'],
        ];
        ob_start();
        $this->textbox($data['name'], $data['label'], $options);
        return ob_get_clean();
    }

    /**
     * Render an image input field with preview
     *
     * @param array $data Prepared field data from prepareImageData()
     * @return string HTML output
     */
    protected function renderImageInput($data) {
        $optionvals = $data['images'];
        $value = $data['value'];
        $showdefault = true;
        $forcestrict = true;
        $id = $data['name'];

        $output = '';

        $output .= '
        <style>
        .image-dropdown {
            border:1px solid #ccc; width:100%; max-width:600px; height:80px; overflow:hidden;
            transition: height 0.1s;
        }
        .image-dropdown:hover {
            height:400px; overflow-y:scroll;
            transition: height 0.5s;
        }
        .image-dropdown input {
            position:absolute;top:0;left:0;opacity:0;
        }
        .image-dropdown label {
            display:none; margin:2px; height:80px; opacity:0.8; overflow:hidden;
        }
        .image-dropdown:hover label{
            display:block;
        }
        .image-dropdown input:checked + label {
            opacity:1 !important; font-weight: bold; display:block;
        }
        .dropimagewidth {
            display: inline-block;
            width: 80px;
            padding-right: 5px;
        }
        </style>
        ';

        $output .= '<div id="' . htmlspecialchars($id) . '_container" class="form-group">';
        $output .= '<label class="form-label">' . htmlspecialchars($data['label']) . '</label>';
        $output .= '<div class="image-dropdown">';

        if ($showdefault) {
            if (is_null($value) || $value === '') {
                $output .= '<input type="radio" id="default_id" name="' . htmlspecialchars($id) . '" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/assets/images/image_placeholder_thumbnail.png"></span> No Image</label>';
            } else {
                $output .= '<input type="radio" id="default_id" name="' . htmlspecialchars($id) . '" value="" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/assets/images/image_placeholder_thumbnail.png"></span> No Image</label>';
            }
        }

        foreach ($optionvals as $optval => $optlabel) {
            if ($forcestrict && $value === $optval) {
                $output .= '<input type="radio" id="' . htmlspecialchars($optval) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($optval) . '" checked="checked" /><label for="' . htmlspecialchars($optval) . '_id"> ' . $optlabel . '</label>';
            } elseif ($value == $optval) {
                $output .= '<input type="radio" id="' . htmlspecialchars($optval) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($optval) . '" checked="checked" /><label for="' . htmlspecialchars($optval) . '_id"> ' . $optlabel . '</label>';
            } else {
                $output .= '<input type="radio" id="' . htmlspecialchars($optval) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($optval) . '" /><label for="' . htmlspecialchars($optval) . '_id"> ' . $optlabel . '</label>';
            }
        }

        $output .= '</div></div>';

        return $output;
    }

    /**
     * Render a textarea field
     *
     * @param array $data Prepared field data from prepareTextareaData()
     * @return string HTML output
     */
    protected function renderTextarea($data) {
        $class = $data['class'] ?: 'form-control';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($data['name']) . '_container" class="form-group">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($data['label']);
            if ($data['required']) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<textarea';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' rows="' . intval($data['rows']) . '"';
        $html .= ' cols="' . intval($data['cols']) . '"';

        if ($data['readonly']) {
            $html .= ' readonly';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['required']) {
            $html .= ' required';
        }
        // Note: HTML5 original textarea used !empty($options['placeholder']) check (not conditional on value)
        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        if (isset($data['minlength'])) {
            $html .= ' minlength="' . intval($data['minlength']) . '"';
        }
        if (isset($data['maxlength'])) {
            $html .= ' maxlength="' . intval($data['maxlength']) . '"';
        }
        if ($data['onchange']) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
        }

        if ($data['has_errors']) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
        }

        $html .= '>';
        $html .= htmlspecialchars($data['value']);
        $html .= '</textarea>';

        if ($data['has_errors']) {
            $html .= '<div id="' . htmlspecialchars($data['name']) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($data['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="form-help">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }
}
