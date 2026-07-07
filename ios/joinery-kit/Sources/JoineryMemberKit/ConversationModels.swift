import Foundation
import JoineryKit

/// One row of `conversation_list`.
public struct ConversationRow: Identifiable, Equatable, Sendable {
    public let conversationID: Int
    public let otherDisplayName: String
    public let preview: String
    public let lastMessageTime: String?
    public var unread: Bool
    public var muted: Bool
    public var id: Int { conversationID }

    init?(json: JSONValue) {
        guard let conversationID = json["conversation_id"]?.intValue else { return nil }
        self.conversationID = conversationID
        otherDisplayName = json["other_display_name"]?.stringValue ?? "Unknown"
        preview = json["preview"]?.stringValue ?? ""
        lastMessageTime = json["last_message_time"]?.stringValue
        unread = json["unread"]?.boolValue ?? false
        muted = json["muted"]?.boolValue ?? false
    }
}

/// The `conversation_list` payload. 20/page, matching the web inbox.
public struct ConversationPage: Equatable, Sendable {
    public static let perPage = 20

    public let conversations: [ConversationRow]
    public let totalCount: Int
    public let offset: Int
    public let perPage: Int

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        conversations = (data["conversations"]?.arrayValue ?? []).compactMap(ConversationRow.init(json:))
        totalCount = data["total_count"]?.intValue ?? 0
        offset = data["offset"]?.intValue ?? 0
        perPage = data["per_page"]?.intValue ?? Self.perPage
    }
}

/// One message of `conversation_thread`.
public struct ThreadMessage: Identifiable, Equatable, Sendable {
    public let messageID: Int
    public let senderID: Int
    public let body: String
    public let time: String
    public let isMine: Bool
    public var id: Int { messageID }

    /// Build a message directly, e.g. to append the caller's own just-sent
    /// message locally (`conversation_send`'s response) without a round trip
    /// through JSON.
    public init(messageID: Int, senderID: Int, body: String, time: String, isMine: Bool) {
        self.messageID = messageID
        self.senderID = senderID
        self.body = body
        self.time = time
        self.isMine = isMine
    }

    init?(json: JSONValue) {
        guard let messageID = json["message_id"]?.intValue else { return nil }
        self.messageID = messageID
        senderID = json["sender_id"]?.intValue ?? 0
        body = json["body"]?.stringValue ?? ""
        time = json["time"]?.stringValue ?? ""
        isMine = json["is_mine"]?.boolValue ?? false
    }
}

/// The `conversation_thread` payload. `to` compose-mode dedup surfaces as
/// `isComposeMode` with a nil `conversationID` and no messages; the first
/// send creates the conversation and the send result carries its id.
public struct ThreadPayload: Equatable, Sendable {
    public let isComposeMode: Bool
    public let conversationID: Int?
    public let otherDisplayName: String
    public let otherUserID: Int?
    public let isMuted: Bool
    public let messages: [ThreadMessage]
    public let hasMore: Bool

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        isComposeMode = data["is_compose_mode"]?.boolValue ?? false
        conversationID = data["conversation_id"]?.intValue
        otherDisplayName = data["other_display_name"]?.stringValue ?? "Unknown"
        otherUserID = data["other_user_id"]?.intValue
        isMuted = data["is_muted"]?.boolValue ?? false
        messages = (data["messages"]?.arrayValue ?? []).compactMap(ThreadMessage.init(json:))
        hasMore = data["has_more"]?.boolValue ?? false
    }
}

/// The `conversation_send` payload: the created message.
public struct SentMessage: Equatable, Sendable {
    public let conversationID: Int
    public let messageID: Int
    public let body: String
    public let sentTime: String

    public init?(data: JSONValue?) {
        guard let data, let messageID = data["message_id"]?.intValue else { return nil }
        self.messageID = messageID
        conversationID = data["conversation_id"]?.intValue ?? 0
        body = data["body"]?.stringValue ?? ""
        sentTime = data["sent_time"]?.stringValue ?? ""
    }
}
