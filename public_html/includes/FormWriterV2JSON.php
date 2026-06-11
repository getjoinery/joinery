<?php
/**
 * FormWriter v2 JSON Renderer
 *
 * Serializes a form as a JSON-encodable definition (fields, labels, values,
 * validation rules, visibility rules) instead of HTML. Served by
 * GET /api/v1/form/{action_name} so native apps can render any platform form
 * with one generic renderer. Inherits all behavioral logic (model autofill,
 * validation auto-detection, value resolution) from FormWriterV2Base's
 * prepare*Data() methods; each render*() accumulates the prepared data into
 * the definition rather than producing markup.
 *
 * Unsupported constructs fail loudly: a builder that uses JavaScript hooks
 * (custom_script, onchange) or a non-serializable field type (file, image,
 * rich text, repeater) throws, so the problem is caught when the form
 * definition is first built — never silently dropped in production.
 *
 * CSRF is forced off: API requests authenticate via key headers, which
 * browsers never attach cross-origin, and the CSRF token is bound to a
 * web session that API clients do not have.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

class FormWriterV2JSON extends FormWriterV2Base {

    const SCHEMA_VERSION = 1;

    protected $definition_fields = [];
    protected $submit_label = null;

    public function __construct($form_id, $options = []) {
        $options['csrf'] = false;
        parent::__construct($form_id, $options);
    }

    /**
     * The complete form definition (schema v1).
     *
     * @return array JSON-encodable definition
     */
    public function getDefinition() {
        $fields = $this->definition_fields;

        if ($this->edit_primary_key_value !== null) {
            array_unshift($fields, [
                'type' => 'hidden',
                'name' => 'edit_primary_key_value',
                'value' => (string)$this->edit_primary_key_value,
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'form' => [
                'name' => $this->form_id,
                'submit_to' => $this->options['submit_to'] ?? ('/api/v1/action/' . $this->form_id),
                'submit_label' => $this->submit_label ?? 'Submit',
            ],
            'fields' => $fields,
        ];
    }

    // ── Definition assembly helpers ───────────────────────────────────────────

    /**
     * Common keys shared by most field types, including the field's effective
     * validation rules (model auto-detected + builder-provided, captured by
     * registerField()).
     */
    protected function commonKeys($data) {
        $field = [
            'name' => $data['name'],
            'label' => $data['label'] ?? '',
            'required' => !empty($data['required']),
            'readonly' => !empty($data['readonly']),
            'disabled' => !empty($data['disabled']),
            'helptext' => $data['helptext'] ?? '',
        ];

        $validation = $this->fields[$data['name']]['validation'] ?? [];
        // The unique rule carries table internals and can only be checked
        // server-side; it never belongs in a client-facing definition.
        unset($validation['unique']);
        if ($validation) {
            $field['validation'] = $validation;
        }

        return $field;
    }

    /**
     * Append a field to the definition, dropping empty/false keys so the
     * serialized form stays minimal (absence of a flag means false).
     * Always returns '' — render*() return values feed handleOutput(),
     * which must output nothing in JSON mode.
     */
    protected function addField($field) {
        $this->definition_fields[] = array_filter($field, function ($v) {
            return $v !== null && $v !== '' && $v !== false && $v !== [];
        });
        return '';
    }

    /**
     * Throw if the prepared data carries JavaScript hooks (loud-failure rule).
     */
    protected function assertSerializable($data) {
        foreach (['custom_script', 'onchange'] as $key) {
            if (!empty($data[$key])) {
                throw new Exception('FormWriterV2JSON: field "' . $data['name'] . '" uses "' . $key
                    . '" — JavaScript cannot be serialized into a JSON form definition');
            }
        }
    }

    protected function unsupported($what) {
        throw new Exception('FormWriterV2JSON: ' . $what
            . ' is not supported in JSON form definitions (schema v' . self::SCHEMA_VERSION . ')');
    }

    // ── render*() implementations (accumulate definition data) ───────────────

    protected function renderTextInput($data) {
        $this->assertSerializable($data);
        $field = array_merge(['type' => 'text'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
            'placeholder' => $data['placeholder'] ?? '',
            'prepend' => $data['prepend'] ?? '',
            // HTML input subtype (email, url, tel...) as a keyboard/format hint
            'input_type' => ($data['type'] !== 'text' && $data['type'] !== 'password') ? $data['type'] : null,
            'pattern' => $data['pattern'] ?? '',
            'minlength' => $data['minlength'] ?? null,
            'maxlength' => $data['maxlength'] ?? null,
            'min' => $data['min'] ?? null,
            'max' => $data['max'] ?? null,
            'step' => $data['step'] ?? null,
        ]);
        return $this->addField($field);
    }

    protected function renderPasswordInput($data) {
        $this->assertSerializable($data);
        // Never serialize a password value — definitions travel to clients.
        $field = array_merge(['type' => 'password'], $this->commonKeys($data), [
            'placeholder' => $data['placeholder'] ?? '',
            'strength_meter' => !empty($data['strength_meter']),
            'minlength' => $data['minlength'] ?? null,
            'maxlength' => $data['maxlength'] ?? null,
            'autocomplete' => $data['autocomplete'] ?? '',
        ]);
        return $this->addField($field);
    }

    protected function renderNumberInput($data) {
        $this->assertSerializable($data);
        $field = array_merge(['type' => 'number'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
            'placeholder' => $data['placeholder'] ?? '',
            'min' => $data['min'] ?? null,
            'max' => $data['max'] ?? null,
            'step' => $data['step'] ?? null,
        ]);
        return $this->addField($field);
    }

    protected function renderDropInput($data) {
        $this->assertSerializable($data);
        $field = array_merge(['type' => 'drop'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
            'options' => $data['options_list'] ?? [],
            'empty_option' => $data['empty_option'] ?? null,
            'multiple' => !empty($data['multiple']),
            // ajaxendpoint serializes as search_endpoint: the renderer performs
            // the same debounced search fetch the web JS does
            'search_endpoint' => $data['ajaxendpoint'] ?? '',
            'visibility_rules' => $data['visibility_rules'] ?? null,
        ]);
        return $this->addField($field);
    }

    protected function renderCheckboxInput($data) {
        $this->assertSerializable($data);
        $field = array_merge(['type' => 'checkbox'], $this->commonKeys($data), [
            'checked_value' => (string)$data['checked_value'],
            'is_checked' => !empty($data['is_checked']),
            'visibility_rules' => $data['visibility_rules'] ?? null,
        ]);
        return $this->addField($field);
    }

    protected function renderRadioInput($data) {
        $this->assertSerializable($data);
        $field = array_merge(['type' => 'radio'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
            'options' => $data['options_list'] ?? [],
        ]);
        return $this->addField($field);
    }

    protected function renderCheckboxList($data) {
        $this->assertSerializable($data);
        // checkboxList's disabled/readonly are arrays of option values, not
        // booleans — serialized under distinct keys. Submits as an array
        // under the field name.
        $field = [
            'type' => 'checkbox_list',
            'name' => $data['name'],
            'label' => $data['label'] ?? '',
            'options' => $data['options_list'] ?? [],
            'checked' => array_values(array_map('strval', $data['checked'] ?? [])),
            'disabled_values' => array_values(array_map('strval', $data['disabled'] ?? [])),
            'readonly_values' => array_values(array_map('strval', $data['readonly'] ?? [])),
            'list_type' => $data['type'] ?? 'checkbox',
            'helptext' => $data['helptext'] ?? '',
        ];
        $validation = $this->fields[$data['name']]['validation'] ?? [];
        unset($validation['unique']);
        if ($validation) {
            $field['validation'] = $validation;
        }
        return $this->addField($field);
    }

    protected function renderDateInput($data) {
        $this->assertSerializable($data);
        // Submits as a single key: name => YYYY-MM-DD
        $field = array_merge(['type' => 'date'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
            'min' => $data['min'] ?? null,
            'max' => $data['max'] ?? null,
        ]);
        return $this->addField($field);
    }

    protected function renderTimeInput($data) {
        $this->assertSerializable($data);
        // Submits as a single key: name => HH:MM (24-hour), matching the
        // hidden input the web time widget keeps in sync
        $field = array_merge(['type' => 'time'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
        ]);
        return $this->addField($field);
    }

    protected function renderDateTimeInput($data) {
        $this->assertSerializable($data);
        // Compound submit contract: the same multi-part POST keys the web
        // form produces, so process_datetimeinput() and logic files work
        // unchanged. Values are in the user's timezone, exactly as on the web.
        $field = [
            'type' => 'datetime',
            'name' => $data['name'],
            'label' => $data['label'] ?? '',
            'readonly' => !empty($data['readonly']),
            'disabled' => !empty($data['disabled']),
            'helptext' => $data['helptext'] ?? '',
            'date_value' => $data['date_value'] ?? '',
            'hour' => ($data['hour'] === null || $data['hour'] === '') ? '' : (string)$data['hour'],
            'minute' => ($data['minute'] === null || $data['minute'] === '') ? '' : (string)$data['minute'],
            'ampm' => $data['ampm'] ?? '',
            'submit_parts' => [
                'date' => $data['date_name'],                  // YYYY-MM-DD
                'hour' => $data['time_name'] . '_hour',        // 1-12
                'minute' => $data['time_name'] . '_minute',    // 0-59
                'ampm' => $data['time_name'] . '_ampm',        // AM | PM
            ],
        ];
        return $this->addField($field);
    }

    protected function renderHiddenInput($data) {
        return $this->addField([
            'type' => 'hidden',
            'name' => $data['name'],
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
        ]);
    }

    protected function renderSubmitButton($data) {
        // One submit per form in schema v1 — a second button means the form
        // cannot be represented faithfully, so fail loudly.
        if ($this->submit_label !== null) {
            $this->unsupported('a second submit button ("' . $data['name'] . '")');
        }
        $this->submit_label = $data['label'] ?: 'Submit';
        return '';
    }

    protected function renderTextarea($data) {
        $this->assertSerializable($data);
        $field = array_merge(['type' => 'textarea'], $this->commonKeys($data), [
            'value' => ($data['value'] === null) ? '' : (string)$data['value'],
            'placeholder' => $data['placeholder'] ?? '',
            'minlength' => $data['minlength'] ?? null,
            'maxlength' => $data['maxlength'] ?? null,
        ]);
        return $this->addField($field);
    }

    // ── Unsupported field types (fail loudly at definition time) ─────────────

    protected function renderFileInput($data) {
        $this->unsupported('fileinput (field "' . $data['name'] . '")');
    }

    protected function renderImageInput($data) {
        $this->unsupported('imageinput (field "' . $data['name'] . '")');
    }

    protected function renderTextbox($data) {
        $this->unsupported('textbox / rich text (field "' . $data['name'] . '")');
    }

    public function repeater($name, $label = '', $options = []) {
        $this->unsupported('repeater (field "' . $name . '")');
    }

    public function imageselector($name, $label = '', $options = []) {
        $this->unsupported('imageselector (field "' . $name . '")');
    }

    public function colorpicker($name, $label = '', $options = []) {
        $this->unsupported('colorpicker (field "' . $name . '")');
    }

    public function file_upload_full($getvars = NULL, $delete = FALSE, $checkall = FALSE) {
        $this->unsupported('file_upload_full');
    }

    public function antispam_question_input($type = NULL) {
        $this->unsupported('antispam_question_input (web bot defence — keep it in the web view, not the builder)');
    }

    public function honeypot_hidden_input($label = '', $type = '') {
        $this->unsupported('honeypot_hidden_input (web bot defence — keep it in the web view, not the builder)');
    }

    public function captcha_hidden_input($type = NULL) {
        $this->unsupported('captcha_hidden_input (web bot defence — keep it in the web view, not the builder)');
    }

    // ── Output suppression (a definition has no markup or scripts) ───────────

    public function begin_form() {
        // No output in JSON mode
    }

    public function end_form() {
        // No output in JSON mode
    }

    /**
     * Visibility rules serialize as field data (see renderDropInput /
     * renderCheckboxInput); validate them as the web renderers do, but emit
     * no script.
     */
    protected function generateVisibilityScript($fieldName, $fieldId, $rules) {
        $this->validateVisibilityRules($fieldId, $rules);
        return '';
    }

    protected function generateFieldScript($fieldId, $scriptBody) {
        $this->unsupported('custom_script (field id "' . $fieldId . '")');
    }
}
