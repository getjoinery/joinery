import XCTest

/// Gate: the deliberately-web member surfaces render inside the app through
/// the bridged webview, reached from their native entry points — Change Plan
/// from the native subscriptions screen, Notifications from the native
/// profile dashboard — chrome-less and with no login prompt.
final class WebviewUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    private func signedInApp() -> XCUIApplication {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        return app
    }

    func testChangePlanLoadsFromNativeSubscriptions() {
        let app = signedInApp()
        app.openMore()
        app.buttons["more_core-subscriptions"].tap()
        app.expect(app.navigationBars["Subscriptions"].firstMatch, timeout: 20, "native subscriptions screen")

        app.buttons["subscriptions_change_plan"].firstMatch.tap()

        // The change-tier page renders in the bridged webview, no login page.
        let webView = app.webViews.firstMatch
        app.expect(webView.staticTexts["Choose Your Membership Level"], timeout: 30,
                   "change-tier page heading in webview")
    }

    func testNotificationsLoadFromProfileDashboard() {
        let app = signedInApp()
        app.tabBars.buttons["My Profile"].tap()
        app.expect(app.staticTexts["profile_user_name"].firstMatch, timeout: 20, "native profile dashboard")

        let row = app.buttons["profile_notifications"].firstMatch
        app.expect(row, timeout: 10, "Notifications row on dashboard")
        row.tap()

        // The notifications page renders in the bridged webview and the app
        // stays foreground (same-origin content never hands off to Safari).
        let webView = app.webViews.firstMatch
        app.expect(webView.staticTexts["Notifications"].firstMatch, timeout: 30,
                   "notifications page heading in webview")
        XCTAssertEqual(app.state, .runningForeground)
    }
}
