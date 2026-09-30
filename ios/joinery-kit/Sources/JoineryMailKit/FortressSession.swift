import Foundation
import CryptoKit
import JoineryKit

/// The phone's half of end-to-end (Fortress) mail — the mirror of the
/// browser's `mailbox_fortress.js` (specs/fortress_mobile_apps.md § R4, R6).
///
/// A Fortress row arrives from `thread_list` and `thread` with its clear content
/// empty and `sealed` carrying its columns as stored. Only the owner's mail
/// vault opens the row's DEK, so everything readable is made here, in memory:
///
///   openList(page)    fills each row's subject, sender, snippet (and the AI summary);
///   openThread(t)     fills each message's headers and bodies, names its parts
///                     from the sealed manifest, and turns inline cid: images
///                     into data: URLs (image types, 5 MB cap);
///   partBytes(att)    fetches a part's stored ciphertext through its signed URL
///                     and opens it under the row DEK with the part's AD;
///   sourceOpen / sourceFiles   what a reply quotes and a forward re-attaches.
///
/// Nothing here unlocks on its own: `unlock()` does, with one biometric prompt.
/// The key is dropped by HeldKeys (background idle, sign-out, 401, retirement);
/// a drop clears every row key cached here too.
@MainActor
public final class FortressSession: ObservableObject {
    public static let scope = "mail"
    static let inlineImageTypes: Set<String> = ["image/png", "image/jpeg", "image/gif", "image/webp",
                                                "image/avif", "image/bmp"]
    static let inlineMaxBytes = 5 * 1024 * 1024

    public let vault: DeviceVault
    let client: APIClient
    let session: SessionController
    private var rowKeys: [Int: SymmetricKey] = [:]
    /// Bumped by every lock; work that started before a lock checks it before
    /// it seals or posts anything.
    @Published public private(set) var lockEpoch = 0

    public init(session: SessionController) {
        self.session = session
        self.client = session.client
        self.vault = DeviceVault.shared(session: session, scope: Self.scope)
        session.heldKeys.onLock { [weak self] _ in
            Task { @MainActor in self?.wipe() }
        }
    }

    public var isOpen: Bool { vault.heldSecret != nil }

    /// Open the mail key (one biometric prompt). True when open.
    @discardableResult
    public func unlock() async throws -> Bool {
        _ = try await vault.openSecret(reason: "Open your end-to-end encrypted mail")
        return isOpen
    }

    public func lock() { vault.lock() }

    private func wipe() {
        rowKeys = [:]
        lockEpoch += 1
    }

    // MARK: Row keys

    func rowKey(messageID: Int, sealedDek: String) throws -> SymmetricKey {
        if let k = rowKeys[messageID] { return k }
        guard let secret = vault.heldSecret else { throw FortressError.locked }
        let k = try VaultCrypto.openDek(sealedDek, scope: Self.scope, secret: secret)
        rowKeys[messageID] = k
        return k
    }

    func rowKey(_ row: SealedRow) throws -> SymmetricKey {
        try rowKey(messageID: row.key, sealedDek: row.sealedDek)
    }

    /// Every `v1.edge.` column of a row opened; bare values pass through.
    func open(_ row: SealedRow) throws -> [String: String] {
        let key = try rowKey(row)
        var out: [String: String] = [:]
        for (col, value) in row.fields {
            out[col] = try VaultCrypto.openField(value, key: key, ad: row.ad(col))
        }
        return out
    }

    /// The note a row shows when it cannot be opened here, before any attempt.
    func note(for row: SealedRow) -> FortressNote? {
        if row.foreign { return .foreign }
        if row.unopenable { return .unopenable }
        if vault.status == .notEnrolled { return row.pending ? .pendingElsewhere : .noKey }
        if row.pending { return .pending }
        if !isOpen { return .locked }
        return nil
    }

    // MARK: The list

    /// Fill every Fortress row of a `thread_list` page, in place.
    public func openList(_ page: ThreadPage) -> ThreadPage {
        var page = page
        for i in page.threads.indices {
            guard let row = page.threads[i].sealed else { continue }
            if let n = note(for: row) {
                Self.placeholder(&page.threads[i], n)
                continue
            }
            do {
                let o = try open(row)
                page.threads[i].subject = o["iem_subject"] ?? ""
                page.threads[i].sender = o["iem_sender"] ?? ""
                page.threads[i].snippet = o["iem_snippet"] ?? ""
                page.threads[i].aiSummary = o["iem_ai_summary"] ?? ""
                page.threads[i].placeholder = nil
            } catch {
                Self.placeholder(&page.threads[i], .failed)
            }
        }
        return page
    }

    static func placeholder(_ t: inout ThreadSummary, _ note: FortressNote) {
        t.subject = ""
        t.sender = "Encrypted"
        t.snippet = note.rawValue
        t.placeholder = note
    }

    // MARK: A thread

    public func openThread(_ thread: MailThread) async -> MailThread {
        var thread = thread
        for i in thread.messages.indices {
            guard let row = thread.messages[i].sealed else { continue }
            if let n = note(for: row) {
                Self.placeholder(&thread.messages[i], n)
                continue
            }
            do {
                try await openMessage(&thread.messages[i], row: row)
            } catch {
                Self.placeholder(&thread.messages[i], .failed)
            }
        }
        return thread
    }

    static func placeholder(_ m: inout MailMessage, _ note: FortressNote) {
        m.bodyPlain = note.rawValue
        m.bodyHTML = ""
        m.bodyHTMLSource = ""
        m.placeholder = note
        m.attachments = m.attachments.filter { !$0.inline }.map { a in
            var a = a
            a.filename = "Encrypted attachment"
            a.contentType = "application/octet-stream"
            return a
        }
    }

    func openMessage(_ m: inout MailMessage, row: SealedRow) async throws {
        let o = try open(row)
        m.sender = o["iem_sender"] ?? ""
        m.subject = o["iem_subject"] ?? ""
        m.bodyPlain = o["iem_body_plain"] ?? ""
        m.bodyHTML = o["iem_body_html"] ?? ""
        m.to = o["iem_to"] ?? ""
        m.cc = o["iem_cc"] ?? ""
        if let r = o["iem_recipient"], !r.isEmpty { m.recipient = r }
        m.aiSummary = o["iem_ai_summary"] ?? ""
        m.aiScan = (o["iem_ai_scan"]).flatMap { try? JSONValue.parse($0) }
        m.rawHeaders = o["iem_raw_headers"] ?? ""
        m.placeholder = nil

        let manifest = Self.manifest(o["iem_attachment_manifest"])
        var byID: [String: JSONValue] = [:]
        var byPart: [String: JSONValue] = [:]
        for e in manifest {
            if let id = e["id"], !id.isNull, let s = id.stringValue { byID[s] = e }
            // A device-parsed row names its parts by number: it sealed the
            // manifest before the server gave them ids.
            else if let p = e["mime_part"]?.stringValue, !p.isEmpty { byPart[p] = e }
        }
        let parts: [MailAttachment] = m.attachments.map { a in
            var a = a
            let e = byID[String(a.id)] ?? byPart[a.mimePart]
            a.filename = e?["filename"]?.stringValue.flatMap { $0.isEmpty ? nil : $0 } ?? "attachment"
            a.contentType = e?["content_type"]?.stringValue.flatMap { $0.isEmpty ? nil : $0 } ?? "application/octet-stream"
            a.contentID = e?["content_id"]?.stringValue ?? ""
            a.sealed = true
            a.messageID = m.id
            a.adPrefix = row.adPrefix
            a.sealedDek = row.sealedDek
            return a
        }
        m.attachments = parts.filter { !$0.inline }
        let inline = parts.filter { $0.inline && !$0.contentID.isEmpty }
        m.bodyHTMLSource = m.bodyHTML
        m.inlineParts = inline
        if !m.bodyHTML.isEmpty && !inline.isEmpty {
            m.bodyHTML = await inlineRewrite(inline, html: m.bodyHTML)
        }
    }

    nonisolated static func manifest(_ json: String?) -> [JSONValue] {
        guard let json, !json.isEmpty, let v = try? JSONValue.parse(json) else { return [] }
        return v.arrayValue ?? []
    }

    // MARK: Parts

    /// One part's plaintext: its stored ciphertext through the signed URL,
    /// opened under the row DEK with the part's AD. Never written to disk here.
    public func partBytes(_ att: MailAttachment) async throws -> Data {
        guard att.sealed else { throw FortressError.message("This part is not sealed for this device.") }
        // From the cache, or from the row's sealed DEK (a lock cleared the cache
        // while the thread stayed on screen, F18).
        let key = try rowKey(messageID: att.messageID, sealedDek: att.sealedDek)
        guard let url = att.url else {
            throw FortressError.message("This attachment could not be fetched.")
        }
        let epoch = lockEpoch
        let stored = try await client.fetchBytes(url)
        guard epoch == lockEpoch else { throw FortressError.locked }
        return try VaultCrypto.openEdgeBytes(String(decoding: stored, as: UTF8.self), key: key, ad: att.ad)
    }

    /// cid: references → data: URLs of the opened inline images.
    func inlineRewrite(_ inline: [MailAttachment], html: String) async -> String {
        var map: [String: String] = [:]
        for a in inline {
            let type = a.contentType.lowercased()
            guard Self.inlineImageTypes.contains(type), a.sizeBytes <= Self.inlineMaxBytes else { continue }
            if let bytes = try? await partBytes(a) {
                map[Self.bareCid(a.contentID)] = "data:\(type);base64," + bytes.base64EncodedString()
            }
        }
        return Self.rewriteCids(html, map: map)
    }

    nonisolated static func bareCid(_ cid: String) -> String {
        var c = cid
        if c.hasPrefix("<") { c.removeFirst() }
        if c.hasSuffix(">") { c.removeLast() }
        return c
    }

    /// `cid:ID` → map[ID] wherever it appears; unknown ids stay as they are.
    nonisolated static func rewriteCids(_ html: String, map: [String: String]) -> String {
        guard !map.isEmpty, let re = try? NSRegularExpression(pattern: "cid:([^\"'\\s>]+)", options: [.caseInsensitive])
        else { return html }
        let ns = html as NSString
        var out = ""
        var last = 0
        for m in re.matches(in: html, range: NSRange(location: 0, length: ns.length)) {
            out += ns.substring(with: NSRange(location: last, length: m.range.location - last))
            let id = ns.substring(with: m.range(at: 1))
            let key = bareCid(id.removingPercentEncoding ?? id)
            out += map[key] ?? ns.substring(with: m.range)
            last = m.range.location + m.range.length
        }
        out += ns.substring(from: last)
        return out
    }

    // MARK: Compose (§ R6)

    /// What a reply or forward quotes of an opened end-to-end message, for
    /// `mailbox/send`'s `source_open`. Nil when the message is not open here.
    public func sourceOpen(_ m: MailMessage) -> JSONValue? {
        guard m.sealed != nil, m.placeholder == nil else { return nil }
        return .object([
            (key: "sender", value: .string(m.sender)),
            (key: "subject", value: .string(m.subject)),
            (key: "recipient", value: .string(m.recipient)),
            (key: "body_html", value: .string(m.bodyHTMLSource)),
            (key: "body_plain", value: .string(m.bodyPlain)),
        ])
    }

    /// An opened end-to-end message's parts for a forward: the files, and the
    /// inline ones named uniquely and keyed by Content-ID (the inline manifest
    /// the server turns into fresh Content-IDs in the quote).
    public func sourceFiles(_ m: MailMessage) async throws -> (files: [MailOutgoingAttachment], inline: [String: String]) {
        guard m.sealed != nil, m.placeholder == nil else { return ([], [:]) }
        var files: [MailOutgoingAttachment] = []
        var inline: [String: String] = [:]
        for a in m.attachments where a.sealed {
            files.append(MailOutgoingAttachment(filename: a.filename, mimeType: a.contentType, data: try await partBytes(a)))
        }
        for (j, p) in m.inlineParts.enumerated() {
            let cid = Self.bareCid(p.contentID)
            guard !cid.isEmpty else { continue }
            let safe = String(p.filename.map { $0.isASCII && ($0.isLetter || $0.isNumber || "._-".contains($0)) ? $0 : "_" })
            let name = "fwdinl\(j)-" + (safe.isEmpty ? "image" : safe)
            files.append(MailOutgoingAttachment(filename: name, mimeType: p.contentType, data: try await partBytes(p)))
            inline[cid] = name
        }
        return (files, inline)
    }

    /// The key new material seals to: the pending key during a rotation, else
    /// the current one — asked of the server each time.
    public func sealingPublicKey() async throws -> String {
        let p = try await vault.probe()
        guard p.setUp == true, let key = p.pendingPublicKey ?? p.publicKey else {
            throw FortressError.message("Set up your vault on a computer to write from an end-to-end encrypted mailbox.")
        }
        return key
    }

    /// The snippet the server derives for a Fortress row.
    nonisolated public static func snippet(_ plain: String) -> String {
        let head = String(plain.unicodeScalars.prefix(4000).map(Character.init))
        let folded = head.split(whereSeparator: { $0.isWhitespace }).joined(separator: " ")
        return String(folded.unicodeScalars.prefix(240).map(Character.init))
    }
}

public enum FortressError: LocalizedError, Equatable {
    case locked
    case message(String)

    public var errorDescription: String? {
        switch self {
        case .locked: return "Unlock your mail to open this."
        case .message(let m): return m
        }
    }
}
