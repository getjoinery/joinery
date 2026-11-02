import XCTest

/// Gate: the Dashboard's Orders link renders the NATIVE order list (JoineryMemberKit;
/// `core-orders`'s destination is `{type: "native", screen: "orders"}`) —
/// no webview.
final class OrdersUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testNativeOrdersListRenders() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.openDashboardLink("profile_tile_orders", "Orders tile")

        app.expect(app.navigationBars["Orders"].firstMatch, timeout: 20, "native orders screen")
        XCTAssertFalse(app.webViews.firstMatch.exists, "Orders must be native, not a webview")
    }
}
