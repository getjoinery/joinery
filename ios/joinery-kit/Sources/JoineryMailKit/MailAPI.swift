import Foundation
import JoineryKit

/// Which slice of a mailbox the thread list shows — the reader's views.
public enum MailView: String, CaseIterable, Identifiable, Sendable {
    case inbox
    case starred
    case all
    case spam
    case drafts

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .inbox: return "Inbox"
        case .starred: return "Starred"
        case .all: return "All Mail"
        case .spam: return "Spam"
        case .drafts: return "Drafts"
        }
    }

    public var systemImage: String {
        switch self {
        case .inbox: return "tray"
        case .starred: return "star"
        case .all: return "archivebox"
        case .spam: return "exclamationmark.octagon"
        case .drafts: return "doc.text"
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

    /// `deviceHits`: the message ids this phone's own index found for `query`
    /// (packed, `MailSearchCore.packIds`); `deviceOnly`: every mailbox in view
    /// is end-to-end, so no term goes to the server at all
    /// (specs/client_custody_mail.md § R5).
    public func threadList(
        aliasID: Int?,
        view: MailView,
        query: String,
        page: Int,
        deviceHits: String? = nil,
        deviceOnly: Bool = false
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
        case .drafts: body.append((key: "drafts", value: .bool(true)))
        }
        if deviceOnly {
            body.append((key: "q", value: .string("")))
            body.append((key: "device_only", value: .string("1")))
            body.append((key: "device_hits", value: .string(deviceHits ?? "")))
        } else if !query.isEmpty {
            body.append((key: "q", value: .string(query)))
            if let deviceHits {
                body.append((key: "device_hits", value: .string(deviceHits)))
            }
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

    /// What a send came back with. The sending lock answers HTTP 200
    /// `{locked: true, message}` (a browser ceremony): the sheet stays open.
    public enum SendOutcome: Equatable, Sendable {
        case sent
        case locked(String)
    }

    /// Send as the mailbox. For reply/reply-all/forward the server quotes the
    /// original, normalizes the subject, and applies threading headers; for a
    /// new message (`sourceID` nil, `aliasID` set) it sends exactly as entered
    /// and starts a fresh conversation. Either way the outbound copy is stored
    /// (with an attachment manifest, so the sent copy shows what was
    /// attached). Files go as multipart `attachments[]`; otherwise a JSON action.
    ///
    /// End-to-end: `sourceOpen` is what this phone opened of a Fortress source
    /// (the server cannot read it to quote), `inlineManifest` names a forward's
    /// inline parts by Content-ID, and `draftID` morphs a saved draft into the
    /// Sent row. `upload_count` tells the server how many files were meant to
    /// arrive, so a dropped part fails the send instead of vanishing.
    public func send(
        mode: ComposeMode,
        sourceID: Int? = nil,
        aliasID: Int? = nil,
        to: String,
        cc: String,
        subject: String,
        body: String,
        attachments: [MailOutgoingAttachment] = [],
        sourceOpen: JSONValue? = nil,
        inlineManifest: [String: String] = [:],
        draftID: Int? = nil
    ) async throws -> SendOutcome {
        var text: [(key: String, value: String)] = [(key: "mode", value: mode.rawValue)]
        if let sourceID { text.append((key: "source_id", value: String(sourceID))) }
        if let aliasID { text.append((key: "alias_id", value: String(aliasID))) }
        text.append(contentsOf: [
            (key: "to", value: to),
            (key: "cc", value: cc),
            (key: "subject", value: subject),
            (key: "body", value: body),
        ])
        if let sourceOpen { text.append((key: "source_open", value: sourceOpen.encoded())) }
        if !inlineManifest.isEmpty {
            let m = JSONValue.object(inlineManifest.sorted { $0.key < $1.key }.map { (key: $0.key, value: .string($0.value)) })
            text.append((key: "inline_manifest", value: m.encoded()))
        }
        if let draftID { text.append((key: "draft_id", value: String(draftID))) }
        let envelope: JSONValue
        if attachments.isEmpty {
            envelope = try await client.submitAction("mailbox/send",
                                                     body: .object(text.map { (key: $0.key, value: .string($0.value)) }))
        } else {
            text.append((key: "upload_count", value: String(attachments.count)))
            let files = attachments.map {
                MultipartFile(field: "attachments[]", filename: $0.filename, mimeType: $0.mimeType, data: $0.data)
            }
            envelope = try await client.submitMultipart("mailbox/send", fields: text, files: files)
        }
        if envelope["data"]?["locked"]?.boolValue == true {
            return .locked(envelope["data"]?["message"]?.stringValue
                ?? "Sending from this mailbox needs a confirmation on a computer.")
        }
        return .sent
    }

    // MARK: Plain actions

    /// Any `mailbox/*` (or core) action with a JSON body; the `data` payload.
    @discardableResult
    public func action(_ name: String, _ fields: [(key: String, value: JSONValue)]) async throws -> JSONValue {
        let envelope = try await client.submitAction(name, body: .object(fields))
        return envelope["data"] ?? .null
    }

    /// Any action as multipart (text fields + files); the `data` payload.
    @discardableResult
    public func multipart(_ name: String, _ fields: [(key: String, value: String)],
                          files: [MultipartFile]) async throws -> JSONValue {
        let envelope = try await client.submitMultipart(name, fields: fields, files: files)
        return envelope["data"] ?? .null
    }
}
