import XCTest

/// Shared helpers for the ScrollDaddy gate suites. Credentials and per-run
/// parameters arrive via the test runner's environment (xcodebuild
/// TEST_RUNNER_* variables) — never hard-coded. Mirrors the member app's
/// TestSupport so both apps' gates read the same way.
enum TestEnv {
    static var email: String { require("JOINERY_TEST_EMAIL") }
    static var password: String { require("JOINERY_TEST_PASSWORD") }

    static func optional(_ key: String) -> String? {
        ProcessInfo.processInfo.environment[key]
    }

    static func require(_ key: String) -> String {
        guard let value = ProcessInfo.processInfo.environment[key], !value.isEmpty else {
            XCTFail("Missing required test environment variable \(key)")
            return ""
        }
        return value
    }
}

extension XCUIApplication {
    /// Launch signed out (Keychain wiped) with optional env overrides.
    func launchScrollDaddy(env: [String: String] = [:]) {
        launchArguments += ["--reset-auth"]
        for (key, value) in env { launchEnvironment[key] = value }
        launch()
    }

    /// Drive the JoineryKit native login screen.
    func signIn(email: String, password: String) {
        let emailField = textFields["login_email"]
        XCTAssertTrue(emailField.waitForExistence(timeout: 10), "login screen did not appear")
        emailField.tap()
        emailField.typeText(email)
        let passwordField = secureTextFields["login_password"]
        passwordField.tap()
        passwordField.typeText(password)
        buttons["login_submit"].tap()
    }

    /// The signed-in surface is the navigation shell; its last tab is always More.
    func expectSignedIn(timeout: TimeInterval = 25) {
        XCTAssertTrue(tabBars.buttons["More"].waitForExistence(timeout: timeout), "signed-in tab bar did not appear")
    }
}
