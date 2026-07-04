import XCTest
@testable import JoineryAIChatKit
@testable import JoineryKit

/// Model parsing over captured API envelopes: the native chat surface must read
/// the joinery_ai/chat_* action responses exactly as the server emits them.
final class ChatParsingTests: XCTestCase {

    /// Load a captured envelope and return its `data` node.
    private func fixture(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json")
        let envelope = try JSONValue.parse(Data(contentsOf: url))
        return try XCTUnwrap(envelope["data"], "fixture \(name) has no data")
    }

    func testThreadParsesConversationAndTurns() throws {
        let payload = try XCTUnwrap(ChatThreadPayload(data: fixture("chat_thread")))

        XCTAssertEqual(payload.conversation.id, 29)
        XCTAssertEqual(payload.conversation.title, "Dovetail guide")
        XCTAssertTrue(payload.conversation.pinned)
        XCTAssertEqual(payload.conversation.model, "accounts/fireworks/models/glm-5p2")
        XCTAssertEqual(payload.conversation.usageLabel, "1,565 tokens used · ~$0.0051")

        XCTAssertEqual(payload.messages.count, 3)

        let user = payload.messages[0]
        XCTAssertEqual(user.role, .user)
        XCTAssertEqual(user.status, .complete)
        XCTAssertNil(user.pendingAction)

        let assistant = payload.messages[1]
        XCTAssertEqual(assistant.role, .assistant)
        XCTAssertTrue(assistant.content.contains("Dovetail"))
        XCTAssertEqual(assistant.costLabel, "~$0.0051")
        XCTAssertEqual(assistant.toolCalls.count, 1)
        XCTAssertEqual(assistant.toolCalls.first?.name, "web_search")
        XCTAssertEqual(assistant.toolCalls.first?.durationMs, 412)
        XCTAssertFalse(assistant.toolCalls.first?.isError ?? true)
    }

    func testPendingActionParses() throws {
        let payload = try XCTUnwrap(ChatThreadPayload(data: fixture("chat_thread")))
        let proposal = try XCTUnwrap(payload.messages.last)
        XCTAssertEqual(proposal.role, .assistant)
        let pending = try XCTUnwrap(proposal.pendingAction)
        XCTAssertEqual(pending.description, "Create a note titled “Dovetail guide”.")
    }

    func testListParses() throws {
        let data = try fixture("chat_list")
        let conversations = (data["conversations"]?.arrayValue ?? []).compactMap { ChatConversation(data: $0) }
        XCTAssertEqual(conversations.count, 3)
        XCTAssertEqual(conversations.first?.id, 29)
        XCTAssertTrue(conversations.first?.pinned ?? false)
        XCTAssertFalse(conversations[1].pinned)
    }

    func testSendResultParsesPollHandle() throws {
        let result = try XCTUnwrap(ChatSendResult(data: fixture("chat_send")))
        XCTAssertEqual(result.conversationID, 44)
        XCTAssertEqual(result.messageID, 86)
        XCTAssertTrue(result.isNew)
        XCTAssertEqual(result.status, .running)
        XCTAssertNil(result.assistantMessage)          // async path: no finished turn yet
        XCTAssertEqual(result.userMessage?.role, .user)
        XCTAssertEqual(result.userMessage?.content, "Reply with exactly: SPAWN OK")
    }

    func testPollCompleteParsesFinishedTurn() throws {
        let result = try XCTUnwrap(ChatPollResult(data: fixture("chat_poll_complete")))
        XCTAssertEqual(result.status, .complete)
        XCTAssertEqual(result.usageLabel, "543 tokens used")
        let message = try XCTUnwrap(result.message)
        XCTAssertEqual(message.id, 86)
        XCTAssertEqual(message.content, "SPAWN OK")
        XCTAssertEqual(message.status, .complete)
    }

    func testStatusDefaultsToComplete() {
        XCTAssertEqual(ChatStatus(nil), .complete)
        XCTAssertEqual(ChatStatus("bogus"), .complete)
        XCTAssertEqual(ChatStatus("running"), .running)
        XCTAssertEqual(ChatStatus("failed"), .failed)
    }

    // MARK: Attachments

    func testMessageParsesAttachments() throws {
        let data = JSONValue.object([
            (key: "id", value: .number(500)),
            (key: "role", value: .string("user")),
            (key: "content", value: .string("See attached")),
            (key: "attachments", value: .array([
                .object([
                    (key: "file_id", value: .number(12)),
                    (key: "name", value: .string("statement.pdf")),
                    (key: "category", value: .string("pdf")),
                    (key: "image_url", value: .string("")),
                ]),
                .object([
                    (key: "file_id", value: .number(13)),
                    (key: "name", value: .string("chart.png")),
                    (key: "category", value: .string("image")),
                    (key: "image_url", value: .string("/uploads/chart.png?expires=1&sig=ab")),
                ]),
            ])),
        ])
        let message = try XCTUnwrap(ChatMessage(data: data))
        XCTAssertEqual(message.attachments.count, 2)
        XCTAssertEqual(message.attachments[0].category, "pdf")
        XCTAssertFalse(message.attachments[0].isImage)          // no image_url → chip
        XCTAssertTrue(message.attachments[1].isImage)
        XCTAssertEqual(message.attachments[1].name, "chart.png")
    }

    func testSendResultParsesAttachmentWarning() {
        let data = JSONValue.object([
            (key: "message_id", value: .number(90)),
            (key: "conversation_id", value: .number(44)),
            (key: "status", value: .string("running")),
            (key: "attachment_warning", value: .string("Couldn’t send “x.pdf”.")),
        ])
        let result = ChatSendResult(data: data)
        XCTAssertEqual(result?.attachmentWarning, "Couldn’t send “x.pdf”.")
    }

    func testMultipartBodyEncodesFieldsAndFiles() {
        let file = MultipartFile(
            field: "attachments[]",
            filename: "a\"b.pdf",                                // embedded quote
            mimeType: "application/pdf",
            data: Data([0x25, 0x50, 0x44, 0x46]))               // %PDF
        let body = APIClient.multipartBody(
            boundary: "B",
            fields: [(key: "message", value: "hi")],
            files: [file])
        let text = String(decoding: body, as: UTF8.self)

        XCTAssertTrue(text.contains("--B\r\n"))
        XCTAssertTrue(text.contains("Content-Disposition: form-data; name=\"message\"\r\n\r\nhi\r\n"))
        XCTAssertTrue(text.contains("name=\"attachments[]\"; filename=\"a'b.pdf\""))  // quote sanitized
        XCTAssertTrue(text.contains("Content-Type: application/pdf"))
        XCTAssertTrue(text.contains("%PDF"))
        XCTAssertTrue(text.hasSuffix("--B--\r\n"))
    }
}
