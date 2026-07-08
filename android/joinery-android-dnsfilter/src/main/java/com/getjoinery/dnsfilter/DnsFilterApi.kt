package com.getjoinery.dnsfilter

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/**
 * Thin typed face over the `dns_filtering/` action namespace
 * (`POST /api/v1/action/dns_filtering/{action}`). Every call rides the app's
 * session key through ApiClient; tier gating, ownership, and save semantics are
 * entirely server-side — the same logic functions the web editor calls — so
 * this layer holds no policy, only shapes.
 */
// `open` so a store test can subclass and control timing; production always
// uses the concrete methods below.
open class DnsFilterApi(val client: ApiClient) {

    private fun ns(name: String): String = "dns_filtering/$name"

    private fun emptyBody(): JsonValue = JsonValue.Obj(emptyList())

    // MARK: Reads

    /** List the user's devices with DoH/DoT endpoints, per-block summaries, and
     *  the merged hard-block hostname list. */
    open suspend fun devices(): List<DnsDevice> {
        val envelope = client.submitAction(ns("devices"), emptyBody())
        return (envelope["data"]?.get("devices")?.arrayValue ?: emptyList())
            .mapNotNull { DnsDevice.from(it) }
    }

    /** Tier name, the five feature flags, and device count vs. limit. */
    suspend fun accountSummary(): DnsAccountSummary {
        val envelope = client.submitAction(ns("account_summary"), emptyBody())
        return DnsAccountSummary.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** The filter/service catalog — static per deployment, cache client-side. */
    suspend fun catalog(): DnsCatalog {
        val envelope = client.submitAction(ns("catalog"), emptyBody())
        return DnsCatalog.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Read one block's full contents (`scheduled_block_edit` with no `action`
     *  key). [deviceId] is required; [blockId] selects the always-on or a
     *  specific scheduled block. */
    suspend fun blockContents(deviceId: Int, blockId: Int): DnsBlockContents {
        val envelope = client.submitAction(
            ns("scheduled_block_edit"),
            JsonValue.obj(
                "device_id" to JsonValue.Num(deviceId.toDouble()),
                "block_id" to JsonValue.Num(blockId.toDouble()),
            ),
        )
        return DnsBlockContents.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    // MARK: Category / service toggles (save-on-change)

    /** Set or clear one category filter on a block. `action` null == Allow,
     *  which the server writes as "no row" (the resolver-merge invariant —
     *  "Allow means no row"). Blocking writes an `action=0` row. */
    suspend fun setFilter(blockId: Int, key: String, action: Int?) =
        setRule(blockId, "filter", key, action)

    /** Set or clear one service toggle on a block. */
    suspend fun setService(blockId: Int, key: String, action: Int?) =
        setRule(blockId, "service", key, action)

    private suspend fun setRule(blockId: Int, type: String, key: String, action: Int?) {
        client.submitAction(
            ns("block_filter_set"),
            JsonValue.obj(
                "block_id" to JsonValue.Num(blockId.toDouble()),
                "type" to JsonValue.Str(type),
                "key" to JsonValue.Str(key),
                // Empty string removes the row; "0" blocks, "1" allows.
                "action" to JsonValue.Str(action?.toString() ?: ""),
            ),
        )
    }

    // MARK: Custom domain rules

    /** Add a custom domain rule. [hardBlock] (block-action + always-on block
     *  only, server-enforced) marks it for the strict-mode tunnel. Rides the
     *  `scrolldaddy_custom_rules` gate. */
    suspend fun addDomainRule(blockId: Int, hostname: String, action: Int, hardBlock: Boolean = false): DnsDomainRule {
        val envelope = client.submitAction(
            ns("block_rule_add"),
            JsonValue.obj(
                "block_id" to JsonValue.Num(blockId.toDouble()),
                "hostname" to JsonValue.Str(hostname),
                "action" to JsonValue.Num(action.toDouble()),
                "hard_block" to JsonValue.Bool(hardBlock),
            ),
        )
        return DnsDomainRule.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun deleteDomainRule(ruleId: Int) {
        client.submitAction(
            ns("block_rule_delete"),
            JsonValue.obj("rule_id" to JsonValue.Num(ruleId.toDouble())),
        )
    }

    // MARK: Devices

    /**
     * Register a device (omit `device_id`). The create path is keyed on
     * `device_name` and reads `device_type` / `sdd_timezone` /
     * `sdd_allow_device_edits` (device_edit_logic + SdDevice::createDevice).
     * In API context the server returns the new device directly (device_edit
     * server contract), so this parses and returns it; a null return means the
     * response wasn't a device (e.g. a server without the API-create contract),
     * and the caller falls back to a before/after device-list diff.
     */
    open suspend fun createDevice(name: String, deviceType: String, timezone: String): DnsDevice? {
        val envelope = client.submitAction(
            ns("device_edit"),
            JsonValue.obj(
                "device_name" to JsonValue.Str(name),
                "device_type" to JsonValue.Str(deviceType),
                "sdd_timezone" to JsonValue.Str(timezone),
                // The create path reads this unguarded; the app always allows edits.
                "sdd_allow_device_edits" to JsonValue.Str("1"),
            ),
        )
        return DnsDevice.from(envelope["data"])
    }
}
