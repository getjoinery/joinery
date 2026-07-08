import Foundation

/// The strict-mode enforcement core, kept out of the NetworkExtension target
/// so it is plain, deterministic, and unit-testable. Two jobs:
///
/// 1. **Hostname matching** (`HardBlockList`) — the synced list of always-on,
///    block-action, hard-block hostnames. A block matches the host exactly or
///    any subdomain of it, so blocking `example.com` also stops
///    `cdn.example.com`.
/// 2. **SNI extraction** (`TLSClientHello`) — pull the destination hostname
///    out of a TLS ClientHello. This is what makes strict mode "not just DNS":
///    a connection to a blocked host is dropped even when the app resolved the
///    IP through its own hardcoded DoH, because the SNI still names the host.
///
/// The packet tunnel provider (in the app's extension target) owns the socket
/// plumbing; it calls into these two types for every decision.
public struct HardBlockList: Equatable, Sendable {
    /// Lowercased, dot-trimmed hostnames.
    public let hosts: Set<String>

    public init(_ hostnames: [String]) {
        hosts = Set(hostnames.map(Self.normalize).filter { !$0.isEmpty })
    }

    public var isEmpty: Bool { hosts.isEmpty }

    /// Exact match, or `host` is a subdomain of a blocked name. Case- and
    /// trailing-dot-insensitive.
    public func blocks(_ host: String) -> Bool {
        let name = Self.normalize(host)
        guard !name.isEmpty else { return false }
        if hosts.contains(name) { return true }
        // Walk parent domains: a.b.example.com -> b.example.com -> example.com.
        var idx = name.startIndex
        while let dot = name[idx...].firstIndex(of: ".") {
            let parent = String(name[name.index(after: dot)...])
            if hosts.contains(parent) { return true }
            idx = name.index(after: dot)
        }
        return false
    }

    static func normalize(_ host: String) -> String {
        var h = host.lowercased()
        while h.hasSuffix(".") { h.removeLast() }
        while h.hasPrefix(".") { h.removeFirst() }
        return h
    }
}

/// Minimal TLS ClientHello reader — just enough to recover the SNI host name.
/// Operates on the bytes at the start of a TLS record (handshake content type).
public enum TLSClientHello {
    /// Return the SNI host_name from a ClientHello, or nil if the bytes are not
    /// a ClientHello or carry no SNI extension. Bounds-checked throughout: a
    /// truncated or malformed record yields nil, never a crash.
    public static func serverName(from bytes: [UInt8]) -> String? {
        var r = Reader(bytes)

        // TLS record header: type(1) version(2) length(2).
        guard let contentType = r.u8(), contentType == 22 else { return nil } // handshake
        guard r.skip(2) else { return nil }                                   // record version
        guard let recordLen = r.u16() else { return nil }
        // The handshake message must fit inside the declared record.
        let handshakeEnd = min(r.offset + Int(recordLen), bytes.count)

        // Handshake header: type(1) length(3).
        guard let hsType = r.u8(), hsType == 1 else { return nil }            // ClientHello
        guard r.skip(3) else { return nil }                                   // handshake length

        // ClientHello body.
        guard r.skip(2) else { return nil }                                   // client version
        guard r.skip(32) else { return nil }                                  // random
        guard let sidLen = r.u8(), r.skip(Int(sidLen)) else { return nil }    // session id
        guard let csLen = r.u16(), r.skip(Int(csLen)) else { return nil }     // cipher suites
        guard let compLen = r.u8(), r.skip(Int(compLen)) else { return nil }  // compression
        guard let extTotal = r.u16() else { return nil }                      // extensions block
        let extEnd = min(r.offset + Int(extTotal), handshakeEnd)

        // Walk extensions looking for server_name (0x0000).
        while r.offset + 4 <= extEnd {
            guard let extType = r.u16(), let extLen = r.u16() else { return nil }
            let extDataEnd = r.offset + Int(extLen)
            guard extDataEnd <= extEnd else { return nil }
            if extType == 0x0000 {
                return parseServerNameList(&r, end: extDataEnd)
            }
            r.offset = extDataEnd
        }
        return nil
    }

    private static func parseServerNameList(_ r: inout Reader, end: Int) -> String? {
        // ServerNameList: list_length(2), then entries of type(1) length(2) name.
        guard let _ = r.u16() else { return nil } // list length (bounded by end)
        while r.offset + 3 <= end {
            guard let nameType = r.u8(), let nameLen = r.u16() else { return nil }
            let nameEnd = r.offset + Int(nameLen)
            guard nameEnd <= end else { return nil }
            if nameType == 0 { // host_name
                let slice = Array(r.bytes[r.offset..<nameEnd])
                return String(bytes: slice, encoding: .utf8)
            }
            r.offset = nameEnd
        }
        return nil
    }

    /// A bounds-safe cursor over the record bytes.
    private struct Reader {
        let bytes: [UInt8]
        var offset = 0
        init(_ bytes: [UInt8]) { self.bytes = bytes }

        mutating func u8() -> UInt8? {
            guard offset < bytes.count else { return nil }
            defer { offset += 1 }
            return bytes[offset]
        }
        mutating func u16() -> Int? {
            guard offset + 2 <= bytes.count else { return nil }
            defer { offset += 2 }
            return Int(bytes[offset]) << 8 | Int(bytes[offset + 1])
        }
        mutating func skip(_ n: Int) -> Bool {
            guard n >= 0, offset + n <= bytes.count else { return false }
            offset += n
            return true
        }
    }
}
