import Foundation
import JoineryKit

/// Remembers which device row *is this phone*. A user manages several devices
/// (laptop, other phones) from one account, but this app applies configuration
/// only to the handset it runs on, so it pins one `device_id` locally. Keyed by
/// deployment origin so a build pointed at a second deployment tracks its own.
public struct PhoneDeviceStore: Sendable {
    private let defaults: UserDefaults
    private let key: String

    public init(baseURL: URL, defaults: UserDefaults = .standard) {
        self.defaults = defaults
        self.key = "scrolldaddy.phone_device_id.\(baseURL.host ?? baseURL.absoluteString)"
    }

    public var deviceID: Int? {
        let value = defaults.integer(forKey: key)
        return value > 0 ? value : nil
    }

    public func set(_ deviceID: Int?) {
        if let deviceID, deviceID > 0 {
            defaults.set(deviceID, forKey: key)
        } else {
            defaults.removeObject(forKey: key)
        }
    }
}

/// Loads the account summary and device list. Backs both the device picker and
/// the protection screen's "this phone" resolution.
@MainActor
public final class DeviceListStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var devices: [DNSDevice] = []
    @Published public private(set) var account: DNSAccountSummary?

    public let api: DNSFilterAPI

    public init(api: DNSFilterAPI) {
        self.api = api
    }

    public func load() async {
        do {
            async let devicesCall = api.devices()
            async let accountCall = api.accountSummary()
            let (devices, account) = try await (devicesCall, accountCall)
            self.devices = devices
            self.account = account
            phase = .loaded
        } catch {
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? "Could not load devices.")
        }
    }

    public func device(id: Int) -> DNSDevice? {
        devices.first { $0.deviceID == id }
    }
}
