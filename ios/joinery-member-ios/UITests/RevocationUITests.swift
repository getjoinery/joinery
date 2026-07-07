import XCTest

/// Gate: revoking every session from the native security screen signs the
/// app out (specs/implemented/mobile_native_member_screens.md acceptance 5).
/// Sign Out All Devices runs the same `security` action the web page uses;
/// it kills the current API key, so the next authenticated call 401s and
/// the native shell lands on login. The runner sets
/// app_bridge_key_check_seconds=0 so any bridged web session dies on its
/// very next load too (lifetime coupling).
final class RevocationUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    func testRevokeAllFromNativeSecuritySignsOut() {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.openSettings()

        app.expect(app.buttons["settings_security"], "Security row in Settings")
        app.buttons["settings_security"].tap()
        app.expect(app.collectionViews["security_list"].firstMatch, timeout: 20, "native security screen")
        app.expect(app.staticTexts["This device"].firstMatch, timeout: 15, "current session marker")

        // Sign Out All Devices renders whenever more than one session key is
        // live — always true mid-gate (every prior suite minted one). It sits
        // below the per-session rows, and the gate accumulates enough of them
        // to push it off-screen; List is lazy, so scroll until it exists.
        let revokeAll = app.buttons["security_revoke_all"].firstMatch
        var scrolls = 0
        while !revokeAll.exists && scrolls < 10 {
            app.collectionViews["security_list"].firstMatch.swipeUp()
            scrolls += 1
        }
        app.expect(revokeAll, timeout: 10, "Sign Out All Devices button")
        revokeAll.tap()

        // The confirmationDialog is an action sheet; scope to it, because the
        // triggering row button carries the same label.
        let confirm = app.sheets.buttons["Sign Out All Devices"].firstMatch
        app.expect(confirm, timeout: 10, "revoke-all confirmation")
        confirm.tap()

        // The current key is among the revoked: the next authenticated call
        // 401s and the shell signs out to the login screen.
        app.expect(app.textFields["login_email"], timeout: 30, "login screen after revoke-all")
    }
}
