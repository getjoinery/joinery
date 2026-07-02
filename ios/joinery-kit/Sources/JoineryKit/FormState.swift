import SwiftUI

/// Live state for one rendered form: current values, visibility, field
/// errors, and the submission body builder. Pure logic — no networking — so
/// it is fully unit-testable; `FormScreen` owns the fetch/submit lifecycle.
@MainActor
public final class FormState: ObservableObject {
    public let definition: FormDefinition

    /// Single-value fields (text, password, drop, radio, checkbox state as
    /// its checked value or "", date "YYYY-MM-DD", time "HH:MM").
    @Published public var values: [String: String] = [:]
    /// Multi-value fields (checkbox_list selections, in option order).
    @Published public var listValues: [String: [String]] = [:]
    /// datetime fields hold a Date; converted to submit parts at build time.
    @Published public var dateValues: [String: Date] = [:]
    /// Field name → message, from client-side checks or the server's 422 map.
    @Published public var fieldErrors: [String: String] = [:]
    /// Form-level error (422 with no field map, transport failures…).
    @Published public var formError: String?
    /// Names hidden by the current visibility evaluation.
    @Published public private(set) var hiddenFields: Set<String> = []

    public init(definition: FormDefinition) {
        self.definition = definition
        for field in definition.fields {
            switch field.type {
            case .checkbox:
                values[field.name] = field.isChecked ? field.checkedValue : ""
            case .checkboxList:
                if field.listType == "radio" {
                    values[field.name] = field.checked.first ?? ""
                } else {
                    listValues[field.name] = field.checked
                }
            case .datetime:
                if let raw = field.value?.stringValue,
                   let date = FormState.parseDatetime(raw) {
                    dateValues[field.name] = date
                }
            case .date:
                values[field.name] = field.value?.stringValue ?? ""
            default:
                values[field.name] = field.value?.stringValue ?? ""
            }
        }
        evaluateVisibility()
    }

    // MARK: Visibility

    /// Re-evaluate all trigger rules. Semantics mirror the web
    /// (docs/formwriter.md § Field Visibility): a drop/radio keys on the
    /// selected option value, a checkbox on `checked`/`unchecked` (with an
    /// optional `default` rule as fallback). Hidden fields keep their values
    /// and still submit, exactly like display:none on the web.
    public func evaluateVisibility() {
        var hidden = Set<String>()
        for field in definition.fields {
            guard let rules = field.visibilityRules?.objectValue, !rules.isEmpty else { continue }
            let key: String
            switch field.type {
            case .checkbox:
                key = (values[field.name] ?? "").isEmpty ? "unchecked" : "checked"
            default:
                key = values[field.name] ?? ""
            }
            var rule = field.visibilityRules?[key]
            if rule == nil { rule = field.visibilityRules?["default"] }
            guard let rule else { continue }
            for target in (rule["hide"]?.arrayValue ?? []).compactMap({ $0.stringValue }) {
                hidden.insert(target)
            }
            for target in (rule["show"]?.arrayValue ?? []).compactMap({ $0.stringValue }) {
                hidden.remove(target)
            }
        }
        hiddenFields = hidden
    }

    public func isVisible(_ field: FormField) -> Bool {
        if case .hidden = field.type { return false }
        return !hiddenFields.contains(field.name)
    }

    // MARK: Client-side checks

    /// Required-field pass before submitting; fills `fieldErrors` and returns
    /// whether the form may submit. The server remains the authority — this
    /// only catches the obvious empties without a round-trip.
    public func validateForSubmit() -> Bool {
        var errors: [String: String] = [:]
        for field in definition.fields where field.required && isVisible(field) {
            let empty: Bool
            switch field.type {
            case .checkboxList where field.listType != "radio":
                empty = (listValues[field.name] ?? []).isEmpty
            case .datetime:
                empty = dateValues[field.name] == nil
            default:
                empty = (values[field.name] ?? "").isEmpty
            }
            if empty {
                errors[field.name] = "This field is required."
            }
        }
        fieldErrors = errors
        return errors.isEmpty
    }

    /// Map a server error onto the form: field messages where given, the
    /// top-level message as the form error otherwise.
    public func apply(error: JoineryAPIError) {
        let fields = error.fieldErrors
        if !fields.isEmpty {
            fieldErrors = fields
            formError = nil
        } else {
            formError = error.displayMessage
        }
    }

    // MARK: Submission body

    /// Build the JSON body for `POST /api/v1/action/{action}` — keys and
    /// value shapes identical to the web form's POST. Unchecked checkboxes
    /// are omitted (the web browser omits them); everything else submits,
    /// including rule-hidden fields.
    public func submissionBody() -> JSONValue {
        var pairs: [(key: String, value: JSONValue)] = []
        for field in definition.fields {
            switch field.type {
            case .checkbox:
                let current = values[field.name] ?? ""
                if !current.isEmpty {
                    pairs.append((key: field.name, value: .string(current)))
                }
            case .checkboxList:
                if field.listType == "radio" {
                    pairs.append((key: field.name, value: .string(values[field.name] ?? "")))
                } else {
                    let selected = listValues[field.name] ?? []
                    pairs.append((key: field.name, value: .array(selected.map { .string($0) })))
                }
            case .datetime:
                guard let parts = field.submitParts, let date = dateValues[field.name] else { continue }
                let calendar = Calendar.current
                let comps = calendar.dateComponents([.year, .month, .day, .hour, .minute], from: date)
                let hour24 = comps.hour ?? 0
                let hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12
                let ampm = hour24 < 12 ? "AM" : "PM"
                if let key = parts["date"]?.stringValue {
                    pairs.append((key: key, value: .string(String(format: "%04d-%02d-%02d", comps.year ?? 0, comps.month ?? 0, comps.day ?? 0))))
                }
                if let key = parts["hour"]?.stringValue {
                    pairs.append((key: key, value: .string(String(hour12))))
                }
                if let key = parts["minute"]?.stringValue {
                    pairs.append((key: key, value: .string(String(comps.minute ?? 0))))
                }
                if let key = parts["ampm"]?.stringValue {
                    pairs.append((key: key, value: .string(ampm)))
                }
            default:
                pairs.append((key: field.name, value: .string(values[field.name] ?? "")))
            }
        }
        return .object(pairs)
    }

    // MARK: Helpers

    static func parseDatetime(_ raw: String) -> Date? {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let d = formatter.date(from: raw) { return d }
        formatter.dateFormat = "yyyy-MM-dd HH:mm"
        return formatter.date(from: raw)
    }
}
