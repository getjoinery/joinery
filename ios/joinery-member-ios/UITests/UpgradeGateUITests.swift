import XCTest

/// Gate: a 426 UpgradeRequired — including at login — renders the blocking
/// upgrade screen. Orchestrated: the runner raises the server's
/// `api_min_client_versions` for joinery-member-ios above this build before
/// running, and restores it after.
final class UpgradeGateUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testLoginHits426AndBlocks() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)

        // The login 426 must flip the whole app into the blocking screen.
        app.expect(app.staticTexts["upgrade_title"], timeout: 15, "blocking upgrade screen")
        // Nothing else is reachable.
        XCTAssertFalse(app.textFields["login_email"].exists)
        XCTAssertFalse(app.staticTexts["settings_display_name"].exists)
    }
}
