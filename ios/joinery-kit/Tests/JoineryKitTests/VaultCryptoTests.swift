import XCTest
import CryptoKit
@testable import JoineryKit

/// The client-custody formats against the shared vectors the browser, PHP and
/// the Go relay sealer are tested with (tests/vault/fixtures/edge_vector.json,
/// device_handoff_vector.json; plugins/mailbox/tests/fixtures/relay_pin_vector.json).
/// Fixtures/ holds verbatim copies; the gate checks they match the originals.
final class VaultCryptoTests: XCTestCase {

    private func vector(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json")
        return try JSONValue.parse(Data(contentsOf: url))
    }

    private func s(_ v: JSONValue?, _ k: String) -> String { v?[k]?.stringValue ?? "" }

    // MARK: edge_vector.json

    func testEdgeVectorOpensTheSealedDek() throws {
        let v = try vector("edge_vector")
        let secret = Data(hex: s(v, "recipient_secret_hex"))
        let dek = try VaultCrypto.open(sealed: s(v, "sealed_dek_b64"), secret: secret)
        XCTAssertEqual(dek, Data(hex: s(v, "dek_hex")))
        // The same secret as PKCS#8 opens it too.
        let viaPKCS8 = try VaultCrypto.open(sealed: s(v, "sealed_dek_b64"), secret: VaultCrypto.pkcs8(fromScalar: secret))
        XCTAssertEqual(viaPKCS8, dek)
    }

    func testEdgeVectorSealIsReproducedByteForByte() throws {
        let v = try vector("edge_vector")
        let eph = try Curve25519.KeyAgreement.PrivateKey(rawRepresentation: Data(hex: s(v, "eph_secret_hex")))
        XCTAssertEqual(eph.publicKey.rawRepresentation, Data(hex: s(v, "eph_public_hex")))
        let sealed = try VaultCrypto.seal(Data(hex: s(v, "dek_hex")), toPublicKey: s(v, "recipient_public_b64"),
                                          ephemeral: eph, iv: Data(hex: s(v, "seal_iv_hex")))
        XCTAssertEqual(sealed, s(v, "sealed_dek_b64"))
    }

    func testEdgeVectorFieldOpensAndReseals() throws {
        let v = try vector("edge_vector")
        let key = SymmetricKey(data: Data(hex: s(v, "dek_hex")))
        let text = try VaultCrypto.openField(VaultCrypto.edgeField + s(v, "field_blob_b64"), key: key, ad: s(v, "field_ad"))
        XCTAssertEqual(text, s(v, "field_plaintext"))
        let blob = try VaultCrypto.encryptBytes(Data(s(v, "field_plaintext").utf8), key: key,
                                                ad: s(v, "field_ad"), iv: Data(hex: s(v, "field_iv_hex")))
        XCTAssertEqual(blob, s(v, "field_blob_b64"))
    }

    func testAnotherAdOrKeyIsRefused() throws {
        let v = try vector("edge_vector")
        let key = SymmetricKey(data: Data(hex: s(v, "dek_hex")))
        let value = VaultCrypto.edgeField + s(v, "field_blob_b64")
        XCTAssertThrowsError(try VaultCrypto.openField(value, key: key, ad: "acn:43:acn_body"))
        XCTAssertThrowsError(try VaultCrypto.openField(value, key: SymmetricKey(size: .bits256), ad: s(v, "field_ad")))
        let wrongSecret = Curve25519.KeyAgreement.PrivateKey().rawRepresentation
        XCTAssertThrowsError(try VaultCrypto.open(sealed: s(v, "sealed_dek_b64"), secret: wrongSecret))
    }

    func testPublicKeyWorkedOutFromTheSecret() throws {
        let v = try vector("edge_vector")
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: Data(hex: s(v, "recipient_secret_hex"))),
                       s(v, "recipient_public_b64"))
    }

    // MARK: device_handoff_vector.json

    func testDeviceHandoffOpensToThePKCS8Secret() throws {
        let v = try vector("device_handoff_vector")
        let deviceSecret = Data(hex: s(v["device"], "secret_hex"))
        let dev = try Curve25519.KeyAgreement.PrivateKey(rawRepresentation: deviceSecret)
        XCTAssertEqual(VaultCrypto.b64(dev.publicKey.rawRepresentation), s(v["device"], "public_b64"))
        let secret = try VaultCrypto.open(sealed: s(v["handoff"], "blob"), secret: deviceSecret)
        XCTAssertEqual(secret, Data(hex: s(v["vault"], "pkcs8_hex")))
        XCTAssertEqual(try VaultCrypto.scalar(fromPKCS8: secret), Data(hex: s(v["vault"], "secret_hex")))
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: secret), s(v["vault"], "public_b64"))
        // Reproduced from the seeded ephemeral key and IV.
        let eph = try Curve25519.KeyAgreement.PrivateKey(rawRepresentation: Data(hex: s(v["handoff"], "eph_secret_hex")))
        let again = try VaultCrypto.seal(secret, toPublicKey: s(v["device"], "public_b64"),
                                         ephemeral: eph, iv: Data(hex: s(v["handoff"], "iv_hex")))
        XCTAssertEqual(again, s(v["handoff"], "blob"))
    }

    func testDeviceHandoffRowOpensEveryField() throws {
        let v = try vector("device_handoff_vector")
        let secret = Data(hex: s(v["vault"], "pkcs8_hex"))
        let row = try XCTUnwrap(v["row"])
        let dek = try VaultCrypto.openDek(s(row, "sealed_dek"), scope: "mail", secret: secret)
        XCTAssertEqual(dek.withUnsafeBytes { Data($0) }, Data(hex: s(row, "dek_hex")))
        XCTAssertThrowsError(try VaultCrypto.openDek(s(row, "sealed_dek"), scope: "drive", secret: secret))
        for field in row["fields"]?.arrayValue ?? [] {
            XCTAssertEqual(try VaultCrypto.openField(s(field, "value"), key: dek, ad: s(field, "ad")), s(field, "plaintext"))
        }
        let st = try XCTUnwrap(row["search_text"])
        let packed = try VaultCrypto.openField(s(st, "value"), key: dek, ad: s(st, "ad"))
        XCTAssertEqual(packed, s(st, "packed"))
        XCTAssertEqual(try Gzip.inflateSearchText(packed), s(st, "text"))
        let part = try XCTUnwrap(row["part"])
        let bytes = try VaultCrypto.openEdgeBytes(s(part, "stored"), key: dek, ad: s(part, "ad"))
        XCTAssertEqual(bytes, Data(base64Encoded: s(part, "bytes_b64")))
        XCTAssertEqual(SHA256.hash(data: bytes).map { String(format: "%02x", $0) }.joined(), s(part, "sha256_hex"))
        XCTAssertThrowsError(try VaultCrypto.openEdgeBytes(s(part, "stored"), key: dek, ad: "mail:4242:att:3"))
    }

    // MARK: gzip

    func testGzipRoundTripsAndPacksLikeTheServer() throws {
        let text = String(repeating: "the quarterly numbers are attached ", count: 40)
        let packed = Gzip.packSearchText(text)
        XCTAssertTrue(packed.hasPrefix("gz:"))
        XCTAssertEqual(try Gzip.inflateSearchText(packed), text.trimmingCharacters(in: .whitespaces))
        XCTAssertEqual(Gzip.packSearchText("short  text\n"), "short text")
        // PHP gzencode() output, the server's writer.
        XCTAssertEqual(try Gzip.inflateSearchText("gz:H4sIAAAAAAACA0vLLyopSi0uVihOTSxKzlAoSa0oAQCiqAuHFAAAAA=="),
                       "fortress search text")
        XCTAssertEqual(Gzip.crc32(Data("123456789".utf8)), 0xCBF43926)
    }

    // MARK: relay_pin_vector.json

    func testRelayPinMacAndStatementSignature() throws {
        let v = try vector("relay_pin_vector")
        // The keyring's form of the secret: PKCS#8, not the raw scalar.
        let secret = VaultCrypto.pkcs8(fromScalar: Data(hex: s(v, "vault_secret_hex")))
        let msg = Data("joinery-relay-pin:v1\n\(v["alias_id"]?.intValue ?? 0)\n\(s(v, "relay_identity_public_key"))".utf8)
        XCTAssertEqual(VaultCrypto.b64(VaultCrypto.mac(secret: secret, info: "sealed-vault:pin", message: msg)),
                       s(v, "pin_mac"))
        XCTAssertNotEqual(VaultCrypto.b64(VaultCrypto.mac(secret: Data(hex: s(v, "vault_secret_hex")), info: "sealed-vault:pin",
                                                          message: msg)), s(v, "pin_mac"), "the raw scalar is not the MAC key")
        let signed = Data(("joinery-relay:seal-target:v1\n" + s(v, "statement")).utf8)
        XCTAssertTrue(VaultCrypto.verifyEd25519(publicKeyB64: s(v, "relay_identity_public_key"),
                                                message: signed, signatureB64: s(v, "signature")))
        let changed = Data(("joinery-relay:seal-target:v1\n" + s(v, "statement")
            .replacingOccurrences(of: "\"map_version\":7", with: "\"map_version\":8")).utf8)
        XCTAssertFalse(VaultCrypto.verifyEd25519(publicKeyB64: s(v, "relay_identity_public_key"),
                                                 message: changed, signatureB64: s(v, "signature")))
    }

    func testSealedDekParsing() {
        XCTAssertEqual(VaultCrypto.parseSealedDek("v1.edgeseal.mail.abc")?.scope, "mail")
        XCTAssertEqual(VaultCrypto.parseSealedDek("v1.edgeseal.mail.abc")?.blob, "abc")
        XCTAssertNil(VaultCrypto.parseSealedDek("v1.edgeseal..abc"))
        XCTAssertNil(VaultCrypto.parseSealedDek("v1.edge.abc"))
        XCTAssertNil(VaultCrypto.parseSealedDek("v1.edgeseal.Mail.abc"))
    }
}

/// The in-memory secrets and the background lock (specs/fortress_mobile_apps.md § R3).
@MainActor
final class HeldKeysTests: XCTestCase {
    func testBackgroundLongerThanTheIdleTimeDropsAndZeroes() {
        var clock = Date(timeIntervalSince1970: 1_000_000)
        let held = HeldKeys(now: { clock }, observeApp: false)
        held.idleMinutes = 5
        var locks = 0
        var saved = 0
        held.onLock { _ in locks += 1 }
        held.beforeLock { saved += 1 }
        held.hold(Data(repeating: 7, count: 48), scope: "mail")
        XCTAssertTrue(held.isOpen("mail"))

        held.enteredBackground()
        XCTAssertEqual(saved, 1, "an open compose is saved while its key still exists")
        clock += 60
        held.enteredForeground()
        XCTAssertTrue(held.isOpen("mail"), "a quick app switch keeps the key")
        held.enteredBackground()
        clock += 301
        held.enteredForeground()
        XCTAssertFalse(held.isOpen("mail"))
        XCTAssertEqual(locks, 1)
        XCTAssertNil(held.secret(scope: "mail"))
    }

    func testDropAllTakesExtrasAndBumpsTheEpoch() {
        let held = HeldKeys(observeApp: false)
        held.hold(Data([1, 2, 3]), scope: "mail")
        held.holdExtra(Data([4]), name: "search")
        let e = held.epoch
        held.dropAll()
        XCTAssertNil(held.extra("search"))
        XCTAssertGreaterThan(held.epoch, e)
        XCTAssertTrue(held.openScopes.isEmpty)
    }
}
