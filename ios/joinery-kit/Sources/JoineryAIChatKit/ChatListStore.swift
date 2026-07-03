import Foundation
import JoineryKit

/// State for the conversation list: the caller's chats, with live search and
/// pin/rename/delete. All writes go through ChatAPI and reload — the server is
/// the single source of truth, shared with the web chat.
@MainActor
public final class ChatListStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var conversations: [ChatConversation] = []
    @Published public var searchText = ""
    @Published public private(set) var activeQuery = ""

    public let api: ChatAPI
    /// Ignores stale in-flight loads after the search term changes.
    private var loadGeneration = 0

    public init(api: ChatAPI) {
        self.api = api
    }

    public func initialLoad() async {
        phase = .loading
        await reload()
    }

    public func reload() async {
        loadGeneration += 1
        let generation = loadGeneration
        do {
            let list = try await api.list(search: activeQuery)
            guard generation == loadGeneration else { return }
            conversations = list
            phase = .loaded
        } catch {
            guard generation == loadGeneration else { return }
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func submitSearch() async {
        activeQuery = searchText.trimmingCharacters(in: .whitespaces)
        await reload()
    }

    public func clearSearch() async {
        guard !activeQuery.isEmpty else { return }
        searchText = ""
        activeQuery = ""
        await reload()
    }

    // MARK: Row actions

    public func togglePin(_ conversation: ChatConversation) async {
        do {
            try await api.pin(conversationID: conversation.id, pinned: !conversation.pinned)
        } catch {
            // fall through to reload either way
        }
        // Reload to pick up the server's pinned-first ordering.
        await reload()
    }

    public func rename(_ conversation: ChatConversation, to title: String) async {
        let trimmed = title.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        do {
            try await api.rename(conversationID: conversation.id, title: trimmed)
            if let index = conversations.firstIndex(where: { $0.id == conversation.id }) {
                conversations[index].title = trimmed
            }
        } catch {
            await reload()
        }
    }

    public func delete(_ conversation: ChatConversation) async {
        conversations.removeAll { $0.id == conversation.id }
        do {
            try await api.deleteConversation(conversationID: conversation.id)
        } catch {
            await reload()
        }
    }
}
