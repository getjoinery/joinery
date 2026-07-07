package com.getjoinery.memberkit

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/**
 * Thin typed face over the dashboard/orders/subscriptions/events read actions
 * plus the two reused mutations that back them (`orders_recurring_action`,
 * `event_withdraw`). Every call rides the app's session key through ApiClient;
 * scoping is entirely server-side — same query paths as the web profile pages.
 */
// `open` so a test can subclass and control call timing for the stale-load
// race test; production always uses the concrete methods below.
open class MemberApi(val client: ApiClient) {

    suspend fun dashboard(): DashboardSummary {
        val envelope = client.submitAction("profile_dashboard", JsonValue.Obj(emptyList()))
        return DashboardSummary.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun orders(offset: Int): OrderPage {
        val envelope = client.submitAction(
            "order_list",
            JsonValue.obj("offset" to JsonValue.Num(offset.toDouble())),
        )
        return OrderPage.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun subscriptions(): SubscriptionSummaryPayload {
        val envelope = client.submitAction("subscription_summary", JsonValue.Obj(emptyList()))
        return SubscriptionSummaryPayload.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Cancel a subscription at period end. The server cancels unconditionally
     *  once called (no server-side confirm step) — the confirmation is the
     *  client's dialog before this call, matching the web page's flow. */
    suspend fun cancelSubscription(orderItemId: Int) {
        client.submitAction(
            "orders_recurring_action",
            JsonValue.obj("order_item_id" to JsonValue.Num(orderItemId.toDouble())),
        )
    }

    open suspend fun events(status: EventStatusFilter, offset: Int): EventPage {
        val envelope = client.submitAction(
            "my_events",
            JsonValue.obj(
                "status" to JsonValue.Str(status.slug),
                "offset" to JsonValue.Num(offset.toDouble()),
            ),
        )
        return EventPage.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** Withdraw from an event registration. `confirm: true` matches the web
     *  confirmation form's required field. */
    suspend fun withdraw(registrantId: Int) {
        client.submitAction(
            "event_withdraw",
            JsonValue.obj(
                "evr_event_registrant_id" to JsonValue.Num(registrantId.toDouble()),
                "confirm" to JsonValue.Bool(true),
            ),
        )
    }
}
