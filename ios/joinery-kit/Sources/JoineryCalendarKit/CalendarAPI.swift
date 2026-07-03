import Foundation
import JoineryKit

/// What the editor sends for a repeating entry.
public struct CalRecurrenceInput: Equatable, Sendable {
    public enum Ends: Equatable, Sendable {
        case never
        case onDate(String)     // "yyyy-MM-dd"
        case afterCount(Int)
    }

    public var type: String         // daily | weekly | monthly | yearly
    public var interval: Int
    public var daysOfWeek: [Int]    // weekly: 0=Sun…6=Sat
    public var weekOfMonth: Int?    // monthly by-weekday: 1-4, -1 = last
    public var ends: Ends

    public init(type: String, interval: Int = 1, daysOfWeek: [Int] = [], weekOfMonth: Int? = nil, ends: Ends = .never) {
        self.type = type
        self.interval = interval
        self.daysOfWeek = daysOfWeek
        self.weekOfMonth = weekOfMonth
        self.ends = ends
    }

    var jsonValue: JSONValue {
        var body: [(key: String, value: JSONValue)] = [
            (key: "type", value: .string(type)),
            (key: "interval", value: .number(Double(max(1, interval)))),
        ]
        if !daysOfWeek.isEmpty {
            body.append((key: "days_of_week", value: .array(daysOfWeek.map { .number(Double($0)) })))
        }
        if let weekOfMonth {
            body.append((key: "week_of_month", value: .number(Double(weekOfMonth))))
        }
        switch ends {
        case .never:
            body.append((key: "ends", value: .string("never")))
        case .onDate(let date):
            body.append((key: "ends", value: .string("date")))
            body.append((key: "end_date", value: .string(date)))
        case .afterCount(let count):
            body.append((key: "ends", value: .string("count")))
            body.append((key: "count", value: .number(Double(count))))
        }
        return .object(body)
    }
}

/// Thin typed face over the core calendar actions (calendar_feed,
/// calendar_entry, calendar_entry_save, calendar_entry_delete). Ownership is
/// enforced server-side; every call rides the app's session key.
public struct CalendarAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func feed(startUTC: String, endUTC: String) async throws -> CalendarFeed {
        let envelope = try await client.submitAction("calendar_feed", body: .object([
            (key: "start", value: .string(startUTC)),
            (key: "end", value: .string(endUTC)),
        ]))
        guard let feed = CalendarFeed(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return feed
    }

    public func entry(id: Int) async throws -> CalEntryDetail {
        let envelope = try await client.submitAction("calendar_entry", body: .object([
            (key: "entry_id", value: .number(Double(id))),
        ]))
        guard let detail = CalEntryDetail(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return detail
    }

    /// Create or update. `scope` + `occurrenceDate` drive the recurring
    /// series semantics exactly as on the web (this / future / all).
    @discardableResult
    public func save(
        entryID: Int?,
        occurrenceDate: String?,
        scope: String?,
        date: String,
        title: String,
        allDay: Bool,
        startTime: String?,
        endTime: String?,
        blocks: Bool,
        timezone: String,
        recurrence: CalRecurrenceInput?
    ) async throws -> Int {
        var body: [(key: String, value: JSONValue)] = [
            (key: "date", value: .string(date)),
            (key: "title", value: .string(title)),
            (key: "all_day", value: .bool(allDay)),
            (key: "blocks", value: .bool(blocks)),
            (key: "timezone", value: .string(timezone)),
        ]
        if let entryID {
            body.append((key: "entry_id", value: .number(Double(entryID))))
        }
        if let occurrenceDate {
            body.append((key: "occurrence_date", value: .string(occurrenceDate)))
        }
        if let scope {
            body.append((key: "scope", value: .string(scope)))
        }
        if !allDay {
            body.append((key: "start_time", value: .string(startTime ?? "")))
            body.append((key: "end_time", value: .string(endTime ?? "")))
        }
        if let recurrence {
            body.append((key: "recurrence", value: recurrence.jsonValue))
        }
        let envelope = try await client.submitAction("calendar_entry_save", body: .object(body))
        return envelope["data"]?["entry_id"]?.intValue ?? entryID ?? 0
    }

    public func delete(entryID: Int, scope: String?, occurrenceDate: String?) async throws {
        var body: [(key: String, value: JSONValue)] = [
            (key: "entry_id", value: .number(Double(entryID))),
        ]
        if let scope {
            body.append((key: "scope", value: .string(scope)))
        }
        if let occurrenceDate {
            body.append((key: "occurrence_date", value: .string(occurrenceDate)))
        }
        _ = try await client.submitAction("calendar_entry_delete", body: .object(body))
    }
}
