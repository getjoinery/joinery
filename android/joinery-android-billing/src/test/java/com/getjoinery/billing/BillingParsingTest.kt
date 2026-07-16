package com.getjoinery.billing

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Parsing tests over billing-surface action payloads. The fixtures dir holds
 *  verbatim API envelopes — the same files backing the iOS JoineryBillingKit
 *  tests, parity by construction. */
class BillingParsingTest {

    private fun fixtureData(name: String): JsonValue {
        val stream = BillingParsingTest::class.java.classLoader!!
            .getResourceAsStream("fixtures/$name.json")
            ?: error("fixture not found: fixtures/$name.json")
        val envelope = JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
        return envelope["data"] ?: error("fixture $name has no data")
    }

    @Test
    fun catalogParses() {
        val catalog = BillingCatalog.from(fixtureData("billing_catalog"))!!
        assertEquals("app_store", catalog.store)
        assertEquals(2, catalog.plans.size)
        assertEquals("com.example.premium.monthly", catalog.plans[0].storeProductId)
        assertEquals("month", catalog.plans[0].period)
        assertEquals("Premium", catalog.plans[0].tier?.name)
        assertEquals(20, catalog.plans[0].tier?.level)
        assertNull(catalog.activeSource)
        assertTrue(catalog.canPurchase)
        assertEquals("00000000-0000-4000-8000-00000000002a", catalog.appAccountToken)
    }

    @Test
    fun catalogBlockedByOtherSource() {
        val catalog = BillingCatalog.from(fixtureData("billing_catalog_blocked"))!!
        assertEquals("stripe", catalog.activeSource)
        // Source exclusivity: an existing stripe subscription blocks purchase.
        assertFalse(catalog.canPurchase)
        assertFalse(catalog.plans.isEmpty())
    }

    @Test
    fun claimResultParses() {
        val claim = BillingClaimResult.from(fixtureData("play_claim"))!!
        assertEquals(640, claim.orderItemId)
        assertEquals("Premium Plan", claim.productName)
        assertEquals(3, claim.tier?.tierId)
        assertEquals("active", claim.status)
        assertEquals("play_store", claim.paymentSource)
        assertEquals("2026-08-16 14:00:00", claim.periodEnd)
    }

    @Test
    fun summaryParses() {
        val summary = BillingSummary.from(fixtureData("subscription_summary_app_store"))!!
        assertEquals("Premium", summary.currentTierName)
        assertEquals("app_store", summary.paymentSource)
        assertEquals(1, summary.activeCount)
        assertEquals("active", summary.status)
        assertNotNull(summary.renewalOrEndDate)
    }

    @Test
    fun otherSourceMessages() {
        assertTrue(otherSourceMessage("stripe").contains("website"))
        assertTrue(otherSourceMessage("app_store").contains("App Store"))
    }

    @Test
    fun claimResultRequiresOrderItemId() {
        assertNull(BillingClaimResult.from(JsonValue.obj("product_name" to JsonValue.Str("x"))))
    }
}
