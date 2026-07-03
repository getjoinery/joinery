import Foundation
import JoineryKit

/// Where a turn is in its lifecycle, mirroring the server's aim_status.
public enum ChatStatus: String, Sendable {
    case running
    case complete
    case failed

    init(_ raw: String?) {
        self = ChatStatus(rawValue: raw ?? "complete") ?? .complete
    }
}

public enum ChatRole: String, Sendable {
    case user
    case assistant
}

/// A conversation as it appears in the list (id/title/pinned) and, when loaded
/// on its own, the extra header fields (model, running usage label).
public struct ChatConversation: Identifiable, Equatable, Hashable, Sendable {
    public let id: Int
    public var title: String
    public var pinned: Bool
    public var model: String?
    public var usageLabel: String?

    public init(id: Int, title: String, pinned: Bool, model: String? = nil, usageLabel: String? = nil) {
        self.id = id
        self.title = title
        self.pinned = pinned
        self.model = model
        self.usageLabel = usageLabel
    }

    init?(data: JSONValue?) {
        guard let data, let id = data["id"]?.intValue else { return nil }
        self.id = id
        title = data["title"]?.stringValue ?? "Untitled"
        pinned = data["pinned"]?.boolValue ?? false
        model = data["model"]?.stringValue
        usageLabel = data["usage_label"]?.stringValue
    }
}

/// A mutating action the assistant proposed and is holding for approval. Its
/// presence on a turn is what surfaces the Confirm / Cancel card.
public struct ChatPendingAction: Equatable, Sendable {
    public let description: String

    init?(data: JSONValue?) {
        guard let data, !data.isNull else { return nil }
        description = data["description"]?.stringValue ?? "Run this action?"
    }
}

/// One entry in a turn's tool trace.
public struct ChatToolCall: Equatable, Sendable {
    public let name: String
    public let isError: Bool
    public let durationMs: Int?

    init(data: JSONValue) {
        name = data["name"]?.stringValue ?? "?"
        isError = data["is_error"]?.boolValue ?? false
        durationMs = data["duration_ms"]?.intValue
    }
}

/// One turn in a conversation. `content` is raw markdown (assistant) or the
/// user's text; the view renders it. Mutable so the store can fold streamed
/// partial text and the final swap into the same row in place.
public struct ChatMessage: Identifiable, Equatable, Sendable {
    public let id: Int
    public let role: ChatRole
    public var content: String
    public var status: ChatStatus
    public var error: String
    public let createdTime: String
    public var pendingAction: ChatPendingAction?
    public var toolCalls: [ChatToolCall]
    public var costLabel: String

    init?(data: JSONValue?) {
        guard let data, let id = data["id"]?.intValue,
              let role = ChatRole(rawValue: data["role"]?.stringValue ?? "") else { return nil }
        self.id = id
        self.role = role
        content = data["content"]?.stringValue ?? ""
        status = ChatStatus(data["status"]?.stringValue)
        error = data["error"]?.stringValue ?? ""
        createdTime = data["created_time"]?.stringValue ?? ""
        pendingAction = ChatPendingAction(data: data["pending_action"])
        toolCalls = (data["tool_calls"]?.arrayValue ?? []).map(ChatToolCall.init(data:))
        costLabel = data["usage"]?["cost_label"]?.stringValue ?? ""
    }

    private init(id: Int, role: ChatRole, content: String, status: ChatStatus, error: String) {
        self.id = id
        self.role = role
        self.content = content
        self.status = status
        self.error = error
        self.createdTime = ""
        self.pendingAction = nil
        self.toolCalls = []
        self.costLabel = ""
    }

    /// The assistant placeholder shown while a detached turn runs; the poll
    /// loop fills its content and finally swaps in the persisted row.
    static func runningPlaceholder(id: Int) -> ChatMessage {
        ChatMessage(id: id, role: .assistant, content: "", status: .running, error: "")
    }

    /// A local-only failed assistant row for an error that never reached (or
    /// came back from) the server; a negative id keeps it distinct.
    static func localFailure(error: String) -> ChatMessage {
        ChatMessage(id: -Int(Date().timeIntervalSince1970), role: .assistant, content: "", status: .failed, error: error)
    }
}

/// A loaded conversation: its header plus every turn.
public struct ChatThreadPayload: Sendable {
    public let conversation: ChatConversation
    public let messages: [ChatMessage]

    init?(data: JSONValue?) {
        guard let data, let conversation = ChatConversation(data: data["conversation"]) else { return nil }
        self.conversation = conversation
        messages = (data["messages"]?.arrayValue ?? []).compactMap { ChatMessage(data: $0) }
    }
}

/// The result of a send or a confirm: the poll handle for the running turn,
/// plus the user turn (send) and — on the synchronous fallback — the finished
/// assistant turn.
public struct ChatSendResult: Sendable {
    public let conversationID: Int
    public let messageID: Int
    public let isNew: Bool
    public let title: String
    public let status: ChatStatus
    public let userMessage: ChatMessage?
    public let assistantMessage: ChatMessage?
    public let usageLabel: String?
    public let error: String?

    init?(data: JSONValue?) {
        guard let data, let messageID = data["message_id"]?.intValue else { return nil }
        self.messageID = messageID
        conversationID = data["conversation_id"]?.intValue ?? 0
        isNew = data["is_new"]?.boolValue ?? false
        title = data["title"]?.stringValue ?? ""
        status = ChatStatus(data["status"]?.stringValue)
        userMessage = ChatMessage(data: data["user_message"])
        assistantMessage = ChatMessage(data: data["assistant_message"])
        usageLabel = data["usage_label"]?.stringValue
        error = data["error"]?.stringValue
    }
}

/// One poll tick: the current status, streamed partial text while running, or
/// the finished turn / error once settled.
public struct ChatPollResult: Sendable {
    public let status: ChatStatus
    public let partialText: String?
    public let message: ChatMessage?
    public let usageLabel: String?
    public let error: String?

    init?(data: JSONValue?) {
        guard let data, let status = data["status"]?.stringValue else { return nil }
        self.status = ChatStatus(status)
        partialText = data["partial_text"]?.stringValue
        message = ChatMessage(data: data["message"])
        usageLabel = data["usage_label"]?.stringValue
        error = data["error"]?.stringValue
    }
}
