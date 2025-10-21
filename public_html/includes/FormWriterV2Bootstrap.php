<?php
/**
 * FormWriter v2 Bootstrap Implementation
 *
 * Bootstrap-themed form field output
 *
 * @version 2.0.0
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2Bootstrap extends FormWriterV2Base {

    /**
     * Output a text input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        // Determine if field has errors
        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        echo '<div class="form-group">';

        // Output label
        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        // Output input
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

        // Display any errors for this field
        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        // Display help text if provided
        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output a password input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputPasswordInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
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

        // Password strength meter if requested
        if (!empty($options['strength_meter'])) {
            echo '<div class="password-strength-meter mt-2">';
            echo '<div class="progress" style="height: 5px;">';
            echo '<div class="progress-bar" role="progressbar" style="width: 0%"></div>';
            echo '</div>';
            echo '<small class="strength-text text-muted"></small>';
            echo '</div>';
        }

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output a textarea field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextarea($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $rows = $options['rows'] ?? 3;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
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
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output a select dropdown field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDropInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $select_options = $options['options'] ?? [];

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
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

        // Default empty option
        if (!empty($options['empty_option'])) {
            echo '<option value="">' . htmlspecialchars($options['empty_option']) . '</option>';
        }

        // Output options
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
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output a checkbox input field with Bootstrap styling
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

        echo '<div class="form-group">';
        echo '<div class="form-check">';

        echo '<input type="checkbox"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="form-check-input' . ($has_errors ? ' is-invalid' : '') . '"';
        echo ' value="' . htmlspecialchars($value) . '"';

        if ($checked) {
            echo ' checked';
        }
        if (!empty($options['disabled'])) {
            echo ' disabled';
        }

        echo '>';

        if ($label) {
            echo '<label class="form-check-label" for="' . htmlspecialchars($id) . '">';
            echo htmlspecialchars($label);
            echo '</label>';
        }

        echo '</div>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output radio input fields with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label (group label)
     * @param array $options Field options (must include 'options' key)
     */
    protected function outputRadioInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $radio_options = $options['options'] ?? [];

        $has_errors = isset($this->errors[$name]);

        echo '<div class="form-group">';

        // Group label
        if ($label) {
            echo '<label>' . htmlspecialchars($label) . '</label>';
        }

        // Wrap radio buttons in errorplacement div for proper error positioning
        echo '<div class="errorplacement">';

        // Output each radio option
        foreach ($radio_options as $opt_value => $opt_label) {
            $id = $name . '_' . $opt_value;

            echo '<div class="form-check">';
            echo '<input type="radio"';
            echo ' name="' . htmlspecialchars($name) . '"';
            echo ' id="' . htmlspecialchars($id) . '"';
            echo ' class="form-check-input' . ($has_errors ? ' is-invalid' : '') . '"';
            echo ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$value === (string)$opt_value) {
                echo ' checked';
            }
            if (!empty($options['disabled'])) {
                echo ' disabled';
            }

            echo '>';

            echo '<label class="form-check-label" for="' . htmlspecialchars($id) . '">';
            echo htmlspecialchars($opt_label);
            echo '</label>';

            echo '</div>';
        }

        echo '</div>'; // End errorplacement

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output a date input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
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
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    /**
     * Output a file input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputFileInput($name, $label, $options) {
        $class = $options['class'] ?? 'form-control-file';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
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
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
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
     * Output a submit button with Bootstrap styling
     *
     * @param string $name Button name
     * @param string $label Button label
     * @param array $options Button options
     */
    protected function outputSubmitButton($name, $label, $options) {
        $class = $options['class'] ?? 'btn btn-primary';
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
