import XCTest

/// Gate: the tab bar and More list are driven entirely by
/// `GET /api/v1/app/navigation`; a plugin profileMenu entry added server-side
/// appears with no app rebuild.
final class NavigationShellUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testTabsAndMoreRenderFromServerNavigation() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()

        // app_navigation's default pins these three.
        app.expect(app.tabBars.buttons["Dashboard"], timeout: 10, "Dashboard tab")
        app.expect(app.tabBars.buttons["Calendar"], "Calendar tab")
        app.expect(app.tabBars.buttons["Email"], "Email tab")

        // Everything unpinned lands in More, plus native Settings.
        app.openMore()
        app.expect(app.buttons["more_core-home"], timeout: 10, "Home in More")
        app.expect(app.buttons["more_messenger"], "Messages in More")
        _ = app.moreRow("more_settings", "Settings in More")

        // Pinned entries never duplicate into More.
        XCTAssertFalse(app.buttons["more_core-calendar"].exists)
    }

    /// Orchestrated: the runner adds a profileMenu entry to a plugin's
    /// plugin.json and syncs menus server-side, then runs only this test
    /// WITHOUT rebuilding the app. The entry appearing proves navigation is
    /// fully server-driven.
    func testPluginMenuEntryAppearsWithoutRebuild() throws {
        guard TestEnv.optional("JOINERY_EXPECT_MENU_PROBE") == "1" else {
            throw XCTSkip("menu probe not staged (JOINERY_EXPECT_MENU_PROBE unset)")
        }
        let slug = TestEnv.optional("JOINERY_MENU_PROBE_SLUG") ?? "mailbox-phase3-probe"

        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.openMore()
        app.expect(app.buttons["more_\(slug)"], timeout: 15, "server-added menu entry (no rebuild)")
    }
}
