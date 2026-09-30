import Foundation

/// gzip (RFC 1952) over Apple's raw-DEFLATE codec: the `gz:` search texts the
/// server and the browser write, and the ones this app writes back.
public enum Gzip {
    public enum Failure: Error { case malformed }

    public static func inflate(_ data: Data) throws -> Data {
        let b = [UInt8](data)
        guard b.count >= 18, b[0] == 0x1f, b[1] == 0x8b, b[2] == 8 else { throw Failure.malformed }
        let flags = b[3]
        var p = 10
        if flags & 0x04 != 0 {                          // FEXTRA
            guard p + 2 <= b.count else { throw Failure.malformed }
            p += 2 + Int(b[p]) + Int(b[p + 1]) << 8
        }
        if flags & 0x08 != 0 { while p < b.count && b[p] != 0 { p += 1 }; p += 1 }   // FNAME
        if flags & 0x10 != 0 { while p < b.count && b[p] != 0 { p += 1 }; p += 1 }   // FCOMMENT
        if flags & 0x02 != 0 { p += 2 }                  // FHCRC
        guard p < b.count - 8 else { throw Failure.malformed }
        let deflated = Data(b[p..<(b.count - 8)])
        do {
            return try (deflated as NSData).decompressed(using: .zlib) as Data
        } catch {
            throw Failure.malformed
        }
    }

    public static func deflate(_ data: Data) throws -> Data {
        let body = try (data as NSData).compressed(using: .zlib) as Data
        var out = Data([0x1f, 0x8b, 8, 0, 0, 0, 0, 0, 0, 3])
        out.append(body)
        var crc = crc32(data).littleEndian
        var size = UInt32(truncatingIfNeeded: data.count).littleEndian
        withUnsafeBytes(of: &crc) { out.append(contentsOf: $0) }
        withUnsafeBytes(of: &size) { out.append(contentsOf: $0) }
        return out
    }

    private static let table: [UInt32] = (0..<256).map { n -> UInt32 in
        var c = UInt32(n)
        for _ in 0..<8 { c = (c & 1) != 0 ? 0xEDB88320 ^ (c >> 1) : c >> 1 }
        return c
    }

    public static func crc32(_ data: Data) -> UInt32 {
        var c: UInt32 = 0xFFFFFFFF
        for byte in data { c = table[Int((c ^ UInt32(byte)) & 0xFF)] ^ (c >> 8) }
        return c ^ 0xFFFFFFFF
    }

    /// A search text as stored: 'gz:' + base64(gzip), or plain.
    public static func inflateSearchText(_ value: String) throws -> String {
        guard value.hasPrefix("gz:") else { return value }
        guard let raw = Data(base64Encoded: String(value.dropFirst(3))) else { throw Failure.malformed }
        return String(decoding: try inflate(raw), as: UTF8.self)
    }

    /// InboundEmailMessage::searchTextFor's shape: whitespace folded, cut at
    /// 32768 characters, and 'gz:' + base64(gzip) when that is a third or more shorter.
    public static func packSearchText(_ text: String) -> String {
        let folded = text.split(whereSeparator: { $0.isWhitespace }).joined(separator: " ")
        let cut = String(folded.unicodeScalars.prefix(32768))
        if cut.isEmpty { return cut }
        let plain = Data(cut.utf8)
        guard let gz = try? deflate(plain) else { return cut }
        let packed = "gz:" + gz.base64EncodedString()
        return packed.utf8.count * 3 <= plain.count * 2 ? packed : cut
    }
}
