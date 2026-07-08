import XCTest

/// Phase 1 gate — the branded shell on JoineryKit. Logging in with a
/// website-created account lands on the ScrollDaddy-branded navigation shell,
/// and the DNS-filtering surface is reachable (native screens when this build
/// knows them, the /profile/dns_filtering/* webview otherwise).
///
/// Requires the runner env: JOINERY_TEST_EMAIL / JOINERY_TEST_PASSWORD for a
/// dev account with DNS filtering, and the server to carry `scrolldaddy-ios`
/// entries in `app_navigation` (tab pinning) and, once native, `nativeScreen`
/// on the dns_filtering profileMenu entries.
final class ShellUITests: XCTestCase {

    override func setUp() { continueAfterFailure = false }

    func testLoginLandsOnBrandedShell() {
        let app = XCUIApplication()
        app.launchScrollDaddy()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        // The Filtering tab (from the dns_filtering profileMenu, pinned for
        // scrolldaddy-ios via app_navigation) is present in the shell.
        XCTAssertTrue(
            app.tabBars.buttons["Filtering"].waitForExistence(timeout: 10),
            "the DNS-filtering surface was not pinned into the shell"
        )
    }

    func testFilteringSurfaceReachable() {
        let app = XCUIApplication()
        app.launchScrollDaddy()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()

        // The tab is titled "Filtering" (its menu display name); the server's
        // nativeScreen makes it a native destination this build knows
        // (dns_protection → ProtectionScreen), so tapping it renders natively.
        // A build without JoineryDNSFilterKit would get the webview fallback —
        // accept either.
        let filtering = app.tabBars.buttons["Filtering"]
        XCTAssertTrue(filtering.waitForExistence(timeout: 10), "no Filtering tab in the shell")
        filtering.tap()

        let native = app.otherElements["protection_list"]
        let nativeLabel = app.staticTexts["protection_status_label"]
        let webview = app.webViews.firstMatch
        XCTAssertTrue(
            native.waitForExistence(timeout: 15)
            || nativeLabel.waitForExistence(timeout: 2)
            || webview.waitForExistence(timeout: 5),
            "the Filtering surface rendered neither the native screen nor the webview fallback"
        )
    }
}
