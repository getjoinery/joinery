import SwiftUI
import JoineryKit

/// Status-tabbed event registration list. Rows open the session content page
/// (video/CMS content) through `context.web` — that surface is deliberately
/// web (specs/mobile_native_member_screens.md § Deliberately web). Withdraw
/// uses a confirmation alert against the existing `event_withdraw` action.
public struct EventsScreen: View {
    @StateObject private var store: EventListStore
    private let client: APIClient
    private let web: WebSessionCoordinator?
    @State private var pendingWithdraw: EventRegistration?

    public init(client: APIClient, web: WebSessionCoordinator?) {
        self.client = client
        self.web = web
        _store = StateObject(wrappedValue: EventListStore(api: MemberAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle("My Events")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { statusPicker }
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
            .confirmationDialog(
                "Withdraw from this event?", isPresented: withdrawDialogBinding, titleVisibility: .visible
            ) {
                Button("Withdraw", role: .destructive) {
                    if let reg = pendingWithdraw {
                        Task { await store.withdraw(registrantID: reg.registrantID) }
                    }
                    pendingWithdraw = nil
                }
                Button("Cancel", role: .cancel) { pendingWithdraw = nil }
            }
            .alert("Could not withdraw", isPresented: withdrawErrorBinding) {
                Button("OK") {}
            } message: {
                Text(store.withdrawError ?? "")
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("events_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("events_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("events_retry")
            }
            .padding()
        case .loaded:
            list
        }
    }

    private var list: some View {
        List {
            if store.registrations.isEmpty {
                Text("No events.")
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("events_empty")
            }
            ForEach(store.registrations) { registration in
                eventRow(registration)
                    .onAppear {
                        if registration.id == store.registrations.last?.id {
                            Task { await store.loadMore() }
                        }
                    }
            }
            if store.isLoadingMore {
                HStack { Spacer(); ProgressView(); Spacer() }
            }
        }
        .accessibilityIdentifier("events_list")
        .refreshable { await store.reload() }
    }

    @ViewBuilder
    private func eventRow(_ registration: EventRegistration) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            if let web {
                NavigationLink {
                    WebScreen(title: registration.eventName, target: registration.webURL, client: client, web: web)
                } label: {
                    eventRowLabel(registration)
                }
            } else {
                eventRowLabel(registration)
            }
            if registration.status == "active" {
                Button("Withdraw", role: .destructive) {
                    pendingWithdraw = registration
                }
                .font(.caption)
                .accessibilityIdentifier("event_withdraw_\(registration.registrantID)")
            }
        }
        .padding(.vertical, 2)
    }

    private func eventRowLabel(_ registration: EventRegistration) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(registration.eventName)
                .font(.subheadline.weight(.medium))
            HStack(spacing: 6) {
                Text(registration.status.capitalized)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                if let next = registration.nextSessionTime {
                    Text("· \(MemberDisplay.dateTimeLabel(next))")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
    }

    @ToolbarContentBuilder
    private var statusPicker: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Menu {
                Picker("Status", selection: statusBinding) {
                    ForEach(EventStatusFilter.allCases) { status in
                        Text(status.title).tag(status)
                    }
                }
            } label: {
                Image(systemName: "line.3.horizontal.decrease.circle")
            }
            .accessibilityIdentifier("events_status_menu")
        }
    }

    private var statusBinding: Binding<EventStatusFilter> {
        Binding(
            get: { store.status },
            set: { newValue in Task { await store.select(status: newValue) } }
        )
    }

    private var withdrawDialogBinding: Binding<Bool> {
        Binding(get: { pendingWithdraw != nil }, set: { if !$0 { pendingWithdraw = nil } })
    }

    private var withdrawErrorBinding: Binding<Bool> {
        Binding(get: { store.withdrawError != nil }, set: { if !$0 { store.clearWithdrawError() } })
    }
}
