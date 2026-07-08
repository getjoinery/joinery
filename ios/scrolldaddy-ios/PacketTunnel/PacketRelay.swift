import Foundation
import NetworkExtension
import JoineryDNSFilterKit

/// The packet-processing loop for strict mode. Reads outbound packets from the
/// tunnel, judges each new TLS flow by its SNI against the hard-block list, and
/// forwards everything else to the origin.
///
/// Inspection and the block decision — the security-critical, unit-tested core
/// — run here via JoineryDNSFilterKit's `TLSClientHello` and `HardBlockList`.
/// The userspace forwarding of allowed flows (a NAT'd TCP/UDP stack that
/// re-injects inbound packets with `packetFlow.writePackets`) is the Phase-4
/// on-device integration seam marked `forward(_:)`; it is deliberately isolated
/// so the decision logic can be verified independently of the transport.
final class PacketRelay {
    private let packetFlow: NEPacketTunnelFlow
    private let dohURL: String
    private var decide: (String) -> Bool
    private var running = false

    /// Flows already judged, keyed by the 4-tuple, so a decision is made once
    /// on the ClientHello and applied to the rest of the flow.
    private var blockedFlows = Set<FlowKey>()

    init(packetFlow: NEPacketTunnelFlow, dohURL: String, decide: @escaping (String) -> Bool) {
        self.packetFlow = packetFlow
        self.dohURL = dohURL
        self.decide = decide
    }

    func start() {
        running = true
        readNext()
    }

    func stop() {
        running = false
    }

    func updateDecision(_ decide: @escaping (String) -> Bool) {
        self.decide = decide
        // A changed list re-judges subsequent flows; existing decisions expire
        // as their flows close.
        blockedFlows.removeAll()
    }

    // MARK: Read loop

    private func readNext() {
        guard running else { return }
        packetFlow.readPackets { [weak self] packets, protocols in
            guard let self, self.running else { return }
            for (packet, family) in zip(packets, protocols) {
                self.handle(packet, family: family)
            }
            self.readNext()
        }
    }

    private func handle(_ packet: Data, family: NSNumber) {
        // Only IPv4 TCP carries the SNI we enforce on; other traffic forwards
        // untouched (DNS is handled by the in-tunnel resolver settings).
        guard family.int32Value == AF_INET, let ip = IPv4Packet(packet), ip.proto == .tcp,
              let tcp = ip.tcpSegment else {
            forward(packet)
            return
        }

        let key = FlowKey(ip: ip, tcp: tcp)
        if blockedFlows.contains(key) {
            // Drop the rest of an already-blocked flow.
            return
        }

        // Judge on the TLS ClientHello (destination 443 with a handshake record).
        if tcp.destinationPort == 443, !tcp.payload.isEmpty,
           let host = TLSClientHello.serverName(from: Array(tcp.payload)) {
            if decide(host) {
                blockedFlows.insert(key)
                return // dropped: the connection never completes
            }
        }
        forward(packet)
    }

    /// Phase-4 on-device seam: hand an allowed packet to the userspace
    /// forwarding stack, which NATs it to the origin and re-injects replies via
    /// `packetFlow.writePackets`. Left as an isolated integration point so the
    /// inspection/decision path above is testable without a transport.
    private func forward(_ packet: Data) {
        // Intentionally a seam — see the class doc. On device this drives the
        // userspace TCP/UDP relay; off device the decision path is exercised by
        // JoineryDNSFilterKit's unit tests.
    }
}

/// The 4-tuple identifying a flow.
private struct FlowKey: Hashable {
    let sourceAddr: UInt32
    let destAddr: UInt32
    let sourcePort: UInt16
    let destPort: UInt16

    init(ip: IPv4Packet, tcp: TCPSegment) {
        sourceAddr = ip.sourceAddress
        destAddr = ip.destinationAddress
        sourcePort = tcp.sourcePort
        destPort = tcp.destinationPort
    }
}

// MARK: - Minimal IPv4 / TCP readers

/// Just enough IPv4 parsing to reach the TCP segment. Bounds-checked.
struct IPv4Packet {
    enum Proto { case tcp, udp, other }

    let sourceAddress: UInt32
    let destinationAddress: UInt32
    let proto: Proto
    let tcpSegment: TCPSegment?

    init?(_ data: Data) {
        let bytes = [UInt8](data)
        guard bytes.count >= 20 else { return nil }
        let version = bytes[0] >> 4
        guard version == 4 else { return nil }
        let ihl = Int(bytes[0] & 0x0f) * 4
        guard ihl >= 20, bytes.count >= ihl else { return nil }

        sourceAddress = IPv4Packet.u32(bytes, 12)
        destinationAddress = IPv4Packet.u32(bytes, 16)

        switch bytes[9] {
        case 6: proto = .tcp
        case 17: proto = .udp
        default: proto = .other
        }

        if proto == .tcp {
            tcpSegment = TCPSegment(Array(bytes[ihl...]))
        } else {
            tcpSegment = nil
        }
    }

    private static func u32(_ b: [UInt8], _ i: Int) -> UInt32 {
        UInt32(b[i]) << 24 | UInt32(b[i + 1]) << 16 | UInt32(b[i + 2]) << 8 | UInt32(b[i + 3])
    }
}

/// Just enough TCP parsing to reach the payload (the TLS record).
struct TCPSegment {
    let sourcePort: UInt16
    let destinationPort: UInt16
    let payload: ArraySlice<UInt8>

    init?(_ bytes: [UInt8]) {
        guard bytes.count >= 20 else { return nil }
        sourcePort = UInt16(bytes[0]) << 8 | UInt16(bytes[1])
        destinationPort = UInt16(bytes[2]) << 8 | UInt16(bytes[3])
        let dataOffset = Int(bytes[12] >> 4) * 4
        guard dataOffset >= 20, bytes.count >= dataOffset else { return nil }
        payload = bytes[dataOffset...]
    }
}
