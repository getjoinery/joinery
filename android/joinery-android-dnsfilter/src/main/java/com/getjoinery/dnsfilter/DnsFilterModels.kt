package com.getjoinery.dnsfilter

import com.getjoinery.android.JsonValue

// Typed models over the `dns_filtering/` API surface
// (plugins/dns_filtering/docs/overview.md § API Surface). Every shape here is
// the JSON-clean export the server emits (ScrollDaddyHelper::exportDevice /
// exportBlock, account_summary, catalog) — no brand knowledge, so a second
// ScrollDaddy-style deployment (NetworkSentry) parses identically. Ported 1:1
// from the iOS JoineryDNSFilterKit models, which are unit-tested against the
// same fixtures.

// MARK: - devices

/**
 * One device from the `devices` action. [dohUrl] is what the standard-mode
 * VpnService forwards captured DNS queries to; [hardBlockHostnames] is what
 * strict mode's tunnel enforces at the connection level.
 */
data class DnsDevice(
    val deviceId: Int,
    val name: String,
    val deviceName: String,
    val deviceType: String,
    val timezone: String,
    val isActive: Boolean,
    val logQueries: Boolean,
    val filtersEditable: Boolean,
    val resolverUid: String,
    val dohUrl: String,
    val dotHostname: String,
    val hardBlockHostnames: List<String>,
    /** When the resolver last saw this device, or null. Proxied from the DNS
     *  server as an object (`{seen: ...}`); a bare string is tolerated too. */
    val lastSeen: String?,
    val blocks: List<DnsBlockSummary>,
) {
    /** The always-on block is the baseline policy every device carries. */
    val alwaysOnBlock: DnsBlockSummary? get() = blocks.firstOrNull { it.isAlwaysOn }
    val scheduledBlocks: List<DnsBlockSummary> get() = blocks.filter { !it.isAlwaysOn }

    companion object {
        fun from(json: JsonValue?): DnsDevice? {
            val j = json ?: return null
            val deviceId = j["device_id"]?.intValue ?: return null
            return DnsDevice(
                deviceId = deviceId,
                name = j["name"]?.stringValue ?: "",
                deviceName = j["device_name"]?.stringValue ?: "",
                deviceType = j["device_type"]?.stringValue ?: "",
                timezone = j["timezone"]?.stringValue ?: "UTC",
                isActive = j["is_active"]?.boolValue ?: true,
                logQueries = j["log_queries"]?.boolValue ?: false,
                filtersEditable = j["filters_editable"]?.boolValue ?: true,
                resolverUid = j["resolver_uid"]?.stringValue ?: "",
                dohUrl = j["doh_url"]?.stringValue ?: "",
                dotHostname = j["dot_hostname"]?.stringValue ?: "",
                hardBlockHostnames = (j["hard_block_hostnames"]?.arrayValue ?: emptyList())
                    .mapNotNull { it.stringValue },
                lastSeen = parseLastSeen(j["last_seen"]),
                blocks = (j["blocks"]?.arrayValue ?: emptyList())
                    .mapNotNull { DnsBlockSummary.from(it) },
            )
        }

        /** `last_seen` arrives as an object (`{seen: "..."}`) proxied from the
         *  DNS server, as a bare string, or null. Fold all three to the seen
         *  timestamp. An empty object (PHP `[]`) reads as null. */
        private fun parseLastSeen(value: JsonValue?): String? {
            if (value == null || value.isNull) return null
            value.get("seen")?.stringValue?.let { return it }
            return value.stringValue
        }
    }
}

/**
 * The `blocks` summary rows on a device (no rule contents — [ruleCount] only).
 * The full contents come from `scheduled_block_edit`.
 */
data class DnsBlockSummary(
    val blockId: Int,
    val name: String,
    val isAlwaysOn: Boolean,
    val isActive: Boolean,
    val activeNow: Boolean,
    val ruleCount: Int,
    val schedule: DnsSchedule,
) {
    companion object {
        fun from(json: JsonValue?): DnsBlockSummary? {
            val j = json ?: return null
            val blockId = j["block_id"]?.intValue ?: return null
            return DnsBlockSummary(
                blockId = blockId,
                name = j["name"]?.stringValue ?: "",
                isAlwaysOn = j["is_always_on"]?.boolValue ?: false,
                isActive = j["is_active"]?.boolValue ?: true,
                activeNow = j["active_now"]?.boolValue ?: false,
                ruleCount = j["rule_count"]?.intValue ?: 0,
                schedule = DnsSchedule.from(j["schedule"]),
            )
        }
    }
}

data class DnsSchedule(
    val start: String?,
    val end: String?,
    val days: List<Int>,
    val timezone: String?,
) {
    companion object {
        fun from(json: JsonValue?): DnsSchedule = DnsSchedule(
            start = json?.get("start")?.stringValue,
            end = json?.get("end")?.stringValue,
            days = (json?.get("days")?.arrayValue ?: emptyList()).mapNotNull { it.intValue },
            timezone = json?.get("timezone")?.stringValue,
        )
    }
}

// MARK: - block contents (scheduled_block_edit read)

/**
 * Full contents of one block: category filters, service toggles, and custom
 * domain rules. The always-on editor loads this to render its Block/Allow
 * state. [filters]/[services] map key -> action (0 block, 1 allow); an absent
 * key means Allow ("no row").
 */
data class DnsBlockContents(
    val blockId: Int,
    val name: String,
    val isAlwaysOn: Boolean,
    val schedule: DnsSchedule,
    val filters: Map<String, Int>,
    val services: Map<String, Int>,
    val rules: List<DnsDomainRule>,
) {
    companion object {
        fun from(data: JsonValue?): DnsBlockContents? {
            if (data == null) return null
            // scheduled_block_edit read wraps the block under `block`; be lenient
            // and accept a flat shape too.
            val block = data["block"] ?: data
            val blockId = block["block_id"]?.intValue ?: return null
            return DnsBlockContents(
                blockId = blockId,
                name = block["name"]?.stringValue ?: "",
                isAlwaysOn = block["is_always_on"]?.boolValue ?: false,
                schedule = DnsSchedule.from(block["schedule"]),
                filters = actionMap(block["filters"]),
                services = actionMap(block["services"]),
                rules = (block["rules"]?.arrayValue ?: emptyList()).mapNotNull { DnsDomainRule.from(it) },
            )
        }

        /** The block's filter/service rows arrive either as a list of
         *  `{key/filter_key, action}` objects or as a `{key: action}` map,
         *  depending on the action. An empty PHP assoc array serializes as `[]`,
         *  which [JsonValue.objectValue] reads as an empty object. Fold all
         *  forms into `{key: action}`. */
        private fun actionMap(value: JsonValue?): Map<String, Int> {
            if (value == null) return emptyMap()
            val out = LinkedHashMap<String, Int>()
            val rows = value.arrayValue
            if (rows != null) {
                for (row in rows) {
                    val key = row["key"]?.stringValue
                        ?: row["filter_key"]?.stringValue
                        ?: row["service_key"]?.stringValue
                    val action = row["action"]?.intValue
                    if (key != null && action != null) out[key] = action
                }
            } else {
                value.objectValue?.forEach { (key, v) ->
                    (v.intValue ?: v["action"]?.intValue)?.let { out[key] = it }
                }
            }
            return out
        }
    }
}

/**
 * A custom domain rule (`sbr_scheduled_block_rules`). [hardBlock] marks it for
 * the strict-mode tunnel's connection-level enforcement.
 */
data class DnsDomainRule(
    val ruleId: Int,
    val hostname: String,
    /** 0 = block, 1 = allow. */
    val action: Int,
    val isActive: Boolean,
    val hardBlock: Boolean,
) {
    val isBlock: Boolean get() = action == 0

    companion object {
        fun from(json: JsonValue?): DnsDomainRule? {
            val j = json ?: return null
            val ruleId = j["rule_id"]?.intValue ?: return null
            return DnsDomainRule(
                ruleId = ruleId,
                hostname = j["hostname"]?.stringValue ?: "",
                action = j["action"]?.intValue ?: 0,
                isActive = j["is_active"]?.boolValue ?: true,
                hardBlock = j["hard_block"]?.boolValue ?: false,
            )
        }
    }
}

// MARK: - account_summary

/**
 * The five ScrollDaddy feature flags plus device count vs. limit. The client
 * renders locked/upsell states from these; the server rejects gated writes
 * regardless (tier gating is server-enforced).
 */
data class DnsAccountSummary(
    val tierName: String?,
    val maxDevices: Int,
    val maxScheduledBlocks: Int,
    val customRules: Boolean,
    val advancedFilters: Boolean,
    val queryLogging: Boolean,
    val deviceCount: Int,
    val deviceMax: Int,
) {
    val atDeviceLimit: Boolean get() = deviceCount >= deviceMax

    companion object {
        fun from(data: JsonValue?): DnsAccountSummary? {
            if (data == null) return null
            val features = data["features"]
            val maxDevices = features?.get("scrolldaddy_max_devices")?.intValue ?: 1
            return DnsAccountSummary(
                tierName = data["tier_name"]?.stringValue,
                maxDevices = maxDevices,
                maxScheduledBlocks = features?.get("scrolldaddy_max_scheduled_blocks")?.intValue ?: 0,
                customRules = features?.get("scrolldaddy_custom_rules")?.boolValue ?: false,
                advancedFilters = features?.get("scrolldaddy_advanced_filters")?.boolValue ?: false,
                queryLogging = features?.get("scrolldaddy_query_logging")?.boolValue ?: false,
                deviceCount = data["device_count"]?.intValue ?: 0,
                deviceMax = data["device_max"]?.intValue ?: maxDevices,
            )
        }
    }
}

// MARK: - catalog

/**
 * The filter/service catalog, static per deployment (cache client-side). The
 * `advanced` flag gates a filter behind `scrolldaddy_advanced_filters`.
 */
data class DnsCatalog(
    val filters: List<DnsCatalogFilter>,
    val serviceCategories: List<DnsCatalogServiceCategory>,
    /** category_key -> the services in that category. */
    val services: Map<String, List<DnsCatalogService>>,
) {
    /** General (ungated) filters — the free-tier floor, in server order. */
    val generalFilters: List<DnsCatalogFilter> get() = filters.filter { !it.advanced }
    val advancedFiltersList: List<DnsCatalogFilter> get() = filters.filter { it.advanced }

    companion object {
        fun from(data: JsonValue?): DnsCatalog? {
            if (data == null) return null
            val svc = LinkedHashMap<String, List<DnsCatalogService>>()
            data["services"]?.objectValue?.forEach { (key, value) ->
                svc[key] = (value.arrayValue ?: emptyList()).mapNotNull { DnsCatalogService.from(it) }
            }
            return DnsCatalog(
                filters = (data["filters"]?.arrayValue ?: emptyList()).mapNotNull { DnsCatalogFilter.from(it) },
                serviceCategories = (data["service_categories"]?.arrayValue ?: emptyList())
                    .mapNotNull { DnsCatalogServiceCategory.from(it) },
                services = svc,
            )
        }
    }
}

data class DnsCatalogFilter(val key: String, val label: String, val advanced: Boolean) {
    companion object {
        fun from(json: JsonValue?): DnsCatalogFilter? {
            val j = json ?: return null
            val key = j["key"]?.stringValue ?: return null
            return DnsCatalogFilter(key, j["label"]?.stringValue ?: key, j["advanced"]?.boolValue ?: false)
        }
    }
}

data class DnsCatalogServiceCategory(val key: String, val label: String) {
    companion object {
        fun from(json: JsonValue?): DnsCatalogServiceCategory? {
            val j = json ?: return null
            val key = j["key"]?.stringValue ?: return null
            return DnsCatalogServiceCategory(key, j["label"]?.stringValue ?: key)
        }
    }
}

data class DnsCatalogService(val key: String, val label: String) {
    companion object {
        fun from(json: JsonValue?): DnsCatalogService? {
            val j = json ?: return null
            val key = j["key"]?.stringValue ?: return null
            return DnsCatalogService(key, j["label"]?.stringValue ?: key)
        }
    }
}

// MARK: - protection mode

/**
 * The one "protection level" control the app presents. Standard and Strict are
 * a routing-scope switch inside one VpnService (unlike iOS's two mechanisms),
 * so the app owns the switch between them.
 */
enum class ProtectionMode(val slug: String) {
    /** DoH-forwarding tunnel that claims only DNS — policy stays server-side. */
    STANDARD("standard"),

    /** All-traffic tunnel that additionally drops connections by SNI/IP from the
     *  hard-block list. */
    STRICT("strict");

    val title: String
        get() = when (this) {
            STANDARD -> "Standard"
            STRICT -> "Strict"
        }

    val summary: String
        get() = when (this) {
            STANDARD ->
                "Encrypted DNS filtering. Blocks sites at lookup — minimal battery."
            STRICT ->
                "Adds connection-level hard-blocking for selected sites, even when an app brings its own DNS. Traffic never leaves your phone."
        }
}
