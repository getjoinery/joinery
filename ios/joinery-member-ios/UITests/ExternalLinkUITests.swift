import XCTest

/// Gate: off-site links leave the app for Safari (webview link policy). The
/// runner stages a probe link on the profile page for determinism.
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

        let webView = app.webViews.firstMatch
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
