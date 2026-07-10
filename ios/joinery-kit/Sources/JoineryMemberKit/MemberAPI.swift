import Foundation
import JoineryKit

/// Thin typed face over the dashboard/orders/subscriptions/events read
/// actions plus the two reused mutations that back them
/// (`store/orders_recurring_action`, `event_manager/event_withdraw`). Every call rides the
/// app's session key through APIClient; scoping is entirely server-side —
/// same query paths as the web profile pages.
public struct MemberAPI: Sendable {
    let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func dashboard() async throws -> DashboardSummary {
        let envelope = try await client.submitAction("profile_dashboard", body: .object([]))
        guard let summary = DashboardSummary(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return summary
    }

    public func orders(offset: Int) async throws -> OrderPage {
        let envelope = try await client.submitAction("store/order_list", body: .object([
            (key: "offset", value: .number(Double(offset))),
        ]))
        guard let page = OrderPage(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return page
    }

    public func subscriptions() async throws -> SubscriptionSummaryPayload {
        let envelope = try await client.submitAction("store/subscription_summary", body: .object([]))
        guard let payload = SubscriptionSummaryPayload(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return payload
    }

    /// Cancel a subscription at period end. The server cancels unconditionally
    /// once called (there is no server-side confirm step) — the confirmation
    /// is the client's alert before this call, matching the web page's flow.
    public func cancelSubscription(orderItemID: Int) async throws {
        _ = try await client.submitAction("store/orders_recurring_action", body: .object([
            (key: "order_item_id", value: .number(Double(orderItemID))),
        ]))
    }

    public func events(status: EventStatusFilter, offset: Int) async throws -> EventPage {
        let envelope = try await client.submitAction("event_manager/my_events", body: .object([
            (key: "status", value: .string(status.rawValue)),
            (key: "offset", value: .number(Double(offset))),
        ]))
        guard let page = EventPage(data: envelope["data"]) else {
            throw JoineryAPIError.malformedResponse
        }
        return page
    }

    /// Withdraw from an event registration. `confirm: true` matches the web
    /// confirmation form's required field.
    public func withdraw(registrantID: Int) async throws {
        _ = try await client.submitAction("event_manager/event_withdraw", body: .object([
            (key: "evr_event_registrant_id", value: .number(Double(registrantID))),
            (key: "confirm", value: .bool(true)),
        ]))
    }
}
