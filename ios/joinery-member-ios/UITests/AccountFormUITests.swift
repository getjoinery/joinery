import XCTest

/// Gate: server-driven forms render natively from JSON and submit through
/// the action endpoint; a server-side definition change appears with no
/// rebuild (probe test, orchestrated).
final class AccountFormUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    private func openAccountEdit(_ app: XCUIApplication) {
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.openSettings()
        app.expect(app.buttons["settings_account_edit"], timeout: 10, "account edit row")
        app.buttons["settings_account_edit"].tap()
    }

    func testAccountEditRendersFromServerDefinition() {
        let app = XCUIApplication()
        openAccountEdit(app)

        // Fields come from the definition, prefilled with the acting user.
        let firstName = app.textFields["usr_first_name"]
        app.expect(firstName, timeout: 15, "usr_first_name field")
        XCTAssertEqual(firstName.value as? String, "AppDev")
        app.expect(app.textFields["usr_nickname"], "usr_nickname field")
        app.expect(app.buttons["form_submit"], "submit button from definition")
    }

    func testAccountEditSubmitRoundTrip() {
        let app = XCUIApplication()
        openAccountEdit(app)

        let nickname = app.textFields["usr_nickname"]
        app.expect(nickname, timeout: 15, "usr_nickname field")
        nickname.tap()
        // Clear then type a deterministic value.
        if let current = nickname.value as? String, !current.isEmpty {
            let deletes = String(repeating: XCUIKeyboardKey.delete.rawValue, count: current.count + 2)
            nickname.typeText(deletes)
        }
        nickname.typeText("NativePhase2")

        app.buttons["form_submit"].tap()
        app.expect(app.staticTexts["form_success"], timeout: 15, "submit success banner")
    }

    /// Orchestrated: the runner adds a probe field to the account_edit form
    /// builder server-side, then runs only this test WITHOUT rebuilding the
    /// app. The new field appearing proves forms are fully server-driven.
    func testServerDrivenFieldChangeAppearsWithoutRebuild() throws {
        guard TestEnv.optional("JOINERY_EXPECT_PROBE") == "1" else {
            throw XCTSkip("probe not staged (JOINERY_EXPECT_PROBE unset)")
        }
        let app = XCUIApplication()
        openAccountEdit(app)
        app.expect(app.textFields["phase2_probe"], timeout: 15, "server-added probe field (no rebuild)")
    }
}
