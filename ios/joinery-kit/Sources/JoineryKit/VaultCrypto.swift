import Foundation
import CryptoKit

/// The platform's client-custody formats, over CryptoKit (specs/fortress_mobile_apps.md
/// § F1). Byte-for-byte the browser's `vault-crypto.js` and `joinery-sealed.js`
/// framing, pinned by the shared vectors in `tests/vault/fixtures/`:
///
///   - a SEAL (a key handed to a public key): `base64(ephPub[32] ‖ IV[12] ‖ ct‖tag)`,
///     AES-256-GCM under `HKDF-SHA256(X25519(eph, recipient), salt '', info
///     'sealed-vault:dek' ‖ ephPub ‖ recipientPub)`, no AAD. A row's DEK carries it
///     behind `v1.edgeseal.{scope}.`; a device handoff carries it bare.
///   - a FIELD: `v1.edge.` + `base64(IV[12] ‖ ct ‖ tag[16])`, AES-256-GCM under the
///     row's DEK, AAD `{ad_prefix}{id}:{column}`. Attachment bytes use the same
///     shape with AAD `{ad_prefix}{id}:att:{mime_part}`.
///
/// A vault secret travels as PKCS#8 (48 bytes); the X25519 scalar is its last 32.
/// Everything the browser MACs or derives from "the secret" uses the PKCS#8 bytes,
/// so this module keeps them in that form.
public enum VaultCrypto {
    public static let edgeSeal = "v1.edgeseal."
    public static let edgeField = "v1.edge."
    static let pkcs8Prefix: [UInt8] = [0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06,
                                       0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20]

    public enum Failure: Error, Equatable {
        case malformed(String)
        case openFailed
    }

    // MARK: Base64

    public static func b64(_ data: Data) -> String { data.base64EncodedString() }

    public static func unb64(_ string: String) throws -> Data {
        guard let data = Data(base64Encoded: string) else { throw Failure.malformed("not base64") }
        return data
    }

    // MARK: PKCS#8

    /// The raw 32-byte scalar inside an X25519 PKCS#8 secret (or a bare 32-byte one).
    public static func scalar(fromPKCS8 secret: Data) throws -> Data {
        if secret.count == 32 { return secret }
        guard secret.count == 48, Array(secret.prefix(16)) == pkcs8Prefix else {
            throw Failure.malformed("not an X25519 PKCS#8 secret")
        }
        return secret.suffix(32)
    }

    public static func pkcs8(fromScalar scalar: Data) -> Data {
        Data(pkcs8Prefix) + scalar
    }

    /// A vault's public key worked out from its own secret (never the server's
    /// report of it): base64 of the raw 32 bytes.
    public static func publicKey(fromSecret secret: Data) throws -> String {
        let key = try Curve25519.KeyAgreement.PrivateKey(rawRepresentation: scalar(fromPKCS8: secret))
        return b64(key.publicKey.rawRepresentation)
    }

    // MARK: Seal / open (key handoff and row DEKs)

    static func sealKey(shared: SharedSecret, ephPub: Data, recipientPub: Data) -> SymmetricKey {
        shared.hkdfDerivedSymmetricKey(
            using: SHA256.self, salt: Data(),
            sharedInfo: Data("sealed-vault:dek".utf8) + ephPub + recipientPub,
            outputByteCount: 32)
    }

    /// Seal `data` to a base64 X25519 public key. `ephemeral` and `iv` are test seams.
    public static func seal(_ data: Data, toPublicKey recipientB64: String,
                            ephemeral: Curve25519.KeyAgreement.PrivateKey? = nil,
                            iv: Data? = nil) throws -> String {
        let recipientBytes = try unb64(recipientB64)
        let recipient = try Curve25519.KeyAgreement.PublicKey(rawRepresentation: recipientBytes)
        let eph = ephemeral ?? Curve25519.KeyAgreement.PrivateKey()
        let ephPub = eph.publicKey.rawRepresentation
        let shared = try eph.sharedSecretFromKeyAgreement(with: recipient)
        let key = sealKey(shared: shared, ephPub: ephPub, recipientPub: recipientBytes)
        let nonce = try iv.map { try AES.GCM.Nonce(data: $0) } ?? AES.GCM.Nonce()
        let box = try AES.GCM.seal(data, using: key, nonce: nonce)
        return b64(ephPub + Data(nonce) + box.ciphertext + box.tag)
    }

    /// Open a seal with the recipient's secret (PKCS#8 or raw scalar).
    public static func open(sealed blob: String, secret: Data) throws -> Data {
        let raw = try unb64(blob)
        guard raw.count >= 32 + 12 + 16 else { throw Failure.malformed("sealed blob too short") }
        let ephPub = raw.prefix(32)
        let iv = raw.subdata(in: raw.startIndex + 32 ..< raw.startIndex + 44)
        let body = raw.suffix(from: raw.startIndex + 44)
        do {
            let priv = try Curve25519.KeyAgreement.PrivateKey(rawRepresentation: scalar(fromPKCS8: secret))
            let eph = try Curve25519.KeyAgreement.PublicKey(rawRepresentation: ephPub)
            let shared = try priv.sharedSecretFromKeyAgreement(with: eph)
            let key = sealKey(shared: shared, ephPub: Data(ephPub), recipientPub: priv.publicKey.rawRepresentation)
            return try gcmOpen(Data(body), iv: iv, key: key, ad: nil)
        } catch let error as Failure {
            throw error
        } catch {
            throw Failure.openFailed
        }
    }

    /// `v1.edgeseal.{scope}.` → (scope, blob), nil when not that shape.
    public static func parseSealedDek(_ sealed: String) -> (scope: String, blob: String)? {
        guard sealed.hasPrefix(edgeSeal) else { return nil }
        let rest = sealed.dropFirst(edgeSeal.count)
        guard let dot = rest.firstIndex(of: "."), dot != rest.startIndex else { return nil }
        let scope = String(rest[..<dot])
        guard scope.count <= 32, scope.allSatisfy({ $0.isASCII && ($0.isLowercase || $0.isNumber || $0 == "_") })
        else { return nil }
        return (scope, String(rest[rest.index(after: dot)...]))
    }

    /// A row's DEK opened with the scope secret; refuses a key sealed to another scope.
    public static func openDek(_ sealedDek: String, scope: String, secret: Data) throws -> SymmetricKey {
        guard let parsed = parseSealedDek(sealedDek), parsed.scope == scope else {
            throw Failure.malformed("This is not sealed to your vault.")
        }
        var raw = try open(sealed: parsed.blob, secret: secret)
        defer { raw.resetBytes(in: 0..<raw.count) }
        guard raw.count == 32 else { throw Failure.malformed("a row key is not 32 bytes") }
        return SymmetricKey(data: raw)
    }

    public static func sealDek(_ dek: SymmetricKey, scope: String, toPublicKey pub: String) throws -> String {
        let bytes = dek.withUnsafeBytes { Data($0) }
        return edgeSeal + scope + "." + (try seal(bytes, toPublicKey: pub))
    }

    // MARK: Fields and bytes under a DEK

    public static func gcmOpen(_ body: Data, iv: Data, key: SymmetricKey, ad: Data?) throws -> Data {
        guard body.count >= 16 else { throw Failure.malformed("ciphertext too short") }
        do {
            let box = try AES.GCM.SealedBox(nonce: AES.GCM.Nonce(data: iv),
                                            ciphertext: body.prefix(body.count - 16),
                                            tag: body.suffix(16))
            if let ad { return try AES.GCM.open(box, using: key, authenticating: ad) }
            return try AES.GCM.open(box, using: key)
        } catch {
            throw Failure.openFailed
        }
    }

    /// base64(IV ‖ ct ‖ tag) → plaintext bytes, bound to `ad`.
    public static func decryptBytes(_ blob: String, key: SymmetricKey, ad: String) throws -> Data {
        let raw = try unb64(blob)
        guard raw.count >= 12 + 16 else { throw Failure.malformed("field too short") }
        return try gcmOpen(Data(raw.suffix(from: raw.startIndex + 12)), iv: Data(raw.prefix(12)),
                           key: key, ad: Data(ad.utf8))
    }

    public static func encryptBytes(_ data: Data, key: SymmetricKey, ad: String, iv: Data? = nil) throws -> String {
        let nonce = try iv.map { try AES.GCM.Nonce(data: $0) } ?? AES.GCM.Nonce()
        let box = try AES.GCM.seal(data, using: key, nonce: nonce, authenticating: Data(ad.utf8))
        return b64(Data(nonce) + box.ciphertext + box.tag)
    }

    public static func isEdgeField(_ value: String?) -> Bool {
        value?.hasPrefix(edgeField) == true
    }

    /// A `v1.edge.` value opened to text; '' and null stay as they are stored.
    public static func openField(_ value: String?, key: SymmetricKey, ad: String) throws -> String {
        guard let value, !value.isEmpty else { return "" }
        guard value.hasPrefix(edgeField) else { return value }
        let bytes = try decryptBytes(String(value.dropFirst(edgeField.count)), key: key, ad: ad)
        guard let text = String(data: bytes, encoding: .utf8) else { throw Failure.malformed("field is not UTF-8") }
        return text
    }

    /// Bytes in the `v1.edge.` shape (an attachment's stored content).
    public static func openEdgeBytes(_ value: String, key: SymmetricKey, ad: String) throws -> Data {
        guard value.hasPrefix(edgeField) else { throw Failure.malformed("This part is not sealed for this device.") }
        return try decryptBytes(String(value.dropFirst(edgeField.count)), key: key, ad: ad)
    }

    /// Text sealed as a field. Empty stays bare, as the server stores it.
    public static func sealField(_ text: String, key: SymmetricKey, ad: String) throws -> String {
        if text.isEmpty { return "" }
        return edgeField + (try encryptBytes(Data(text.utf8), key: key, ad: ad))
    }

    public static func sealEdgeBytes(_ data: Data, key: SymmetricKey, ad: String) throws -> String {
        edgeField + (try encryptBytes(data, key: key, ad: ad))
    }

    public static func newDek() -> SymmetricKey { SymmetricKey(size: .bits256) }

    // MARK: MAC, signatures, fingerprints

    /// HMAC-SHA256 under HKDF-SHA256(secret, empty salt, info): the vault's pin MAC.
    public static func mac(secret: Data, info: String, message: Data) -> Data {
        let key = HKDF<SHA256>.deriveKey(inputKeyMaterial: SymmetricKey(data: secret),
                                         salt: Data(), info: Data(info.utf8), outputByteCount: 32)
        return Data(HMAC<SHA256>.authenticationCode(for: message, using: key))
    }

    public static func verifyEd25519(publicKeyB64: String, message: Data, signatureB64: String) -> Bool {
        guard let pub = Data(base64Encoded: publicKeyB64), let sig = Data(base64Encoded: signatureB64),
              let key = try? Curve25519.Signing.PublicKey(rawRepresentation: pub) else { return false }
        return key.isValidSignature(sig, for: message)
    }

    /// SHA-256, the first 16 bytes in groups of four hex digits (the browser's fingerprint).
    public static func fingerprint(_ b64: String) -> String {
        guard let data = Data(base64Encoded: b64) else { return "(unreadable)" }
        let hex = SHA256.hash(data: data).prefix(16).map { String(format: "%02x", $0) }.joined()
        var groups: [String] = []
        var i = hex.startIndex
        while i < hex.endIndex {
            let j = hex.index(i, offsetBy: 4)
            groups.append(String(hex[i..<j]))
            i = j
        }
        return groups.joined(separator: " ")
    }

    /// Constant-time text compare.
    public static func sameText(_ a: String, _ b: String) -> Bool {
        let x = Array(a.utf8), y = Array(b.utf8)
        guard x.count == y.count else { return false }
        var d: UInt8 = 0
        for i in 0..<x.count { d |= x[i] ^ y[i] }
        return d == 0
    }

    public static func randomHex(_ bytes: Int) -> String {
        var b = [UInt8](repeating: 0, count: bytes)
        _ = SecRandomCopyBytes(kSecRandomDefault, bytes, &b)
        return b.map { String(format: "%02x", $0) }.joined()
    }
}

extension Data {
    /// Overwrite with zeros (the in-memory secret's end).
    public mutating func wipe() {
        resetBytes(in: 0..<count)
    }

    init(hex: String) {
        var out = Data(capacity: hex.count / 2)
        var i = hex.startIndex
        while i < hex.endIndex, let j = hex.index(i, offsetBy: 2, limitedBy: hex.endIndex) {
            if let b = UInt8(hex[i..<j], radix: 16) { out.append(b) }
            i = j
        }
        self = out
    }
}
