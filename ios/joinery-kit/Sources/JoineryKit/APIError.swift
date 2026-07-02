import Foundation

/// Client-side classification of API failures.
///
/// The server's `errortype` vocabulary is closed (docs/api.md § Contract):
/// AuthenticationError, TransactionError, ActionError, ValidationError,
/// SecurityError, UpgradeRequired, RateLimitError, NotFound. Clients branch
/// on `errortype` + HTTP status; the `error` string is display-only.
public enum JoineryAPIError: Error {
    /// 426 UpgradeRequired — the app must update. Renders the blocking
    /// upgrade screen; nothing else is actionable.
    case upgradeRequired(message: String)
    /// 429 RateLimitError — too many requests or failed auth attempts.
    case rateLimited(message: String)
    /// 401/403 AuthenticationError. `isInvalidCredentials` is true for the
    /// login endpoint's 401 (bad email/password); a 401 mid-session means the
    /// key was revoked and the app should return to the login screen.
    case authentication(message: String, status: Int)
    /// 422 with a field-keyed `validation_errors` map (may be empty when the
    /// server produced only a top-level message).
    case validation(message: String, fieldErrors: [String: String])
    /// Any other 4xx/5xx API error envelope.
    case server(errortype: String, message: String, status: Int)
    /// Transport failure — offline, DNS, TLS, timeout.
    case network(underlying: Error)
    /// Response was not a valid API envelope.
    case malformedResponse

    /// Human-readable message for display.
    public var displayMessage: String {
        switch self {
        case .upgradeRequired(let m): return m
        case .rateLimited(let m): return m
        case .authentication(let m, _): return m
        case .validation(let m, _): return m
        case .server(_, let m, _): return m
        case .network: return "Could not reach the server. Check your connection and try again."
        case .malformedResponse: return "The server returned an unexpected response."
        }
    }

    public var fieldErrors: [String: String] {
        if case .validation(_, let fields) = self { return fields }
        return [:]
    }
}
