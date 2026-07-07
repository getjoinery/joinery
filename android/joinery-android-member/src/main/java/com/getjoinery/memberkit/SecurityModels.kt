package com.getjoinery.memberkit

import com.getjoinery.android.JsonValue

/** One row of `security_overview`'s `app_sessions` — the only read surface for
 *  the session key (it has no CRUD exposure by design). */
data class AppSessionRow(
    val apiKeyId: Int,
    val deviceLabel: String,
    val createdTime: String,
    val lastUsedTime: String?,
    val isCurrent: Boolean,
) {
    companion object {
        fun from(json: JsonValue): AppSessionRow? {
            val apiKeyId = json["api_key_id"]?.intValue ?: return null
            return AppSessionRow(
                apiKeyId = apiKeyId,
                deviceLabel = json["device_label"]?.stringValue ?: "App session",
                createdTime = json["created_time"]?.stringValue ?: "",
                lastUsedTime = json["last_used_time"]?.takeUnless { it.isNull }?.stringValue,
                isCurrent = json["is_current"]?.boolValue ?: false,
            )
        }
    }
}

/** The `security_overview` payload. */
data class SecurityOverview(
    val totpEnabled: Boolean,
    val totpEnabledTime: String?,
    val backupCodesRemaining: Int,
    val appSessions: List<AppSessionRow>,
    val passkeyCount: Int,
    val vaultActive: Boolean,
) {
    companion object {
        fun from(data: JsonValue?): SecurityOverview? {
            if (data == null) return null
            return SecurityOverview(
                totpEnabled = data["totp_enabled"]?.boolValue ?: false,
                totpEnabledTime = data["totp_enabled_time"]?.takeUnless { it.isNull }?.stringValue,
                backupCodesRemaining = data["backup_codes_remaining"]?.intValue ?: 0,
                appSessions = (data["app_sessions"]?.arrayValue ?: emptyList())
                    .mapNotNull { AppSessionRow.from(it) },
                passkeyCount = data["passkey_count"]?.intValue ?: 0,
                vaultActive = data["vault_active"]?.boolValue ?: false,
            )
        }
    }
}

/**
 * The `security` action's render-based responses (`start_enable`,
 * `confirm_enable` on failure or success, `regenerate_backup_codes`).
 * `revoke_app_session` / `revoke_all_app_sessions` / `disable` /
 * `cancel_enable` redirect server-side instead — an empty `data: {}` with no
 * fields here, so the caller treats a non-throwing call as success for those
 * (docs/api.md § redirect envelope).
 */
data class TotpSetupState(
    val totpEnabled: Boolean,
    val totpEnabledTime: String?,
    val setupInProgress: Boolean,
    val provisioningUri: String?,
    val backupCodes: List<String>?,
    val justEnabled: Boolean,
) {
    companion object {
        fun from(data: JsonValue?): TotpSetupState? {
            if (data == null) return null
            return TotpSetupState(
                totpEnabled = data["totp_enabled"]?.boolValue ?: false,
                totpEnabledTime = data["totp_enabled_time"]?.takeUnless { it.isNull }?.stringValue,
                setupInProgress = data["setup_in_progress"]?.boolValue ?: false,
                provisioningUri = data["provisioning_uri"]?.takeUnless { it.isNull }?.stringValue,
                backupCodes = data["backup_codes"]?.arrayValue?.mapNotNull { it.stringValue },
                justEnabled = data["just_enabled"]?.boolValue ?: false,
            )
        }
    }
}
