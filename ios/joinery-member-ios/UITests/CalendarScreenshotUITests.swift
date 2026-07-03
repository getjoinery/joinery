import XCTest

/// Manual utility, not part of any gate: walks the native calendar screens
/// and attaches full-resolution screenshots for visual review. Run explicitly:
///   -only-testing:JoineryMemberUITests/CalendarScreenshotUITests
/// then export with `xcrun xcresulttool export attachments`.
final class CalendarScreenshotUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = true
    }

    func testCaptureCalendarScreens() throws {
        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Calendar"].tap()

        _ = app.staticTexts["cal_month_title"].firstMatch.waitForExistence(timeout: 20)

        // Editor with the timed + recurrence surface open. Dismiss the
        // keyboard (return key) before touching toggles — a covered switch
        // swallows the tap.
        app.buttons["cal_add"].tap()
        let titleField = app.textFields["cal_entry_title"].firstMatch
        guard titleField.waitForExistence(timeout: 10) else { return }
        titleField.tap()
        titleField.typeText("Focus time\n")
        flip(app, "cal_entry_allday")
        flip(app, "cal_entry_repeats")
        sleep(1)
        attach(app, "2-entry-editor")

        // Save (weekly on today's weekday) and show the grid + agenda with
        // the recurring entry's dots.
        app.buttons["cal_entry_save"].tap()
        let row = app.staticTexts["Focus time"].firstMatch
        _ = row.waitForExistence(timeout: 20)
        sleep(2)
        attach(app, "1-calendar")

        // The edit sheet for a recurring occurrence (scope-aware).
        row.tap()
        _ = app.buttons["cal_entry_delete"].firstMatch.waitForExistence(timeout: 15)
        sleep(1)
        attach(app, "3-entry-edit")

        // Clean up: delete this walk's entry plus leftovers from earlier walks.
        confirmDelete(app)
        for title in ["Morning workout", "Focus time"] {
            deleteIfPresent(app, title: title)
        }
    }

    /// SwiftUI Toggle rows swallow center taps — hit the thumb edge.
    private func flip(_ app: XCUIApplication, _ id: String) {
        let sw = app.switches[id].firstMatch
        guard sw.waitForExistence(timeout: 5) else { return }
        sw.coordinate(withNormalizedOffset: CGVector(dx: 0.93, dy: 0.5)).tap()
    }

    /// Confirm whichever delete prompt appears: the standalone alert or the
    /// recurring scope dialog (an action sheet on iPhone).
    private func confirmDelete(_ app: XCUIApplication) {
        let alertDelete = app.alerts.firstMatch.buttons["Delete"]
        let dialogAll = app.sheets.firstMatch.buttons["All occurrences"]
        let plainAll = app.buttons["All occurrences"].firstMatch
        for _ in 0..<20 {
            if alertDelete.exists { alertDelete.tap(); return }
            if dialogAll.exists { dialogAll.tap(); return }
            if plainAll.exists { plainAll.tap(); return }
            usleep(500_000)
        }
    }

    private func deleteIfPresent(_ app: XCUIApplication, title: String) {
        let row = app.staticTexts[title].firstMatch
        guard row.waitForExistence(timeout: 5) else { return }
        row.tap()
        guard app.buttons["cal_entry_delete"].firstMatch.waitForExistence(timeout: 15) else { return }
        app.buttons["cal_entry_delete"].tap()
        confirmDelete(app)
        sleep(2)
    }

    private func attach(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }
}
