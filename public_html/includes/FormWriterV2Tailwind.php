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

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '<input type="text"';
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

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
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

        if (!empty($options['strength_meter'])) {
            $html .= '<div class="password-strength-meter mt-2">';
            $html .= '<div class="w-full bg-gray-200 rounded-full h-1.5">';
            $html .= '<div class="bg-blue-600 h-1.5 rounded-full" style="width: 0%"></div>';
            $html .= '</div>';
            $html .= '<p class="text-sm text-gray-500 mt-1 strength-text"></p>';
            $html .= '</div>';
        }

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '<textarea';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        $html .= ' rows="' . (int)$rows . '"';

        if ($placeholder) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
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
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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
        $ajaxendpoint = $options['ajaxendpoint'] ?? '';

        $has_errors = isset($this->errors[$name]);
        if ($has_errors) {
            $class .= ' border-red-500';
        }

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
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
        if (!empty($options['onchange'])) {
            $html .= ' onchange="' . htmlspecialchars($options['onchange']) . '"';
        }

        $html .= '>';

        if (!empty($options['empty_option'])) {
            $html .= '<option value="">' . htmlspecialchars($options['empty_option']) . '</option>';
        }

        foreach ($select_options as $opt_label => $opt_value) {
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
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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

        $html = '<div class="mb-4">';
        $html .= '<div class="flex items-center">';

        $html .= '<input type="checkbox"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500' . ($has_errors ? ' border-red-500' : '') . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';

        if ($checked) {
            $html .= ' checked';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }

        $html .= '>';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="ml-2 block text-sm text-gray-900">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '</div>';

        if ($has_errors) {
            foreach ($this->errors[$name] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        $html .= '</div>';

        // Check for visibility rules or custom scripts in options
        if (isset($options['visibility_rules']) && !empty($options['visibility_rules'])) {
            $html .= $this->generateVisibilityScript($name, $id, $options['visibility_rules']);
        } elseif (isset($options['custom_script']) && !empty($options['custom_script'])) {
            $html .= $this->generateFieldScript($id, $options['custom_script']);
        }

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
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

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700 mb-2">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '<div class="errorplacement space-y-2">';

        foreach ($radio_options as $opt_value => $opt_label) {
            $id = $name . '_' . $opt_value;

            $html .= '<div class="flex items-center">';
            $html .= '<input type="radio"';
            $html .= ' name="' . htmlspecialchars($name) . '"';
            $html .= ' id="' . htmlspecialchars($id) . '"';
            $html .= ' class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500' . ($has_errors ? ' border-red-500' : '') . '"';
            $html .= ' value="' . htmlspecialchars($opt_value) . '"';

            if ((string)$value === (string)$opt_value) {
                $html .= ' checked';
            }
            if (!empty($options['disabled'])) {
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
            foreach ($this->errors[$name] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
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
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
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
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
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
     * Output a submit button with Tailwind styling
     *
     * @param string $name Button name
     * @param string $label Button label
     * @param array $options Button options
     */
    protected function outputSubmitButton($name, $label, $options) {
        $class = $options['class'] ?? 'inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';
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
     * Output a checkbox list (or radio group) field with Tailwind styling
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
            $html = '<div class="rounded-md bg-yellow-50 p-4"><div class="text-sm text-yellow-800">No options available for ' . htmlspecialchars($name) . '</div></div>';
            if ($this->use_deferred_output) {
                $this->deferred_output[$name] = $html;
            } else {
                echo $html;
            }
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

        $html = '<div id="' . htmlspecialchars($id) . '_container" class="mb-4">';
        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<div class="mt-2 space-y-2">';

        foreach ($optionvals as $key => $value) {
            $uniqid = $id . '_' . htmlspecialchars($value);
            $is_checked = in_array($value, $checked) ? 'checked="checked"' : '';
            $is_disabled = in_array($value, $disabled) ? 'disabled="disabled"' : '';

            // Readonly means it cannot be changed but is submitted
            if (in_array($value, $readonly)) {
                if (in_array($value, $checked)) {
                    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" />';
                }
                $html .= '<div class="relative flex items-center">';
                $html .= '<input class="h-4 w-4 rounded border-gray-300 text-indigo-600" type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" ' . $is_checked . ' disabled="disabled" />';
                $html .= '<label for="' . htmlspecialchars($uniqid) . '" class="ml-3 block text-sm text-gray-700">' . htmlspecialchars($key) . '</label>';
                $html .= '</div>';
            } else {
                $html .= '<div class="relative flex items-center">';
                $html .= '<input class="h-4 w-4 rounded border-gray-300 text-indigo-600" type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($uniqid) . '" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" ' . $is_checked . ' ' . $is_disabled . ' />';
                $html .= '<label for="' . htmlspecialchars($uniqid) . '" class="ml-3 block text-sm text-gray-700">' . htmlspecialchars($key) . '</label>';
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        $html .= '</div>';

        // Either echo immediately or store for deferred output
        if ($this->use_deferred_output) {
            $this->deferred_output[$name] = $html;
        } else {
            echo $html;
        }
    }

    /**
     * Output a time input field (hour:minute AM/PM) with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTimeInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $class = $options['class'] ?? 'mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $id = $options['id'] ?? $name;
        $hour_id = $id . '_hour';
        $minute_id = $id . '_minute';
        $ampm_id = $id . '_ampm';

        $has_errors = isset($this->errors[$name]);
        $input_class = $class;
        if ($has_errors) {
            $input_class .= ' border-red-500';
        }

        // Use centralized time parsing helper
        $parsed = $this->parseTimeValue($value);
        $hour = $parsed['hour'];
        $minute = $parsed['minute'];
        $ampm = $parsed['ampm'];

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700">' . htmlspecialchars($label) . '</label>';
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
        $html .= ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
        if (!empty($options['disabled'])) $html .= ' disabled';
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
        $html .= ' value="' . htmlspecialchars($minute) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
        if (!empty($options['disabled'])) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';

        // AM/PM selector
        $html .= '<div class="flex-none">';
        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($id . '_ampm') . '"';
        $html .= ' class="' . htmlspecialchars($input_class) . '"';
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
            $html .= '<div class="mt-1 text-sm text-red-600">';
            foreach ($this->errors[$name] as $error) {
                $html .= htmlspecialchars($error) . '<br>';
            }
            $html .= '</div>';
        }

        if (!empty($options['helptext'])) {
            $html .= '<small class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);

        // Output shared JavaScript from base class
        $this->outputTimeInputJavaScript();

        // Add data attributes to trigger the sync
        $sync_html = '<div data-time-hour="' . htmlspecialchars($hour_id) . '"';
        $sync_html .= ' data-time-minute="' . htmlspecialchars($minute_id) . '"';
        $sync_html .= ' data-time-ampm="' . htmlspecialchars($ampm_id) . '"';
        $sync_html .= ' data-time-hidden="' . htmlspecialchars($id) . '"';
        $sync_html .= ' style="display:none;"></div>';
        echo $sync_html;
    }

    /**
     * Output separate date and time input fields with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputDateTimeInput($name, $label, $options) {
        // Derive date and time field names from the main name
        $date_name = $name . '_dateinput';
        $time_name = $name . '_timeinput';
        $date_value = $options['value'] ?? $options['date_value'] ?? '';
        $time_value = $options['time_value'] ?? '';
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $date_id = $options['date_id'] ?? $date_name;
        $time_id = $options['time_id'] ?? $time_name;

        // Extract date from datetime value if provided
        if ($date_value && strpos($date_value, ' ') !== false) {
            list($date_value, $time_value) = explode(' ', $date_value, 2);
        }

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label class="block text-sm font-medium text-gray-700">' . htmlspecialchars($label) . '</label>';
        }

        $html .= '<div class="grid grid-cols-2 gap-4 mt-1">';

        // Date input
        $date_class = $class;
        if (isset($this->errors[$date_name])) {
            $date_class .= ' border-red-500';
        }

        $html .= '<div>';
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
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }
        $html .= '</div>';

        // Time input - use centralized parseTimeValue helper
        $html .= '<div>';
        $time_class = $class;
        if (isset($this->errors[$time_name])) {
            $time_class .= ' border-red-500';
        }

        $parsed = $this->parseTimeValue($time_value);
        $hour = $parsed['hour'];
        $minute = $parsed['minute'];
        $ampm = $parsed['ampm'];

        $time_hour_id = $time_id . '_hour';
        $time_minute_id = $time_id . '_minute';
        $time_ampm_id = $time_id . '_ampm';

        $html .= '<div class="flex gap-2">';
        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_hour_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_hour') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 80px;"';
        $html .= ' min="1" max="12" placeholder="HH"';
        $html .= ' value="' . htmlspecialchars($hour) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
        $html .= '>';

        $html .= '<span class="flex items-center font-bold">:</span>';

        $html .= '<input type="number"';
        $html .= ' id="' . htmlspecialchars($time_minute_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_minute') . '"';
        $html .= ' class="' . htmlspecialchars($time_class) . '" style="width: 80px;"';
        $html .= ' min="0" max="59" placeholder="MM"';
        $html .= ' value="' . htmlspecialchars($minute) . '"';
        if (!empty($options['readonly'])) $html .= ' readonly';
        $html .= '>';

        $html .= '<select';
        $html .= ' id="' . htmlspecialchars($time_ampm_id) . '"';
        $html .= ' name="' . htmlspecialchars($time_name . '_ampm') . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        if (!empty($options['readonly'])) $html .= ' disabled';
        $html .= '>';
        $html .= '<option value="AM"' . ($ampm === 'AM' ? ' selected' : '') . '>AM</option>';
        $html .= '<option value="PM"' . ($ampm === 'PM' ? ' selected' : '') . '>PM</option>';
        $html .= '</select>';
        $html .= '</div>';

        $html .= '<input type="hidden"';
        $html .= ' id="' . htmlspecialchars($time_id) . '"';
        $html .= ' value="' . htmlspecialchars($time_value) . '"';
        $html .= '>';

        if (isset($this->errors[$time_name])) {
            foreach ($this->errors[$time_name] as $error) {
                $html .= '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars($error) . '</p>';
            }
        }
        $html .= '</div>';

        $html .= '</div>';

        if (!empty($options['helptext'])) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</p>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }


    /**
     * Output a rich text editor (Trumbowyg) field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputTextbox($name, $label, $options) {
        $value = $options['value'] ?? '';
        $id = $options['id'] ?? $name;
        $class = $options['class'] ?? 'trumbowyg-editor';

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '<textarea';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' class="' . htmlspecialchars($class) . '"';
        if (!empty($options['readonly'])) {
            $html .= ' readonly';
        }
        if (!empty($options['disabled'])) {
            $html .= ' disabled';
        }
        $html .= '>';
        $html .= htmlspecialchars($value);
        $html .= '</textarea>';

        if (!empty($options['helptext'])) {
            $html .= '<small class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</small>';
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

        $this->handleOutput($name, $html);
    }

    /**
     * Output an image input field with Tailwind styling
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param array $options Field options
     */
    protected function outputImageInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $id = $options['id'] ?? $name;
        $class = $options['class'] ?? 'mt-1 block w-full rounded-md border border-gray-300 shadow-sm';

        $html = '<div class="mb-4">';

        if ($label) {
            $html .= '<label for="' . htmlspecialchars($id) . '" class="block text-sm font-medium text-gray-700">';
            $html .= htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '<input type="hidden"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
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

        if (!empty($options['helptext'])) {
            $html .= '<small class="mt-1 text-sm text-gray-500">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        $html .= '</div>';

        $this->handleOutput($name, $html);
    }
}
