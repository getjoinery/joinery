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
        $prepend = $options['prepend'] ?? '';  // Bootstrap input-group prepend text

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

        // Open input-group if prepend text is provided
        if ($prepend) {
            echo '<div class="input-group">';
            echo '<div class="input-group-text">' . htmlspecialchars($prepend) . '</div>';
        }

        // Output input
        echo '<input type="text"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' class="' . htmlspecialchars($class) . '"';
        echo ' value="' . htmlspecialchars($value) . '"';

        // Only show placeholder if field is empty (Bootstrap native behavior)
        if ($placeholder && !$value) {
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

        // Close input-group if prepend text was provided
        if ($prepend) {
            echo '</div>';  // Close input-group
        }

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
        $ajaxendpoint = $options['ajaxendpoint'] ?? '';

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
        foreach ($select_options as $opt_label => $opt_value) {
            echo '<option value="' . htmlspecialchars($opt_value) . '"';
            if ((string)$value === (string)$opt_value) {
                echo ' selected';
            }
            echo '>' . htmlspecialchars($opt_label) . '</option>';
        }

        echo '</select>';

        // AJAX dropdown support - output inline script
        if (!empty($ajaxendpoint)) {
            echo '<script>
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
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        // Check for visibility rules or custom scripts in options
        if (isset($options['visibility_rules']) && !empty($options['visibility_rules'])) {
            echo $this->generateVisibilityScript($name, $id, $options['visibility_rules']);
        } elseif (isset($options['custom_script']) && !empty($options['custom_script'])) {
            echo $this->generateFieldScript($id, $options['custom_script']);
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
     * Output a time input field with Bootstrap styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTimeInput($name, $label, $options) {
        $value = $options['value'] ?? '';
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

        // Parse value - handles both "HH:MM" (24-hour) and "g:i a" (12-hour) formats
        $hour = '';
        $minute = '';
        $ampm = 'AM';

        if ($value) {
            // Check if value contains AM/PM (e.g., "3:15 PM" from datetimeinput)
            if (stripos($value, 'am') !== false || stripos($value, 'pm') !== false) {
                // Extract AM/PM first
                if (stripos($value, 'pm') !== false) {
                    $ampm = 'PM';
                    $value = str_ireplace('pm', '', $value);
                } else {
                    $ampm = 'AM';
                    $value = str_ireplace('am', '', $value);
                }
                $value = trim($value);
            }

            // Now parse hour and minute
            if (strpos($value, ':') !== false) {
                list($h, $m) = explode(':', $value);
                $h = intval(trim($h));
                $m = intval(trim($m));

                // If we extracted AM/PM, the hour is already in 12-hour format
                if ($ampm === 'PM' && $h !== 12) {
                    // Will display as-is, conversion happens on submit
                } elseif ($ampm === 'AM' && $h === 12) {
                    // Keep as 12
                } elseif ($h >= 12 && (stripos($value, 'am') === false && stripos($value, 'pm') === false)) {
                    // If no AM/PM was in original value, convert from 24-hour
                    if ($h >= 12) {
                        $ampm = 'PM';
                        if ($h > 12) $h -= 12;
                    } else {
                        $ampm = 'AM';
                        if ($h == 0) $h = 12;
                    }
                }

                $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
                $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
            }
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label>' . htmlspecialchars($label) . '</label>';
        }

        echo '<div class="row g-2">';

        // Hour input
        echo '<div class="col-auto">';
        echo '<input type="number"';
        echo ' id="' . htmlspecialchars($hour_id) . '"';
        echo ' name="' . htmlspecialchars($id . '_hour') . '"';
        echo ' class="' . htmlspecialchars($input_class) . '" style="width: 80px;"';
        echo ' min="1" max="12"';
        echo ' placeholder="HH"';
        echo ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($options['readonly'])) echo ' readonly';
        if (!empty($options['disabled'])) echo ' disabled';
        echo '>';
        echo '</div>';

        // Colon separator
        echo '<div class="col-auto" style="display: flex; align-items: center;">';
        echo '<strong>:</strong>';
        echo '</div>';

        // Minute input
        echo '<div class="col-auto">';
        echo '<input type="number"';
        echo ' id="' . htmlspecialchars($minute_id) . '"';
        echo ' name="' . htmlspecialchars($id . '_minute') . '"';
        echo ' class="' . htmlspecialchars($input_class) . '" style="width: 80px;"';
        echo ' min="0" max="59"';
        echo ' placeholder="MM"';
        echo ' value="' . htmlspecialchars($minute) . '"';
        if (!empty($options['readonly'])) echo ' readonly';
        if (!empty($options['disabled'])) echo ' disabled';
        echo '>';
        echo '</div>';

        // AM/PM selector
        echo '<div class="col-auto">';
        echo '<select';
        echo ' id="' . htmlspecialchars($ampm_id) . '"';
        echo ' name="' . htmlspecialchars($id . '_ampm') . '"';
        echo ' class="form-select"';
        if (!empty($options['readonly'])) echo ' disabled';
        if (!empty($options['disabled'])) echo ' disabled';
        echo '>';
        echo '<option value="AM"' . ($ampm === 'AM' ? ' selected' : '') . '>AM</option>';
        echo '<option value="PM"' . ($ampm === 'PM' ? ' selected' : '') . '>PM</option>';
        echo '</select>';
        echo '</div>';

        // Hidden field to store the actual time value
        echo '<input type="hidden"';
        echo ' name="' . htmlspecialchars($name) . '"';
        echo ' id="' . htmlspecialchars($id) . '"';
        echo ' value="' . htmlspecialchars($value) . '"';
        echo '>';

        echo '</div>';

        if ($has_errors) {
            echo '<div class="invalid-feedback d-block">';
            foreach ($this->errors[$name] as $error) {
                echo htmlspecialchars($error) . '<br>';
            }
            echo '</div>';
        }

        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';

        // JavaScript to sync the hidden field with user input
        static $time_input_js_loaded = false;
        if (!$time_input_js_loaded) {
            echo '<script type="text/javascript">
            function updateTimeInput(hourId, minuteId, ampmId, hiddenId) {
                var hour = document.getElementById(hourId).value;
                var minute = document.getElementById(minuteId).value;
                var ampm = document.getElementById(ampmId).value;

                if (hour && minute) {
                    var h = parseInt(hour);
                    if (ampm === "PM" && h !== 12) h += 12;
                    if (ampm === "AM" && h === 12) h = 0;

                    var timeValue = String(h).padStart(2, "0") + ":" + String(minute).padStart(2, "0");
                    document.getElementById(hiddenId).value = timeValue;
                }
            }

            document.addEventListener("DOMContentLoaded", function() {
                var timeInputs = document.querySelectorAll("[data-time-hour]");
                timeInputs.forEach(function(el) {
                    var hourId = el.getAttribute("data-time-hour");
                    var minuteId = el.getAttribute("data-time-minute");
                    var ampmId = el.getAttribute("data-time-ampm");
                    var hiddenId = el.getAttribute("data-time-hidden");

                    document.getElementById(hourId).addEventListener("change", function() {
                        updateTimeInput(hourId, minuteId, ampmId, hiddenId);
                    });
                    document.getElementById(minuteId).addEventListener("change", function() {
                        updateTimeInput(hourId, minuteId, ampmId, hiddenId);
                    });
                    document.getElementById(ampmId).addEventListener("change", function() {
                        updateTimeInput(hourId, minuteId, ampmId, hiddenId);
                    });
                });
            });
            </script>';
            $time_input_js_loaded = true;
        }

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
     * @param string $date_name Date field name
     * @param string $time_name Time field name
     */
    protected function outputDateTimeInput($name, $label, $options, $date_name, $time_name) {
        $date_value = $options['value'] ?? $options['date_value'] ?? '';
        $time_value = $options['time_value'] ?? '';
        $class = $options['class'] ?? 'form-control';
        $date_id = $options['date_id'] ?? $date_name;
        $time_id = $options['time_id'] ?? $time_name;

        // Extract date from datetime value if provided
        if ($date_value && strpos($date_value, ' ') !== false) {
            list($date_value, $time_value) = explode(' ', $date_value, 2);
        }

        echo '<div class="form-group">';

        if ($label) {
            echo '<label>' . htmlspecialchars($label) . '</label>';
        }

        echo '<div class="row">';
        echo '<div class="col-md-6">';

        // Date input
        $date_class = $class;
        if (isset($this->errors[$date_name])) {
            $date_class .= ' is-invalid';
        }

        echo '<input type="date"';
        echo ' name="' . htmlspecialchars($date_name) . '"';
        echo ' id="' . htmlspecialchars($date_id) . '"';
        echo ' class="' . htmlspecialchars($date_class) . '"';
        echo ' value="' . htmlspecialchars($date_value) . '"';
        if (!empty($options['readonly'])) {
            echo ' readonly';
        }
        echo '>';

        if (isset($this->errors[$date_name])) {
            foreach ($this->errors[$date_name] as $error) {
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        echo '</div>';
        echo '<div class="col-md-6">';

        // Time input - use same AM/PM format as outputTimeInput
        $time_class = $class;
        if (isset($this->errors[$time_name])) {
            $time_class .= ' is-invalid';
        }

        $hour = '';
        $minute = '';
        $ampm = 'AM';

        if ($time_value) {
            list($h, $m) = explode(':', $time_value);
            $h = intval($h);
            if ($h >= 12) {
                $ampm = 'PM';
                if ($h > 12) $h -= 12;
            } else {
                $ampm = 'AM';
                if ($h == 0) $h = 12;
            }
            $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
            $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
        }

        $time_hour_id = $time_id . '_hour';
        $time_minute_id = $time_id . '_minute';
        $time_ampm_id = $time_id . '_ampm';

        echo '<div class="row g-2">';
        echo '<div class="col-auto">';
        echo '<input type="number"';
        echo ' id="' . htmlspecialchars($time_hour_id) . '"';
        echo ' name="' . htmlspecialchars($time_name . '_hour') . '"';
        echo ' class="' . htmlspecialchars($time_class) . '" style="width: 80px;"';
        echo ' min="1" max="12" placeholder="HH"';
        echo ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($options['readonly'])) echo ' readonly';
        echo '>';
        echo '</div>';
        echo '<div class="col-auto" style="display: flex; align-items: center;"><strong>:</strong></div>';
        echo '<div class="col-auto">';
        echo '<input type="number"';
        echo ' id="' . htmlspecialchars($time_minute_id) . '"';
        echo ' name="' . htmlspecialchars($time_name . '_minute') . '"';
        echo ' class="' . htmlspecialchars($time_class) . '" style="width: 80px;"';
        echo ' min="0" max="59" placeholder="MM"';
        echo ' value="' . htmlspecialchars($minute) . '"';
        if (!empty($options['readonly'])) echo ' readonly';
        echo '>';
        echo '</div>';
        echo '<div class="col-auto">';
        echo '<select';
        echo ' id="' . htmlspecialchars($time_ampm_id) . '"';
        echo ' name="' . htmlspecialchars($time_name . '_ampm') . '"';
        echo ' class="form-select"';
        if (!empty($options['readonly'])) echo ' disabled';
        echo '>';
        echo '<option value="AM"' . ($ampm === 'AM' ? ' selected' : '') . '>AM</option>';
        echo '<option value="PM"' . ($ampm === 'PM' ? ' selected' : '') . '>PM</option>';
        echo '</select>';
        echo '</div>';
        echo '</div>';

        echo '<input type="hidden"';
        echo ' id="' . htmlspecialchars($time_id) . '"';
        echo ' value="' . htmlspecialchars($time_value) . '"';
        echo '>';

        if (isset($this->errors[$time_name])) {
            echo '<div class="invalid-feedback d-block">';
            foreach ($this->errors[$time_name] as $error) {
                echo htmlspecialchars($error) . '<br>';
            }
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

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

    /**
     * Output a textarea field with optional rich text editor (Trumbowyg)
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (rows, value, placeholder, htmlmode, etc)
     */
    public function textbox($name, $label, $options = []) {
        $rows = $options['rows'] ?? 5;
        $cols = $options['cols'] ?? 80;
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $htmlmode = $options['htmlmode'] ?? 'no';

        if ($htmlmode === 'yes') {
            // Load Trumbowyg CSS and dynamically load scripts after jQuery is ready
            echo '<link rel="stylesheet" href="/assets/vendor/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">';
            echo '<script type="text/javascript">
            $(function() {
                // Ensure jQuery is available in window scope for UMD modules
                window.jQuery = $;
                // Temporarily disable module detection to force browser global approach
                var originalDefine = window.define;
                var originalExports = window.exports;
                delete window.define;
                delete window.exports;

                var scripts = [
                    "/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js",
                    "/assets/vendor/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"
                ];

                // Load scripts sequentially using jQuery.getScript
                function loadNextScript(index) {
                    if (index >= scripts.length) {
                        // All scripts loaded, restore module detection
                        if (originalDefine) window.define = originalDefine;
                        if (originalExports) window.exports = originalExports;

                        if (typeof $.fn.trumbowyg === "function") {
                            $(".html_editable").trumbowyg({
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
                            $(".trumbowyg-editor").attr("name", "trumbobox");
                        }
                        return;
                    }

                    $.getScript(scripts[index])
                        .done(function() {
                            loadNextScript(index + 1);
                        });
                }

                loadNextScript(0);
            });
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
        $value = $options['value'] ?? null;
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

        foreach ($optionvals as $key => $val) {
            if ($forcestrict && $value === $val) {
                // Note: $key contains HTML (image label with <img> tag), do NOT escape it
                $output .= '<input type="radio" id="' . htmlspecialchars($val) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($val) . '" checked="checked" /><label for="' . htmlspecialchars($val) . '_id"> ' . $key . '</label>';
            } elseif ($value == $val) {
                // Note: $key contains HTML (image label with <img> tag), do NOT escape it
                $output .= '<input type="radio" id="' . htmlspecialchars($val) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($val) . '" checked="checked" /><label for="' . htmlspecialchars($val) . '_id"> ' . $key . '</label>';
            } else {
                // Note: $key contains HTML (image label with <img> tag), do NOT escape it
                $output .= '<input type="radio" id="' . htmlspecialchars($val) . '_id" name="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($val) . '" /><label for="' . htmlspecialchars($val) . '_id"> ' . $key . '</label>';
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

        echo '<div id="' . htmlspecialchars($id) . '_container" class="mb-3 errorplacement">';
        if ($label) {
            echo '<label class="form-label">' . htmlspecialchars($label) . '</label>';
        }

        foreach ($optionvals as $key => $value) {
            $uniqid = $id . '_' . htmlspecialchars($value);
            $is_checked = in_array($value, $checked) ? 'checked="checked"' : '';
            $is_disabled = in_array($value, $disabled) ? 'disabled="disabled"' : '';

            // Readonly means it cannot be changed but is submitted
            if (in_array($value, $readonly)) {
                if (in_array($value, $checked)) {
                    echo '<input type="hidden" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" />';
                }
                echo '<div class="form-check">';
                echo '<input class="form-check-input" type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" ' . $is_checked . ' disabled="disabled" />';
                echo '<label class="form-check-label" for="' . htmlspecialchars($uniqid) . '">' . htmlspecialchars($key) . '</label>';
                echo '</div>';
            } else {
                echo '<div class="form-check">';
                echo '<input class="form-check-input" type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" ' . $is_checked . ' ' . $is_disabled . ' />';
                echo '<label class="form-check-label" for="' . htmlspecialchars($uniqid) . '">' . htmlspecialchars($key) . '</label>';
                echo '</div>';
            }
        }

        echo '</div>';
    }
}
