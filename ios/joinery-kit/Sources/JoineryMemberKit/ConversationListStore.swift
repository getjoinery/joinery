import Foundation
import JoineryKit

/// State for the conversation inbox: paginated list with mute/unmute/delete.
/// 20/page, matching the web inbox. All writes go through ConversationAPI
/// and reload — the server is the single source of truth, shared with the
/// web conversation page (both ride the same actions).
@MainActor
public final class ConversationListStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var conversations: [ConversationRow] = []
    @Published public private(set) var isLoadingMore = false

    public let api: ConversationAPI
    private var totalCount = 0

    public init(api: ConversationAPI) {
        self.api = api
    }

    public var hasMore: Bool { conversations.count < totalCount }

    public func initialLoad() async {
        phase = .loading
        await reload()
    }

    public func reload() async {
        do {
            let page = try await api.list(offset: 0)
            conversations = page.conversations
            totalCount = page.totalCount
            phase = .loaded
        } catch {
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func loadMore() async {
        guard hasMore, !isLoadingMore else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        do {
            let page = try await api.list(offset: conversations.count)
            let known = Set(conversations.map(\.conversationID))
            conversations += page.conversations.filter { !known.contains($0.conversationID) }
            totalCount = page.totalCount
        } catch {
            // Paging failures are silent; the next scroll retries.
        }
    }

    public func toggleMute(_ conversation: ConversationRow) async {
        let target: ConversationRow = conversation
        let newMuted = !target.muted
        patch(target.conversationID) { $0.muted = newMuted }
        do {
            try await api.action(newMuted ? .mute : .unmute, conversationID: target.conversationID)
        } catch {
            await reload()
        }
    }

    public func delete(_ conversation: ConversationRow) async {
        conversations.removeAll { $0.conversationID == conversation.conversationID }
        do {
            try await api.action(.delete, conversationID: conversation.conversationID)
        } catch {
            await reload()
        }
    }

    private func patch(_ conversationID: Int, mutate: (inout ConversationRow) -> Void) {
        guard let index = conversations.firstIndex(where: { $0.conversationID == conversationID }) else { return }
        mutate(&conversations[index])
    }
}
