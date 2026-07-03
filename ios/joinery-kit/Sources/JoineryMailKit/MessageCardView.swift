import SwiftUI
import WebKit
import JoineryKit

/// One message inside a thread. Collapsed: header + one-line preview.
/// Expanded: full body — HTML in a sandboxed web widget (JavaScript off,
/// links open externally), plain text natively — plus attachment chips.
struct MessageCardView: View {
    let message: MailMessage
    let isExpanded: Bool
    let onToggle: () -> Void

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
        .sheet(item: $download) { item in
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
        if !message.bodyHTML.isEmpty {
            HTMLBodyView(html: message.bodyHTML, height: $htmlHeight)
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
                    .disabled(attachment.url == nil)
                }
            }
        }
    }

    /// Fetch the signed URL to a temp file and hand it to the share sheet
    /// (the same hand-off the webview downloads use).
    private func open(_ attachment: MailAttachment) async {
        guard let urlString = attachment.url, let url = URL(string: urlString),
              downloadingID == nil else { return }
        downloadingID = attachment.id
        defer { downloadingID = nil }
        do {
            let (data, response) = try await URLSession.shared.data(from: url)
            guard (response as? HTTPURLResponse)?.statusCode == 200 else { return }
            let dir = FileManager.default.temporaryDirectory
                .appendingPathComponent("mail-attachments", isDirectory: true)
            try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
            let file = dir.appendingPathComponent(attachment.filename)
            try data.write(to: file, options: .atomic)
            download = AttachmentDownload(url: file)
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

/// Standard native-mail HTML rendering: JavaScript off, every link tap opens
/// externally, content scaled to the device width. Inline images arrive as
/// short-lived signed URLs already rewritten server-side, so no session of
/// any kind exists inside this webview.
struct HTMLBodyView: UIViewRepresentable {
    let html: String
    @Binding var height: CGFloat

    func makeCoordinator() -> Coordinator {
        Coordinator(height: $height)
    }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = false
        configuration.dataDetectorTypes = []
        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = context.coordinator
        webView.scrollView.isScrollEnabled = false
        webView.isOpaque = false
        webView.backgroundColor = .clear
        webView.loadHTMLString(Self.wrap(html), baseURL: nil)
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {}

    /// Viewport + typography wrapper so arbitrary mail HTML reads well on a
    /// phone: system font fallback, images capped to the width, no sideways
    /// scrolling.
    static func wrap(_ body: String) -> String {
        """
        <!doctype html><html><head>
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
