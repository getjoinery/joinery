import SwiftUI
import JoineryKit

/// The conversation inbox: a paginated list with mute/unmute/delete swipe
/// actions, opening into a threaded conversation. Closest reference:
/// JoineryAIChatKit's list/thread split.
public struct ConversationsScreen: View {
    @StateObject private var store: ConversationListStore
    private let client: APIClient
    @State private var hasAppearedBefore = false

    public init(client: APIClient) {
        self.client = client
        _store = StateObject(wrappedValue: ConversationListStore(api: ConversationAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle("Messages")
            .navigationBarTitleDisplayMode(.large)
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
            .onAppear {
                // Re-read on return from a thread so a just-read conversation's
                // unread badge and bumped preview show up; skip the very first
                // appear (the .task above owns the initial load).
                if hasAppearedBefore { Task { await store.reload() } }
                hasAppearedBefore = true
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("conversations_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("conversations_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("conversations_retry")
            }
            .padding()
        case .loaded:
            list
        }
    }

    private var list: some View {
        List {
            if store.conversations.isEmpty {
                Text("No conversations yet.")
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("conversations_empty")
            }
            ForEach(store.conversations) { conversation in
                NavigationLink {
                    ConversationThreadView(
                        client: client,
                        origin: .conversation(id: conversation.conversationID, otherDisplayName: conversation.otherDisplayName)
                    )
                } label: {
                    ConversationRowView(conversation: conversation)
                }
                .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                    Button(role: .destructive) {
                        Task { await store.delete(conversation) }
                    } label: {
                        Label("Delete", systemImage: "trash")
                    }
                }
                .swipeActions(edge: .leading) {
                    Button {
                        Task { await store.toggleMute(conversation) }
                    } label: {
                        Label(conversation.muted ? "Unmute" : "Mute",
                              systemImage: conversation.muted ? "bell" : "bell.slash")
                    }
                    .tint(.orange)
                }
                .onAppear {
                    if conversation.id == store.conversations.last?.id {
                        Task { await store.loadMore() }
                    }
                }
            }
            if store.isLoadingMore {
                HStack { Spacer(); ProgressView(); Spacer() }
            }
        }
        .listStyle(.plain)
        .accessibilityIdentifier("conversations_list")
        .refreshable { await store.reload() }
    }
}

/// One inbox row: bold when unread, a muted glyph when muted, preview text.
struct ConversationRowView: View {
    let conversation: ConversationRow

    var body: some View {
        HStack(spacing: 10) {
            if conversation.unread {
                Circle().fill(Color.accentColor).frame(width: 8, height: 8)
            } else {
                Circle().fill(Color.clear).frame(width: 8, height: 8)
            }
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Text(conversation.otherDisplayName)
                        .font(.subheadline.weight(conversation.unread ? .semibold : .regular))
                    if conversation.muted {
                        Image(systemName: "bell.slash.fill")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    Text(MemberDisplay.listStamp(conversation.lastMessageTime))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Text(conversation.preview)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
        }
        .padding(.vertical, 2)
    }
}
