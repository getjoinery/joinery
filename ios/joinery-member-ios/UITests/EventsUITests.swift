import XCTest

/// Gate: More → My Events renders the NATIVE status-tabbed event list
/// (JoineryMemberKit; `core-events`'s destination is `{type: "native",
/// screen: "events"}`) — no webview.
final class EventsUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testNativeEventsListRenders() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.openMore()

        let row = app.buttons["more_core-events"]
        app.expect(row, timeout: 10, "My Events row in More")
        row.tap()

        app.expect(app.navigationBars["My Events"].firstMatch, timeout: 20, "native events screen")
        XCTAssertFalse(app.webViews.firstMatch.exists, "Events must be native, not a webview")
    }
}
