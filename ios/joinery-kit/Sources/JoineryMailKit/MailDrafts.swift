import Foundation
import CryptoKit
import JoineryKit

/// Drafts, at every level (specs/fortress_mobile_apps.md § R12), the same
/// server calls the web reader makes.
///
/// Standard and Private: `draft_save` with the plain columns and any new files
/// as uploads; the server seals a Private draft itself. A send with `draft_id`
/// morphs the draft into the Sent row and reuses its saved parts.
///
/// Fortress: the browser's two-step save (client_custody_mail.md § WP4). The
/// first save posts the clear columns and gets `{draft_id, id,
/// sealed_ad_prefix}`; every save after it posts the eleven sealed columns of
/// `MailboxDrafts::FORTRESS_DRAFT_FIELDS` under one DEK for the draft's life,
/// that DEK sealed to the vault's current (or pending) public key, and `keep`
/// (authoritative). A new part is sealed here under the draft DEK with AD
/// `{prefix}{id}:att:{part}`, named `draft:` + 24 hex, and posted as its own
/// request after the text (the browser's B7 shape). The server opens nothing.
public struct DraftPart: Equatable, Sendable, Identifiable {
    public var id: Int?
    public let mimePart: String
    public let filename: String
    public let contentType: String
    public let contentID: String
    public let inline: Bool
    public let size: Int
    public var url: String?
}

/// What the compose sheet holds, in the shape a draft stores.
public struct DraftContent: Equatable, Sendable {
    public var aliasID: Int?
    public var mode: MailAPI.ComposeMode
    public var sourceID: Int?
    public var sender: String = ""
    public var to: String = ""
    public var cc: String = ""
    public var bcc: String = ""
    public var subject: String = ""
    public var body: String = ""

    public init(aliasID: Int?, mode: MailAPI.ComposeMode, sourceID: Int?) {
        self.aliasID = aliasID
        self.mode = mode
        self.sourceID = sourceID
    }
}

@MainActor
public final class DraftSession {
    public private(set) var draftID: Int?
    public let fortress: Bool
    /// Saved parts (Fortress: what the server holds, opened on demand).
    public private(set) var parts: [DraftPart] = []
    /// Saved regular attachments of a plain draft (server-held).
    public private(set) var savedAttachments: [MailAttachment] = []
    private var adPrefix = ""
    private var dek: SymmetricKey?
    private let api: MailAPI
    private let fortressSession: FortressSession?
    private var saving: Task<Void, Error>?

    static let fortressFields = ["iem_sender", "iem_recipient", "iem_subject", "iem_body_plain", "iem_body_html",
                                 "iem_to", "iem_cc", "iem_bcc", "iem_draft_state", "iem_snippet",
                                 "iem_attachment_manifest"]

    public init(api: MailAPI, fortress: FortressSession?, isFortress: Bool, draftID: Int? = nil) {
        self.api = api
        self.fortressSession = fortress
        self.fortress = isFortress
        self.draftID = draftID
    }

    // MARK: Save

    /// Which local attachment each uploaded Fortress part holds (by the
    /// attachment's own identity, never its filename — two photo.jpg are two
    /// parts, F20).
    private var uploaded: [UUID: String] = [:]

    /// Which local attachments go up now, and which saved parts stay: by the
    /// attachment's identity, so two files of one name are two parts.
    nonisolated static func partition(files: [MailOutgoingAttachment], uploaded: [UUID: String],
                                      keepSaved: [String]) -> (fresh: [MailOutgoingAttachment], keep: [String]) {
        (files.filter { uploaded[$0.id] == nil }, keepSaved + files.compactMap { uploaded[$0.id] })
    }

    /// Save the draft. Plain: `files` are the new files to upload (a removed
    /// saved attachment is deleted at once with `removeSaved`). Fortress:
    /// `files` are every local attachment now on the sheet — the ones not yet
    /// uploaded go up, the others are kept — and `keepSaved` names the parts
    /// saved in an earlier session still wanted. Saves run one at a time.
    public func save(_ c: DraftContent, files: [MailOutgoingAttachment] = [], keepSaved: [String] = []) async throws {
        let previous = saving
        let task = Task { @MainActor in
            _ = try? await previous?.value
            if fortress {
                let plan = Self.partition(files: files, uploaded: uploaded, keepSaved: keepSaved)
                try await saveFortress(c, newFiles: plan.fresh, keep: plan.keep)
            } else {
                try await savePlain(c, newFiles: files)
            }
        }
        saving = task
        try await task.value
    }

    private func savePlain(_ c: DraftContent, newFiles: [MailOutgoingAttachment]) async throws {
        var text: [(key: String, value: String)] = [
            (key: "mode", value: c.mode.rawValue),
            (key: "to", value: c.to), (key: "cc", value: c.cc), (key: "bcc", value: c.bcc),
            (key: "subject", value: c.subject), (key: "body", value: c.body),
        ]
        if let a = c.aliasID { text.append((key: "alias_id", value: String(a))) }
        if let s = c.sourceID { text.append((key: "source_id", value: String(s))) }
        if let d = draftID { text.append((key: "draft_id", value: String(d))) }
        let files = newFiles.map {
            MultipartFile(field: "attachments[]", filename: $0.filename, mimeType: $0.mimeType, data: $0.data)
        }
        let data = try await api.multipart("mailbox/draft_save", text, files: files)
        if data["locked"]?.boolValue == true {
            throw FortressError.message(data["message"]?.stringValue ?? "Unlock your vault to save this draft.")
        }
        if let id = data["draft_id"]?.intValue { draftID = id }
        savedAttachments = (data["attachments"]?.arrayValue ?? []).compactMap(MailAttachment.init(json:))
    }

    public func removeSaved(_ attachment: MailAttachment) async {
        guard let draftID else { return }
        _ = try? await api.action("mailbox/draft_attachment_delete", [
            (key: "draft_id", value: .number(Double(draftID))),
            (key: "attachment_id", value: .number(Double(attachment.id))),
        ])
        savedAttachments.removeAll { $0.id == attachment.id }
    }

    private func clearFields(_ c: DraftContent) -> [(key: String, value: String)] {
        var f: [(key: String, value: String)] = [
            (key: "fortress", value: "1"),
            (key: "alias_id", value: c.aliasID.map(String.init) ?? ""),
            (key: "mode", value: c.mode.rawValue),
            (key: "source_id", value: c.sourceID.map(String.init) ?? ""),
        ]
        if let d = draftID { f.append((key: "draft_id", value: String(d))) }
        return f
    }

    private func saveFortress(_ c: DraftContent, newFiles: [MailOutgoingAttachment], keep: [String]) async throws {
        guard let fs = fortressSession, fs.isOpen else { throw FortressError.locked }
        let epoch = fs.lockEpoch
        if draftID == nil {
            let first = try await api.multipart("mailbox/draft_save", clearFields(c), files: [])
            guard let id = first["draft_id"]?.intValue else { throw JoineryAPIError.malformedResponse }
            draftID = id
            adPrefix = first["sealed_ad_prefix"]?.stringValue ?? "mail:"
        }
        if dek == nil { dek = VaultCrypto.newDek() }
        let publicKey = try await fs.sealingPublicKey()
        let keepSet = Set(keep)
        parts = parts.filter { keepSet.contains($0.mimePart) }
        // Text first, then one request per new part: a part that fails leaves
        // the text saved and says so.
        try await postFortress(c, publicKey: publicKey, upload: nil, epoch: epoch)
        for file in newFiles {
            let name = "draft:" + VaultCrypto.randomHex(12)
            let part = DraftPart(id: nil, mimePart: name, filename: file.filename, contentType: file.mimeType,
                                 contentID: "", inline: false, size: file.data.count)
            try await postFortress(c, publicKey: publicKey, upload: (part, file.data), epoch: epoch)
            if parts.contains(where: { $0.mimePart == name }) { uploaded[file.id] = name }
        }
    }

    private func postFortress(_ c: DraftContent, publicKey: String,
                              upload: (DraftPart, Data)?, epoch: Int) async throws {
        guard let dek, let draftID, let fs = fortressSession else { throw FortressError.locked }
        var all = parts
        if let upload { all.append(upload.0) }
        let manifest: String = all.isEmpty ? "" : JSONValue.array(all.map { p in
            .object([
                (key: "mime_part", value: .string(p.mimePart)), (key: "filename", value: .string(p.filename)),
                (key: "content_type", value: .string(p.contentType)), (key: "content_id", value: .string(p.contentID)),
                (key: "inline", value: .bool(p.inline)), (key: "size", value: .number(Double(p.size))),
            ])
        }).encoded()
        let state = JSONValue.object([
            (key: "mode", value: .string(c.mode.rawValue)),
            (key: "source_id", value: .number(Double(c.sourceID ?? 0))),
            (key: "to", value: .string(c.to)), (key: "cc", value: .string(c.cc)),
        ]).encoded()
        let values: [String: String] = [
            "iem_sender": c.sender,
            "iem_recipient": [c.to, c.cc].filter { !$0.isEmpty }.joined(separator: ", "),
            "iem_to": c.to, "iem_cc": c.cc, "iem_bcc": c.bcc,
            "iem_subject": c.subject, "iem_body_html": "", "iem_body_plain": c.body,
            "iem_draft_state": state,
            "iem_snippet": FortressSession.snippet(c.body),
            "iem_attachment_manifest": manifest,
        ]
        var fields: [(key: String, value: JSONValue)] = []
        for col in Self.fortressFields {
            fields.append((key: col, value: .string(try VaultCrypto.sealField(values[col] ?? "", key: dek,
                                                                                 ad: "\(adPrefix)\(draftID):\(col)"))))
        }
        var text = clearFields(c)
        text.append((key: "sealed_dek", value: try VaultCrypto.sealDek(dek, scope: FortressSession.scope,
                                                                       toPublicKey: publicKey)))
        text.append((key: "public_key", value: publicKey))
        text.append((key: "fields", value: JSONValue.object(fields).encoded()))
        var files: [MultipartFile] = []
        var uploads: [JSONValue] = []
        if let (part, data) = upload {
            let sealed = try VaultCrypto.sealEdgeBytes(data, key: dek, ad: "\(adPrefix)\(draftID):att:\(part.mimePart)")
            files.append(MultipartFile(field: "attachments[]", filename: part.mimePart,
                                       mimeType: "application/octet-stream", data: Data(sealed.utf8)))
            uploads.append(.object([(key: "mime_part", value: .string(part.mimePart)),
                                    (key: "size", value: .number(Double(part.size))),
                                    (key: "inline", value: .bool(false))]))
        }
        text.append((key: "parts", value: JSONValue.array(uploads).encoded()))
        text.append((key: "keep", value: JSONValue.array(all.map { .string($0.mimePart) }).encoded()))
        // Nothing is posted once the vault shut under this save.
        guard epoch == fs.lockEpoch else { throw FortressError.locked }
        let answer = try await api.multipart("mailbox/draft_save", text, files: files)
        var ids: [String: Int] = [:]
        var urls: [String: String] = [:]
        for p in answer["parts"]?.arrayValue ?? [] {
            if let mp = p["mime_part"]?.stringValue, let id = p["id"]?.intValue { ids[mp] = id }
            if let mp = p["mime_part"]?.stringValue, let u = p["url"]?.stringValue { urls[mp] = u }
        }
        // What the server holds after this save, and nothing else.
        parts = all.compactMap { p in
            guard let id = ids[p.mimePart] else { return nil }
            var p = p
            p.id = id
            p.url = urls[p.mimePart] ?? p.url
            return p
        }
    }

    // MARK: Open

    /// A `draft_get` answer turned into compose content; Fortress drafts are
    /// opened here with the DEK sealed to the mail key.
    public func open(_ data: JSONValue) async throws -> DraftContent {
        draftID = data["draft_id"]?.intValue ?? draftID
        let alias = data["alias_id"]?.intValue
        if data["locked"]?.boolValue == true {
            throw FortressError.message("Unlock your vault on a computer to open this draft.")
        }
        guard data["fortress"]?.boolValue == true else {
            var c = DraftContent(aliasID: alias, mode: MailAPI.ComposeMode(rawValue: data["mode"]?.stringValue ?? "new") ?? .new,
                                 sourceID: data["source_id"]?.intValue.flatMap { $0 > 0 ? $0 : nil })
            c.to = data["to"]?.stringValue ?? ""
            c.cc = data["cc"]?.stringValue ?? ""
            c.bcc = data["bcc"]?.stringValue ?? ""
            c.subject = data["subject"]?.stringValue ?? ""
            c.body = Self.plainText(fromHTML: data["body_html"]?.stringValue ?? "")
            savedAttachments = (data["attachments"]?.arrayValue ?? []).compactMap(MailAttachment.init(json:))
            return c
        }
        adPrefix = data["sealed_ad_prefix"]?.stringValue ?? "mail:"
        var c = DraftContent(aliasID: alias, mode: .new, sourceID: nil)
        guard let sealed = data["sealed"], !sealed.isNull, let fs = fortressSession else { return c }
        guard let secret = fs.vault.heldSecret else { throw FortressError.locked }
        let dekKey = try VaultCrypto.openDek(sealed["sealed_dek"]?.stringValue ?? "", scope: FortressSession.scope,
                                             secret: secret)
        dek = dekKey
        let id = draftID ?? 0
        // Any column that will not open fails the whole open: shown blank, the
        // next autosave would overwrite the stored draft with empties (F21).
        func openCol(_ col: String) throws -> String {
            do {
                return try VaultCrypto.openField(sealed[col]?.stringValue, key: dekKey, ad: "\(adPrefix)\(id):\(col)")
            } catch {
                throw FortressError.message("This draft could not be opened on this phone. It is left as it was.")
            }
        }
        let stateText = try openCol("iem_draft_state")
        let state = stateText.isEmpty ? .object([]) : ((try? JSONValue.parse(stateText)) ?? .object([]))
        c.mode = MailAPI.ComposeMode(rawValue: state["mode"]?.stringValue ?? "new") ?? .new
        c.sourceID = state["source_id"]?.intValue.flatMap { $0 > 0 ? $0 : nil }
        c.to = state["to"]?.stringValue ?? ""
        c.cc = state["cc"]?.stringValue ?? ""
        c.bcc = try openCol("iem_bcc")
        c.subject = try openCol("iem_subject")
        let plain = try openCol("iem_body_plain")
        let html = try openCol("iem_body_html")
        c.body = plain.isEmpty ? Self.plainText(fromHTML: html) : plain
        var named: [String: JSONValue] = [:]
        for e in FortressSession.manifest(try openCol("iem_attachment_manifest")) {
            if let mp = e["mime_part"]?.stringValue { named[mp] = e }
        }
        // A draftinl: part made on a computer shows as an attachment here: the
        // phone composes plain text.
        parts = (data["parts"]?.arrayValue ?? []).compactMap { p in
            guard let mp = p["mime_part"]?.stringValue else { return nil }
            let e = named[mp]
            return DraftPart(id: p["id"]?.intValue, mimePart: mp,
                             filename: e?["filename"]?.stringValue ?? "attachment",
                             contentType: e?["content_type"]?.stringValue ?? "application/octet-stream",
                             contentID: e?["content_id"]?.stringValue ?? "",
                             inline: false, size: p["size_bytes"]?.intValue ?? 0,
                             url: p["url"]?.stringValue)
        }
        return c
    }

    /// The saved Fortress parts named in `keep`, opened, for a send.
    public func fortressFiles(keep: [String]) async throws -> [MailOutgoingAttachment] {
        guard let dek, let draftID, let fs = fortressSession else { return [] }
        var out: [MailOutgoingAttachment] = []
        for p in parts where keep.contains(p.mimePart) {
            guard let url = p.url else {
                throw FortressError.message("\"\(p.filename)\" is saved on this draft but cannot be fetched here. Send it from a computer.")
            }
            let stored = try await fs.client.fetchBytes(url)
            let data = try VaultCrypto.openEdgeBytes(String(decoding: stored, as: UTF8.self), key: dek,
                                                     ad: "\(adPrefix)\(draftID):att:\(p.mimePart)")
            out.append(MailOutgoingAttachment(filename: p.filename, mimeType: p.contentType, data: data))
        }
        return out
    }

    public func delete() async {
        guard let draftID else { return }
        _ = try? await api.action("mailbox/draft_delete", [(key: "draft_id", value: .number(Double(draftID)))])
        self.draftID = nil
    }

    /// Drop the draft's key and the bytes held for it (the lock, a closed sheet).
    public func forgetKeys() {
        dek = nil
    }

    /// A computer's rich draft read as plain text on the phone.
    static func plainText(fromHTML html: String) -> String {
        guard !html.isEmpty else { return "" }
        var s = html
        for (pattern, replacement) in [("(?i)<br\\s*/?>", "\n"), ("(?i)</p>", "\n\n"), ("(?i)</div>", "\n"),
                                       ("<[^>]+>", "")] {
            s = s.replacingOccurrences(of: pattern, with: replacement, options: .regularExpression)
        }
        for (entity, char) in [("&nbsp;", " "), ("&lt;", "<"), ("&gt;", ">"), ("&quot;", "\""), ("&#39;", "'"), ("&amp;", "&")] {
            s = s.replacingOccurrences(of: entity, with: char)
        }
        return s.trimmingCharacters(in: .whitespacesAndNewlines)
    }
}
