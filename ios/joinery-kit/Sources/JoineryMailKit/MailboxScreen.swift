import SwiftUI
import JoineryKit

/// Module entry point: call once at app launch to make the `mailbox`
/// navigation screen available. The server flips the Email entry to
/// `{type: "native", screen: "mailbox"}`; builds without this module keep
/// loading the web reader via the entry's fallback URL.
public enum JoineryMail {
    public static func registerScreens() {
        NativeScreenRegistry.register("mailbox") { context in
            AnyView(MailboxScreen(session: context.session, user: context.user))
        }
    }
}

/// Everything one signed-in mailbox screen holds: the list, the end-to-end
/// half, the device-side work, the phone's search, and the AI settings.
@MainActor
final class MailScreenModel: ObservableObject {
    let store: MailboxStore
    let fortress: FortressSession
    let work: FortressWork
    let ai: DeviceAISettings

    init(session: SessionController, user: UserSummary) {
        let api = MailAPI(client: session.client)
        let fortress = FortressSession(session: session)
        fortress.vault.companions = [DeviceAISettings.keyName]
        let search = DeviceSearch(api: api, fortress: fortress, userID: user.userId)
        ai = DeviceAISettings(deviceKeys: session.deviceKeys, userID: user.userId)
        store = MailboxStore(api: api, fortress: fortress, deviceSearch: search)
        work = FortressWork(fortress: fortress, api: api, ai: ai)
        self.fortress = fortress
        session.addSignOutHook {
            OpenedFiles.removeAll()
            MailPolling.reset()
            UserDefaults.standard.removeObject(forKey: FortressWork.skipKey)
            UserDefaults.standard.removeObject(forKey: FortressWork.parsingKey)
            // The sealed index stays; its key goes with the mail key and it
            // reopens after the next enrollment (§ R9).
        }
        MailPolling.schedule()
    }

    private var lastRefreshCheck = Date.distantPast

    /// A list refresh (pull, return, a reload): notice a retired key and new
    /// relay mail at most once per 10 s — the probe, then the device work.
    func refreshed() async {
        guard Date().timeIntervalSince(lastRefreshCheck) >= 10 else { return }
        lastRefreshCheck = Date()
        let before = fortress.vault.status
        await fortress.vault.checkStillHeld()
        if before != fortress.vault.status { await store.reload(refreshMailboxes: true) }
        startWork()
    }

    /// With the key held and the mailbox on screen: the device's share of the
    /// work (parse, rules, pin check, AI) and the search index's first build.
    func startWork() {
        guard fortress.isOpen, let boxes = store.home?.mailboxes, boxes.contains(where: \.isFortress) else { return }
        store.deviceSearch?.warm()
        work.run(mailboxes: boxes) { [weak self] in await self?.store.reload(refreshMailboxes: true) }
    }
}

/// The native mailbox: a Gmail-style thread list over the granted mailboxes,
/// with view switching (Inbox / Starred / All Mail / Spam / Drafts), search
/// (server-side, and on the phone's own index for end-to-end mail), swipe
/// triage, paging, and pull-to-refresh. End-to-end mailboxes open here with
/// the mail key this phone holds (specs/fortress_mobile_apps.md).
public struct MailboxScreen: View {
    @StateObject private var model: MailScreenModel
    @State private var newCompose: ComposeRequest?
    @State private var showEnroll = false
    @State private var showSettings = false
    @State private var unlockError: String?
    @Environment(\.scenePhase) private var scenePhase

    private var store: MailboxStore { model.store }

    public init(session: SessionController, user: UserSummary) {
        _model = StateObject(wrappedValue: MailScreenModel(session: session, user: user))
    }

    public var body: some View {
        MailboxScreenBody(model: model, store: model.store, vault: model.fortress.vault, work: model.work,
                          newCompose: $newCompose, showEnroll: $showEnroll, showSettings: $showSettings,
                          unlockError: $unlockError)
            .task {
                OpenedFiles.removeAll()
                if case .loading = model.store.phase { await model.store.initialLoad() }
                await model.fortress.vault.checkStillHeld()
                model.startWork()
            }
            .onChange(of: scenePhase) { phase in
                guard phase == .active else { return }
                // B6: a key retired while away is noticed here, and wiped.
                Task { await model.refreshed() }
            }
            .onReceive(model.fortress.$lockEpoch.dropFirst()) { _ in
                Task { await model.store.reopenRows() }
            }
            .sheet(item: $newCompose) { request in
                ComposeSheet(api: model.store.api, request: request,
                             mailboxes: model.store.home?.mailboxes ?? [], preselectedAlias: model.store.selectedAlias,
                             fortress: model.fortress) {
                    Task { await model.store.reload(refreshMailboxes: true) }
                }
            }
            .sheet(isPresented: $showEnroll) {
                EnrollSheet(vault: model.fortress.vault) {
                    Task {
                        await model.store.reopenRows()
                        model.startWork()
                    }
                }
            }
            .sheet(isPresented: $showSettings) {
                MailSettingsView(model: model)
            }
    }
}

/// The screen's body, observing the list, the vault and the device work.
struct MailboxScreenBody: View {
    let model: MailScreenModel
    @ObservedObject var store: MailboxStore
    @ObservedObject var vault: DeviceVault
    @ObservedObject var work: FortressWork
    @Binding var newCompose: ComposeRequest?
    @Binding var showEnroll: Bool
    @Binding var showSettings: Bool
    @Binding var unlockError: String?

    var body: some View {
        content
            .navigationTitle(store.title)
            .navigationBarTitleDisplayMode(.large)
            .toolbar { toolbarContent }
            .alert(item: Binding(get: { work.relayAlarm }, set: { _ in })) { alarm in
                Alert(title: Text("Mail arriving at your relay is not being sealed to this phone's key"),
                      message: Text("\(alarm.address): \(alarm.reason)\n\nTrusted relay: \(alarm.expected)\nAnswering now: \(alarm.reported)"),
                      dismissButton: .default(Text("Close")))
            }
    }

    private var hasFortress: Bool { store.home?.mailboxes.contains(where: \.isFortress) ?? false }

    /// R10: one banner for the end-to-end state, one button.
    @ViewBuilder
    private var fortressBanner: some View {
        if hasFortress {
            switch vault.status {
            case .notEnrolled:
                banner(vault.retired
                       ? "Your mail key changed. Open your mail on a computer once to hand this phone the new key."
                       : "This mailbox is end-to-end encrypted. Hand this phone the key from a computer where your mail is open.",
                       button: "Enroll", id: "mail_fortress_enroll") { showEnroll = true }
            case .locked:
                banner(unlockError ?? "End-to-end encrypted mail is locked on this phone.",
                       button: "Unlock", id: "mail_fortress_unlock") {
                    Task {
                        do {
                            try await model.fortress.unlock()
                            unlockError = nil
                            await store.reopenRows()
                            model.startWork()
                        } catch DeviceKeyStore.Failure.cancelled {
                        } catch {
                            unlockError = (error as? LocalizedError)?.errorDescription ?? error.localizedDescription
                        }
                    }
                }
            case .open:
                ForEach([work.pendingLine, work.aiLine, store.searchNote, store.deviceSearch?.status.line]
                    .compactMap { $0 }, id: \.self) { line in
                    Text(line).font(.footnote).foregroundStyle(.secondary)
                        .listRowSeparator(.hidden)
                        .accessibilityIdentifier("mail_fortress_status")
                }
            }
        }
    }

    private func banner(_ text: String, button: String, id: String, action: @escaping () -> Void) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Label(text, systemImage: "lock.shield")
                .font(.subheadline)
                .accessibilityIdentifier("mail_fortress_banner")
            Button(button, action: action)
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier(id)
        }
        .padding(.vertical, 6)
        .listRowSeparator(.hidden)
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("mail_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("mail_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("mail_retry")
            }
            .padding()
        case .loaded:
            threadList
        }
    }

    private var threadList: some View {
        List {
            fortressBanner
            if store.threads.isEmpty {
                emptyState
            }
            ForEach(store.threads) { thread in
                ZStack {
                    // Row content owns the layout; a background NavigationLink
                    // keeps the disclosure chevron out of the Gmail-style row.
                    if store.view == .drafts {
                        Button { newCompose = .draft(thread.latestID, aliasID: store.selectedAlias) } label: { EmptyView() }
                            .opacity(0)
                    } else {
                        NavigationLink {
                            ThreadDetailView(store: store, summary: thread)
                        } label: { EmptyView() }
                        .opacity(0)
                    }
                    ThreadRowView(thread: thread) {
                        Task {
                            await store.perform(thread.isStarred ? "unstar" : "star", on: thread)
                        }
                    }
                    .contentShape(Rectangle())
                    .onTapGesture {
                        if store.view == .drafts { newCompose = .draft(thread.latestID, aliasID: store.selectedAlias) }
                    }
                    .allowsHitTesting(store.view == .drafts)
                }
                .listRowInsets(EdgeInsets(top: 10, leading: 16, bottom: 10, trailing: 12))
                .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                    if store.view == .drafts {
                        Button(role: .destructive) {
                            Task {
                                _ = try? await store.api.action("mailbox/draft_delete",
                                                                [(key: "draft_id", value: .number(Double(thread.latestID)))])
                                store.remove(thread.threadKey)
                            }
                        } label: { Label("Discard", systemImage: "trash") }
                    } else if store.view != .spam {
                        Button {
                            Task { await store.perform(thread.isArchived ? "unarchive" : "archive", on: thread) }
                        } label: {
                            Label(thread.isArchived ? "Unarchive" : "Archive",
                                  systemImage: thread.isArchived ? "tray.and.arrow.up" : "archivebox")
                        }
                        .tint(.green)
                    }
                }
                .swipeActions(edge: .leading, allowsFullSwipe: true) {
                    Button {
                        Task {
                            await store.perform(thread.hasUnread ? "mark_read" : "mark_unread", on: thread)
                        }
                    } label: {
                        Label(thread.hasUnread ? "Read" : "Unread",
                              systemImage: thread.hasUnread ? "envelope.open" : "envelope.badge")
                    }
                    .tint(.blue)
                }
                .onAppear {
                    if thread.threadKey == store.threads.last?.threadKey {
                        Task { await store.loadMore() }
                    }
                }
            }
            if store.isLoadingMore {
                HStack { Spacer(); ProgressView(); Spacer() }
            }
        }
        .listStyle(.plain)
        .accessibilityIdentifier("mail_list")
        .refreshable {
            await store.reload(refreshMailboxes: true)
            await model.refreshed()
        }
        .searchable(text: $store.searchText, placement: .navigationBarDrawer(displayMode: .automatic),
                    prompt: "Search mail")
        .onSubmit(of: .search) {
            Task { await store.submitSearch() }
        }
        .onChange(of: store.searchText) { text in
            if text.isEmpty { Task { await store.clearSearch() } }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 8) {
            Image(systemName: store.view.systemImage)
                .font(.largeTitle)
                .foregroundStyle(.secondary)
            Text(emptyText)
                .foregroundStyle(.secondary)
                .accessibilityIdentifier("mail_empty")
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
        .listRowSeparator(.hidden)
    }

    private var emptyText: String {
        if !store.activeQuery.isEmpty { return "No results for “\(store.activeQuery)”" }
        if (store.home?.mailboxes.isEmpty ?? true) { return "No mailbox has been granted to this account." }
        switch store.view {
        case .inbox: return "Inbox zero — nothing here."
        case .starred: return "No starred conversations."
        case .all: return "No mail yet."
        case .spam: return "No spam. Nice."
        case .drafts: return "No drafts."
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Menu {
                Picker("View", selection: viewBinding) {
                    ForEach(MailView.allCases) { view in
                        Label(view.title, systemImage: view.systemImage).tag(view)
                    }
                }
                if let mailboxes = store.home?.mailboxes, mailboxes.count > 1 {
                    Picker("Mailbox", selection: aliasBinding) {
                        Text("All mailboxes").tag(Int?.none)
                        ForEach(mailboxes) { box in
                            Text(box.address).tag(Int?.some(box.aliasID))
                        }
                    }
                }
                Button {
                    showSettings = true
                } label: {
                    Label("Mail settings", systemImage: "gearshape")
                }
            } label: {
                Image(systemName: "line.3.horizontal.decrease.circle")
            }
            .accessibilityIdentifier("mail_view_menu")
        }
        ToolbarItem(placement: .topBarTrailing) {
            Button {
                newCompose = .new
            } label: {
                Image(systemName: "square.and.pencil")
            }
            .accessibilityIdentifier("mail_new_message")
            .disabled(store.home?.canCompose != true)
        }
    }

    private var viewBinding: Binding<MailView> {
        Binding(
            get: { store.view },
            set: { newValue in Task { await store.select(view: newValue) } }
        )
    }

    private var aliasBinding: Binding<Int?> {
        Binding(
            get: { store.selectedAlias },
            set: { newValue in Task { await store.select(alias: newValue) } }
        )
    }
}

/// One Gmail-style list row: colored initial avatar, sender + date line,
/// subject line, snippet + star line. Unread rows render bold.
struct ThreadRowView: View {
    let thread: ThreadSummary
    let onStarTap: () -> Void

    private static let palette: [Color] = [
        Color(red: 0.86, green: 0.20, blue: 0.21),
        Color(red: 0.96, green: 0.49, blue: 0.00),
        Color(red: 0.98, green: 0.74, blue: 0.02),
        Color(red: 0.20, green: 0.66, blue: 0.33),
        Color(red: 0.01, green: 0.62, blue: 0.64),
        Color(red: 0.26, green: 0.52, blue: 0.96),
        Color(red: 0.40, green: 0.31, blue: 0.64),
        Color(red: 0.76, green: 0.18, blue: 0.47),
    ]

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            avatar
            VStack(alignment: .leading, spacing: 2) {
                HStack(alignment: .firstTextBaseline) {
                    Text(senderLine)
                        .font(.subheadline.weight(thread.hasUnread ? .semibold : .regular))
                        .foregroundStyle(thread.hasUnread ? .primary : .secondary)
                        .lineLimit(1)
                    Spacer(minLength: 8)
                    Text(MailDisplay.listStamp(thread.latestTime))
                        .font(.caption)
                        .foregroundStyle(thread.hasUnread ? Color.accentColor : Color.secondary)
                        .fontWeight(thread.hasUnread ? .semibold : .regular)
                }
                HStack(alignment: .top, spacing: 8) {
                    VStack(alignment: .leading, spacing: 1) {
                        // An end-to-end row this phone cannot open says so (B1),
                        // never "(no subject)".
                        Text(thread.placeholder != nil ? "End-to-end encrypted"
                             : (thread.subject.isEmpty ? "(no subject)" : thread.subject))
                            .font(.subheadline.weight(thread.hasUnread ? .semibold : .regular))
                            .foregroundStyle(.primary)
                            .lineLimit(1)
                        Text(thread.aiSummary.isEmpty ? thread.snippet : thread.aiSummary)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                    Spacer(minLength: 4)
                    Button(action: onStarTap) {
                        Image(systemName: thread.isStarred ? "star.fill" : "star")
                            .foregroundStyle(thread.isStarred ? Color.yellow : Color.secondary)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(thread.isStarred ? "Unstar" : "Star")
                }
            }
        }
    }

    private var senderLine: String {
        let name = MailDisplay.senderName(thread.sender)
        return thread.messageCount > 1 ? "\(name) \(thread.messageCount)" : name
    }

    private var avatar: some View {
        let index = MailDisplay.avatarColorIndex(thread.sender, paletteSize: Self.palette.count)
        let initial = MailDisplay.senderName(thread.sender).prefix(1).uppercased()
        return ZStack {
            Circle()
                .fill(Self.palette[index])
                .frame(width: 40, height: 40)
            Text(initial)
                .font(.headline)
                .foregroundStyle(.white)
        }
        .overlay(alignment: .topTrailing) {
            if thread.hasUnread {
                Circle()
                    .fill(Color.accentColor)
                    .frame(width: 10, height: 10)
                    .overlay(Circle().stroke(Color(uiColor: .systemBackground), lineWidth: 2))
            }
        }
    }
}
