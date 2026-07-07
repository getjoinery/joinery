import Foundation
import JoineryKit

/// Which slice of a mailbox the thread list shows — the reader's views.
public enum MailView: String, CaseIterable, Identifiable, Sendable {
    case inbox
    case starred
    case all
    case spam

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .inbox: return "Inbox"
        case .starred: return "Starred"
        case .all: return "All Mail"
        case .spam: return "Spam"
        }
    }

    public var systemImage: String {
        switch self {
        case .inbox: return "tray"
        case .starred: return "star"
        case .all: return "archivebox"
        case .spam: return "exclamationmark.octagon"
        }
    }
}

/// Thin typed face over the `mailbox/*` actions
/// (specs/mobile_native_email.md § Server-side). Every call rides the app's
/// session key through APIClient; scoping is entirely server-side.
public struct MailAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func mailboxes() async throws -> MailboxHome {
        let envelope = try await client.submitAction("mailbox/mailboxes", body: .object([]))
        guard let home = MailboxHome(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return home
    }

    public func threadList(
        aliasID: Int?,
        view: MailView,
        query: String,
        page: Int
    ) async throws -> ThreadPage {
        var body: [(key: String, value: JSONValue)] = [
            (key: "page", value: .number(Double(page))),
        ]
        if let aliasID {
            body.append((key: "alias_id", value: .number(Double(aliasID))))
        }
        switch view {
        case .inbox: body.append((key: "inbox", value: .bool(true)))
        case .starred: body.append((key: "starred_only", value: .bool(true)))
        case .all: break
        case .spam: body.append((key: "spam", value: .bool(true)))
        }
        if !query.isEmpty {
            body.append((key: "q", value: .string(query)))
        }
        let envelope = try await client.submitAction("mailbox/thread_list", body: .object(body))
        guard let pageData = ThreadPage(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return pageData
    }

    public func thread(key: String, aliasID: Int?) async throws -> MailThread {
        var body: [(key: String, value: JSONValue)] = [
            (key: "thread_key", value: .string(key)),
        ]
        if let aliasID {
            body.append((key: "alias_id", value: .number(Double(aliasID))))
        }
        let envelope = try await client.submitAction("mailbox/thread", body: .object(body))
        guard let thread = MailThread(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return thread
    }

    /// A thread-level state mutation (mark_read, star, archive, delete,
    /// mark_spam, set_membership, …). Returns the number of affected
    /// messages. `folderID`/`present` drive `set_membership`: for an
    /// exclusive feed present is always true (choosing a folder relocates
    /// the thread); for a non-exclusive one it toggles the label.
    @discardableResult
    public func threadAction(
        _ action: String,
        threadKey: String,
        aliasID: Int?,
        folderID: Int? = nil,
        present: Bool? = nil
    ) async throws -> Int {
        var body: [(key: String, value: JSONValue)] = [
            (key: "action", value: .string(action)),
            (key: "thread_key", value: .string(threadKey)),
        ]
        if let aliasID {
            body.append((key: "alias_id", value: .number(Double(aliasID))))
        }
        if let folderID {
            body.append((key: "folder_id", value: .number(Double(folderID))))
        }
        if let present {
            body.append((key: "present", value: .bool(present)))
        }
        let envelope = try await client.submitAction("mailbox/thread_action", body: .object(body))
        return envelope["data"]?["count"]?.intValue ?? 0
    }

    /// Create a folder/label on the thread's mailbox and file the thread
    /// into it — one call, matching the web reader's "New label / New
    /// folder" row (`buildFolderControl()` in mailbox_reader.js).
    public func createFolder(name: String, threadKey: String, aliasID: Int?) async throws -> MailFolder? {
        var body: [(key: String, value: JSONValue)] = [
            (key: "action", value: .string("create_folder")),
            (key: "thread_key", value: .string(threadKey)),
            (key: "name", value: .string(name)),
        ]
        if let aliasID {
            body.append((key: "alias_id", value: .number(Double(aliasID))))
        }
        let envelope = try await client.submitAction("mailbox/thread_action", body: .object(body))
        return envelope["data"]?["folder"].flatMap(MailFolder.init(json:))
    }

    public enum ComposeMode: String, Sendable {
        case reply
        case replyAll = "reply_all"
        case forward
        case new
    }

    /// Send as the mailbox. For reply/reply-all/forward the server quotes the
    /// original, normalizes the subject, and applies threading headers; for a
    /// new message (`sourceID` nil, `aliasID` set) it sends exactly as entered
    /// and starts a fresh conversation. Either way the outbound copy is stored
    /// (with an attachment manifest, so the sent copy shows what was
    /// attached). When `attachments` is non-empty the call goes out as
    /// multipart so the files reach the server's `$_FILES['attachments']`;
    /// otherwise it's a plain JSON action — the exact `ChatAPI.send()` shape.
    public func send(
        mode: ComposeMode,
        sourceID: Int? = nil,
        aliasID: Int? = nil,
        to: String,
        cc: String,
        subject: String,
        body: String,
        attachments: [MailOutgoingAttachment] = []
    ) async throws {
        if attachments.isEmpty {
            var fields: [(key: String, value: JSONValue)] = [
                (key: "mode", value: .string(mode.rawValue)),
            ]
            if let sourceID { fields.append((key: "source_id", value: .number(Double(sourceID)))) }
            if let aliasID { fields.append((key: "alias_id", value: .number(Double(aliasID)))) }
            fields.append(contentsOf: [
                (key: "to", value: .string(to)),
                (key: "cc", value: .string(cc)),
                (key: "subject", value: .string(subject)),
                (key: "body", value: .string(body)),
            ])
            _ = try await client.submitAction("mailbox/send", body: .object(fields))
        } else {
            var textFields: [(key: String, value: String)] = [
                (key: "mode", value: mode.rawValue),
            ]
            if let sourceID { textFields.append((key: "source_id", value: String(sourceID))) }
            if let aliasID { textFields.append((key: "alias_id", value: String(aliasID))) }
            textFields.append(contentsOf: [
                (key: "to", value: to),
                (key: "cc", value: cc),
                (key: "subject", value: subject),
                (key: "body", value: body),
            ])
            let files = attachments.map {
                MultipartFile(field: "attachments[]", filename: $0.filename, mimeType: $0.mimeType, data: $0.data)
            }
            _ = try await client.submitMultipart("mailbox/send", fields: textFields, files: files)
        }
    }
}
