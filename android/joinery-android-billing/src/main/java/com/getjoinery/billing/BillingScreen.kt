package com.getjoinery.billing

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import kotlinx.coroutines.launch

internal const val PLAY_MANAGE_URL = "https://play.google.com/store/account/subscriptions"

/**
 * The `billing` native screen: current plan (server's view), purchasable
 * plans with Play-localized prices, restore, and manage-routing by source.
 * When the user's subscription is billed elsewhere (source exclusivity), the
 * screen shows the existing source instead of purchase buttons.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BillingScreen(client: ApiClient, onBack: (() -> Unit)? = null) {
    val context = LocalContext.current
    val store = remember { BillingStore(BillingApi(client)) }
    val scope = rememberCoroutineScope()
    val connector = remember {
        PlayBillingConnector(context) { purchases ->
            scope.launch {
                store.claimTokens(purchases.map { it.purchaseToken }, context.packageName)
            }
        }
    }

    LaunchedEffect(Unit) {
        if (store.phase is BillingPhase.Loading) {
            store.initialLoad()
            store.catalog?.let { catalog ->
                store.onProductDetails(connector.productDetails(catalog.plans.map { it.storeProductId }))
            }
        }
    }

    Scaffold(topBar = {
        TopAppBar(
            title = { Text("Subscription") },
            navigationIcon = {
                if (onBack != null) {
                    IconButton(onClick = onBack, modifier = Modifier.testTag("billing_back")) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                }
            },
        )
    }) { padding ->
        when (val phase = store.phase) {
            is BillingPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("billing_loading"))
                }
            is BillingPhase.Failed ->
                Column(
                    Modifier.fillMaxSize().padding(padding).padding(24.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(
                        phase.message,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.testTag("billing_error"),
                    )
                    Button(
                        onClick = { scope.launch { store.initialLoad() } },
                        modifier = Modifier.padding(top = 12.dp).testTag("billing_retry"),
                    ) { Text("Try Again") }
                }
            is BillingPhase.Loaded ->
                store.catalog?.let { catalog ->
                    BillingList(
                        store = store,
                        catalog = catalog,
                        modifier = Modifier.padding(padding),
                        onPurchase = { plan ->
                            val activity = context.findActivity()
                            val details = store.productDetails[plan.storeProductId]
                            if (activity == null || details == null) {
                                store.actionError = "This plan is not available right now."
                            } else {
                                store.purchasing = true
                                if (!connector.launchPurchase(activity, details, catalog.appAccountToken)) {
                                    store.actionError = "The purchase could not be started."
                                }
                                store.purchasing = false
                            }
                        },
                        onRestore = {
                            scope.launch {
                                val purchases = connector.currentPurchases()
                                if (purchases.isEmpty()) {
                                    store.actionError = "No purchases to restore."
                                } else {
                                    store.claimTokens(purchases.map { it.purchaseToken }, context.packageName)
                                }
                            }
                        },
                        onOpenPlay = {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(PLAY_MANAGE_URL)))
                        },
                    )
                }
        }
    }

    store.actionError?.let { message ->
        AlertDialog(
            onDismissRequest = { store.clearActionError() },
            title = { Text("Something went wrong") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { store.clearActionError() }) { Text("OK") } },
        )
    }
    store.actionMessage?.let { message ->
        AlertDialog(
            onDismissRequest = { store.clearActionMessage() },
            title = { Text("Done") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { store.clearActionMessage() }) { Text("OK") } },
        )
    }
}

@Composable
private fun BillingList(
    store: BillingStore,
    catalog: BillingCatalog,
    modifier: Modifier,
    onPurchase: (BillingPlan) -> Unit,
    onRestore: () -> Unit,
    onOpenPlay: () -> Unit,
) {
    LazyColumn(modifier.fillMaxSize().testTag("billing_list")) {
        item {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 14.dp).testTag("billing_current_tier"),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("Current Plan", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                Text(
                    store.summary?.currentTierName ?: "Free",
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        store.summary?.takeIf { it.activeCount > 0 }?.let { summary ->
            summary.status?.let { status ->
                item {
                    LabelRow("Status", status.replaceFirstChar { it.uppercase() }, "billing_status")
                }
            }
            summary.renewalOrEndDate?.let { date ->
                item { LabelRow("Renews", date, "billing_renewal") }
            }
        }
        item { HorizontalDivider() }

        if (catalog.canPurchase) {
            item {
                Text(
                    "Plans",
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp),
                )
            }
            if (catalog.plans.isEmpty()) {
                item {
                    Text(
                        "No plans are available right now.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(16.dp).testTag("billing_no_plans"),
                    )
                }
            }
            catalog.plans.forEach { plan ->
                item { PlanRow(store, plan, onPurchase) }
            }
        } else {
            catalog.activeSource?.let { source ->
                item {
                    Text(
                        otherSourceMessage(source),
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(16.dp).testTag("billing_other_source"),
                    )
                }
            }
        }

        item { HorizontalDivider() }
        item {
            Text(
                "Restore Purchases",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.fillMaxWidth().clickable(onClick = onRestore)
                    .padding(horizontal = 16.dp, vertical = 14.dp).testTag("billing_restore"),
            )
        }
        if (store.summary?.paymentSource == "play_store") {
            item {
                Row(
                    Modifier.fillMaxWidth().clickable(onClick = onOpenPlay)
                        .padding(horizontal = 16.dp, vertical = 14.dp).testTag("billing_manage_play_store"),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text("Manage in Google Play", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                    Icon(
                        Icons.AutoMirrored.Filled.KeyboardArrowRight,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
private fun LabelRow(label: String, value: String, tag: String) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
        Text(value, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun PlanRow(store: BillingStore, plan: BillingPlan, onPurchase: (BillingPlan) -> Unit) {
    val details = store.productDetails[plan.storeProductId]
    val isCurrent = (store.summary?.activeCount ?: 0) > 0 && store.summary?.currentTierName == plan.tier?.name
    val price = details?.subscriptionOfferDetails?.firstOrNull()
        ?.pricingPhases?.pricingPhaseList?.firstOrNull()?.formattedPrice
    val enabled = !store.purchasing && !isCurrent && details != null

    Row(
        Modifier.fillMaxWidth()
            .clickable(enabled = enabled) { onPurchase(plan) }
            .padding(horizontal = 16.dp, vertical = 12.dp)
            .testTag("billing_plan_${plan.storeProductId}"),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                plan.tier?.name ?: plan.productName,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Medium,
            )
            if (plan.period.isNotEmpty()) {
                Text(
                    "per ${plan.period}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        Text(
            when {
                isCurrent -> "Current"
                price != null -> price
                else -> "Unavailable"
            },
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.SemiBold,
            color = if (isCurrent || price == null) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.primary,
        )
    }
}

internal fun otherSourceMessage(source: String): String = when (source) {
    "stripe", "paypal" -> "Your subscription is billed through the website. Manage it there."
    "app_store" -> "Your subscription is billed through the App Store. Manage it there."
    else -> "Your subscription is billed elsewhere."
}

private fun Context.findActivity(): Activity? {
    var current: Context = this
    while (current is ContextWrapper) {
        if (current is Activity) return current
        current = current.baseContext
    }
    return null
}
