import Foundation
import JoineryKit

/// The brand-neutral configuration the DNS filter kit needs from the app
/// target: the deployment origin (for per-deployment phone pinning), a display
/// name for the saved profiles, and the packet-tunnel extension's bundle id.
public struct DNSFilterConfig: Sendable {
    public let baseURL: URL
    public let brandName: String
    public let tunnelBundleID: String
    /// Whether Strict mode (the packet tunnel) is offered. Off until the tunnel
    /// transport is complete: the packet relay's forwarding path
    /// (`PacketRelay.forward`) and in-tunnel DNS handler are the deferred Phase 4
    /// work, and an entitled device build would black-hole all traffic without
    /// them. The app flips this to true only in a release that ships the relay.
    public let strictModeAvailable: Bool

    public init(baseURL: URL, brandName: String, tunnelBundleID: String, strictModeAvailable: Bool = false) {
        self.baseURL = baseURL
        self.brandName = brandName
        self.tunnelBundleID = tunnelBundleID
        self.strictModeAvailable = strictModeAvailable
    }
}

/// The status/home screen's state machine. Resolves *this phone's* device row,
/// drives the standard-mode `NEDNSSettingsManager` activation flow and the
/// strict-mode packet tunnel, and keeps the tunnel's hard-block list in sync
/// with the server on every policy change.
@MainActor
public final class ProtectionStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var account: DNSAccountSummary?
    @Published public private(set) var phoneDevice: DNSDevice?
    @Published public private(set) var activation: DNSActivationStatus = .notConfigured
    @Published public private(set) var strict: StrictModeStatus = .off
    @Published public private(set) var mode: ProtectionMode = .standard
    @Published public var errorMessage: String?
    @Published public private(set) var isWorking = false

    public let api: DNSFilterAPI
    public let config: DNSFilterConfig
    private let activationManager: DNSActivating
    private let strictManager: StrictModeManaging
    private let phoneStore: PhoneDeviceStore

    public init(
        api: DNSFilterAPI,
        config: DNSFilterConfig,
        activationManager: DNSActivating = DNSActivationManager(),
        strictManager: StrictModeManaging? = nil
    ) {
        self.api = api
        self.config = config
        self.activationManager = activationManager
        self.strictManager = strictManager ?? StrictModeManager(tunnelBundleID: config.tunnelBundleID)
        self.phoneStore = PhoneDeviceStore(baseURL: config.baseURL)
    }

    /// "Protected" once the encrypted-DNS profile is enabled, or strict mode is
    /// running. Drives the big status banner.
    public var isProtected: Bool {
        activation == .protected || strict == .on
    }

    public func load() async {
        do {
            async let devicesCall = api.devices()
            async let accountCall = api.accountSummary()
            let (devices, account) = try await (devicesCall, accountCall)
            self.account = account
            self.phoneDevice = resolvePhone(in: devices)
            await refreshStatuses()
            phase = .loaded
        } catch {
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? "Could not load your protection status.")
        }
    }

    /// Match the persisted phone device_id against the fresh list; if the row
    /// was deleted server-side, forget it.
    private func resolvePhone(in devices: [DNSDevice]) -> DNSDevice? {
        if let id = phoneStore.deviceID, let device = devices.first(where: { $0.deviceID == id }) {
            return device
        }
        phoneStore.set(nil)
        return nil
    }

    private func refreshStatuses() async {
        activation = await activationManager.refresh()
        strict = await strictManager.refresh()
        if strict == .on { mode = .strict }
    }

    // MARK: Onboarding — register this phone

    public var needsRegistration: Bool { phoneDevice == nil }

    public func registerThisPhone() async {
        guard !isWorking else { return }
        isWorking = true
        defer { isWorking = false }
        do {
            // Snapshot existing ids first: create redirects with no id, and the
            // account may already own devices (laptop, other phones), so we pin
            // the row that appears — never the "newest", which could be another
            // device this app must not claim as this phone.
            let before = Set(try await api.devices().map(\.deviceID))
            try await api.createDevice(name: defaultDeviceName(), deviceType: "phone", timezone: TimeZone.current.identifier)
            let after = try await api.devices()
            let created = after.filter { !before.contains($0.deviceID) }
            guard let newDevice = created.max(by: { $0.deviceID < $1.deviceID }) else {
                errorMessage = "This phone could not be registered. Please try again."
                return
            }
            phoneStore.set(newDevice.deviceID)
            phoneDevice = newDevice
        } catch {
            errorMessage = (error as? JoineryAPIError)?.displayMessage ?? "Could not register this phone."
        }
    }

    private func defaultDeviceName() -> String {
        #if canImport(UIKit)
        return "My iPhone"
        #else
        return "My Phone"
        #endif
    }

    // MARK: Standard mode

    /// Save the DoH configuration for this phone, then the UI guides the
    /// one-time Settings enable. Idempotent — re-saving after a UID change
    /// applies silently once enabled.
    public func enableStandard() async {
        guard let device = phoneDevice, !device.dohURL.isEmpty else {
            errorMessage = DNSActivationError.invalidURL.errorDescription
            return
        }
        isWorking = true
        defer { isWorking = false }
        do {
            if strict == .on { try await strictManager.stop() }
            try await activationManager.install(dohURL: device.dohURL, brandName: config.brandName)
            mode = .standard
            await refreshStatuses()
        } catch {
            errorMessage = (error as? LocalizedError)?.errorDescription ?? "Could not save the DNS configuration."
        }
    }

    // MARK: Strict mode

    public func enableStrict() async {
        guard let device = phoneDevice, !device.dohURL.isEmpty else {
            errorMessage = DNSActivationError.invalidURL.errorDescription
            return
        }
        isWorking = true
        defer { isWorking = false }
        do {
            // Keep standard mode installed as the floor first, THEN start the
            // tunnel (an active VPN supersedes the DoH config at the OS level).
            // If start fails — a VPN conflict is an expected, designed failure —
            // the DoH profile is still in place, so the user falls back to
            // standard DNS protection, never to unprotected (acceptance item 5).
            try await activationManager.install(dohURL: device.dohURL, brandName: config.brandName)
            try await strictManager.start(
                dohURL: device.dohURL,
                hardBlockHostnames: device.hardBlockHostnames,
                brandName: config.brandName
            )
            mode = .strict
            await refreshStatuses()
        } catch {
            // Standard remains installed; reflect that we fell back to it.
            mode = .standard
            await refreshStatuses()
            errorMessage = (error as? LocalizedError)?.errorDescription ?? "Could not start strict mode."
        }
    }

    /// Set the single protection-level control. Switching is the app's job
    /// because the two modes can't coexist.
    public func select(mode newMode: ProtectionMode) async {
        guard newMode != mode || !isProtected else { return }
        switch newMode {
        case .standard: await enableStandard()
        case .strict: await enableStrict()
        }
    }

    /// "Turn off ScrollDaddy": remove the DoH profile and stop the tunnel. iOS
    /// reverts to the network's original DNS immediately, with no residue.
    public func turnOff() async {
        isWorking = true
        defer { isWorking = false }
        try? await activationManager.remove()
        try? await strictManager.stop()
        await refreshStatuses()
    }

    /// Re-read this phone's device (picking up a fresh hard-block list) and push
    /// it into the running tunnel. Called after a custom-rule change.
    public func syncHardBlockList() async {
        guard let id = phoneDevice?.deviceID else { return }
        guard let refreshed = try? await api.devices().first(where: { $0.deviceID == id }) else { return }
        phoneDevice = refreshed
        if strict == .on {
            try? await strictManager.syncHardBlockList(refreshed.hardBlockHostnames)
        }
    }
}
