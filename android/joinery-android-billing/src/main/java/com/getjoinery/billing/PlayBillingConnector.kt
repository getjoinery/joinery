package com.getjoinery.billing

import android.app.Activity
import android.content.Context
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingFlowParams
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.PendingPurchasesParams
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase
import com.android.billingclient.api.PurchasesUpdatedListener
import com.android.billingclient.api.QueryProductDetailsParams
import com.android.billingclient.api.QueryPurchasesParams
import com.android.billingclient.api.queryProductDetails
import com.android.billingclient.api.queryPurchasesAsync
import kotlin.coroutines.resume
import kotlinx.coroutines.suspendCancellableCoroutine

/**
 * Thin wrapper over the Play Billing client: connect, query ProductDetails
 * for the server catalog's product IDs, run the purchase sheet, and surface
 * purchased tokens to [onPurchased] so the screen can claim them server-side.
 * Acknowledgement happens server-side during the claim — this class never
 * acknowledges locally.
 */
class PlayBillingConnector(
    context: Context,
    private val onPurchased: (List<Purchase>) -> Unit,
) : PurchasesUpdatedListener {

    private val client: BillingClient = BillingClient.newBuilder(context.applicationContext)
        .setListener(this)
        .enablePendingPurchases(
            PendingPurchasesParams.newBuilder().enableOneTimeProducts().build()
        )
        .build()

    override fun onPurchasesUpdated(result: BillingResult, purchases: MutableList<Purchase>?) {
        if (result.responseCode == BillingClient.BillingResponseCode.OK && purchases != null) {
            onPurchased(purchases.filter { it.purchaseState == Purchase.PurchaseState.PURCHASED })
        }
    }

    /** Connect (idempotent). Returns true when the client is ready. */
    suspend fun connect(): Boolean {
        if (client.isReady) return true
        return suspendCancellableCoroutine { cont ->
            client.startConnection(object : BillingClientStateListener {
                override fun onBillingSetupFinished(result: BillingResult) {
                    if (cont.isActive) cont.resume(result.responseCode == BillingClient.BillingResponseCode.OK)
                }

                override fun onBillingServiceDisconnected() {
                    // Reconnection happens on the next connect() call.
                }
            })
        }
    }

    /** ProductDetails for the catalog's subscription product IDs. */
    suspend fun productDetails(productIds: List<String>): Map<String, ProductDetails> {
        if (productIds.isEmpty() || !connect()) return emptyMap()
        val params = QueryProductDetailsParams.newBuilder()
            .setProductList(
                productIds.map {
                    QueryProductDetailsParams.Product.newBuilder()
                        .setProductId(it)
                        .setProductType(BillingClient.ProductType.SUBS)
                        .build()
                }
            )
            .build()
        val result = client.queryProductDetails(params)
        if (result.billingResult.responseCode != BillingClient.BillingResponseCode.OK) return emptyMap()
        return (result.productDetailsList ?: emptyList()).associateBy { it.productId }
    }

    /**
     * Launch the purchase sheet. [obfuscatedAccountId] is the server-issued
     * account token, so store notifications can be linked back to the user.
     * The result arrives through [onPurchased].
     */
    fun launchPurchase(activity: Activity, details: ProductDetails, obfuscatedAccountId: String): Boolean {
        val offerToken = details.subscriptionOfferDetails?.firstOrNull()?.offerToken ?: return false
        val params = BillingFlowParams.newBuilder()
            .setProductDetailsParamsList(
                listOf(
                    BillingFlowParams.ProductDetailsParams.newBuilder()
                        .setProductDetails(details)
                        .setOfferToken(offerToken)
                        .build()
                )
            )
            .setObfuscatedAccountId(obfuscatedAccountId)
            .build()
        val result = client.launchBillingFlow(activity, params)
        return result.responseCode == BillingClient.BillingResponseCode.OK
    }

    /** Current subscription purchases (restore flow). */
    suspend fun currentPurchases(): List<Purchase> {
        if (!connect()) return emptyList()
        val result = client.queryPurchasesAsync(
            QueryPurchasesParams.newBuilder().setProductType(BillingClient.ProductType.SUBS).build()
        )
        if (result.billingResult.responseCode != BillingClient.BillingResponseCode.OK) return emptyList()
        return result.purchasesList.filter { it.purchaseState == Purchase.PurchaseState.PURCHASED }
    }
}
