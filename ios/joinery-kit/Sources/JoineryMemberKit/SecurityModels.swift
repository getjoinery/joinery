import Foundation
import JoineryKit

/// One row of `security_overview`'s `app_sessions` — the only read surface
/// for `ApiKey` (it has no CRUD exposure by design).
public struct AppSessionRow: Identifiable, Equatable, Sendable {
    public let apiKeyID: Int
    public let deviceLabel: String
    public let createdTime: String
    public let lastUsedTime: String?
    public let isCurrent: Bool
    public var id: Int { apiKeyID }

    init?(json: JSONValue) {
        guard let apiKeyID = json["api_key_id"]?.intValue else { return nil }
        self.apiKeyID = apiKeyID
        deviceLabel = json["device_label"]?.stringValue ?? "App session"
        createdTime = json["created_time"]?.stringValue ?? ""
        lastUsedTime = json["last_used_time"]?.stringValue
        isCurrent = json["is_current"]?.boolValue ?? false
    }
}

/// The `security_overview` payload.
public struct SecurityOverview: Equatable, Sendable {
    public let totpEnabled: Bool
    public let totpEnabledTime: String?
    public let backupCodesRemaining: Int
    public let appSessions: [AppSessionRow]
    public let passkeyCount: Int
    public let vaultActive: Bool

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        totpEnabled = data["totp_enabled"]?.boolValue ?? false
        totpEnabledTime = data["totp_enabled_time"]?.stringValue
        backupCodesRemaining = data["backup_codes_remaining"]?.intValue ?? 0
        appSessions = (data["app_sessions"]?.arrayValue ?? []).compactMap(AppSessionRow.init(json:))
        passkeyCount = data["passkey_count"]?.intValue ?? 0
        vaultActive = data["vault_active"]?.boolValue ?? false
    }
}

/// The `security` action's render-based responses (`start_enable`,
/// `confirm_enable` on failure or success, `regenerate_backup_codes`).
/// `revoke_app_session` / `revoke_all_app_sessions` / `disable` /
/// `cancel_enable` redirect server-side instead — an empty `data: {}` with
/// no fields here, so the caller treats a non-throwing call as success for
/// those (docs/api.md § redirect envelope).
public struct TOTPSetupState: Equatable, Sendable {
    public let totpEnabled: Bool
    public let totpEnabledTime: String?
    public let setupInProgress: Bool
    public let provisioningURI: String?
    public let backupCodes: [String]?
    public let justEnabled: Bool
    /// Present on a `confirm_enable` failure (bad code) — the display
    /// message the web page would have shown via a flash message.
    public let formError: String?

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        totpEnabled = data["totp_enabled"]?.boolValue ?? false
        totpEnabledTime = data["totp_enabled_time"]?.stringValue
        setupInProgress = data["setup_in_progress"]?.boolValue ?? false
        provisioningURI = data["provisioning_uri"]?.stringValue
        backupCodes = data["backup_codes"]?.arrayValue.map { $0.compactMap(\.stringValue) }
        justEnabled = data["just_enabled"]?.boolValue ?? false
        formError = nil
    }
}
