import SwiftUI
import JoineryKit

/// Create / edit a native calendar entry: title, date, all-day or timed,
/// availability blocking, and the full recurrence surface (frequency,
/// interval, weekly days, monthly pattern, series end). Recurring edits and
/// deletes ask for scope (this / future / all) exactly like the web form.
struct EntryEditorSheet: View {
    let api: CalendarAPI
    let request: EditorRequest
    let onSaved: () -> Void

    @Environment(\.dismiss) private var dismiss

    // Load state for edits (the entry detail is fetched on open).
    @State private var isLoadingDetail = false
    @State private var loadError: String?

    // Entry fields
    @State private var title = ""
    @State private var date = Date()
    @State private var allDay = true
    @State private var startTime = Date()
    @State private var endTime = Date()
    @State private var blocks = true

    // Recurrence fields
    @State private var repeats = false
    @State private var frequency = "weekly"
    @State private var interval = 1
    @State private var weeklyDays: Set<Int> = []
    @State private var monthlyByWeekday = false
    @State private var weekOfMonth = 1
    @State private var monthlyDOW = 1
    @State private var endsMode = "never"       // never | date | count
    @State private var endsDate = Date()
    @State private var endsCount = 10

    // Edit context
    @State private var entryID: Int?
    @State private var occurrenceDate: String?  // set → occurrence edit, scope asked
    @State private var isRecurringParent = false
    @State private var entryTimezone: String?

    // Save/delete state
    @State private var isSaving = false
    @State private var saveError: String?
    @State private var showEditScopeDialog = false
    @State private var showDeleteScopeDialog = false
    @State private var showDeleteConfirm = false

    private let calendar = Calendar.current

    var body: some View {
        NavigationStack {
            Group {
                if isLoadingDetail {
                    ProgressView()
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if let loadError {
                    VStack(spacing: 12) {
                        Text(loadError).foregroundStyle(.secondary)
                        Button("Close") { dismiss() }
                    }
                    .padding()
                } else {
                    form
                }
            }
            .navigationTitle(entryID == nil ? "New Entry" : "Edit Entry")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                        .accessibilityIdentifier("cal_entry_cancel")
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { saveTapped() }
                        .disabled(isSaving || isLoadingDetail)
                        .accessibilityIdentifier("cal_entry_save")
                }
            }
            .task { await loadIfNeeded() }
            .confirmationDialog("Save recurring entry", isPresented: $showEditScopeDialog, titleVisibility: .visible) {
                Button("This occurrence only") { Task { await save(scope: "this") } }
                Button("This and future occurrences") { Task { await save(scope: "future") } }
                Button("All occurrences") { Task { await save(scope: "all") } }
                Button("Cancel", role: .cancel) {}
            }
            .confirmationDialog("Delete recurring entry", isPresented: $showDeleteScopeDialog, titleVisibility: .visible) {
                if occurrenceDate != nil {
                    Button("This occurrence only", role: .destructive) { Task { await performDelete(scope: "this") } }
                    Button("This and future occurrences", role: .destructive) { Task { await performDelete(scope: "future") } }
                }
                Button("All occurrences", role: .destructive) { Task { await performDelete(scope: "all") } }
                Button("Cancel", role: .cancel) {}
            }
            .alert("Delete this entry?", isPresented: $showDeleteConfirm) {
                Button("Delete", role: .destructive) { Task { await performDelete(scope: nil) } }
                Button("Cancel", role: .cancel) {}
            }
        }
    }

    // MARK: Form

    private var form: some View {
        Form {
            if let saveError {
                Section {
                    Text(saveError)
                        .foregroundStyle(.red)
                        .accessibilityIdentifier("cal_entry_error")
                }
            }
            if let occurrenceDate {
                Section {
                    Text("Editing the \(occurrenceDate) occurrence of a repeating entry.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
            }
            Section {
                TextField("Title", text: $title)
                    .accessibilityIdentifier("cal_entry_title")
                DatePicker("Date", selection: $date, displayedComponents: .date)
                    .accessibilityIdentifier("cal_entry_date")
                Toggle("All day", isOn: $allDay)
                    .accessibilityIdentifier("cal_entry_allday")
                if !allDay {
                    DatePicker("Starts", selection: $startTime, displayedComponents: .hourAndMinute)
                        .accessibilityIdentifier("cal_entry_start")
                    DatePicker("Ends", selection: $endTime, displayedComponents: .hourAndMinute)
                        .accessibilityIdentifier("cal_entry_end")
                }
            }
            Section {
                Toggle("Block this time", isOn: $blocks)
                    .accessibilityIdentifier("cal_entry_blocks")
            } footer: {
                Text("Removes this time from your booking availability.")
            }
            recurrenceSection
            if entryID != nil {
                Section {
                    Button("Delete Entry", role: .destructive) { deleteTapped() }
                        .disabled(isSaving)
                        .accessibilityIdentifier("cal_entry_delete")
                }
            }
        }
    }

    /// Turning Repeats on pre-selects the entry date's weekday — done in the
    /// binding (synchronous), because modifiers like onChange attached to a
    /// Form Section are not reliably applied.
    private var repeatsBinding: Binding<Bool> {
        Binding(
            get: { repeats },
            set: { on in
                repeats = on
                if on && weeklyDays.isEmpty {
                    weeklyDays = [calendar.component(.weekday, from: date) - 1]
                }
            }
        )
    }

    @ViewBuilder
    private var recurrenceSection: some View {
        Section {
            Toggle("Repeats", isOn: repeatsBinding)
                .accessibilityIdentifier("cal_entry_repeats")
            if repeats {
                Picker("Frequency", selection: $frequency) {
                    Text("Daily").tag("daily")
                    Text("Weekly").tag("weekly")
                    Text("Monthly").tag("monthly")
                    Text("Yearly").tag("yearly")
                }
                Stepper("Every \(interval) \(intervalUnit)", value: $interval, in: 1...99)
                if frequency == "weekly" {
                    weekdayChips
                }
                if frequency == "monthly" {
                    Toggle("On a specific weekday", isOn: $monthlyByWeekday)
                    if monthlyByWeekday {
                        Picker("Week", selection: $weekOfMonth) {
                            Text("First").tag(1)
                            Text("Second").tag(2)
                            Text("Third").tag(3)
                            Text("Fourth").tag(4)
                            Text("Last").tag(-1)
                        }
                        Picker("Weekday", selection: $monthlyDOW) {
                            ForEach(0..<7, id: \.self) { dow in
                                Text(calendar.weekdaySymbols[dow]).tag(dow)
                            }
                        }
                    }
                }
                Picker("Ends", selection: $endsMode) {
                    Text("Never").tag("never")
                    Text("On date").tag("date")
                    Text("After a number of times").tag("count")
                }
                if endsMode == "date" {
                    DatePicker("End date", selection: $endsDate, displayedComponents: .date)
                }
                if endsMode == "count" {
                    Stepper("After \(endsCount) occurrences", value: $endsCount, in: 1...999)
                }
            }
        } header: {
            Text("Repeat")
        }
    }

    private var weekdayChips: some View {
        HStack(spacing: 6) {
            ForEach(0..<7, id: \.self) { dow in
                let selected = weeklyDays.contains(dow)
                Button {
                    if selected { weeklyDays.remove(dow) } else { weeklyDays.insert(dow) }
                } label: {
                    Text(calendar.veryShortWeekdaySymbols[dow])
                        .font(.footnote.weight(.semibold))
                        .frame(width: 34, height: 34)
                        .background(Circle().fill(selected ? Color.accentColor : Color(.systemGray5)))
                        .foregroundStyle(selected ? .white : .primary)
                }
                .buttonStyle(.plain)
            }
        }
        .frame(maxWidth: .infinity)
    }

    private var intervalUnit: String {
        let unit: String
        switch frequency {
        case "daily": unit = "day"
        case "weekly": unit = "week"
        case "monthly": unit = "month"
        default: unit = "year"
        }
        return interval == 1 ? unit : unit + "s"
    }

    // MARK: Load

    private func loadIfNeeded() async {
        guard case .edit(let item) = request.kind, entryID == nil, let id = item.entryID else {
            if case .create(let day) = request.kind {
                date = day
                startTime = defaultTime(hour: 9)
                endTime = defaultTime(hour: 10)
            }
            return
        }
        isLoadingDetail = true
        defer { isLoadingDetail = false }
        do {
            let detail = try await api.entry(id: id)
            entryID = detail.entryID
            occurrenceDate = item.occurrenceDate
            isRecurringParent = detail.isRecurringParent
            entryTimezone = detail.timezone.isEmpty ? nil : detail.timezone
            title = detail.title
            allDay = detail.allDay
            blocks = detail.blocksAvailability
            // For a single occurrence the date shown is the occurrence's day;
            // times come from the series' wall clock.
            let dateString = item.occurrenceDate ?? detail.date
            date = Self.dayFormatter.date(from: dateString) ?? Date()
            startTime = time(on: date, hms: detail.startTime) ?? defaultTime(hour: 9)
            endTime = time(on: date, hms: detail.endTime) ?? defaultTime(hour: 10)
            if let type = detail.recurrence.type {
                repeats = true
                frequency = type
                interval = detail.recurrence.interval
                if type == "weekly" {
                    weeklyDays = Set(detail.recurrence.daysOfWeek)
                } else if type == "monthly", let week = detail.recurrence.weekOfMonth {
                    monthlyByWeekday = true
                    weekOfMonth = week
                    monthlyDOW = detail.recurrence.daysOfWeek.first ?? 1
                }
                if let end = detail.recurrence.endDate, let endAsDate = Self.dayFormatter.date(from: end) {
                    endsMode = "date"
                    endsDate = endAsDate
                }
            }
        } catch {
            loadError = (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }

    // MARK: Save / delete

    private func saveTapped() {
        saveError = nil
        if !allDay, formatTime(endTime) <= formatTime(startTime) {
            saveError = "The end time must be after the start time."
            return
        }
        if entryID != nil && (occurrenceDate != nil || isRecurringParent) {
            showEditScopeDialog = true
        } else {
            Task { await save(scope: nil) }
        }
    }

    private func deleteTapped() {
        if occurrenceDate != nil || isRecurringParent {
            showDeleteScopeDialog = true
        } else {
            showDeleteConfirm = true
        }
    }

    private func save(scope: String?) async {
        isSaving = true
        defer { isSaving = false }
        do {
            _ = try await api.save(
                entryID: entryID,
                occurrenceDate: occurrenceDate,
                scope: scope,
                date: Self.dayFormatter.string(from: date),
                title: title.trimmingCharacters(in: .whitespaces),
                allDay: allDay,
                startTime: formatTime(startTime),
                endTime: formatTime(endTime),
                blocks: blocks,
                timezone: entryTimezone ?? TimeZone.current.identifier,
                recurrence: recurrenceInput(scope: scope)
            )
            onSaved()
            dismiss()
        } catch {
            saveError = (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }

    private func performDelete(scope: String?) async {
        guard let entryID else { return }
        isSaving = true
        defer { isSaving = false }
        do {
            try await api.delete(entryID: entryID, scope: scope, occurrenceDate: occurrenceDate)
            onSaved()
            dismiss()
        } catch {
            saveError = (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }

    private func recurrenceInput(scope: String?) -> CalRecurrenceInput? {
        // A 'this occurrence only' save produces a standalone replacement —
        // recurrence settings stay on the series.
        guard repeats, scope != "this" else { return nil }
        var days: [Int] = []
        var week: Int?
        if frequency == "weekly" {
            days = weeklyDays.sorted()
        } else if frequency == "monthly" && monthlyByWeekday {
            days = [monthlyDOW]
            week = weekOfMonth
        }
        let ends: CalRecurrenceInput.Ends
        switch endsMode {
        case "date": ends = .onDate(Self.dayFormatter.string(from: endsDate))
        case "count": ends = .afterCount(endsCount)
        default: ends = .never
        }
        return CalRecurrenceInput(type: frequency, interval: interval, daysOfWeek: days, weekOfMonth: week, ends: ends)
    }

    // MARK: Helpers

    private static let dayFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    private func formatTime(_ value: Date) -> String {
        let c = calendar.dateComponents([.hour, .minute], from: value)
        return String(format: "%02d:%02d", c.hour ?? 0, c.minute ?? 0)
    }

    private func time(on day: Date, hms: String) -> Date? {
        let parts = hms.split(separator: ":").compactMap { Int($0) }
        guard parts.count >= 2 else { return nil }
        return calendar.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: day)
    }

    private func defaultTime(hour: Int) -> Date {
        calendar.date(bySettingHour: hour, minute: 0, second: 0, of: date) ?? date
    }
}
