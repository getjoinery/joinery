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
     * Track if Trumbowyg script has already been output to prevent double initialization
     */
    private static $trumbowyg_script_output = false;

    /**
     * Output a text input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $prepend = $options['prepend'] ?? '';  // Bootstrap input-group prepend text

        // Determine if field has errors
        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        // Output label
        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        // Open input-group if prepend text is provided
        if ($prepend) {
            $html .= '<div class="input-group">';
            $html .= '<div class="input-group-text">' . htmlspecialchars($prepend) . '</div>';
        }

        // Output input
        $html .= '<input type="text"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        // Only show placeholder if field is empty (Bootstrap native behavior)
        if ($placeholder && !$value) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['autofocus'])) {
            $html .= ' autofocus';
        }
        if (!empty($options['autocomplete'])) {
            $html .= ' autocomplete="' . htmlspecialchars($options['autocomplete']) . '"';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        $html .= '>';

        // Close input-group if prepend text was provided
        if ($prepend) {
            $html .= '</div>';  // Close input-group
        }

        // Display any errors for this field
        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        // Display help text if provided
        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
    }

    /**
     * Output a password input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputPasswordInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<input type="password"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        if ($placeholder) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['autocomplete'])) {
            $html .= ' autocomplete="' . htmlspecialchars($options['autocomplete']) . '"';
        }

        $html .= '>';

        // Password strength meter if requested
        if (!empty($options['strength_meter'])) {
            $html .= '<div class="password-strength-meter mt-2">';
            $html .= '<div class="progress" style="height: 5px;">';
            $html .= '<div class="progress-bar" role="progressbar" style="width: 0%"></div>';
            $html .= '</div>';
            $html .= '<small class="strength-text text-muted"></small>';
            $html .= '</div>';
        }

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
    }

    /**
     * Output a textarea field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    /**
     * Output a select dropdown field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDropInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $select_options = $options['options'] ?? [];
        $ajaxendpoint = $options['ajaxendpoint'] ?? '';

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<select';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if (!empty($options['multiple'])) {
            $html .= ' multiple';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        $html .= '>';

        // Default empty option
        if (!empty($options['empty_option'])) {
            // If empty_option is boolean true, show "Select..." as default text
            // If it's a string, use that string as the label
            $empty_label = ($options['empty_option'] === true) ? 'Select...' : $options['empty_option'];
            $html .= '<option value="">' . htmlspecialchars($empty_label) . '</option>';
        }

        // Output options - Standard convention: [id => label]
        foreach ($select_options as $opt_value => $opt_label) {
            $html .= '<option value="' . htmlspecialchars($opt_value) . '"';
            if ((string)$value === (string)$opt_value) {
                $html .= ' selected';
            }
            $html .= '>' . htmlspecialchars($opt_label) . '</option>';
        }

        $html .= '</select>';

        // AJAX dropdown support - output inline script
        if (!empty($ajaxendpoint)) {
            $html .= '<script>
(function() {
  class AjaxSearchSelect {
    constructor(selectEl, ajaxUrl) {
      this.select = selectEl;
      this.ajaxUrl = ajaxUrl;
      this.cache = {};
      this.debounceTimer = null;

      const input = document.createElement(\'input\');
      input.type = \'text\';
      input.className = selectEl.className;
      // Don\'t transfer name - keep it on the select element so it submits the ID
      input.placeholder = \'Type to search...\';

      const list = document.createElement(\'datalist\');
      list.id = selectEl.id + \'_list\';
      input.setAttribute(\'list\', list.id);

      selectEl.style.display = \'none\';
      // Keep name on select so it submits the ID, not the input value
      selectEl.parentNode.insertBefore(input, selectEl);
      selectEl.parentNode.insertBefore(list, selectEl);

      this.input = input;
      this.list = list;
      this.data = [];

      if (selectEl.value) {
        input.value = selectEl.options[selectEl.selectedIndex].text;
      }

      input.addEventListener(\'input\', (e) => this.search(e.target.value));
      input.addEventListener(\'change\', (e) => {
        const inputVal = e.target.value.trim();
        if (!inputVal) {
          selectEl.value = \'\';
        } else {
          // When user selects from datalist, find the matching item and update hidden select
          const matching = this.data.find(item => item.text === inputVal);
          if (matching) {
            // Add the option to the select if it doesn\'t exist
            let option = selectEl.querySelector(\'option[value="\' + matching.id + \'"]\');
            if (!option) {
              option = document.createElement(\'option\');
              option.value = matching.id;
              option.textContent = matching.text;
              selectEl.innerHTML = \'\';  // Clear previous options
              selectEl.appendChild(option);
            }
            selectEl.value = matching.id;
          }
        }
        selectEl.dispatchEvent(new Event(\'change\', { bubbles: true }));
      });
    }

    search(query) {
      clearTimeout(this.debounceTimer);
      if (query.length < 3) {
        this.list.innerHTML = \'\';
        this.data = [];
        return;
      }

      if (this.cache[query]) {
        this.updateList(this.cache[query]);
        return;
      }

      this.debounceTimer = setTimeout(() => {
        // Build URL - use & if URL already has ?, otherwise use ?
        const separator = this.ajaxUrl.includes(\'?\') ? \'&\' : \'?\';
        fetch(this.ajaxUrl + separator + \'q=\' + encodeURIComponent(query))
          .then(r => r.json())
          .then(data => {
            this.cache[query] = data;
            this.updateList(data);
          });
      }, 250);
    }

    updateList(data) {
      this.data = data;
      this.list.innerHTML = \'\';
      data.forEach(item => {
        const opt = document.createElement(\'option\');
        opt.value = item.text;  // Display the full text (name - email)
        opt.dataset.id = item.id;  // Store the ID for later retrieval
        this.list.appendChild(opt);
      });
    }
  }

  document.addEventListener(\'DOMContentLoaded\', () => {
    const select = document.getElementById(\'' . htmlspecialchars($id) . '\');
    if (select) {
      new AjaxSearchSelect(select, \'' . htmlspecialchars($ajaxendpoint) . '\');
    }
  });
})();
</script>';
        }

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        // Check for visibility rules or custom scripts in options
        if (isset($options['visibility_rules']) && !empty($options['visibility_rules'])) {
            $html .= $this->generateVisibilityScript($name, $id, $options['visibility_rules']);
        } elseif (isset($options['custom_script']) && !empty($options['custom_script'])) {
            $html .= $this->generateFieldScript($id, $options['custom_script']);
        }

        $html .= '</div>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
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

        $html = '';
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';

        $html .= '<input type="checkbox"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="form-check-input' . ($has_errors ? ' is-invalid' : '') . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        if ($checked) {
            $html .= ' checked';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($label) {
            $html .= '<label class="form-check-label" for="' . htmlspecialchars($id) . '">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '</div>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);

        // Check for visibility rules or custom scripts in options
        if (isset($options['visibility_rules']) && !empty($options['visibility_rules'])) {
            echo $this->generateVisibilityScript($name, $id, $options['visibility_rules']);
        } elseif (isset($options['custom_script']) && !empty($options['custom_script'])) {
            echo $this->generateFieldScript($id, $options['custom_script']);
        }
    }

    /**
     * Output radio input fields with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label (group label)
     * @param array $options Field options (must include 'options' key)
     */
    protected function outputRadioInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $radio_options = $options['options'] ?? [];

        $has_errors = isset($this->errors[$name]);

        $html = '';
        $html .= '<div class="form-group">';

        // Group label
        if ($label) {
            $html .= '<label>' . htmlspecialchars($label) . '</label>';
        }

        // Wrap radio buttons in errorplacement div for proper error positioning
        $html .= '<div class="errorplacement">';

        // Output each radio option
        foreach ($radio_options as $opt_value => $opt_label) {
            $id = $name . '_' . $opt_value;

            $html .= '<div class="form-check">';
            $html .= '<input type="radio"';
            $html .= ' name="' . htmlspecialchars($name) . '"';
            $html .= ' id="' . htmlspecialchars($id) . '"';
            $html .= ' class="form-check-input' . ($has_errors ? ' is-invalid' : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$value === (string)$opt_value) {
                $html .= ' checked';
            }
            if (!empty($options['disabled'])) {
                $html .= ' disabled';
            }

            $html .= '>';

            $html .= '<label class="form-check-label" for="' . htmlspecialchars($id) . '">';
            $html .= htmlspecialchars($opt_label);
            $html .= '</label>';

            $html .= '</div>';
        }

        $html .= '</div>'; // End errorplacement

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a date input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        if (!empty($options['min'])) {
            $html .= ' min="' . htmlspecialchars($options['min']) . '"';
        }
        if (!empty($options['max'])) {
            $html .= ' max="' . htmlspecialchars($options['max']) . '"';
        }
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a time input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTimeInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $hour_id = $id . '_hour';
        $minute_id = $id . '_minute';
        $ampm_id = $id . '_ampm';

        $has_errors = isset($this->errors[$name]);
        $input_class = $class;
        if ($has_errors) {
            $input_class .= ' is-invalid';
        }

        // Use centralized time parsing helper
        $time_components = $this->parseTimeValue($value);
        $hour = $time_components['hour'];
        $minute = $time_components['minute'];
        $ampm = $time_components['ampm'];

        $html = '';
        $html .= '<div class="form-group">';

        if ($label) {
            $html .= '<label>' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<div class="row g-2">';

        // Hour input
        $html .= '<div class="col-auto">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '" style="width: 120px;"';
        $html .= ' min="1" max="12"';
        $html .= ' placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
        if (!empty($options['disabled'])) $html .= ' disabled';
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
        $html .= ' value="' . htmlspecialchars($minute) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
        if (!empty($options['disabled'])) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';

        // AM/PM selector
        $html .= '<div class="col-auto">';
        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_ampm') . '"';
        $html .= ' class="form-select"';
        if (!empty($options['readonly'])) $html .= ' disabled';
        if (!empty($options['disabled'])) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($ampm === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($ampm === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';
        $html .= '</div>';

        // Hidden field to store the actual time value
        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= '>';

        $html .= '</div>';

        if ($has_errors) {
            $html .= '<div class="invalid-feedback d-block">';
            foreach ($this->errors[$name] as $error) {
                $html .= htmlspecialchars($error) . '<br>';
            }
            $html .= '</div>';
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);

        // Output shared JavaScript for time inputs
        $this->outputTimeInputJavaScript();

        // Add data attributes to trigger the sync
        echo '<div data-time-hour="' . htmlspecialchars($hour_id) . '"';
        echo ' data-time-minute="' . htmlspecialchars($minute_id) . '"';
        echo ' data-time-ampm="' . htmlspecialchars($ampm_id) . '"';
        echo ' data-time-hidden="' . htmlspecialchars($id) . '"';
        echo ' style="display:none;"></div>';
    }

    /**
     * Output separate date and time input fields with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateTimeInput($name, $label, $options) {
        // Derive date and time field names from the main name
        $date_name = $name . '_dateinput';
        $time_name = $name . '_timeinput';
        $date_value = $options['value'] ?? $options['date_value'] ?? ($this->values[$name] ?? '');
        $time_value = $options['time_value'] ?? ($this->values[$time_name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $date_id = $options['date_id'] ?? $date_name;
        $time_id = $options['time_id'] ?? $time_name;

        // Extract date from datetime value if provided
        if ($date_value && strpos($date_value, ' ') !== false) {
            list($date_value, $time_value) = explode(' ', $date_value, 2);
        }

        // Use helper to parse time value
        $time_components = $this->parseTimeValue($time_value);
        $hour = $time_components['hour'];
        $minute = $time_components['minute'];
        $ampm = $time_components['ampm'];

        $html = '';
        $html .= '<div class="form-group">';

        if ($label) {
            $html .= '<label>' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';

        // Date input
        $date_class = $class;
        if (isset($this->errors[$date_name])) {
            $date_class .= ' is-invalid';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($date_name) . '"';
        $html .= ' id="' . htmlspecialchars($date_id) . '"';
        $html .= ' class="' . htmlspecialchars($date_class) . '"';
        $html .= ' value="' . htmlspecialchars($date_value) . '"';
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        $html .= '>';

        if (isset($this->errors[$date_name])) {
            foreach ($this->errors[$date_name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        $html .= '</div>';
        $html .= '<div class="col-md-6">';

        // Time input - use same AM/PM format as outputTimeInput
        $time_class = $class;
        if (isset($this->errors[$time_name])) {
            $time_class .= ' is-invalid';
        }

        $time_hour_id = $time_id . '_hour';
        $time_minute_id = $time_id . '_minute';
        $time_ampm_id = $time_id . '_ampm';

        $html .= '<div class="row g-2">';
        $html .= '<div class="col-auto">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 120px;"';
        $html .= ' min="1" max="12" placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
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
        if (!empty($options['readonly'])) $html .= ' readonly';
        $html .= '>';
        $html .= '</div>';
        $html .= '<div class="col-auto">';
        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($time_ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_ampm') . '"';
        $html .= ' class="form-select"';
        if (!empty($options['readonly'])) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($ampm === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($ampm === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<input type="hidden"';
        $html .= ' id="' . htmlspecialchars($time_id) . '"';
        $html .= ' value="' . htmlspecialchars($time_value) . '"';
        $html .= '>';

        if (isset($this->errors[$time_name])) {
            $html .= '<div class="invalid-feedback d-block">';
            foreach ($this->errors[$time_name] as $error) {
                $html .= htmlspecialchars($error) . '<br>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
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

        $html = '';
        $html .= '<div class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<input type="file"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if (!empty($options['accept'])) {
            $html .= ' accept="' . htmlspecialchars($options['accept']) . '"';
        }
        if (!empty($options['multiple'])) {
            $html .= ' multiple';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a hidden input field
     *
     * @param string $name Field name
     * @param array $options Field options
     */
    protected function outputHiddenInput($name, $options) {
        $value = $options['value'] ?? '';

        $html = '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= '>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
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

        $html = '<button type="submit"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['onclick'])) {
            $html .= ' onclick="' . htmlspecialchars($options['onclick']) . '"';
        }

        $html .= '>';
        $html .= htmlspecialchars($label);
        $html .= '</button>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
    }

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

    /**
     * Create a checkbox list (or radio group) field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (options, checked, disabled, readonly, type)
     */
    public function checkboxlist($name, $label = '', $options = []) {
        $this->registerField($name, 'checkboxlist', $label, $options);
        $this->outputCheckboxList($name, $label, $options);
    }

    /**
     * Output a checkbox list (or radio group) field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputCheckboxList($name, $label, $options) {
        $optionvals = $options['options'] ?? [];
        $checked = $options['checked'] ?? [];
        $disabled = $options['disabled'] ?? [];
        $readonly = $options['readonly'] ?? [];
        $type = $options['type'] ?? 'checkbox';
        $id = $options['id'] ?? $name;

        // Ensure checked is an array
        if (!is_array($checked)) {
            $checked = [];
        }

        // Validate input
        if (empty($optionvals)) {
            echo '<div class="alert alert-warning">No options available for ' . htmlspecialchars($name) . '</div>';
            return;
        }

        if ($type === 'radio') {
            if (is_array($checked) && count($checked) > 1) {
                throw new DisplayableUserException('A radio field cannot have more than one checked value.');
            }
            if ($readonly) {
                throw new DisplayableUserException('A radio field cannot have read only values.');
            }
        } elseif ($type !== 'checkbox') {
            throw new DisplayableUserException('Invalid checkbox list type.');
        }

        $html = '';
        $html .= '<div id="' . htmlspecialchars($id) . '_container" class="mb-3 errorplacement">';
        if ($label) {
            $html .= '<label class="form-label">' . htmlspecialchars($label) . '</label>';
        }

        // Standard convention: $optionvals is [id => label]
        // $key is the ID (value to submit), $value is the display label
        foreach ($optionvals as $key => $value) {
            $uniqid = $id . '_' . htmlspecialchars($key);
            $is_checked = in_array($key, $checked) ? 'checked="checked"' : '';
            $is_disabled = in_array($key, $disabled) ? 'disabled="disabled"' : '';

            // Readonly means it cannot be changed but is submitted
            if (in_array($key, $readonly)) {
                if (in_array($key, $checked)) {
                    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($key) . '" />';
                }
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($key) . '" ' . $is_checked . ' disabled="disabled" />';
                $html .= '<label class="form-check-label" for="' . htmlspecialchars($uniqid) . '">' . htmlspecialchars($value) . '</label>';
                $html .= '</div>';
            } else {
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($key) . '" ' . $is_checked . ' ' . $is_disabled . ' />';
                $html .= '<label class="form-check-label" for="' . htmlspecialchars($uniqid) . '">' . htmlspecialchars($value) . '</label>';
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a textarea field
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextarea($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $rows = $options['rows'] ?? 5;
        $cols = $options['cols'] ?? 80;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<textarea';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' rows="' . intval($rows) . '"';
        $html .= ' cols="' . intval($cols) . '"';

        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';
        $html .= htmlspecialchars($value);
        $html .= '</textarea>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a textbox (rich text editor) field
     * Implementation for abstract method
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextbox($name, $label, $options) {
        // The public textbox() method handles rich text editor logic
        // This protected method is called from the public wrapper
        // Delegate to the actual implementation
        $this->textbox($name, $label, $options);
    }

    /**
     * Output an image input (selection) field
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputImageInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '';
        $html .= '<div class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>';
        }

        // Image input as hidden field with preview
        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="image-input-hidden"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= '>';

        // Display preview if value exists
        if ($value) {
            $html .= '<div class="mt-2">';
            $html .= '<img src="' . htmlspecialchars($value) . '" alt="Preview" style="max-width: 200px; max-height: 200px;" class="img-thumbnail">';
            $html .= '</div>';
        }

        // Image selection button (placeholder)
        $html .= '<div class="mt-2">';
        $html .= '<button type="button" class="btn btn-secondary btn-sm" onclick="alert(\'Image selection not implemented\')">';
        $html .= 'Select Image';
        $html .= '</button>';
        $html .= '</div>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }
}
