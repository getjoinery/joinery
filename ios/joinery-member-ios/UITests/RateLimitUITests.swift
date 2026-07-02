import XCTest

/// Gate: the failed-auth rate limiter path renders correctly. Run LAST —
/// tripping the limiter blocks further auth attempts from this network for
/// the 15-minute window.
final class RateLimitUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testRepeatedFailuresSurfaceRateLimitError() {
        let app = XCUIApplication()
        app.launchJoinery()

        let emailField = app.textFields["login_email"]
        XCTAssertTrue(emailField.waitForExistence(timeout: 10))
        emailField.tap()
        emailField.typeText(TestEnv.email)

        let passwordField = app.secureTextFields["login_password"]
        var sawRateLimit = false
        // The failed-auth limiter allows 10 failures per window; attempt 11+
        // must switch from "invalid credentials" to the rate-limit message.
        for attempt in 1...14 {
            passwordField.tap()
            // Replace previous attempt's text.
            if let current = passwordField.value as? String, !current.isEmpty {
                passwordField.typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: current.count + 2))
            }
            passwordField.typeText("wrong-password-\(attempt)")
            app.buttons["login_submit"].tap()

            let error = app.staticTexts["login_error"]
            XCTAssertTrue(error.waitForExistence(timeout: 10), "attempt \(attempt) produced no inline error")
            if error.label.lowercased().contains("too many") {
                sawRateLimit = true
                break
            }
        }
        XCTAssertTrue(sawRateLimit, "rate-limit message never surfaced after repeated failures")
    }
}
