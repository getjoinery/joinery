import SwiftUI
import WebKit

/// The authenticated webview screen — how every `{type: "web"}` navigation
/// destination renders (spec § Webview contract).
///
/// - Same-origin only: off-site links (and non-web schemes) leave the app —
///   Safari, Mail, etc. Member-surface navigation stays in-webview.
/// - The native shell owns the title bar; the web page's title fills it, and
///   a toolbar chevron walks webview history when there is any.
/// - First use bridges the API session into an app-context web session; a
///   logged-out redirect (`/login`) silently re-bridges and retries once
///   before surfacing anything. A dead API key makes the re-bridge mint 401,
///   which signs the whole app out through the client handler.
/// - Pull-to-refresh, file uploads (WKWebView native pickers), downloads
///   (share-sheet hand-off), and standard loading/error/retry states.
public struct WebScreen: View {
    let title: String
    let target: String
    let client: APIClient
    let web: WebSessionCoordinator

    @State private var pageState = WebPageState()
    @State private var reloadToken = 0

    public init(title: String, target: String, client: APIClient, web: WebSessionCoordinator) {
        self.title = title
        self.target = target
        self.client = client
        self.web = web
    }

    public var body: some View {
        ZStack {
            // The webview stays inside the safe area: extending under the
            // native tab bar leaves fixed-bottom web UI (cookie consent,
            // sticky action bars) visible but untappable behind it.
            WebViewRepresentable(
                target: target,
                client: client,
                web: web,
                pageState: $pageState,
                reloadToken: reloadToken
            )

            if pageState.isLoading {
                ProgressView()
                    .accessibilityIdentifier("web_loading")
            }
            if let message = pageState.failureMessage {
                VStack(spacing: 12) {
                    Text(message)
                        .multilineTextAlignment(.center)
                        .foregroundStyle(.secondary)
                        .accessibilityIdentifier("web_error")
                    Button("Try Again") { reloadToken += 1 }
                        .buttonStyle(.borderedProminent)
                        .accessibilityIdentifier("web_retry")
                }
                .padding()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .background(Color(uiColor: .systemBackground))
            }
        }
        .navigationTitle(pageState.pageTitle.isEmpty ? title : pageState.pageTitle)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if pageState.canGoBack {
                ToolbarItem(placement: .topBarLeading) {
                    Button {
                        pageState.goBackRequests += 1
                    } label: {
                        Image(systemName: "chevron.backward")
                    }
                    .accessibilityIdentifier("web_back")
                }
            }
        }
        .sheet(item: $pageState.downloadedFile) { download in
            ShareSheet(items: [download.url])
        }
    }
}

/// Webview state surfaced to the native chrome.
struct WebPageState {
    var isLoading = true
    var failureMessage: String?
    var pageTitle = ""
    var canGoBack = false
    /// Incremented by the native back chevron; the coordinator consumes it.
    var goBackRequests = 0
    var downloadedFile: DownloadedFile?
}

struct DownloadedFile: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

private struct ShareSheet: UIViewControllerRepresentable {
    let items: [Any]
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: items, applicationActivities: nil)
    }
    func updateUIViewController(_ controller: UIActivityViewController, context: Context) {}
}

// MARK: - WKWebView wrapper

private struct WebViewRepresentable: UIViewRepresentable {
    let target: String
    let client: APIClient
    let web: WebSessionCoordinator
    @Binding var pageState: WebPageState
    let reloadToken: Int

    func makeCoordinator() -> WebCoordinator {
        WebCoordinator(target: target, client: client, web: web)
    }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        // The shared default store carries the bridged session cookie across
        // every webview and across launches.
        configuration.websiteDataStore = .default()
        configuration.allowsInlineMediaPlayback = true

        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = context.coordinator
        webView.uiDelegate = context.coordinator
        webView.allowsBackForwardNavigationGestures = true
        webView.scrollView.refreshControl = context.coordinator.makeRefreshControl()

        context.coordinator.attach(webView: webView) { update in
            pageState = update(pageState)
        }
        context.coordinator.startInitialLoad()
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        context.coordinator.onStateWrite = { update in
            pageState = update(pageState)
        }
        context.coordinator.consumeGoBack(requests: pageState.goBackRequests)
        context.coordinator.retryIfRequested(token: reloadToken)
    }
}

/// All webview policy lives here: bridging, logged-out detection, the link
/// policy, downloads, and refresh.
@MainActor
final class WebCoordinator: NSObject {
    private let target: String
    private let client: APIClient
    private let web: WebSessionCoordinator

    private weak var webView: WKWebView?
    var onStateWrite: ((@escaping (WebPageState) -> WebPageState) -> Void)?

    /// The path the user is meant to be on — re-bridges land back here.
    private var intendedPath: String
    /// One silent re-bridge per load attempt; reset on success and refresh.
    private var didRebridge = false
    /// Set while the current navigation is a bridge URL, so its completion
    /// marks the shared coordinator bridged.
    private var bridging = false

    private var titleObservation: NSKeyValueObservation?
    private var backObservation: NSKeyValueObservation?
    private var consumedGoBacks = 0
    private var lastReloadToken = 0
    private var downloadDestination: URL?

    init(target: String, client: APIClient, web: WebSessionCoordinator) {
        self.target = target
        self.client = client
        self.web = web
        self.intendedPath = target
        super.init()
    }

    func attach(webView: WKWebView, stateWriter: @escaping ((@escaping (WebPageState) -> WebPageState) -> Void)) {
        self.webView = webView
        self.onStateWrite = stateWriter
        titleObservation = webView.observe(\.title, options: [.new]) { [weak self] view, _ in
            let title = view.title ?? ""
            Task { @MainActor in self?.write { state in var s = state; s.pageTitle = title; return s } }
        }
        backObservation = webView.observe(\.canGoBack, options: [.new]) { [weak self] view, _ in
            let canGoBack = view.canGoBack
            Task { @MainActor in self?.write { state in var s = state; s.canGoBack = canGoBack; return s } }
        }
    }

    private func write(_ update: @escaping (WebPageState) -> WebPageState) {
        onStateWrite?(update)
    }

    func makeRefreshControl() -> UIRefreshControl {
        let control = UIRefreshControl()
        control.addTarget(self, action: #selector(pullToRefresh), for: .valueChanged)
        return control
    }

    @objc private func pullToRefresh() {
        didRebridge = false
        webView?.reload()
    }

    func consumeGoBack(requests: Int) {
        guard requests > consumedGoBacks else { return }
        consumedGoBacks = requests
        webView?.goBack()
    }

    func retryIfRequested(token: Int) {
        guard token != lastReloadToken else { return }
        lastReloadToken = token
        didRebridge = false
        write { state in var s = state; s.failureMessage = nil; s.isLoading = true; return s }
        startInitialLoad()
    }

    // MARK: Loading

    func startInitialLoad() {
        Task { await load(path: intendedPath) }
    }

    private func load(path: String) async {
        guard let webView else { return }
        if web.bridged, let direct = web.pageURL(for: path) {
            webView.load(URLRequest(url: direct))
            return
        }
        await bridge(to: path)
    }

    /// Mint a fresh single-use bridge token and drive the webview through it.
    /// A 401 here means the API key is dead — the client handler signs the
    /// whole app out, so this screen only needs to stop quietly.
    private func bridge(to path: String) async {
        guard let webView else { return }
        do {
            let bridgeURL = try await web.bridgeURL(for: path)
            bridging = true
            webView.load(URLRequest(url: bridgeURL))
        } catch let error as JoineryAPIError {
            if case .authentication = error { return }
            fail(error.displayMessage)
        } catch {
            fail("This page could not be loaded.")
        }
    }

    private func fail(_ message: String) {
        write { state in
            var s = state
            s.isLoading = false
            s.failureMessage = message
            return s
        }
        webView?.scrollView.refreshControl?.endRefreshing()
    }

    /// The logged-out signal: the site 302s member pages to `/login` when the
    /// bridged session is gone. Silently re-bridge back to where the user was
    /// headed; only a second failure in the same attempt surfaces.
    private func handleLoggedOut() {
        if didRebridge {
            fail("Your session has expired. Pull to refresh to try again.")
            return
        }
        didRebridge = true
        let path = intendedPath
        Task { await bridge(to: path) }
    }

    private func isSameOrigin(_ url: URL) -> Bool {
        guard let host = url.host, let baseHost = web.baseHost else { return false }
        return host == baseHost
    }
}

// MARK: WKNavigationDelegate

extension WebCoordinator: WKNavigationDelegate {

    func webView(_ webView: WKWebView,
                 decidePolicyFor navigationAction: WKNavigationAction,
                 decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = navigationAction.request.url else {
            decisionHandler(.cancel)
            return
        }

        // Subframes (embedded payment fields, media) are the page's business.
        guard navigationAction.targetFrame?.isMainFrame != false else {
            decisionHandler(.allow)
            return
        }

        // Non-web schemes (mailto:, tel:, app links) leave the app.
        if let scheme = url.scheme?.lowercased(), scheme != "http", scheme != "https" {
            UIApplication.shared.open(url)
            decisionHandler(.cancel)
            return
        }

        // Off-site: Safari (spec — same-origin only in the webview).
        if !isSameOrigin(url) {
            UIApplication.shared.open(url)
            decisionHandler(.cancel)
            return
        }

        // Logged-out redirect → silent re-bridge.
        if url.path == "/login" || url.path.hasPrefix("/login/") {
            decisionHandler(.cancel)
            handleLoggedOut()
            return
        }

        // Track where the user is headed so a mid-session re-bridge lands
        // back on the right page (bridge plumbing itself doesn't count).
        if url.path != "/app_bridge" {
            var path = url.path.isEmpty ? "/" : url.path
            if let query = url.query, !query.isEmpty { path += "?" + query }
            intendedPath = path
        }
        decisionHandler(.allow)
    }

    func webView(_ webView: WKWebView,
                 decidePolicyFor navigationResponse: WKNavigationResponse,
                 decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void) {
        if let http = navigationResponse.response as? HTTPURLResponse {
            // An expired bridge token renders 410 — mint a fresh one.
            if http.statusCode == 410, http.url?.path == "/app_bridge" {
                decisionHandler(.cancel)
                handleLoggedOut()
                return
            }
        }
        // Content the webview can't render (files) becomes a download.
        if !navigationResponse.canShowMIMEType {
            decisionHandler(.download)
            return
        }
        decisionHandler(.allow)
    }

    func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
        write { state in var s = state; s.isLoading = true; s.failureMessage = nil; return s }
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        if bridging {
            bridging = false
            web.markBridged()
        }
        didRebridge = false
        write { state in var s = state; s.isLoading = false; return s }
        webView.scrollView.refreshControl?.endRefreshing()
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        finishWithError(error)
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        finishWithError(error)
    }

    private func finishWithError(_ error: Error) {
        let nsError = error as NSError
        // Cancellations (our own policy cancels, mid-flight reloads) are not
        // failures the user should see.
        if nsError.domain == NSURLErrorDomain && nsError.code == NSURLErrorCancelled { return }
        if nsError.domain == "WebKitErrorDomain" && nsError.code == 102 { return } // frame load interrupted by policy
        fail(nsError.code == NSURLErrorNotConnectedToInternet
             ? "You appear to be offline. Pull to refresh when you're back online."
             : "This page could not be loaded.")
    }

    func webView(_ webView: WKWebView,
                 navigationResponse: WKNavigationResponse,
                 didBecome download: WKDownload) {
        download.delegate = self
    }
}

// MARK: WKUIDelegate (target=_blank, JS dialogs)

extension WebCoordinator: WKUIDelegate {
    func webView(_ webView: WKWebView,
                 createWebViewWith configuration: WKWebViewConfiguration,
                 for navigationAction: WKNavigationAction,
                 windowFeatures: WKWindowFeatures) -> WKWebView? {
        if let url = navigationAction.request.url {
            if isSameOrigin(url) {
                webView.load(URLRequest(url: url))
            } else {
                UIApplication.shared.open(url)
            }
        }
        return nil
    }

    // The member surface uses alert()/confirm() (e.g. "Revoke All" asks for
    // confirmation). WKWebView drops them silently unless the UI delegate
    // presents native panels.

    func webView(_ webView: WKWebView,
                 runJavaScriptAlertPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo,
                 completionHandler: @escaping () -> Void) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "OK", style: .default) { _ in completionHandler() })
        guard present(alert) else { completionHandler(); return }
    }

    func webView(_ webView: WKWebView,
                 runJavaScriptConfirmPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo,
                 completionHandler: @escaping (Bool) -> Void) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "Cancel", style: .cancel) { _ in completionHandler(false) })
        alert.addAction(UIAlertAction(title: "OK", style: .default) { _ in completionHandler(true) })
        guard present(alert) else { completionHandler(false); return }
    }

    func webView(_ webView: WKWebView,
                 runJavaScriptTextInputPanelWithPrompt prompt: String,
                 defaultText: String?,
                 initiatedByFrame frame: WKFrameInfo,
                 completionHandler: @escaping (String?) -> Void) {
        let alert = UIAlertController(title: nil, message: prompt, preferredStyle: .alert)
        alert.addTextField { $0.text = defaultText }
        alert.addAction(UIAlertAction(title: "Cancel", style: .cancel) { _ in completionHandler(nil) })
        alert.addAction(UIAlertAction(title: "OK", style: .default) { [weak alert] _ in
            completionHandler(alert?.textFields?.first?.text ?? "")
        })
        guard present(alert) else { completionHandler(nil); return }
    }

    /// Present on the frontmost view controller; false when none is found.
    private func present(_ alert: UIAlertController) -> Bool {
        let scene = UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .first { $0.activationState == .foregroundActive }
        guard var top = scene?.keyWindow?.rootViewController else { return false }
        while let presented = top.presentedViewController { top = presented }
        top.present(alert, animated: true)
        return true
    }
}

// MARK: WKDownloadDelegate (share-sheet hand-off)

extension WebCoordinator: WKDownloadDelegate {
    func download(_ download: WKDownload,
                  decideDestinationUsing response: URLResponse,
                  suggestedFilename: String,
                  completionHandler: @escaping (URL?) -> Void) {
        let directory = FileManager.default.temporaryDirectory
            .appendingPathComponent("JoineryDownloads-\(UUID().uuidString)", isDirectory: true)
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let destination = directory.appendingPathComponent(suggestedFilename)
        downloadDestination = destination
        completionHandler(destination)
    }

    func downloadDidFinish(_ download: WKDownload) {
        guard let fileURL = downloadDestination else { return }
        write { state in
            var s = state
            s.isLoading = false
            s.downloadedFile = DownloadedFile(url: fileURL)
            return s
        }
        webView?.scrollView.refreshControl?.endRefreshing()
    }

    func download(_ download: WKDownload, didFailWithError error: Error, resumeData: Data?) {
        fail("The download failed.")
    }
}
