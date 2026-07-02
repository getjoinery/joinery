import XCTest

/// Gate: revoking the app's session from the web signs out both layers.
/// Driven end-to-end inside the app: the App Sessions page (a webview
/// destination in Settings) is the web surface; Revoke All kills the API
/// key, the bridged web session dies with it (lifetime coupling — the
/// runner sets app_bridge_key_check_seconds=0 so the very next page load
/// notices), the silent re-bridge mint 401s, and the native layer signs out.
final class RevocationUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testRevokeFromWebSignsOutBothLayers() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.openSettings()

        app.expect(app.buttons["settings_app_sessions"], "App Sessions row")
        app.buttons["settings_app_sessions"].tap()

        // The web App Sessions surface, chrome-less, inside the app.
        let webView = app.webViews.firstMatch
        app.expect(webView.staticTexts["App Sessions"].firstMatch, timeout: 30, "App Sessions page")

        let revokeAll = webView.buttons["Revoke All"].firstMatch
        app.expect(revokeAll, timeout: 10, "Revoke All button")
        revokeAll.tap()

        // The page's confirm() surfaces as a native alert.
        let confirmOK = app.alerts.buttons["OK"]
        app.expect(confirmOK, timeout: 10, "native confirm for Revoke All")
        confirmOK.tap()

        // Both layers die: bridged session invalid on the next load, the
        // re-bridge mint 401s, and the native shell lands on login.
        app.expect(app.textFields["login_email"], timeout: 30, "login screen after web-side revocation")
    }
}
