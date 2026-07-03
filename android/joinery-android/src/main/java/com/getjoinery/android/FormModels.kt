package com.getjoinery.android

/**
 * A parsed server-driven form definition (docs/formwriter.md § JSON Output
 * Mode, schema v1). Fields arrive in display order; option maps preserve server
 * order via [JsonValue].
 */
class FormDefinition private constructor(
    val schemaVersion: Int,
    val name: String,
    val submitTo: String,
    val submitLabel: String,
    val fields: List<FormField>,
) {
    /** The action name for `POST /api/v1/action/{action}` — `submit_to` verbatim
     *  (which may include a plugin prefix, kept intact). */
    val actionPath: String get() = submitTo

    /** False when the schema is newer than we support or any field type is
     *  unknown — callers fall back to the web for this one form. */
    val isRenderable: Boolean
        get() {
            if (schemaVersion > SUPPORTED_SCHEMA_VERSION) return false
            return fields.none { it.type is FormFieldType.Unknown }
        }

    companion object {
        /** The newest definition schema this renderer understands. A definition
         *  with a higher `schema_version` falls back per form. */
        const val SUPPORTED_SCHEMA_VERSION = 1

        fun from(data: JsonValue?): FormDefinition? {
            val form = data?.get("form") ?: return null
            val name = form["name"]?.stringValue ?: return null
            return FormDefinition(
                schemaVersion = data["schema_version"]?.intValue ?: 1,
                name = name,
                submitTo = form["submit_to"]?.stringValue ?: "",
                submitLabel = form["submit_label"]?.stringValue ?: "Submit",
                fields = (data["fields"]?.arrayValue ?: emptyList()).mapNotNull { FormField.from(it) },
            )
        }
    }
}

sealed class FormFieldType {
    object Text : FormFieldType()
    object Password : FormFieldType()
    object Number : FormFieldType()
    object Textarea : FormFieldType()
    object Drop : FormFieldType()
    object Checkbox : FormFieldType()
    object Radio : FormFieldType()
    object CheckboxList : FormFieldType()
    object Date : FormFieldType()
    object Time : FormFieldType()
    object Datetime : FormFieldType()
    object Hidden : FormFieldType()
    data class Unknown(val raw: String) : FormFieldType()

    companion object {
        fun from(raw: String): FormFieldType = when (raw) {
            "text" -> Text
            "password" -> Password
            "number" -> Number
            "textarea" -> Textarea
            "drop" -> Drop
            "checkbox" -> Checkbox
            "radio" -> Radio
            "checkbox_list" -> CheckboxList
            "date" -> Date
            "time" -> Time
            "datetime" -> Datetime
            "hidden" -> Hidden
            else -> Unknown(raw)
        }
    }
}

/**
 * One field of a definition. Keys whose value is empty/false are omitted by the
 * server, so everything optional defaults to its "absent" reading.
 */
class FormField private constructor(
    val type: FormFieldType,
    val name: String,
    val label: String,
    val value: JsonValue?,
    val required: Boolean,
    val readonly: Boolean,
    val disabled: Boolean,
    val helptext: String,
    val placeholder: String,
    /** HTML input subtype for `text` (email, url, tel…) — drives keyboard. */
    val inputType: String?,
    val maxlength: Int?,
    /** Ordered option (value, label) pairs for drop/radio/checkbox_list. */
    val options: List<Option>,
    val emptyOption: String?,
    /** checkbox: value submitted when ticked (default "1"). */
    val checkedValue: String,
    /** checkbox: initial state. */
    val isChecked: Boolean,
    /** checkbox_list: initially-checked option values. */
    val checked: List<String>,
    val disabledValues: List<String>,
    /** checkbox_list rendered as radio (single-select) when "radio". */
    val listType: String?,
    /** Trigger rules: rule key → {show: [names], hide: [names]}. */
    val visibilityRules: JsonValue?,
    /** datetime: multi-part POST key map (date/hour/minute/ampm). */
    val submitParts: JsonValue?,
    /** Raw validation rules (required, maxlength, email, …) — server-mirrored. */
    val validation: JsonValue?,
) {
    data class Option(val value: String, val label: String)

    companion object {
        fun from(json: JsonValue): FormField? {
            val rawType = json["type"]?.stringValue ?: return null
            val name = json["name"]?.stringValue ?: return null
            return FormField(
                type = FormFieldType.from(rawType),
                name = name,
                label = json["label"]?.stringValue ?: "",
                value = json["value"],
                required = json["required"]?.boolValue ?: false,
                readonly = json["readonly"]?.boolValue ?: false,
                disabled = json["disabled"]?.boolValue ?: false,
                helptext = json["helptext"]?.stringValue ?: "",
                placeholder = json["placeholder"]?.stringValue ?: "",
                inputType = json["input_type"]?.stringValue,
                maxlength = json["maxlength"]?.intValue,
                options = (json["options"]?.objectValue ?: emptyList()).map {
                    Option(it.first, it.second.stringValue ?: it.first)
                },
                emptyOption = json["empty_option"]?.stringValue,
                checkedValue = json["checked_value"]?.stringValue ?: "1",
                isChecked = json["is_checked"]?.boolValue ?: false,
                checked = (json["checked"]?.arrayValue ?: emptyList()).mapNotNull { it.stringValue },
                disabledValues = (json["disabled_values"]?.arrayValue ?: emptyList()).mapNotNull { it.stringValue },
                listType = json["list_type"]?.stringValue,
                visibilityRules = json["visibility_rules"],
                submitParts = json["submit_parts"],
                validation = json["validation"],
            )
        }
    }
}
