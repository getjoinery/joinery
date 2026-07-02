import Foundation
import Security

/// Session-key storage. Secrets live only in the Keychain — never
/// UserDefaults or plists (spec security note).
public struct KeychainStore: Sendable {
    private let service: String

    /// `service` namespaces entries per app; pass the bundle id.
    public init(service: String) {
        self.service = service
    }

    private static let publicKeyAccount = "joinery.session.public_key"
    private static let secretKeyAccount = "joinery.session.secret_key"

    // MARK: Credentials

    public func loadCredentials() -> APICredentials? {
        guard let publicKey = readString(account: Self.publicKeyAccount),
              let secretKey = readString(account: Self.secretKeyAccount) else {
            return nil
        }
        return APICredentials(publicKey: publicKey, secretKey: secretKey)
    }

    @discardableResult
    public func saveCredentials(_ credentials: APICredentials) -> Bool {
        writeString(credentials.publicKey, account: Self.publicKeyAccount)
            && writeString(credentials.secretKey, account: Self.secretKeyAccount)
    }

    public func deleteCredentials() {
        delete(account: Self.publicKeyAccount)
        delete(account: Self.secretKeyAccount)
    }

    // MARK: Primitives

    private func baseQuery(account: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
    }

    private func readString(account: String) -> String? {
        var query = baseQuery(account: account)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    @discardableResult
    private func writeString(_ value: String, account: String) -> Bool {
        let data = Data(value.utf8)
        var query = baseQuery(account: account)
        // Available after first unlock so a background refresh can read it;
        // never migrates to a new device (session keys are per-device).
        query[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        query[kSecValueData as String] = data
        var status = SecItemAdd(query as CFDictionary, nil)
        if status == errSecDuplicateItem {
            let update = [kSecValueData as String: data] as CFDictionary
            status = SecItemUpdate(baseQuery(account: account) as CFDictionary, update)
        }
        return status == errSecSuccess
    }

    private func delete(account: String) {
        SecItemDelete(baseQuery(account: account) as CFDictionary)
    }
}
