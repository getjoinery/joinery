package com.getjoinery.memberkit

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import kotlinx.coroutines.launch

/**
 * Paginated order history: order id/number, date, total, and its line-item
 * summaries. Read-only — there is no native purchase or refund flow.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OrdersScreen(client: ApiClient, onBack: (() -> Unit)? = null) {
    val store = remember { OrderListStore(MemberApi(client)) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        if (store.phase is MemberPhase.Loading) store.initialLoad()
    }
    MemberResumeRefresh(store.phase is MemberPhase.Loading) { scope.launch { store.reload() } }

    Scaffold(topBar = { MemberTopBar("Orders", onBack) }) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_orders_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_orders_error", "member_orders_retry", Modifier.padding(padding)) {
                    scope.launch { store.initialLoad() }
                }
            is MemberPhase.Loaded ->
                MemberPullRefresh(onRefresh = { store.reload() }, Modifier.padding(padding)) {
                LazyColumn(Modifier.fillMaxSize().testTag("member_orders_list")) {
                    if (store.orders.isEmpty()) {
                        item {
                            Text(
                                "No orders yet.",
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.padding(16.dp).testTag("member_orders_empty"),
                            )
                        }
                    }
                    items(store.orders.size, key = { store.orders[it].orderId }) { index ->
                        OrderRow(store.orders[index])
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        if (index == store.orders.lastIndex) {
                            LaunchedEffect(store.orders[index].orderId) { store.loadMore() }
                        }
                    }
                    if (store.isLoadingMore) {
                        item {
                            Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator(Modifier.size(24.dp))
                            }
                        }
                    }
                }
                }
        }
    }
}

@Composable
private fun OrderRow(order: OrderSummary) {
    Column(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp).testTag("member_order_row"),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                "Order #${order.number}",
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Medium,
                modifier = Modifier.weight(1f),
            )
            Text("$${order.total}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
        }
        Text(
            MemberDisplay.dateLabel(order.date),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        order.items.forEach { item ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    item.productName,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f),
                )
                Text(
                    "$${item.price}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}
