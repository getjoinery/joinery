package com.getjoinery.memberkit

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Parsing tests over live member-surface action payloads captured from dev.
 *  The fixtures dir holds verbatim API envelopes — the same files backing the
 *  iOS JoineryMemberKit tests, parity by construction. */
class MemberParsingTest {

    private fun fixtureData(name: String): JsonValue {
        val stream = FixtureAnchor::class.java.classLoader!!
            .getResourceAsStream("fixtures/$name.json")
            ?: error("fixture not found: fixtures/$name.json")
        val envelope = JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
        return envelope["data"] ?: error("fixture $name has no data")
    }

    // MARK: profile_dashboard

    @Test
    fun dashboardFixtureParses() {
        val summary = DashboardSummary.from(fixtureData("profile_dashboard"))!!
        assertEquals("Fixture UserA", summary.userName)
        assertFalse(summary.userEmail.isEmpty())
        assertEquals(1, summary.upcomingEvents.size)
        assertEquals(1, summary.upcomingEventCount)
        assertEquals("Fixture Capture Event FIXE55676", summary.upcomingEvents.first().eventName)

        // Messaging is on in this fixture: gated keys are present.
        assertTrue(summary.messagingActive)
        assertEquals(1, summary.unreadConversationCount)
        assertEquals(1, summary.recentConversations?.size)
        assertEquals("Fixture UserB", summary.recentConversations?.first()?.otherDisplayName)

        // Products are on: recent orders present; subscriptions is an empty list
        // here (not omitted) — still "active" (the key exists) with zero rows.
        assertTrue(summary.productsActive)
        assertEquals(2, summary.recentOrders?.size)
        assertTrue(summary.subscriptionsActive)
        assertEquals(0, summary.recentSubscriptions?.size)
        assertEquals(0, summary.activeSubscriptionCount)

        assertTrue(summary.mailingLists.isEmpty())
        assertTrue(summary.pendingSurveys.isEmpty())
    }

    @Test
    fun dashboardOmitsGatedSectionsWhenAbsent() {
        // A deployment with messaging/products off never sends those keys.
        val json = JsonValue.parse(
            """
            {"user":{"name":"","email":"","avatar_url":"","address":""},
             "pending_surveys":[],"upcoming_events":[],"upcoming_event_count":0,
             "mailing_lists":[]}
            """.trimIndent(),
        )
        val summary = DashboardSummary.from(json)!!
        assertFalse(summary.messagingActive)
        assertNull(summary.unreadConversationCount)
        assertNull(summary.recentConversations)
        assertFalse(summary.productsActive)
        assertNull(summary.recentOrders)
        assertFalse(summary.subscriptionsActive)
        assertNull(summary.recentSubscriptions)
    }

    // MARK: order_list

    @Test
    fun orderListFixtureParses() {
        val page = OrderPage.from(fixtureData("order_list"))!!
        assertEquals(2, page.totalCount)
        assertEquals(0, page.offset)
        assertEquals(10, page.perPage)
        assertEquals(2, page.orders.size)
        val first = page.orders.first()
        assertEquals(6464, first.orderId)
        assertEquals("50.00", first.total)
        assertTrue(first.items.isEmpty())
    }

    @Test
    fun orderItemsParseWhenPresent() {
        val json = JsonValue.parse(
            """
            {"order_id":1,"number":1,"total":"10.00","date":"2026-01-01 00:00:00",
             "items":[{"product_name":"Widget","price":"10.00"}]}
            """.trimIndent(),
        )
        val order = OrderSummary.from(json)!!
        assertEquals(1, order.items.size)
        assertEquals("Widget", order.items.first().productName)
    }

    // MARK: subscription_summary

    @Test
    fun subscriptionSummaryFixtureParses() {
        val payload = SubscriptionSummaryPayload.from(fixtureData("subscription_summary"))!!
        assertTrue(payload.activeSubscriptions.isEmpty())
        assertTrue(payload.cancelledSubscriptions.isEmpty())
        assertNull(payload.currentTier)
        assertEquals("none", payload.paymentSource)
    }

    @Test
    fun subscriptionRowParsesCancelAffordance() {
        val json = JsonValue.parse(
            """
            {"order_item_id":9,"product_name":"Pro Plan","period":"month","price":"20.00",
             "status":"active","renewal_or_end_date":"2026-08-01 00:00:00","can_cancel":true,
             "payment_source":"stripe"}
            """.trimIndent(),
        )
        val row = SubscriptionRow.from(json)!!
        assertTrue(row.canCancel)
        assertEquals("stripe", row.paymentSource)
    }

    // MARK: my_events

    @Test
    fun myEventsFixtureParses() {
        val page = EventPage.from(fixtureData("my_events"))!!
        assertEquals(1, page.totalCount)
        assertEquals(10, page.perPage)
        assertEquals("all", page.statusFilter)
        val reg = page.registrations.first()
        assertEquals("Fixture Capture Event FIXE55676", reg.eventName)
        assertEquals("active", reg.status)
        assertEquals("/profile/event_sessions?evt_event_id=104", reg.webUrl)
    }

    // MARK: conversation_list

    @Test
    fun conversationListFixtureParses() {
        val page = ConversationPage.from(fixtureData("conversation_list"))!!
        assertEquals(1, page.totalCount)
        assertEquals(20, page.perPage)
        val row = page.conversations.first()
        assertEquals("Fixture UserB", row.otherDisplayName)
        assertTrue(row.unread)
        assertFalse(row.muted)
    }

    // MARK: conversation_thread

    @Test
    fun conversationThreadFixtureParses() {
        val payload = ThreadPayload.from(fixtureData("conversation_thread"))!!
        assertFalse(payload.isComposeMode)
        assertEquals(5, payload.conversationId)
        assertEquals("Fixture UserB", payload.otherDisplayName)
        assertFalse(payload.isMuted)
        assertFalse(payload.hasMore)
        assertEquals(2, payload.messages.size)
        assertTrue(payload.messages[0].isMine)
        assertFalse(payload.messages[1].isMine)
    }

    @Test
    fun composeModeParsesWithoutMessages() {
        val json = JsonValue.parse(
            """
            {"is_compose_mode":true,"conversation_id":null,"other_display_name":"New Person",
             "other_user_id":42,"messages":[],"has_more":false}
            """.trimIndent(),
        )
        val payload = ThreadPayload.from(json)!!
        assertTrue(payload.isComposeMode)
        assertNull(payload.conversationId)
        assertTrue(payload.messages.isEmpty())
    }

    // MARK: security_overview

    @Test
    fun securityOverviewFixtureParses() {
        val overview = SecurityOverview.from(fixtureData("security_overview"))!!
        assertFalse(overview.totpEnabled)
        assertEquals(0, overview.backupCodesRemaining)
        assertEquals(1, overview.appSessions.size)
        assertTrue(overview.appSessions.first().isCurrent)
        assertEquals(0, overview.passkeyCount)
        assertFalse(overview.vaultActive)
    }

    @Test
    fun totpSetupStateParsesProvisioningUri() {
        val json = JsonValue.parse(
            """
            {"totp_enabled":false,"setup_in_progress":true,
             "provisioning_uri":"otpauth://totp/Joinery:user%40example.com?secret=ABC&issuer=Joinery",
             "just_enabled":false}
            """.trimIndent(),
        )
        val state = TotpSetupState.from(json)!!
        assertTrue(state.setupInProgress)
        assertFalse(state.justEnabled)
        assertEquals("otpauth://totp/Joinery:user%40example.com?secret=ABC&issuer=Joinery", state.provisioningUri)
    }

    @Test
    fun totpSetupStateParsesBackupCodesOnEnable() {
        val json = JsonValue.parse(
            """
            {"totp_enabled":true,"just_enabled":true,"backup_codes":["AAAA1111","BBBB2222"]}
            """.trimIndent(),
        )
        val state = TotpSetupState.from(json)!!
        assertTrue(state.justEnabled)
        assertEquals(listOf("AAAA1111", "BBBB2222"), state.backupCodes)
    }

    // MARK: display helpers

    @Test
    fun displayHelpersFormatUtcTimes() {
        assertNotNull(MemberDisplay.date("2026-07-07 15:50:57"))
        // Fractional-second suffixes parse via the 19-char prefix.
        assertNotNull(MemberDisplay.date("2026-07-07 15:50:57.791146"))
        assertNull(MemberDisplay.date("garbage"))
        assertEquals("", MemberDisplay.dateLabel(null))
        assertTrue(MemberDisplay.dateLabel("2026-07-07 15:50:57").isNotEmpty())
        assertTrue(MemberDisplay.listStamp("2026-07-07 15:50:57").isNotEmpty())
    }
}

private class FixtureAnchor
