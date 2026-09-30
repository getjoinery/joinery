import Foundation
import Security
import LocalAuthentication
import CryptoKit

/// Where a device's own keys live (specs/fortress_mobile_apps.md § R3, F5).
///
/// The Secure Enclave holds P-256 keys only, so the X25519 device key and the
/// vault secrets it receives cannot live in the chip. They live in the Keychain
/// as `WhenPasscodeSetThisDeviceOnly` items whose access control is
/// `.biometryCurrentSet`: the item's class key is held by the Secure Enclave and
/// released only after Face ID or Touch ID, and enrolling a new face or finger
/// invalidates it. Nothing here syncs, migrates or lands in a backup, and there
/// is no weaker gate: with no biometry enrolled the key is not kept at all.
///
/// What is stored:
///   - the device X25519 key (secret gated; its public half ungated, since the
///     enrollment call sends it and a public key protects nothing);
///   - each client-custody scope's secret, as the PKCS#8 bytes the handoff
///     delivers (`vault.{scope}`);
///   - small per-device secrets beside them (the AI endpoint's key).
///
/// A new face or finger invalidates every gated item. That is noticed without
/// a prompt — the biometric domain state recorded when the device key was made
/// differs — and the whole set is then dropped and the device key made anew,
/// so the next enrollment binds a key this phone can actually open.
///
/// One biometric prompt opens everything: `unlock(reason:)` evaluates the
/// policy once and the returned context reads every gated item.
public final class DeviceKeyStore: @unchecked Sendable {
    public enum Protection: Sendable {
        /// Face ID / Touch ID, nothing weaker.
        case biometric
        /// No user-presence gate — unit tests and the simulator gate only.
        case plain
    }

    public enum Failure: Error, Equatable {
        case cancelled
        case unavailable(String)
        case storeFailed(OSStatus)
    }

    /// A gated read: the bytes, gone for good, or not readable right now.
    public enum Read: Equatable {
        case value(Data)
        /// Not found, or invalidated by a new face or finger: wipe and re-enroll.
        case gone
        /// Cancelled, failed, locked out or not allowed now: keep, try again.
        case unavailable
    }

    private let service: String
    public let protection: Protection
    /// Where the items live: the Keychain, or (unit tests, which have no
    /// keychain in a package test host) memory.
    let items: ItemBackend
    /// The current enrollment's state (a seam for tests).
    let domainStateNow: () -> Data?

    public init(service: String, protection: Protection? = nil) {
        self.service = service + ".devicekeys"
        self.protection = protection ?? Self.defaultProtection()
        self.items = KeychainBackend(service: self.service)
        self.domainStateNow = Self.currentDomainState
    }

    init(service: String, protection: Protection, items: ItemBackend,
         domainState: @escaping () -> Data? = DeviceKeyStore.currentDomainState) {
        self.service = service + ".devicekeys"
        self.protection = protection
        self.items = items
        self.domainStateNow = domainState
    }

    /// Debug builds may run the gate without biometrics (the simulator's UI
    /// tests cannot present a face); a release build never can.
    static func defaultProtection() -> Protection {
        #if DEBUG
        if ProcessInfo.processInfo.environment["JOINERY_TEST_KEYSTORE"] == "plain"
            || ProcessInfo.processInfo.arguments.contains("--plain-keystore") {
            return .plain
        }
        #endif
        return .biometric
    }

    // MARK: Accounts

    static let devicePublic = "device.x25519.public"
    static let deviceSecret = "device.x25519.secret"
    static let domainState = "device.biometry.domainstate"
    static func vaultAccount(_ scope: String) -> String { "vault.\(scope)" }

    // MARK: Biometry

    /// The current biometric enrollment's fingerprint, read without a prompt.
    static func currentDomainState() -> Data? {
        let c = LAContext()
        var e: NSError?
        _ = c.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &e)
        return c.evaluatedPolicyDomainState
    }

    /// Did the enrolled faces or fingers change? Only when both states are
    /// known: the state is nil whenever biometry cannot be evaluated (a
    /// lockout, Face ID off for this app, a moment's unavailability), and an
    /// unknown state never counts as a change — a pocket lockout must not cost
    /// the key (R1).
    static func domainStateChanged(recorded: Data?, current: Data?) -> Bool {
        guard let recorded, let current else { return false }
        return recorded != current
    }

    /// True when the faces or fingers changed since the gated items were made:
    /// every one of them is invalidated. Never true under `.plain`, and never
    /// on an unknown state. `context`, when given, is one that just passed
    /// Face ID, whose state is the one to trust.
    public func biometryChanged(context: LAContext? = nil) -> Bool {
        guard protection == .biometric else { return false }
        let recorded = read(Self.domainState, context: nil, gated: false).data
        let current = context?.evaluatedPolicyDomainState ?? domainStateNow()
        return Self.domainStateChanged(recorded: recorded, current: current)
    }

    public var biometryChanged: Bool { biometryChanged(context: nil) }

    /// One prompt for the session. `nil` context under `.plain`. A biometric
    /// lockout is cleared with the passcode first, then Face ID is asked again:
    /// the items are `.biometryCurrentSet`, which a passcode alone never opens.
    public func unlock(reason: String) async throws -> LAContext? {
        guard protection == .biometric else { return nil }
        var error: NSError?
        let probe = LAContext()
        if !probe.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error) {
            if (error as? LAError)?.code == .biometryLockout {
                let unlockout = LAContext()
                _ = try? await unlockout.evaluatePolicy(.deviceOwnerAuthentication,
                                                        localizedReason: "Face ID is locked. Enter your passcode to use it again.")
            } else {
                throw Failure.unavailable("Set up Face ID or Touch ID to keep your mail key on this phone.")
            }
        }
        let context = LAContext()
        context.touchIDAuthenticationAllowableReuseDuration = 10
        do {
            let ok = try await context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: reason)
            guard ok else { throw Failure.cancelled }
        } catch let e as LAError where e.code == .userCancel || e.code == .appCancel || e.code == .systemCancel
                    || e.code == .userFallback {
            throw Failure.cancelled
        } catch let e as Failure {
            throw e
        } catch {
            throw Failure.unavailable("Face ID did not open your key. Try again.")
        }
        return context
    }

    // MARK: The device key

    /// The device's public key, creating the pair on first use and again when
    /// the old one's secret can no longer be opened (a new face or finger).
    /// Kept across sign-out so a re-enrollment approves the same key; wiped on unlink.
    public func devicePublicKey() throws -> String {
        if let existing = read(Self.devicePublic, context: nil, gated: false).data, !deviceSecretGone {
            return String(decoding: existing, as: UTF8.self)
        }
        // A pair is minted only with the enrollment's state in hand, so a later
        // change can always be told from an unknown (X2).
        let state = protection == .biometric ? domainStateNow() : nil
        if protection == .biometric && state == nil {
            throw Failure.unavailable("Face ID is not available right now. Make sure it is on for this app, then try again.")
        }
        forgetAllGated()
        let pair = Curve25519.KeyAgreement.PrivateKey()
        let pub = VaultCrypto.b64(pair.publicKey.rawRepresentation)
        try write(pair.rawRepresentation, account: Self.deviceSecret, gated: true)
        try write(Data(pub.utf8), account: Self.devicePublic, gated: false)
        if let state {
            try write(state, account: Self.domainState, gated: false)
        }
        return pub
    }

    /// A pair from before a state was recorded gets one only once a gated
    /// read has succeeded under `context` — proof the key opens with this
    /// enrollment (X2).
    public func recordStateIfMissing(provenBy context: LAContext?) {
        guard protection == .biometric, read(Self.domainState, context: nil, gated: false).data == nil,
              let state = context?.evaluatedPolicyDomainState else { return }
        try? write(state, account: Self.domainState, gated: false)
    }

    /// The gated half is missing or invalidated (checked without a prompt).
    var deviceSecretGone: Bool {
        biometryChanged || !exists(Self.deviceSecret)
    }

    public var hasDeviceKey: Bool {
        read(Self.devicePublic, context: nil, gated: false).data != nil && !deviceSecretGone
    }

    /// The device secret (raw scalar), behind the gate.
    public func deviceSecret(context: LAContext?) -> Read {
        read(Self.deviceSecret, context: context, gated: true)
    }

    /// Unlink: both halves and everything opened through them.
    public func forgetDeviceKey() {
        forgetAllGated()
        delete(Self.devicePublic)
        delete(Self.domainState)
    }

    /// Every gated item (the device secret, the scope secrets, the extras):
    /// by name from the index kept at write time, and whatever a listing finds
    /// (which can come back empty for access-controlled items, R4).
    private func forgetAllGated() {
        var names = Set(gatedIndex())
        names.formUnion(items.accounts())
        names.insert(Self.deviceSecret)
        for account in names where account != Self.devicePublic && account != Self.domainState
            && account != Self.gatedIndexAccount {
            delete(account)
        }
        delete(Self.gatedIndexAccount)
    }

    static let gatedIndexAccount = "device.gated.index"

    private func gatedIndex() -> [String] {
        guard let d = read(Self.gatedIndexAccount, context: nil, gated: false).data,
              let s = String(data: d, encoding: .utf8) else { return [] }
        return s.split(separator: "\n").map(String.init)
    }

    private func noteGated(_ account: String) {
        var names = gatedIndex()
        guard !names.contains(account) else { return }
        names.append(account)
        let data = Data(names.joined(separator: "\n").utf8)
        delete(Self.gatedIndexAccount)
        _ = items.add(account: Self.gatedIndexAccount, data: data, access: nil, gated: false)
    }

    // MARK: Scope secrets and per-device secrets

    public func storeVaultSecret(_ secret: Data, scope: String) throws {
        try write(secret, account: Self.vaultAccount(scope), gated: true)
    }

    public func vaultSecret(scope: String, context: LAContext?) -> Read {
        if biometryChanged { return .gone }
        return read(Self.vaultAccount(scope), context: context, gated: true)
    }

    /// Whether a usable secret is stored, without opening it (no prompt).
    public func hasVaultSecret(scope: String) -> Bool {
        !biometryChanged && exists(Self.vaultAccount(scope))
    }

    public func forgetVaultSecret(scope: String) {
        delete(Self.vaultAccount(scope))
    }

    public func storeSecret(_ data: Data, name: String) throws {
        try write(data, account: "secret.\(name)", gated: true)
    }

    public func secret(name: String, context: LAContext?) -> Data? {
        read("secret.\(name)", context: context, gated: true).data
    }

    public func forgetSecret(name: String) {
        delete("secret.\(name)")
    }

    // MARK: Primitives

    private func write(_ data: Data, account: String, gated: Bool) throws {
        var access: SecAccessControl? = nil
        if gated && protection == .biometric {
            // Face ID or Touch ID, or nothing: never a passcode-satisfiable gate.
            guard LAContext().canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: nil) else {
                throw Failure.unavailable("Set up Face ID or Touch ID to keep your mail key on this phone.")
            }
            var error: Unmanaged<CFError>?
            guard let a = SecAccessControlCreateWithFlags(
                nil, kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly, .biometryCurrentSet, &error) else {
                throw Failure.unavailable("This phone has no passcode, so it cannot hold the key.")
            }
            access = a
        }
        delete(account)
        let status = items.add(account: account, data: data, access: access, gated: gated)
        guard status == errSecSuccess else { throw Failure.storeFailed(status) }
        if gated { noteGated(account) }
    }

    static func classify(_ status: OSStatus) -> Read {
        // Not found is the only answer that means the item is no more; every
        // other failure (cancel, auth failed, not allowed now) keeps it.
        // Whether an item invalidated by a new face or finger (.biometryCurrentSet)
        // answers errSecItemNotFound or errSecAuthFailed is unverified on
        // hardware (R3). If it is the latter it reads as "unavailable" here, and
        // recovery rests on the domain-state comparison (biometryChanged),
        // which fires once biometry can be evaluated again. This classification
        // is not what detects invalidation.
        status == errSecItemNotFound ? .gone : .unavailable
    }

    private func read(_ account: String, context: LAContext?, gated: Bool) -> Read {
        var ctx = context
        if gated, context == nil, protection == .biometric {
            // Never prompt from a read the caller did not unlock for.
            let quiet = LAContext()
            quiet.interactionNotAllowed = true
            ctx = quiet
        }
        let (status, data) = items.copy(account: account, context: gated ? ctx : nil)
        if status == errSecSuccess, let data { return .value(data) }
        return Self.classify(status)
    }

    private func exists(_ account: String) -> Bool {
        items.exists(account: account)
    }

    private func delete(_ account: String) {
        items.delete(account: account)
    }
}

/// The item store under DeviceKeyStore.
protocol ItemBackend: AnyObject {
    func add(account: String, data: Data, access: SecAccessControl?, gated: Bool) -> OSStatus
    func copy(account: String, context: LAContext?) -> (OSStatus, Data?)
    /// Present, even if it would want a face to read (no prompt).
    func exists(account: String) -> Bool
    func delete(account: String)
    func accounts() -> [String]
}

final class KeychainBackend: ItemBackend {
    let service: String
    init(service: String) { self.service = service }

    private func base(_ account: String) -> [String: Any] {
        [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
         kSecAttrAccount as String: account]
    }

    func add(account: String, data: Data, access: SecAccessControl?, gated: Bool) -> OSStatus {
        var q = base(account)
        q[kSecValueData as String] = data
        if let access {
            q[kSecAttrAccessControl as String] = access
        } else {
            q[kSecAttrAccessible as String] = gated ? kSecAttrAccessibleWhenUnlockedThisDeviceOnly
                                                    : kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        }
        return SecItemAdd(q as CFDictionary, nil)
    }

    func copy(account: String, context: LAContext?) -> (OSStatus, Data?) {
        var q = base(account)
        q[kSecReturnData as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne
        if let context { q[kSecUseAuthenticationContext as String] = context }
        var result: AnyObject?
        let status = SecItemCopyMatching(q as CFDictionary, &result)
        return (status, result as? Data)
    }

    func exists(account: String) -> Bool {
        var q = base(account)
        q[kSecReturnAttributes as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne
        let quiet = LAContext()
        quiet.interactionNotAllowed = true
        q[kSecUseAuthenticationContext as String] = quiet
        var result: AnyObject?
        let status = SecItemCopyMatching(q as CFDictionary, &result)
        // An item that exists but wants a face answers "interaction not allowed".
        return status == errSecSuccess || status == errSecInteractionNotAllowed
    }

    func delete(account: String) {
        SecItemDelete(base(account) as CFDictionary)
    }

    func accounts() -> [String] {
        let quiet = LAContext()
        quiet.interactionNotAllowed = true
        let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
                                kSecReturnAttributes as String: true, kSecMatchLimit as String: kSecMatchLimitAll,
                                kSecUseAuthenticationContext as String: quiet]
        var result: AnyObject?
        guard SecItemCopyMatching(q as CFDictionary, &result) == errSecSuccess,
              let rows = result as? [[String: Any]] else { return [] }
        return rows.compactMap { $0[kSecAttrAccount as String] as? String }
    }
}

/// Memory, for unit tests.
final class MemoryBackend: ItemBackend {
    var store: [String: Data] = [:]
    func add(account: String, data: Data, access: SecAccessControl?, gated: Bool) -> OSStatus {
        store[account] = data
        return errSecSuccess
    }
    func copy(account: String, context: LAContext?) -> (OSStatus, Data?) {
        store[account].map { (errSecSuccess, $0) } ?? (errSecItemNotFound, nil)
    }
    func exists(account: String) -> Bool { store[account] != nil }
    func delete(account: String) { store[account] = nil }
    func accounts() -> [String] { Array(store.keys) }
}

extension DeviceKeyStore.Read {
    public var data: Data? {
        if case .value(let d) = self { return d }
        return nil
    }
}
