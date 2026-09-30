import Foundation
import JoineryKit

/// One tracked folder/label on a mailbox — the unit the reader's Move/Labels
/// control and folder-filtered views operate on.
public struct MailFolder: Identifiable, Equatable, Sendable {
    public let id: Int
    public let name: String
    public let role: String

    init?(json: JSONValue) {
        guard let id = json["id"]?.intValue else { return nil }
        self.id = id
        name = json["name"]?.stringValue ?? ""
        role = json["role"]?.stringValue ?? "custom"
    }
}

/// One granted mailbox from `mailbox/mailboxes`. `foldersExclusive` drives
/// whether the folder control is a single-pick "Move" (exclusive feed, e.g.
/// an IMAP mailbox where a message lives in exactly one folder) or checkbox
/// "Labels" (Gmail-style, non-exclusive membership).
public struct Mailbox: Identifiable, Equatable, Sendable {
    public let aliasID: Int
    public let address: String
    public let unread: Int
    public let total: Int
    public let folders: [MailFolder]
    public let foldersExclusive: Bool
    /// `standard`, `private` or `fortress` (the mailbox's own level).
    public let securityLevel: String
    /// Sealed at rest and the viewer's server window is shut (Private).
    public let locked: Bool
    /// The viewer is a member (not an all-access view): the mailboxes whose
    /// new mail notifies by default.
    public let own: Bool
    /// The highest message id the `unread` count counts, nil when none is
    /// unread — the polling high-water mark (specs/fortress_mobile_apps.md § R15).
    public let newestUnreadID: Int?
    public let signature: String
    /// The add-ons in force (e.g. "Seal at the relay"), as the level chip names them.
    public let protectionAddons: [String]
    /// Saved drafts on this mailbox (the Drafts badge).
    public let drafts: Int

    /// End-to-end: the server cannot read it; this phone opens it with the mail key.
    public var isFortress: Bool { securityLevel == "fortress" }

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
        self.folders = (json["folders"]?.arrayValue ?? []).compactMap(MailFolder.init(json:))
        self.foldersExclusive = json["folders_exclusive"]?.boolValue ?? false
        self.securityLevel = json["security_level"]?.stringValue ?? "standard"
        self.locked = json["locked"]?.boolValue ?? false
        self.own = json["own"]?.boolValue ?? true
        let newest = json["newest_unread_id"]
        self.newestUnreadID = (newest?.isNull ?? true) ? nil : newest?.intValue
        self.signature = json["signature"]?.stringValue ?? ""
        self.protectionAddons = (json["protection_addons"]?.arrayValue ?? []).compactMap(\.stringValue)
        self.drafts = json["drafts"]?.intValue ?? 0
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
    public var subject: String
    public var sender: String
    public var snippet: String
    /// A Fortress row: the server sent ciphertext for the phone to open.
    public let sealed: SealedRow?
    /// What stands in for the content when this phone cannot open it.
    public var placeholder: FortressNote?
    /// The summary the owner's own model wrote (sealed under the row on Fortress).
    public var aiSummary: String = ""
    /// The newest message's id (in the Drafts view, the draft's id).
    public var latestID: Int = 0
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
        sealed = SealedRow(json: json["sealed"])
        placeholder = nil
        latestID = json["latest_id"]?.intValue ?? 0
        aiSummary = json["ai_summary"]?.stringValue ?? ""
    }
}

/// The `mailbox/thread_list` payload.
public struct ThreadPage: Equatable, Sendable {
    public var threads: [ThreadSummary]
    public let hasMore: Bool
    public let page: Int
    /// The page carries end-to-end rows (`fortress: true`).
    public let fortress: Bool

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        fortress = data["fortress"]?.boolValue ?? false
        threads = (data["threads"]?.arrayValue ?? []).compactMap(ThreadSummary.init(json:))
        hasMore = data["has_more"]?.boolValue ?? false
        page = data["page"]?.intValue ?? 1
    }
}

/// A non-inline attachment on a message. `url` is a short-lived signed
/// download URL when the bytes are file-backed, nil otherwise.
public struct MailAttachment: Identifiable, Equatable, Sendable {
    public let id: Int
    public var filename: String
    public var contentType: String
    public let sizeBytes: Int
    public let url: String?
    /// The IMAP part number; a Fortress part's AD is built from it.
    public let mimePart: String
    public let inline: Bool
    public var contentID: String = ""
    /// Stored as ciphertext: fetched from `url` and opened on this phone.
    public var sealed = false
    /// The message whose DEK opens it, and that row's AD prefix.
    public var messageID: Int = 0
    public var adPrefix: String = ""
    /// The row's sealed DEK, so a part opens after a lock cleared the key cache.
    public var sealedDek: String = ""

    init?(json: JSONValue) {
        guard let id = json["id"]?.intValue else { return nil }
        self.id = id
        filename = json["filename"]?.stringValue ?? "attachment"
        contentType = json["content_type"]?.stringValue ?? "application/octet-stream"
        sizeBytes = json["size_bytes"]?.intValue ?? 0
        let u = json["url"]
        url = (u?.isNull ?? true) ? nil : u?.stringValue
        mimePart = json["mime_part"]?.stringValue ?? ""
        inline = json["inline"]?.boolValue ?? false
    }

    /// A part's AD under its row's DEK.
    public var ad: String { "\(adPrefix)\(messageID):att:\(mimePart)" }

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
    /// The mailbox this message arrived through, `nil` for the superadmin
    /// "Unmatched" view. Resolves which mailbox's folder rail the Move/Labels
    /// control uses (the first message in a thread that has one).
    public let aliasID: Int?
    public var sender: String
    public var recipient: String
    public var subject: String
    public let receivedTime: String
    public var isRead: Bool
    public var isStarred: Bool
    public let direction: String
    public var bodyPlain: String
    public var bodyHTML: String
    public var attachments: [MailAttachment]
    public var to: String
    public var cc: String
    /// A Fortress message's ciphertext as sent; kept after opening, since the
    /// row key opens its parts for a download or a forward.
    public let sealed: SealedRow?
    public let fortress: Bool
    /// What stands in for the content when this phone cannot open it.
    public var placeholder: FortressNote?
    /// The body HTML as the sender wrote it (cid: references intact), for a
    /// reply's quote; `bodyHTML` has them resolved for display.
    public var bodyHTMLSource: String = ""
    /// Inline parts (cid: images) of an opened Fortress message.
    public var inlineParts: [MailAttachment] = []
    public var aiSummary: String = ""
    public var aiScan: JSONValue?
    public var rawHeaders: String = ""
    public let aiDangerScore: Int?
    public let receivedAuth: (spf: String, dkim: String, dmarc: String)

    public static func == (a: MailMessage, b: MailMessage) -> Bool {
        a.id == b.id && a.isRead == b.isRead && a.isStarred == b.isStarred && a.subject == b.subject
            && a.bodyPlain == b.bodyPlain && a.bodyHTML == b.bodyHTML && a.attachments == b.attachments
            && a.placeholder == b.placeholder
    }

    public var isOutbound: Bool { direction == "outbound" }

    init?(json: JSONValue) {
        guard let id = json["id"]?.intValue else { return nil }
        self.id = id
        aliasID = json["alias_id"]?.isNull == false ? json["alias_id"]?.intValue : nil
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
        to = json["to"]?.stringValue ?? ""
        cc = json["cc"]?.stringValue ?? ""
        sealed = SealedRow(json: json["sealed"])
        fortress = json["fortress"]?.boolValue ?? false
        let score = json["ai_danger_score"]
        aiDangerScore = (score?.isNull ?? true) ? nil : score?.intValue
        receivedAuth = (json["spf_result"]?.stringValue ?? "", json["dkim_result"]?.stringValue ?? "",
                        json["dmarc_result"]?.stringValue ?? "")
        bodyHTMLSource = bodyHTML
    }

    /// Reply and forward are offered on an opened message, never on a placeholder.
    public var canRespond: Bool { placeholder == nil }
}

/// The `mailbox/thread` payload: the in-scope messages plus the thread's
/// current folder/label memberships (ids into the mailbox's `folders`).
public struct MailThread: Equatable, Sendable {
    public var messages: [MailMessage]
    public let folderIDs: [Int]

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        messages = (data["messages"]?.arrayValue ?? []).compactMap(MailMessage.init(json:))
        folderIDs = (data["folders"]?.arrayValue ?? []).compactMap(\.intValue)
    }
}

// MARK: - Address + date display helpers

public enum MailDisplay {
    // The sender-label rules below mirror the web reader's helpers in
    // plugins/mailbox/assets/mailbox_reader.js — one mail surface, one label for the
    // same message. Change them together.

    /// Mail providers where the person is the identity and the domain says nothing:
    /// a bare address here falls back to the local part, not to "Gmail".
    static let consumerMailDomains: Set<String> = [
        "gmail", "googlemail", "outlook", "hotmail", "live", "msn",
        "yahoo", "ymail", "aol", "icloud", "me", "mac",
        "proton", "protonmail", "pm", "fastmail", "hey", "zoho",
        "gmx", "web", "mail", "yandex", "qq", "163", "126"
    ]

    /// Registry-ish second levels, so example.co.uk yields "example" and not "co".
    static let registrySecondLevels: Set<String> = [
        "co", "com", "net", "org", "edu", "gov", "ac", "or", "ne"
    ]

    /// Mailboxes no person owns. A role address is infrastructure, so its local part is
    /// never the identity — the sending organization is, even at a consumer provider:
    /// no-reply@notify.proton.me is Proton writing to you, not somebody named No-Reply.
    static let roleLocalParts: Set<String> = [
        "noreply", "donotreply", "notify", "notification", "notifications",
        "alert", "alerts", "bounce", "bounces", "postmaster",
        "mailerdaemon", "abuse", "webmaster", "root", "support",
        "help", "info", "billing", "sales", "admin", "contact"
    ]

    /// "jeremy.tunnell" → "Jeremy Tunnell", "e-trade" → "E-Trade".
    static func titleCase(_ label: String) -> String {
        let spaced = label
            .replacingOccurrences(of: "[._+]+", with: " ", options: .regularExpression)
            .replacingOccurrences(of: "\\s+", with: " ", options: .regularExpression)
            .trimmingCharacters(in: .whitespaces)
        var out = ""
        var atWordStart = true
        for ch in spaced {
            // ASCII-only, matching the web helper's /[a-z]/ — and one lowercase letter
            // can uppercase into two (ß → SS), which a Character could not hold.
            if atWordStart, ch.isASCII, ch.isLowercase {
                out += ch.uppercased()
            } else {
                out.append(ch)
            }
            atWordStart = (ch == " " || ch == "-")
        }
        return out
    }

    /// The host labels left after dropping the public suffix: accounts.google.com →
    /// ["accounts", "google"], mail.example.co.uk → ["mail", "example"].
    private static func registrableLabels(_ host: String) -> [String] {
        var parts = host.lowercased().split(separator: ".").map(String.init).filter { !$0.isEmpty }
        guard parts.count >= 2 else { return parts }
        parts.removeLast()                                              // the TLD
        if parts.count > 1, registrySecondLevels.contains(parts[parts.count - 1]) {
            parts.removeLast()                                          // a ccTLD's second level
        }
        return parts
    }

    /// The organization label out of a host: accounts.google.com → "google". Taking the
    /// LAST remaining label after the public suffix drops infrastructure subdomains.
    static func orgLabel(_ host: String) -> String {
        let parts = registrableLabels(host)
        if parts.count < 2 { return parts.first ?? "" }
        return parts.last ?? ""
    }

    /// True when the address sits BELOW a domain rather than at it (notify.proton.me vs
    /// proton.me). A personal mailbox is never at a subdomain of its provider, so this
    /// is what separates a provider's own outbound infrastructure from its users.
    static func hasSubdomain(_ host: String) -> Bool {
        registrableLabels(host).count > 1
    }

    /// A role mailbox by name: exact match on the punctuation-stripped local part, or a
    /// no-reply marker anywhere in it (AmericanExpress-no-reply, DOTServicesnoreply).
    static func isRoleLocalPart(_ local: String) -> Bool {
        let key = String(local.lowercased().filter { $0.isASCII && ($0.isLetter || $0.isNumber) })
        if roleLocalParts.contains(key) { return true }
        return key.contains("noreply") || key.contains("donotreply")
    }

    /// "Jane Doe <jane@x.com>" → "Jane Doe". With no display name the sending
    /// ORGANIZATION is the identity — hello@fireworks.ai reads as "Fireworks", not
    /// "hello" — except at a consumer mail provider, where the local part is the only
    /// identity there is. That exception holds only for what could actually be a
    /// person's mailbox: a role address, or one below the provider's own domain, is the
    /// company writing.
    public static func senderName(_ raw: String) -> String {
        let trimmed = raw.trimmingCharacters(in: .whitespaces)
        if trimmed.isEmpty { return "(unknown)" }
        if let lt = trimmed.firstIndex(of: "<") {
            let name = String(trimmed[..<lt])
                .trimmingCharacters(in: CharacterSet(charactersIn: " \"'"))
            if !name.isEmpty { return name }
        }
        let addr = address(trimmed)
        guard let at = addr.lastIndex(of: "@"), at != addr.startIndex else {
            let bare = addr.trimmingCharacters(in: CharacterSet(charactersIn: "<> "))
            return bare.isEmpty ? "(unknown)" : bare
        }
        let local = String(addr[addr.startIndex..<at])
        let host = String(addr[addr.index(after: at)...])
        let org = orgLabel(host)
        let asPerson = { titleCase(local).isEmpty ? local : titleCase(local) }
        if org.isEmpty { return asPerson() }
        let personal = consumerMailDomains.contains(org)
            && !hasSubdomain(host)
            && !isRoleLocalPart(local)
        return personal ? asPerson() : titleCase(org)
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


// MARK: - End-to-end (Fortress) rows

/// A row's sealed columns as the server stores them
/// (`InboundEmailMessage::sealedForBrowser`): `{key, sealed_scope, sealed_dek,
/// sealed_ad_prefix, iem_*}` plus the flags the reader acts on. Every `iem_*`
/// value is a `v1.edge.` field under the row's DEK, AD `{prefix}{key}:{column}`.
public struct SealedRow: Equatable, Sendable {
    public let key: Int
    public let scope: String
    public let sealedDek: String
    public let adPrefix: String
    public let fields: [String: String]
    /// Relay-sealed and not parsed yet: the first device that opens it parses it.
    public let pending: Bool
    /// Sealed to a key the owner's vault does not hold.
    public let unopenable: Bool
    /// Another person's row (an all-access viewer): only the owner's devices open it.
    public let foreign: Bool

    public init?(json: JSONValue?) {
        guard let json, case .object(let pairs) = json else { return nil }
        key = json["key"]?.intValue ?? 0
        scope = json["sealed_scope"]?.stringValue ?? "mail"
        sealedDek = json["sealed_dek"]?.stringValue ?? ""
        adPrefix = json["sealed_ad_prefix"]?.stringValue ?? "mail:"
        var f: [String: String] = [:]
        for (k, v) in pairs where k.hasPrefix("iem_") {
            if let s = v.stringValue { f[k] = s }
        }
        fields = f
        pending = json["pending"]?.boolValue ?? false
        unopenable = json["unopenable"]?.boolValue ?? false
        foreign = json["foreign"]?.boolValue ?? false
    }

    public func ad(_ column: String) -> String { "\(adPrefix)\(key):\(column)" }
}

/// Why a Fortress row shows a placeholder instead of its content — the
/// browser's notes, word for word where they apply to a phone.
public enum FortressNote: String, Equatable, Sendable {
    case locked = "End-to-end encrypted. Unlock to read it."
    case noKey = "Encrypted on your other devices."
    case pending = "Waiting to be opened."
    case pendingElsewhere = "Waiting to be opened on a computer."
    case failed = "This message could not be opened on this device."
    case foreign = "End-to-end encrypted. Only the mailbox owner's devices can open it."
    case unopenable = "This message arrived sealed to a key your vault does not hold, so it cannot be opened. You can delete it."
}
