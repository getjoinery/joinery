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
public struct ChatConversation: Identifiable, Equatable, Sendable {
    public let id: Int
    public var title: String
    public var pinned: Bool
    public var model: String?
    public var usageLabel: String?
    /// Present only on a full thread load (`chat_thread`), not list rows.
    public var controls: ChatControlValues?

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
        controls = data["controls"].map { ChatControlValues(data: $0) }
    }
}

/// The per-chat control values. Numeric fields are text ("" = inherit the
/// plugin-setting default); the picker/toggle fields carry concrete values.
public struct ChatControlValues: Equatable, Sendable {
    public var model: String
    public var dataAccess: Bool
    public var webSearch: Bool
    public var thinkingLevel: String   // off | low | medium | high
    public var temperature: String
    public var topP: String
    public var maxTokens: String
    public var instructions: String

    init(data: JSONValue?) {
        model = data?["model"]?.stringValue ?? ""
        dataAccess = data?["data_access"]?.boolValue ?? false
        webSearch = data?["web_search"]?.boolValue ?? false
        thinkingLevel = data?["thinking_level"]?.stringValue ?? "off"
        temperature = Self.numberString(data?["temperature"])
        topP = Self.numberString(data?["top_p"])
        maxTokens = Self.numberString(data?["max_tokens"])
        instructions = data?["instructions"]?.stringValue ?? ""
    }

    /// A new chat's starting controls: the server defaults, with data access on
    /// so the native assistant is useful out of the box.
    init(defaults: ChatControlDefaults) {
        model = defaults.model
        dataAccess = true
        webSearch = defaults.webSearch
        thinkingLevel = defaults.thinkingLevel
        temperature = defaults.temperature
        topP = defaults.topP
        maxTokens = defaults.maxTokens
        instructions = ""
    }

    /// The seed fields sent on a new chat's first message (string-valued;
    /// `ChatControls::seedNewConversation` validates each).
    var seedFields: [String: String] {
        var fields: [String: String] = [
            "data_access": dataAccess ? "1" : "0",
            "web_search": webSearch ? "1" : "0",
            "thinking_level": thinkingLevel,
        ]
        if !model.isEmpty { fields["model"] = model }
        if !temperature.isEmpty { fields["temperature"] = temperature }
        if !topP.isEmpty { fields["top_p"] = topP }
        if !maxTokens.isEmpty { fields["max_tokens"] = maxTokens }
        if !instructions.isEmpty { fields["instructions"] = instructions }
        return fields
    }

    private static func numberString(_ value: JSONValue?) -> String {
        guard let value, !value.isNull, let d = value.doubleValue else { return "" }
        return d == d.rounded() ? String(Int(d)) : String(d)
    }
}

/// One selectable model in the catalog.
public struct ChatModelOption: Identifiable, Equatable, Sendable {
    public let id: String
    public let label: String
    public let isPrivate: Bool

    init?(data: JSONValue) {
        guard let id = data["id"]?.stringValue, !id.isEmpty else { return nil }
        self.id = id
        label = data["label"]?.stringValue ?? id
        isPrivate = data["private"]?.boolValue ?? false
    }
}

/// Resolved default control values, shown as placeholders / new-chat seeds.
public struct ChatControlDefaults: Equatable, Sendable {
    public let model: String
    public let thinkingLevel: String
    public let temperature: String
    public let topP: String
    public let maxTokens: String
    public let webSearch: Bool

    init(data: JSONValue?) {
        model = data?["model"]?.stringValue ?? ""
        thinkingLevel = data?["thinking_level"]?.stringValue ?? "off"
        temperature = data?["temperature"]?.stringValue ?? ""
        topP = data?["top_p"]?.stringValue ?? ""
        maxTokens = data?["max_tokens"]?.stringValue ?? ""
        webSearch = data?["web_search"]?.boolValue ?? false
    }
}

/// Chat control metadata: the model catalog plus the defaults.
public struct ChatControlsMeta: Sendable {
    public let models: [ChatModelOption]
    public let webSearchAvailable: Bool
    public let defaults: ChatControlDefaults

    init?(data: JSONValue?) {
        guard let data else { return nil }
        models = (data["models"]?.arrayValue ?? []).compactMap { ChatModelOption(data: $0) }
        webSearchAvailable = data["web_search_available"]?.boolValue ?? false
        defaults = ChatControlDefaults(data: data["defaults"])
    }

    /// The catalog label for a model id, falling back to the id.
    public func label(for modelID: String) -> String {
        models.first { $0.id == modelID }?.label ?? modelID
    }

    public func isPrivate(_ modelID: String) -> Bool {
        models.first { $0.id == modelID }?.isPrivate ?? false
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

/// A file attached to a turn, as the server serializes it. `imageURL` is a
/// short-lived signed URL for image attachments (empty for pdf/text/file); the
/// view resolves it against the app's base URL and renders a thumbnail. Others
/// render as a labeled file chip.
public struct ChatAttachment: Identifiable, Equatable, Sendable {
    public let id: Int            // the file id
    public let name: String
    public let category: String   // image | pdf | text | html | file
    public let imageURL: String

    public var isImage: Bool { category == "image" && !imageURL.isEmpty }

    init?(data: JSONValue) {
        guard let id = data["file_id"]?.intValue else { return nil }
        self.id = id
        name = data["name"]?.stringValue ?? "attachment"
        category = data["category"]?.stringValue ?? "file"
        imageURL = data["image_url"]?.stringValue ?? ""
    }
}

/// A file the user picked to send with a message. Carried as a multipart part;
/// the server validates type, size, and the model's vision/document capability
/// and is the sole authority — the client only pre-filters the picker.
public struct ChatOutgoingAttachment: Identifiable, Equatable, Sendable {
    public let id: UUID
    public let filename: String
    public let mimeType: String
    public let data: Data

    public init(id: UUID = UUID(), filename: String, mimeType: String, data: Data) {
        self.id = id
        self.filename = filename
        self.mimeType = mimeType
        self.data = data
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
    public var attachments: [ChatAttachment]
    /// The runner's live stage label while the turn runs ("Waiting for
    /// glm-5p2…", "Running tool: web_search…"); empty once settled or against
    /// an older server.
    public var activity: String
    /// Server-computed elapsed seconds for a running turn, so a thread opened
    /// mid-generation shows the true elapsed time.
    public var runningSeconds: Int?

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
        attachments = (data["attachments"]?.arrayValue ?? []).compactMap(ChatAttachment.init(data:))
        activity = data["activity"]?.stringValue ?? ""
        runningSeconds = data["running_seconds"]?.intValue
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
        self.attachments = []
        self.activity = ""
        self.runningSeconds = nil
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
    /// Present when a file was dropped server-side at commit (type drift); shown
    /// so a dropped attachment is never silent.
    public let attachmentWarning: String?

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
        attachmentWarning = data["attachment_warning"]?.stringValue
    }
}

/// One poll tick: the current status, streamed partial text while running, or
/// the finished turn / error once settled. While running it also carries the
/// runner's live stage label and elapsed seconds
/// (specs/ai_chat_turn_activity.md); both absent against an older server.
public struct ChatPollResult: Sendable {
    public let status: ChatStatus
    public let partialText: String?
    public let message: ChatMessage?
    public let usageLabel: String?
    public let error: String?
    public let activity: String
    public let runningSeconds: Int?

    init?(data: JSONValue?) {
        guard let data, let status = data["status"]?.stringValue else { return nil }
        self.status = ChatStatus(status)
        partialText = data["partial_text"]?.stringValue
        message = ChatMessage(data: data["message"])
        usageLabel = data["usage_label"]?.stringValue
        error = data["error"]?.stringValue
        activity = data["activity"]?.stringValue ?? ""
        runningSeconds = data["running_seconds"]?.intValue
    }
}
