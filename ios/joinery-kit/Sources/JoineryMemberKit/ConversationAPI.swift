import Foundation
import JoineryKit

/// Thin typed face over the `conversation_*` actions. Every call rides the
/// app's session key through APIClient; participant authorization is
/// entirely server-side, matching the web conversation page's checks
/// (which the same actions back — specs/mobile_native_member_screens.md).
public struct ConversationAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func list(offset: Int) async throws -> ConversationPage {
        let envelope = try await client.submitAction("conversation_list", body: .object([
            (key: "offset", value: .number(Double(offset))),
        ]))
        guard let page = ConversationPage(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return page
    }

    /// Load an existing conversation, or dedup into one for `to` in compose
    /// mode. `before`/`after` are ISO UTC cursors; omit both for the newest
    /// page. Marks the conversation read as a side effect.
    public func thread(
        conversationID: Int?,
        to: Int?,
        before: String? = nil,
        after: String? = nil
    ) async throws -> ThreadPayload {
        var body: [(key: String, value: JSONValue)] = []
        if let conversationID {
            body.append((key: "conversation_id", value: .number(Double(conversationID))))
        } else if let to {
            body.append((key: "to", value: .number(Double(to))))
        }
        if let before { body.append((key: "before", value: .string(before))) }
        if let after { body.append((key: "after", value: .string(after))) }
        let envelope = try await client.submitAction("conversation_thread", body: .object(body))
        guard let payload = ThreadPayload(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return payload
    }

    /// Send a message. Provide `conversationID` for an existing thread, or
    /// `to` to create/reuse a 1:1 conversation with that user.
    public func send(conversationID: Int?, to: Int?, body: String) async throws -> SentMessage {
        var fields: [(key: String, value: JSONValue)] = []
        if let conversationID {
            fields.append((key: "conversation_id", value: .number(Double(conversationID))))
        } else if let to {
            fields.append((key: "to", value: .number(Double(to))))
        }
        fields.append((key: "body", value: .string(body)))
        let envelope = try await client.submitAction("conversation_send", body: .object(fields))
        guard let sent = SentMessage(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return sent
    }

    public enum ConversationMutation: String, Sendable {
        case mute
        case unmute
        case delete
    }

    @discardableResult
    public func action(_ action: ConversationMutation, conversationID: Int) async throws -> JSONValue {
        try await client.submitAction("conversation_action", body: .object([
            (key: "conversation_id", value: .number(Double(conversationID))),
            (key: "action", value: .string(action.rawValue)),
        ]))
    }
}
