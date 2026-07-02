import Foundation

/// A parsed server-driven form definition (docs/formwriter.md § JSON Output
/// Mode, schema v1). Fields arrive in display order; option maps preserve
/// server order via `JSONValue`.
public struct FormDefinition: Sendable {
    /// The newest definition schema this renderer understands. A definition
    /// with a higher `schema_version` falls back per form ("update the app or
    /// use the website").
    public static let supportedSchemaVersion = 1

    public let schemaVersion: Int
    public let name: String
    public let submitTo: String
    public let submitLabel: String
    public let fields: [FormField]

    public init?(data: JSONValue?) {
        guard let data,
              let form = data["form"],
              let name = form["name"]?.stringValue else { return nil }
        self.schemaVersion = data["schema_version"]?.intValue ?? 1
        self.name = name
        self.submitTo = form["submit_to"]?.stringValue ?? ""
        self.submitLabel = form["submit_label"]?.stringValue ?? "Submit"
        self.fields = (data["fields"]?.arrayValue ?? []).compactMap { FormField(json: $0) }
    }

    /// The action name for `POST /api/v1/action/{action}` — trailing segment
    /// of `submit_to` (which may include a plugin prefix, kept intact).
    public var actionPath: String { submitTo }

    /// False when the schema is newer than we support or any field type is
    /// unknown — callers fall back to the web for this one form.
    public var isRenderable: Bool {
        guard schemaVersion <= Self.supportedSchemaVersion else { return false }
        return !fields.contains { if case .unknown = $0.type { return true } else { return false } }
    }
}

public enum FormFieldType: Equatable, Sendable {
    case text
    case password
    case number
    case textarea
    case drop
    case checkbox
    case radio
    case checkboxList
    case date
    case time
    case datetime
    case hidden
    case unknown(String)

    init(raw: String) {
        switch raw {
        case "text": self = .text
        case "password": self = .password
        case "number": self = .number
        case "textarea": self = .textarea
        case "drop": self = .drop
        case "checkbox": self = .checkbox
        case "radio": self = .radio
        case "checkbox_list": self = .checkboxList
        case "date": self = .date
        case "time": self = .time
        case "datetime": self = .datetime
        case "hidden": self = .hidden
        default: self = .unknown(raw)
        }
    }
}

/// One field of a definition. Keys whose value is empty/false are omitted by
/// the server, so everything optional defaults to its "absent" reading.
public struct FormField: Sendable {
    public let type: FormFieldType
    public let name: String
    public let label: String
    public let value: JSONValue?
    public let required: Bool
    public let readonly: Bool
    public let disabled: Bool
    public let helptext: String
    public let placeholder: String
    /// HTML input subtype for `text` (email, url, tel…) — drives keyboard.
    public let inputType: String?
    public let maxlength: Int?
    /// Ordered option (value, label) pairs for drop/radio/checkbox_list.
    public let options: [(value: String, label: String)]
    public let emptyOption: String?
    /// checkbox: value submitted when ticked (default "1").
    public let checkedValue: String
    /// checkbox: initial state.
    public let isChecked: Bool
    /// checkbox_list: initially-checked option values.
    public let checked: [String]
    public let disabledValues: [String]
    /// checkbox_list rendered as radio (single-select) when "radio".
    public let listType: String?
    /// Trigger rules: rule key → {show: [names], hide: [names]}.
    public let visibilityRules: JSONValue?
    /// datetime: multi-part POST key map (date/hour/minute/ampm).
    public let submitParts: JSONValue?
    /// Raw validation rules (required, maxlength, email, …) — server-mirrored.
    public let validation: JSONValue?

    public init?(json: JSONValue) {
        guard let rawType = json["type"]?.stringValue,
              let name = json["name"]?.stringValue else { return nil }
        self.type = FormFieldType(raw: rawType)
        self.name = name
        self.label = json["label"]?.stringValue ?? ""
        self.value = json["value"]
        self.required = json["required"]?.boolValue ?? false
        self.readonly = json["readonly"]?.boolValue ?? false
        self.disabled = json["disabled"]?.boolValue ?? false
        self.helptext = json["helptext"]?.stringValue ?? ""
        self.placeholder = json["placeholder"]?.stringValue ?? ""
        self.inputType = json["input_type"]?.stringValue
        self.maxlength = json["maxlength"]?.intValue
        self.options = (json["options"]?.objectValue ?? []).map {
            (value: $0.key, label: $0.value.stringValue ?? $0.key)
        }
        self.emptyOption = json["empty_option"]?.stringValue
        self.checkedValue = json["checked_value"]?.stringValue ?? "1"
        self.isChecked = json["is_checked"]?.boolValue ?? false
        self.checked = (json["checked"]?.arrayValue ?? []).compactMap { $0.stringValue }
        self.disabledValues = (json["disabled_values"]?.arrayValue ?? []).compactMap { $0.stringValue }
        self.listType = json["list_type"]?.stringValue
        self.visibilityRules = json["visibility_rules"]
        self.submitParts = json["submit_parts"]
        self.validation = json["validation"]
    }
}
