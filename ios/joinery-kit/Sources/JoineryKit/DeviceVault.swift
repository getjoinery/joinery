import Foundation
import UIKit
import LocalAuthentication

/// One client-custody vault as this phone holds it (specs/fortress_mobile_apps.md
/// § R2, R3, R9): whether the key is here, getting it here, opening it for use,
/// and letting it go when the server says it was retired.
///
/// The phone never unlocks against the server. It is handed the scope's secret
/// once, by a computer where the vault is open: `enroll()` attaches this phone's
/// device key to the session key it already signed in with (no second
/// credential), the person approves on `/profile/devices/link`, the browser
/// seals the secret to the device key, and `poll()` collects it. The secret is
/// then stored behind the biometric gate and opened with one prompt per session.
@MainActor
public final class DeviceVault: ObservableObject {
    public enum Status: Equatable {
        /// No secret on this phone: the person hands it over from a computer.
        case notEnrolled
        /// Stored behind the biometric gate, not in memory.
        case locked
        /// In memory now.
        case open
    }

    public struct Ticket: Equatable, Sendable {
        public let linkCode: String
        public let verifyURL: URL?
        public let pollToken: String
        public let expiresTime: String
        public let pollAfter: Int
    }

    public enum PollOutcome: Equatable {
        case pending(after: Int)
        case denied
        case approved
        case expired
    }

    /// What the probe answered. A field the answer does not carry is nil —
    /// unknown, never read as "no" (F10).
    public struct Probe: Equatable, Sendable {
        public let setUp: Bool?
        public let publicKey: String?
        public let keyGeneration: Int
        public let pendingPublicKey: String?
        public let heldByThisDevice: Bool?
        /// The calling session key has a live device row (false before the
        /// first handover too).
        public let deviceLinked: Bool?

        public init?(data: JSONValue?) {
            guard let data, case .object = data else { return nil }
            func bool(_ k: String) -> Bool? {
                guard let v = data[k], !v.isNull else { return nil }
                return v.boolValue
            }
            setUp = bool("set_up")
            let pub = data["public_key"]
            publicKey = (pub?.isNull ?? true) ? nil : pub?.stringValue
            keyGeneration = data["key_generation"]?.intValue ?? 0
            let pending = data["pending_public_key"]
            pendingPublicKey = (pending?.isNull ?? true) ? nil : pending?.stringValue
            heldByThisDevice = bool("held_by_this_device")
            deviceLinked = bool("device_linked")
        }

        /// Keys this probe names as the vault's (current, and pending mid-rotation).
        public var vaultKeys: [String] { [publicKey, pendingPublicKey].compactMap { $0 } }
    }

    /// What a probe answer means for the key this phone holds.
    public enum Verdict: Equatable {
        case keep
        /// Retired (rotation, recovery code): wipe the scope secret, keep the device key.
        case retired
        /// Unlinked: wipe the device key too.
        case unlinked
    }

    /// F10/F2, pure: act only on an answer that says so outright.
    public static func judge(_ p: Probe, storedPublicKey: String?, handedOverUnderThisKey: Bool) -> Verdict {
        if p.deviceLinked == false && handedOverUnderThisKey { return .unlinked }
        if p.heldByThisDevice == false { return .retired }
        if p.setUp == false { return .retired }
        if let stored = storedPublicKey, !p.vaultKeys.isEmpty, !p.vaultKeys.contains(stored) { return .retired }
        return .keep
    }

    public enum Failure: LocalizedError, Equatable {
        case notSetUp
        case wrongKey
        case noKeyInHandoff
        case message(String)

        public var errorDescription: String? {
            switch self {
            case .notSetUp: return "Your vault is not set up yet."
            case .wrongKey: return "The key handed over is not your mail vault's key. Nothing was kept."
            case .noKeyInHandoff: return "The computer did not hand over a mail key."
            case .message(let m): return m
            }
        }
    }

    public let scope: String
    /// A handover collected but not yet kept (Face ID did not open the device
    /// key at that moment). Memory only; sealed to this phone's device key.
    @Published public private(set) var pendingHandover: String?

    #if DEBUG
    func pendingHandoverForTest(_ blob: String) { pendingHandover = blob }
    #endif

    /// Retry keeping a collected handover.
    public func retryHandover() async throws {
        guard let blob = pendingHandover else { throw Failure.noKeyInHandoff }
        try await receive(blob)
    }
    /// Per-device secrets stored beside this scope's (DeviceKeyStore
    /// `secret.{name}`), opened into HeldKeys extras by the same prompt.
    public var companions: [String] = []
    @Published public private(set) var status: Status = .notEnrolled
    /// Set when the server retired the key this phone held (a rotation or a
    /// recovery-code use): the banner says to hand the new key over.
    @Published public private(set) var retired = false

    let session: SessionController
    private var client: APIClient { session.client }
    private var keys: DeviceKeyStore { session.deviceKeys }
    private var held: HeldKeys { session.heldKeys }
    private let defaults = UserDefaults.standard

    private static var instances: [String: DeviceVault] = [:]

    /// One instance per session and scope, so its hooks register once.
    public static func shared(session: SessionController, scope: String) -> DeviceVault {
        let key = "\(ObjectIdentifier(session).hashValue):\(scope)"
        if let v = instances[key] { return v }
        let v = DeviceVault(session: session, scope: scope)
        instances[key] = v
        return v
    }

    init(session: SessionController, scope: String) {
        self.session = session
        self.scope = scope
        refreshStatus()
        session.heldKeys.onLock { [weak self] _ in
            Task { @MainActor in self?.refreshStatus() }
        }
        session.addSignOutHook { [weak self] in
            Task { @MainActor in self?.forget() }
        }
    }

    private var publicKeyDefault: String { "joinery.vault.\(scope).public" }
    /// The session key a handover completed under (for reading device_linked).
    private var handoverDefault: String { "joinery.vault.\(scope).handover_key" }

    /// The public key of the secret stored here, recorded at enrollment (not a
    /// secret), so a retirement is noticed without opening anything.
    public var storedPublicKey: String? { defaults.string(forKey: publicKeyDefault) }

    public func refreshStatus() {
        if held.isOpen(scope) { status = .open }
        else if keys.hasVaultSecret(scope: scope) { status = .locked }
        else { status = .notEnrolled }
    }

    // MARK: Using the key

    /// The secret, opening the gate if it is not in memory. Throws `.cancelled`
    /// from DeviceKeyStore when the person dismisses the prompt.
    public func openSecret(reason: String) async throws -> Data {
        if let s = held.secret(scope: scope) { return s }
        let context = try await keys.unlock(reason: reason)
        let secret: Data
        // A context that just passed Face ID knows the real enrollment: a new
        // face or finger shows here, with certainty (R1).
        if keys.biometryChanged(context: context) {
            forget()
            keys.forgetDeviceKey()
            throw Failure.message("A new face or finger was added, so this phone's key was reset. Hand it over again from a computer.")
        }
        switch keys.vaultSecret(scope: scope, context: context) {
        case .value(let s):
            secret = s
            keys.recordStateIfMissing(provenBy: context)
        case .gone:
            // Not found, or invalidated by a new face or finger: the banner
            // offers Enroll.
            forget()
            throw Failure.message("This phone no longer holds the key. Hand it over again from a computer.")
        case .unavailable:
            // A cancel, a failed match, a lockout: the key stays; try again.
            throw Failure.message("Your mail key could not be opened just now. Try Unlock again.")
        }
        // The small secrets kept beside this one open with the same prompt.
        for name in companions {
            if let extra = keys.secret(name: name, context: context) { held.holdExtra(extra, name: name) }
        }
        held.hold(secret, scope: scope)
        refreshStatus()
        return secret
    }

    /// The secret when it is already in memory; never prompts.
    public var heldSecret: Data? { held.secret(scope: scope) }

    public func lock() { held.drop(scope: scope) }

    // MARK: Enrollment

    public static var deviceName: String {
        let name = UIDevice.current.name
        return name.isEmpty ? "iPhone" : name
    }

    /// `device_key_enroll`: bind this phone's device key to its session key and
    /// open a link the person approves on a computer.
    public func enroll() async throws -> Ticket {
        // A new ceremony replaces any handover still waiting to be kept.
        pendingHandover = nil
        let pub = try keys.devicePublicKey()
        let body = JSONValue.object([
            (key: "device_pubkey", value: .string(pub)),
            (key: "platform", value: .string("ios")),
            (key: "device_name", value: .string(Self.deviceName)),
        ])
        let envelope = try await client.submitAction("device_key_enroll", body: body)
        let d = envelope["data"]
        guard let code = d?["link_code"]?.stringValue, let token = d?["poll_token"]?.stringValue else {
            throw JoineryAPIError.malformedResponse
        }
        return Ticket(linkCode: code,
                      verifyURL: d?["verify_url"]?.stringValue.flatMap(URL.init(string:)),
                      pollToken: token,
                      expiresTime: d?["expires_time"]?.stringValue ?? "",
                      pollAfter: d?["poll_after"]?.intValue ?? 3)
    }

    /// One poll. On approval the handed-over secret is opened with the device
    /// key (one biometric prompt), checked against the vault's public key,
    /// stored behind the gate and held; the sealed blob is discarded.
    public func poll(_ ticket: Ticket) async throws -> PollOutcome {
        let envelope: JSONValue
        do {
            envelope = try await client.request("POST", "/api/v1/auth/device_link/\(ticket.pollToken)",
                                                authenticated: false)
        } catch JoineryAPIError.authentication(_, let status) where status == 404 || status == 409 {
            return .expired
        }
        let d = envelope["data"]
        switch d?["status"]?.stringValue {
        case "pending": return .pending(after: d?["poll_after"]?.intValue ?? 3)
        case "denied": return .denied
        case "approved":
            guard let blob = d?["sealed_vault_keys"]?[scope]?.stringValue else {
                throw Failure.noKeyInHandoff
            }
            // Delivered once: held in memory until it is kept, so a failed
            // Face ID can be retried without a new enrollment (X3).
            pendingHandover = blob
            try await receive(blob)
            return .approved
        default:
            throw JoineryAPIError.malformedResponse
        }
    }

    /// Open a handed-over secret and keep it. Internal for the vector tests.
    func receive(_ blob: String) async throws {
        // The key is checked against the vault before anything is kept: no
        // answer from the site, nothing kept (F10).
        let p: Probe
        do { p = try await probe() } catch {
            throw Failure.message("Your site could not confirm the key just now. Nothing was kept; try again.")
        }
        let context = try await keys.unlock(reason: "Keep your mail key on this phone")
        var deviceSecret: Data
        switch keys.deviceSecret(context: context) {
        case .value(let d) where !keys.biometryChanged(context: context):
            deviceSecret = d
            keys.recordStateIfMissing(provenBy: context)
        case .unavailable:
            // Not readable just now: keep the device key and the handover,
            // and try again (X3).
            throw Failure.message("Face ID did not open this phone's key just now. Tap Try again.")
        default:
            // Gone, or invalidated by a new face or finger since the code was
            // made: the next Enroll makes a new device key.
            pendingHandover = nil
            keys.forgetDeviceKey()
            throw Failure.message("This phone's device key changed. Start again.")
        }
        defer { deviceSecret.wipe() }
        let opened: Data
        do {
            opened = try VaultCrypto.open(sealed: blob, secret: deviceSecret)
        } catch {
            // Sealed to a key this phone no longer holds: it will never open,
            // so "Try again" starts a new ceremony instead (L3).
            pendingHandover = nil
            throw Failure.message("This handover cannot be opened on this phone. Start again.")
        }
        // Kept as the keyring holds it — PKCS#8 — since the pin MAC and every
        // other derivation from "the secret" take those bytes, not the scalar.
        let secret = opened.count == 32 ? VaultCrypto.pkcs8(fromScalar: opened) : opened
        let derived = try VaultCrypto.publicKey(fromSecret: secret)
        // The key must be the vault's own (current or, mid-rotation, pending):
        // worked out from the secret, checked against what the server reports.
        guard p.setUp == true, p.vaultKeys.contains(derived) else { pendingHandover = nil; throw Failure.wrongKey }
        try keys.storeVaultSecret(secret, scope: scope)
        defaults.set(derived, forKey: publicKeyDefault)
        defaults.set(client.credentials?.publicKey, forKey: handoverDefault)
        pendingHandover = nil
        held.hold(secret, scope: scope)
        retired = false
        refreshStatus()
    }

    // MARK: Is the key still good? (B6)

    /// `vault_client_probe` for this scope.
    public func probe() async throws -> Probe {
        let envelope = try await client.submitAction("vault_client_probe",
                                                     body: .object([(key: "scope", value: .string(scope))]))
        guard let p = Probe(data: envelope["data"]) else { throw JoineryAPIError.malformedResponse }
        return p
    }

    /// At launch and on foreground: a key the server no longer lists for this
    /// device, or one that is not the vault's any more, is wiped, and the
    /// screen asks for a new handoff. Network failures leave everything as is.
    @discardableResult
    public func checkStillHeld() async -> Bool {
        // A new face or finger invalidated the stored key: it is gone.
        if keys.biometryChanged {
            forget()
            retired = true
            return false
        }
        let handedOver = defaults.string(forKey: handoverDefault)
        let underThisKey = handedOver != nil && handedOver == client.credentials?.publicKey
        guard keys.hasVaultSecret(scope: scope) || held.isOpen(scope) || underThisKey else { return false }
        // No answer, no change (F10).
        guard let p = try? await probe() else { return true }
        switch Self.judge(p, storedPublicKey: storedPublicKey, handedOverUnderThisKey: underThisKey) {
        case .keep:
            return true
        case .retired:
            forget()
            retired = true
            return false
        case .unlinked:
            unlink()
            return false
        }
    }

    /// The device was unlinked (its row removed, or its session revoked): the
    /// scope secret and both halves of the device key go.
    public func unlink() {
        forget()
        keys.forgetDeviceKey()
    }

    /// Wipe the stored and held secret (sign-out, unlink, retirement). The
    /// device key stays, so the next enrollment approves the same public key.
    public func forget() {
        pendingHandover = nil
        held.drop(scope: scope)
        keys.forgetVaultSecret(scope: scope)
        defaults.removeObject(forKey: publicKeyDefault)
        defaults.removeObject(forKey: handoverDefault)
        refreshStatus()
    }
}
