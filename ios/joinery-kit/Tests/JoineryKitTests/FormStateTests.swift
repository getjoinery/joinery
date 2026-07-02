import XCTest
@testable import JoineryKit

@MainActor
final class FormStateTests: XCTestCase {

    private func makeDefinition(_ fieldsJSON: String, name: String = "test") throws -> FormDefinition {
        let json = """
        {"schema_version": 1,
         "form": {"name": "\(name)", "submit_to": "/api/v1/action/\(name)", "submit_label": "Go"},
         "fields": \(fieldsJSON)}
        """
        return FormDefinition(data: try JSONValue.parse(json))!
    }

    // MARK: Visibility

    func testDropVisibilityRules() throws {
        let def = try makeDefinition("""
        [
          {"type": "drop", "name": "kind", "label": "Kind", "value": "a",
           "options": {"a": "A", "b": "B"},
           "visibility_rules": {
             "a": {"show": ["field_a"], "hide": ["field_b"]},
             "b": {"show": ["field_b"], "hide": ["field_a"]}
           }},
          {"type": "text", "name": "field_a", "label": "For A"},
          {"type": "text", "name": "field_b", "label": "For B"}
        ]
        """)
        let state = FormState(definition: def)
        XCTAssertFalse(state.hiddenFields.contains("field_a"))
        XCTAssertTrue(state.hiddenFields.contains("field_b"))

        state.values["kind"] = "b"
        state.evaluateVisibility()
        XCTAssertTrue(state.hiddenFields.contains("field_a"))
        XCTAssertFalse(state.hiddenFields.contains("field_b"))
    }

    func testCheckboxVisibilityKeysOnState() throws {
        let def = try makeDefinition("""
        [
          {"type": "checkbox", "name": "repeats", "label": "Repeats", "checked_value": "1",
           "visibility_rules": {
             "checked": {"show": ["frequency"]},
             "unchecked": {"hide": ["frequency"]}
           }},
          {"type": "text", "name": "frequency", "label": "Frequency"}
        ]
        """)
        let state = FormState(definition: def)
        XCTAssertTrue(state.hiddenFields.contains("frequency"))

        state.values["repeats"] = "1"
        state.evaluateVisibility()
        XCTAssertFalse(state.hiddenFields.contains("frequency"))
    }

    func testHiddenByRuleStillSubmits() throws {
        let def = try makeDefinition("""
        [
          {"type": "drop", "name": "kind", "label": "Kind", "value": "a",
           "options": {"a": "A", "b": "B"},
           "visibility_rules": {"a": {"hide": ["extra"]}}},
          {"type": "text", "name": "extra", "label": "Extra", "value": "kept"}
        ]
        """)
        let state = FormState(definition: def)
        XCTAssertTrue(state.hiddenFields.contains("extra"))
        // display:none on the web still posts — native matches.
        XCTAssertEqual(state.submissionBody()["extra"]?.stringValue, "kept")
    }

    // MARK: Submission body

    func testCheckboxSubmitOmittedWhenUnchecked() throws {
        let def = try makeDefinition("""
        [
          {"type": "checkbox", "name": "privacy", "label": "P", "checked_value": "1"},
          {"type": "checkbox", "name": "setcookie", "label": "S", "checked_value": "1", "is_checked": true}
        ]
        """)
        let state = FormState(definition: def)
        let body = state.submissionBody()
        XCTAssertNil(body["privacy"], "unchecked checkbox must be omitted, like a browser POST")
        XCTAssertEqual(body["setcookie"]?.stringValue, "1")
    }

    func testCheckboxListSubmitsArray() throws {
        let def = try makeDefinition("""
        [
          {"type": "checkbox_list", "name": "subs", "label": "Subs",
           "options": {"1": "News", "2": "Events", "3": "Offers"},
           "checked": ["2"]}
        ]
        """)
        let state = FormState(definition: def)
        XCTAssertEqual(state.listValues["subs"], ["2"])
        state.listValues["subs"] = ["2", "3"]
        let body = state.submissionBody()
        XCTAssertEqual(body["subs"]?.arrayValue?.compactMap { $0.stringValue }, ["2", "3"])
    }

    func testHiddenFieldRoundTrips() throws {
        let def = try makeDefinition("""
        [
          {"type": "hidden", "name": "act_code", "value": "CODE42"},
          {"type": "password", "name": "usr_password", "label": "New"}
        ]
        """)
        let state = FormState(definition: def)
        state.values["usr_password"] = "hunter22"
        let body = state.submissionBody()
        XCTAssertEqual(body["act_code"]?.stringValue, "CODE42")
        XCTAssertEqual(body["usr_password"]?.stringValue, "hunter22")
    }

    func testDatetimeSubmitParts() throws {
        let def = try makeDefinition("""
        [
          {"type": "datetime", "name": "evt_start", "label": "Starts",
           "value": "2026-07-04 14:30:00",
           "submit_parts": {"date": "evt_start_dateinput", "hour": "evt_start_timeinput_hour",
                            "minute": "evt_start_timeinput_minute", "ampm": "evt_start_timeinput_ampm"}}
        ]
        """)
        let state = FormState(definition: def)
        let body = state.submissionBody()
        XCTAssertEqual(body["evt_start_dateinput"]?.stringValue, "2026-07-04")
        XCTAssertEqual(body["evt_start_timeinput_hour"]?.stringValue, "2")
        XCTAssertEqual(body["evt_start_timeinput_minute"]?.stringValue, "30")
        XCTAssertEqual(body["evt_start_timeinput_ampm"]?.stringValue, "PM")
    }

    // MARK: Client-side validation + server error mapping

    func testRequiredValidationBlocksSubmit() throws {
        let def = try makeDefinition("""
        [
          {"type": "text", "name": "usr_email", "label": "Email", "required": true},
          {"type": "text", "name": "optional_bit", "label": "Optional"}
        ]
        """)
        let state = FormState(definition: def)
        XCTAssertFalse(state.validateForSubmit())
        XCTAssertNotNil(state.fieldErrors["usr_email"])
        state.values["usr_email"] = "a@b.com"
        XCTAssertTrue(state.validateForSubmit())
    }

    func testHiddenRequiredFieldNotValidated() throws {
        let def = try makeDefinition("""
        [
          {"type": "drop", "name": "kind", "label": "Kind", "value": "a",
           "options": {"a": "A"}, "visibility_rules": {"a": {"hide": ["gone"]}}},
          {"type": "text", "name": "gone", "label": "Gone", "required": true}
        ]
        """)
        let state = FormState(definition: def)
        XCTAssertTrue(state.validateForSubmit(), "rule-hidden required fields must not block submit")
    }

    func testServerErrorsMapOntoFields() throws {
        let def = try makeDefinition("""
        [{"type": "text", "name": "usr_email", "label": "Email"}]
        """)
        let state = FormState(definition: def)
        state.apply(error: .validation(message: "Fix the form", fieldErrors: ["usr_email": "Bad address"]))
        XCTAssertEqual(state.fieldErrors["usr_email"], "Bad address")
        XCTAssertNil(state.formError)

        state.apply(error: .validation(message: "Top-level only", fieldErrors: [:]))
        XCTAssertEqual(state.formError, "Top-level only")
    }
}
