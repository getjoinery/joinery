import SwiftUI
import JoineryKit

/// The native always-on block editor: category filters (general are the free
/// floor; advanced are tier-gated), service toggles, and custom domain rules.
/// Every toggle is save-on-change through `block_filter_set`; "off" removes the
/// row (Allow = no row). The server re-enforces every gate, so locked states
/// here are presentation only.
public struct AlwaysOnEditorScreen: View {
    @StateObject private var store: BlockEditorStore

    public init(client: APIClient, account: DNSAccountSummary, deviceID: Int, blockID: Int, onHardBlockChange: (() -> Void)? = nil) {
        let store = BlockEditorStore(api: DNSFilterAPI(client: client), account: account, deviceID: deviceID, blockID: blockID)
        store.onHardBlockChange = onHardBlockChange
        _store = StateObject(wrappedValue: store)
    }

    public var body: some View {
        content
            .navigationTitle("Always-On Rules")
            .navigationBarTitleDisplayMode(.inline)
            .task { if case .loading = store.phase { await store.load() } }
            .alert("Could not save", isPresented: errorBinding) {
                Button("OK") {}
            } message: { Text(store.errorMessage ?? "") }
    }

    @ViewBuilder
    private var content: some View {
        switch store.phase {
        case .loading:
            ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
                .accessibilityIdentifier("editor_loading")
        case .failed(let message):
            VStack(spacing: 12) {
                Text(message).multilineTextAlignment(.center).foregroundStyle(.secondary)
                    .accessibilityIdentifier("editor_error")
                Button("Try Again") { Task { await store.load() } }.buttonStyle(.borderedProminent)
            }.padding()
        case .loaded:
            editor
        }
    }

    private var editor: some View {
        List {
            if let catalog = store.catalog {
                generalSection(catalog.generalFilters)
                advancedSection(catalog.advancedFilters)
                servicesSections(catalog)
            }
            customRulesSection
        }
        .accessibilityIdentifier("editor_list")
    }

    // MARK: Categories

    private func generalSection(_ filters: [DNSCatalogFilter]) -> some View {
        Section {
            ForEach(filters) { filter in
                filterRow(filter, gated: false)
            }
        } header: {
            Text("Content Categories")
        } footer: {
            Text("Blocked categories won't resolve on this device.")
        }
    }

    @ViewBuilder
    private func advancedSection(_ filters: [DNSCatalogFilter]) -> some View {
        if !filters.isEmpty {
            Section {
                ForEach(filters) { filter in
                    filterRow(filter, gated: !store.account.advancedFilters)
                }
            } header: {
                HStack {
                    Text("Advanced Protection")
                    if !store.account.advancedFilters {
                        Image(systemName: "lock.fill").font(.caption).foregroundStyle(.secondary)
                    }
                }
            } footer: {
                if !store.account.advancedFilters {
                    Text("Ads, malware, phishing, and fake-news filters are available on Premium and Pro.")
                }
            }
        }
    }

    private func filterRow(_ filter: DNSCatalogFilter, gated: Bool) -> some View {
        let isBlocked = store.isFilterBlocked(filter.key)
        // A downgraded user can still turn an advanced filter OFF (remove the
        // row), just not on — mirrors the server's option-C escape hatch.
        let disabled = store.isBusy(filter.key) || (gated && !isBlocked)
        return Toggle(isOn: Binding(
            get: { isBlocked },
            set: { _ in Task { await store.toggleFilter(filter.key) } }
        )) {
            Text(filter.label)
        }
        .disabled(disabled)
        .accessibilityIdentifier("filter_\(filter.key)")
    }

    // MARK: Services

    @ViewBuilder
    private func servicesSections(_ catalog: DNSCatalog) -> some View {
        ForEach(catalog.serviceCategories) { category in
            if let items = catalog.services[category.key], !items.isEmpty {
                Section(category.label) {
                    ForEach(items) { service in
                        Toggle(isOn: Binding(
                            get: { store.isServiceBlocked(service.key) },
                            set: { _ in Task { await store.toggleService(service.key) } }
                        )) {
                            Text(service.label)
                        }
                        .disabled(store.isBusy(service.key))
                        .accessibilityIdentifier("service_\(service.key)")
                    }
                }
            }
        }
    }

    // MARK: Custom rules

    @ViewBuilder
    private var customRulesSection: some View {
        if store.account.customRules {
            CustomRulesSection(store: store)
        } else {
            Section("Custom Domain Rules") {
                HStack {
                    Image(systemName: "lock.fill").foregroundStyle(.secondary)
                    Text("Block or allow specific sites on Premium and Pro.")
                        .font(.subheadline).foregroundStyle(.secondary)
                }
                .accessibilityIdentifier("custom_rules_locked")
            }
        }
    }

    private var errorBinding: Binding<Bool> {
        Binding(get: { store.errorMessage != nil }, set: { if !$0 { store.errorMessage = nil } })
    }
}

/// The custom-rule list plus inline add form. Split out so the add-state lives
/// with the section it drives. Hard block is offered here because this is the
/// always-on, block-action context where the server permits it.
private struct CustomRulesSection: View {
    @ObservedObject var store: BlockEditorStore
    @State private var newHostname = ""
    @State private var newAction = 0 // 0 block, 1 allow
    @State private var newHardBlock = false

    var body: some View {
        Section {
            ForEach(store.rules) { rule in
                ruleRow(rule)
            }
            addForm
        } header: {
            Text("Custom Domain Rules")
        } footer: {
            Text("Hard block also stops the site at the connection level in Strict mode, even if an app brings its own DNS.")
        }
    }

    private func ruleRow(_ rule: DNSDomainRule) -> some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(rule.hostname).font(.subheadline.weight(.medium))
                HStack(spacing: 6) {
                    Text(rule.isBlock ? "Block" : "Allow").font(.caption).foregroundStyle(.secondary)
                    if rule.hardBlock {
                        Text("· Hard block").font(.caption).foregroundStyle(.orange)
                    }
                }
            }
            Spacer()
            if rule.isBlock {
                Button {
                    Task { await store.setHardBlock(rule, hardBlock: !rule.hardBlock) }
                } label: {
                    Image(systemName: rule.hardBlock ? "bolt.shield.fill" : "bolt.shield")
                }
                .buttonStyle(.borderless)
                .accessibilityIdentifier("rule_hardblock_\(rule.ruleID)")
            }
            Button(role: .destructive) {
                Task { await store.deleteRule(rule) }
            } label: {
                Image(systemName: "trash")
            }
            .buttonStyle(.borderless)
            .accessibilityIdentifier("rule_delete_\(rule.ruleID)")
        }
    }

    private var addForm: some View {
        VStack(alignment: .leading, spacing: 8) {
            TextField("example.com", text: $newHostname)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .keyboardType(.URL)
                .accessibilityIdentifier("rule_hostname_field")
            Picker("Action", selection: $newAction) {
                Text("Block").tag(0)
                Text("Allow").tag(1)
            }
            .pickerStyle(.segmented)
            if newAction == 0 {
                Toggle("Hard block (Strict mode)", isOn: $newHardBlock)
                    .font(.subheadline)
            }
            Button("Add rule") {
                let host = newHostname
                let action = newAction
                let hard = newAction == 0 && newHardBlock
                Task {
                    await store.addRule(hostname: host, action: action, hardBlock: hard)
                    newHostname = ""
                    newHardBlock = false
                }
            }
            .buttonStyle(.borderedProminent)
            .disabled(newHostname.trimmingCharacters(in: .whitespaces).isEmpty)
            .accessibilityIdentifier("rule_add_button")
        }
        .padding(.vertical, 4)
    }
}
