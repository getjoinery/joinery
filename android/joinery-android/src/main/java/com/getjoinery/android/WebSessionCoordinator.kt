package com.getjoinery.android

import android.os.Handler
import android.os.Looper
import android.webkit.CookieManager
import android.webkit.WebStorage
import okhttp3.HttpUrl.Companion.toHttpUrl

/**
 * Owns the app ↔ web session handshake for every webview in the app.
 *
 * The app authenticates with its API session key; webviews need a web session.
 * `POST /api/v1/auth/web_session` mints a single-use bridge URL (~60s TTL) that
 * starts an app-context web session and 302s to the target
 * (docs/mobile_apps.md § Web-session bridge). The bridged session lives in the
 * process-wide WebView cookie store, persists across launches, and is
 * lifetime-coupled server-side to the API key — so this class never stores a
 * web credential itself.
 */
class WebSessionCoordinator(private val client: ApiClient) {
    /** True once any webview has completed a bridge navigation this launch.
     *  Later webviews load their target directly on the shared cookie; a
     *  logged-out redirect (cookie expired server-side) re-bridges lazily. */
    var bridged = false
        private set

    private val mainHandler = Handler(Looper.getMainLooper())

    /** Mint a bridge URL that lands on `target` (a same-origin relative path).
     *  Each call is a fresh single-use token — concurrent webviews may each mint
     *  their own. */
    suspend fun bridgeUrl(target: String): String {
        val body = JsonValue.obj("target" to JsonValue.Str(target))
        val envelope = client.request("POST", "/api/v1/auth/web_session", body = body)
        val path = envelope["data"]?.get("bridge_url")?.stringValue ?: throw JoineryApiError.Malformed
        return absolute(path)
    }

    /** The absolute URL for a same-origin relative path. */
    fun pageUrl(target: String): String = absolute(target)

    val baseHost: String?
        get() = runCatching { client.config.baseUrl.toHttpUrl().host }.getOrNull()

    fun markBridged() {
        bridged = true
    }

    /** Sign-out: drop the bridged web session along with the API key — clears the
     *  site's cookies and storage from the shared WebView store
     *  (spec § Security notes). Off-site links open in the external browser, not
     *  this webview, so the store only ever holds our origin's cookies. */
    fun reset() {
        bridged = false
        CookieManager.getInstance().apply {
            removeAllCookies(null)
            flush()
        }
        // WebStorage must be touched on the main thread.
        mainHandler.post { WebStorage.getInstance().deleteAllData() }
    }

    private fun absolute(pathOrUrl: String): String =
        client.config.baseUrl.toHttpUrl().resolve(pathOrUrl)?.toString()
            ?: (client.config.baseUrl.trimEnd('/') + "/" + pathOrUrl.trimStart('/'))
}
