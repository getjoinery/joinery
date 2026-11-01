import Foundation
import CryptoKit

/// The platform's client-custody formats, over CryptoKit (specs/fortress_mobile_apps.md
/// § F1, specs/post_quantum_sealing.md R2). Byte-for-byte the browser's
/// `vault-crypto.js` and `joinery-sealed.js` framing, pinned by the shared
/// vectors in `tests/vault/fixtures/`:
///
///   - a SEAL (a key handed to a public key): `base64(ct[1120] ‖ IV[12] ‖ ct‖tag)`,
///     X-Wing (`XWingMLKEM768X25519`) to the recipient's 1216-byte public key,
///     AES-256-GCM under `HKDF-SHA256(shared secret, salt '', info
///     'joinery-seal:v2:dek')`, no AAD. A row's DEK carries it behind
///     `v2.edgeseal.{scope}.`; a device handoff carries it bare.
///   - a FIELD: `v1.edge.` + `base64(IV[12] ‖ ct ‖ tag[16])`, AES-256-GCM under the
///     row's DEK, AAD `{ad_prefix}{id}:{column}`. Attachment bytes use the same
///     shape with AAD `{ad_prefix}{id}:att:{mime_part}`.
///
/// A vault secret, and this phone's device secret, is a 32-byte X-Wing seed; its
/// public key is worked out from it. Everything the browser MACs or derives from
/// "the secret" uses the seed's bytes.
public enum VaultCrypto {
    public static let edgeSeal = "v2.edgeseal."
    public static let edgeField = "v1.edge."
    static let sealInfo = "joinery-seal:v2:"
    static let scopeDek = "dek"
    static let seedBytes = 32
    static let publicKeyBytes = 1216
    static let ciphertextBytes = 1120

    public enum Failure: Error, Equatable {
        case malformed(String)
        case openFailed
        /// A 32-byte (pre-X-Wing) public key: nothing seals to it.
        case oldKey
    }

    // MARK: Base64

    public static func b64(_ data: Data) -> String { data.base64EncodedString() }

    public static func unb64(_ string: String) throws -> Data {
        guard let data = Data(base64Encoded: string) else { throw Failure.malformed("not base64") }
        return data
    }

    // MARK: Keys

    static func xwingKey(seed: Data) throws -> XWingMLKEM768X25519.PrivateKey {
        guard seed.count == seedBytes else { throw Failure.malformed("an X-Wing secret is a 32-byte seed") }
        do {
            return try XWingMLKEM768X25519.PrivateKey(seedRepresentation: seed, publicKey: nil)
        } catch {
            throw Failure.malformed("an X-Wing secret is a 32-byte seed")
        }
    }

    /// A fresh keypair: the 32-byte seed and the base64 1216-byte public key.
    public static func newKeypair() throws -> (seed: Data, publicKey: String) {
        let key = try XWingMLKEM768X25519.PrivateKey()
        return (key.seedRepresentation, b64(key.publicKey.rawRepresentation))
    }

    /// A key's public half worked out from its own seed (never the server's
    /// report of it): base64 of the 1216 bytes.
    public static func publicKey(fromSecret seed: Data) throws -> String {
        b64(try xwingKey(seed: seed).publicKey.rawRepresentation)
    }

    /// True for a public key from before X-Wing (32 bytes).
    public static func isOldPublicKey(_ b64: String?) -> Bool {
        guard let b64, let d = Data(base64Encoded: b64) else { return false }
        return d.count == 32
    }

    // MARK: Seal / open (key handoff and row DEKs)

    static func sealKey(_ shared: SymmetricKey, scope: String) -> SymmetricKey {
        HKDF<SHA256>.deriveKey(inputKeyMaterial: shared, salt: Data(),
                               info: Data((sealInfo + scope).utf8), outputByteCount: 32)
    }

    /// Seal `data` to a base64 1216-byte X-Wing public key. A 32-byte key is
    /// from before X-Wing and is refused: its owner rotates it first. `iv` is a
    /// test seam.
    public static func seal(_ data: Data, toPublicKey recipientB64: String, iv: Data? = nil) throws -> String {
        let recipientBytes = try unb64(recipientB64)
        if recipientBytes.count == 32 { throw Failure.oldKey }
        guard recipientBytes.count == publicKeyBytes else { throw Failure.malformed("a public key is 1216 bytes") }
        let recipient = try XWingMLKEM768X25519.PublicKey(rawRepresentation: recipientBytes)
        let enc = try recipient.encapsulate()
        let key = sealKey(enc.sharedSecret, scope: scopeDek)
        let nonce = try iv.map { try AES.GCM.Nonce(data: $0) } ?? AES.GCM.Nonce()
        let box = try AES.GCM.seal(data, using: key, nonce: nonce)
        return b64(enc.encapsulated + Data(nonce) + box.ciphertext + box.tag)
    }

    /// Open raw seal bytes with a seed under `scope` (the vector tests name
    /// other scopes; everything else opens 'dek').
    static func openRaw(_ raw: Data, seed: Data, scope: String) throws -> Data {
        guard raw.count >= ciphertextBytes + 12 + 16 else { throw Failure.malformed("sealed blob too short") }
        let start = raw.startIndex
        let ct = raw.subdata(in: start ..< start + ciphertextBytes)
        let iv = raw.subdata(in: start + ciphertextBytes ..< start + ciphertextBytes + 12)
        let body = raw.subdata(in: start + ciphertextBytes + 12 ..< raw.endIndex)
        let priv = try xwingKey(seed: seed)
        do {
            let shared = try priv.decapsulate(ct)
            return try gcmOpen(body, iv: iv, key: sealKey(shared, scope: scope), ad: nil)
        } catch let error as Failure {
            throw error
        } catch {
            throw Failure.openFailed
        }
    }

    /// Open a seal with the recipient's 32-byte seed.
    public static func open(sealed blob: String, secret: Data) throws -> Data {
        try openRaw(try unb64(blob), seed: secret, scope: scopeDek)
    }

    /// `v2.edgeseal.{scope}.` → (scope, blob), nil when not that shape.
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

extension VaultCrypto.Failure: LocalizedError {
    public var errorDescription: String? {
        switch self {
        case .oldKey:
            return "That vault key is an old (pre-X-Wing) key; its owner must rotate it on the Security page before anything can be sealed to it."
        default:
            return nil
        }
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
