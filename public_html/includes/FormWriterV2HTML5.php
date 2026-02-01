<?php
/**
 * FormWriter v2 HTML5 Implementation
 *
 * Pure HTML5 form generation with semantic markup and no CSS framework dependencies.
 * Provides accessible, standards-compliant forms that any theme can style.
 *
 * @version 2.0.5
 * @changelog 2.0.5 - Added public textbox() method with Trumbowyg rich text editor support (htmlmode option)
 * @changelog 2.0.4 - Fixed placeholder to only show when field is empty (matches Bootstrap behavior)
 * @changelog 2.0.3 - Added inline flex layout for time and datetime inputs to display fields side-by-side
 * @changelog 2.0.2 - Changed fieldset/legend to div/label for radio and checkbox groups to match Bootstrap styling
 * @changelog 2.0.1 - Fixed outputRadioInput to iterate through options array and display all radio buttons
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2HTML5 extends FormWriterV2Base {

    /**
     * Output a text input field with HTML5 markup
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
        $type = $options['type'] ?? 'text';  // Support email, tel, url, number, search, etc.

        // Determine if field has errors
        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        // Output label
        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        // Output input
        $html .= '<input type="' . htmlspecialchars($type) . '"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        // Only show placeholder if field is empty
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
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        if (!empty($options['pattern'])) {
            $html .= ' pattern="' . htmlspecialchars($options['pattern']) . '"';
        }
        if (isset($options['min'])) {
            $html .= ' min="' . htmlspecialchars($options['min']) . '"';
        }
        if (isset($options['max'])) {
            $html .= ' max="' . htmlspecialchars($options['max']) . '"';
        }
        if (isset($options['minlength'])) {
            $html .= ' minlength="' . intval($options['minlength']) . '"';
        }
        if (isset($options['maxlength'])) {
            $html .= ' maxlength="' . intval($options['maxlength']) . '"';
        }
        if (isset($options['step'])) {
            $html .= ' step="' . htmlspecialchars($options['step']) . '"';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        // ARIA attributes for accessibility
        if ($has_errors) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
        }

        $html .= '>';

        // Display any errors for this field
        if ($has_errors) {
            $html .= '<div id="' . htmlspecialchars($name) . '_error" class="form-error">';
            $html .= '<ul class="error-list">';
            foreach ($this->errors[$name] as $error) {
                $html .= '<li>' . htmlspecialchars($error) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        // Display help text if provided
        if (!empty($options['helptext'])) {
            $html .= '<small class="form-help">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a password input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputPasswordInput($name, $label, $options) {
        // Password input is essentially a text input with type="password"
        $options['type'] = 'password';
        $this->outputTextInput($name, $label, $options);
    }

    /**
     * Output a dropdown select field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options (must include 'options' array)
     */
    protected function outputDropInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $select_options = $options['options'] ?? [];
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $ajaxendpoint = $options['ajaxendpoint'] ?? '';

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
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
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        // ARIA attributes
        if ($has_errors) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
        }

        $html .= '>';

        // Default empty option
        if (!empty($options['empty_option'])) {
            $html .= '<option value="">' . htmlspecialchars($options['empty_option']) . '</option>';
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
      input.placeholder = \'Type to search...\';

      const list = document.createElement(\'datalist\');
      list.id = selectEl.id + \'_list\';
      input.setAttribute(\'list\', list.id);

      selectEl.style.display = \'none\';
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
          const matching = this.data.find(item => item.text === inputVal);
          if (matching) {
            let option = selectEl.querySelector(\'option[value="\' + matching.id + \'"]\');
            if (!option) {
              option = document.createElement(\'option\');
              option.value = matching.id;
              option.textContent = matching.text;
              selectEl.innerHTML = \'\';
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
        opt.value = item.text;
        opt.dataset.id = item.id;
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

        $this->handleOutput($name, $html);
    }

    /**
     * Output a single checkbox input
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputCheckboxInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $checked_value = $options['checked_value'] ?? '1';
        $class = $options['class'] ?? '';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-check">';

        $html .= '<input type="checkbox"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="form-check-input' . ($class ? ' ' . htmlspecialchars($class) : '') . '"';
        $html .= ' value="' . htmlspecialchars($checked_value) . '"';

        if ((string)$value === (string)$checked_value) {
            $html .= ' checked';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        if ($has_errors) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
        }

        $html .= '>';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-check-label">' . htmlspecialchars($label) . '</label>';
        }

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

        $this->handleOutput($name, $html);
    }

    /**
     * Output radio button group
     *
     * @param string $name Field name
     * @param string $label Group label
     * @param array $options Field options (must include 'options' array)
     */
    protected function outputRadioInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $radio_options = $options['options'] ?? [];
        $class = $options['class'] ?? '';

        $has_errors = isset($this->errors[$name]);

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        // Group label
        if ($label) {
            $html .= '<label class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        // Output each radio option
        foreach ($radio_options as $opt_value => $opt_label) {
            $id = $name . '_' . $opt_value;

            $html .= '<div class="form-check">';
            $html .= '<input type="radio"';
            $html .= ' name="' . htmlspecialchars($name) . '"';
            $html .= ' id="' . htmlspecialchars($id) . '"';
            $html .= ' class="form-check-input' . ($class ? ' ' . htmlspecialchars($class) : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$value === (string)$opt_value) {
                $html .= ' checked';
            }
            if (!empty($options['disabled'])) {
                $html .= ' disabled';
            }
            if (!empty($options['required'])) {
                $html .= ' required';
            }
            if (!empty($options['onchange'])) {
                $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
            }

            if ($has_errors) {
                $html .= ' aria-invalid="true"';
                $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
            }

            $html .= '>';

            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-check-label">' . htmlspecialchars($opt_label) . '</label>';

            $html .= '</div>';
        }

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

        $this->handleOutput($name, $html);
    }

    /**
     * Output a list of checkboxes
     *
     * @param string $name Field name
     * @param string $label Group label
     * @param array $options Field options (must include 'options' array)
     */
    protected function outputCheckboxList($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? []);
        $checkbox_options = $options['options'] ?? [];
        $class = $options['class'] ?? '';

        // Ensure value is an array
        if (!is_array($value)) {
            $value = [$value];
        }

        $has_errors = isset($this->errors[$name]);

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        foreach ($checkbox_options as $opt_value => $opt_label) {
            $checkbox_id = $name . '_' . $opt_value;

            $html .= '<div class="form-check">';
            $html .= '<input type="checkbox"';
            $html .= ' name="' . htmlspecialchars($name) . '[]"';
            $html .= ' id="' . htmlspecialchars($checkbox_id) . '"';
            $html .= ' class="form-check-input' . ($class ? ' ' . htmlspecialchars($class) : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if (in_array((string)$opt_value, array_map('strval', $value))) {
                $html .= ' checked';
            }
            if (!empty($options['disabled'])) {
                $html .= ' disabled';
            }

            $html .= '>';
            $html .= '<label for="' . htmlspecialchars($checkbox_id) . '" class="form-check-label">' . htmlspecialchars($opt_label) . '</label>';
            $html .= '</div>';
        }

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

        $this->handleOutput($name, $html);
    }

    /**
     * Output a date input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        // Format date value for HTML5 date input (YYYY-MM-DD)
        if ($value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                $date = new DateTime($value);
                $value = $date->format('Y-m-d');
            } catch (Exception $e) {
                // If date parsing fails, leave value as is
            }
        }

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<input type="date"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        if (isset($options['min'])) {
            $html .= ' min="' . htmlspecialchars($options['min']) . '"';
        }
        if (isset($options['max'])) {
            $html .= ' max="' . htmlspecialchars($options['max']) . '"';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        if ($has_errors) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
        }

        $html .= '>';

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

        $this->handleOutput($name, $html);
    }

    /**
     * Output a time input field with hour/minute/AM-PM selectors
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTimeInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $format = $options['format'] ?? '12hour';  // 12hour or 24hour

        $has_errors = isset($this->errors[$name]);

        // Parse time value using base class helper
        $parsed = $this->parseTimeValue($value);

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        // Hidden field to store the actual time value (24-hour format)
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($value) . '">';

        // Time input controls wrapper - inline flex layout
        $html .= '<div class="time-input-group" style="display: flex; align-items: center; gap: 8px;">';

        if ($format === '12hour') {
            // Hour selector (1-12)
            $html .= '<select id="' . htmlspecialchars($id) . '_hour" class="' . htmlspecialchars($class) . '" style="width: auto;">';
            $html .= '<option value="">HH</option>';
            for ($i = 1; $i <= 12; $i++) {
                $selected = ($parsed['hour'] == $i) ? ' selected' : '';
                $html .= '<option value="' . $i . '"' . $selected . '>' . str_pad($i, 2, '0', STR_PAD_LEFT) . '</option>';
            }
            $html .= '</select>';

            $html .= '<span class="time-separator" style="font-weight: bold;">:</span>';

            // Minute selector (00-59)
            $html .= '<select id="' . htmlspecialchars($id) . '_minute" class="' . htmlspecialchars($class) . '" style="width: auto;">';
            $html .= '<option value="">MM</option>';
            for ($i = 0; $i < 60; $i++) {
                $selected = ($parsed['minute'] == $i) ? ' selected' : '';
                $html .= '<option value="' . $i . '"' . $selected . '>' . str_pad($i, 2, '0', STR_PAD_LEFT) . '</option>';
            }
            $html .= '</select>';

            // AM/PM selector
            $html .= '<select id="' . htmlspecialchars($id) . '_ampm" class="' . htmlspecialchars($class) . '" style="width: auto;">';
            $html .= '<option value="AM"' . ($parsed['ampm'] === 'AM' ? ' selected' : '') . '>AM</option>';
            $html .= '<option value="PM"' . ($parsed['ampm'] === 'PM' ? ' selected' : '') . '>PM</option>';
            $html .= '</select>';
        } else {
            // 24-hour format
            $html .= '<input type="time"';
            $html .= ' id="' . htmlspecialchars($id) . '_time"';
            $html .= ' class="' . htmlspecialchars($class) . '"';
            $html .= ' value="' . htmlspecialchars($value) . '"';
            if (!empty($options['readonly'])) {
                $html .= ' readonly';
            }
            if (!empty($options['disabled'])) {
                $html .= ' disabled';
            }
            if (!empty($options['required'])) {
                $html .= ' required';
            }
            $html .= '>';
        }

        $html .= '</div>';

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

        // Output JavaScript for time synchronization (12-hour format only)
        if ($format === '12hour') {
            $html .= $this->outputTimeInputJavaScript($id);
        } else {
            // For 24-hour format, sync the HTML5 time input to the hidden field
            $html .= '<script>
(function() {
    const timeInput = document.getElementById(\'' . htmlspecialchars($id) . '_time\');
    const hiddenInput = document.getElementById(\'' . htmlspecialchars($id) . '\');

    if (timeInput && hiddenInput) {
        timeInput.addEventListener(\'change\', function() {
            hiddenInput.value = timeInput.value;
        });
    }
})();
</script>';
        }

        $this->handleOutput($name, $html);
    }

    /**
     * Output a datetime input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateTimeInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        // For datetime, we'll use separate date and time inputs
        $date_value = '';
        $time_value = '';

        if ($value) {
            try {
                $dt = new DateTime($value);
                $date_value = $dt->format('Y-m-d');
                $time_value = $dt->format('H:i');
            } catch (Exception $e) {
                // If parsing fails, leave values empty
            }
        }

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '_date" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        // Hidden field to store combined datetime value
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($value) . '">';

        // Date and time inputs wrapper - inline flex layout
        $html .= '<div class="datetime-input-group" style="display: flex; align-items: center; gap: 8px;">';

        // Date input
        $html .= '<input type="date"';
        $html .= ' id="' . htmlspecialchars($id) . '_date"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($date_value) . '"';
        $html .= ' style="width: auto;"';
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        $html .= '>';

        // Time input
        $html .= '<input type="time"';
        $html .= ' id="' . htmlspecialchars($id) . '_time"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' value="' . htmlspecialchars($time_value) . '"';
        $html .= ' style="width: auto;"';
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        $html .= '>';

        $html .= '</div>';

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

        // JavaScript to sync date/time inputs to hidden field
        $html .= '<script>
(function() {
    const dateInput = document.getElementById(\'' . htmlspecialchars($id) . '_date\');
    const timeInput = document.getElementById(\'' . htmlspecialchars($id) . '_time\');
    const hiddenInput = document.getElementById(\'' . htmlspecialchars($id) . '\');

    function updateHiddenField() {
        if (dateInput.value && timeInput.value) {
            hiddenInput.value = dateInput.value + \' \' + timeInput.value + \':00\';
        } else if (dateInput.value) {
            hiddenInput.value = dateInput.value + \' 00:00:00\';
        } else {
            hiddenInput.value = \'\';
        }
    }

    if (dateInput && timeInput && hiddenInput) {
        dateInput.addEventListener(\'change\', updateHiddenField);
        timeInput.addEventListener(\'change\', updateHiddenField);
    }
})();
</script>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a file input field
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputFileInput($name, $label, $options) {
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;
        $accept = $options['accept'] ?? '';  // MIME types or file extensions

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' is-invalid';
        }

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<input type="file"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';

        if ($accept) {
            $html .= ' accept="' . htmlspecialchars($accept) . '"';
        }
        if (!empty($options['multiple'])) {
            $html .= ' multiple';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        if ($has_errors) {
            $html .= ' aria-invalid="true"';
            $html .= ' aria-describedby="' . htmlspecialchars($name) . '_error"';
        }

        $html .= '>';

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

        $this->handleOutput($name, $html);
    }

    /**
     * Output a hidden input field
     *
     * @param string $name Field name
     * @param array $options Field options
     */
    protected function outputHiddenInput($name, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $id = $options['id'] ?? $name;

        $html = '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= '>';

        $this->handleOutput($name, $html);
    }

    /**
     * Output a submit button
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

        $this->handleOutput($name, $html);
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

        // Only show placeholder if field is empty
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
     * Output a rich text editor (textbox) - protected method for abstract requirement
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextbox($name, $label, $options) {
        // Delegate to public textbox method
        $this->textbox($name, $label, $options);
    }

    /**
     * Output an image input field with preview
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputImageInput($name, $label, $options) {
        $value = $options['value'] ?? ($this->values[$name] ?? '');
        $class = $options['class'] ?? 'form-control';
        $id = $options['id'] ?? $name;

        $has_errors = isset($this->errors[$name]);

        $html = '<div id="' . htmlspecialchars($name) . '_container" class="form-group">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label);
            if (!empty($options['required'])) {
                $html .= ' <span aria-label="required">*</span>';
            }
            $html .= '</label>';
        }

        // Hidden input to store image path
        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="image-input-hidden"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= '>';

        // Display preview if value exists
        if ($value) {
            $html .= '<div class="image-preview" style="margin: 10px 0;">';
            $html .= '<img src="' . htmlspecialchars($value) . '" alt="Preview" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; padding: 5px;">';
            $html .= '</div>';
        }

        // Image selection button (placeholder)
        $html .= '<div style="margin-top: 10px;">';
        $html .= '<button type="button" class="btn btn-secondary" onclick="alert(\'Image selection not implemented\')">';
        $html .= 'Select Image';
        $html .= '</button>';
        $html .= '</div>';

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

        $this->handleOutput($name, $html);
    }

    /**
     * Output a textarea field
     *
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
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' rows="' . intval($rows) . '"';
        $html .= ' cols="' . intval($cols) . '"';

        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        if (!empty($options['required'])) {
            $html .= ' required';
        }
        if (!empty($options['placeholder'])) {
            $html .= ' placeholder="' . htmlspecialchars($options['placeholder']) . '"';
        }
        if (isset($options['minlength'])) {
            $html .= ' minlength="' . intval($options['minlength']) . '"';
        }
        if (isset($options['maxlength'])) {
            $html .= ' maxlength="' . intval($options['maxlength']) . '"';
        }
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
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

        $this->handleOutput($name, $html);
    }
}
