package com.getjoinery.billing

import com.getjoinery.android.JsonValue

/** A subscription tier as the catalog reports it. */
data class BillingTier(
    val tierId: Int,
    val name: String,
    val level: Int,
) {
    companion object {
        fun from(json: JsonValue?): BillingTier? {
            if (json == null || json is JsonValue.Null) return null
            val tierId = json["tier_id"]?.intValue ?: return null
            return BillingTier(
                tierId = tierId,
                name = json["name"]?.stringValue ?: "",
                level = json["level"]?.intValue ?: 0,
            )
        }
    }
}

/**
 * One purchasable plan: a store product ID mapped server-side to a product and
 * tier. Localized pricing comes from Play Billing, not this payload.
 */
data class BillingPlan(
    val storeProductId: String,
    val productName: String,
    val period: String,
    val tier: BillingTier?,
) {
    companion object {
        fun from(json: JsonValue): BillingPlan? {
            val id = json["store_product_id"]?.stringValue.orEmpty()
            if (id.isEmpty()) return null
            return BillingPlan(
                storeProductId = id,
                productName = json["product_name"]?.stringValue ?: "",
                period = json["period"]?.stringValue ?: "",
                tier = BillingTier.from(json["tier"]),
            )
        }
    }
}

/** The store/billing_catalog payload. */
data class BillingCatalog(
    val store: String,
    val plans: List<BillingPlan>,
    /** stripe | paypal | app_store | play_store — null when no active subscription. */
    val activeSource: String?,
    /**
     * False when the user's active subscription is billed elsewhere (source
     * exclusivity) — the screen shows the existing source instead of purchase
     * buttons.
     */
    val canPurchase: Boolean,
    /**
     * Deterministic account token the kit passes as obfuscatedAccountId on
     * purchase, so store notifications can be linked back to the user.
     */
    val appAccountToken: String,
) {
    companion object {
        fun from(data: JsonValue?): BillingCatalog? {
            if (data == null) return null
            return BillingCatalog(
                store = data["store"]?.stringValue ?: "",
                plans = (data["products"]?.arrayValue ?: emptyList()).mapNotNull(BillingPlan::from),
                activeSource = data["active_source"]?.stringValue?.takeIf { it.isNotEmpty() },
                canPurchase = data["can_purchase"]?.boolValue ?: false,
                appAccountToken = data["app_account_token"]?.stringValue ?: "",
            )
        }
    }
}

/** The store/play_claim result: the server's record of the granted subscription. */
data class BillingClaimResult(
    val orderItemId: Int,
    val productName: String,
    val tier: BillingTier?,
    val status: String,
    val periodEnd: String?,
    val paymentSource: String,
) {
    companion object {
        fun from(data: JsonValue?): BillingClaimResult? {
            if (data == null) return null
            val orderItemId = data["order_item_id"]?.intValue ?: return null
            return BillingClaimResult(
                orderItemId = orderItemId,
                productName = data["product_name"]?.stringValue ?: "",
                tier = BillingTier.from(data["tier"]),
                status = data["status"]?.stringValue ?: "",
                periodEnd = data["period_end"]?.stringValue,
                paymentSource = data["payment_source"]?.stringValue ?: "",
            )
        }
    }
}

/**
 * The slice of store/subscription_summary the billing screen shows: the
 * server-authoritative current plan and source.
 */
data class BillingSummary(
    val currentTierName: String?,
    /** stripe | paypal | app_store | play_store | none */
    val paymentSource: String,
    val activeCount: Int,
    val renewalOrEndDate: String?,
    val status: String?,
) {
    companion object {
        fun from(data: JsonValue?): BillingSummary? {
            if (data == null) return null
            val active = data["active_subscriptions"]?.arrayValue ?: emptyList()
            return BillingSummary(
                currentTierName = data["current_tier"]?.get("name")?.stringValue,
                paymentSource = data["payment_source"]?.stringValue ?: "none",
                activeCount = active.size,
                renewalOrEndDate = active.firstOrNull()?.get("renewal_or_end_date")?.stringValue,
                status = active.firstOrNull()?.get("status")?.stringValue,
            )
        }
    }
}
