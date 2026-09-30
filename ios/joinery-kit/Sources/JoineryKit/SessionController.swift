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
    /// The device's own keys and the client-custody secrets handed to it
    /// (specs/fortress_mobile_apps.md § R3), and the ones held in memory now.
    public let deviceKeys: DeviceKeyStore
    public let heldKeys: HeldKeys
    private var signOutHooks: [() -> Void] = []

    public init(config: JoineryConfig, keychainService: String) {
        self.client = APIClient(config: config)
        self.keychain = KeychainStore(service: keychainService)
        self.deviceKeys = DeviceKeyStore(service: keychainService)
        self.heldKeys = HeldKeys()
        wireClient()
    }

    /// Test seam: inject a prepared client/keychain.
    public init(client: APIClient, keychain: KeychainStore,
                deviceKeys: DeviceKeyStore = DeviceKeyStore(service: "test", protection: .plain),
                heldKeys: HeldKeys? = nil) {
        self.client = client
        self.keychain = keychain
        self.deviceKeys = deviceKeys
        self.heldKeys = heldKeys ?? HeldKeys(observeApp: false)
        wireClient()
    }

    /// Runs on every sign-out path — user action or a 401 — after the held
    /// keys are dropped. Modules register what they keep per account (a mail
    /// secret, a search key, a polling schedule) so it goes with the account.
    public func addSignOutHook(_ hook: @escaping () -> Void) {
        signOutHooks.append(hook)
    }

    private func wireClient() {
        client.upgradeRequiredHandler = { [weak self] message in
            Task { @MainActor in
                self?.state = .upgradeRequired(message: message)
            }
        }
        client.sessionInvalidatedHandler = { [weak self] in
            Task { @MainActor in
                // A 401 mid-session: the key was revoked on the Security page,
                // which is how a phone is unlinked (D1).
                self?.signOutLocally(revoked: true)
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
                // Not the site's answer (a portal's JSON): keep the session.
                state = .loggedIn(UserSummary.offlinePlaceholder)
                Task { await self.refreshUser() }
            }
        } catch {
            if case .upgradeRequired(let message) = error as? JoineryAPIError {
                state = .upgradeRequired(message: message)
            } else if Self.signsOut(on: error) {
                // Key revoked while we were gone.
                signOutLocally(revoked: true)
            } else {
                // Offline, a captive portal's page (not a Joinery envelope), a
                // server error: none of them says the key is bad. The session
                // stays; enter with a placeholder and retry (R9).
                state = .loggedIn(UserSummary.offlinePlaceholder)
                Task { await self.refreshUser() }
            }
        }
    }

    /// Only the site's own authentication error signs a stored session out.
    nonisolated static func signsOut(on error: Error) -> Bool {
        if case .authentication = error as? JoineryAPIError { return true }
        return false
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

    private func signOutLocally(revoked: Bool = false) {
        client.setCredentials(nil)
        keychain.deleteCredentials()
        // Signing out wipes the held secrets and the stored ones; the device
        // key stays, so the next enrollment approves the same public key —
        // unless the session was revoked, which unlinks the phone: then the
        // device key goes too.
        heldKeys.dropAll()
        if revoked { deviceKeys.forgetDeviceKey() }
        signOutHooks.forEach { $0() }
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
