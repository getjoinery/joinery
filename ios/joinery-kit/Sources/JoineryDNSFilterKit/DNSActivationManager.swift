import Foundation
#if canImport(NetworkExtension)
import NetworkExtension
#endif

/// Where the device stands relative to the saved DoH configuration. The
/// activation flow is a state machine over this: install → guide the one-time
/// Settings enable → verify Protected.
public enum DNSActivationStatus: Equatable, Sendable {
    /// No DoH configuration saved yet (fresh install, or turned off).
    case notConfigured
    /// Saved, but the user has not flipped the one-time Settings toggle. iOS
    /// requires that single manual step; the app deep-links them to it.
    case needsEnable
    /// Saved and enabled — the device is filtered.
    case protected
    /// The DNS Settings capability is unavailable (Simulator, or missing the
    /// paid-developer entitlement). Standard mode can't be exercised here.
    case unsupported
}

/// Wraps `NEDNSSettingsManager`, the native API that installs a system-wide
/// encrypted-DNS (DoH) configuration — the same mechanism NextDNS/AdGuard use.
/// Saving the device's `doh_url` is all standard mode needs; iOS layers it over
/// the network's DNS non-destructively, so disabling or uninstalling reverts
/// losslessly with nothing to restore.
///
/// The one unavoidable manual step is the OS security gate: after `install`,
/// the user enables the profile once in Settings. `refresh` detects that via
/// `isEnabled`; every later change (new UID, server switch) then applies
/// silently.
public protocol DNSActivating: Sendable {
    func refresh() async -> DNSActivationStatus
    /// Save (or update) the DoH configuration pointing at the device's resolver
    /// URL. Idempotent — re-saving a changed URL applies silently once enabled.
    func install(dohURL: String, brandName: String) async throws
    /// Remove the configuration entirely ("Turn off"): iOS reverts to the
    /// network's original DNS immediately.
    func remove() async throws
}

public enum DNSActivationError: LocalizedError {
    case unsupported
    case invalidURL

    public var errorDescription: String? {
        switch self {
        case .unsupported:
            return "Encrypted DNS isn't available on this device. It requires a physical iPhone."
        case .invalidURL:
            return "This device isn't registered with a resolver yet."
        }
    }
}

#if canImport(NetworkExtension)
/// Production implementation against `NEDNSSettingsManager.shared()`.
public struct DNSActivationManager: DNSActivating {
    public init() {}

    public func refresh() async -> DNSActivationStatus {
        let manager = NEDNSSettingsManager.shared()
        do {
            try await manager.loadFromPreferences()
        } catch {
            return .unsupported
        }
        guard manager.dnsSettings != nil else { return .notConfigured }
        return manager.isEnabled ? .protected : .needsEnable
    }

    public func install(dohURL: String, brandName: String) async throws {
        guard let url = URL(string: dohURL), url.scheme == "https" else {
            throw DNSActivationError.invalidURL
        }
        let manager = NEDNSSettingsManager.shared()
        do {
            try await manager.loadFromPreferences()
        } catch {
            throw DNSActivationError.unsupported
        }
        let settings = NEDNSOverHTTPSSettings(servers: [])
        settings.serverURL = url
        manager.dnsSettings = settings
        manager.localizedDescription = brandName
        // No onDemand rules: the configuration applies on every network. iOS
        // still gates the first enable through Settings.
        try await manager.saveToPreferences()
    }

    public func remove() async throws {
        let manager = NEDNSSettingsManager.shared()
        try? await manager.loadFromPreferences()
        try await manager.removeFromPreferences()
    }
}
#else
/// Fallback for platforms without NetworkExtension so the package still builds.
public struct DNSActivationManager: DNSActivating {
    public init() {}
    public func refresh() async -> DNSActivationStatus { .unsupported }
    public func install(dohURL: String, brandName: String) async throws { throw DNSActivationError.unsupported }
    public func remove() async throws {}
}
#endif

#if canImport(UIKit)
import UIKit
#endif

/// The App Store-safe deep link into Settings. `App-prefs:` deep links into
/// specific panes are a private-API rejection trigger; `openSettingsURLString`
/// lands on the app's own Settings page (the sanctioned path), from which the
/// DNS row is one tap away. Onboarding shows the "tap here, flip this" step.
public var dnsSettingsDeepLink: URL? {
    #if canImport(UIKit)
    return URL(string: UIApplication.openSettingsURLString)
    #else
    return nil
    #endif
}
