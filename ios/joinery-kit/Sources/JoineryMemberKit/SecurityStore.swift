import Foundation
import JoineryKit

/// State for the security screen: the app-session list, TOTP status, and the
/// enable/confirm/disable/regenerate flows. All writes go through
/// SecurityAPI and reload `security_overview` — the server is the single
/// source of truth, shared with the web security page.
@MainActor
public final class SecurityStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    /// The TOTP setup sheet's own phase, separate from the screen load.
    public enum SetupPhase: Equatable {
        case idle
        case awaitingCode(provisioningURI: String)
        case justEnabled(backupCodes: [String])
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var overview: SecurityOverview?
    @Published public private(set) var setupPhase: SetupPhase = .idle
    @Published public private(set) var setupError: String?
    @Published public private(set) var isBusy = false
    /// Set once a revoke call turns out to have killed the session that made
    /// it — the screen signs the app out through this signal (the kit's
    /// existing 401 path handles the actual credential teardown; this call
    /// simply forces a `logout()` so the app leaves promptly rather than
    /// waiting for the next request to 401).
    public var onSelfRevoked: (() async -> Void)?

    public let api: SecurityAPI

    public init(api: SecurityAPI) {
        self.api = api
    }

    public func initialLoad() async {
        phase = .loading
        await reload()
    }

    public func reload() async {
        do {
            overview = try await api.overview()
            phase = .loaded
        } catch {
            if case .loaded = phase { return }
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? error.localizedDescription)
        }
    }

    // MARK: TOTP setup

    public func startSetup() async {
        setupError = nil
        do {
            let state = try await api.startEnable()
            if let uri = state.provisioningURI, !uri.isEmpty {
                setupPhase = .awaitingCode(provisioningURI: uri)
            }
        } catch {
            setupError = (error as? JoineryAPIError)?.displayMessage ?? "Could not start setup."
        }
    }

    public func confirmSetup(code: String) async {
        setupError = nil
        isBusy = true
        defer { isBusy = false }
        do {
            let state = try await api.confirmEnable(code: code)
            if state.justEnabled {
                setupPhase = .justEnabled(backupCodes: state.backupCodes ?? [])
                await reload()
            } else if let uri = state.provisioningURI {
                setupPhase = .awaitingCode(provisioningURI: uri)
                setupError = "That code did not match. Please try again."
            }
        } catch {
            setupError = (error as? JoineryAPIError)?.displayMessage ?? "Could not confirm the code."
        }
    }

    public func cancelSetup() async {
        setupPhase = .idle
        setupError = nil
        try? await api.cancelEnable()
    }

    public func finishSetup() {
        setupPhase = .idle
        setupError = nil
    }

    public func regenerateBackupCodes() async -> [String]? {
        setupError = nil
        do {
            let state = try await api.regenerateBackupCodes()
            await reload()
            return state.backupCodes
        } catch {
            setupError = (error as? JoineryAPIError)?.displayMessage ?? "Could not regenerate backup codes."
            return nil
        }
    }

    public func disable(totpCode: String, backupCode: String) async -> Bool {
        setupError = nil
        isBusy = true
        defer { isBusy = false }
        do {
            let succeeded = try await api.disable(totpCode: totpCode, backupCode: backupCode)
            await reload()
            if !succeeded {
                setupError = "Please confirm with a current 6-digit code or an 8-character backup code."
            }
            return succeeded
        } catch {
            setupError = (error as? JoineryAPIError)?.displayMessage ?? "Could not disable two-factor authentication."
            return false
        }
    }

    // MARK: App sessions

    public func revoke(_ session: AppSessionRow) async {
        do {
            try await api.revokeAppSession(apiKeyID: session.apiKeyID)
        } catch {
            // Fall through to reload either way.
        }
        if session.isCurrent {
            await onSelfRevoked?()
            return
        }
        await reload()
    }

    public func revokeAll() async {
        let hadCurrent = overview?.appSessions.contains(where: \.isCurrent) ?? false
        do {
            try await api.revokeAllAppSessions()
        } catch {
            // Fall through to reload either way.
        }
        if hadCurrent {
            await onSelfRevoked?()
            return
        }
        await reload()
    }
}
