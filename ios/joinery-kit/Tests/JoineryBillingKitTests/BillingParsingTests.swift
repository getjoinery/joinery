import XCTest
@testable import JoineryBillingKit
@testable import JoineryKit

/// Parsing tests over billing-surface action payloads (Fixtures/*.json are
/// verbatim API envelopes, same discipline as the other kits).
final class BillingParsingTests: XCTestCase {

    private func fixture(_ name: String) throws -> JSONValue {
        let url = try XCTUnwrap(
            Bundle.module.url(forResource: "Fixtures/\(name).json", withExtension: nil)
                ?? Bundle.module.url(forResource: "\(name).json", withExtension: nil, subdirectory: "Fixtures"),
            "missing fixture \(name).json"
        )
        let envelope = try JSONValue.parse(Data(contentsOf: url))
        return try XCTUnwrap(envelope["data"], "fixture \(name) has no data")
    }

    // MARK: billing_catalog

    func testCatalogParses() throws {
        let catalog = try XCTUnwrap(BillingCatalog(data: fixture("billing_catalog")))
        XCTAssertEqual(catalog.store, "app_store")
        XCTAssertEqual(catalog.plans.count, 2)
        XCTAssertEqual(catalog.plans.first?.storeProductID, "com.example.premium.monthly")
        XCTAssertEqual(catalog.plans.first?.period, "month")
        XCTAssertEqual(catalog.plans.first?.tier?.name, "Premium")
        XCTAssertEqual(catalog.plans.first?.tier?.level, 20)
        XCTAssertNil(catalog.activeSource)
        XCTAssertTrue(catalog.canPurchase)
        XCTAssertEqual(catalog.appAccountToken, "00000000-0000-4000-8000-00000000002a")
        XCTAssertNotNil(UUID(uuidString: catalog.appAccountToken), "app account token must be a valid UUID for StoreKit")
    }

    func testCatalogBlockedByOtherSource() throws {
        let catalog = try XCTUnwrap(BillingCatalog(data: fixture("billing_catalog_blocked")))
        XCTAssertEqual(catalog.activeSource, "stripe")
        XCTAssertFalse(catalog.canPurchase, "source exclusivity: existing stripe subscription blocks purchase")
        XCTAssertFalse(catalog.plans.isEmpty, "plans still parse so the screen can show them greyed out")
    }

    // MARK: app_store_claim

    func testClaimResultParses() throws {
        let claim = try XCTUnwrap(BillingClaimResult(data: fixture("app_store_claim")))
        XCTAssertEqual(claim.orderItemID, 512)
        XCTAssertEqual(claim.productName, "Premium Plan")
        XCTAssertEqual(claim.tier?.tierID, 3)
        XCTAssertEqual(claim.status, "active")
        XCTAssertEqual(claim.paymentSource, "app_store")
        XCTAssertEqual(claim.periodEnd, "2026-08-16 14:00:00")
    }

    // MARK: subscription_summary

    func testSummaryParses() throws {
        let summary = try XCTUnwrap(BillingSummary(data: fixture("subscription_summary_app_store")))
        XCTAssertEqual(summary.currentTierName, "Premium")
        XCTAssertEqual(summary.paymentSource, "app_store")
        XCTAssertEqual(summary.activeCount, 1)
        XCTAssertEqual(summary.status, "active")
        XCTAssertEqual(summary.renewalOrEndDate, "2026-08-16 14:00:00")
    }

    // MARK: display helpers

    func testOtherSourceMessages() {
        XCTAssertTrue(BillingScreen.otherSourceMessage("stripe").contains("website"))
        XCTAssertTrue(BillingScreen.otherSourceMessage("play_store").contains("Google Play"))
    }

    func testDateLabelFallsBackToRaw() {
        XCTAssertEqual(BillingDisplay.dateLabel("not-a-date"), "not-a-date")
        XCTAssertNotEqual(BillingDisplay.dateLabel("2026-08-16 14:00:00"), "2026-08-16 14:00:00")
    }
}
