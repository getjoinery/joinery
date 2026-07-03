package com.getjoinery.android

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

/**
 * Live state for one rendered form: current values, visibility, field errors,
 * and the submission body builder. Pure logic — no networking — so it is fully
 * unit-testable; FormScreen owns the fetch/submit lifecycle. Backed by Compose
 * snapshot state so the renderer recomposes on change.
 */
class FormState(val definition: FormDefinition) {
    /** Single-value fields (text, password, drop, radio, checkbox state as its
     *  checked value or "", date "YYYY-MM-DD", time "HH:MM"). */
    val values = mutableStateMapOf<String, String>()
    /** Multi-value fields (checkbox_list selections, in option order). */
    val listValues = mutableStateMapOf<String, List<String>>()
    /** datetime fields hold epoch millis; converted to submit parts at build time. */
    val dateValues = mutableStateMapOf<String, Long>()
    /** Field name → message, from client-side checks or the server's 422 map. */
    val fieldErrors = mutableStateMapOf<String, String>()
    /** Form-level error (422 with no field map, transport failures…). */
    var formError by mutableStateOf<String?>(null)
    /** Names hidden by the current visibility evaluation. */
    var hiddenFields by mutableStateOf<Set<String>>(emptySet())
        private set

    init {
        for (field in definition.fields) {
            when (field.type) {
                is FormFieldType.Checkbox ->
                    values[field.name] = if (field.isChecked) field.checkedValue else ""
                is FormFieldType.CheckboxList ->
                    if (field.listType == "radio") {
                        values[field.name] = field.checked.firstOrNull() ?: ""
                    } else {
                        listValues[field.name] = field.checked
                    }
                is FormFieldType.Datetime -> {
                    val raw = field.value?.stringValue
                    val millis = if (raw != null) parseDatetime(raw) else null
                    if (millis != null) dateValues[field.name] = millis
                }
                else -> values[field.name] = field.value?.stringValue ?: ""
            }
        }
        evaluateVisibility()
    }

    // MARK: Visibility

    /**
     * Re-evaluate all trigger rules. Semantics mirror the web
     * (docs/formwriter.md § Field Visibility): a drop/radio keys on the selected
     * option value, a checkbox on `checked`/`unchecked` (with an optional
     * `default` rule as fallback). Hidden fields keep their values and still
     * submit, exactly like display:none on the web.
     */
    fun evaluateVisibility() {
        val hidden = LinkedHashSet<String>()
        for (field in definition.fields) {
            val rules = field.visibilityRules?.objectValue
            if (rules == null || rules.isEmpty()) continue
            val key = when (field.type) {
                is FormFieldType.Checkbox -> if ((values[field.name] ?: "").isEmpty()) "unchecked" else "checked"
                else -> values[field.name] ?: ""
            }
            val rule = field.visibilityRules?.get(key) ?: field.visibilityRules?.get("default") ?: continue
            (rule["hide"]?.arrayValue ?: emptyList()).mapNotNull { it.stringValue }.forEach { hidden.add(it) }
            (rule["show"]?.arrayValue ?: emptyList()).mapNotNull { it.stringValue }.forEach { hidden.remove(it) }
        }
        hiddenFields = hidden
    }

    fun isVisible(field: FormField): Boolean {
        if (field.type is FormFieldType.Hidden) return false
        return !hiddenFields.contains(field.name)
    }

    // MARK: Client-side checks

    /**
     * Required-field pass before submitting; fills [fieldErrors] and returns
     * whether the form may submit. The server remains the authority — this only
     * catches the obvious empties without a round-trip.
     */
    fun validateForSubmit(): Boolean {
        val errors = LinkedHashMap<String, String>()
        for (field in definition.fields) {
            if (!field.required || !isVisible(field)) continue
            val empty = when {
                field.type is FormFieldType.CheckboxList && field.listType != "radio" ->
                    (listValues[field.name] ?: emptyList()).isEmpty()
                field.type is FormFieldType.Datetime ->
                    dateValues[field.name] == null
                else -> (values[field.name] ?: "").isEmpty()
            }
            if (empty) errors[field.name] = "This field is required."
        }
        fieldErrors.clear()
        fieldErrors.putAll(errors)
        return errors.isEmpty()
    }

    /** Map a server error onto the form: field messages where given, the
     *  top-level message as the form error otherwise. */
    fun apply(error: JoineryApiError) {
        val fields = error.fieldErrors
        if (fields.isNotEmpty()) {
            fieldErrors.clear()
            fieldErrors.putAll(fields)
            formError = null
        } else {
            formError = error.displayMessage
        }
    }

    // MARK: Submission body

    /**
     * Build the JSON body for `POST /api/v1/action/{action}` — keys and value
     * shapes identical to the web form's POST. Unchecked checkboxes are omitted
     * (the web browser omits them); everything else submits, including
     * rule-hidden fields.
     */
    fun submissionBody(): JsonValue {
        val pairs = ArrayList<Pair<String, JsonValue>>()
        for (field in definition.fields) {
            when (field.type) {
                is FormFieldType.Checkbox -> {
                    val current = values[field.name] ?: ""
                    if (current.isNotEmpty()) pairs.add(field.name to JsonValue.Str(current))
                }
                is FormFieldType.CheckboxList -> {
                    if (field.listType == "radio") {
                        pairs.add(field.name to JsonValue.Str(values[field.name] ?: ""))
                    } else {
                        val selected = listValues[field.name] ?: emptyList()
                        pairs.add(field.name to JsonValue.Arr(selected.map { JsonValue.Str(it) }))
                    }
                }
                is FormFieldType.Datetime -> {
                    val parts = field.submitParts ?: continue
                    val millis = dateValues[field.name] ?: continue
                    val cal = Calendar.getInstance().apply { timeInMillis = millis }
                    val hour24 = cal.get(Calendar.HOUR_OF_DAY)
                    val hour12 = if (hour24 % 12 == 0) 12 else hour24 % 12
                    val ampm = if (hour24 < 12) "AM" else "PM"
                    parts["date"]?.stringValue?.let {
                        pairs.add(it to JsonValue.Str(
                            "%04d-%02d-%02d".format(cal.get(Calendar.YEAR), cal.get(Calendar.MONTH) + 1, cal.get(Calendar.DAY_OF_MONTH))
                        ))
                    }
                    parts["hour"]?.stringValue?.let { pairs.add(it to JsonValue.Str(hour12.toString())) }
                    parts["minute"]?.stringValue?.let { pairs.add(it to JsonValue.Str(cal.get(Calendar.MINUTE).toString())) }
                    parts["ampm"]?.stringValue?.let { pairs.add(it to JsonValue.Str(ampm)) }
                }
                else -> pairs.add(field.name to JsonValue.Str(values[field.name] ?: ""))
            }
        }
        return JsonValue.Obj(pairs)
    }

    companion object {
        fun parseDatetime(raw: String): Long? {
            for (pattern in listOf("yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd HH:mm")) {
                try {
                    return SimpleDateFormat(pattern, Locale.US).parse(raw)?.time
                } catch (_: Exception) {
                }
            }
            return null
        }
    }
}
