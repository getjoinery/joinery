import SwiftUI
import Combine
import PhotosUI
import UniformTypeIdentifiers
import UIKit
import JoineryKit

/// Reply / reply-all / forward / new-message compose. Deliberately lean: the
/// server is the authority on quoting, subject normalization (Re:/Fwd:),
/// threading headers, and the sending identity (for a new message, the
/// picked mailbox) — this sheet collects recipients and the new text. A
/// forward re-attaches the original server-side; new uploads (any mode)
/// attach here via Photo Library / Files, mirroring the AI chat composer's
/// attach flow (specs/implemented/inbound_email_compose_attachments.md,
/// specs/implemented/inbound_email_new_message_compose.md).
///
/// End-to-end (specs/fortress_mobile_apps.md § R6): the server cannot read a
/// Fortress source, so a reply posts what this phone opened of it
/// (`source_open`) and a forward re-attaches its opened parts. The text is
/// autosaved as a draft three seconds after the last edit and on leaving
/// (§ R12); a Fortress draft is sealed here. The sending lock answers
/// `{locked: true}`: the sheet stays open and says to send from a computer (B2).
struct ComposeSheet: View {
    let api: MailAPI
    let request: ComposeRequest
    /// The viewer's granted mailboxes — only consulted in `.new` mode, for the
    /// From picker (reply/forward keep their implicit source-derived identity).
    let mailboxes: [Mailbox]
    var fortress: FortressSession? = nil
    let onSent: () -> Void

    @State private var to: String
    @State private var cc: String = ""
    @State private var subject: String
    @State private var fromAlias: Int?
    @State private var bodyText = ""
    @State private var attachments: [MailOutgoingAttachment] = []
    @State private var showPhotoPicker = false
    @State private var photoSelection: [PhotosPickerItem] = []
    @State private var showFileImporter = false
    @State private var isSending = false
    @State private var failure: String?
    @State private var draft: DraftSession?
    @State private var restoredParts: [DraftPart] = []
    @State private var savedPlain: [MailAttachment] = []
    @State private var autosave: Task<Void, Never>?
    @State private var draftNote: String?
    @State private var restoring = false
    @State private var restoreFailed = false
    @State private var sent = false
    @State private var lockEpochAtOpen = 0
    /// A reopened reply or forward draft: its own mode and source.
    @State private var restoredMode: MailAPI.ComposeMode?
    @State private var restoredSource: MailMessage?
    @State private var restoredSourceID: Int?
    @Environment(\.dismiss) private var dismiss
    @FocusState private var bodyFocused: Bool

    /// Preflight only — mirrors the server's real caps
    /// (`MailboxSender::MAX_UPLOAD_FILES/MAX_UPLOAD_BYTES/MAX_TOTAL_BYTES`); the
    /// server remains the authority and re-validates every file and the total.
    private static let maxAttachments = 10
    private static let maxAttachmentBytes = 10_485_760
    private static let maxTotalBytes = 26_214_400

    /// No `accept` filter on Files — email legitimately carries arbitrary file
    /// types, matching the server's no-allowlist policy.
    private static let documentTypes: [UTType] = [.item]

    /// Image UTTypes the server accepts as-is; anything else the Photos picker
    /// hands back (notably HEIC, the iPhone default) is transcoded to JPEG.
    private static let directImageTypes: Set<String> = [
        UTType.png.identifier, UTType.jpeg.identifier, UTType.gif.identifier,
        UTType.webP.identifier,
    ]

    init(api: MailAPI, request: ComposeRequest, mailboxes: [Mailbox] = [],
         preselectedAlias: Int? = nil, fortress: FortressSession? = nil, onSent: @escaping () -> Void) {
        self.api = api
        self.request = request
        self.mailboxes = mailboxes
        self.fortress = fortress
        self.onSent = onSent

        switch request.mode {
        case .reply, .replyAll:
            if let source = request.source {
                // Replying to your own outbound message goes back to its
                // recipient; otherwise to the sender.
                let target = source.isOutbound ? source.recipient : source.sender
                _to = State(initialValue: MailDisplay.address(target))
                _subject = State(initialValue: Self.prefixed(source.subject, "Re:"))
            } else {
                _to = State(initialValue: "")
                _subject = State(initialValue: "")
            }
            _fromAlias = State(initialValue: nil)
        case .forward:
            _to = State(initialValue: "")
            _subject = State(initialValue: Self.prefixed(request.source?.subject ?? "", "Fwd:"))
            _fromAlias = State(initialValue: nil)
        case .new:
            _to = State(initialValue: "")
            _subject = State(initialValue: "")
            _fromAlias = State(initialValue: request.draftAlias ?? preselectedAlias ?? mailboxes.first?.aliasID)
        }
    }

    // MARK: Which mailbox, and is it end-to-end

    private var mode: MailAPI.ComposeMode { restoredMode ?? request.mode }
    private var source: MailMessage? { request.source ?? restoredSource }
    private var sourceID: Int? { source?.id ?? restoredSourceID }

    private var sendingAlias: Int? {
        mode == .new || request.draftID != nil ? fromAlias : (source?.aliasID ?? request.draftAlias)
    }

    private var sendingMailbox: Mailbox? {
        guard let a = sendingAlias else { return nil }
        return mailboxes.first { $0.aliasID == a }
    }

    /// The draft is sealed on this phone when the sending mailbox is end-to-end.
    private var isFortress: Bool {
        sendingMailbox?.isFortress == true || (mode != .new && source?.sealed != nil)
    }

    private var content: DraftContent {
        var c = DraftContent(aliasID: sendingAlias, mode: mode, sourceID: sourceID)
        c.sender = sendingMailbox?.address ?? ""
        c.to = to
        c.cc = cc
        c.subject = subject
        c.body = bodyText
        return c
    }

    private var hasContent: Bool {
        !(to.isEmpty && cc.isEmpty && bodyText.isEmpty && attachments.isEmpty
          && (mode != .new || subject.isEmpty))
    }

    private static func prefixed(_ subject: String, _ prefix: String) -> String {
        let trimmed = subject.trimmingCharacters(in: .whitespaces)
        if trimmed.lowercased().hasPrefix(prefix.lowercased()) { return trimmed }
        return "\(prefix) \(trimmed)"
    }

    private var title: String {
        switch mode {
        case .reply: return "Reply"
        case .replyAll: return "Reply all"
        case .forward: return "Forward"
        case .new: return "New message"
        }
    }

    /// No footer for a new message — there is nothing quoted or forwarded.
    private var footerText: String? {
        switch mode {
        case .forward: return "The forwarded message and its attachments are included below your text."
        case .new: return nil
        case .reply, .replyAll: return "The original message is quoted below your text."
        }
    }

    var body: some View {
        NavigationStack {
            pickers(lifecycle(Form { formContent }
                .navigationTitle(title)
                .navigationBarTitleDisplayMode(.inline)
                .toolbar { toolbarContent }))
        }
    }

    @ViewBuilder
    private var formContent: some View {
        if mode == .new {
            Section {
                Picker("From", selection: $fromAlias) {
                    ForEach(mailboxes) { box in
                        Text(box.address).tag(Optional(box.aliasID))
                    }
                }
                .accessibilityIdentifier("mail_compose_from")
            }
        }
        Section {
            TextField("To", text: $to)
                .keyboardType(.emailAddress)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .accessibilityIdentifier("mail_compose_to")
            if mode != .forward {
                TextField("Cc", text: $cc)
                    .keyboardType(.emailAddress)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .accessibilityIdentifier("mail_compose_cc")
            }
            TextField("Subject", text: $subject)
                .accessibilityIdentifier("mail_compose_subject")
        }
        Section {
            TextEditor(text: $bodyText)
                .frame(minHeight: 180)
                .focused($bodyFocused)
                .accessibilityIdentifier("mail_compose_body")
        } footer: {
            if let footerText {
                Text(footerText)
            }
        }
        if !restoredParts.isEmpty || !savedPlain.isEmpty {
            Section {
                ForEach(restoredParts) { part in
                    savedChip(part.filename) { restoredParts.removeAll { $0.mimePart == part.mimePart }; scheduleSave() }
                }
                ForEach(savedPlain) { att in
                    savedChip(att.filename) {
                        savedPlain.removeAll { $0.id == att.id }
                        Task { await draft?.removeSaved(att) }
                    }
                }
            } header: {
                Text("Saved with the draft")
            }
        }
        if !attachments.isEmpty {
            Section {
                ForEach(attachments) { att in
                    HStack(spacing: 8) {
                        Image(systemName: attachmentIcon(mimeType: att.mimeType))
                            .foregroundStyle(.secondary)
                        Text(att.filename)
                            .lineLimit(1)
                        Spacer()
                        Button {
                            attachments.removeAll { $0.id == att.id }
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .foregroundStyle(.secondary)
                        }
                        .accessibilityIdentifier("mail_compose_attachment_remove")
                    }
                }
            }
        }
        if let failure {
            Section {
                Text(failure)
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("mail_compose_error")
            }
        }
        if let draftNote {
            Section {
                Text(draftNote)
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("mail_compose_draft_note")
            }
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .topBarLeading) {
            Button("Cancel") {
                // Leaving keeps what was typed as a draft.
                Task { await saveNow(); dismiss() }
            }
                .accessibilityIdentifier("mail_compose_cancel")
                .disabled(isSending)
        }
        ToolbarItem(placement: .topBarTrailing) {
            attachButton
        }
        ToolbarItem(placement: .topBarTrailing) {
            Button {
                Task { await send() }
            } label: {
                if isSending {
                    ProgressView()
                } else {
                    Image(systemName: "paperplane.fill")
                }
            }
            .accessibilityIdentifier("mail_compose_send")
            .accessibilityLabel("Send")
            .disabled(isSending || to.trimmingCharacters(in: .whitespaces).isEmpty
                      || (mode == .new && fromAlias == nil))
        }
        }

    private var lockPublisher: AnyPublisher<Void, Never> {
        fortress?.objectWillChange.eraseToAnyPublisher() ?? Empty<Void, Never>().eraseToAnyPublisher()
    }

    /// Autosave and the lock.
    private func lifecycle<V: View>(_ content: V) -> some View {
        content
        .onAppear {
            bodyFocused = true
            lockEpochAtOpen = fortress?.lockEpoch ?? 0
        }
        .task { await restoreDraft() }
        .onChange(of: to) { _ in scheduleSave() }
        .onChange(of: cc) { _ in scheduleSave() }
        .onChange(of: subject) { _ in scheduleSave() }
        .onChange(of: bodyText) { _ in scheduleSave() }
        .onChange(of: attachments) { _ in scheduleSave() }
        .onDisappear {
            autosave?.cancel()
            if !sent { Task { await saveNow() } }
        }
        .onReceive(NotificationCenter.default.publisher(for: UIApplication.didEnterBackgroundNotification)) { _ in
            // Saved while the key still exists; the lock itself clears the sheet.
            Task { await saveNow() }
        }
        .onReceive(lockPublisher) { _ in
            // The background lock took the mail key: an end-to-end compose
            // closes with it (the draft was saved first).
            if isFortress, let f = fortress, f.lockEpoch != lockEpochAtOpen, !f.isOpen {
                draft?.forgetKeys()
                dismiss()
            }
        }
    }

    private func pickers<V: View>(_ content: V) -> some View {
        content
        .interactiveDismissDisabled(isSending)
        .photosPicker(isPresented: $showPhotoPicker, selection: $photoSelection,
                      maxSelectionCount: max(0, Self.maxAttachments - attachments.count), matching: .images)
        .onChange(of: photoSelection) { items in
            guard !items.isEmpty else { return }
            let picked = items
            photoSelection = []
            Task { for item in picked { await loadPhoto(item) } }
        }
        .fileImporter(isPresented: $showFileImporter,
                      allowedContentTypes: Self.documentTypes,
                      allowsMultipleSelection: true) { result in
            loadFiles(result)
        }
    }

    private func savedChip(_ name: String, remove: @escaping () -> Void) -> some View {
        HStack(spacing: 8) {
            Image(systemName: "paperclip").foregroundStyle(.secondary)
            Text(name).lineLimit(1)
            Spacer()
            Button(action: remove) {
                Image(systemName: "xmark.circle.fill").foregroundStyle(.secondary)
            }
        }
    }

    // MARK: Drafts (§ R12)

    private func scheduleSave() {
        guard !restoring, !sent else { return }
        autosave?.cancel()
        autosave = Task {
            try? await Task.sleep(nanoseconds: 3_000_000_000)
            guard !Task.isCancelled else { return }
            await saveNow()
        }
    }

    private func draftSession() -> DraftSession {
        if let draft { return draft }
        let d = DraftSession(api: api, fortress: fortress, isFortress: isFortress, draftID: request.draftID)
        draft = d
        return d
    }

    /// Save now. Plain drafts upload new files and move them to the saved chips;
    /// a Fortress draft seals everything here and keeps the files in memory.
    private func saveNow() async {
        // A draft that failed to open is never saved over (F21).
        guard hasContent, !sent, !isSending, !restoreFailed else { return }
        if isFortress, fortress?.isOpen != true { return }
        let d = draftSession()
        do {
            if d.fortress {
                // Parts are tracked by the attachment's identity, not its name (F20).
                try await d.save(content, files: attachments, keepSaved: restoredParts.map(\.mimePart))
            } else {
                let fresh = attachments
                try await d.save(content, files: fresh)
                attachments.removeAll { a in fresh.contains { $0.id == a.id } }
                savedPlain = d.savedAttachments
            }
            draftNote = "Draft saved."
        } catch {
            draftNote = "The draft could not be saved: " + ((error as? LocalizedError)?.errorDescription
                ?? (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    /// Reopen a saved draft (from the Drafts view).
    private func restoreDraft() async {
        guard let id = request.draftID, draft == nil else { return }
        restoring = true
        defer { restoring = false }
        do {
            let data = try await api.action("mailbox/draft_get", [(key: "draft_id", value: .number(Double(id)))])
            let fortressDraft = data["fortress"]?.boolValue == true
            if fortressDraft, let fortress, !fortress.isOpen { try await fortress.unlock() }
            let d = DraftSession(api: api, fortress: fortress, isFortress: fortressDraft, draftID: id)
            let c = try await d.open(data)
            draft = d
            to = c.to
            cc = c.cc
            subject = c.subject
            bodyText = c.body
            if let a = c.aliasID { fromAlias = a }
            restoredParts = d.parts
            savedPlain = d.savedAttachments
            if c.mode != .new {
                restoredMode = c.mode
                restoredSourceID = c.sourceID
                // An end-to-end source is quoted from what this phone opens of it.
                if let key = data["thread_key"]?.stringValue, !key.isEmpty, let sid = c.sourceID,
                   let thread = try? await api.thread(key: key, aliasID: nil) {
                    let opened = await fortress?.openThread(thread) ?? thread
                    restoredSource = opened.messages.first { $0.id == sid }
                }
            }
        } catch {
            restoreFailed = true
            failure = (error as? LocalizedError)?.errorDescription
                ?? (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }

    private var attachButton: some View {
        Menu {
            Button {
                showPhotoPicker = true
            } label: {
                Label("Photo Library", systemImage: "photo")
            }
            Button {
                showFileImporter = true
            } label: {
                Label("Files", systemImage: "doc")
            }
            #if DEBUG
            // The gate's attachment (debug builds only): the Simulator's
            // pickers cannot be driven reliably from a UI test.
            if let name = ProcessInfo.processInfo.environment["JOINERY_TEST_ATTACHMENT"] {
                Button {
                    addAttachment(MailOutgoingAttachment(filename: name, mimeType: "text/plain",
                                                         data: Data("gate attachment \(name)".utf8)))
                } label: {
                    Label("Test file", systemImage: "doc.badge.plus")
                }
            }
            #endif
        } label: {
            Image(systemName: "paperclip")
        }
        .disabled(isSending || attachments.count >= Self.maxAttachments)
        .accessibilityIdentifier("mail_compose_attach")
    }

    // MARK: Picking

    /// Load a picked photo. HEIC/other non-server-types are transcoded to JPEG so
    /// the server's byte-detected type lands in its allowed set.
    private func loadPhoto(_ item: PhotosPickerItem) async {
        guard let data = try? await item.loadTransferable(type: Data.self) else { return }
        let type = item.supportedContentTypes.first
        if let type, Self.directImageTypes.contains(type.identifier) {
            let ext = type.preferredFilenameExtension ?? "img"
            let mime = type.preferredMIMEType ?? "application/octet-stream"
            addAttachment(MailOutgoingAttachment(filename: "photo.\(ext)", mimeType: mime, data: data))
        } else if let image = UIImage(data: data), let jpeg = image.jpegData(compressionQuality: 0.9) {
            addAttachment(MailOutgoingAttachment(filename: "photo.jpg", mimeType: "image/jpeg", data: jpeg))
        }
    }

    private func loadFiles(_ result: Result<[URL], Error>) {
        guard case .success(let urls) = result else { return }
        for url in urls {
            let scoped = url.startAccessingSecurityScopedResource()
            defer { if scoped { url.stopAccessingSecurityScopedResource() } }
            guard let data = try? Data(contentsOf: url) else { continue }
            let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType
                ?? "application/octet-stream"
            addAttachment(MailOutgoingAttachment(filename: url.lastPathComponent, mimeType: mime, data: data))
        }
    }

    /// Client-side preflight only (fast, friendly failure); the server remains
    /// the authority and re-validates every file and the running total.
    private func addAttachment(_ att: MailOutgoingAttachment) {
        guard attachments.count < Self.maxAttachments else {
            failure = "Up to \(Self.maxAttachments) attachments per message."
            return
        }
        guard att.data.count <= Self.maxAttachmentBytes else {
            failure = "\"\(att.filename)\" is larger than the per-file limit."
            return
        }
        let total = attachments.reduce(0) { $0 + $1.data.count } + att.data.count
        guard total <= Self.maxTotalBytes else {
            failure = "The attachments exceed the total size limit."
            return
        }
        attachments.append(att)
    }

    private func attachmentIcon(mimeType: String) -> String {
        if mimeType.hasPrefix("image/") { return "photo" }
        if mimeType == "application/pdf" { return "doc.richtext" }
        if mimeType.hasPrefix("text/") || mimeType.contains("json") || mimeType.contains("csv") {
            return "doc.text"
        }
        return "doc"
    }

    private func send() async {
        autosave?.cancel()
        isSending = true
        failure = nil
        do {
            var files = attachments
            var sourceOpen: JSONValue?
            var inline: [String: String] = [:]
            // An end-to-end source: the quote and a forward's parts come from
            // what this phone opened, since the server cannot read them.
            if let source, source.sealed != nil, mode != .new {
                guard let fortress, let open = fortress.sourceOpen(source) else {
                    throw FortressError.message("Unlock your mail and open the message again, then send.")
                }
                sourceOpen = open
                if mode == .forward {
                    let fwd = try await fortress.sourceFiles(source)
                    files += fwd.files
                    inline = fwd.inline
                }
            }
            if let d = draft, d.fortress {
                // The parts saved in an earlier session, opened from their
                // signed URLs; this session's own attachments go from memory.
                files += try await d.fortressFiles(keep: restoredParts.map(\.mimePart))
            }
            let outcome = try await api.send(
                mode: mode,
                sourceID: sourceID,
                aliasID: mode == .new ? fromAlias : nil,
                to: to,
                cc: cc,
                subject: subject,
                body: bodyText,
                attachments: files,
                sourceOpen: sourceOpen,
                inlineManifest: inline,
                draftID: draft?.draftID
            )
            isSending = false
            switch outcome {
            case .sent:
                sent = true
                draft?.forgetKeys()
                dismiss()
                onSent()
            case .locked(let message):
                // B2: nothing was sent. The sending lock is a browser ceremony.
                failure = message + " Send this from a computer; your draft is kept."
                await saveNow()
            }
        } catch {
            isSending = false
            failure = (error as? LocalizedError)?.errorDescription
                ?? (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }
}
