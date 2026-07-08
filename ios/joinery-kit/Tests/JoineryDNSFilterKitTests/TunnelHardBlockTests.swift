import XCTest
@testable import JoineryDNSFilterKit

final class TunnelHardBlockTests: XCTestCase {

    // MARK: HardBlockList

    func testExactMatch() {
        let list = HardBlockList(["example.com", "tracker.net"])
        XCTAssertTrue(list.blocks("example.com"))
        XCTAssertTrue(list.blocks("tracker.net"))
        XCTAssertFalse(list.blocks("notblocked.com"))
    }

    func testSubdomainMatch() {
        let list = HardBlockList(["example.com"])
        XCTAssertTrue(list.blocks("cdn.example.com"))
        XCTAssertTrue(list.blocks("a.b.example.com"))
        // A sibling that merely ends in the string but isn't a subdomain.
        XCTAssertFalse(list.blocks("notexample.com"))
        XCTAssertFalse(list.blocks("example.com.evil.net"))
    }

    func testCaseAndTrailingDotInsensitive() {
        let list = HardBlockList(["Example.COM."])
        XCTAssertTrue(list.blocks("EXAMPLE.com"))
        XCTAssertTrue(list.blocks("cdn.example.com."))
    }

    func testEmptyList() {
        let list = HardBlockList([])
        XCTAssertTrue(list.isEmpty)
        XCTAssertFalse(list.blocks("anything.com"))
    }

    // MARK: SNI extraction

    func testExtractSNIFromClientHello() {
        let hello = Self.clientHello(serverName: "blocked.example.com")
        XCTAssertEqual(TLSClientHello.serverName(from: hello), "blocked.example.com")
    }

    func testClientHelloWithoutSNIReturnsNil() {
        let hello = Self.clientHello(serverName: nil)
        XCTAssertNil(TLSClientHello.serverName(from: hello))
    }

    func testNonHandshakeRecordReturnsNil() {
        // Application-data content type (23), not a handshake.
        XCTAssertNil(TLSClientHello.serverName(from: [23, 3, 3, 0, 1, 0]))
    }

    func testTruncatedRecordDoesNotCrash() {
        let full = Self.clientHello(serverName: "example.com")
        for cut in 0..<full.count {
            // Any prefix must parse to nil or the name, never trap.
            _ = TLSClientHello.serverName(from: Array(full.prefix(cut)))
        }
    }

    func testSNIEnforcementEndToEnd() {
        // The whole point of strict mode: a ClientHello naming a blocked host is
        // caught by SNI even though this test never touched DNS.
        let list = HardBlockList(["example.com"])
        let hello = Self.clientHello(serverName: "cdn.example.com")
        let sni = TLSClientHello.serverName(from: hello)
        XCTAssertNotNil(sni)
        XCTAssertTrue(list.blocks(sni!))
    }

    // MARK: ClientHello builder

    /// Assemble a minimal but structurally valid TLS 1.2 ClientHello, optionally
    /// carrying an SNI extension. Lengths are computed, so the parser is
    /// exercised against real framing rather than hand-counted constants.
    static func clientHello(serverName: String?) -> [UInt8] {
        var extensions: [UInt8] = []
        if let serverName {
            let host = Array(serverName.utf8)
            var sniEntry: [UInt8] = [0x00]                 // name type host_name
            sniEntry += u16(host.count) + host
            let extData = u16(sniEntry.count) + sniEntry   // server_name_list
            extensions += u16(0x0000)                      // extension type SNI
            extensions += u16(extData.count) + extData
        }

        var body: [UInt8] = [0x03, 0x03]                   // client version
        body += [UInt8](repeating: 0, count: 32)           // random
        body += [0x00]                                     // session id length
        body += u16(2) + [0x00, 0x2f]                      // cipher suites
        body += [0x01, 0x00]                               // compression methods
        body += u16(extensions.count) + extensions         // extensions block

        var handshake: [UInt8] = [0x01]                    // ClientHello
        handshake += u24(body.count) + body

        var record: [UInt8] = [0x16, 0x03, 0x01]           // handshake, version
        record += u16(handshake.count) + handshake
        return record
    }

    private static func u16(_ v: Int) -> [UInt8] { [UInt8((v >> 8) & 0xff), UInt8(v & 0xff)] }
    private static func u24(_ v: Int) -> [UInt8] { [UInt8((v >> 16) & 0xff), UInt8((v >> 8) & 0xff), UInt8(v & 0xff)] }
}
