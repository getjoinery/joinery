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

/// Thin typed face over the `inbound_email/*` actions
/// (specs/mobile_native_email.md § Server-side). Every call rides the app's
/// session key through APIClient; scoping is entirely server-side.
public struct MailAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func mailboxes() async throws -> MailboxHome {
        let envelope = try await client.submitAction("inbound_email/mailboxes", body: .object([]))
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
        let envelope = try await client.submitAction("inbound_email/thread_list", body: .object(body))
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
        let envelope = try await client.submitAction("inbound_email/thread", body: .object(body))
        guard let thread = MailThread(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return thread
    }

    /// A thread-level state mutation (mark_read, star, archive, delete,
    /// mark_spam, …). Returns the number of affected messages.
    @discardableResult
    public func threadAction(_ action: String, threadKey: String, aliasID: Int?) async throws -> Int {
        var body: [(key: String, value: JSONValue)] = [
            (key: "action", value: .string(action)),
            (key: "thread_key", value: .string(threadKey)),
        ]
        if let aliasID {
            body.append((key: "alias_id", value: .number(Double(aliasID))))
        }
        let envelope = try await client.submitAction("inbound_email/thread_action", body: .object(body))
        return envelope["data"]?["count"]?.intValue ?? 0
    }

    public enum ComposeMode: String, Sendable {
        case reply
        case replyAll = "reply_all"
        case forward
    }

    /// Send as the mailbox. The server quotes the original, normalizes the
    /// subject, applies threading headers, and stores the outbound copy.
    public func send(
        mode: ComposeMode,
        sourceID: Int,
        to: String,
        cc: String,
        subject: String,
        body: String
    ) async throws {
        _ = try await client.submitAction("inbound_email/send", body: .object([
            (key: "mode", value: .string(mode.rawValue)),
            (key: "source_id", value: .number(Double(sourceID))),
            (key: "to", value: .string(to)),
            (key: "cc", value: .string(cc)),
            (key: "subject", value: .string(subject)),
            (key: "body", value: .string(body)),
        ]))
    }
}
