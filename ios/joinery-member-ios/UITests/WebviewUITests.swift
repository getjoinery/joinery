import XCTest

/// Gate: member pages render inside the app through the bridged webview —
/// the calendar is usable, orders and conversations load, and in-webview
/// same-origin navigation stays in the app.
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

    private func monthTitle(offset: Int) -> String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "MMMM yyyy"
        let date = Calendar.current.date(byAdding: .month, value: offset, to: Date())!
        return formatter.string(from: date)
    }

    func testCalendarIsUsableInApp() {
        let app = signedInApp()
        app.tabBars.buttons["Calendar"].tap()

        // The bridged page renders: current month title in the grid toolbar.
        let webView = app.webViews.firstMatch
        app.expect(webView.staticTexts[monthTitle(offset: 0)], timeout: 30, "current month title")

        // Usable, not just visible: month navigation works in-webview.
        webView.buttons["Next"].tap()
        app.expect(webView.staticTexts[monthTitle(offset: 1)], timeout: 15, "next month after tapping Next")
        webView.buttons["Today"].tap()
        app.expect(webView.staticTexts[monthTitle(offset: 0)], timeout: 15, "back to current month")
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
