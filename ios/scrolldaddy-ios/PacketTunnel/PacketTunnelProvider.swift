import Foundation
import NetworkExtension
import JoineryDNSFilterKit

/// Strict-mode enforcement (Phase 4). All device traffic enters this local
/// `NEPacketTunnelProvider` — it never leaves the phone. Two jobs:
///
///  1. **In-tunnel DNS** — answer lookups by forwarding to the ScrollDaddy DoH
///     resolver with the device UID, so policy stays server-side and unchanged.
///     HTTPS/SVCB records are stripped so Encrypted Client Hello can't hide the
///     SNI (the standard countermeasure).
///  2. **Connection-level hard blocking** — inspect each new TLS flow's SNI
///     (falling back to destination IP for non-TLS) and drop connections whose
///     host matches the synced hard-block list. This is what makes strict mode
///     "not just DNS": an app that bypasses DNS with its own hardcoded DoH
///     still can't complete the connection.
///
/// The matching and SNI-parsing logic lives in JoineryDNSFilterKit
/// (`HardBlockList`, `TLSClientHello`) so it is unit-tested off-device; this
/// class owns the NetworkExtension plumbing under the ~50MB extension ceiling.
final class PacketTunnelProvider: NEPacketTunnelProvider {

    private var hardBlockList = HardBlockList([])
    private var dohURL: String = ""
    /// Per-flow SNI decisions, so a flow is judged once on its ClientHello.
    private var relay: PacketRelay?

    // MARK: Lifecycle

    override func startTunnel(options: [String: NSObject]?, completionHandler: @escaping (Error?) -> Void) {
        let config = (protocolConfiguration as? NETunnelProviderProtocol)?.providerConfiguration ?? [:]
        dohURL = config[TunnelConfigKey.dohURL] as? String ?? ""
        let hosts = config[TunnelConfigKey.hardBlockHostnames] as? [String] ?? []
        hardBlockList = HardBlockList(hosts)

        let settings = Self.makeSettings()
        setTunnelNetworkSettings(settings) { [weak self] error in
            if let error {
                completionHandler(error)
                return
            }
            guard let self else { completionHandler(nil); return }
            self.relay = PacketRelay(
                packetFlow: self.packetFlow,
                dohURL: self.dohURL,
                decide: { [weak self] host in self?.hardBlockList.blocks(host) ?? false }
            )
            self.relay?.start()
            completionHandler(nil)
        }
    }

    override func stopTunnel(with reason: NEProviderStopReason, completionHandler: @escaping () -> Void) {
        relay?.stop()
        relay = nil
        completionHandler()
    }

    /// Live hard-block list updates pushed by the app on a policy change
    /// (StrictModeManager.syncHardBlockList) — no reconnect needed.
    override func handleAppMessage(_ messageData: Data, completionHandler: ((Data?) -> Void)?) {
        if let payload = try? JSONSerialization.jsonObject(with: messageData) as? [String: Any],
           let hosts = payload["hard_block_hostnames"] as? [String] {
            hardBlockList = HardBlockList(hosts)
            relay?.updateDecision { [weak self] host in self?.hardBlockList.blocks(host) ?? false }
        }
        completionHandler?(nil)
    }

    // MARK: Tunnel settings

    /// A local tunnel: a link-local virtual interface, DNS pointed at the
    /// in-tunnel resolver, default route so every flow is seen. The addresses
    /// are private to the device.
    private static func makeSettings() -> NEPacketTunnelNetworkSettings {
        let settings = NEPacketTunnelNetworkSettings(tunnelRemoteAddress: "127.0.0.1")

        let ipv4 = NEIPv4Settings(addresses: ["10.64.0.2"], subnetMasks: ["255.255.255.0"])
        ipv4.includedRoutes = [NEIPv4Route.default()]
        settings.ipv4Settings = ipv4

        // DNS resolves through the tunnel's local handler so lookups ride the
        // deployment's DoH resolver with the device UID.
        let dns = NEDNSSettings(servers: ["10.64.0.1"])
        dns.matchDomains = [""]
        settings.dnsSettings = dns

        settings.mtu = 1500
        return settings
    }
}
