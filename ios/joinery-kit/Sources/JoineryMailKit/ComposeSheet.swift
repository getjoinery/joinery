import SwiftUI
import JoineryKit

/// Reply / reply-all / forward compose. Deliberately lean: the server is the
/// authority on quoting, subject normalization (Re:/Fwd:), threading headers,
/// and the sending identity — this sheet collects recipients and the new
/// text. Attachments ride only on forwards (re-attached server-side).
struct ComposeSheet: View {
    let api: MailAPI
    let request: ComposeRequest
    let onSent: () -> Void

    @State private var to: String
    @State private var cc: String = ""
    @State private var subject: String
    @State private var bodyText = ""
    @State private var isSending = false
    @State private var failure: String?
    @Environment(\.dismiss) private var dismiss
    @FocusState private var bodyFocused: Bool

    init(api: MailAPI, request: ComposeRequest, onSent: @escaping () -> Void) {
        self.api = api
        self.request = request
        self.onSent = onSent

        let source = request.source
        switch request.mode {
        case .reply, .replyAll:
            // Replying to your own outbound message goes back to its
            // recipient; otherwise to the sender.
            let target = source.isOutbound ? source.recipient : source.sender
            _to = State(initialValue: MailDisplay.address(target))
            _subject = State(initialValue: Self.prefixed(source.subject, "Re:"))
        case .forward:
            _to = State(initialValue: "")
            _subject = State(initialValue: Self.prefixed(source.subject, "Fwd:"))
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
        }
    }

    var body: some View {
        NavigationStack {
            Form {
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
                    Text(request.mode == .forward
                         ? "The forwarded message and its attachments are included below your text."
                         : "The original message is quoted below your text.")
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
                    .disabled(isSending || to.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
            .onAppear { bodyFocused = true }
            .interactiveDismissDisabled(isSending)
        }
    }

    private func send() async {
        isSending = true
        failure = nil
        do {
            try await api.send(
                mode: request.mode,
                sourceID: request.source.id,
                to: to,
                cc: cc,
                subject: subject,
                body: bodyText
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
