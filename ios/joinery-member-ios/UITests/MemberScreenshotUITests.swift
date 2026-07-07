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

        app.tabBars.buttons["My Profile"].tap()
        guard app.staticTexts["profile_user_name"].firstMatch.waitForExistence(timeout: 20) else { return }
        sleep(1)
        attach(app, "1-profile-dashboard")

        app.openMore()
        if app.buttons["more_core-orders"].firstMatch.waitForExistence(timeout: 10) {
            app.buttons["more_core-orders"].tap()
            _ = app.navigationBars["Orders"].firstMatch.waitForExistence(timeout: 15)
            sleep(1)
            attach(app, "2-orders")
            app.navigationBars.buttons.firstMatch.tap()
        }

        app.openMore()
        if app.buttons["more_core-subscriptions"].firstMatch.waitForExistence(timeout: 10) {
            app.buttons["more_core-subscriptions"].tap()
            _ = app.navigationBars["Subscriptions"].firstMatch.waitForExistence(timeout: 15)
            sleep(1)
            attach(app, "3-subscriptions")
            app.navigationBars.buttons.firstMatch.tap()
        }

        app.openMore()
        if app.buttons["more_core-events"].firstMatch.waitForExistence(timeout: 10) {
            app.buttons["more_core-events"].tap()
            _ = app.navigationBars["My Events"].firstMatch.waitForExistence(timeout: 15)
            sleep(1)
            attach(app, "4-events")
            app.navigationBars.buttons.firstMatch.tap()
        }

        app.tabBars.buttons["My Profile"].tap()
        _ = app.staticTexts["profile_user_name"].firstMatch.waitForExistence(timeout: 15)
        if app.staticTexts["Messages"].firstMatch.waitForExistence(timeout: 5) {
            app.staticTexts["Messages"].firstMatch.tap()
            _ = app.collectionViews["conversations_list"].firstMatch.waitForExistence(timeout: 15)
            sleep(1)
            attach(app, "5-conversations")
        }

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
