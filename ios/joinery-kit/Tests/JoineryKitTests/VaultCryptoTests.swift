import XCTest
import CryptoKit
@testable import JoineryKit

/// The client-custody formats against the shared vectors the browser, PHP, Rust
/// and the Go relay sealer are tested with (tests/vault/fixtures/xwing_vector.json,
/// edge_vector.json, device_handoff_vector.json;
/// plugins/mailbox/tests/fixtures/relay_pin_vector.json). Fixtures/ holds
/// verbatim copies; the gate checks they match the originals. CryptoKit's X-Wing
/// takes no caller-supplied randomness, so a seal here is checked by
/// decapsulating and opening the vectors' (specs/post_quantum_sealing.md R9).
final class VaultCryptoTests: XCTestCase {

    private func vector(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json")
        return try JSONValue.parse(Data(contentsOf: url))
    }

    private func s(_ v: JSONValue?, _ k: String) -> String { v?[k]?.stringValue ?? "" }

    // MARK: xwing_vector.json

    func testXWingDraftVectorsDecapsulate() throws {
        let list = try vector("xwing_vector")["xwing"]?.arrayValue ?? []
        XCTAssertEqual(list.count, 3)
        for (i, v) in list.enumerated() {
            let key = try VaultCrypto.xwingKey(seed: Data(hex: s(v, "seed")))
            XCTAssertEqual(key.publicKey.rawRepresentation, Data(hex: s(v, "pk")), "vector \(i): public key")
            let ss = try key.decapsulate(Data(hex: s(v, "ct")))
            XCTAssertEqual(ss.withUnsafeBytes { Data($0) }, Data(hex: s(v, "ss")), "vector \(i): shared secret")
        }
    }

    func testSealVectorsOpenOnlyUnderTheirScope() throws {
        let list = try vector("xwing_vector")["seal"]?.arrayValue ?? []
        XCTAssertTrue(list.contains { s($0, "scope") == "dek" })
        for v in list {
            let scope = s(v, "scope")
            let seed = Data(hex: s(v, "seed_hex"))
            let raw = try XCTUnwrap(Data(base64Encoded: s(v, "sealed_b64")))
            XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: seed), s(v, "public_b64"), "\(scope): public key")
            XCTAssertEqual(try VaultCrypto.openRaw(raw, seed: seed, scope: scope), Data(hex: s(v, "plaintext_hex")), "\(scope): open")
            let other = scope == "dek" ? "joinery-restore-approval:" : "dek"
            XCTAssertThrowsError(try VaultCrypto.openRaw(raw, seed: seed, scope: other), "\(scope): opened under \(other)")
            // The public opener takes the dek scope only.
            XCTAssertEqual((try? VaultCrypto.open(sealed: s(v, "sealed_b64"), secret: seed)) != nil, scope == "dek")
        }
    }

    func testAFreshKeyRoundTripsAndNoOtherOpens() throws {
        let pair = try VaultCrypto.newKeypair()
        XCTAssertEqual(pair.seed.count, 32)
        XCTAssertEqual(Data(base64Encoded: pair.publicKey)?.count, 1216)
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: pair.seed), pair.publicKey)
        let data = Data((0..<48).map { UInt8($0) })
        let sealed = try VaultCrypto.seal(data, toPublicKey: pair.publicKey)
        XCTAssertEqual(Data(base64Encoded: sealed)?.count, 1120 + 12 + 48 + 16)
        XCTAssertEqual(try VaultCrypto.open(sealed: sealed, secret: pair.seed), data)
        XCTAssertThrowsError(try VaultCrypto.open(sealed: sealed, secret: try VaultCrypto.newKeypair().seed))
    }

    func testSealingToAnOldKeyIsRefusedAndNamesTheRotation() {
        let old = VaultCrypto.b64(Data(repeating: 9, count: 32))
        XCTAssertTrue(VaultCrypto.isOldPublicKey(old))
        XCTAssertThrowsError(try VaultCrypto.seal(Data([1]), toPublicKey: old)) { error in
            XCTAssertEqual(error as? VaultCrypto.Failure, .oldKey)
            XCTAssertTrue(error.localizedDescription.contains("rotate"))
        }
        XCTAssertThrowsError(try VaultCrypto.publicKey(fromSecret: Data(repeating: 1, count: 48)), "a 48-byte secret is not a seed")
    }

    // MARK: edge_vector.json

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
    }

    // MARK: device_handoff_vector.json

    func testDeviceHandoffOpensToTheVaultSeed() throws {
        let v = try vector("device_handoff_vector")
        let deviceSeed = Data(hex: s(v["device"], "seed_hex"))
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: deviceSeed), s(v["device"], "public_b64"))
        let secret = try VaultCrypto.open(sealed: s(v["handoff"], "blob"), secret: deviceSeed)
        XCTAssertEqual(secret, Data(hex: s(v["vault"], "seed_hex")))
        XCTAssertEqual(try VaultCrypto.publicKey(fromSecret: secret), s(v["vault"], "public_b64"))
        // Another device's key opens nothing.
        XCTAssertThrowsError(try VaultCrypto.open(sealed: s(v["handoff"], "blob"), secret: try VaultCrypto.newKeypair().seed))
        // A seal this device makes to the vault opens with the vault's seed.
        let again = try VaultCrypto.seal(Data([7, 7]), toPublicKey: s(v["vault"], "public_b64"))
        XCTAssertEqual(try VaultCrypto.open(sealed: again, secret: secret), Data([7, 7]))
    }

    func testDeviceHandoffRowOpensEveryField() throws {
        let v = try vector("device_handoff_vector")
        let secret = Data(hex: s(v["vault"], "seed_hex"))
        let row = try XCTUnwrap(v["row"])
        XCTAssertTrue(s(row, "sealed_dek").hasPrefix("v2.edgeseal.mail."))
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
        // The MAC is under the secret's bytes as the keyring holds them. The
        // vector's secret is a pre-X-Wing PKCS#8; the MAC takes any bytes.
        let secret = Data(hex: "302e020100300506032b656e04220420" + s(v, "vault_secret_hex"))
        let msg = Data("joinery-relay-pin:v1\n\(v["alias_id"]?.intValue ?? 0)\n\(s(v, "relay_identity_public_key"))".utf8)
        XCTAssertEqual(VaultCrypto.b64(VaultCrypto.mac(secret: secret, info: "sealed-vault:pin", message: msg)),
                       s(v, "pin_mac"))
        XCTAssertNotEqual(VaultCrypto.b64(VaultCrypto.mac(secret: Data(hex: s(v, "vault_secret_hex")), info: "sealed-vault:pin",
                                                          message: msg)), s(v, "pin_mac"), "the MAC is under the bytes held, not a part of them")
        let signed = Data(("joinery-relay:seal-target:v1\n" + s(v, "statement")).utf8)
        XCTAssertTrue(VaultCrypto.verifyEd25519(publicKeyB64: s(v, "relay_identity_public_key"),
                                                message: signed, signatureB64: s(v, "signature")))
        let changed = Data(("joinery-relay:seal-target:v1\n" + s(v, "statement")
            .replacingOccurrences(of: "\"map_version\":7", with: "\"map_version\":8")).utf8)
        XCTAssertFalse(VaultCrypto.verifyEd25519(publicKeyB64: s(v, "relay_identity_public_key"),
                                                 message: changed, signatureB64: s(v, "signature")))
    }

    func testSealedDekParsing() {
        XCTAssertEqual(VaultCrypto.parseSealedDek("v2.edgeseal.mail.abc")?.scope, "mail")
        XCTAssertEqual(VaultCrypto.parseSealedDek("v2.edgeseal.mail.abc")?.blob, "abc")
        XCTAssertNil(VaultCrypto.parseSealedDek("v2.edgeseal..abc"))
        XCTAssertNil(VaultCrypto.parseSealedDek("v1.edge.abc"))
        XCTAssertNil(VaultCrypto.parseSealedDek("v2.edgeseal.Mail.abc"))
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
