import XCTest

/// Gate: member pages render inside the app through the bridged webview —
/// orders and conversations load, and in-webview same-origin navigation
/// stays in the app.
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

    func testOrdersLoads() {
        let app = signedInApp()
        app.openMore()
        app.buttons["more_core-orders"].tap()

        // /profile#orders is the profile page; its orders card renders.
        let webView = app.webViews.firstMatch
        app.expect(webView.staticTexts["Recent Orders"], timeout: 30, "orders card on profile page")
    }

    func testConversationsLoadViaInWebviewNavigation() {
        let app = signedInApp()
        app.tabBars.buttons["My Profile"].tap()

        // Same-origin navigation stays in the webview: the profile page's
        // Unread Messages card links to /profile/conversations.
        let webView = app.webViews.firstMatch
        let messagesCard = webView.staticTexts["Unread Messages"]
        app.expect(messagesCard, timeout: 30, "messages stat card on profile")
        messagesCard.tap()

        app.expect(webView.staticTexts["Messages"].firstMatch, timeout: 20, "conversations page heading")
        // Still inside the app (no Safari hand-off for same-origin).
        XCTAssertEqual(app.state, .runningForeground)
    }
}
