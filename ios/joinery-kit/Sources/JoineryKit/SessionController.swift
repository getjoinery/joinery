import SwiftUI

/// App-level auth state machine: one instance per app, owned by the root
/// view. Holds the session key (Keychain-backed), the signed-in user summary,
/// and the global upgrade gate.
@MainActor
public final class SessionController: ObservableObject {
    public enum State: Equatable {
        /// Checking the Keychain / refreshing on launch.
        case launching
        case loggedOut
        case loggedIn(UserSummary)
        /// Blocking 426 upgrade screen; `message` is the server's text.
        case upgradeRequired(message: String)
    }

    @Published public private(set) var state: State = .launching

    /// Runs on every sign-out path (user action or 401 invalidation) —
    /// the navigation shell hooks this to drop the bridged webview session
    /// (cookies) along with the API key.
    public var onSignOut: (() -> Void)?

    public let client: APIClient
    private let keychain: KeychainStore

    public init(config: JoineryConfig, keychainService: String) {
        self.client = APIClient(config: config)
        self.keychain = KeychainStore(service: keychainService)
        wireClient()
    }

    /// Test seam: inject a prepared client/keychain.
    public init(client: APIClient, keychain: KeychainStore) {
        self.client = client
        self.keychain = keychain
        wireClient()
    }

    private func wireClient() {
        client.upgradeRequiredHandler = { [weak self] message in
            Task { @MainActor in
                self?.state = .upgradeRequired(message: message)
            }
        }
        client.sessionInvalidatedHandler = { [weak self] in
            Task { @MainActor in
                self?.signOutLocally()
            }
        }
    }

    // MARK: Lifecycle

    /// Call once at launch: restores the Keychain credentials and validates
    /// them with `auth/session`.
    public func bootstrap() async {
        guard let stored = keychain.loadCredentials() else {
            state = .loggedOut
            return
        }
        client.setCredentials(stored)
        do {
            let envelope = try await client.request("GET", "/api/v1/auth/session")
            if let user = UserSummary(json: envelope["data"]) {
                state = .loggedIn(user)
            } else {
                signOutLocally()
            }
        } catch let error as JoineryAPIError {
            switch error {
            case .authentication:
                // Key revoked while we were gone.
                signOutLocally()
            case .upgradeRequired(let message):
                state = .upgradeRequired(message: message)
            case .network:
                // Offline at launch with a stored key: enter the app with a
                // placeholder; data loads surface their own errors and the
                // next successful call refreshes the summary.
                state = .loggedIn(UserSummary.offlinePlaceholder)
                Task { await self.refreshUser() }
            default:
                signOutLocally()
            }
        } catch {
            signOutLocally()
        }
    }

    /// `auth/login`: mints a session key, stores it, enters the app.
    public func login(email: String, password: String, deviceLabel: String) async throws {
        let body = JSONValue.object([
            (key: "email", value: .string(email)),
            (key: "password", value: .string(password)),
            (key: "device_label", value: .string(deviceLabel)),
        ])
        let envelope = try await client.request(
            "POST", "/api/v1/auth/login",
            body: body, authenticated: false
        )
        guard let result = LoginResult(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        client.setCredentials(result.credentials)
        keychain.saveCredentials(result.credentials)
        if let user = result.user {
            state = .loggedIn(user)
        } else {
            await refreshUser()
        }
    }

    /// `auth/logout` (revokes the key server-side), then clears local state.
    /// Local sign-out proceeds even if the revoke call fails — the user asked
    /// to leave, and a dead key is inert server-side anyway.
    public func logout() async {
        _ = try? await client.request("POST", "/api/v1/auth/logout", body: .object([]))
        signOutLocally()
    }

    /// Re-fetch the user summary (e.g. after account_edit changes the name).
    public func refreshUser() async {
        guard client.credentials != nil else { return }
        if let envelope = try? await client.request("GET", "/api/v1/auth/session"),
           let user = UserSummary(json: envelope["data"]) {
            state = .loggedIn(user)
        }
    }

    private func signOutLocally() {
        client.setCredentials(nil)
        keychain.deleteCredentials()
        onSignOut?()
        state = .loggedOut
    }
}

extension UserSummary {
    /// Shown when the app launches offline with stored credentials.
    static var offlinePlaceholder: UserSummary {
        UserSummary(json: .object([
            (key: "user_id", value: .number(0)),
            (key: "display_name", value: .string("")),
        ]))!
    }
}
