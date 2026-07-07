package com.getjoinery.memberkit

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/**
 * Thin typed face over `security_overview` (the read surface) and the existing
 * `security` action's TOTP + app-session mutations. `security` predates the
 * API-purpose-built pattern: most branches redirect server-side (empty
 * `data: {}` on both success and failure — the web page distinguishes them
 * with a flash message the native client can't read), so mutations that can
 * silently no-op re-read `security_overview` afterward to confirm the outcome
 * rather than trusting the envelope alone.
 */
class SecurityApi(val client: ApiClient) {

    suspend fun overview(): SecurityOverview {
        val envelope = client.submitAction("security_overview", JsonValue.Obj(emptyList()))
        return SecurityOverview.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Begin TOTP setup: the server mints a secret and returns its provisioning
     *  URI (an `otpauth://` string) for the QR. */
    suspend fun startEnable(): TotpSetupState = securityAction("start_enable")

    /** Confirm the 6-digit code from the authenticator app. On a bad code the
     *  server re-renders the same QR state with `justEnabled == false`; the
     *  caller keeps the setup sheet open for another attempt. */
    suspend fun confirmEnable(code: String): TotpSetupState =
        securityAction("confirm_enable", listOf("totp_code" to JsonValue.Str(code)))

    /** Abandon a pending setup. */
    suspend fun cancelEnable() {
        client.submitAction(
            "security",
            JsonValue.obj("action" to JsonValue.Str("cancel_enable")),
        )
    }

    suspend fun regenerateBackupCodes(): TotpSetupState = securityAction("regenerate_backup_codes")

    /** Disable TOTP with a current 6-digit code or an 8-character backup code.
     *  The server's `disable` branch reads a single `confirm_code` and
     *  classifies its shape itself (6 digits = authenticator code, 8 chars =
     *  backup code), so whichever the user entered is sent under that one key.
     *  The action redirects on both success and a bad code, so this re-reads
     *  `security_overview` to tell them apart. */
    suspend fun disable(totpCode: String, backupCode: String): Boolean {
        val confirmation = totpCode.ifEmpty { backupCode }
        val extra = ArrayList<Pair<String, JsonValue>>()
        extra.add("action" to JsonValue.Str("disable"))
        if (confirmation.isNotEmpty()) extra.add("confirm_code" to JsonValue.Str(confirmation))
        client.submitAction("security", JsonValue.Obj(extra))
        return !overview().totpEnabled
    }

    /** Revoke one app session. `revoke_app_session` redirects unconditionally
     *  (the ownership check is silent), so the caller reloads
     *  `security_overview` afterward to confirm. */
    suspend fun revokeAppSession(apiKeyId: Int) {
        client.submitAction(
            "security",
            JsonValue.obj(
                "action" to JsonValue.Str("revoke_app_session"),
                "apk_api_key_id" to JsonValue.Num(apiKeyId.toDouble()),
            ),
        )
    }

    suspend fun revokeAllAppSessions() {
        client.submitAction(
            "security",
            JsonValue.obj("action" to JsonValue.Str("revoke_all_app_sessions")),
        )
    }

    private suspend fun securityAction(
        action: String,
        extra: List<Pair<String, JsonValue>> = emptyList(),
    ): TotpSetupState {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("action" to JsonValue.Str(action))
        body.addAll(extra)
        val envelope = client.submitAction("security", JsonValue.Obj(body))
        return TotpSetupState.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }
}
