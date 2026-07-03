package com.getjoinery.android

/**
 * Client-side classification of API failures.
 *
 * The server's `errortype` vocabulary is closed (docs/api.md § Contract):
 * AuthenticationError, TransactionError, ActionError, ValidationError,
 * SecurityError, UpgradeRequired, RateLimitError, NotFound. Clients branch on
 * `errortype` + HTTP status; the `error` string is display-only.
 */
sealed class JoineryApiError(message: String) : Exception(message) {
    /** 426 UpgradeRequired — the app must update. Renders the blocking upgrade
     *  screen; nothing else is actionable. */
    data class UpgradeRequired(val text: String) : JoineryApiError(text)

    /** 429 RateLimitError — too many requests or failed auth attempts. */
    data class RateLimited(val text: String) : JoineryApiError(text)

    /** 401/403 AuthenticationError. A 401 mid-session means the key was revoked
     *  and the app should return to the login screen. */
    data class Authentication(val text: String, val status: Int) : JoineryApiError(text)

    /** 422 with a field-keyed `validation_errors` map (may be empty when the
     *  server produced only a top-level message). */
    data class Validation(val text: String, val fields: Map<String, String>) : JoineryApiError(text)

    /** Any other 4xx/5xx API error envelope. */
    data class Server(val errortype: String, val text: String, val status: Int) : JoineryApiError(text)

    /** Transport failure — offline, DNS, TLS, timeout. */
    data class Network(val underlying: Throwable) : JoineryApiError("Could not reach the server. Check your connection and try again.")

    /** Response was not a valid API envelope. */
    object Malformed : JoineryApiError("The server returned an unexpected response.")

    /** Human-readable message for display. */
    val displayMessage: String
        get() = when (this) {
            is UpgradeRequired -> text
            is RateLimited -> text
            is Authentication -> text
            is Validation -> text
            is Server -> text
            is Network -> "Could not reach the server. Check your connection and try again."
            is Malformed -> "The server returned an unexpected response."
        }

    val fieldErrors: Map<String, String>
        get() = (this as? Validation)?.fields ?: emptyMap()
}
