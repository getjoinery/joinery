import Foundation
import JoineryKit

/// How a thread screen was opened: an existing conversation, or `to` a
/// recipient for compose-mode dedup (the server returns the existing 1:1
/// conversation if there is one, else an empty compose-mode payload).
public enum ThreadOrigin: Equatable, Sendable {
    case conversation(id: Int, otherDisplayName: String)
    case compose(to: Int, otherDisplayName: String)
}

/// State for one conversation: its messages and the compose bar. The server
/// returns messages oldest-first from the start of the thread (no cursor);
/// `loadMore()` pages forward with an `after` cursor when scrolled to the
/// bottom, matching the read action's ordering
/// (logic/conversation_thread_logic.php). All writes go through
/// ConversationAPI and reconcile against the server.
@MainActor
public final class ConversationThreadStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var messages: [ThreadMessage] = []
    @Published public private(set) var conversationID: Int?
    @Published public private(set) var otherDisplayName: String
    @Published public private(set) var isMuted = false
    @Published public private(set) var hasMore = false
    @Published public private(set) var isLoadingMore = false
    @Published public var composerText = ""
    @Published public private(set) var isSending = false
    @Published public private(set) var sendError: String?

    public let api: ConversationAPI
    private let origin: ThreadOrigin

    public init(api: ConversationAPI, origin: ThreadOrigin) {
        self.api = api
        self.origin = origin
        switch origin {
        case .conversation(let id, let name):
            conversationID = id
            otherDisplayName = name
        case .compose(_, let name):
            conversationID = nil
            otherDisplayName = name
        }
    }

    public var canSend: Bool {
        !composerText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !isSending
    }

    public func load() async {
        phase = .loading
        do {
            let payload = try await fetch()
            apply(payload)
            phase = .loaded
        } catch {
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    public func loadMore() async {
        guard hasMore, !isLoadingMore, let cursor = messages.last?.time else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        do {
            let payload = try await api.thread(conversationID: conversationID, to: nil, after: cursor)
            let known = Set(messages.map(\.messageID))
            messages += payload.messages.filter { !known.contains($0.messageID) }
            hasMore = payload.hasMore
        } catch {
            // Paging failures are silent; the next scroll retries.
        }
    }

    public func send() async {
        let text = composerText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !isSending else { return }
        isSending = true
        composerText = ""
        sendError = nil
        defer { isSending = false }
        do {
            let recipientID: Int? = conversationID == nil ? composeRecipient : nil
            let sent = try await api.send(conversationID: conversationID, to: recipientID, body: text)
            if conversationID == nil {
                conversationID = sent.conversationID
            }
            messages.append(ThreadMessage(
                messageID: sent.messageID, senderID: 0, body: sent.body, time: sent.sentTime, isMine: true
            ))
        } catch {
            composerText = text
            sendError = (error as? JoineryAPIError)?.displayMessage ?? "Could not send your message."
        }
    }

    public func setMuted(_ muted: Bool) async {
        guard let conversationID else { return }
        isMuted = muted
        do {
            try await api.action(muted ? .mute : .unmute, conversationID: conversationID)
        } catch {
            isMuted = !muted
        }
    }

    /// Delete the conversation; the caller (thread screen) dismisses on
    /// success.
    public func delete() async throws {
        guard let conversationID else { return }
        try await api.action(.delete, conversationID: conversationID)
    }

    private var composeRecipient: Int? {
        if case .compose(let to, _) = origin { return to }
        return nil
    }

    private func fetch() async throws -> ThreadPayload {
        if let conversationID {
            return try await api.thread(conversationID: conversationID, to: nil)
        }
        if case .compose(let to, _) = origin {
            return try await api.thread(conversationID: nil, to: to)
        }
        throw JoineryAPIError.malformedResponse
    }

    private func apply(_ payload: ThreadPayload) {
        if let id = payload.conversationID {
            conversationID = id
        }
        if !payload.otherDisplayName.isEmpty { otherDisplayName = payload.otherDisplayName }
        isMuted = payload.isMuted
        messages = payload.messages
        hasMore = payload.hasMore
    }
}

/// Pure cursor math for paging a message list forward from the oldest
/// message (unit-tested directly): the `after` cursor for the next page is
/// always the last loaded message's time, and there is nothing to page while
/// the list is empty or the server reported no more.
public enum ThreadCursorMath {
    public static func nextAfterCursor(messages: [ThreadMessage], hasMore: Bool) -> String? {
        guard hasMore, let last = messages.last else { return nil }
        return last.time
    }
}
