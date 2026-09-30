import SwiftUI
import Combine
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
    @State private var folderIDs: Set<Int> = []
    @State private var showFolderPicker = false
    @Environment(\.dismiss) private var dismiss

    init(store: MailboxStore, summary: ThreadSummary) {
        self.store = store
        self.summary = summary
        _isStarred = State(initialValue: summary.isStarred)
    }

    /// The mailbox whose folder rail applies to this thread — resolved from
    /// the messages' alias so Move/Labels works in the "all mailboxes" list
    /// too (mirrors the Android `threadMailbox` derivation).
    private var threadAlias: Int? {
        messages.first(where: { $0.aliasID != nil })?.aliasID
    }

    private var threadMailbox: Mailbox? {
        guard let threadAlias else { return nil }
        return store.home?.mailboxes.first { $0.aliasID == threadAlias }
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
            ComposeSheet(api: store.api, request: request,
                         mailboxes: store.home?.mailboxes ?? [], preselectedAlias: store.selectedAlias,
                         fortress: store.fortress) {
                Task { await load(markRead: false) }
            }
        }
        .sheet(isPresented: $showFolderPicker) {
            if let threadMailbox {
                FolderPickerSheet(
                    mailbox: threadMailbox,
                    currentIDs: folderIDs,
                    onMove: { folder in
                        Task {
                            do {
                                _ = try await store.api.threadAction(
                                    "set_membership", threadKey: summary.threadKey, aliasID: store.selectedAlias,
                                    folderID: folder.id, present: true
                                )
                                showFolderPicker = false
                                await store.reload(refreshMailboxes: true)
                                dismiss()
                            } catch {
                                showFolderPicker = false
                            }
                        }
                    },
                    onToggle: { folder, present in
                        Task {
                            do {
                                _ = try await store.api.threadAction(
                                    "set_membership", threadKey: summary.threadKey, aliasID: store.selectedAlias,
                                    folderID: folder.id, present: present
                                )
                                folderIDs = present ? folderIDs.union([folder.id]) : folderIDs.subtracting([folder.id])
                                await store.reload(refreshMailboxes: true)
                            } catch {
                                // Leave the sheet state as-is; the toggle reflects folderIDs.
                            }
                        }
                    },
                    onCreate: { name in
                        Task {
                            if let folder = try? await store.api.createFolder(
                                name: name, threadKey: summary.threadKey, aliasID: threadAlias
                            ) {
                                folderIDs.insert(folder.id)
                                await store.reload(refreshMailboxes: true)
                                if threadMailbox.foldersExclusive {
                                    showFolderPicker = false
                                    dismiss()
                                }
                            }
                        }
                    }
                )
            }
        }
        .task { await load() }
        .onReceive(store.fortress?.$lockEpoch.dropFirst().eraseToAnyPublisher()
                   ?? Empty<Int, Never>().eraseToAnyPublisher()) { _ in
            // A lock drops what was opened: the thread shows placeholders again.
            Task { await load(markRead: false) }
        }
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

                if messages.contains(where: { $0.placeholder == .locked }), let fortress = store.fortress {
                    // R4: after a lock, one Unlock right here, as in the list.
                    Button {
                        Task {
                            do {
                                try await fortress.unlock()
                                await load(markRead: false)
                            } catch {
                                loadFailure = nil
                            }
                        }
                    } label: {
                        Label("Unlock to read", systemImage: "lock.open")
                    }
                    .buttonStyle(.borderedProminent)
                    .padding(.horizontal)
                    .padding(.vertical, 6)
                    .accessibilityIdentifier("mail_thread_unlock")
                }
                ForEach(messages) { message in
                    MessageCardView(
                        message: message,
                        isExpanded: expanded.contains(message.id),
                        onToggle: { toggle(message.id) },
                        client: store.api.client,
                        fortress: store.fortress
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
            if let threadMailbox, !threadMailbox.folders.isEmpty {
                Button {
                    showFolderPicker = true
                } label: {
                    Image(systemName: threadMailbox.foldersExclusive ? "folder" : "tag")
                }
                .accessibilityIdentifier("mail_folders")
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
        // Reply and Forward only on a message this phone opened (never on a placeholder).
        if let source = messages.last, store.home?.canCompose == true, source.canRespond {
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
            var thread = try await store.api.thread(key: summary.threadKey, aliasID: store.selectedAlias)
            if let fortress = store.fortress, thread.messages.contains(where: { $0.sealed != nil }) {
                thread = await fortress.openThread(thread)
            }
            messages = thread.messages
            folderIDs = Set(thread.folderIDs)
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

/// A compose invocation: what mode, and (for reply/reply-all/forward) which
/// message it responds to. New-message compose has no source to quote — the
/// sending identity comes from a From picker over the granted mailboxes
/// instead (specs/implemented/inbound_email_new_message_compose.md).
struct ComposeRequest: Identifiable {
    let mode: MailAPI.ComposeMode
    let source: MailMessage?
    /// Reopening a saved draft: its id, and the mailbox and source it names.
    var draftID: Int? = nil
    var draftAlias: Int? = nil
    var draftSourceID: Int? = nil
    var id: String {
        if let draftID { return "draft-\(draftID)" }
        return source.map { "\(mode.rawValue)-\($0.id)" } ?? mode.rawValue
    }

    init(mode: MailAPI.ComposeMode, source: MailMessage) {
        self.mode = mode
        self.source = source
    }

    /// New-message compose: no source to reply to or quote.
    static let new = ComposeRequest(mode: .new)

    /// A saved draft, reopened (it restores its own mode and recipients).
    static func draft(_ id: Int, aliasID: Int?) -> ComposeRequest {
        var r = ComposeRequest(mode: .new)
        r.draftID = id
        r.draftAlias = aliasID
        return r
    }

    private init(mode: MailAPI.ComposeMode) {
        self.mode = mode
        self.source = nil
    }
}
