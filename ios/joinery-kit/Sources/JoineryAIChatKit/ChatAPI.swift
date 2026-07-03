import Foundation
import JoineryKit

/// Thin typed face over the `joinery_ai/chat_*` actions (the native chat
/// surface). Every call rides the app's session key through APIClient;
/// conversations are owner-scoped entirely server-side. Turns run detached on
/// the server, so `send`/`confirm` return a poll handle and `poll` delivers the
/// streaming result — mirroring the web reader's send-then-poll transport.
public struct ChatAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    /// The caller's conversations, pinned-first then newest, optional search.
    public func list(search: String = "") async throws -> [ChatConversation] {
        var body: [(key: String, value: JSONValue)] = []
        if !search.isEmpty {
            body.append((key: "search", value: .string(search)))
        }
        let envelope = try await client.submitAction("joinery_ai/chat_list", body: .object(body))
        return (envelope["data"]?["conversations"]?.arrayValue ?? []).compactMap { ChatConversation(data: $0) }
    }

    /// One conversation and its turns.
    public func thread(conversationID: Int) async throws -> ChatThreadPayload {
        let envelope = try await client.submitAction("joinery_ai/chat_thread", body: .object([
            (key: "conversation_id", value: .number(Double(conversationID))),
        ]))
        guard let payload = ChatThreadPayload(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return payload
    }

    /// Send a message. Omit `conversationID` to start a new conversation;
    /// `enableDataAccess` seeds a new chat so the assistant can read (and, with
    /// confirmation, write) the owner's data — there's no controls UI yet.
    public func send(message: String, conversationID: Int?, enableDataAccess: Bool) async throws -> ChatSendResult {
        var body: [(key: String, value: JSONValue)] = [
            (key: "message", value: .string(message)),
        ]
        if let conversationID {
            body.append((key: "conversation_id", value: .number(Double(conversationID))))
        } else if enableDataAccess {
            body.append((key: "data_access", value: .bool(true)))
        }
        let envelope = try await client.submitAction("joinery_ai/chat_send", body: .object(body))
        guard let result = ChatSendResult(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return result
    }

    /// One poll tick for a running turn.
    public func poll(messageID: Int) async throws -> ChatPollResult {
        let envelope = try await client.submitAction("joinery_ai/chat_poll", body: .object([
            (key: "message_id", value: .number(Double(messageID))),
        ]))
        guard let result = ChatPollResult(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return result
    }

    /// Resolve a proposed action (`confirm` | `cancel`); the turn resumes on the
    /// same message id, polled like a send.
    public func confirm(conversationID: Int, messageID: Int, decision: String) async throws -> ChatSendResult {
        let envelope = try await client.submitAction("joinery_ai/chat_confirm", body: .object([
            (key: "conversation_id", value: .number(Double(conversationID))),
            (key: "message_id", value: .number(Double(messageID))),
            (key: "decision", value: .string(decision)),
        ]))
        guard let result = ChatSendResult(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return result
    }

    /// Delete a turn; returns the ids the server actually removed (a user turn
    /// takes its paired reply with it).
    @discardableResult
    public func deleteTurn(messageID: Int) async throws -> [Int] {
        let envelope = try await client.submitAction("joinery_ai/chat_turn_action", body: .object([
            (key: "message_id", value: .number(Double(messageID))),
            (key: "action", value: .string("delete")),
        ]))
        return (envelope["data"]?["deleted_ids"]?.arrayValue ?? []).compactMap { $0.intValue }
    }

    public func pin(conversationID: Int, pinned: Bool) async throws {
        try await threadAction("pin", conversationID: conversationID, value: pinned ? "1" : "0")
    }

    public func rename(conversationID: Int, title: String) async throws {
        try await threadAction("rename", conversationID: conversationID, value: title)
    }

    public func deleteConversation(conversationID: Int) async throws {
        try await threadAction("delete", conversationID: conversationID)
    }

    private func threadAction(_ action: String, conversationID: Int, value: String? = nil) async throws {
        var body: [(key: String, value: JSONValue)] = [
            (key: "conversation_id", value: .number(Double(conversationID))),
            (key: "action", value: .string(action)),
        ]
        if let value {
            body.append((key: "value", value: .string(value)))
        }
        _ = try await client.submitAction("joinery_ai/chat_thread_action", body: .object(body))
    }
}
