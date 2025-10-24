<?php
require_once(PathHelper::getIncludePath('includes/FormWriterBase.php'));

class FormWriterTailwind extends FormWriterBase {

	function start_form($id, $name, $action = null, $method = 'POST', $options = array(), $form_action_override = FALSE) {
		$onsubmit = isset($options['onsubmit']) ? $options['onsubmit'] : '';
		$class = isset($options['class']) ? $options['class'] : '';
		$enctype = isset($options['enctype']) ? $options['enctype'] : '';
		$data_attributes = isset($options['data']) ? $options['data'] : array();

		$output = '<form id="' . $id . '" name="' . $name . '" method="' . $method . '"';

		if ($action !== null) {
			$output .= ' action="' . $action . '"';
		}

		if ($onsubmit) {
			$output .= ' onsubmit="' . $onsubmit . '"';
		}

		if ($class) {
			$output .= ' class="' . $class . '"';
		}

		if ($enctype) {
			$output .= ' enctype="' . $enctype . '"';
		}

		foreach ($data_attributes as $key => $value) {
			$output .= ' data-' . $key . '="' . htmlspecialchars($value) . '"';
		}

		$output .= '>';

		if (!$form_action_override) {
			$output .= '<input type="hidden" name="action" value="' . str_replace('_', '-', $name) . '">';
		}

		return $output;
	}

	function end_form() {
		return '</form>';
	}

	function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255,
					  $readonly='', $autocomplete=TRUE, $formhint=FALSE,
					  $type='text', $layout='default') {
		$name = $id; // Use id as name for compatibility
		$placeholder = $hint ?: '';
		$required = ''; // Could be derived from other parameters if needed
		$data_attributes = array();

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"';
		$output .= ' class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"';

		if ($maxlength) {
			$output .= ' maxlength="' . $maxlength . '"';
		}

		if ($size) {
			$output .= ' size="' . $size . '"';
		}

		if ($placeholder) {
			$output .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
		}

		if ($readonly) {
			$output .= ' readonly="readonly"';
		}

		if (!$autocomplete) {
			$output .= ' autocomplete="off"';
		}

		foreach ($data_attributes as $key => $val) {
			$output .= ' data-' . $key . '="' . htmlspecialchars($val) . '"';
		}

		$output .= '>';
		$output .= '</div>';

		return $output;
	}

	function textarea($label, $name, $class = '', $rows = 5, $cols = 50, $value = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name;
		$placeholder = isset($options['placeholder']) ? $options['placeholder'] : '';
		$required = isset($options['required']) ? 'required' : '';
		$readonly = isset($options['readonly']) ? 'readonly' : '';

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<textarea id="' . $id . '" name="' . $name . '" rows="' . $rows . '" cols="' . $cols . '"';
		$output .= ' class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"';

		if ($placeholder) {
			$output .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
		}

		if ($required) {
			$output .= ' ' . $required;
		}

		if ($readonly) {
			$output .= ' ' . $readonly;
		}

		$output .= '>' . htmlspecialchars($value) . '</textarea>';
		$output .= '</div>';

		return $output;
	}

	function selectbox($label, $name, $options_array, $selected = '', $class = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name;
		$required = isset($options['required']) ? 'required' : '';
		$multiple = isset($options['multiple']) ? 'multiple' : '';

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<select id="' . $id . '" name="' . $name . '"';
		$output .= ' class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"';

		if ($required) {
			$output .= ' ' . $required;
		}

		if ($multiple) {
			$output .= ' ' . $multiple;
		}

		$output .= '>';

		foreach ($options_array as $value => $display) {
			$selected_attr = ($value == $selected) ? ' selected' : '';
			$output .= '<option value="' . htmlspecialchars($value) . '"' . $selected_attr . '>' . htmlspecialchars($display) . '</option>';
		}

		$output .= '</select>';
		$output .= '</div>';

		return $output;
	}

	function checkbox($label, $name, $value = '1', $checked = false, $class = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name;

		$output = '<div class="' . $class . ' flex items-center mb-4">';
		$output .= '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"';
		$output .= ' class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"';

		if ($checked) {
			$output .= ' checked';
		}

		$output .= '>';
		$output .= '<label for="' . $id . '" class="ml-2 block text-sm text-gray-900">' . $label . '</label>';
		$output .= '</div>';

		return $output;
	}

	function radiobutton($label, $name, $value, $checked = false, $class = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name . '_' . $value;

		$output = '<div class="' . $class . ' flex items-center mb-2">';
		$output .= '<input type="radio" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"';
		$output .= ' class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"';

		if ($checked) {
			$output .= ' checked';
		}

		$output .= '>';
		$output .= '<label for="' . $id . '" class="ml-2 block text-sm text-gray-900">' . $label . '</label>';
		$output .= '</div>';

		return $output;
	}

	function fileinput($label, $id, $class, $size, $hint, $layout='default') {
		$name = $id; // Use id as name for compatibility
		$accept = '';
		$multiple = '';
		$required = '';

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<input type="file" id="' . $id . '" name="' . $name . '"';
		$output .= ' class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"';

		if ($accept) {
			$output .= ' accept="' . htmlspecialchars($accept) . '"';
		}

		if ($multiple) {
			$output .= ' ' . $multiple;
		}

		if ($required) {
			$output .= ' ' . $required;
		}

		$output .= '>';
		$output .= '</div>';

		return $output;
	}

	function hiddeninput($name, $value) {
		return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '">';
	}

	function submit($label, $name = 'submit', $class = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name;

		$output = '<button type="submit" id="' . $id . '" name="' . $name . '"';
		$output .= ' class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 ' . $class . '"';
		$output .= '>' . $label . '</button>';

		return $output;
	}

	function button($label, $onclick = '', $class = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : '';
		$type = isset($options['type']) ? $options['type'] : 'button';

		$output = '<button type="' . $type . '"';

		if ($id) {
			$output .= ' id="' . $id . '"';
		}

		if ($onclick) {
			$output .= ' onclick="' . htmlspecialchars($onclick) . '"';
		}

		$output .= ' class="inline-flex justify-center rounded-md border border-gray-300 bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 ' . $class . '"';
		$output .= '>' . $label . '</button>';

		return $output;
	}

	function start_fieldset($legend = '', $class = '') {
		$output = '<fieldset class="border border-gray-300 rounded-md p-4 mb-4 ' . $class . '">';
		if ($legend) {
			$output .= '<legend class="text-sm font-medium text-gray-900 px-2">' . $legend . '</legend>';
		}
		return $output;
	}

	function end_fieldset() {
		return '</fieldset>';
	}

	function start_buttons($class = '') {
		return '<div class="flex items-center justify-end gap-x-4 border-t border-gray-200 pt-4 mt-6 ' . $class . '">';
	}

	function end_buttons() {
		return '</div>';
	}

	function new_form_button($label='Submit', $style='primary', $width='standard', $class='', $id=NULL) {
		if ($style == 'submit' || $style == 'primary') {
			return $this->submit($label, $id ?: 'submit', $class);
		} else {
			return $this->button($label, '', $class, array('type' => 'button', 'id' => $id));
		}
	}

	// Date/Time inputs using Tailwind styling
	function dateinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $layout='default') {
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, $autocomplete, $formhint, 'date', $layout);
	}

	function timeinput($label, $id, $class, $value, $hint, $layout='default') {
		return $this->textinput($label, $id, $class, 0, $value, $hint, 255, '', TRUE, FALSE, 'time', $layout);
	}

	function datetimeinput($label, $id, $class, $inputdatetime, $hint, $timehint, $datehint, $layout='default') {
		// For Tailwind implementation, combine the hints
		$combined_hint = $hint;
		if ($timehint && $datehint) {
			$combined_hint = $datehint . ' ' . $timehint;
		} elseif ($timehint) {
			$combined_hint = $timehint;
		} elseif ($datehint) {
			$combined_hint = $datehint;
		}
		return $this->textinput($label, $id, $class, 0, $inputdatetime, $combined_hint, 255, '', TRUE, FALSE, 'datetime-local', $layout);
	}

	// Email input with Tailwind styling
	function emailinput($label, $name, $class = '', $size = 30, $value = '', $options = array()) {
		$options['type'] = 'email';
		return $this->textinput($label, $name, $class, $size, $value, $options);
	}

	// Number input with Tailwind styling
	function numberinput($label, $name, $class = '', $value = '', $options = array()) {
		$options['type'] = 'number';
		$min = isset($options['min']) ? $options['min'] : '';
		$max = isset($options['max']) ? $options['max'] : '';
		$step = isset($options['step']) ? $options['step'] : '';

		$id = isset($options['id']) ? $options['id'] : $name;
		$placeholder = isset($options['placeholder']) ? $options['placeholder'] : '';
		$required = isset($options['required']) ? 'required' : '';
		$readonly = isset($options['readonly']) ? 'readonly' : '';

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<input type="number" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"';
		$output .= ' class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"';

		if ($min !== '') {
			$output .= ' min="' . $min . '"';
		}

		if ($max !== '') {
			$output .= ' max="' . $max . '"';
		}

		if ($step !== '') {
			$output .= ' step="' . $step . '"';
		}

		if ($placeholder) {
			$output .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
		}

		if ($required) {
			$output .= ' ' . $required;
		}

		if ($readonly) {
			$output .= ' ' . $readonly;
		}

		$output .= '>';
		$output .= '</div>';

		return $output;
	}

	// Password input with Tailwind styling
	function passwordinput($label, $id, $class, $size, $value, $hint,
						  $maxlength=255, $readonly="", $layout='default') {
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, true, false, 'password', $layout);
	}

	// URL input with Tailwind styling
	function urlinput($label, $name, $class = '', $size = 30, $value = '', $options = array()) {
		$options['type'] = 'url';
		return $this->textinput($label, $name, $class, $size, $value, $options);
	}

	// Search input with Tailwind styling
	function searchinput($label, $name, $class = '', $size = 30, $value = '', $options = array()) {
		$options['type'] = 'search';
		return $this->textinput($label, $name, $class, $size, $value, $options);
	}

	// Range input with Tailwind styling
	function rangeinput($label, $name, $class = '', $value = '', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name;
		$min = isset($options['min']) ? $options['min'] : '0';
		$max = isset($options['max']) ? $options['max'] : '100';
		$step = isset($options['step']) ? $options['step'] : '1';

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<input type="range" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"';
		$output .= ' min="' . $min . '" max="' . $max . '" step="' . $step . '"';
		$output .= ' class="mt-1 block w-full"';
		$output .= '>';
		$output .= '</div>';

		return $output;
	}

	// Color input with Tailwind styling
	function colorinput($label, $name, $class = '', $value = '#000000', $options = array()) {
		$id = isset($options['id']) ? $options['id'] : $name;

		$output = '<div class="' . $class . ' mb-4">';
		$output .= '<label for="' . $id . '" class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
		$output .= '<input type="color" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"';
		$output .= ' class="mt-1 block h-10 w-20 border border-gray-300 rounded cursor-pointer"';
		$output .= '>';
		$output .= '</div>';

		return $output;
	}

	// Alert/Message displays with Tailwind styling
	function error_message($message, $class = '') {
		$output = '<div class="rounded-md bg-red-50 p-4 mb-4 ' . $class . '">';
		$output .= '<div class="flex">';
		$output .= '<div class="flex-shrink-0">';
		$output .= '<svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">';
		$output .= '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />';
		$output .= '</svg>';
		$output .= '</div>';
		$output .= '<div class="ml-3">';
		$output .= '<p class="text-sm font-medium text-red-800">' . $message . '</p>';
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	function success_message($message, $class = '') {
		$output = '<div class="rounded-md bg-green-50 p-4 mb-4 ' . $class . '">';
		$output .= '<div class="flex">';
		$output .= '<div class="flex-shrink-0">';
		$output .= '<svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">';
		$output .= '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />';
		$output .= '</svg>';
		$output .= '</div>';
		$output .= '<div class="ml-3">';
		$output .= '<p class="text-sm font-medium text-green-800">' . $message . '</p>';
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	function warning_message($message, $class = '') {
		$output = '<div class="rounded-md bg-yellow-50 p-4 mb-4 ' . $class . '">';
		$output .= '<div class="flex">';
		$output .= '<div class="flex-shrink-0">';
		$output .= '<svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">';
		$output .= '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />';
		$output .= '</svg>';
		$output .= '</div>';
		$output .= '<div class="ml-3">';
		$output .= '<p class="text-sm font-medium text-yellow-800">' . $message . '</p>';
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	function info_message($message, $class = '') {
		$output = '<div class="rounded-md bg-blue-50 p-4 mb-4 ' . $class . '">';
		$output .= '<div class="flex">';
		$output .= '<div class="flex-shrink-0">';
		$output .= '<svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">';
		$output .= '<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />';
		$output .= '</svg>';
		$output .= '</div>';
		$output .= '<div class="ml-3">';
		$output .= '<p class="text-sm font-medium text-blue-800">' . $message . '</p>';
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	// Card/Panel components with Tailwind styling
	function start_card($title = '', $class = '') {
		$output = '<div class="bg-white overflow-hidden shadow rounded-lg ' . $class . '">';
		if ($title) {
			$output .= '<div class="px-4 py-5 sm:px-6 border-b border-gray-200">';
			$output .= '<h3 class="text-lg leading-6 font-medium text-gray-900">' . $title . '</h3>';
			$output .= '</div>';
		}
		$output .= '<div class="px-4 py-5 sm:p-6">';

		return $output;
	}

	function end_card() {
		return '</div></div>';
	}

	// Grid helpers with Tailwind styling
	function start_grid($cols = 2, $class = '') {
		return '<div class="grid grid-cols-' . $cols . ' gap-4 ' . $class . '">';
	}

	function end_grid() {
		return '</div>';
	}

	// Table helpers with Tailwind styling
	function start_table($headers = array(), $class = '') {
		$output = '<div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg ' . $class . '">';
		$output .= '<table class="min-w-full divide-y divide-gray-300">';

		if (!empty($headers)) {
			$output .= '<thead class="bg-gray-50">';
			$output .= '<tr>';
			foreach ($headers as $header) {
				$output .= '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . $header . '</th>';
			}
			$output .= '</tr>';
			$output .= '</thead>';
			$output .= '<tbody class="bg-white divide-y divide-gray-200">';
		}

		return $output;
	}

	function end_table() {
		return '</tbody></table></div>';
	}

	function table_row($cells = array(), $class = '') {
		$output = '<tr class="' . $class . '">';
		foreach ($cells as $cell) {
			$output .= '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">' . $cell . '</td>';
		}
		$output .= '</tr>';

		return $output;
	}

	// Override the multi_upload_button to use Tailwind styles
	protected function multi_upload_button($context, $id, $label, $disabled = false) {
		$disabled_attr = $disabled ? ' disabled' : '';
		$style_class = '';

		switch($context) {
			case 'browse':
				$style_class = 'bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded';
				break;
			case 'upload':
				$style_class = 'bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded';
				break;
			case 'clear':
				$style_class = 'bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded ml-2';
				break;
		}

		return '<button type="button" id="' . $id . '" class="' . $style_class . '"' . $disabled_attr . '>' . $label . '</button>';
	}

	// WYSIWYG with QuillJS for modern browsers (Tailwind doesn't have a built-in editor)
	function wysiwyg($label, $name, $class = '', $value = '', $height = '300px', $options = array()) {
			$id = isset($options['id']) ? $options['id'] : $name . '_editor';

			$output = '<div class="' . $class . ' mb-4">';
			$output .= '<label class="block text-sm font-medium text-gray-700 mb-1">' . $label . '</label>';
			$output .= '<div id="' . $id . '" style="height: ' . $height . ';" class="bg-white border border-gray-300 rounded-md">';
			$output .= htmlspecialchars($value);
			$output .= '</div>';
			$output .= '<input type="hidden" name="' . $name . '" id="' . $name . '" value="' . htmlspecialchars($value) . '">';
			$output .= '</div>';

			// Add QuillJS
			$output .= '<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">';
			$output .= '<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>';
			$output .= '<script>
				var quill_' . $name . ' = new Quill("#' . $id . '", {
					theme: "snow",
					modules: {
						toolbar: [
							[{ "header": [1, 2, 3, false] }],
							["bold", "italic", "underline", "strike"],
							[{ "list": "ordered"}, { "list": "bullet" }],
							["link", "image"],
							["clean"]
						]
					}
				});
				quill_' . $name . '.on("text-change", function() {
					document.getElementById("' . $name . '").value = quill_' . $name . '.root.innerHTML;
				});
			</script>';

			return $output;

	}

	// Additional methods for Bootstrap compatibility

	/**
	 * begin_form - Bootstrap-compatible form opening
	 */
	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'"';
		if($onsubmit){
			$output .= ' onsubmit="'.$onsubmit.'"';
		}
		$output .= '><fieldset>';
		return $output;
	}

	/**
	 * text - Read-only text display field
	 */
	function text($id, $label, $value, $class, $layout='default') {
		if($layout == 'default'){
			$output = '
			<div id="'.$id.'_container" class="mb-4 errorplacement">
			<label for="'.$id.'" class="block text-sm font-medium text-gray-700 mb-1">'.$label.'</label>
			<input class="bg-gray-100 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-2.5" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
			</div>';
		}
		else{
			$output = '
			<div class="grid grid-cols-12 gap-4 mb-4">
			  <label for="'.$id.'" class="col-span-2 text-sm font-medium text-gray-700 pt-2">'.$label.'</label>
			  <div class="col-span-10">
				<input class="bg-gray-100 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-2.5" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
			  </div>
			</div>';
		}
		return $output;
	}

	/**
	 * new_button - Bootstrap-compatible button
	 */
	function new_button($label='Submit', $link, $style='primary', $width='standard', $class='', $id=NULL) {
		$output = '';

		$btn_class = '';
		if($style == 'primary'){
			$btn_class = 'bg-blue-500 hover:bg-blue-700 text-white';
		}
		else{
			$btn_class = 'bg-gray-500 hover:bg-gray-700 text-white';
		}

		if($width == 'full'){
			$output .= '<div class="w-full">';
			$btn_class .= ' w-full';
		}

		$output .= '<a href="'.$link.'"><button type="button" class="font-bold py-2 px-4 rounded '.$btn_class.' '.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button></a>';
		if($width == 'full'){
			$output .= '</div>';
		}
		return $output;
	}

	/**
	 * textbox - Bootstrap-compatible textarea
	 */
	function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
		$output = '<div id="'.$id.'_container" class="mb-4 errorplacement">';
		$output .= '<label for="'.$id.'" class="block text-sm font-medium text-gray-700 mb-1">'.$label.'</label>';
		$output .= '<textarea name="'.$id.'" id="'.$id.'" rows="'.$rows.'" cols="'.$cols.'" ';
		$output .= 'class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 '.$class.'" ';
		if($hint){
			$output .= 'placeholder="'.$hint.'" ';
		}
		$output .= '>'.$value.'</textarea>';
		$output .= '</div>';
		return $output;
	}

	/**
	 * checkboxinput - Bootstrap-compatible checkbox
	 */
	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint, $layout='default'){
		$checked = ($value == $truevalue) ? 'checked="checked"' : '';

		if($layout == 'horizontal'){
			return '<div class="grid grid-cols-12 gap-4 mb-4">
						<div class="col-span-2 text-sm font-medium text-gray-700 pt-2">'.$label.'</div>
						<div class="col-span-10">
						  <div class="errorplacement">
							<div id="'.$id.'_container" class="flex items-center">
								<input class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' />
								<label for="'.$id.'" class="ml-2 text-sm font-medium text-gray-900"></label>
							</div>
						   </div>
						</div>
					</div>';
		}
		else{
			return '<div class="errorplacement mb-4">
					<div id="'.$id.'_container" class="flex items-center">
						<input class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' />
						<label for="'.$id.'" class="ml-2 text-sm font-medium text-gray-900">'.$label.'</label>
					</div>
				   </div>';
		}
	}

	/**
	 * checkboxList - Bootstrap-compatible checkbox group
	 */
	function checkboxList($label, $id, $class, $optionvals, $checkedvals=array(), $disabledvals=array(), $readonlyvals=array(), $hint='', $type='checkbox') {
		$output = '<div id="'.$id.'_container" class="mb-4 errorplacement">';
		if($label){
			$output .= '<label class="block text-sm font-medium text-gray-700 mb-2">'.$label.'</label>';
		}

		foreach($optionvals as $optionlabel => $optionvalue){
			$checked = in_array($optionvalue, $checkedvals) ? 'checked="checked"' : '';
			$disabled = in_array($optionvalue, $disabledvals) ? 'disabled="disabled"' : '';
			$readonly = in_array($optionvalue, $readonlyvals) ? 'readonly="readonly"' : '';

			$output .= '<div class="flex items-center mb-2">';
			$output .= '<input type="'.$type.'" id="'.$id.'_'.$optionvalue.'" name="'.$id.'[]" value="'.$optionvalue.'" ';
			$output .= 'class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" ';
			$output .= $checked.' '.$disabled.' '.$readonly.' />';
			$output .= '<label for="'.$id.'_'.$optionvalue.'" class="ml-2 text-sm font-medium text-gray-900">'.$optionlabel.'</label>';
			$output .= '</div>';
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * radioinput - Bootstrap-compatible radio group
	 */
	function radioinput($label, $id, $class, &$optionvals, $checkedval, $disabledvals, $readonlyvals, $hint) {
		return $this->checkboxList($label, $id, $class, $optionvals, array($checkedval), $disabledvals, $readonlyvals, $hint, 'radio');
	}

	/**
	 * dropinput - Bootstrap-compatible dropdown
	 */
	function dropinput($label, $id, $class, &$optionvals, $input, $hint, $showdefault=TRUE, $forcestrict=FALSE, $ajaxendpoint=FALSE, $imagedropdown=FALSE, $layout='default') {
		$output = '';

		if($ajaxendpoint){
			$output .= '<script>
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
        if (!e.target.value) {
          selectEl.value = \'\';
          selectEl.dispatchEvent(new Event(\'change\', { bubbles: true }));
        }
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
        fetch(this.ajaxUrl + \'?q=\' + encodeURIComponent(query))
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
    const select = document.getElementById(\''.$id.'\');
    if (select && select.dataset.ajaxEndpoint) {
      new AjaxSearchSelect(select, select.dataset.ajaxEndpoint);
    } else if (select) {
      new AjaxSearchSelect(select, \''.$ajaxendpoint.'\');
    }
  });
})();
</script>';
		}

		if($layout == 'horizontal'){
			$output .= '<div id="'.$id.'_container" class="errorplacement">
							<div class="grid grid-cols-12 gap-4 mb-4">
								<label for="'.$id.'" class="col-span-2 text-sm font-medium text-gray-700 pt-2">'.$label.'</label>
								<div class="col-span-10">';
		}
		else{
			$output .= '<div id="'.$id.'_container" class="mb-4 errorplacement">
							<label for="'.$id.'" class="block text-sm font-medium text-gray-700 mb-1">'.$label.'</label>';
		}

		$output .= '<select name="'.$id.'" id="'.$id.'" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">';

		if($showdefault){
			$default_text = ($showdefault === true) ? 'Choose One' : $showdefault;
			$selected = is_null($input) ? 'selected="selected"' : '';
			$output .=  '<option value="" '.$selected.'>'.$default_text.'</option>';
		}

		foreach ($optionvals as $key => $value) {
			$selected = '';
			if($forcestrict){
				if ($input === $value) {
					$selected = 'selected="selected"';
				}
			}
			else{
				if ($input == $value) {
					$selected = 'selected="selected"';
				}
			}
			$output .= '<option value="'. $value .'" '.$selected.'>' . $key . '</option>';
		}
		$output .= '</select>';

		if($layout == 'horizontal'){
			$output .= '</div></div>';
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * datetimeinput2 - Bootstrap-compatible datetime input
	 */
	function datetimeinput2($label, $id, $class, $value, $hint, $readonly=false, $formhint=FALSE, $layout='default'){
		$readonly_attr = $readonly ? 'readonly="readonly"' : '';

		$output = '<div id="'.$id.'_container" class="mb-4 errorplacement">';
		if($label){
			$output .= '<label for="'.$id.'" class="block text-sm font-medium text-gray-700 mb-1">'.$label.'</label>';
		}

		if($formhint){
			$output .= '<div class="flex">';
			$output .= '<span class="inline-flex items-center px-3 text-sm text-gray-900 bg-gray-200 border border-r-0 border-gray-300 rounded-l-md">'.$formhint.'</span>';
		}

		$output .= '<input type="datetime-local" name="'.$id.'" id="'.$id.'" value="'.$value.'" ';
		$output .= 'class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" ';
		if($hint){
			$output .= 'placeholder="'.$hint.'" ';
		}
		$output .= $readonly_attr.' />';

		if($formhint){
			$output .= '</div>';
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * imageinput - Bootstrap-compatible image selector
	 */
	function imageinput($label, $id, $class, &$optionvals, $input, $hint, $showdefault=TRUE, $forcestrict=TRUE, $ajaxendpoint=FALSE) {
		// For now, use regular dropdown for image selection
		// Could be enhanced with image preview functionality
		return $this->dropinput($label, $id, $class, $optionvals, $input, $hint, $showdefault, $forcestrict, $ajaxendpoint, true);
	}

}
?>