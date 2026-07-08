import Foundation
#if canImport(NetworkExtension)
import NetworkExtension
#endif

/// Where strict mode (the local packet tunnel) stands.
public enum StrictModeStatus: Equatable, Sendable {
    case off
    case connecting
    case on
    /// Another VPN is already installed/active; one-VPN-at-a-time means strict
    /// mode can't run alongside it. The app explains the conflict rather than
    /// silently failing.
    case vpnConflict
    case unsupported
}

/// Keys the app and the packet-tunnel extension agree on inside
/// `NETunnelProviderProtocol.providerConfiguration`. The extension reads the
/// DoH resolver URL (for in-tunnel DNS forwarding) and the hard-block hostname
/// list (for SNI/IP connection dropping) from here, and the app re-writes them
/// on every policy change.
public enum TunnelConfigKey {
    public static let dohURL = "doh_url"
    public static let hardBlockHostnames = "hard_block_hostnames"
    public static let brandName = "brand_name"
}

/// Manages the on-device `NEPacketTunnelProvider` for strict mode: install the
/// VPN profile, start/stop it, and push the current hard-block list into the
/// running extension whenever the policy changes. All traffic enters a local
/// tunnel that never leaves the device (`NEPacketTunnelProvider`, precedent:
/// Lockdown / AdGuard full protection).
public protocol StrictModeManaging: Sendable {
    func refresh() async -> StrictModeStatus
    /// Install (if needed) and start the tunnel with the current resolver URL
    /// and hard-block list. Shows the iOS VPN consent dialog on first install.
    func start(dohURL: String, hardBlockHostnames: [String], brandName: String) async throws
    func stop() async throws
    /// Push an updated hard-block list to the running tunnel without a
    /// reconnect (sync on every policy change).
    func syncHardBlockList(_ hostnames: [String]) async throws
}

public enum StrictModeError: LocalizedError {
    case unsupported
    case vpnConflict
    case invalidURL

    public var errorDescription: String? {
        switch self {
        case .unsupported: return "Strict mode requires a physical iPhone."
        case .vpnConflict: return "Another VPN is active. Strict mode needs the single VPN slot, so turn the other VPN off first."
        case .invalidURL: return "This device isn't registered with a resolver yet."
        }
    }
}

#if canImport(NetworkExtension)
/// Production implementation against `NETunnelProviderManager`.
public struct StrictModeManager: StrictModeManaging {
    /// The packet-tunnel extension's bundle identifier — the app target injects
    /// it (it's app-specific, e.g. `app.scrolldaddy.ios.tunnel`).
    public let tunnelBundleID: String

    public init(tunnelBundleID: String) {
        self.tunnelBundleID = tunnelBundleID
    }

    public func refresh() async -> StrictModeStatus {
        let managers = (try? await NETunnelProviderManager.loadAllFromPreferences()) ?? []
        guard let ours = mine(in: managers) else {
            // Not installed yet. If another VPN is actively holding the single
            // slot, surface the conflict up front (the orange footer); an
            // installed-but-idle foreign VPN isn't a conflict until we try.
            return isForeignVPNActive(in: managers) ? .vpnConflict : .off
        }
        switch ours.connection.status {
        case .connected: return .on
        case .connecting, .reasserting: return .connecting
        default:
            return isForeignVPNActive(in: managers) ? .vpnConflict : .off
        }
    }

    public func start(dohURL: String, hardBlockHostnames: [String], brandName: String) async throws {
        guard let _ = URL(string: dohURL), dohURL.hasPrefix("https://") else {
            throw StrictModeError.invalidURL
        }
        let managers = (try? await NETunnelProviderManager.loadAllFromPreferences()) ?? []
        // A different active VPN blocks us.
        if isForeignVPNActive(in: managers) {
            throw StrictModeError.vpnConflict
        }
        let manager = mine(in: managers) ?? NETunnelProviderManager()

        let proto = NETunnelProviderProtocol()
        proto.providerBundleIdentifier = tunnelBundleID
        // serverAddress is a display string only for a local tunnel.
        proto.serverAddress = brandName
        proto.providerConfiguration = [
            TunnelConfigKey.dohURL: dohURL,
            TunnelConfigKey.hardBlockHostnames: hardBlockHostnames,
            TunnelConfigKey.brandName: brandName,
        ]
        manager.protocolConfiguration = proto
        manager.localizedDescription = "\(brandName) Strict"
        manager.isEnabled = true

        try await manager.saveToPreferences()
        // A reload after save is required before the connection can start.
        try await manager.loadFromPreferences()
        try manager.connection.startVPNTunnel()
    }

    public func stop() async throws {
        let managers = (try? await NETunnelProviderManager.loadAllFromPreferences()) ?? []
        guard let manager = mine(in: managers) else { return }
        manager.connection.stopVPNTunnel()
    }

    public func syncHardBlockList(_ hostnames: [String]) async throws {
        let managers = (try? await NETunnelProviderManager.loadAllFromPreferences()) ?? []
        guard let manager = mine(in: managers),
              let proto = manager.protocolConfiguration as? NETunnelProviderProtocol else { return }
        var config = proto.providerConfiguration ?? [:]
        config[TunnelConfigKey.hardBlockHostnames] = hostnames
        proto.providerConfiguration = config
        manager.protocolConfiguration = proto
        try await manager.saveToPreferences()
        // Nudge the running extension to re-read via a provider message.
        if manager.connection.status == .connected,
           let session = manager.connection as? NETunnelProviderSession,
           let payload = try? JSONSerialization.data(withJSONObject: ["hard_block_hostnames": hostnames]) {
            try? session.sendProviderMessage(payload)
        }
    }

    private func mine(in managers: [NETunnelProviderManager]) -> NETunnelProviderManager? {
        managers.first {
            ($0.protocolConfiguration as? NETunnelProviderProtocol)?.providerBundleIdentifier == tunnelBundleID
        }
    }

    /// A VPN we don't own that is currently connected/connecting — the conflict
    /// case. Our own disconnected profile doesn't count.
    private func isForeignVPNActive(in managers: [NETunnelProviderManager]) -> Bool {
        managers.contains {
            let isOurs = ($0.protocolConfiguration as? NETunnelProviderProtocol)?.providerBundleIdentifier == tunnelBundleID
            let active = $0.connection.status == .connected || $0.connection.status == .connecting
            return !isOurs && active
        }
    }
}
#else
public struct StrictModeManager: StrictModeManaging {
    public let tunnelBundleID: String
    public init(tunnelBundleID: String) { self.tunnelBundleID = tunnelBundleID }
    public func refresh() async -> StrictModeStatus { .unsupported }
    public func start(dohURL: String, hardBlockHostnames: [String], brandName: String) async throws { throw StrictModeError.unsupported }
    public func stop() async throws {}
    public func syncHardBlockList(_ hostnames: [String]) async throws {}
}
#endif
