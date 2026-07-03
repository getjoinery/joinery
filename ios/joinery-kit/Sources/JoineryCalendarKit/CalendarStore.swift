import Foundation
import JoineryKit

/// State for the calendar screen: one month window of aggregated items,
/// grouped by local day, plus the selected day. All writes go through
/// CalendarAPI and reload the window — the server (shared with the web
/// calendar) is the single source of truth.
@MainActor
public final class CalendarStore: ObservableObject {
    public enum Phase {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var itemsByDay: [String: [CalItem]] = [:]
    @Published public private(set) var displayedMonth: Date
    @Published public var selectedDate: Date

    public let api: CalendarAPI
    public let calendar: Calendar
    /// Ignores stale in-flight loads after the month changes.
    private var loadGeneration = 0

    public init(api: CalendarAPI, calendar: Calendar = .current, today: Date = Date()) {
        self.api = api
        self.calendar = calendar
        self.displayedMonth = CalMonthMath.monthStart(today, calendar: calendar)
        self.selectedDate = calendar.startOfDay(for: today)
    }

    public var monthTitle: String {
        let f = DateFormatter()
        f.calendar = calendar
        f.setLocalizedDateFormatFromTemplate("MMMM yyyy")
        return f.string(from: displayedMonth)
    }

    public func items(on day: Date) -> [CalItem] {
        (itemsByDay[CalDisplay.dayKey(day, calendar: calendar)] ?? []).sorted(by: CalDisplay.agendaSort)
    }

    public func hasItems(on day: Date) -> Bool {
        !(itemsByDay[CalDisplay.dayKey(day, calendar: calendar)] ?? []).isEmpty
    }

    /// Up to `limit` dot colors for a day cell.
    public func dotColors(on day: Date, limit: Int = 3) -> [String] {
        items(on: day).prefix(limit).map(\.colorHex)
    }

    public func initialLoad() async {
        phase = .loading
        await loadWindow()
    }

    /// Re-fetch the current month window (pull-to-refresh, after saves).
    public func reload() async {
        await loadWindow()
    }

    public func show(month: Date) async {
        let anchor = CalMonthMath.monthStart(month, calendar: calendar)
        guard anchor != displayedMonth else { return }
        displayedMonth = anchor
        // Keep a selection inside the visible month.
        if !calendar.isDate(selectedDate, equalTo: anchor, toGranularity: .month) {
            selectedDate = anchor
        }
        await loadWindow()
    }

    public func shiftMonth(by delta: Int) async {
        guard let next = calendar.date(byAdding: .month, value: delta, to: displayedMonth) else { return }
        await show(month: next)
    }

    public func goToToday() async {
        let today = calendar.startOfDay(for: Date())
        selectedDate = today
        await show(month: today)
        if case .loaded = phase { return }
        await loadWindow()
    }

    private func loadWindow() async {
        loadGeneration += 1
        let generation = loadGeneration
        let window = CalMonthMath.fetchWindow(for: displayedMonth, calendar: calendar)
        do {
            let feed = try await api.feed(startUTC: window.start, endUTC: window.end)
            guard generation == loadGeneration else { return }
            var grouped: [String: [CalItem]] = [:]
            for item in feed.items {
                for key in CalDisplay.dayKeys(for: item, calendar: calendar) {
                    grouped[key, default: []].append(item)
                }
            }
            itemsByDay = grouped
            phase = .loaded
        } catch {
            guard generation == loadGeneration else { return }
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }
}
