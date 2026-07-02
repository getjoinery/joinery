import XCTest
@testable import JoineryKit

final class FormParsingTests: XCTestCase {

    private func definition(_ fixtureName: String) throws -> FormDefinition {
        let envelope = try JSONValue.parse(try fixture(fixtureName))
        guard let def = FormDefinition(data: envelope["data"]) else {
            throw NSError(domain: "test", code: 1, userInfo: [NSLocalizedDescriptionKey: "definition failed to parse"])
        }
        return def
    }

    func testRegisterFormParses() throws {
        let def = try definition("form_register.json")
        XCTAssertEqual(def.name, "register")
        XCTAssertEqual(def.submitTo, "/api/v1/action/register")
        XCTAssertEqual(def.submitLabel, "Register Now")
        XCTAssertTrue(def.isRenderable)

        let names = def.fields.map { $0.name }
        XCTAssertEqual(names, [
            "usr_first_name", "usr_last_name", "usr_nickname", "usr_email",
            "password", "usr_timezone", "privacy", "newsletter", "setcookie",
        ])

        let email = def.fields.first { $0.name == "usr_email" }!
        XCTAssertEqual(email.type, .text)
        XCTAssertEqual(email.inputType, "email")
        XCTAssertTrue(email.required)
        XCTAssertEqual(email.maxlength, 64)

        let setcookie = def.fields.first { $0.name == "setcookie" }!
        XCTAssertEqual(setcookie.type, .checkbox)
        XCTAssertTrue(setcookie.isChecked)
        XCTAssertEqual(setcookie.checkedValue, "1")

        let privacy = def.fields.first { $0.name == "privacy" }!
        XCTAssertFalse(privacy.isChecked)
    }

    func testTimezoneOptionsKeepServerOrder() throws {
        let def = try definition("form_register.json")
        let tz = def.fields.first { $0.name == "usr_timezone" }!
        XCTAssertEqual(tz.type, .drop)
        XCTAssertGreaterThan(tz.options.count, 100)
        // Server emits Africa/Abidjan first; unordered decoding would scramble.
        XCTAssertEqual(tz.options.first?.value, "Africa/Abidjan")
    }

    func testPasswordReset2CarriesHiddenCode() throws {
        let def = try definition("form_password_reset_2.json")
        let hidden = def.fields.first { $0.name == "act_code" }!
        XCTAssertEqual(hidden.type, .hidden)
        XCTAssertEqual(hidden.value?.stringValue, "SAMPLECODE123")
        XCTAssertEqual(def.fields.filter { $0.type == .password }.count, 2)
    }

    func testContactPreferencesCheckboxList() throws {
        let def = try definition("form_contact_preferences.json")
        let list = def.fields.first { $0.name == "new_list_subscribes" }!
        XCTAssertEqual(list.type, .checkboxList)
        XCTAssertGreaterThan(list.options.count, 0)
        XCTAssertTrue(def.isRenderable)
    }

    func testUnknownFieldTypeMakesFormUnrenderable() throws {
        let json = """
        {"schema_version": 1,
         "form": {"name": "x", "submit_to": "/api/v1/action/x", "submit_label": "Go"},
         "fields": [
            {"type": "text", "name": "a", "label": "A"},
            {"type": "hologram", "name": "b", "label": "B"}
         ]}
        """
        let def = FormDefinition(data: try JSONValue.parse(json))!
        XCTAssertFalse(def.isRenderable)
    }

    func testNewerSchemaVersionMakesFormUnrenderable() throws {
        let json = """
        {"schema_version": 2,
         "form": {"name": "x", "submit_to": "/api/v1/action/x", "submit_label": "Go"},
         "fields": [{"type": "text", "name": "a", "label": "A"}]}
        """
        let def = FormDefinition(data: try JSONValue.parse(json))!
        XCTAssertFalse(def.isRenderable)
    }

    func testLoginAndSessionSummaries() throws {
        let login = try JSONValue.parse(try fixture("login.json"))
        let result = LoginResult(data: login["data"])
        XCTAssertNotNil(result)
        XCTAssertEqual(result?.user?.email, "appdev.phase2@inbox.dev.getjoinery.com")
        XCTAssertNil(result?.user?.tier)
        XCTAssertNotNil(result?.expiresTime)

        let session = try JSONValue.parse(try fixture("session.json"))
        let user = UserSummary(json: session["data"])
        XCTAssertEqual(user?.displayName, "AppDev PhaseTwo")
        XCTAssertEqual(user?.permission, 0)
    }

    func testTimestampParsing() {
        let date = JoineryTimestamp.parse("2027-07-02 21:06:35")
        XCTAssertNotNil(date)
        XCTAssertEqual(JoineryTimestamp.format(date!), "2027-07-02 21:06:35")
        XCTAssertNil(JoineryTimestamp.parse(nil))
        XCTAssertNil(JoineryTimestamp.parse(""))
    }
}
