import XCTest

/// Gate: off-site links leave the app for Safari (webview link policy). The
/// runner stages a probe link on the notifications page — a deliberately-web
/// surface reached from the native dashboard — for determinism.
final class ExternalLinkUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testExternalLinkOpensSafari() throws {
        guard TestEnv.optional("JOINERY_EXPECT_LINK_PROBE") == "1" else {
            throw XCTSkip("link probe not staged (JOINERY_EXPECT_LINK_PROBE unset)")
        }

        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["My Profile"].tap()
        app.expect(app.staticTexts["profile_user_name"].firstMatch, timeout: 20, "native profile dashboard")

        let row = app.buttons["profile_notifications"].firstMatch
        app.expect(row, timeout: 10, "Notifications row on dashboard")
        row.tap()

        let webView = app.webViews.firstMatch
        app.dismissCookieConsent()
        let probe = webView.links["External Probe Link"].firstMatch
        app.expect(probe, timeout: 30, "staged external probe link")
        probe.tap()

        // The link leaves the app: Safari foregrounds, the app backgrounds.
        let safari = XCUIApplication(bundleIdentifier: "com.apple.mobilesafari")
        XCTAssertTrue(safari.wait(for: .runningForeground, timeout: 20),
                      "Safari should foreground for an off-site link")
        safari.terminate()
    }
}
