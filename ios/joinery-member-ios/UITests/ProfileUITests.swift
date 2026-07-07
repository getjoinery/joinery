import XCTest

/// Gate: the "My Profile" tab renders the NATIVE dashboard (JoineryMemberKit;
/// `core-profile`'s destination is `{type: "native", screen: "profile"}`) —
/// no webview — with the user card and stat tiles, and its tiles navigate to
/// the other native member screens.
final class ProfileUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testNativeProfileDashboardRenders() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["My Profile"].tap()

        // Native proof: the dashboard list renders (no webview involved).
        app.expect(app.staticTexts["profile_user_name"].firstMatch, timeout: 20, "native profile user card")
        XCTAssertFalse(app.webViews.firstMatch.exists, "the Profile tab must be native, not a webview")

        // The Security tile navigates into the native security screen.
        app.staticTexts["Security"].firstMatch.tap()
        app.expect(app.collectionViews["security_list"].firstMatch, timeout: 15, "native security screen")
        XCTAssertFalse(app.webViews.firstMatch.exists, "Security must be native, not a webview")
    }
}
