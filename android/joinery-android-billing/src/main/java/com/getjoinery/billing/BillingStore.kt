package com.getjoinery.billing

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.android.billingclient.api.ProductDetails
import com.getjoinery.android.JoineryApiError

sealed class BillingPhase {
    object Loading : BillingPhase()
    object Loaded : BillingPhase()
    data class Failed(val message: String) : BillingPhase()
}

internal fun displayMessage(e: Exception): String =
    (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong.")

/**
 * Server-side state for the billing screen: catalog + summary loads and the
 * claim call. Play Billing state (ProductDetails, the purchase sheet) is fed
 * in by [PlayBillingConnector]; the tier is granted only when the server
 * accepts the claim — the screen reflects the server's view, not Play's.
 */
class BillingStore(val api: BillingApi) {
    var phase by mutableStateOf<BillingPhase>(BillingPhase.Loading)
        private set
    var catalog by mutableStateOf<BillingCatalog?>(null)
        private set
    var summary by mutableStateOf<BillingSummary?>(null)
        private set
    var productDetails by mutableStateOf<Map<String, ProductDetails>>(emptyMap())
        private set
    var purchasing by mutableStateOf(false)
    var actionError by mutableStateOf<String?>(null)
    var actionMessage by mutableStateOf<String?>(null)

    private var loadGeneration = 0

    suspend fun initialLoad() {
        phase = BillingPhase.Loading
        reload()
    }

    suspend fun reload() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val newCatalog = api.catalog()
            val newSummary = api.summary()
            if (generation != loadGeneration) return
            catalog = newCatalog
            summary = newSummary
            phase = BillingPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            phase = BillingPhase.Failed(displayMessage(e))
        }
    }

    fun onProductDetails(details: Map<String, ProductDetails>) {
        productDetails = details
    }

    /**
     * Claim purchase tokens server-side (post-purchase and restore). Returns
     * the number of successful claims; failures surface in [actionError].
     */
    suspend fun claimTokens(tokens: List<String>, packageName: String): Int {
        var claimed = 0
        var lastError: String? = null
        for (token in tokens) {
            try {
                val result = api.claim(token, packageName)
                claimed += 1
                actionMessage = result.tier?.let { "You're on ${it.name}." } ?: "Purchase complete."
            } catch (e: Exception) {
                lastError = displayMessage(e)
            }
        }
        if (claimed == 0 && lastError != null) actionError = lastError
        if (claimed > 0) reload()
        return claimed
    }

    fun clearActionError() { actionError = null }
    fun clearActionMessage() { actionMessage = null }
}
