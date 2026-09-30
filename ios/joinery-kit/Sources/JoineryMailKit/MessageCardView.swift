import SwiftUI
import WebKit
import JoineryKit

/// One message inside a thread. Collapsed: header + one-line preview.
/// Expanded: full body — HTML in a sandboxed web widget (JavaScript off,
/// links open externally), plain text natively — plus attachment chips.
/// An end-to-end message's parts are fetched as ciphertext and opened here
/// (FortressSession.partBytes); its body loads nothing from the network.
struct MessageCardView: View {
    let message: MailMessage
    let isExpanded: Bool
    let onToggle: () -> Void
    var client: APIClient? = nil
    var fortress: FortressSession? = nil

    @State private var htmlHeight: CGFloat = 60
    @State private var download: AttachmentDownload?
    @State private var downloadingID: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            header
                .contentShape(Rectangle())
                .onTapGesture(perform: onToggle)
            if isExpanded {
                bodyContent
                    .padding(.horizontal)
                    .padding(.bottom, 12)
                if !message.attachments.isEmpty {
                    attachmentChips
                        .padding(.horizontal)
                        .padding(.bottom, 12)
                }
            }
        }
        .background(Color(uiColor: .systemBackground))
        .overlay(Divider(), alignment: .bottom)
        .sheet(item: $download, onDismiss: {
            // B4: an opened part lives on disk only while the share sheet is up.
            OpenedFiles.removeAll()
        }) { item in
            MailShareSheet(items: [item.url])
        }
    }

    private var header: some View {
        HStack(alignment: .top, spacing: 12) {
            avatar
            VStack(alignment: .leading, spacing: 2) {
                HStack(alignment: .firstTextBaseline) {
                    Text(message.isOutbound ? "Me" : MailDisplay.senderName(message.sender))
                        .font(.subheadline.weight(message.isRead ? .regular : .semibold))
                        .lineLimit(1)
                    Spacer(minLength: 8)
                    Text(MailDisplay.messageStamp(message.receivedTime))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                if isExpanded {
                    Text("to \(MailDisplay.address(message.recipient))")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                } else {
                    Text(previewLine)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
        }
        .padding(.horizontal)
        .padding(.vertical, 10)
    }

    private var previewLine: String {
        let plain = message.bodyPlain.trimmingCharacters(in: .whitespacesAndNewlines)
        if !plain.isEmpty {
            return plain.replacingOccurrences(of: "\n", with: " ")
        }
        return message.attachments.isEmpty ? "" : "📎 \(message.attachments.count) attachment(s)"
    }

    private var avatar: some View {
        let seed = message.isOutbound ? message.recipient : message.sender
        let index = MailDisplay.avatarColorIndex(seed, paletteSize: 8)
        let palette: [Color] = [.red, .orange, .yellow, .green, .teal, .blue, .indigo, .pink]
        let initial = (message.isOutbound ? "M" : MailDisplay.senderName(message.sender).prefix(1).uppercased())
        return ZStack {
            Circle().fill(palette[index].opacity(0.85)).frame(width: 36, height: 36)
            Text(String(initial)).font(.subheadline.weight(.semibold)).foregroundStyle(.white)
        }
    }

    @ViewBuilder
    private var bodyContent: some View {
        if let note = message.placeholder {
            Label(note.rawValue, systemImage: "lock.fill")
                .font(.body)
                .foregroundStyle(.secondary)
                .frame(maxWidth: .infinity, alignment: .leading)
                .accessibilityIdentifier("mail_fortress_placeholder")
        } else {
            VStack(alignment: .leading, spacing: 8) {
                if let banner = dangerBanner {
                    banner
                }
                messageBody
            }
        }
    }

    /// The security scan's verdict, as the web reader shows it: the score and
    /// scan time are clear, the reasons were sealed and opened here.
    private var dangerBanner: AnyView? {
        let score = message.aiDangerScore ?? message.aiScan?["score"]?.intValue
        guard let score, score >= 5 else { return nil }
        let reasons = (message.aiScan?["red_flags"]?.arrayValue ?? []).compactMap(\.stringValue)
        let model = message.aiScan?["model"]?.stringValue
        return AnyView(VStack(alignment: .leading, spacing: 4) {
            Label(score >= 7 ? "This message looks dangerous" : "Be careful with this message",
                  systemImage: "exclamationmark.triangle.fill")
                .font(.subheadline.weight(.semibold))
            if let summary = message.aiScan?["summary"]?.stringValue, !summary.isEmpty {
                Text(summary).font(.caption)
            }
            ForEach(reasons, id: \.self) { Text("• " + $0).font(.caption) }
            if let model { Text("Judged by \(model)").font(.caption2).foregroundStyle(.secondary) }
        }
        .padding(8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background((score >= 7 ? Color.red : Color.orange).opacity(0.15))
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .accessibilityIdentifier("mail_danger_banner"))
    }

    @ViewBuilder
    private var messageBody: some View {
        if !message.bodyHTML.isEmpty {
            HTMLBodyView(html: message.bodyHTML, height: $htmlHeight, sealed: message.sealed != nil)
                .frame(height: htmlHeight)
        } else {
            Text(message.bodyPlain)
                .font(.body)
                .textSelection(.enabled)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private var attachmentChips: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(message.attachments) { attachment in
                    Button {
                        Task { await open(attachment) }
                    } label: {
                        HStack(spacing: 6) {
                            if downloadingID == attachment.id {
                                ProgressView().controlSize(.small)
                            } else {
                                Image(systemName: "paperclip")
                            }
                            VStack(alignment: .leading, spacing: 0) {
                                Text(attachment.filename)
                                    .font(.caption.weight(.medium))
                                    .lineLimit(1)
                                Text(attachment.sizeLabel)
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(Color(uiColor: .secondarySystemBackground))
                        .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                    .disabled(attachment.url == nil || message.placeholder != nil)
                    .accessibilityIdentifier("mail_attachment_chip")
                }
            }
        }
    }

    /// Fetch the part (a Fortress part is ciphertext, opened here), write it
    /// under complete file protection, and hand it to the share sheet; the
    /// file goes when the sheet does (B4). The fetch carries no cookie.
    private func open(_ attachment: MailAttachment) async {
        guard let urlString = attachment.url, downloadingID == nil else { return }
        downloadingID = attachment.id
        defer { downloadingID = nil }
        do {
            let data: Data
            if attachment.sealed, let fortress {
                data = try await fortress.partBytes(attachment)
            } else if let client {
                data = try await client.fetchBytes(urlString)
            } else {
                guard let url = URL(string: urlString) else { return }
                let (d, response) = try await URLSession.shared.data(from: url)
                guard (response as? HTTPURLResponse)?.statusCode == 200 else { return }
                data = d
            }
            download = AttachmentDownload(url: try OpenedFiles.write(data, name: attachment.filename))
        } catch {
            // Transient network failure — the chip stays tappable to retry.
        }
    }
}

struct AttachmentDownload: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

struct MailShareSheet: UIViewControllerRepresentable {
    let items: [Any]
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: items, applicationActivities: nil)
    }
    func updateUIViewController(_ controller: UIActivityViewController, context: Context) {}
}

// MARK: - Sandboxed HTML body

/// Native-mail HTML rendering: JavaScript off, every link tap opens
/// externally, content scaled to the device width. Inline images arrive as
/// short-lived signed URLs (or, end-to-end, as data: URLs opened here).
///
/// The view has its own non-persistent data store (B3): the default store is
/// the one holding the bridged web-session cookie, and an `<img>` pointing at
/// the deployment's own origin would otherwise be fetched with it. An
/// end-to-end body additionally loads nothing from the network at all — a
/// Content-Security-Policy admits only data: images and inline styles, so an
/// opened message cannot tell anyone it was read.
struct HTMLBodyView: UIViewRepresentable {
    let html: String
    @Binding var height: CGFloat
    var sealed = false

    func makeCoordinator() -> Coordinator {
        Coordinator(height: $height)
    }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = false
        configuration.dataDetectorTypes = []
        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = context.coordinator
        webView.scrollView.isScrollEnabled = false
        webView.isOpaque = false
        webView.backgroundColor = .clear
        webView.loadHTMLString(Self.wrap(html, sealed: sealed), baseURL: nil)
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {}

    /// Only data: images and inline styles: nothing leaves the phone.
    static let sealedPolicy = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:"

    /// Viewport + typography wrapper so arbitrary mail HTML reads well on a
    /// phone: system font fallback, images capped to the width, no sideways
    /// scrolling.
    static func wrap(_ body: String, sealed: Bool = false) -> String {
        let csp = sealed ? "<meta http-equiv=\"Content-Security-Policy\" content=\"\(sealedPolicy)\">" : ""
        return """
        <!doctype html><html><head>\(csp)
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=2">
        <style>
        body { font: -apple-system-body; font-family: -apple-system, sans-serif;
               margin: 0; padding: 0; word-wrap: break-word; overflow-wrap: break-word;
               color: CanvasText; }
        img { max-width: 100% !important; height: auto !important; }
        table { max-width: 100% !important; }
        pre, blockquote { white-space: pre-wrap; overflow-x: hidden; }
        @media (prefers-color-scheme: dark) { body { color: #eee; } a { color: #7ab8ff; } }
        </style></head><body>\(body)</body></html>
        """
    }

    final class Coordinator: NSObject, WKNavigationDelegate {
        let height: Binding<CGFloat>

        init(height: Binding<CGFloat>) {
            self.height = height
        }

        func webView(_ webView: WKWebView,
                     decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            // The only allowed load is the HTML string itself; any link tap
            // (http, mailto, …) leaves the app.
            if navigationAction.navigationType == .linkActivated,
               let url = navigationAction.request.url {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
                return
            }
            // Nothing else navigates: a <meta http-equiv="refresh"> or a frame
            // pointing off-device would be a load the reader never asked for.
            let scheme = navigationAction.request.url?.scheme?.lowercased() ?? "about"
            if navigationAction.targetFrame?.isMainFrame == true, scheme != "about" {
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            measure(webView)
            // Remote inline images can land after didFinish; re-measure once
            // they have had a moment to lay out.
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.8) { [weak webView] in
                guard let webView else { return }
                self.measure(webView)
            }
        }

        private func measure(_ webView: WKWebView) {
            // App-injected evaluation still runs with content JavaScript off.
            webView.evaluateJavaScript("document.documentElement.scrollHeight") { value, _ in
                if let h = value as? CGFloat, h > 0 {
                    self.height.wrappedValue = max(24, h)
                } else if let h = value as? Double, h > 0 {
                    self.height.wrappedValue = max(24, CGFloat(h))
                }
            }
        }
    }
}

// MARK: - Opened files (B4)

/// Where an opened part is written for the share sheet: the app's caches,
/// under complete file protection (unreadable while the phone is locked),
/// one directory per file so it keeps its name, and gone when the sheet
/// closes, at launch and at sign-out. No plaintext copy lingers at rest.
enum OpenedFiles {
    static var root: URL {
        FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("opened-parts", isDirectory: true)
    }

    static func write(_ data: Data, name: String) throws -> URL {
        let dir = root.appendingPathComponent(UUID().uuidString, isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true,
                                                attributes: [.protectionKey: FileProtectionType.complete])
        let clean = name.replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: ":", with: "_")
        let file = dir.appendingPathComponent(clean.isEmpty ? "attachment" : clean)
        try data.write(to: file, options: [.atomic, .completeFileProtection])
        return file
    }

    static func removeAll() {
        try? FileManager.default.removeItem(at: root)
        // The pre-B4 location: parts written there were never deleted.
        try? FileManager.default.removeItem(at: FileManager.default.temporaryDirectory
            .appendingPathComponent("mail-attachments", isDirectory: true))
    }
}
