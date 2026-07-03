import SwiftUI
import JoineryKit

/// Module entry point: call once at app launch to make the `mailbox`
/// navigation screen available. The server flips the Email entry to
/// `{type: "native", screen: "mailbox"}`; builds without this module keep
/// loading the web reader via the entry's fallback URL.
public enum JoineryMail {
    public static func registerScreens() {
        NativeScreenRegistry.register("mailbox") { context in
            AnyView(MailboxScreen(client: context.session.client))
        }
    }
}

/// The native mailbox: a Gmail-style thread list over the granted mailboxes,
/// with view switching (Inbox / Starred / All Mail / Spam), server-side
/// search, swipe triage, paging, and pull-to-refresh.
public struct MailboxScreen: View {
    @StateObject private var store: MailboxStore

    public init(client: APIClient) {
        _store = StateObject(wrappedValue: MailboxStore(api: MailAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle(store.title)
            .navigationBarTitleDisplayMode(.large)
            .toolbar { toolbarContent }
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
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
            if store.threads.isEmpty {
                emptyState
            }
            ForEach(store.threads) { thread in
                ZStack {
                    // Row content owns the layout; a background NavigationLink
                    // keeps the disclosure chevron out of the Gmail-style row.
                    NavigationLink {
                        ThreadDetailView(store: store, summary: thread)
                    } label: { EmptyView() }
                    .opacity(0)
                    ThreadRowView(thread: thread) {
                        Task {
                            await store.perform(thread.isStarred ? "unstar" : "star", on: thread)
                        }
                    }
                }
                .listRowInsets(EdgeInsets(top: 10, leading: 16, bottom: 10, trailing: 12))
                .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                    if store.view != .spam {
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
            } label: {
                Image(systemName: "line.3.horizontal.decrease.circle")
            }
            .accessibilityIdentifier("mail_view_menu")
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
                        Text(thread.subject.isEmpty ? "(no subject)" : thread.subject)
                            .font(.subheadline.weight(thread.hasUnread ? .semibold : .regular))
                            .foregroundStyle(.primary)
                            .lineLimit(1)
                        Text(thread.snippet)
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
