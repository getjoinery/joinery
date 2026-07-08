import SwiftUI
import JoineryKit

/// The app's home: whether this phone is protected, the guided one-tap enable,
/// the Standard/Strict protection-level control, and Turn Off. Everything
/// account- and shell-shaped comes from JoineryKit; this screen is the
/// ScrollDaddy-specific activation surface.
public struct ProtectionScreen: View {
    @StateObject private var store: ProtectionStore
    private let client: APIClient
    @Environment(\.openURL) private var openURL

    public init(client: APIClient, config: DNSFilterConfig) {
        self.client = client
        _store = StateObject(wrappedValue: ProtectionStore(api: DNSFilterAPI(client: client), config: config))
    }

    /// Test seam: inject a pre-built store (mocked managers).
    public init(store: ProtectionStore, client: APIClient) {
        self.client = client
        _store = StateObject(wrappedValue: store)
    }

    public var body: some View {
        content
            .navigationTitle("Protection")
            .task { if case .loading = store.phase { await store.load() } }
            .alert("Something went wrong", isPresented: errorBinding) {
                Button("OK") {}
            } message: { Text(store.errorMessage ?? "") }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("protection_loading")
        case .failed(let message):
            retry(message)
        case .loaded:
            loaded
        }
    }

    private func retry(_ message: String) -> some View {
        VStack(spacing: 12) {
            Text(message).multilineTextAlignment(.center).foregroundStyle(.secondary)
                .accessibilityIdentifier("protection_error")
            Button("Try Again") { Task { await store.load() } }
                .buttonStyle(.borderedProminent)
        }
        .padding()
    }

    private var loaded: some View {
        List {
            statusSection
            if store.needsRegistration {
                registerSection
            } else {
                if store.activation == .needsEnable {
                    enableStepSection
                }
                protectionLevelSection
                editorLinkSection
                if store.isProtected { turnOffSection }
            }
        }
        .accessibilityIdentifier("protection_list")
        .refreshable { await store.load() }
    }

    // MARK: Sections

    private var statusSection: some View {
        Section {
            HStack(spacing: 14) {
                Image(systemName: store.isProtected ? "checkmark.shield.fill" : "shield.slash")
                    .font(.system(size: 34))
                    .foregroundStyle(store.isProtected ? Color.green : Color.secondary)
                VStack(alignment: .leading, spacing: 2) {
                    Text(store.isProtected ? "Protected" : "Not protected")
                        .font(.title3.weight(.semibold))
                        .accessibilityIdentifier("protection_status_label")
                    Text(store.isProtected
                         ? (store.mode == .strict ? "Strict mode is on." : "Encrypted DNS filtering is on.")
                         : "Finish setup to start filtering.")
                        .font(.subheadline).foregroundStyle(.secondary)
                }
            }
            .padding(.vertical, 4)
        }
    }

    private var registerSection: some View {
        Section {
            Text("Register this phone to apply ScrollDaddy filtering to it.")
                .font(.subheadline).foregroundStyle(.secondary)
            Button {
                Task { await store.registerThisPhone() }
            } label: {
                HStack {
                    Text("Set up this phone")
                    if store.isWorking { Spacer(); ProgressView() }
                }
            }
            .disabled(store.isWorking)
            .accessibilityIdentifier("protection_register")
        }
    }

    private var enableStepSection: some View {
        Section("One more step") {
            Text("iOS needs you to switch the profile on once: Settings → General → VPN, DNS & Device Management → DNS, then pick \(store.config.brandName). After that, every change applies automatically.")
                .font(.subheadline).foregroundStyle(.secondary)
            Button("Open Settings") {
                if let url = dnsSettingsDeepLink { openURL(url) }
            }
            .accessibilityIdentifier("protection_open_settings")
            Button("I enabled it — check") { Task { await store.load() } }
                .accessibilityIdentifier("protection_recheck")
        }
    }

    @ViewBuilder
    private var protectionLevelSection: some View {
        if store.config.strictModeAvailable {
            // Both modes offered — the single protection-level control.
            Section {
                Picker("Protection level", selection: modeBinding) {
                    ForEach(ProtectionMode.allCases) { m in Text(m.title).tag(m) }
                }
                .pickerStyle(.segmented)
                .accessibilityIdentifier("protection_mode_picker")
                Text(store.mode.summary).font(.footnote).foregroundStyle(.secondary)
                if !store.isProtected {
                    enableButton(label: store.mode == .strict ? "Turn on Strict mode" : "Turn on filtering")
                }
            } header: {
                Text("Protection level")
            } footer: {
                if store.strict == .vpnConflict {
                    Text("Another VPN is active. Turn it off to use Strict mode.")
                        .foregroundStyle(.orange)
                }
            }
        } else {
            // Strict mode (packet tunnel) not shipped in this build — Standard
            // (encrypted DNS) only. No mode picker, so there's no way to enable
            // a tunnel whose forwarding path isn't built yet.
            Section {
                Text(ProtectionMode.standard.summary).font(.footnote).foregroundStyle(.secondary)
                if !store.isProtected {
                    enableButton(label: "Turn on filtering")
                }
            } header: {
                Text("Protection")
            }
        }
    }

    private func enableButton(label: String) -> some View {
        Button {
            Task { await store.select(mode: store.mode) }
        } label: {
            HStack {
                Text(label)
                if store.isWorking { Spacer(); ProgressView() }
            }
        }
        .disabled(store.isWorking)
        .accessibilityIdentifier("protection_enable")
    }

    private var editorLinkSection: some View {
        Section {
            NavigationLink {
                if let account = store.account, let device = store.phoneDevice, let block = device.alwaysOnBlock {
                    AlwaysOnEditorScreen(client: client, account: account, deviceID: device.deviceID, blockID: block.blockID, onHardBlockChange: { Task { await store.syncHardBlockList() } })
                } else {
                    Text("Set up this phone first.").foregroundStyle(.secondary)
                }
            } label: {
                Label("Always-On Rules", systemImage: "slider.horizontal.3")
            }
            .accessibilityIdentifier("protection_edit_rules")
        }
    }

    private var turnOffSection: some View {
        Section {
            Button(role: .destructive) {
                Task { await store.turnOff() }
            } label: {
                Text("Turn off \(store.config.brandName)")
            }
            .accessibilityIdentifier("protection_turn_off")
        } footer: {
            Text("Removes the filtering configuration. Deleting the app does the same automatically — either way iOS goes back to your network's normal DNS.")
        }
    }

    // MARK: Bindings

    private var modeBinding: Binding<ProtectionMode> {
        Binding(
            get: { store.mode },
            set: { newValue in Task { await store.select(mode: newValue) } }
        )
    }

    private var errorBinding: Binding<Bool> {
        Binding(get: { store.errorMessage != nil }, set: { if !$0 { store.errorMessage = nil } })
    }
}
