import XCTest

/// Gate: the Dashboard's Subscriptions link renders the NATIVE subscription list
/// (JoineryMemberKit; `core-subscriptions`'s destination is `{type:
/// "native", screen: "subscriptions"}`) — no webview. Change-plan and
/// billing rows stay web (deliberately — Apple IAP policy), so this gate
/// only checks the native list itself renders.
final class SubscriptionsUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testNativeSubscriptionsListRenders() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.openDashboardLink("profile_tile_subscriptions", "Subscriptions tile")

        app.expect(app.navigationBars["Subscriptions"].firstMatch, timeout: 20, "native subscriptions screen")
        XCTAssertFalse(app.webViews.firstMatch.exists, "Subscriptions must be native, not a webview")
    }
}
