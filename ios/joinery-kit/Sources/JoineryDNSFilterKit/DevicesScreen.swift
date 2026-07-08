import SwiftUI
import JoineryKit

/// The device list — every device on the account, each opening its always-on
/// editor. This app applies *configuration* only to the phone it runs on (that
/// lives on the Protection screen); this screen edits the shared *policy* for
/// any device, exactly as the web devices page does.
public struct DevicesScreen: View {
    @StateObject private var store: DeviceListStore
    private let client: APIClient
    private let web: WebSessionCoordinator?

    public init(client: APIClient, web: WebSessionCoordinator?) {
        self.client = client
        self.web = web
        _store = StateObject(wrappedValue: DeviceListStore(api: DNSFilterAPI(client: client)))
    }

    public var body: some View {
        content
            .navigationTitle("Devices")
            .task { if case .loading = store.phase { await store.load() } }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("devices_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message).multilineTextAlignment(.center).foregroundStyle(.secondary)
                    .accessibilityIdentifier("devices_error")
                Button("Try Again") { Task { await store.load() } }.buttonStyle(.borderedProminent)
            }.padding()
        case .loaded:
            list
        }
    }

    private var list: some View {
        List {
            if let account = store.account {
                Section {
                    ForEach(store.devices) { device in
                        deviceRow(device, account: account)
                    }
                    if store.devices.isEmpty {
                        Text("No devices yet.").foregroundStyle(.secondary)
                            .accessibilityIdentifier("devices_empty")
                    }
                } footer: {
                    Text("\(account.deviceCount) of \(account.deviceMax) devices used.")
                }
            }
        }
        .accessibilityIdentifier("devices_list")
        .refreshable { await store.load() }
    }

    @ViewBuilder
    private func deviceRow(_ device: DNSDevice, account: DNSAccountSummary) -> some View {
        if let block = device.alwaysOnBlock {
            NavigationLink {
                AlwaysOnEditorScreen(client: client, account: account, deviceID: device.deviceID, blockID: block.blockID)
            } label: {
                rowLabel(device)
            }
            .accessibilityIdentifier("device_\(device.deviceID)")
        } else {
            rowLabel(device)
        }
    }

    private func rowLabel(_ device: DNSDevice) -> some View {
        HStack(spacing: 12) {
            Image(systemName: deviceIcon(device.deviceType))
                .foregroundStyle(.secondary)
                .frame(width: 24)
            VStack(alignment: .leading, spacing: 2) {
                Text(device.name).font(.subheadline.weight(.medium))
                Text(device.isActive ? "Active" : "Paused")
                    .font(.caption).foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 2)
    }

    private func deviceIcon(_ type: String) -> String {
        switch type.lowercased() {
        case "phone": return "iphone"
        case "tablet": return "ipad"
        case "laptop", "computer", "desktop": return "laptopcomputer"
        default: return "network"
        }
    }
}
