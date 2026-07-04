import SwiftUI
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
struct ComposeSheet: View {
    let api: MailAPI
    let request: ComposeRequest
    /// The viewer's granted mailboxes — only consulted in `.new` mode, for the
    /// From picker (reply/forward keep their implicit source-derived identity).
    let mailboxes: [Mailbox]
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
         preselectedAlias: Int? = nil, onSent: @escaping () -> Void) {
        self.api = api
        self.request = request
        self.mailboxes = mailboxes
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
            _fromAlias = State(initialValue: preselectedAlias ?? mailboxes.first?.aliasID)
        }
    }

    private static func prefixed(_ subject: String, _ prefix: String) -> String {
        let trimmed = subject.trimmingCharacters(in: .whitespaces)
        if trimmed.lowercased().hasPrefix(prefix.lowercased()) { return trimmed }
        return "\(prefix) \(trimmed)"
    }

    private var title: String {
        switch request.mode {
        case .reply: return "Reply"
        case .replyAll: return "Reply all"
        case .forward: return "Forward"
        case .new: return "New message"
        }
    }

    /// No footer for a new message — there is nothing quoted or forwarded.
    private var footerText: String? {
        switch request.mode {
        case .forward: return "The forwarded message and its attachments are included below your text."
        case .new: return nil
        case .reply, .replyAll: return "The original message is quoted below your text."
        }
    }

    var body: some View {
        NavigationStack {
            Form {
                if request.mode == .new {
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
                    if request.mode != .forward {
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
            }
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") { dismiss() }
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
                              || (request.mode == .new && fromAlias == nil))
                }
            }
            .onAppear { bodyFocused = true }
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
        isSending = true
        failure = nil
        do {
            try await api.send(
                mode: request.mode,
                sourceID: request.source?.id,
                aliasID: request.mode == .new ? fromAlias : nil,
                to: to,
                cc: cc,
                subject: subject,
                body: bodyText,
                attachments: attachments
            )
            isSending = false
            dismiss()
            onSent()
        } catch {
            isSending = false
            failure = (error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription
        }
    }
}
