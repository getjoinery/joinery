import Foundation
import JoineryKit

// Typed models over the `dns_filtering/` API surface
// (plugins/dns_filtering/docs/overview.md § API Surface). Every shape here is
// the JSON-clean export the server emits (ScrollDaddyHelper::exportDevice /
// exportBlock, account_summary, catalog) — no brand knowledge, so a second
// ScrollDaddy-style deployment (NetworkSentry) parses identically.

// MARK: - devices

/// One device from the `devices` action. `dohURL` is what the standard-mode
/// activation flow saves into `NEDNSSettingsManager`; `hardBlockHostnames` is
/// what strict mode's packet tunnel enforces at the connection level.
public struct DNSDevice: Identifiable, Equatable, Sendable {
    public let deviceID: Int
    public let name: String
    public let deviceName: String
    public let deviceType: String
    public let timezone: String
    public let isActive: Bool
    public let logQueries: Bool
    public let filtersEditable: Bool
    public let resolverUID: String
    public let dohURL: String
    public let dotHostname: String
    public let hardBlockHostnames: [String]
    public let lastSeen: String?
    public let blocks: [DNSBlockSummary]

    public var id: Int { deviceID }

    /// The always-on block is the baseline policy every device carries.
    public var alwaysOnBlock: DNSBlockSummary? {
        blocks.first(where: { $0.isAlwaysOn })
    }

    public var scheduledBlocks: [DNSBlockSummary] {
        blocks.filter { !$0.isAlwaysOn }
    }

    public init?(json: JSONValue?) {
        guard let json, let deviceID = json["device_id"]?.intValue else { return nil }
        self.deviceID = deviceID
        name = json["name"]?.stringValue ?? ""
        deviceName = json["device_name"]?.stringValue ?? ""
        deviceType = json["device_type"]?.stringValue ?? ""
        timezone = json["timezone"]?.stringValue ?? "UTC"
        isActive = json["is_active"]?.boolValue ?? true
        logQueries = json["log_queries"]?.boolValue ?? false
        filtersEditable = json["filters_editable"]?.boolValue ?? true
        resolverUID = json["resolver_uid"]?.stringValue ?? ""
        dohURL = json["doh_url"]?.stringValue ?? ""
        dotHostname = json["dot_hostname"]?.stringValue ?? ""
        hardBlockHostnames = (json["hard_block_hostnames"]?.arrayValue ?? []).compactMap(\.stringValue)
        // `last_seen` arrives as an object ({seen: ...}) proxied from the DNS
        // server, as a bare string, or null. Fold all three to the timestamp.
        lastSeen = json["last_seen"]?["seen"]?.stringValue ?? json["last_seen"]?.stringValue
        blocks = (json["blocks"]?.arrayValue ?? []).compactMap(DNSBlockSummary.init(json:))
    }
}

/// The `blocks` summary rows on a device (no rule contents — `rule_count`
/// only). The full contents come from `block_list` / `scheduled_block_edit`.
public struct DNSBlockSummary: Identifiable, Equatable, Sendable {
    public let blockID: Int
    public let name: String
    public let isAlwaysOn: Bool
    public let isActive: Bool
    public let activeNow: Bool
    public let ruleCount: Int
    public let schedule: DNSSchedule

    public var id: Int { blockID }

    public init?(json: JSONValue?) {
        guard let json, let blockID = json["block_id"]?.intValue else { return nil }
        self.blockID = blockID
        name = json["name"]?.stringValue ?? ""
        isAlwaysOn = json["is_always_on"]?.boolValue ?? false
        isActive = json["is_active"]?.boolValue ?? true
        activeNow = json["active_now"]?.boolValue ?? false
        ruleCount = json["rule_count"]?.intValue ?? 0
        schedule = DNSSchedule(json: json["schedule"])
    }
}

public struct DNSSchedule: Equatable, Sendable {
    public let start: String?
    public let end: String?
    public let days: [Int]
    public let timezone: String?

    public init(json: JSONValue?) {
        start = json?["start"]?.stringValue
        end = json?["end"]?.stringValue
        days = (json?["days"]?.arrayValue ?? []).compactMap(\.intValue)
        timezone = json?["timezone"]?.stringValue
    }
}

// MARK: - block contents (scheduled_block_edit read / block_list)

/// Full contents of one block: category filters, service toggles, and custom
/// domain rules. The always-on editor loads this to render its Block/Allow
/// state.
public struct DNSBlockContents: Equatable, Sendable {
    public let blockID: Int
    public let name: String
    public let isAlwaysOn: Bool
    public let schedule: DNSSchedule
    /// filter_key -> action (0 block, 1 allow). Absent key == Allow ("no row").
    public let filters: [String: Int]
    public let services: [String: Int]
    public let rules: [DNSDomainRule]

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        // scheduled_block_edit read wraps the block under `block`; be lenient
        // and accept a flat shape too.
        let block = data["block"] ?? data
        guard let blockID = block["block_id"]?.intValue else { return nil }
        self.blockID = blockID
        name = block["name"]?.stringValue ?? ""
        isAlwaysOn = block["is_always_on"]?.boolValue ?? false
        schedule = DNSSchedule(json: block["schedule"])

        filters = Self.actionMap(block["filters"])
        services = Self.actionMap(block["services"])
        rules = (block["rules"]?.arrayValue ?? []).compactMap(DNSDomainRule.init(json:))
    }

    /// The block's filter/service rows arrive either as a list of
    /// `{key/filter_key, action}` objects or as a `{key: action}` map,
    /// depending on the action. Fold both into `[key: action]`.
    private static func actionMap(_ value: JSONValue?) -> [String: Int] {
        guard let value else { return [:] }
        var out: [String: Int] = [:]
        if let rows = value.arrayValue {
            for row in rows {
                let key = row["key"]?.stringValue
                    ?? row["filter_key"]?.stringValue
                    ?? row["service_key"]?.stringValue
                if let key, let action = row["action"]?.intValue {
                    out[key] = action
                }
            }
        } else if let pairs = value.objectValue {
            for (key, v) in pairs {
                if let action = v.intValue { out[key] = action }
                else if let action = v["action"]?.intValue { out[key] = action }
            }
        }
        return out
    }
}

/// A custom domain rule (`sbr_scheduled_block_rules`). `hardBlock` marks it
/// for the strict-mode tunnel's connection-level enforcement.
public struct DNSDomainRule: Identifiable, Equatable, Sendable {
    public let ruleID: Int
    public let hostname: String
    /// 0 = block, 1 = allow.
    public let action: Int
    public let isActive: Bool
    public let hardBlock: Bool

    public var id: Int { ruleID }
    public var isBlock: Bool { action == 0 }

    public init?(json: JSONValue?) {
        guard let json, let ruleID = json["rule_id"]?.intValue else { return nil }
        self.ruleID = ruleID
        hostname = json["hostname"]?.stringValue ?? ""
        action = json["action"]?.intValue ?? 0
        isActive = json["is_active"]?.boolValue ?? true
        hardBlock = json["hard_block"]?.boolValue ?? false
    }
}

// MARK: - account_summary

/// The five ScrollDaddy feature flags plus device count vs. limit. The client
/// renders locked/upsell states from these; the server rejects gated writes
/// regardless (tier gating is server-enforced).
public struct DNSAccountSummary: Equatable, Sendable {
    public let tierName: String?
    public let maxDevices: Int
    public let maxScheduledBlocks: Int
    public let customRules: Bool
    public let advancedFilters: Bool
    public let queryLogging: Bool
    public let deviceCount: Int
    public let deviceMax: Int

    public var atDeviceLimit: Bool { deviceCount >= deviceMax }

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        tierName = data["tier_name"]?.stringValue
        let features = data["features"]
        maxDevices = features?["scrolldaddy_max_devices"]?.intValue ?? 1
        maxScheduledBlocks = features?["scrolldaddy_max_scheduled_blocks"]?.intValue ?? 0
        customRules = features?["scrolldaddy_custom_rules"]?.boolValue ?? false
        advancedFilters = features?["scrolldaddy_advanced_filters"]?.boolValue ?? false
        queryLogging = features?["scrolldaddy_query_logging"]?.boolValue ?? false
        deviceCount = data["device_count"]?.intValue ?? 0
        deviceMax = data["device_max"]?.intValue ?? maxDevices
    }
}

// MARK: - catalog

/// The filter/service catalog, static per deployment (cache client-side). The
/// `advanced` flag gates a filter behind `scrolldaddy_advanced_filters`.
public struct DNSCatalog: Equatable, Sendable {
    public let filters: [DNSCatalogFilter]
    public let serviceCategories: [DNSCatalogServiceCategory]
    /// category_key -> the services in that category.
    public let services: [String: [DNSCatalogService]]

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        filters = (data["filters"]?.arrayValue ?? []).compactMap(DNSCatalogFilter.init(json:))
        serviceCategories = (data["service_categories"]?.arrayValue ?? []).compactMap(DNSCatalogServiceCategory.init(json:))
        var svc: [String: [DNSCatalogService]] = [:]
        for (key, value) in (data["services"]?.objectValue ?? []) {
            svc[key] = (value.arrayValue ?? []).compactMap(DNSCatalogService.init(json:))
        }
        services = svc
    }

    /// General (ungated) filters — the free-tier floor. Ordered as the server
    /// returned them.
    public var generalFilters: [DNSCatalogFilter] { filters.filter { !$0.advanced } }
    public var advancedFilters: [DNSCatalogFilter] { filters.filter { $0.advanced } }
}

public struct DNSCatalogFilter: Identifiable, Equatable, Sendable {
    public let key: String
    public let label: String
    public let advanced: Bool
    public var id: String { key }

    public init?(json: JSONValue?) {
        guard let json, let key = json["key"]?.stringValue else { return nil }
        self.key = key
        label = json["label"]?.stringValue ?? key
        advanced = json["advanced"]?.boolValue ?? false
    }
}

public struct DNSCatalogServiceCategory: Identifiable, Equatable, Sendable {
    public let key: String
    public let label: String
    public var id: String { key }

    public init?(json: JSONValue?) {
        guard let json, let key = json["key"]?.stringValue else { return nil }
        self.key = key
        label = json["label"]?.stringValue ?? key
    }
}

public struct DNSCatalogService: Identifiable, Equatable, Sendable {
    public let key: String
    public let label: String
    public var id: String { key }

    public init?(json: JSONValue?) {
        guard let json, let key = json["key"]?.stringValue else { return nil }
        self.key = key
        label = json["label"]?.stringValue ?? key
    }
}

// MARK: - protection mode

/// The one "protection level" control the app presents. Standard and Strict
/// are mutually exclusive at the OS level (an active VPN supersedes installed
/// DNS settings), so the app owns the switch between them.
public enum ProtectionMode: String, Sendable, CaseIterable, Identifiable {
    /// Encrypted DNS via `NEDNSSettingsManager` — policy stays server-side.
    case standard
    /// Local `NEPacketTunnelProvider` adding connection-level hard blocking.
    case strict

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .standard: return "Standard"
        case .strict: return "Strict"
        }
    }

    public var summary: String {
        switch self {
        case .standard:
            return "Encrypted DNS filtering. Blocks sites at lookup — no VPN, minimal battery."
        case .strict:
            return "Adds an on-device VPN that hard-blocks selected sites at the connection level, even when an app brings its own DNS. Traffic never leaves your phone."
        }
    }
}
