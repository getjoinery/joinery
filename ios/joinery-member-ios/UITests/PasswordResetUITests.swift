import XCTest

/// Gate: both password-reset steps fully native. Run as two orchestrated
/// invocations — the reset code only exists in the emailed link, which the
/// dev-side runner reads from `iem_inbound_email_messages` between steps:
///
///   1. -only-testing:...testRequestResetEmail   (step 1: sends the email)
///   2. runner extracts the code from the fixture inbox
///   3. -only-testing:...testCompleteResetWithCode  with
///      TEST_RUNNER_JOINERY_RESET_CODE and TEST_RUNNER_JOINERY_NEW_PASSWORD
final class PasswordResetUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testRequestResetEmail() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.expect(app.buttons["login_forgot"], "forgot-password entry")
        app.buttons["login_forgot"].tap()

        // Step 1 is the server-driven password_reset_1 form.
        let email = app.textFields["usr_email"]
        app.expect(email, timeout: 15, "reset email field")
        email.tap()
        email.typeText(TestEnv.email)
        app.buttons["form_submit"].tap()

        // Success advances to the code-entry screen.
        app.expect(app.textFields["reset_code"], timeout: 15, "code entry after request")
    }

    func testCompleteResetWithCode() {
        let code = TestEnv.require("JOINERY_RESET_CODE")
        let newPassword = TestEnv.require("JOINERY_NEW_PASSWORD")

        let app = XCUIApplication()
        app.launchJoinery()
        app.expect(app.buttons["login_forgot"], "forgot-password entry")
        app.buttons["login_forgot"].tap()

        // Skip re-requesting: we already hold a live code.
        app.expect(app.buttons["reset_have_code"], "have-a-code shortcut")
        app.buttons["reset_have_code"].tap()

        let codeField = app.textFields["reset_code"]
        app.expect(codeField, "code field")
        codeField.tap()
        codeField.typeText(code)
        app.buttons["reset_code_continue"].tap()

        // Step 2 is the server-driven password_reset_2 form; the code
        // round-trips via the form's hidden field.
        let password = app.secureTextFields["usr_password"]
        app.expect(password, timeout: 15, "new password field")
        password.tap()
        password.typeText(newPassword)
        let again = app.secureTextFields["usr_password_again"]
        again.tap()
        again.typeText(newPassword)
        app.buttons["form_submit"].tap()

        app.expect(app.staticTexts["reset_done"], timeout: 15, "reset completion screen")
        app.buttons["reset_back_to_login"].tap()

        // The new password signs in natively.
        app.signIn(email: TestEnv.email, password: newPassword)
        app.expectSignedIn(timeout: 20)
    }
}
