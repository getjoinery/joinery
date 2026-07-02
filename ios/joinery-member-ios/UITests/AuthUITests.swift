import XCTest

/// Gate: log in / log out natively; invalid credentials render an inline
/// error (never a web page).
final class AuthUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testInvalidCredentialsShowInlineError() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: "definitely-wrong-password")
        app.expect(app.staticTexts["login_error"], "inline login error")
        // Still on the native login screen, not signed in.
        XCTAssertTrue(app.textFields["login_email"].exists)
    }

    func testLoginAndLogout() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)

        // Signed-in surface: the navigation shell; Settings lives under More.
        app.openSettings()
        app.expect(app.staticTexts["settings_email"], "account email row")
        XCTAssertEqual(app.staticTexts["settings_email"].label, TestEnv.email)

        // Sign out (confirmation dialog) returns to the native login screen.
        app.buttons["settings_sign_out"].tap()
        let confirm = app.buttons["settings_sign_out_confirm"]
        if confirm.waitForExistence(timeout: 5) {
            confirm.tap()
        } else {
            // Dialog buttons sometimes surface by label only.
            app.buttons["Sign Out"].firstMatch.tap()
        }
        app.expect(app.textFields["login_email"], timeout: 15, "login screen after sign-out")
    }
}
