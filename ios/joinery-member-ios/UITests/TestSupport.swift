import XCTest

/// Shared helpers for the Phase 2 gate suites. Credentials and per-run
/// parameters arrive via the test runner's environment (xcodebuild
/// TEST_RUNNER_* variables) — never hard-coded.
enum TestEnv {
    static var email: String { require("JOINERY_TEST_EMAIL") }
    static var password: String { require("JOINERY_TEST_PASSWORD") }

    static func optional(_ key: String) -> String? {
        ProcessInfo.processInfo.environment[key]
    }

    static func require(_ key: String) -> String {
        guard let value = ProcessInfo.processInfo.environment[key], !value.isEmpty else {
            XCTFail("Missing required test environment variable \(key)")
            return ""
        }
        return value
    }
}

extension XCUIApplication {
    /// Launch signed out (Keychain wiped) with optional env overrides for
    /// the app process.
    func launchJoinery(env: [String: String] = [:]) {
        launchArguments += ["--reset-auth"]
        // The site the gate made its fixture on (TEST_RUNNER_JOINERY_BASE_URL).
        if let base = TestEnv.optional("JOINERY_BASE_URL"), !base.isEmpty {
            launchEnvironment["JOINERY_BASE_URL"] = base
        }
        for (key, value) in env { launchEnvironment[key] = value }
        launch()
    }

    /// Drive the native login screen. Leaves the app wherever login lands it.
    func signIn(email: String, password: String) {
        let emailField = textFields["login_email"]
        XCTAssertTrue(emailField.waitForExistence(timeout: 10), "login screen did not appear")
        emailField.tap()
        emailField.typeText(email)
        let passwordField = secureTextFields["login_password"]
        passwordField.tap()
        passwordField.typeText(password)
        buttons["login_submit"].tap()
        dismissSavePasswordPrompt()
    }

    /// Close the system share sheet. Since iOS 26 it opens full height with an
    /// ✕ that appears once it has finished presenting; a swipe down no longer
    /// closes it. Wait for the ✕, tap it, and wait for the sheet to go; swipe
    /// only when no ✕ ever shows.
    func dismissShareSheet(_ share: XCUIElement, timeout: TimeInterval = 10) {
        let deadline = Date().addingTimeInterval(timeout)
        while Date() < deadline && share.exists {
            for label in ["Close", "xmark", "Done"] {
                let b = buttons[label].firstMatch
                if b.exists && b.isHittable {
                    b.tap()
                    if share.waitForNonExistence(timeout: 5) { return }
                }
            }
            Thread.sleep(forTimeInterval: 0.5)
        }
        if share.exists {
            share.swipeDown(velocity: .fast)
            _ = share.waitForNonExistence(timeout: 5)
        }
    }

    /// iOS 26+ offers "Save Password?" after a sign-in. The sheet covers the
    /// screen and takes the next tap, so a test answers it "Not Now". It may
    /// belong to the app or to SpringBoard; neither appearing is fine.
    func dismissSavePasswordPrompt(timeout: TimeInterval = 20) {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        let deadline = Date().addingTimeInterval(timeout)
        var seen = false
        var clearChecks = 0
        while Date() < deadline {
            var shown = false
            for owner in [springboard, self] {
                let notNow = owner.buttons["Not Now"]
                if notNow.exists {
                    shown = true
                    seen = true
                    // A tap by position: the sheet's own element can refuse a
                    // plain tap while it is still presenting.
                    notNow.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).tap()
                    break
                }
            }
            if shown {
                clearChecks = 0
            } else {
                clearChecks += 1
                // Never seen within a few seconds: no sheet this time. Seen:
                // gone on two checks in a row, so its dismissal has finished.
                if (!seen && clearChecks >= 8) || (seen && clearChecks >= 2) { break }
            }
            Thread.sleep(forTimeInterval: 1)
        }
        if seen { Thread.sleep(forTimeInterval: 1) }
    }

    /// Wait for any element to exist, with a clearer failure message.
    @discardableResult
    func expect(_ element: XCUIElement, timeout: TimeInterval = 10, _ label: String) -> Bool {
        let ok = element.waitForExistence(timeout: timeout)
        XCTAssertTrue(ok, "expected \(label) within \(Int(timeout))s")
        return ok
    }

    /// The signed-in surface is the navigation shell: a tab bar whose last
    /// slot is always More.
    func expectSignedIn(timeout: TimeInterval = 25) {
        expect(tabBars.buttons["More"], timeout: timeout, "signed-in tab bar")
    }

    /// Open the More tab (waits for the shell first).
    func openMore() {
        expectSignedIn()
        tabBars.buttons["More"].tap()
    }

    /// A row of the More list, scrolled into being: the list is lazy, so a row
    /// below the fold does not exist until it is scrolled to. Opens More first.
    func moreRow(_ identifier: String, _ label: String) -> XCUIElement {
        openMore()
        let row = buttons[identifier].firstMatch
        var scrolls = 0
        while !row.waitForExistence(timeout: scrolls == 0 ? 5 : 1) && scrolls < 8 {
            collectionViews.firstMatch.swipeUp()
            scrolls += 1
        }
        expect(row, timeout: 5, label)
        return row
    }

    /// The Dashboard tab (core-profile, pinned first by app_navigation's
    /// default): the native member dashboard, from which Orders,
    /// Subscriptions, Events, Messages and Security are reached.
    func openDashboard() {
        expectSignedIn()
        tabBars.buttons["Dashboard"].tap()
        expect(staticTexts["profile_user_name"].firstMatch, timeout: 20, "native dashboard")
    }

    /// Open the dashboard and follow one of its links (profile_tile_*,
    /// profile_notifications), scrolling to it if it is below the fold.
    func openDashboardLink(_ identifier: String, _ label: String) {
        openDashboard()
        let link = descendants(matching: .any)[identifier].firstMatch
        var scrolls = 0
        while !link.waitForExistence(timeout: scrolls == 0 ? 5 : 1) && scrolls < 8 {
            collectionViews.firstMatch.swipeUp()
            scrolls += 1
        }
        expect(link, timeout: 5, label)
        link.tap()
    }

    /// Dismiss the site cookie-consent banner if it's showing in a webview.
    /// Its fixed-position layer swallows taps on page controls underneath;
    /// each test run reinstalls the app (fresh webview data store), so the
    /// banner is back every run.
    func dismissCookieConsent() {
        let accept = webViews.buttons["Accept All"].firstMatch
        if accept.waitForExistence(timeout: 4) { accept.tap() }
    }

    /// Navigate More → Settings (the native settings screen).
    func openSettings() {
        moreRow("more_settings", "Settings row in More").tap()
        expect(staticTexts["settings_display_name"], timeout: 15, "settings screen")
    }
}
