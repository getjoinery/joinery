<?php
/**
 * FormWriter v2 Tailwind CSS Implementation
 *
 * Tailwind-themed form field output
 *
 * @version 2.0.0
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2Tailwind extends FormWriterV2Base {

    /**
     * Output a text input field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' border-red-500';
        }

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<input type="text"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';
        echo ' value="' . htmlspecialchars($value) . '"';

        if ($placeholder) {
            echo ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if (!empty($options['readonly'])) {
            echo ' readonly';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }
        if (!empty($options['autofocus'])) {
            echo ' autofocus';
        }
        if (!empty($options['autocomplete'])) {
            echo ' autocomplete="' . htmlspecialchars($options['autocomplete']) . '"';
        }
        if (!empty($options['onchange'])) {
            echo ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        echo '>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a password input field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputPasswordInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' border-red-500';
        }

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<input type="password"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';
        echo ' value="' . htmlspecialchars($value) . '"';

        if ($placeholder) {
            echo ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if (!empty($options['readonly'])) {
            echo ' readonly';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }
        if (!empty($options['autocomplete'])) {
            echo ' autocomplete="' . htmlspecialchars($options['autocomplete']) . '"';
        }

        echo '>';

        if (!empty($options['strength_meter'])) {
            echo '<div class="password-strength-meter mt-2">';
            echo '<div class="w-full bg-gray-200 rounded-full h-1.5">';
            echo '<div class="bg-blue-600 h-1.5 rounded-full" style="width: 0%"></div>';
            echo '</div>';
            echo '<p class="text-sm text-gray-500 mt-1 strength-text"></p>';
            echo '</div>';
        }

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a textarea field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextarea($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $options['id'] ?? $name;
        $rows = $options['rows'] ?? 3;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' border-red-500';
        }

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<textarea';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';
        echo ' rows="' . (int)$rows . '"';

        if ($placeholder) {
            echo ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if (!empty($options['readonly'])) {
            echo ' readonly';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }

        echo '>';
        echo htmlspecialchars($value);
        echo '</textarea>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a select dropdown field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDropInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $options['id'] ?? $name;
        $select_options = $options['options'] ?? [];

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' border-red-500';
        }

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<select';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';

        if (!empty($options['multiple'])) {
            echo ' multiple';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }
        if (!empty($options['onchange'])) {
            echo ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        echo '>';

        if (!empty($options['empty_option'])) {
            echo '<option value="">' . htmlspecialchars($options['empty_option']) . '</option>';
        }

        foreach ($select_options as $opt_value => $opt_label) {
            echo '<option value="' . htmlspecialchars($opt_value) . '"';
            if ((string)$value === (string)$opt_value) {
                echo ' selected';
            }
            echo '>' . htmlspecialchars($opt_label) . '</option>';
        }

        echo '</select>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a checkbox input field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputCheckboxInput($name, $label, $options) {
        $value = $options['value'] ?? '1';
        $checked = !empty($options['checked']) || (isset($this->values[$name]) && $this->values[$name]);
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);

        echo '<div class="mb-4">';
        echo '<div class="flex items-center">';

        echo '<input type="checkbox"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500' . ($has_errors ? ' border-red-500' : '') . '"';
        echo ' value="' . htmlspecialchars($value) . '"';

        if ($checked) {
            echo ' checked';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }

        echo '>';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="ml-2 block text-sm text-gray-900">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '</div>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output radio input fields with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label (group label)
     * @param array $options Field options (must include 'options' key)
     */
    protected function outputRadioInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $radio_options = $options['options'] ?? [];

        $has_errors = isset($this->errors[$name]);

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label class="block text-sm font-medium text-gray-700 mb-2">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<div class="errorplacement space-y-2">';

        foreach ($radio_options as $opt_value => $opt_label) {
            $id = $name . '_' . $opt_value;

            echo '<div class="flex items-center">';
            echo '<input type="radio"';
            echo ' name="' . htmlspecialchars($name) . '"';
            echo ' id="' . htmlspecialchars($id) . '"';
            echo ' class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500' . ($has_errors ? ' border-red-500' : '') . '"';
            echo ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$value === (string)$opt_value) {
                echo ' checked';
            }
            if (!empty($options['disabled'])) {
                echo ' disabled';
            }

            echo '>';

            echo '<label for="' . htmlspecialchars($id) . '" class="ml-2 block text-sm text-gray-900">';
            echo htmlspecialchars($opt_label);
            echo '</label>';

            echo '</div>';
        }

        echo '</div>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a date input field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' border-red-500';
        }

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<input type="date"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';
        echo ' value="' . htmlspecialchars($value) . '"';

        if (!empty($options['min'])) {
            echo ' min="' . htmlspecialchars($options['min']) . '"';
        }
        if (!empty($options['max'])) {
            echo ' max="' . htmlspecialchars($options['max']) . '"';
        }
        if (!empty($options['readonly'])) {
            echo ' readonly';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }

        echo '>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a file input field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputFileInput($name, $label, $options) {
        $class = $options['class'] ?? 'mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);

        echo '<div class="mb-4">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '<input type="file"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';

        if (!empty($options['accept'])) {
            echo ' accept="' . htmlspecialchars($options['accept']) . '"';
        }
        if (!empty($options['multiple'])) {
            echo ' multiple';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }

        echo '>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Output a hidden input field
     *
     * @param string $name Field name
     * @param array $options Field options
     */
    protected function outputHiddenInput($name, $options) {
        $value = $options['value'] ?? '';

        echo '<input type="hidden"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' value="' . htmlspecialchars($value) . '"';
        echo '>';
    }

    /**
     * Output a submit button with Tailwind styling
     *
     * @param string $name Button name
     * @param string $label Button label
     * @param array $options Button options
     */
    protected function outputSubmitButton($name, $label, $options) {
        $class = $options['class'] ?? 'inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';
        $id = $options['id'] ?? $name;

        echo '<button type="submit"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';

        if (!empty($options['disabled'])) {
            echo ' disabled';
        }
        if (!empty($options['onclick'])) {
            echo ' onclick="' . htmlspecialchars($options['onclick']) . '"';
        }

        echo '>';
        echo htmlspecialchars($label);
        echo '</button>';
    }
}
