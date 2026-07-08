package com.getjoinery.dnsfilter

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Parsing tests against the exact JSON shapes ScrollDaddyHelper emits
 * (exportDevice / exportBlock, account_summary, catalog). Ported from the iOS
 * DNSFilterModelParsingTests, with the extra cases guardrail 8 calls out — the
 * `last_seen` object/string/null variants and the `[]`-empty-map quirk — since
 * those are exactly the fields that drifted on iOS.
 */
class DnsFilterModelParsingTest {

    private fun json(text: String): JsonValue = JsonValue.parse(text)

    @Test fun deviceParsing() {
        val data = json(
            """
            {"device_id":42,"name":"My Phone","device_name":"My Phone","device_type":"phone",
             "timezone":"America/New_York","is_active":true,"log_queries":false,"filters_editable":true,
             "resolver_uid":"abc123","doh_url":"https://dns.scrolldaddy.app/resolve/abc123",
             "dot_hostname":"abc123.dns.scrolldaddy.app","hard_block_hostnames":["porn.example","bet.example"],
             "last_seen":{"seen":"2026-07-08 12:00:00"},
             "blocks":[{"block_id":7,"name":"Always-On Rules","is_always_on":true,"is_active":true,
                        "active_now":true,"rule_count":3,"schedule":{"start":null,"end":null,"days":[],"timezone":null}},
                       {"block_id":8,"name":"Bedtime","is_always_on":false,"is_active":true,"active_now":false,
                        "rule_count":1,"schedule":{"start":"22:00","end":"06:00","days":[1,2,3],"timezone":"America/New_York"}}]}
            """.trimIndent(),
        )
        val device = DnsDevice.from(data)
        assertNotNull(device)
        assertEquals(42, device?.deviceId)
        assertEquals("https://dns.scrolldaddy.app/resolve/abc123", device?.dohUrl)
        assertEquals(listOf("porn.example", "bet.example"), device?.hardBlockHostnames)
        // last_seen is an OBJECT on the real server — the seen timestamp is folded out.
        assertEquals("2026-07-08 12:00:00", device?.lastSeen)
        assertEquals(7, device?.alwaysOnBlock?.blockId)
        assertEquals(1, device?.scheduledBlocks?.size)
        assertEquals(listOf(1, 2, 3), device?.scheduledBlocks?.first()?.schedule?.days)
    }

    @Test fun deviceLastSeenNullAndStringVariants() {
        val nullSeen = DnsDevice.from(json("""{"device_id":1,"last_seen":null,"blocks":[]}"""))
        assertNull(nullSeen?.lastSeen)
        // Empty PHP array proxied for last_seen also reads as "not seen".
        val emptySeen = DnsDevice.from(json("""{"device_id":1,"last_seen":[],"blocks":[]}"""))
        assertNull(emptySeen?.lastSeen)
        // A bare string is tolerated (fixture form).
        val strSeen = DnsDevice.from(json("""{"device_id":1,"last_seen":"2026-07-08 12:00:00","blocks":[]}"""))
        assertEquals("2026-07-08 12:00:00", strSeen?.lastSeen)
    }

    @Test fun blockContentsFiltersAsMap() {
        val data = json(
            """
            {"device_id":42,"block":{"block_id":7,"name":"Always-On Rules","is_always_on":true,
              "is_active":true,"active_now":true,"schedule":{"start":null,"end":null,"days":[],"timezone":null},
              "filters":{"gambling":0,"adult":0,"safesearch":1},
              "services":{"reddit":0},
              "rules":[{"rule_id":5,"hostname":"youtube.com","action":1,"is_active":true,"hard_block":false},
                       {"rule_id":6,"hostname":"casino.example","action":0,"is_active":true,"hard_block":true}]}}
            """.trimIndent(),
        )
        val contents = DnsBlockContents.from(data)
        assertNotNull(contents)
        assertEquals(7, contents?.blockId)
        assertTrue(contents?.isAlwaysOn ?: false)
        assertEquals(0, contents?.filters?.get("gambling"))
        assertEquals(1, contents?.filters?.get("safesearch"))
        assertEquals(0, contents?.services?.get("reddit"))
        assertEquals(2, contents?.rules?.size)
        assertEquals(true, contents?.rules?.first { it.ruleId == 6 }?.hardBlock)
    }

    @Test fun blockContentsEmptyFiltersAsArray() {
        // An empty PHP assoc array serializes as [], not {} — must still parse.
        val data = json(
            """
            {"device_id":42,"block":{"block_id":9,"name":"Always-On Rules","is_always_on":true,
              "is_active":true,"active_now":true,"schedule":{"start":null,"end":null,"days":[],"timezone":null},
              "filters":[],"services":[],"rules":[]}}
            """.trimIndent(),
        )
        val contents = DnsBlockContents.from(data)
        assertNotNull(contents)
        assertTrue(contents?.filters?.isEmpty() ?: false)
        assertTrue(contents?.rules?.isEmpty() ?: false)
    }

    @Test fun accountSummaryFlags() {
        val data = json(
            """
            {"tier_name":"Premium","features":{"scrolldaddy_max_devices":3,"scrolldaddy_max_scheduled_blocks":2,
              "scrolldaddy_custom_rules":true,"scrolldaddy_advanced_filters":true,"scrolldaddy_query_logging":false},
             "device_count":2,"device_max":3}
            """.trimIndent(),
        )
        val account = DnsAccountSummary.from(data)
        assertEquals("Premium", account?.tierName)
        assertEquals(3, account?.maxDevices)
        assertTrue(account?.customRules ?: false)
        assertTrue(account?.advancedFilters ?: false)
        assertFalse(account?.atDeviceLimit ?: true)
    }

    @Test fun accountSummaryFreeTierAtLimit() {
        val data = json(
            """
            {"tier_name":"Basic","features":{"scrolldaddy_max_devices":1,"scrolldaddy_max_scheduled_blocks":0,
              "scrolldaddy_custom_rules":false,"scrolldaddy_advanced_filters":false,"scrolldaddy_query_logging":false},
             "device_count":1,"device_max":1}
            """.trimIndent(),
        )
        val account = DnsAccountSummary.from(data)
        assertFalse(account?.customRules ?: true)
        assertTrue(account?.atDeviceLimit ?: false)
    }

    @Test fun catalogParsingAndAdvancedSplit() {
        val data = json(
            """
            {"filters":[{"key":"adult","label":"Adult Content","advanced":false},
                        {"key":"gambling","label":"Gambling","advanced":false},
                        {"key":"malware","label":"Malware","advanced":true}],
             "service_categories":[{"key":"social","label":"Social Media"}],
             "services":{"social":[{"key":"reddit","label":"Reddit"},{"key":"tiktok","label":"TikTok"}]}}
            """.trimIndent(),
        )
        val catalog = DnsCatalog.from(data)
        assertNotNull(catalog)
        assertEquals(listOf("adult", "gambling"), catalog?.generalFilters?.map { it.key })
        assertEquals(listOf("malware"), catalog?.advancedFiltersList?.map { it.key })
        assertEquals(2, catalog?.services?.get("social")?.size)
    }

    @Test fun createDeviceResponseParsesDirectly() {
        // The device_edit API-create contract returns the new device as `data`.
        val data = json(
            """
            {"device_id":99,"name":"My Phone","device_name":"user5-My Phone","device_type":"phone",
             "timezone":"UTC","is_active":true,"resolver_uid":"newuid",
             "doh_url":"https://dns.scrolldaddy.app/resolve/newuid","dot_hostname":"newuid.dns.scrolldaddy.app",
             "hard_block_hostnames":[]}
            """.trimIndent(),
        )
        val device = DnsDevice.from(data)
        assertEquals(99, device?.deviceId)
        assertEquals("https://dns.scrolldaddy.app/resolve/newuid", device?.dohUrl)
        assertTrue(device?.hardBlockHostnames?.isEmpty() ?: false)
    }
}
