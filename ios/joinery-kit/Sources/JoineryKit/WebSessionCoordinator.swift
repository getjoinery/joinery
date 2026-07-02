import Foundation
import WebKit

/// Owns the app ↔ web session handshake for every webview in the app.
///
/// The app authenticates with its API session key; webviews need a web
/// session. `POST /api/v1/auth/web_session` mints a single-use bridge URL
/// (~60s TTL) that starts an app-context web session and 302s to the target
/// (docs/mobile_apps.md § Web-session bridge). The bridged session lives in
/// the shared webview cookie store, persists across launches, and is
/// lifetime-coupled server-side to the API key — so this class never stores
/// a web credential itself.
@MainActor
public final class WebSessionCoordinator {
    private let client: APIClient

    /// True once any webview has completed a bridge navigation this launch.
    /// Later webviews load their target directly on the shared cookie; a
    /// logged-out redirect (cookie expired server-side) re-bridges lazily.
    private(set) var bridged = false

    public init(client: APIClient) {
        self.client = client
    }

    /// Mint a bridge URL that lands on `target` (a same-origin relative
    /// path). Each call is a fresh single-use token — concurrent webviews
    /// may each mint their own.
    func bridgeURL(for target: String) async throws -> URL {
        let body = JSONValue.object([(key: "target", value: .string(target))])
        let envelope = try await client.request("POST", "/api/v1/auth/web_session", body: body)
        guard let bridgePath = envelope["data"]?["bridge_url"]?.stringValue,
              let url = URL(string: bridgePath, relativeTo: client.config.baseURL)
        else { throw JoineryAPIError.malformedResponse }
        return url
    }

    /// The absolute URL for a same-origin relative path.
    func pageURL(for target: String) -> URL? {
        URL(string: target, relativeTo: client.config.baseURL)
    }

    var baseHost: String? { client.config.baseURL.host }

    func markBridged() { bridged = true }

    /// Sign-out: drop the bridged web session along with the API key —
    /// clears the site's cookies and storage from the shared store
    /// (spec § Security notes).
    public func reset() async {
        bridged = false
        let store = WKWebsiteDataStore.default()
        let types = WKWebsiteDataStore.allWebsiteDataTypes()
        let records = await store.dataRecords(ofTypes: types)
        let host = baseHost ?? ""
        let ours = records.filter { record in
            record.displayName == host || host.hasSuffix("." + record.displayName)
                || record.displayName.hasSuffix(host)
        }
        await store.removeData(ofTypes: types, for: ours)
    }
}
