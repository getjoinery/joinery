import SwiftUI
import JoineryKit

/// One conversation, Gmail-style: subject header, message cards (older ones
/// collapsed), triage in the toolbar, and a Reply / Reply all / Forward bar
/// pinned to the bottom. Opening the thread marks it read — the same
/// explicit mark_read the web reader performs, so state stays shared.
struct ThreadDetailView: View {
    @ObservedObject var store: MailboxStore
    let summary: ThreadSummary

    @State private var messages: [MailMessage] = []
    @State private var expanded: Set<Int> = []
    @State private var loadFailure: String?
    @State private var isLoading = true
    @State private var isStarred: Bool
    @State private var compose: ComposeRequest?
    @Environment(\.dismiss) private var dismiss

    init(store: MailboxStore, summary: ThreadSummary) {
        self.store = store
        self.summary = summary
        _isStarred = State(initialValue: summary.isStarred)
    }

    var body: some View {
        Group {
            if isLoading {
                ProgressView()
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .accessibilityIdentifier("mail_thread_loading")
            } else if let loadFailure {
                VStack(spacing: 12) {
                    Text(loadFailure)
                        .multilineTextAlignment(.center)
                        .foregroundStyle(.secondary)
                    Button("Try Again") { Task { await load() } }
                        .buttonStyle(.borderedProminent)
                }
                .padding()
            } else {
                messageScroll
            }
        }
        .navigationTitle("")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar { toolbarContent }
        .safeAreaInset(edge: .bottom) { replyBar }
        .sheet(item: $compose) { request in
            ComposeSheet(api: store.api, request: request) {
                Task { await load(markRead: false) }
            }
        }
        .task { await load() }
    }

    private var messageScroll: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 0) {
                HStack(alignment: .top) {
                    Text(summary.subject.isEmpty ? "(no subject)" : summary.subject)
                        .font(.title3.weight(.semibold))
                        .accessibilityIdentifier("mail_thread_subject")
                    Spacer()
                    Button {
                        Task { await toggleStar() }
                    } label: {
                        Image(systemName: isStarred ? "star.fill" : "star")
                            .foregroundStyle(isStarred ? Color.yellow : Color.secondary)
                    }
                    .accessibilityLabel(isStarred ? "Unstar" : "Star")
                }
                .padding(.horizontal)
                .padding(.top, 12)
                .padding(.bottom, 4)

                ForEach(messages) { message in
                    MessageCardView(
                        message: message,
                        isExpanded: expanded.contains(message.id),
                        onToggle: { toggle(message.id) }
                    )
                }
            }
            .padding(.bottom, 12)
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItemGroup(placement: .topBarTrailing) {
            if store.view != .spam {
                Button {
                    Task { await act(summary.isArchived ? "unarchive" : "archive", thenDismiss: true) }
                } label: {
                    Image(systemName: "archivebox")
                }
                .accessibilityIdentifier("mail_archive")
            }
            Menu {
                Button {
                    Task { await act("mark_unread", thenDismiss: true) }
                } label: {
                    Label("Mark unread", systemImage: "envelope.badge")
                }
                if store.view == .spam {
                    Button {
                        Task { await act("mark_not_spam", thenDismiss: true) }
                    } label: {
                        Label("Not spam", systemImage: "checkmark.shield")
                    }
                } else {
                    Button {
                        Task { await act("mark_spam", thenDismiss: true) }
                    } label: {
                        Label("Report spam", systemImage: "exclamationmark.octagon")
                    }
                }
                Button(role: .destructive) {
                    Task { await act("delete", thenDismiss: true) }
                } label: {
                    Label("Delete", systemImage: "trash")
                }
            } label: {
                Image(systemName: "ellipsis")
            }
            .accessibilityIdentifier("mail_thread_menu")
        }
    }

    /// Gmail's bottom action row. Reply targets the latest message; the
    /// server resolves the sending mailbox from it and quotes it.
    @ViewBuilder
    private var replyBar: some View {
        if let source = messages.last, store.home?.canCompose == true {
            HStack(spacing: 12) {
                replyButton("Reply", icon: "arrowshape.turn.up.left", id: "mail_reply") {
                    compose = ComposeRequest(mode: .reply, source: source)
                }
                replyButton("Reply all", icon: "arrowshape.turn.up.left.2", id: "mail_reply_all") {
                    compose = ComposeRequest(mode: .replyAll, source: source)
                }
                replyButton("Forward", icon: "arrowshape.turn.up.right", id: "mail_forward") {
                    compose = ComposeRequest(mode: .forward, source: source)
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 10)
            .background(.bar)
        }
    }

    private func replyButton(_ title: String, icon: String, id: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: icon)
                .font(.subheadline.weight(.medium))
                .frame(maxWidth: .infinity)
        }
        .buttonStyle(.bordered)
        .buttonBorderShape(.capsule)
        .accessibilityIdentifier(id)
    }

    // MARK: State

    private func load(markRead: Bool = true) async {
        do {
            let thread = try await store.api.thread(key: summary.threadKey, aliasID: store.selectedAlias)
            messages = thread.messages
            // Latest message expanded, everything read collapsed; unread
            // messages always start expanded.
            var open = Set(thread.messages.filter { !$0.isRead }.map(\.id))
            if let last = thread.messages.last { open.insert(last.id) }
            expanded = open
            isLoading = false
            loadFailure = nil
            if markRead, thread.messages.contains(where: { !$0.isRead }) {
                _ = try? await store.api.threadAction("mark_read", threadKey: summary.threadKey, aliasID: store.selectedAlias)
                store.patch(summary.threadKey) { $0.unreadCount = 0 }
            }
        } catch {
            isLoading = false
            loadFailure = (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }

    private func toggle(_ id: Int) {
        if expanded.contains(id) { expanded.remove(id) } else { expanded.insert(id) }
    }

    private func toggleStar() async {
        let action = isStarred ? "unstar" : "star"
        isStarred.toggle()
        await store.perform(action, on: summary)
    }

    private func act(_ action: String, thenDismiss: Bool) async {
        await store.perform(action, on: summary)
        if thenDismiss { dismiss() }
    }
}

/// A compose invocation: what mode, quoting which message.
struct ComposeRequest: Identifiable {
    let mode: MailAPI.ComposeMode
    let source: MailMessage
    var id: String { "\(mode.rawValue)-\(source.id)" }
}
