import SwiftUI
import JoineryKit

/// Module entry point: call once at app launch to make the `calendar`
/// navigation screen available. The server flips the Calendar entry to
/// `{type: "native", screen: "calendar"}`; builds without this module keep
/// loading the web calendar via the entry's fallback URL.
public enum JoineryCalendar {
    public static func registerScreens() {
        NativeScreenRegistry.register("calendar") { context in
            AnyView(CalendarScreen(client: context.session.client, web: context.web))
        }
    }
}

/// What the editor sheet is opened for.
struct EditorRequest: Identifiable {
    enum Kind {
        case create(Date)
        case edit(CalItem)
    }
    let kind: Kind
    var id: String {
        switch kind {
        case .create(let date): return "create-\(date.timeIntervalSince1970)"
        case .edit(let item): return "edit-\(item.sourceKey)"
        }
    }
}

/// The native personal calendar: a month grid with event dots over a
/// selected-day agenda. Native entries open the entry editor; projected
/// items (events, bookings) open their web page in an authenticated webview.
public struct CalendarScreen: View {
    @StateObject private var store: CalendarStore
    private let client: APIClient
    private let web: WebSessionCoordinator?
    @State private var editorRequest: EditorRequest?

    public init(client: APIClient, web: WebSessionCoordinator?) {
        self.client = client
        self.web = web
        _store = StateObject(wrappedValue: CalendarStore(api: CalendarAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle("Calendar")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
            .sheet(item: $editorRequest) { request in
                EntryEditorSheet(api: store.api, request: request) {
                    Task { await store.reload() }
                }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("cal_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("cal_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("cal_retry")
            }
            .padding()
        case .loaded:
            VStack(spacing: 0) {
                monthHeader
                weekdayHeader
                monthGrid
                Divider()
                agenda
            }
        }
    }

    // MARK: Month grid

    private var monthHeader: some View {
        HStack {
            Button {
                Task { await store.shiftMonth(by: -1) }
            } label: {
                Image(systemName: "chevron.left")
                    .frame(width: 44, height: 44)
            }
            .accessibilityIdentifier("cal_prev_month")
            Spacer()
            Text(store.monthTitle)
                .font(.headline)
                .accessibilityIdentifier("cal_month_title")
            Spacer()
            Button {
                Task { await store.shiftMonth(by: 1) }
            } label: {
                Image(systemName: "chevron.right")
                    .frame(width: 44, height: 44)
            }
            .accessibilityIdentifier("cal_next_month")
        }
        .padding(.horizontal, 8)
    }

    private var weekdayHeader: some View {
        HStack(spacing: 0) {
            ForEach(Array(CalMonthMath.weekdaySymbols(store.calendar).enumerated()), id: \.offset) { _, symbol in
                Text(symbol)
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity)
            }
        }
        .padding(.bottom, 4)
    }

    private var monthGrid: some View {
        let cells = CalMonthMath.gridDays(for: store.displayedMonth, calendar: store.calendar)
        return LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 0), count: 7), spacing: 6) {
            ForEach(Array(cells.enumerated()), id: \.offset) { _, day in
                if let day {
                    DayCell(
                        day: day,
                        calendar: store.calendar,
                        isSelected: store.calendar.isDate(day, inSameDayAs: store.selectedDate),
                        isToday: store.calendar.isDateInToday(day),
                        dotColors: store.dotColors(on: day)
                    )
                    .contentShape(Rectangle())
                    .onTapGesture { store.selectedDate = day }
                } else {
                    Color.clear.frame(height: 44)
                }
            }
        }
        .padding(.horizontal, 6)
        .padding(.bottom, 8)
        .accessibilityIdentifier("cal_grid")
        .gesture(
            DragGesture(minimumDistance: 30)
                .onEnded { value in
                    guard abs(value.translation.width) > abs(value.translation.height) else { return }
                    Task { await store.shiftMonth(by: value.translation.width < 0 ? 1 : -1) }
                }
        )
    }

    // MARK: Agenda

    private var agenda: some View {
        List {
            Section {
                let items = store.items(on: store.selectedDate)
                if items.isEmpty {
                    Text("Nothing on this day.")
                        .foregroundStyle(.secondary)
                        .listRowSeparator(.hidden)
                        .accessibilityIdentifier("cal_agenda_empty")
                }
                ForEach(items) { item in
                    agendaRow(item)
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 12))
                }
            } header: {
                Text(selectedDayLabel)
                    .accessibilityIdentifier("cal_selected_day")
            }
        }
        .listStyle(.plain)
        .accessibilityIdentifier("cal_agenda")
        .refreshable {
            await store.reload()
        }
    }

    @ViewBuilder
    private func agendaRow(_ item: CalItem) -> some View {
        if item.isEditableEntry {
            Button {
                editorRequest = EditorRequest(kind: .edit(item))
            } label: {
                AgendaRowView(item: item)
            }
            .buttonStyle(.plain)
        } else if let url = item.url, !url.isEmpty, web != nil {
            ZStack {
                NavigationLink {
                    WebScreen(title: item.title, target: url, client: client, web: web!)
                } label: { EmptyView() }
                .opacity(0)
                AgendaRowView(item: item, showsChevron: true)
            }
        } else {
            AgendaRowView(item: item)
        }
    }

    private var selectedDayLabel: String {
        let f = DateFormatter()
        f.calendar = store.calendar
        f.setLocalizedDateFormatFromTemplate("EEEE, MMMM d")
        return f.string(from: store.selectedDate)
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Button("Today") {
                Task { await store.goToToday() }
            }
            .accessibilityIdentifier("cal_today")
        }
        ToolbarItem(placement: .topBarTrailing) {
            Button {
                editorRequest = EditorRequest(kind: .create(store.selectedDate))
            } label: {
                Image(systemName: "plus")
            }
            .accessibilityIdentifier("cal_add")
        }
    }
}

/// One month-grid day: the number, selection/today emphasis, event dots.
struct DayCell: View {
    let day: Date
    let calendar: Calendar
    let isSelected: Bool
    let isToday: Bool
    let dotColors: [String]

    var body: some View {
        VStack(spacing: 3) {
            Text("\(calendar.component(.day, from: day))")
                .font(.callout.weight(isToday || isSelected ? .semibold : .regular))
                .foregroundStyle(numberColor)
                .frame(width: 32, height: 32)
                .background {
                    if isSelected {
                        Circle().fill(Color.accentColor)
                    } else if isToday {
                        Circle().strokeBorder(Color.accentColor, lineWidth: 1.5)
                    }
                }
            HStack(spacing: 3) {
                ForEach(Array(dotColors.enumerated()), id: \.offset) { _, hex in
                    Circle()
                        .fill(Color(hex: hex))
                        .frame(width: 5, height: 5)
                }
            }
            .frame(height: 6)
        }
        .frame(height: 44)
        .frame(maxWidth: .infinity)
    }

    private var numberColor: Color {
        if isSelected { return .white }
        if isToday { return .accentColor }
        return .primary
    }
}

/// One agenda row: color bar, title, time label, and the item's kind when
/// it isn't a personal entry.
struct AgendaRowView: View {
    let item: CalItem
    var showsChevron = false

    var body: some View {
        HStack(spacing: 12) {
            RoundedRectangle(cornerRadius: 2)
                .fill(Color(hex: item.colorHex))
                .frame(width: 4, height: 36)
            VStack(alignment: .leading, spacing: 2) {
                Text(item.title.isEmpty ? "Busy" : item.title)
                    .font(.subheadline.weight(.medium))
                    .lineLimit(1)
                HStack(spacing: 6) {
                    Text(CalDisplay.timeLabel(item))
                    if item.type != "personal" {
                        Text(item.type.capitalized)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 1)
                            .background(Capsule().fill(Color(hex: item.colorHex).opacity(0.15)))
                    }
                }
                .font(.caption)
                .foregroundStyle(.secondary)
            }
            Spacer(minLength: 4)
            if showsChevron {
                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
        }
    }
}

extension Color {
    /// "#2563eb" → Color. Falls back to gray on malformed input.
    init(hex: String) {
        var value: UInt64 = 0
        let cleaned = hex.trimmingCharacters(in: CharacterSet(charactersIn: "#"))
        guard cleaned.count == 6, Scanner(string: cleaned).scanHexInt64(&value) else {
            self = .gray
            return
        }
        self.init(
            red: Double((value >> 16) & 0xFF) / 255,
            green: Double((value >> 8) & 0xFF) / 255,
            blue: Double(value & 0xFF) / 255
        )
    }
}
