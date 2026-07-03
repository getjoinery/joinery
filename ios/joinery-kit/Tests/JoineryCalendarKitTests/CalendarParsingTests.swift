import XCTest
@testable import JoineryCalendarKit
@testable import JoineryKit

/// Parsing tests over live calendar action payloads captured from dev
/// (Fixtures/*.json are verbatim API envelopes) plus the pure month math
/// behind the grid.
final class CalendarParsingTests: XCTestCase {

    private func fixture(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json"
        )
        let envelope = try JSONValue.parse(Data(contentsOf: url))
        return try XCTUnwrap(envelope["data"], "fixture \(name) has no data")
    }

    /// A fixed calendar so day grouping is deterministic regardless of the
    /// machine running the tests.
    private var utcCalendar: Calendar {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC")!
        cal.firstWeekday = 1 // Sunday
        return cal
    }

    // MARK: Fixtures

    func testFeedFixtureParses() throws {
        let feed = try XCTUnwrap(CalendarFeed(data: fixture("calendar_feed")))
        XCTAssertFalse(feed.timezone.isEmpty)
        XCTAssertEqual(feed.items.count, 4)

        // The standalone timed entry: edit coordinates but no occurrence.
        let standalone = try XCTUnwrap(feed.items.first { $0.title.contains("standalone") })
        XCTAssertFalse(standalone.allDay)
        XCTAssertNotNil(standalone.entryID)
        XCTAssertNil(standalone.occurrenceDate)
        XCTAssertTrue(standalone.isEditableEntry)
        XCTAssertFalse(standalone.isRecurringOccurrence)

        // Recurring occurrences: same parent id, distinct occurrence dates.
        let occurrences = feed.items.filter { $0.occurrenceDate != nil }
        XCTAssertEqual(occurrences.count, 2)
        XCTAssertEqual(Set(occurrences.compactMap(\.entryID)).count, 1)
        XCTAssertEqual(Set(occurrences.map(\.sourceKey)).count, 2)
        for occ in occurrences {
            XCTAssertTrue(occ.allDay)
            XCTAssertTrue(occ.isRecurringOccurrence)
        }
    }

    func testEntryFixtureParses() throws {
        let detail = try XCTUnwrap(CalEntryDetail(data: fixture("calendar_entry")))
        XCTAssertEqual(detail.title, "CalAPI Probe weekly")
        XCTAssertTrue(detail.allDay)
        XCTAssertTrue(detail.isRecurringParent)
        XCTAssertFalse(detail.recurrenceDescription.isEmpty)
        XCTAssertEqual(detail.recurrence.type, "weekly")
        XCTAssertEqual(detail.recurrence.interval, 1)
        XCTAssertEqual(detail.recurrence.daysOfWeek, [1, 3])
        XCTAssertNotNil(detail.recurrence.endDate)
    }

    // MARK: Day grouping

    func testDayKeysSingleTimedItem() throws {
        let feed = try XCTUnwrap(CalendarFeed(data: fixture("calendar_feed")))
        let standalone = try XCTUnwrap(feed.items.first { $0.title.contains("standalone") })
        // 2026-07-10 19:00–20:30 UTC stays on one UTC day.
        XCTAssertEqual(CalDisplay.dayKeys(for: standalone, calendar: utcCalendar), ["2026-07-10"])
    }

    func testDayKeysAllDayStaysOnOneDay() throws {
        // An all-day New-York entry spans 04:00 → next-day 04:00 UTC; in a
        // New-York calendar it must group onto exactly its own day.
        var nyCal = Calendar(identifier: .gregorian)
        nyCal.timeZone = TimeZone(identifier: "America/New_York")!
        let feed = try XCTUnwrap(CalendarFeed(data: fixture("calendar_feed")))
        let occ = try XCTUnwrap(feed.items.first { $0.occurrenceDate == "2026-07-06" })
        XCTAssertEqual(CalDisplay.dayKeys(for: occ, calendar: nyCal), ["2026-07-06"])
    }

    func testAgendaSortAllDayFirstThenStart() throws {
        let feed = try XCTUnwrap(CalendarFeed(data: fixture("calendar_feed")))
        let sorted = feed.items.sorted(by: CalDisplay.agendaSort)
        XCTAssertTrue(sorted.first!.allDay)
        let timedStarts = sorted.filter { !$0.allDay }.map(\.start)
        XCTAssertEqual(timedStarts, timedStarts.sorted())
    }

    // MARK: Month math

    func testGridDaysJuly2026() {
        let cal = utcCalendar
        let july = CalDisplay.dbFormatter.date(from: "2026-07-15 12:00:00")!
        let cells = CalMonthMath.gridDays(for: july, calendar: cal)
        // July 1, 2026 is a Wednesday → 3 leading blanks with a Sunday start.
        XCTAssertEqual(cells.prefix(3).compactMap { $0 }.count, 0)
        XCTAssertEqual(cells.compactMap { $0 }.count, 31)
        let firstDay = cells.compactMap { $0 }.first!
        XCTAssertEqual(cal.component(.day, from: firstDay), 1)
        XCTAssertEqual(cal.component(.month, from: firstDay), 7)
    }

    func testGridDaysHonorsFirstWeekday() {
        var cal = utcCalendar
        cal.firstWeekday = 2 // Monday
        let july = CalDisplay.dbFormatter.date(from: "2026-07-15 12:00:00")!
        let cells = CalMonthMath.gridDays(for: july, calendar: cal)
        // Monday start → Wednesday July 1 has 2 leading blanks.
        XCTAssertNil(cells[0])
        XCTAssertNil(cells[1])
        XCTAssertNotNil(cells[2])
    }

    func testFetchWindowPadsAWeekEachSide() {
        let cal = utcCalendar
        let july = CalDisplay.dbFormatter.date(from: "2026-07-15 12:00:00")!
        let window = CalMonthMath.fetchWindow(for: july, calendar: cal)
        XCTAssertEqual(window.start, "2026-06-24 00:00:00")
        XCTAssertEqual(window.end, "2026-08-08 00:00:00")
    }

    func testWeekdaySymbolsRotateWithFirstWeekday() {
        var cal = utcCalendar
        cal.firstWeekday = 2
        let symbols = CalMonthMath.weekdaySymbols(cal)
        XCTAssertEqual(symbols.count, 7)
        XCTAssertEqual(symbols.first, cal.veryShortWeekdaySymbols[1])
        XCTAssertEqual(symbols.last, cal.veryShortWeekdaySymbols[0])
    }

    // MARK: Recurrence input encoding

    func testRecurrenceInputEncodesWeeklyCount() throws {
        let input = CalRecurrenceInput(type: "weekly", interval: 2, daysOfWeek: [1, 3], ends: .afterCount(4))
        let json = input.jsonValue
        XCTAssertEqual(json["type"]?.stringValue, "weekly")
        XCTAssertEqual(json["interval"]?.intValue, 2)
        XCTAssertEqual(json["days_of_week"]?.arrayValue?.compactMap(\.intValue), [1, 3])
        XCTAssertEqual(json["ends"]?.stringValue, "count")
        XCTAssertEqual(json["count"]?.intValue, 4)
        XCTAssertNil(json["end_date"])
    }

    func testRecurrenceInputEncodesMonthlyByWeekday() throws {
        let input = CalRecurrenceInput(type: "monthly", daysOfWeek: [2], weekOfMonth: -1, ends: .onDate("2026-12-31"))
        let json = input.jsonValue
        XCTAssertEqual(json["week_of_month"]?.intValue, -1)
        XCTAssertEqual(json["days_of_week"]?.arrayValue?.compactMap(\.intValue), [2])
        XCTAssertEqual(json["ends"]?.stringValue, "date")
        XCTAssertEqual(json["end_date"]?.stringValue, "2026-12-31")
    }
}
