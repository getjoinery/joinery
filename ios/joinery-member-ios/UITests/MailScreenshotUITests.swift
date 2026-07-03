import XCTest

/// Manual utility, not part of any gate: walks the native mail screens and
/// attaches full-resolution screenshots for visual review. Run explicitly:
///   -only-testing:JoineryMemberUITests/MailScreenshotUITests
/// then export with `xcrun xcresulttool export attachments`.
final class MailScreenshotUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = true
    }

    func testCaptureMailScreens() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Email"].tap()

        _ = app.collectionViews["mail_list"].firstMatch.waitForExistence(timeout: 20)
        sleep(2)
        attach(app, "1-mail-list")

        // The attachment-probe thread shows off the HTML body + chips.
        let row = app.staticTexts
            .matching(NSPredicate(format: "label BEGINSWITH %@", "NativeMail Probe2")).firstMatch
        guard row.waitForExistence(timeout: 10) else { return }
        row.tap()
        _ = app.staticTexts["mail_thread_subject"].waitForExistence(timeout: 15)
        sleep(3)
        attach(app, "2-thread")

        guard app.buttons["mail_reply"].waitForExistence(timeout: 5) else { return }
        app.buttons["mail_reply"].tap()
        _ = app.textViews["mail_compose_body"].waitForExistence(timeout: 10)
        sleep(1)
        attach(app, "3-compose")
    }

    private func attach(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }
}
