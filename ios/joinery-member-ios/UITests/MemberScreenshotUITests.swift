import XCTest

/// Manual utility, not part of any gate: walks the native member screens
/// (profile, orders, subscriptions, events, conversations, security) and
/// attaches full-resolution screenshots for visual review. Run explicitly:
///   -only-testing:JoineryMemberUITests/MemberScreenshotUITests
/// then export with `xcrun xcresulttool export attachments`.
final class MemberScreenshotUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = true
    }

    func testCaptureMemberScreens() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()

        app.openDashboard()
        sleep(1)
        attach(app, "1-profile-dashboard")

        // Each native list is reached from its dashboard link, as a member reaches it.
        for (tile, bar, shot) in [("profile_tile_orders", "Orders", "2-orders"),
                                  ("profile_tile_subscriptions", "Subscriptions", "3-subscriptions"),
                                  ("profile_tile_events", "My Events", "4-events")] {
            app.openDashboardLink(tile, bar)
            _ = app.navigationBars[bar].firstMatch.waitForExistence(timeout: 15)
            sleep(1)
            attach(app, shot)
            app.navigationBars.buttons.firstMatch.tap()
        }

        app.openDashboardLink("profile_tile_conversations", "Messages")
        _ = app.collectionViews["conversations_list"].firstMatch.waitForExistence(timeout: 15)
        sleep(1)
        attach(app, "5-conversations")

        app.openSettings()
        if app.buttons["settings_security"].firstMatch.waitForExistence(timeout: 10) {
            app.buttons["settings_security"].tap()
            _ = app.collectionViews["security_list"].firstMatch.waitForExistence(timeout: 15)
            sleep(1)
            attach(app, "6-security")
        }
    }

    private func attach(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }
}
