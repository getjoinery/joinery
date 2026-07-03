import SwiftUI
import JoineryKit

/// Module entry point: call once at app launch to make the `ai_chat` navigation
/// screen available. The server flips the AI Chat entry to
/// `{type: "native", screen: "ai_chat"}`; builds without this module keep
/// loading the web chat via the entry's fallback URL.
public enum JoineryAIChat {
    public static func registerScreens() {
        NativeScreenRegistry.register("ai_chat") { context in
            AnyView(ChatScreen(client: context.session.client))
        }
    }
}

/// The native AI chat: a list of the member's conversations with search, pin,
/// rename, and delete, opening into a threaded chat with the assistant.
public struct ChatScreen: View {
    @StateObject private var store: ChatListStore
    private let api: ChatAPI

    @State private var startNewChat = false
    @State private var hasAppearedBefore = false
    @State private var renaming: ChatConversation?
    @State private var renameText = ""

    public init(client: APIClient) {
        let api = ChatAPI(client: client)
        self.api = api
        _store = StateObject(wrappedValue: ChatListStore(api: api))
    }

    public var body: some View {
        content
            .navigationTitle("AI Chat")
            .navigationBarTitleDisplayMode(.large)
            .toolbar { toolbarContent }
            .task {
                if case .loading = store.phase { await store.initialLoad() }
            }
            .onAppear {
                // Re-read on return from a thread so a new/renamed conversation
                // and its bumped order show up; skip the very first appear (the
                // .task above owns the initial load).
                if hasAppearedBefore { Task { await store.reload() } }
                hasAppearedBefore = true
            }
            .navigationDestination(isPresented: $startNewChat) {
                ChatThreadView(api: api, conversationID: nil, title: "New chat")
            }
            .alert("Rename chat", isPresented: renameAlertBinding) {
                TextField("Title", text: $renameText)
                Button("Cancel", role: .cancel) { renaming = nil }
                Button("Save") {
                    if let conversation = renaming {
                        let title = renameText
                        Task { await store.rename(conversation, to: title) }
                    }
                    renaming = nil
                }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("chat_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("chat_error")
                Button("Try Again") {
                    Task { await store.initialLoad() }
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("chat_retry")
            }
            .padding()
        case .loaded:
            conversationList
        }
    }

    private var conversationList: some View {
        List {
            if store.conversations.isEmpty {
                emptyState
            }
            ForEach(store.conversations) { conversation in
                NavigationLink {
                    ChatThreadView(api: api, conversationID: conversation.id, title: conversation.title)
                } label: {
                    ConversationRow(conversation: conversation)
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
                        Task { await store.togglePin(conversation) }
                    } label: {
                        Label(conversation.pinned ? "Unpin" : "Pin",
                              systemImage: conversation.pinned ? "pin.slash" : "pin")
                    }
                    .tint(.orange)
                }
                .contextMenu {
                    Button {
                        renameText = conversation.title
                        renaming = conversation
                    } label: { Label("Rename", systemImage: "pencil") }
                    Button {
                        Task { await store.togglePin(conversation) }
                    } label: {
                        Label(conversation.pinned ? "Unpin" : "Pin",
                              systemImage: conversation.pinned ? "pin.slash" : "pin")
                    }
                    Button(role: .destructive) {
                        Task { await store.delete(conversation) }
                    } label: { Label("Delete", systemImage: "trash") }
                }
            }
        }
        .listStyle(.plain)
        .accessibilityIdentifier("chat_list")
        .refreshable { await store.reload() }
        .searchable(text: $store.searchText, placement: .navigationBarDrawer(displayMode: .automatic),
                    prompt: "Search chats")
        .onSubmit(of: .search) {
            Task { await store.submitSearch() }
        }
        .onChange(of: store.searchText) { text in
            if text.isEmpty { Task { await store.clearSearch() } }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 10) {
            Image(systemName: "sparkles")
                .font(.largeTitle)
                .foregroundStyle(.secondary)
            Text(store.activeQuery.isEmpty ? "No chats yet." : "No results for “\(store.activeQuery)”")
                .foregroundStyle(.secondary)
            if store.activeQuery.isEmpty {
                Button("Start a chat") { startNewChat = true }
                    .buttonStyle(.borderedProminent)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
        .listRowSeparator(.hidden)
        .accessibilityIdentifier("chat_empty")
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Button {
                startNewChat = true
            } label: {
                Image(systemName: "square.and.pencil")
            }
            .accessibilityIdentifier("chat_new")
        }
    }

    private var renameAlertBinding: Binding<Bool> {
        Binding(
            get: { renaming != nil },
            set: { if !$0 { renaming = nil } }
        )
    }
}

/// One conversation row: a pin marker (when pinned) and the title.
struct ConversationRow: View {
    let conversation: ChatConversation

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: conversation.pinned ? "pin.fill" : "bubble.left.and.bubble.right")
                .font(.footnote)
                .foregroundStyle(conversation.pinned ? Color.orange : Color.secondary)
                .frame(width: 22)
            Text(conversation.title.isEmpty ? "Untitled" : conversation.title)
                .lineLimit(1)
            Spacer(minLength: 0)
        }
        .padding(.vertical, 2)
    }
}
