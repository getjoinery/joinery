import Foundation
import JoineryKit

/// Thin typed face over `security_overview` (the read surface) and the
/// existing `security` action's TOTP + app-session mutations. `security`
/// predates the API-purpose-built pattern: most branches redirect
/// server-side (empty `data: {}` on both success and failure — the web page
/// distinguishes them with a flash message the native client can't read),
/// so mutations that can silently no-op re-read `security_overview`
/// afterward to confirm the outcome rather than trusting the envelope alone.
public struct SecurityAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func overview() async throws -> SecurityOverview {
        let envelope = try await client.submitAction("security_overview", body: .object([]))
        guard let overview = SecurityOverview(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return overview
    }

    /// Begin TOTP setup: the server mints a secret and returns its
    /// provisioning URI (an `otpauth://` string) for the QR.
    public func startEnable() async throws -> TOTPSetupState {
        try await securityAction("start_enable")
    }

    /// Confirm the 6-digit code from the authenticator app. On a bad code the
    /// server re-renders the same QR state with `justEnabled == false`; the
    /// caller should keep the setup sheet open for another attempt.
    public func confirmEnable(code: String) async throws -> TOTPSetupState {
        try await securityAction("confirm_enable", extra: [(key: "totp_code", value: .string(code))])
    }

    /// Abandon a pending setup.
    public func cancelEnable() async throws {
        _ = try await client.submitAction("security", body: .object([
            (key: "action", value: .string("cancel_enable")),
        ]))
    }

    public func regenerateBackupCodes() async throws -> TOTPSetupState {
        try await securityAction("regenerate_backup_codes")
    }

    /// Disable TOTP with a current 6-digit code or an 8-character backup
    /// code. The server's `disable` branch reads a single `confirm_code` and
    /// classifies its shape itself (6 digits = authenticator code, 8 chars =
    /// backup code), so whichever the user entered is sent under that one key.
    /// The action redirects on both success and a bad code, so this re-reads
    /// `security_overview` to tell them apart.
    public func disable(totpCode: String, backupCode: String) async throws -> Bool {
        let confirmation = totpCode.isEmpty ? backupCode : totpCode
        var extra: [(key: String, value: JSONValue)] = []
        if !confirmation.isEmpty { extra.append((key: "confirm_code", value: .string(confirmation))) }
        _ = try await client.submitAction("security", body: .object(
            [(key: "action", value: .string("disable"))] + extra
        ))
        let after = try await overview()
        return !after.totpEnabled
    }

    /// Revoke one app session. `revoke_app_session` redirects unconditionally
    /// (the ownership check is silent), so the caller reloads
    /// `security_overview` afterward to confirm.
    public func revokeAppSession(apiKeyID: Int) async throws {
        _ = try await client.submitAction("security", body: .object([
            (key: "action", value: .string("revoke_app_session")),
            (key: "apk_api_key_id", value: .number(Double(apiKeyID))),
        ]))
    }

    public func revokeAllAppSessions() async throws {
        _ = try await client.submitAction("security", body: .object([
            (key: "action", value: .string("revoke_all_app_sessions")),
        ]))
    }

    private func securityAction(
        _ action: String,
        extra: [(key: String, value: JSONValue)] = []
    ) async throws -> TOTPSetupState {
        let envelope = try await client.submitAction("security", body: .object(
            [(key: "action", value: .string(action))] + extra
        ))
        guard let state = TOTPSetupState(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return state
    }
}
