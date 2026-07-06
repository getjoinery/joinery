import Foundation
import JoineryKit

/// One granted mailbox from `mailbox/mailboxes`.
public struct Mailbox: Identifiable, Equatable, Sendable {
    public let aliasID: Int
    public let address: String
    public let unread: Int
    public let total: Int

    public var id: Int { aliasID }

    /// The local part — what the switcher shows when every grant shares a domain.
    public var localPart: String {
        address.split(separator: "@").first.map(String.init) ?? address
    }

    init?(json: JSONValue) {
        guard let aliasID = json["alias_id"]?.intValue,
              let address = json["address"]?.stringValue, !address.isEmpty
        else { return nil }
        self.aliasID = aliasID
        self.address = address
        self.unread = json["unread"]?.intValue ?? 0
        self.total = json["total"]?.intValue ?? 0
    }
}

/// The `mailbox/mailboxes` payload.
public struct MailboxHome: Equatable, Sendable {
    public let mailboxes: [Mailbox]
    public let canCompose: Bool

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        mailboxes = (data["mailboxes"]?.arrayValue ?? []).compactMap(Mailbox.init(json:))
        canCompose = data["can_compose"]?.boolValue ?? false
    }
}

/// One row of `mailbox/thread_list`.
public struct ThreadSummary: Identifiable, Equatable, Sendable {
    public let threadKey: String
    public let subject: String
    public let sender: String
    public let snippet: String
    public let messageCount: Int
    public var unreadCount: Int
    public var isStarred: Bool
    public var isArchived: Bool
    public let latestTime: String

    public var id: String { threadKey }
    public var hasUnread: Bool { unreadCount > 0 }

    init?(json: JSONValue) {
        guard let key = json["thread_key"]?.stringValue, !key.isEmpty else { return nil }
        threadKey = key
        subject = json["subject"]?.stringValue ?? ""
        sender = json["sender"]?.stringValue ?? ""
        snippet = json["snippet"]?.stringValue ?? ""
        messageCount = json["msg_count"]?.intValue ?? 1
        unreadCount = json["unread_count"]?.intValue ?? 0
        isStarred = json["any_starred"]?.boolValue ?? false
        isArchived = json["any_archived"]?.boolValue ?? false
        latestTime = json["latest_time"]?.stringValue ?? ""
    }
}

/// The `mailbox/thread_list` payload.
public struct ThreadPage: Equatable, Sendable {
    public let threads: [ThreadSummary]
    public let hasMore: Bool
    public let page: Int

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        threads = (data["threads"]?.arrayValue ?? []).compactMap(ThreadSummary.init(json:))
        hasMore = data["has_more"]?.boolValue ?? false
        page = data["page"]?.intValue ?? 1
    }
}

/// A non-inline attachment on a message. `url` is a short-lived signed
/// download URL when the bytes are file-backed, nil otherwise.
public struct MailAttachment: Identifiable, Equatable, Sendable {
    public let id: Int
    public let filename: String
    public let contentType: String
    public let sizeBytes: Int
    public let url: String?

    init?(json: JSONValue) {
        guard let id = json["id"]?.intValue else { return nil }
        self.id = id
        filename = json["filename"]?.stringValue ?? "attachment"
        contentType = json["content_type"]?.stringValue ?? "application/octet-stream"
        sizeBytes = json["size_bytes"]?.intValue ?? 0
        url = json["url"]?.stringValue
    }

    public var sizeLabel: String {
        let bytes = Double(sizeBytes)
        if bytes >= 1_048_576 { return String(format: "%.1f MB", bytes / 1_048_576) }
        if bytes >= 1024 { return String(format: "%.0f KB", bytes / 1024) }
        return "\(sizeBytes) B"
    }
}

/// A file the user picked to attach to a reply/forward. Carried as a multipart
/// part; the server re-detects the type and enforces the size/count caps and is
/// the sole authority — the client only pre-filters the picker. Duplicated from
/// `JoineryAIChatKit.ChatOutgoingAttachment` rather than shared across modules
/// (kept small and mail-specific on purpose).
public struct MailOutgoingAttachment: Identifiable, Equatable, Sendable {
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

/// One message of `mailbox/thread`.
public struct MailMessage: Identifiable, Equatable, Sendable {
    public let id: Int
    public let sender: String
    public let recipient: String
    public let subject: String
    public let receivedTime: String
    public var isRead: Bool
    public var isStarred: Bool
    public let direction: String
    public let bodyPlain: String
    public let bodyHTML: String
    public let attachments: [MailAttachment]

    public var isOutbound: Bool { direction == "outbound" }

    init?(json: JSONValue) {
        guard let id = json["id"]?.intValue else { return nil }
        self.id = id
        sender = json["sender"]?.stringValue ?? ""
        recipient = json["recipient"]?.stringValue ?? ""
        subject = json["subject"]?.stringValue ?? ""
        receivedTime = json["received_time"]?.stringValue ?? ""
        isRead = json["is_read"]?.boolValue ?? true
        isStarred = json["is_starred"]?.boolValue ?? false
        direction = json["direction"]?.stringValue ?? "inbound"
        bodyPlain = json["body_plain"]?.stringValue ?? ""
        bodyHTML = json["body_html"]?.stringValue ?? ""
        attachments = (json["attachments"]?.arrayValue ?? []).compactMap(MailAttachment.init(json:))
    }
}

/// The `mailbox/thread` payload.
public struct MailThread: Equatable, Sendable {
    public let messages: [MailMessage]

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        messages = (data["messages"]?.arrayValue ?? []).compactMap(MailMessage.init(json:))
    }
}

// MARK: - Address + date display helpers

public enum MailDisplay {
    /// "Jane Doe <jane@x.com>" → "Jane Doe"; bare addresses → local part.
    public static func senderName(_ raw: String) -> String {
        let trimmed = raw.trimmingCharacters(in: .whitespaces)
        if let lt = trimmed.firstIndex(of: "<") {
            let name = String(trimmed[..<lt])
                .trimmingCharacters(in: CharacterSet(charactersIn: " \"'"))
            if !name.isEmpty { return name }
        }
        let addr = address(trimmed)
        return addr.split(separator: "@").first.map(String.init) ?? addr
    }

    /// The bare address inside an RFC-style sender string.
    public static func address(_ raw: String) -> String {
        if let lt = raw.firstIndex(of: "<"), let gt = raw.firstIndex(of: ">"), lt < gt {
            return String(raw[raw.index(after: lt)..<gt])
        }
        return raw.trimmingCharacters(in: .whitespaces)
    }

    private static let dbFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return f
    }()

    public static func date(_ dbTime: String) -> Date? {
        // Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)".
        let base = String(dbTime.prefix(19))
        return dbFormatter.date(from: base)
    }

    /// Gmail-style list stamp: time today, "Jul 3" this year, else "7/3/25".
    public static func listStamp(_ dbTime: String, now: Date = Date()) -> String {
        guard let date = date(dbTime) else { return "" }
        let cal = Calendar.current
        let f = DateFormatter()
        f.locale = Locale.current
        if cal.isDate(date, inSameDayAs: now) {
            f.timeStyle = .short
            f.dateStyle = .none
        } else if cal.component(.year, from: date) == cal.component(.year, from: now) {
            f.setLocalizedDateFormatFromTemplate("MMM d")
        } else {
            f.dateStyle = .short
            f.timeStyle = .none
        }
        return f.string(from: date)
    }

    /// Header stamp inside a thread: "Jul 3, 2026 at 9:41 AM".
    public static func messageStamp(_ dbTime: String) -> String {
        guard let date = date(dbTime) else { return "" }
        let f = DateFormatter()
        f.dateStyle = .medium
        f.timeStyle = .short
        return f.string(from: date)
    }

    /// Stable avatar hue for a sender (Gmail-style colored initial circle).
    public static func avatarColorIndex(_ raw: String, paletteSize: Int) -> Int {
        let addr = address(raw).lowercased()
        var hash: UInt32 = 2166136261
        for byte in addr.utf8 {
            hash = (hash ^ UInt32(byte)) &* 16777619
        }
        return Int(hash % UInt32(max(paletteSize, 1)))
    }
}
