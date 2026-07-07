import XCTest
@testable import JoineryMemberKit
@testable import JoineryKit

/// Parsing tests over live member-surface action payloads captured from dev
/// (Fixtures/*.json are verbatim API envelopes, same discipline as the
/// other three kits).
final class MemberParsingTests: XCTestCase {

    private func fixture(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json"
        )
        let envelope = try JSONValue.parse(Data(contentsOf: url))
        return try XCTUnwrap(envelope["data"], "fixture \(name) has no data")
    }

    // MARK: profile_dashboard

    func testDashboardFixtureParses() throws {
        let summary = try XCTUnwrap(DashboardSummary(data: fixture("profile_dashboard")))
        XCTAssertEqual(summary.userName, "Fixture UserA")
        XCTAssertFalse(summary.userEmail.isEmpty)
        XCTAssertEqual(summary.upcomingEvents.count, 1)
        XCTAssertEqual(summary.upcomingEventCount, 1)
        XCTAssertEqual(summary.upcomingEvents.first?.eventName, "Fixture Capture Event FIXE55676")

        // Messaging is on in this fixture: gated keys are present.
        XCTAssertTrue(summary.messagingActive)
        XCTAssertEqual(summary.unreadConversationCount, 1)
        XCTAssertEqual(summary.recentConversations?.count, 1)
        XCTAssertEqual(summary.recentConversations?.first?.otherDisplayName, "Fixture UserB")

        // Products are on: recent orders present, but subscriptions is an
        // empty list here (not omitted) — still counts as "active" (the key
        // exists) with zero rows.
        XCTAssertTrue(summary.productsActive)
        XCTAssertEqual(summary.recentOrders?.count, 2)
        XCTAssertTrue(summary.subscriptionsActive)
        XCTAssertEqual(summary.recentSubscriptions?.count, 0)
        XCTAssertEqual(summary.activeSubscriptionCount, 0)

        XCTAssertTrue(summary.mailingLists.isEmpty)
        XCTAssertTrue(summary.pendingSurveys.isEmpty)
    }

    func testDashboardOmitsGatedSectionsWhenAbsent() throws {
        // A deployment with messaging/products off never sends those keys —
        // simulate that by parsing a payload with only the always-present keys.
        let json = try JSONValue.parse("""
        {"user":{"name":"","email":"","avatar_url":"","address":""},
         "pending_surveys":[],"upcoming_events":[],"upcoming_event_count":0,
         "mailing_lists":[]}
        """)
        let summary = try XCTUnwrap(DashboardSummary(data: json))
        XCTAssertFalse(summary.messagingActive)
        XCTAssertNil(summary.unreadConversationCount)
        XCTAssertNil(summary.recentConversations)
        XCTAssertFalse(summary.productsActive)
        XCTAssertNil(summary.recentOrders)
        XCTAssertFalse(summary.subscriptionsActive)
        XCTAssertNil(summary.recentSubscriptions)
    }

    // MARK: order_list

    func testOrderListFixtureParses() throws {
        let page = try XCTUnwrap(OrderPage(data: fixture("order_list")))
        XCTAssertEqual(page.totalCount, 2)
        XCTAssertEqual(page.offset, 0)
        XCTAssertEqual(page.perPage, 10)
        XCTAssertEqual(page.orders.count, 2)
        let first = try XCTUnwrap(page.orders.first)
        XCTAssertEqual(first.orderID, 6464)
        XCTAssertEqual(first.total, "50.00")
        // Fixture items arrays are empty, but the type must parse regardless
        // of whether a real order has line items.
        XCTAssertTrue(first.items.isEmpty)
    }

    func testOrderItemsParseWhenPresent() throws {
        let json = try JSONValue.parse("""
        {"order_id":1,"number":1,"total":"10.00","date":"2026-01-01 00:00:00",
         "items":[{"product_name":"Widget","price":"10.00"}]}
        """)
        let order = try XCTUnwrap(OrderSummary(json: json))
        XCTAssertEqual(order.items.count, 1)
        XCTAssertEqual(order.items.first?.productName, "Widget")
    }

    // MARK: subscription_summary

    func testSubscriptionSummaryFixtureParses() throws {
        let payload = try XCTUnwrap(SubscriptionSummaryPayload(data: fixture("subscription_summary")))
        XCTAssertTrue(payload.activeSubscriptions.isEmpty)
        XCTAssertTrue(payload.cancelledSubscriptions.isEmpty)
        XCTAssertNil(payload.currentTier)
        XCTAssertEqual(payload.paymentSource, "none")
    }

    func testSubscriptionRowParsesCancelAffordance() throws {
        let json = try JSONValue.parse("""
        {"order_item_id":9,"product_name":"Pro Plan","period":"month","price":"20.00",
         "status":"active","renewal_or_end_date":"2026-08-01 00:00:00","can_cancel":true,
         "payment_source":"stripe"}
        """)
        let row = try XCTUnwrap(SubscriptionRow(json: json))
        XCTAssertTrue(row.canCancel)
        XCTAssertEqual(row.paymentSource, "stripe")
    }

    // MARK: my_events

    func testMyEventsFixtureParses() throws {
        let page = try XCTUnwrap(EventPage(data: fixture("my_events")))
        XCTAssertEqual(page.totalCount, 1)
        XCTAssertEqual(page.perPage, 10)
        XCTAssertEqual(page.statusFilter, "all")
        let reg = try XCTUnwrap(page.registrations.first)
        XCTAssertEqual(reg.eventName, "Fixture Capture Event FIXE55676")
        XCTAssertEqual(reg.status, "active")
        XCTAssertEqual(reg.webURL, "/profile/event_sessions?evt_event_id=104")
    }

    // MARK: conversation_list

    func testConversationListFixtureParses() throws {
        let page = try XCTUnwrap(ConversationPage(data: fixture("conversation_list")))
        XCTAssertEqual(page.totalCount, 1)
        XCTAssertEqual(page.perPage, 20)
        let row = try XCTUnwrap(page.conversations.first)
        XCTAssertEqual(row.otherDisplayName, "Fixture UserB")
        XCTAssertTrue(row.unread)
        XCTAssertFalse(row.muted)
    }

    // MARK: conversation_thread

    func testConversationThreadFixtureParses() throws {
        let payload = try XCTUnwrap(ThreadPayload(data: fixture("conversation_thread")))
        XCTAssertFalse(payload.isComposeMode)
        XCTAssertEqual(payload.conversationID, 5)
        XCTAssertEqual(payload.otherDisplayName, "Fixture UserB")
        XCTAssertFalse(payload.isMuted)
        XCTAssertFalse(payload.hasMore)
        XCTAssertEqual(payload.messages.count, 2)
        XCTAssertTrue(payload.messages[0].isMine)
        XCTAssertFalse(payload.messages[1].isMine)
    }

    func testComposeModeParsesWithoutMessages() throws {
        let json = try JSONValue.parse("""
        {"is_compose_mode":true,"conversation_id":null,"other_display_name":"New Person",
         "other_user_id":42,"messages":[],"has_more":false}
        """)
        let payload = try XCTUnwrap(ThreadPayload(data: json))
        XCTAssertTrue(payload.isComposeMode)
        XCTAssertNil(payload.conversationID)
        XCTAssertTrue(payload.messages.isEmpty)
    }

    // MARK: security_overview

    func testSecurityOverviewFixtureParses() throws {
        let overview = try XCTUnwrap(SecurityOverview(data: fixture("security_overview")))
        XCTAssertFalse(overview.totpEnabled)
        XCTAssertEqual(overview.backupCodesRemaining, 0)
        XCTAssertEqual(overview.appSessions.count, 1)
        XCTAssertTrue(overview.appSessions.first?.isCurrent ?? false)
        XCTAssertEqual(overview.passkeyCount, 0)
        XCTAssertFalse(overview.vaultActive)
    }

    func testTOTPSetupStateParsesProvisioningURI() throws {
        let json = try JSONValue.parse("""
        {"totp_enabled":false,"setup_in_progress":true,
         "provisioning_uri":"otpauth://totp/Joinery:user%40example.com?secret=ABC&issuer=Joinery",
         "just_enabled":false}
        """)
        let state = try XCTUnwrap(TOTPSetupState(data: json))
        XCTAssertTrue(state.setupInProgress)
        XCTAssertFalse(state.justEnabled)
        XCTAssertEqual(state.provisioningURI, "otpauth://totp/Joinery:user%40example.com?secret=ABC&issuer=Joinery")
    }

    func testTOTPSetupStateParsesBackupCodesOnEnable() throws {
        let json = try JSONValue.parse("""
        {"totp_enabled":true,"just_enabled":true,"backup_codes":["AAAA1111","BBBB2222"]}
        """)
        let state = try XCTUnwrap(TOTPSetupState(data: json))
        XCTAssertTrue(state.justEnabled)
        XCTAssertEqual(state.backupCodes, ["AAAA1111", "BBBB2222"])
    }
}
