<?php
/**
 * FormWriter v2 Tailwind CSS Implementation
 *
 * Tailwind-themed form field rendering. Implements render*() methods only —
 * all behavioral logic (value resolution, state determination) lives in
 * FormWriterV2Base::prepare*Data() methods.
 *
 * @version 2.2.0
 * @changelog 2.2.0 - Phase 2: shared AJAX script, visibility moved to base, buildCommonAttributes in renderTextInput, renderTextbox returns string
 * @changelog 2.1.0 - Refactored to prepare/render pattern: output*() → render*()
 * @changelog 2.0.1 - Behavioral parity with HTML5/Bootstrap: value fallbacks, type option, outputNumberInput,
 *                    outputDropInput key/value order fix, outputCheckboxList key/value order fix,
 *                    outputCheckboxInput unified checked logic, isset for date min/max,
 *                    conditional placeholder, textarea cols/rows defaults, outputTextInput attribute parity
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2Tailwind extends FormWriterV2Base {

    /**
     * Render a text input field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareTextData()
     * @return string HTML output
     */
    protected function renderTextInput($data) {
        $class = $data['class'] ?: 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<input type="' . htmlspecialchars($data['type']) . '"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
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

        $html .= '>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a password input field with Tailwind styling
     *
     * @param array $data Prepared field data from preparePasswordData()
     * @return string HTML output
     */
    protected function renderPasswordInput($data) {
        $class = $data['class'] ?: 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<input type="password"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        if ($data['readonly']) {
            $html .= ' readonly';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['autocomplete']) {
            $html .= ' autocomplete="' . htmlspecialchars($data['autocomplete']) . '"';
        }

        $html .= '>';

        if ($data['strength_meter']) {
            $html .= '<div class="password-strength-meter mt-2">';
            $html .= '<div class="w-full bg-gray-200 rounded-full h-1.5">';
            $html .= '<div class="bg-blue-600 h-1.5 rounded-full" style="width: 0%"></div>';
            $html .= '</div>';
            $html .= '<p class="text-sm text-gray-500 mt-1 strength-text"></p>';
            $html .= '</div>';
        }

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a number input field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareNumberData()
     * @return string HTML output
     */
    protected function renderNumberInput($data) {
        return $this->renderTextInput($data);
    }

    /**
     * Render a textarea field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareTextareaData()
     * @return string HTML output
     */
    protected function renderTextarea($data) {
        $class = $data['class'] ?: 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<textarea';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' rows="' . intval($data['rows']) . '"';
        $html .= ' cols="' . intval($data['cols']) . '"';

        if ($data['placeholder']) {
            $html .= ' placeholder="' . htmlspecialchars($data['placeholder']) . '"';
        }
        if ($data['readonly']) {
            $html .= ' readonly';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        if ($data['required']) {
            $html .= ' required';
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

        $html .= '>';
        $html .= htmlspecialchars($data['value']);
        $html .= '</textarea>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a select dropdown field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareDropData()
     * @return string HTML output
     */
    protected function renderDropInput($data) {
        $class = $data['class'] ?: 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
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
        if ($data['onchange']) {
            $html .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
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
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a checkbox input field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareCheckboxData()
     * @return string HTML output
     */
    protected function renderCheckboxInput($data) {
        $id = $data['id'];

        $html = '<div class="mb-4">';
        $html .= '<div class="flex items-center">';

        $html .= '<input type="checkbox"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500' . ($data['has_errors'] ? ' border-red-500' : '') . '"';
        $html .= ' value="' . htmlspecialchars($data['checked_value']) . '"';

        if ($data['is_checked']) {
            $html .= ' checked';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="ml-2 block text-sm text-gray-900">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '</div>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render radio input fields with Tailwind styling
     *
     * @param array $data Prepared field data from prepareRadioData()
     * @return string HTML output
     */
    protected function renderRadioInput($data) {
        $has_errors = $data['has_errors'];

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label class="block text-sm font-medium text-gray-700 mb-2">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<div class="errorplacement space-y-2">';

        foreach ($data['options_list'] as $opt_value => $opt_label) {
            $id = $data['name'] . '_' . $opt_value;

            $html .= '<div class="flex items-center">';
            $html .= '<input type="radio"';
            $html .= ' name="' . htmlspecialchars($data['name']) . '"';
            $html .= ' id="' . htmlspecialchars($id) . '"';
            $html .= ' class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500' . ($has_errors ? ' border-red-500' : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$data['value'] === (string)$opt_value) {
                $html .= ' checked';
            }
            if ($data['disabled']) {
                $html .= ' disabled';
            }

            $html .= '>';

            $html .= '<label for="' . htmlspecialchars($id) . '" class="ml-2 block text-sm text-gray-900">';
            $html .= htmlspecialchars($opt_label);
            $html .= '</label>';

            $html .= '</div>';
        }

        $html .= '</div>';

        if ($has_errors) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a date input field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareDateData()
     * @return string HTML output
     */
    protected function renderDateInput($data) {
        $class = $data['class'] ?: 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $data['id'];

        if ($data['has_errors']) {
            $class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';

        if (isset($data['min'])) {
            $html .= ' min="' . htmlspecialchars($data['min']) . '"';
        }
        if (isset($data['max'])) {
            $html .= ' max="' . htmlspecialchars($data['max']) . '"';
        }
        if ($data['readonly']) {
            $html .= ' readonly';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a file input field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareFileData()
     * @return string HTML output
     */
    protected function renderFileInput($data) {
        $class = $data['class'] ?: 'mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100';
        $id = $data['id'];

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
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

        $html .= '>';

        if ($data['has_errors']) {
            foreach ($data['errors'] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
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
     * Render a submit button with Tailwind styling
     *
     * @param array $data Prepared field data from prepareSubmitData()
     * @return string HTML output
     */
    protected function renderSubmitButton($data) {
        $class = $data['class'] ?: 'inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';

        $html = '<button type="submit"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($data['id']) . '"';
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
     * Render a checkbox list (or radio group) field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareCheckboxListData()
     * @return string HTML output
     */
    protected function renderCheckboxList($data) {
        $id = $data['id'];

        if (empty($data['options_list'])) {
            return '<div class="rounded-md bg-yellow-50 p-4"><div class="text-sm text-yellow-800">No options available for ' . htmlspecialchars($data['name']) . '</div></div>';
        }

        $html = '<div id="' . htmlspecialchars($id) . '_container" class="mb-4">';
        if ($data['label']) {
            $html .= '<label class="block text-sm font-medium text-gray-700">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<div class="mt-2 space-y-2">';

        foreach ($data['options_list'] as $key => $value) {
            $uniqid = $id . '_' . htmlspecialchars($key);
            $is_checked = in_array($key, $data['checked']) ? 'checked="checked"' : '';
            $is_disabled = in_array($key, $data['disabled']) ? 'disabled="disabled"' : '';

            if (in_array($key, $data['readonly'])) {
                if (in_array($key, $data['checked'])) {
                    $html .= '<input type="hidden" name="' . htmlspecialchars($data['name']) . '[]" value="' . htmlspecialchars($key) . '" />';
                }
                $html .= '<div class="relative flex items-center">';
                $html .= '<input class="h-4 w-4 rounded border-gray-300 text-indigo-600" type="' . htmlspecialchars($data['type']) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($data['name']) . '[]" value="' . htmlspecialchars($key) . '" ' . $is_checked . ' disabled="disabled" />';
                $html .= '<label for="' . htmlspecialchars($uniqid) . '" class="ml-3 block text-sm text-gray-700">' . htmlspecialchars($value) . '</label>';
                $html .= '</div>';
            } else {
                $html .= '<div class="relative flex items-center">';
                $html .= '<input class="h-4 w-4 rounded border-gray-300 text-indigo-600" type="' . htmlspecialchars($data['type']) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($data['name']) . '[]" value="' . htmlspecialchars($key) . '" ' . $is_checked . ' ' . $is_disabled . ' />';
                $html .= '<label for="' . htmlspecialchars($uniqid) . '" class="ml-3 block text-sm text-gray-700">' . htmlspecialchars($value) . '</label>';
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a time input field (hour:minute AM/PM) with Tailwind styling
     *
     * @param array $data Prepared field data from prepareTimeData()
     * @return string HTML output
     */
    protected function renderTimeInput($data) {
        $class = $data['class'] ?: 'mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $data['id'];
        $hour_id = $id . '_hour';
        $minute_id = $id . '_minute';
        $ampm_id = $id . '_ampm';

        $input_class = $class;
        if ($data['has_errors']) {
            $input_class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label class="block text-sm font-medium text-gray-700">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<div class="flex gap-2 mt-1">';

        // Hour input
        $html .= '<div class="flex-none">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '" style="width: 80px;"';
        $html .= ' min="1" max="12"';
        $html .= ' placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($data['hour']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        if ($data['disabled']) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';

        // Colon separator
        $html .= '<div class="flex items-center">';
        $html .= '<strong>:</strong>';
        $html .= '</div>';

        // Minute input
        $html .= '<div class="flex-none">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($minute_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_minute') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '" style="width: 80px;"';
        $html .= ' min="0" max="59"';
        $html .= ' placeholder="MM"';
        $html .= ' value="' . htmlspecialchars($data['minute']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        if ($data['disabled']) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';

        // AM/PM selector
        $html .= '<div class="flex-none">';
        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_ampm') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '"';
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
            $html .= '<div class="mt-1 text-sm text-red-600">';
            foreach ($data['errors'] as $error) {
                $html .= htmlspecialchars($error) . '<br>';
            }
            $html .= '</div>';
        }

        if ($data['helptext']) {
            $html .= '<small class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</small>';
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
     * Render separate date and time input fields with Tailwind styling
     *
     * @param array $data Prepared field data from prepareDateTimeData()
     * @return string HTML output
     */
    protected function renderDateTimeInput($data) {
        $class = $data['class'] ?: 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $date_name = $data['date_name'];
        $time_name = $data['time_name'];
        $date_id = $date_name;
        $time_id = $time_name;

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label class="block text-sm font-medium text-gray-700">' . htmlspecialchars($data['label']) . '</label>';
        }

        $html .= '<div class="grid grid-cols-2 gap-4 mt-1">';

        // Date input
        $date_class = $class;
        if (!empty($data['date_errors'])) {
            $date_class .= ' border-red-500';
        }

        $html .= '<div>';
        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($date_name) . '"';
        $html .= ' id="' . htmlspecialchars($date_id) . '"';
        $html .= ' class="' . htmlspecialchars($date_class) . '"';
        $html .= ' value="' . htmlspecialchars($data['date_value']) . '"';
        if ($data['readonly']) {
            $html .= ' readonly';
        }
        $html .= '>';

        foreach ($data['date_errors'] as $error) {
            $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
        }
        $html .= '</div>';

        // Time input
        $html .= '<div>';
        $time_class = $class;
        if (!empty($data['time_errors'])) {
            $time_class .= ' border-red-500';
        }

        $time_hour_id = $time_id . '_hour';
        $time_minute_id = $time_id . '_minute';
        $time_ampm_id = $time_id . '_ampm';

        $html .= '<div class="flex gap-2">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 80px;"';
        $html .= ' min="1" max="12" placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($data['hour']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        $html .= '>';

        $html .= '<span class="flex items-center font-bold">:</span>';

        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_minute_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_minute') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 80px;"';
        $html .= ' min="0" max="59" placeholder="MM"';
        $html .= ' value="' . htmlspecialchars($data['minute']) . '"';
        if ($data['readonly']) $html .= ' readonly';
        $html .= '>';

        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($time_ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_ampm') . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        if ($data['readonly']) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($data['ampm'] === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($data['ampm'] === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';
        $html .= '</div>';

        $html .= '<input type="hidden"';
        $html .= ' id="' . htmlspecialchars($time_id) . '"';
        $html .= ' value="' . htmlspecialchars($data['time_value']) . '"';
        $html .= '>';

        foreach ($data['time_errors'] as $error) {
            $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
        }
        $html .= '</div>';

        $html .= '</div>';

        if ($data['helptext']) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a rich text editor (Trumbowyg) field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareTextboxData()
     */
    protected function renderTextbox($data) {
        $id = $data['id'];
        $class = $data['class'] ?: 'trumbowyg-editor';

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<textarea';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        if ($data['readonly']) {
            $html .= ' readonly';
        }
        if ($data['disabled']) {
            $html .= ' disabled';
        }
        $html .= '>';
        $html .= htmlspecialchars($data['value']);
        $html .= '</textarea>';

        if ($data['helptext']) {
            $html .= '<small class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        // Include Trumbowyg library
        $html .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>';
        $html .= '<link href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.25.1/ui/trumbowyg.min.css" rel="stylesheet">';
        $html .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.25.1/trumbowyg.min.js"></script>';
        $html .= '<script>';
        $html .= '$(document).ready(function() {';
        $html .= '  $("#' . htmlspecialchars($id) . '").trumbowyg({';
        $html .= '    btns: [[\'undo\', \'redo\'], [\'bold\', \'italic\', \'underline\'], [\'link\'], [\'justifyLeft\', \'justifyCenter\', \'justifyRight\']]';
        $html .= '  });';
        $html .= '});';
        $html .= '</script>';

        return $html;
    }

    /**
     * Render an image input field with Tailwind styling
     *
     * @param array $data Prepared field data from prepareImageData()
     * @return string HTML output
     */
    protected function renderImageInput($data) {
        $id = $data['id'];
        $value = $data['value'];

        $html = '<div class="mb-4">';

        if ($data['label']) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($data['label']);
            $html .= '</label>';
        }

        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="image-input-hidden"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= '>';

        if ($value) {
            $html .= '<div class="mt-2">';
            $html .= '<img src="' . htmlspecialchars($value) . '" alt="Preview" style="max-width: 200px; max-height: 200px;" class="rounded-lg border border-gray-300">';
            $html .= '</div>';
        }

        $html .= '<div class="mt-2">';
        $html .= '<button type="button" class="inline-flex justify-center rounded-md border border-gray-300 bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" onclick="alert(\'Image selection not implemented\')">';
        $html .= 'Select Image';
        $html .= '</button>';
        $html .= '</div>';

        if ($data['helptext']) {
            $html .= '<small class="mt-1 text-sm text-gray-500">' . htmlspecialchars($data['helptext']) . '</small>';
        }

        $html .= '</div>';

        return $html;
    }
}
