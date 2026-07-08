import Foundation
import JoineryKit

/// State for the native always-on block editor: the catalog (cached), the
/// block's current Block/Allow state, and the custom domain rules. Category and
/// service toggles are save-on-change (`block_filter_set`), matching the web
/// always-on editor; custom rules add/delete iteratively (`block_rule_add` /
/// `block_rule_delete`). "Allow" submits as *removing the row* — the
/// resolver-merge invariant lives server-side, so the client just sends the
/// same semantics.
@MainActor
public final class BlockEditorStore: ObservableObject {
    public enum Phase: Equatable {
        case loading
        case loaded
        case failed(String)
    }

    @Published public private(set) var phase: Phase = .loading
    /// filter_key / service_key currently blocked (has an action=0 row).
    @Published public private(set) var blockedFilters: Set<String> = []
    @Published public private(set) var blockedServices: Set<String> = []
    @Published public private(set) var rules: [DNSDomainRule] = []
    @Published public private(set) var catalog: DNSCatalog?
    @Published public var errorMessage: String?
    /// Keys with an in-flight toggle — the row shows a spinner and ignores taps.
    @Published public private(set) var busyKeys: Set<String> = []

    public let api: DNSFilterAPI
    public let account: DNSAccountSummary
    public let deviceID: Int
    public let blockID: Int
    /// Fired after any change that can alter the hard-block hostname list, so
    /// the protection layer can re-sync the strict-mode tunnel.
    public var onHardBlockChange: (() -> Void)?

    public init(api: DNSFilterAPI, account: DNSAccountSummary, deviceID: Int, blockID: Int) {
        self.api = api
        self.account = account
        self.deviceID = deviceID
        self.blockID = blockID
    }

    public func load() async {
        do {
            async let catalogCall = api.catalog()
            async let contentsCall = api.blockContents(deviceID: deviceID, blockID: blockID)
            let (catalog, contents) = try await (catalogCall, contentsCall)
            self.catalog = catalog
            blockedFilters = Set(contents.filters.filter { $0.value == 0 }.keys)
            blockedServices = Set(contents.services.filter { $0.value == 0 }.keys)
            rules = contents.rules
            phase = .loaded
        } catch {
            phase = .failed((error as? JoineryAPIError)?.displayMessage ?? "Could not load rules.")
        }
    }

    public func isFilterBlocked(_ key: String) -> Bool { blockedFilters.contains(key) }
    public func isServiceBlocked(_ key: String) -> Bool { blockedServices.contains(key) }
    public func isBusy(_ key: String) -> Bool { busyKeys.contains(key) }

    /// Toggle a category between Block (action=0 row) and Allow (no row).
    public func toggleFilter(_ key: String) async {
        guard !busyKeys.contains(key) else { return }
        let block = !blockedFilters.contains(key)
        busyKeys.insert(key)
        defer { busyKeys.remove(key) }
        do {
            try await api.setFilter(blockID: blockID, key: key, action: block ? 0 : nil)
            if block { blockedFilters.insert(key) } else { blockedFilters.remove(key) }
        } catch {
            errorMessage = (error as? JoineryAPIError)?.displayMessage ?? "Could not save."
        }
    }

    public func toggleService(_ key: String) async {
        guard !busyKeys.contains(key) else { return }
        let block = !blockedServices.contains(key)
        busyKeys.insert(key)
        defer { busyKeys.remove(key) }
        do {
            try await api.setService(blockID: blockID, key: key, action: block ? 0 : nil)
            if block { blockedServices.insert(key) } else { blockedServices.remove(key) }
        } catch {
            errorMessage = (error as? JoineryAPIError)?.displayMessage ?? "Could not save."
        }
    }

    // MARK: Custom domain rules

    public func addRule(hostname: String, action: Int, hardBlock: Bool) async {
        let host = hostname.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !host.isEmpty else { return }
        do {
            let rule = try await api.addDomainRule(blockID: blockID, hostname: host, action: action, hardBlock: hardBlock)
            rules.append(rule)
            if rule.hardBlock { onHardBlockChange?() }
        } catch {
            errorMessage = (error as? JoineryAPIError)?.displayMessage ?? "Could not add the rule."
        }
    }

    public func deleteRule(_ rule: DNSDomainRule) async {
        do {
            try await api.deleteDomainRule(ruleID: rule.ruleID)
            rules.removeAll { $0.ruleID == rule.ruleID }
            if rule.hardBlock { onHardBlockChange?() }
        } catch {
            errorMessage = (error as? JoineryAPIError)?.displayMessage ?? "Could not remove the rule."
        }
    }

    /// Flip a rule's hard-block flag. The API has no in-place update, so this
    /// re-creates the rule with the new flag (delete + add) — the server
    /// re-validates the always-on/block-action constraint on the add.
    public func setHardBlock(_ rule: DNSDomainRule, hardBlock: Bool) async {
        guard rule.hardBlock != hardBlock else { return }
        do {
            try await api.deleteDomainRule(ruleID: rule.ruleID)
            let replacement = try await api.addDomainRule(blockID: blockID, hostname: rule.hostname, action: rule.action, hardBlock: hardBlock)
            if let idx = rules.firstIndex(where: { $0.ruleID == rule.ruleID }) {
                rules[idx] = replacement
            }
            onHardBlockChange?()
        } catch {
            // Re-load to avoid drifting from the server on a partial failure.
            errorMessage = (error as? JoineryAPIError)?.displayMessage ?? "Could not update the rule."
            await load()
        }
    }
}
