import XCTest

/// Shared helpers for the Phase 2 gate suites. Credentials and per-run
/// parameters arrive via the test runner's environment (xcodebuild
/// TEST_RUNNER_* variables) — never hard-coded.
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
    /// Launch signed out (Keychain wiped) with optional env overrides for
    /// the app process.
    func launchJoinery(env: [String: String] = [:]) {
        launchArguments += ["--reset-auth"]
        for (key, value) in env { launchEnvironment[key] = value }
        launch()
    }

    /// Drive the native login screen. Leaves the app wherever login lands it.
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

    /// Wait for any element to exist, with a clearer failure message.
    @discardableResult
    func expect(_ element: XCUIElement, timeout: TimeInterval = 10, _ label: String) -> Bool {
        let ok = element.waitForExistence(timeout: timeout)
        XCTAssertTrue(ok, "expected \(label) within \(Int(timeout))s")
        return ok
    }

    /// The signed-in surface is the navigation shell: a tab bar whose last
    /// slot is always More.
    func expectSignedIn(timeout: TimeInterval = 25) {
        expect(tabBars.buttons["More"], timeout: timeout, "signed-in tab bar")
    }

    /// Open the More tab (waits for the shell first).
    func openMore() {
        expectSignedIn()
        tabBars.buttons["More"].tap()
    }

    /// Dismiss the site cookie-consent banner if it's showing in a webview.
    /// Its fixed-position layer swallows taps on page controls underneath;
    /// each test run reinstalls the app (fresh webview data store), so the
    /// banner is back every run.
    func dismissCookieConsent() {
        let accept = webViews.buttons["Accept All"].firstMatch
        if accept.waitForExistence(timeout: 4) { accept.tap() }
    }

    /// Navigate More → Settings (the native settings screen).
    func openSettings() {
        openMore()
        let row = buttons["more_settings"]
        expect(row, timeout: 10, "Settings row in More")
        row.tap()
        expect(staticTexts["settings_display_name"], timeout: 15, "settings screen")
    }
}
