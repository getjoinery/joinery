package com.getjoinery.memberkit

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import com.getjoinery.android.WebSessionCoordinator
import kotlinx.coroutines.launch

/**
 * Active + cancelled subscriptions. Read-only plus cancel — changing tier and
 * billing management are deliberately web-only (Google Play IAP policy), so
 * those rows open the web pages through [web] instead of native purchase UI.
 * Store-billed subscriptions (payment_source app_store / play_store) are
 * managed in their store: the web rows hide and a deep link to the store's
 * subscription management appears instead.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SubscriptionsScreen(client: ApiClient, web: WebSessionCoordinator?, onBack: (() -> Unit)? = null) {
    val store = remember { SubscriptionStore(MemberApi(client)) }
    val scope = rememberCoroutineScope()
    var pendingCancel by remember { mutableStateOf<SubscriptionRow?>(null) }
    var webDest by remember { mutableStateOf<Pair<String, String>?>(null) }

    LaunchedEffect(Unit) {
        if (store.phase is MemberPhase.Loading) store.initialLoad()
    }
    MemberResumeRefresh(store.phase is MemberPhase.Loading) { scope.launch { store.reload() } }

    val dest = webDest
    if (dest != null) {
        MemberWebDetail(dest.first, dest.second, client, web, onBack = { webDest = null })
        return
    }

    Scaffold(topBar = { MemberTopBar("Subscriptions", onBack) }) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_subscriptions_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_subscriptions_error", "member_subscriptions_retry", Modifier.padding(padding)) {
                    scope.launch { store.initialLoad() }
                }
            is MemberPhase.Loaded ->
                store.payload?.let { payload ->
                    MemberPullRefresh(onRefresh = { store.reload() }, Modifier.padding(padding)) {
                        SubscriptionList(
                            payload = payload,
                            web = web,
                            onOpenWeb = { title, target -> webDest = title to target },
                            onCancel = { pendingCancel = it },
                        )
                    }
                }
        }
    }

    pendingCancel?.let { sub ->
        AlertDialog(
            onDismissRequest = { pendingCancel = null },
            title = { Text("Cancel this subscription?") },
            text = { Text("Your access continues until the end of the current billing period.") },
            confirmButton = {
                TextButton(onClick = {
                    pendingCancel = null
                    scope.launch { store.cancel(sub.orderItemId) }
                }) { Text("Cancel Subscription") }
            },
            dismissButton = {
                TextButton(onClick = { pendingCancel = null }) { Text("Keep Subscription") }
            },
        )
    }

    store.cancelError?.let { message ->
        AlertDialog(
            onDismissRequest = { store.clearCancelError() },
            title = { Text("Could not cancel") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { store.clearCancelError() }) { Text("OK") } },
        )
    }
}

@Composable
private fun SubscriptionList(
    payload: SubscriptionSummaryPayload,
    web: WebSessionCoordinator?,
    onOpenWeb: (String, String) -> Unit,
    onCancel: (SubscriptionRow) -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize().testTag("member_subscriptions_list")) {
        item {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 14.dp).testTag("member_subscriptions_current_tier"),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("Current Plan", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                Text(
                    payload.currentTier?.name ?: "Free",
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        when (payload.paymentSource) {
            "play_store" -> item {
                StoreManageRow("Manage in Google Play", "member_subscriptions_manage_play_store", PLAY_STORE_MANAGE_URL)
            }
            "app_store" -> item {
                StoreManageRow("Manage in App Store", "member_subscriptions_manage_app_store", APP_STORE_MANAGE_URL)
            }
            else -> if (web != null) {
                item {
                    WebRow("Change Plan", "member_subscriptions_change_plan") { onOpenWeb("Change Plan", "/profile/change-tier") }
                }
                if (payload.paymentSource == "stripe") {
                    item {
                        WebRow("Manage Billing", "member_subscriptions_billing") { onOpenWeb("Billing", "/profile/billing") }
                    }
                }
            }
        }

        if (payload.activeSubscriptions.isNotEmpty()) {
            item { HorizontalDivider() }
            item { SubSectionHeader("Active") }
            payload.activeSubscriptions.forEach { sub ->
                item { SubscriptionRowView(sub, active = true, onCancel = onCancel) }
            }
        }
        if (payload.cancelledSubscriptions.isNotEmpty()) {
            item { HorizontalDivider() }
            item { SubSectionHeader("Cancelled") }
            payload.cancelledSubscriptions.forEach { sub ->
                item { SubscriptionRowView(sub, active = false, onCancel = onCancel) }
            }
        }
        if (payload.activeSubscriptions.isEmpty() && payload.cancelledSubscriptions.isEmpty()) {
            item {
                Text(
                    "No subscriptions.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(16.dp).testTag("member_subscriptions_empty"),
                )
            }
        }
    }
}

@Composable
private fun SubSectionHeader(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.labelMedium,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp),
    )
}

internal const val PLAY_STORE_MANAGE_URL = "https://play.google.com/store/account/subscriptions"
internal const val APP_STORE_MANAGE_URL = "https://apps.apple.com/account/subscriptions"

/** A row that deep-links out to the store's subscription management page. */
@Composable
private fun StoreManageRow(label: String, tag: String, url: String) {
    val context = androidx.compose.ui.platform.LocalContext.current
    Row(
        Modifier.fillMaxWidth().clickable {
            context.startActivity(android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(url)))
        }.padding(horizontal = 16.dp, vertical = 14.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Icon(
            Icons.AutoMirrored.Filled.KeyboardArrowRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun WebRow(label: String, tag: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 14.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Icon(
            Icons.AutoMirrored.Filled.KeyboardArrowRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun SubscriptionRowView(sub: SubscriptionRow, active: Boolean, onCancel: (SubscriptionRow) -> Unit) {
    Column(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                sub.productName,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Medium,
                modifier = Modifier.weight(1f),
            )
            Text("$${sub.price}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                sub.status.replaceFirstChar { it.uppercase() },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            sub.renewalOrEndDate?.let { date ->
                Text(
                    " · ${MemberDisplay.dateLabel(date)}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        if (active && sub.canCancel) {
            Text(
                "Cancel",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
                modifier = Modifier.clickable { onCancel(sub) }.testTag("member_subscription_cancel_${sub.orderItemId}"),
            )
        }
    }
}
