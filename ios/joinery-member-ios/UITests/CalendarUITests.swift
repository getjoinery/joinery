import XCTest

/// Gate: the Calendar tab renders the NATIVE calendar (JoineryCalendarKit;
/// the entry's navigation destination is `{type: "native", screen:
/// "calendar"}`), month navigation works, and a personal entry round-trips —
/// created in the editor, visible in the agenda, then deleted. The runner
/// passes a unique title via the environment and verifies the soft-deleted
/// row server-side afterwards.
final class CalendarUITests: XCTestCase {

    override func setUp() {
        continueAfterFailure = false
    }

    private func monthTitle(offset: Int) -> String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "MMMM yyyy"
        let date = Calendar.current.date(byAdding: .month, value: offset, to: Date())!
        return formatter.string(from: date)
    }

    func testNativeCalendarEntryCrud() throws {
        let title = TestEnv.require("JOINERY_CAL_TITLE")

        let app = XCUIApplication()
        app.launchJoinery()
        app.signIn(email: TestEnv.email, password: TestEnv.password)
        app.expectSignedIn()
        app.tabBars.buttons["Calendar"].tap()

        // Native proof: the month grid renders (no webview involved).
        let monthLabel = app.staticTexts["cal_month_title"].firstMatch
        app.expect(monthLabel, timeout: 20, "native month title")
        XCTAssertFalse(app.webViews.firstMatch.exists, "the Calendar tab must be native, not a webview")
        XCTAssertEqual(monthLabel.label, monthTitle(offset: 0))

        // Month navigation round-trip.
        app.buttons["cal_next_month"].tap()
        app.expect(app.staticTexts[monthTitle(offset: 1)].firstMatch, timeout: 10, "next month title")
        app.buttons["cal_today"].tap()
        app.expect(app.staticTexts[monthTitle(offset: 0)].firstMatch, timeout: 10, "back to current month")

        // Create an all-day entry for today via the native editor.
        app.buttons["cal_add"].tap()
        let titleField = app.textFields["cal_entry_title"].firstMatch
        app.expect(titleField, timeout: 10, "entry editor title field")
        titleField.tap()
        titleField.typeText(title)
        app.buttons["cal_entry_save"].tap()

        // The agenda shows the new entry after the reload.
        let row = app.staticTexts[title].firstMatch
        app.expect(row, timeout: 20, "created entry in the agenda")

        // Open it again and delete it (standalone → plain confirm).
        row.tap()
        let deleteButton = app.buttons["cal_entry_delete"].firstMatch
        app.expect(deleteButton, timeout: 15, "delete button in the editor")
        deleteButton.tap()
        let confirm = app.alerts.firstMatch.buttons["Delete"]
        app.expect(confirm, timeout: 10, "delete confirmation")
        confirm.tap()

        // The entry leaves the agenda.
        let gone = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "exists == false"), object: row)
        XCTAssertEqual(XCTWaiter.wait(for: [gone], timeout: 20), .completed,
                       "deleted entry should leave the agenda")
    }
}
