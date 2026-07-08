import Foundation
import JoineryKit

/// Thin typed face over the `dns_filtering/` action namespace
/// (`POST /api/v1/action/dns_filtering/{action}`). Every call rides the app's
/// session key through APIClient; tier gating, ownership, and save semantics
/// are entirely server-side — the same logic functions the web editor calls —
/// so this layer holds no policy, only shapes.
public struct DNSFilterAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    private func ns(_ name: String) -> String { "dns_filtering/\(name)" }

    // MARK: Reads

    /// List the user's devices with DoH/DoT endpoints, per-block summaries,
    /// and the merged hard-block hostname list.
    public func devices() async throws -> [DNSDevice] {
        let envelope = try await client.submitAction(ns("devices"), body: .object([]))
        return (envelope["data"]?["devices"]?.arrayValue ?? []).compactMap(DNSDevice.init(json:))
    }

    /// Tier name, the five feature flags, and device count vs. limit.
    public func accountSummary() async throws -> DNSAccountSummary {
        let envelope = try await client.submitAction(ns("account_summary"), body: .object([]))
        guard let summary = DNSAccountSummary(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return summary
    }

    /// The filter/service catalog — static per deployment, cache client-side.
    public func catalog() async throws -> DNSCatalog {
        let envelope = try await client.submitAction(ns("catalog"), body: .object([]))
        guard let catalog = DNSCatalog(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return catalog
    }

    /// Read one block's full contents (`scheduled_block_edit` with no `action`
    /// key). `device_id` is required; `block_id` selects the always-on or a
    /// specific scheduled block.
    public func blockContents(deviceID: Int, blockID: Int) async throws -> DNSBlockContents {
        let envelope = try await client.submitAction(ns("scheduled_block_edit"), body: .object([
            (key: "device_id", value: .number(Double(deviceID))),
            (key: "block_id", value: .number(Double(blockID))),
        ]))
        guard let contents = DNSBlockContents(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return contents
    }

    // MARK: Category / service toggles (save-on-change)

    /// Set or clear one category filter on a block. `action` nil == Allow,
    /// which the server writes as "no row" (the resolver-merge invariant —
    /// "Allow means no row"). Blocking writes an `action=0` row.
    public func setFilter(blockID: Int, key: String, action: Int?) async throws {
        try await setRule(blockID: blockID, type: "filter", key: key, action: action)
    }

    /// Set or clear one service toggle on a block.
    public func setService(blockID: Int, key: String, action: Int?) async throws {
        try await setRule(blockID: blockID, type: "service", key: key, action: action)
    }

    private func setRule(blockID: Int, type: String, key: String, action: Int?) async throws {
        _ = try await client.submitAction(ns("block_filter_set"), body: .object([
            (key: "block_id", value: .number(Double(blockID))),
            (key: "type", value: .string(type)),
            (key: "key", value: .string(key)),
            // Empty string removes the row; "0" blocks, "1" allows.
            (key: "action", value: .string(action.map { String($0) } ?? "")),
        ]))
    }

    // MARK: Custom domain rules

    /// Add a custom domain rule. `hardBlock` (block-action + always-on block
    /// only, server-enforced) marks it for the strict-mode tunnel. Rides the
    /// `scrolldaddy_custom_rules` gate.
    @discardableResult
    public func addDomainRule(blockID: Int, hostname: String, action: Int, hardBlock: Bool = false) async throws -> DNSDomainRule {
        let envelope = try await client.submitAction(ns("block_rule_add"), body: .object([
            (key: "block_id", value: .number(Double(blockID))),
            (key: "hostname", value: .string(hostname)),
            (key: "action", value: .number(Double(action))),
            (key: "hard_block", value: .bool(hardBlock)),
        ]))
        guard let rule = DNSDomainRule(json: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return rule
    }

    public func deleteDomainRule(ruleID: Int) async throws {
        _ = try await client.submitAction(ns("block_rule_delete"), body: .object([
            (key: "rule_id", value: .number(Double(ruleID))),
        ]))
    }

    // MARK: Devices

    /// Register a device (omit `device_id`). The create path is keyed on
    /// `device_name` and reads `device_type` / `sdd_timezone` /
    /// `sdd_allow_device_edits` (device_edit_logic + SdDevice::createDevice),
    /// then redirects — it returns no id, so the caller diffs the device list
    /// to find the new row. Rides the `scrolldaddy_max_devices` gate.
    public func createDevice(name: String, deviceType: String, timezone: String) async throws {
        _ = try await client.submitAction(ns("device_edit"), body: .object([
            (key: "device_name", value: .string(name)),
            (key: "device_type", value: .string(deviceType)),
            (key: "sdd_timezone", value: .string(timezone)),
            // The create path reads this unguarded; the app always allows edits.
            (key: "sdd_allow_device_edits", value: .string("1")),
        ]))
    }
}
