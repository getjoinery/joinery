package com.getjoinery.billing

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/**
 * Server calls for the billing surface. All ride the device session-key
 * convention (POST /api/v1/action/{action}), same shape as
 * store/orders_recurring_action.
 */
class BillingApi(private val client: ApiClient) {

    /**
     * The purchasable plans for this store, the caller's active billing
     * source, and the account token to attach to purchases.
     */
    suspend fun catalog(): BillingCatalog {
        val envelope = client.submitAction(
            "store/billing_catalog",
            JsonValue.obj("store" to JsonValue.Str("play_store")),
        )
        return BillingCatalog.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /**
     * Post a Play purchase token for server-side validation and tier grant.
     * The server fetches authoritative purchase state from the Play Developer
     * API. Idempotent — safe to repeat on restore.
     */
    suspend fun claim(purchaseToken: String, packageName: String): BillingClaimResult {
        val envelope = client.submitAction(
            "store/play_claim",
            JsonValue.obj(
                "purchase_token" to JsonValue.Str(purchaseToken),
                "package_name" to JsonValue.Str(packageName),
            ),
        )
        return BillingClaimResult.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /**
     * The server's view of the user's subscriptions — the screen's status
     * section reflects this, not Play Billing's local state.
     */
    suspend fun summary(): BillingSummary {
        val envelope = client.submitAction("store/subscription_summary", JsonValue.Obj(emptyList()))
        return BillingSummary.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }
}
