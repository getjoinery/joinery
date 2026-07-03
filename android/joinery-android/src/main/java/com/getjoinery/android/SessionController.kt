package com.getjoinery.android

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/** Session-key persistence. EncryptedCredentialStore is the production
 *  implementation; tests inject a fake. */
interface CredentialStore {
    fun loadCredentials(): ApiCredentials?
    fun saveCredentials(credentials: ApiCredentials)
    fun deleteCredentials()
}

/**
 * App-level auth state machine: one instance per app, owned by the root
 * composition. Holds the session key (encrypted-storage-backed), the signed-in
 * user summary, and the global upgrade gate.
 */
class SessionController(
    val client: ApiClient,
    private val store: CredentialStore,
    private val scope: CoroutineScope,
) {
    sealed class State {
        /** Checking storage / refreshing on launch. */
        object Launching : State()
        object LoggedOut : State()
        data class LoggedIn(val user: UserSummary) : State()
        /** Blocking 426 upgrade screen; `message` is the server's text. */
        data class UpgradeRequired(val message: String) : State()
    }

    var state by mutableStateOf<State>(State.Launching)
        private set

    /** Runs on every sign-out path (user action or 401 invalidation) — the
     *  navigation shell hooks this to drop the bridged webview session (cookies)
     *  along with the API key. */
    var onSignOut: (() -> Unit)? = null

    init {
        client.upgradeRequiredHandler = { message ->
            state = State.UpgradeRequired(message)
        }
        client.sessionInvalidatedHandler = {
            signOutLocally()
        }
    }

    // MARK: Lifecycle

    /** Call once at launch: restores stored credentials and validates them with
     *  `auth/session`. */
    suspend fun bootstrap() {
        val stored = store.loadCredentials()
        if (stored == null) {
            state = State.LoggedOut
            return
        }
        client.credentials = stored
        try {
            val envelope = client.request("GET", "/api/v1/auth/session")
            val user = UserSummary.from(envelope["data"])
            if (user != null) state = State.LoggedIn(user) else signOutLocally()
        } catch (e: JoineryApiError) {
            when (e) {
                is JoineryApiError.Authentication -> signOutLocally() // key revoked while away
                is JoineryApiError.UpgradeRequired -> state = State.UpgradeRequired(e.text)
                is JoineryApiError.Network -> {
                    // Offline at launch with a stored key: enter with a
                    // placeholder; the next successful call refreshes the summary.
                    state = State.LoggedIn(UserSummary.offlinePlaceholder)
                    scope.launch { refreshUser() }
                }
                else -> signOutLocally()
            }
        } catch (e: Exception) {
            signOutLocally()
        }
    }

    /** `auth/login`: mints a session key, stores it, enters the app. */
    suspend fun login(email: String, password: String, deviceLabel: String) {
        val body = JsonValue.obj(
            "email" to JsonValue.Str(email),
            "password" to JsonValue.Str(password),
            "device_label" to JsonValue.Str(deviceLabel),
        )
        val envelope = client.request("POST", "/api/v1/auth/login", body = body, authenticated = false)
        val result = LoginResult.from(envelope["data"]) ?: throw JoineryApiError.Malformed
        client.credentials = result.credentials
        store.saveCredentials(result.credentials)
        val user = result.user
        if (user != null) state = State.LoggedIn(user) else refreshUser()
    }

    /** `auth/logout` (revokes the key server-side), then clears local state.
     *  Local sign-out proceeds even if the revoke call fails — the user asked to
     *  leave, and a dead key is inert server-side anyway. */
    suspend fun logout() {
        try {
            client.request("POST", "/api/v1/auth/logout", body = JsonValue.Obj(emptyList()))
        } catch (_: Exception) {
        }
        signOutLocally()
    }

    /** Re-fetch the user summary (e.g. after account_edit changes the name). */
    suspend fun refreshUser() {
        if (client.credentials == null) return
        try {
            val envelope = client.request("GET", "/api/v1/auth/session")
            UserSummary.from(envelope["data"])?.let { state = State.LoggedIn(it) }
        } catch (_: Exception) {
        }
    }

    private fun signOutLocally() {
        client.credentials = null
        store.deleteCredentials()
        onSignOut?.invoke()
        state = State.LoggedOut
    }

    companion object {
        /** Production wiring: encrypted storage keyed to the app's package. */
        fun create(
            context: Context,
            config: JoineryConfig,
            storeFileName: String,
            scope: CoroutineScope,
        ): SessionController = SessionController(
            client = ApiClient(config),
            store = EncryptedCredentialStore(context, storeFileName),
            scope = scope,
        )
    }
}
