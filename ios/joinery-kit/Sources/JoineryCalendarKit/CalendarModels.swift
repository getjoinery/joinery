import Foundation
import JoineryKit

/// One item on the personal calendar — the `calendar_feed` wire shape. Most
/// items are projections (events, bookings); native personal entries also
/// carry edit coordinates (entryID, occurrenceDate) so the editor can open
/// without parsing web URLs.
public struct CalItem: Identifiable, Equatable, Sendable {
    public let start: String        // UTC "yyyy-MM-dd HH:mm:ss"
    public let end: String
    public let allDay: Bool
    public let title: String
    public let url: String?
    public let colorHex: String
    public let type: String         // event | booking | external | personal
    public let sourceKey: String
    public let blocksAvailability: Bool
    public let entryID: Int?
    public let occurrenceDate: String?  // "yyyy-MM-dd", virtual occurrences only

    public var id: String { sourceKey }

    /// Native entries are the ones this module can edit in place.
    public var isEditableEntry: Bool { entryID != nil }
    public var isRecurringOccurrence: Bool { occurrenceDate != nil }

    init?(json: JSONValue) {
        guard let start = json["start"]?.stringValue, !start.isEmpty else { return nil }
        self.start = start
        end = json["end"]?.stringValue ?? start
        allDay = json["all_day"]?.boolValue ?? false
        title = json["title"]?.stringValue ?? ""
        url = json["url"]?.stringValue
        colorHex = json["color"]?.stringValue ?? "#6b7280"
        type = json["type"]?.stringValue ?? "personal"
        sourceKey = json["source_key"]?.stringValue ?? "\(start)-\(json["title"]?.stringValue ?? "")"
        blocksAvailability = json["blocks_availability"]?.boolValue ?? true
        entryID = json["entry_id"]?.intValue
        occurrenceDate = json["occurrence_date"]?.stringValue
    }
}

/// The `calendar_feed` payload.
public struct CalendarFeed: Equatable, Sendable {
    public let items: [CalItem]
    public let timezone: String

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        items = (data["items"]?.arrayValue ?? []).compactMap(CalItem.init(json:))
        timezone = data["timezone"]?.stringValue ?? ""
    }
}

/// Stored recurrence settings on a native entry (`calendar_entry`).
public struct CalRecurrence: Equatable, Sendable {
    public let type: String?        // daily | weekly | monthly | yearly | nil
    public let interval: Int
    public let daysOfWeek: [Int]    // weekly: 0=Sun…6=Sat; monthly by-weekday: single value
    public let weekOfMonth: Int?    // monthly by-weekday: 1-4, -1 = last
    public let endDate: String?     // "yyyy-MM-dd"

    init(json: JSONValue?) {
        type = json?["type"]?.stringValue
        interval = max(1, json?["interval"]?.intValue ?? 1)
        let raw = json?["days_of_week"]?.stringValue ?? ""
        daysOfWeek = raw.split(separator: ",").compactMap { Int($0) }.filter { (0...6).contains($0) }
        weekOfMonth = json?["week_of_month"]?.intValue
        endDate = json?["end_date"]?.stringValue
    }
}

/// The `calendar_entry` payload — one native entry shaped for the editor.
public struct CalEntryDetail: Equatable, Sendable {
    public let entryID: Int
    public let title: String
    public let date: String         // wall-clock "yyyy-MM-dd"
    public let startTime: String    // "HH:mm:ss"
    public let endTime: String
    public let timezone: String     // IANA zone the wall-clock values are in
    public let allDay: Bool
    public let blocksAvailability: Bool
    public let isRecurringParent: Bool
    public let recurrenceDescription: String
    public let recurrence: CalRecurrence

    public init?(data: JSONValue?) {
        guard let entry = data?["entry"], let id = entry["entry_id"]?.intValue else { return nil }
        entryID = id
        title = entry["title"]?.stringValue ?? ""
        date = entry["date"]?.stringValue ?? ""
        startTime = entry["start_time"]?.stringValue ?? ""
        endTime = entry["end_time"]?.stringValue ?? ""
        timezone = entry["timezone"]?.stringValue ?? ""
        allDay = entry["all_day"]?.boolValue ?? false
        blocksAvailability = entry["blocks_availability"]?.boolValue ?? true
        isRecurringParent = entry["is_recurring_parent"]?.boolValue ?? false
        recurrenceDescription = entry["recurrence_description"]?.stringValue ?? ""
        recurrence = CalRecurrence(json: entry["recurrence"])
    }
}

// MARK: - Date math (pure, unit-tested)

public enum CalDisplay {
    static let dbFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return f
    }()

    /// Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)".
    public static func date(_ dbTime: String) -> Date? {
        dbFormatter.date(from: String(dbTime.prefix(19)))
    }

    /// Local day key ("yyyy-MM-dd" in the calendar's zone) for grouping.
    public static func dayKey(_ date: Date, calendar: Calendar) -> String {
        let c = calendar.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", c.year ?? 0, c.month ?? 0, c.day ?? 0)
    }

    /// Every local day an item touches. End instants are exclusive, so an
    /// all-day entry ending at the next local midnight stays on one day.
    public static func dayKeys(for item: CalItem, calendar: Calendar) -> [String] {
        guard let start = date(item.start) else { return [] }
        let end = date(item.end) ?? start
        var keys: [String] = []
        var cursor = calendar.startOfDay(for: start)
        var guardrail = 0
        repeat {
            keys.append(dayKey(cursor, calendar: calendar))
            guard let next = calendar.date(byAdding: .day, value: 1, to: cursor) else { break }
            cursor = next
            guardrail += 1
        } while cursor < end && guardrail < 62
        return keys
    }

    /// Agenda ordering: all-day items first, then by start instant.
    public static func agendaSort(_ a: CalItem, _ b: CalItem) -> Bool {
        if a.allDay != b.allDay { return a.allDay }
        if a.start != b.start { return a.start < b.start }
        return a.sourceKey < b.sourceKey
    }

    /// "2:00 – 3:30 PM" for timed items, "All day" otherwise.
    public static func timeLabel(_ item: CalItem) -> String {
        if item.allDay { return "All day" }
        guard let start = date(item.start), let end = date(item.end) else { return "" }
        let f = DateIntervalFormatter()
        f.dateStyle = .none
        f.timeStyle = .short
        return f.string(from: start, to: end)
    }
}

public enum CalMonthMath {
    /// The first instant of the month containing `anchor`.
    public static func monthStart(_ anchor: Date, calendar: Calendar) -> Date {
        let comps = calendar.dateComponents([.year, .month], from: anchor)
        return calendar.date(from: comps) ?? anchor
    }

    /// Cells for a month grid honoring the calendar's first weekday:
    /// leading nils, then one Date per day.
    public static func gridDays(for anchor: Date, calendar: Calendar) -> [Date?] {
        let first = monthStart(anchor, calendar: calendar)
        guard let range = calendar.range(of: .day, in: .month, for: first) else { return [] }
        let firstWeekday = calendar.component(.weekday, from: first)
        let leading = (firstWeekday - calendar.firstWeekday + 7) % 7
        var cells: [Date?] = Array(repeating: nil, count: leading)
        for day in range {
            cells.append(calendar.date(byAdding: .day, value: day - 1, to: first))
        }
        return cells
    }

    /// Weekday header symbols in the calendar's display order.
    public static func weekdaySymbols(_ calendar: Calendar) -> [String] {
        let symbols = calendar.veryShortWeekdaySymbols
        let shift = calendar.firstWeekday - 1
        return Array(symbols[shift...] + symbols[..<shift])
    }

    /// The UTC fetch window for a month view: the month padded a week on
    /// each side, so leading/trailing grid cells have data too.
    public static func fetchWindow(for anchor: Date, calendar: Calendar) -> (start: String, end: String) {
        let first = monthStart(anchor, calendar: calendar)
        let start = calendar.date(byAdding: .day, value: -7, to: first) ?? first
        let nextMonth = calendar.date(byAdding: .month, value: 1, to: first) ?? first
        let end = calendar.date(byAdding: .day, value: 7, to: nextMonth) ?? nextMonth
        let f = CalDisplay.dbFormatter
        return (f.string(from: start), f.string(from: end))
    }
}
