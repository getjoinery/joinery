import XCTest

/// Gate: Settings → Security renders the NATIVE security screen
/// (JoineryMemberKit, reached through Settings' Security row via
/// NativeScreenRegistry) — no webview — with the app-session list and TOTP
/// status.
final class SecurityUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testNativeSecurityScreenRenders() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.openSettings()

        let row = app.buttons["settings_security"]
        app.expect(row, timeout: 10, "Security row in Settings")
        row.tap()

        // Native proof: the app-session list renders (no webview involved).
        // Section headers render uppercased in inset-grouped lists, so assert
        // on identifiers and row content, never on header text.
        app.expect(app.collectionViews["security_list"].firstMatch, timeout: 20, "native security list")
        XCTAssertFalse(app.webViews.firstMatch.exists, "Security must be native, not a webview")

        // The signed-in device's current session is flagged.
        app.expect(app.staticTexts["This device"].firstMatch, timeout: 15, "current session marker")

        // The TOTP section shows its control in either enabled state.
        let totpVisible = app.buttons["security_enable_totp"].firstMatch.waitForExistence(timeout: 10)
            || app.buttons["security_disable_totp"].firstMatch.exists
        XCTAssertTrue(totpVisible, "expected the TOTP enable/disable control")
    }
}
