import Foundation
import JoineryKit

/// Server calls for the billing surface. All ride the device session-key
/// convention (`POST /api/v1/action/{action}`), same shape as
/// `store/orders_recurring_action`.
public struct BillingAPI: Sendable {
    private let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    /// The purchasable plans for this store, the caller's active billing
    /// source, and the account token to attach to purchases.
    public func catalog() async throws -> BillingCatalog {
        let envelope = try await client.submitAction("store/billing_catalog", body: .object([
            (key: "store", value: .string("app_store")),
        ]))
        guard let catalog = BillingCatalog(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return catalog
    }

    /// Post a StoreKit 2 signed transaction for server-side validation and
    /// tier grant. Idempotent — safe to repeat on restore.
    public func claim(jws: String) async throws -> BillingClaimResult {
        let envelope = try await client.submitAction("store/app_store_claim", body: .object([
            (key: "jws", value: .string(jws)),
        ]))
        guard let result = BillingClaimResult(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return result
    }

    /// The server's view of the user's subscriptions — the screen's status
    /// section reflects this, not StoreKit's local state.
    public func summary() async throws -> BillingSummary {
        let envelope = try await client.submitAction("store/subscription_summary", body: .object([]))
        guard let summary = BillingSummary(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return summary
    }
}
