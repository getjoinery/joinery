import Foundation
import JoineryKit

/// A subscription tier as the catalog reports it.
public struct BillingTier: Equatable, Sendable {
    public let tierID: Int
    public let name: String
    public let level: Int

    init?(json: JSONValue?) {
        guard let json, !json.isNull, let tierID = json["tier_id"]?.intValue else { return nil }
        self.tierID = tierID
        name = json["name"]?.stringValue ?? ""
        level = json["level"]?.intValue ?? 0
    }
}

/// One purchasable plan: a store product ID mapped server-side to a product
/// and tier. Localized pricing comes from StoreKit, not this payload.
public struct BillingPlan: Identifiable, Equatable, Sendable {
    public let storeProductID: String
    public let productName: String
    public let period: String
    public let tier: BillingTier?

    public var id: String { storeProductID }

    init?(json: JSONValue) {
        guard let storeProductID = json["store_product_id"]?.stringValue, !storeProductID.isEmpty else { return nil }
        self.storeProductID = storeProductID
        productName = json["product_name"]?.stringValue ?? ""
        period = json["period"]?.stringValue ?? ""
        tier = BillingTier(json: json["tier"])
    }
}

/// The `store/billing_catalog` payload.
public struct BillingCatalog: Equatable, Sendable {
    public let store: String
    public let plans: [BillingPlan]
    /// stripe | paypal | app_store | play_store — nil when no active subscription.
    public let activeSource: String?
    /// False when the user's active subscription is billed elsewhere
    /// (source exclusivity) — the screen shows the existing source instead
    /// of purchase buttons.
    public let canPurchase: Bool
    /// Deterministic account token the kit passes as `appAccountToken` on
    /// purchase, so store notifications can be linked back to the user.
    public let appAccountToken: String

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        store = data["store"]?.stringValue ?? ""
        plans = (data["products"]?.arrayValue ?? []).compactMap(BillingPlan.init(json:))
        let source = data["active_source"]?.stringValue
        activeSource = (source?.isEmpty == false) ? source : nil
        canPurchase = data["can_purchase"]?.boolValue ?? false
        appAccountToken = data["app_account_token"]?.stringValue ?? ""
    }
}

/// The `store/app_store_claim` result: the server's record of the granted
/// subscription.
public struct BillingClaimResult: Equatable, Sendable {
    public let orderItemID: Int
    public let productName: String
    public let tier: BillingTier?
    public let status: String
    public let periodEnd: String?
    public let paymentSource: String

    public init?(data: JSONValue?) {
        guard let data, let orderItemID = data["order_item_id"]?.intValue else { return nil }
        self.orderItemID = orderItemID
        productName = data["product_name"]?.stringValue ?? ""
        tier = BillingTier(json: data["tier"])
        status = data["status"]?.stringValue ?? ""
        periodEnd = data["period_end"]?.stringValue
        paymentSource = data["payment_source"]?.stringValue ?? ""
    }
}

/// The slice of `store/subscription_summary` the billing screen shows: the
/// server-authoritative current plan and source.
public struct BillingSummary: Equatable, Sendable {
    public let currentTierName: String?
    /// stripe | paypal | app_store | play_store | none
    public let paymentSource: String
    public let activeCount: Int
    public let renewalOrEndDate: String?
    public let status: String?

    public init?(data: JSONValue?) {
        guard let data else { return nil }
        currentTierName = data["current_tier"]?["name"]?.stringValue
        paymentSource = data["payment_source"]?.stringValue ?? "none"
        let active = data["active_subscriptions"]?.arrayValue ?? []
        activeCount = active.count
        renewalOrEndDate = active.first?["renewal_or_end_date"]?.stringValue
        status = active.first?["status"]?.stringValue
    }
}
