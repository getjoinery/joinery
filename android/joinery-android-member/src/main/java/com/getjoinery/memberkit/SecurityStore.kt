package com.getjoinery.memberkit

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError

/**
 * State for the security screen: the app-session list, TOTP status, and the
 * enable/confirm/disable/regenerate flows. All writes go through SecurityApi
 * and reload `security_overview` — the server is the single source of truth,
 * shared with the web security page.
 */
class SecurityStore(val api: SecurityApi) {

    /** The TOTP setup sheet's own phase, separate from the screen load. */
    sealed class SetupPhase {
        object Idle : SetupPhase()
        data class AwaitingCode(val provisioningUri: String) : SetupPhase()
        data class JustEnabled(val backupCodes: List<String>) : SetupPhase()
    }

    var phase by mutableStateOf<MemberPhase>(MemberPhase.Loading)
        private set
    var overview by mutableStateOf<SecurityOverview?>(null)
        private set
    var setupPhase by mutableStateOf<SetupPhase>(SetupPhase.Idle)
        private set
    var setupError by mutableStateOf<String?>(null)
        private set
    var isBusy by mutableStateOf(false)
        private set

    /** Set once a revoke call turns out to have killed the session that made
     *  it — the screen signs the app out through this signal (the core 401
     *  path handles the actual credential teardown; this forces a `logout()`
     *  so the app leaves promptly instead of waiting for the next request to
     *  401). */
    var onSelfRevoked: (suspend () -> Unit)? = null

    private var loadGeneration = 0

    suspend fun initialLoad() {
        phase = MemberPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val loaded = api.overview()
            if (generation != loadGeneration) return
            overview = loaded
            phase = MemberPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is MemberPhase.Loaded) return
            phase = MemberPhase.Failed(displayMessage(e))
        }
    }

    // MARK: TOTP setup

    suspend fun startSetup() {
        setupError = null
        try {
            val state = api.startEnable()
            val uri = state.provisioningUri
            if (!uri.isNullOrEmpty()) setupPhase = SetupPhase.AwaitingCode(uri)
        } catch (e: Exception) {
            setupError = (e as? JoineryApiError)?.displayMessage ?: "Could not start setup."
        }
    }

    suspend fun confirmSetup(code: String) {
        setupError = null
        isBusy = true
        try {
            val state = api.confirmEnable(code)
            if (state.justEnabled) {
                setupPhase = SetupPhase.JustEnabled(state.backupCodes ?: emptyList())
                reload()
            } else if (state.provisioningUri != null) {
                setupPhase = SetupPhase.AwaitingCode(state.provisioningUri)
                setupError = "That code did not match. Please try again."
            }
        } catch (e: Exception) {
            setupError = (e as? JoineryApiError)?.displayMessage ?: "Could not confirm the code."
        } finally {
            isBusy = false
        }
    }

    suspend fun cancelSetup() {
        setupPhase = SetupPhase.Idle
        setupError = null
        try {
            api.cancelEnable()
        } catch (e: Exception) {
            // Abandoning setup is best-effort; the server expires it anyway.
        }
    }

    fun finishSetup() {
        setupPhase = SetupPhase.Idle
        setupError = null
    }

    suspend fun regenerateBackupCodes(): List<String>? {
        setupError = null
        return try {
            val state = api.regenerateBackupCodes()
            reload()
            state.backupCodes
        } catch (e: Exception) {
            setupError = (e as? JoineryApiError)?.displayMessage ?: "Could not regenerate backup codes."
            null
        }
    }

    suspend fun disable(totpCode: String, backupCode: String): Boolean {
        setupError = null
        isBusy = true
        return try {
            val succeeded = api.disable(totpCode, backupCode)
            reload()
            if (!succeeded) {
                setupError = "Please confirm with a current 6-digit code or an 8-character backup code."
            }
            succeeded
        } catch (e: Exception) {
            setupError = (e as? JoineryApiError)?.displayMessage
                ?: "Could not disable two-factor authentication."
            false
        } finally {
            isBusy = false
        }
    }

    // MARK: App sessions

    suspend fun revoke(session: AppSessionRow) {
        try {
            api.revokeAppSession(session.apiKeyId)
        } catch (e: Exception) {
            // Fall through to reload either way.
        }
        // Revoking the current key kills the app's own session: an explicit
        // callback leaves promptly, otherwise the reload below runs on the dead
        // key, 401s, and the core sessionInvalidated handler signs the app out.
        if (session.isCurrent) {
            val cb = onSelfRevoked
            if (cb != null) { cb(); return }
        }
        reload()
    }

    suspend fun revokeAll() {
        val hadCurrent = overview?.appSessions?.any { it.isCurrent } ?: false
        try {
            api.revokeAllAppSessions()
        } catch (e: Exception) {
            // Fall through to reload either way.
        }
        if (hadCurrent) {
            val cb = onSelfRevoked
            if (cb != null) { cb(); return }
        }
        reload()
    }
}
