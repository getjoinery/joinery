package com.getjoinery.memberkit

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.AlertDialog
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
 * Status-tabbed event registration list. Rows open the session content page
 * (video/CMS content) through [web] — that surface is deliberately web.
 * Withdraw uses a confirmation dialog against the existing `event_withdraw`
 * action.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EventsScreen(client: ApiClient, web: WebSessionCoordinator?, onBack: (() -> Unit)? = null) {
    val store = remember { EventListStore(MemberApi(client)) }
    val scope = rememberCoroutineScope()
    var pendingWithdraw by remember { mutableStateOf<EventRegistration?>(null) }
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

    Scaffold(topBar = {
        MemberTopBar("My Events", onBack) {
            StatusMenu(current = store.status) { newStatus ->
                if (newStatus != store.status) scope.launch { store.select(newStatus) }
            }
        }
    }) { padding ->
        when (val phase = store.phase) {
            is MemberPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("member_events_loading"))
                }
            is MemberPhase.Failed ->
                RetryBox(phase.message, "member_events_error", "member_events_retry", Modifier.padding(padding)) {
                    scope.launch { store.initialLoad() }
                }
            is MemberPhase.Loaded ->
                MemberPullRefresh(onRefresh = { store.reload() }, Modifier.padding(padding)) {
                LazyColumn(Modifier.fillMaxSize().testTag("member_events_list")) {
                    if (store.registrations.isEmpty()) {
                        item {
                            Text(
                                "No events.",
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.padding(16.dp).testTag("member_events_empty"),
                            )
                        }
                    }
                    items(store.registrations.size, key = { store.registrations[it].registrantId }) { index ->
                        EventRow(
                            registration = store.registrations[index],
                            onOpen = { reg ->
                                if (web != null && reg.webUrl.isNotEmpty()) webDest = reg.eventName to reg.webUrl
                            },
                            onWithdraw = { pendingWithdraw = it },
                        )
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        if (index == store.registrations.lastIndex) {
                            LaunchedEffect(store.registrations[index].registrantId) { store.loadMore() }
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

    pendingWithdraw?.let { reg ->
        AlertDialog(
            onDismissRequest = { pendingWithdraw = null },
            title = { Text("Withdraw from this event?") },
            text = { Text("This cannot be undone and any payment is non-refundable.") },
            confirmButton = {
                TextButton(onClick = {
                    pendingWithdraw = null
                    scope.launch { store.withdraw(reg.registrantId) }
                }) { Text("Withdraw") }
            },
            dismissButton = { TextButton(onClick = { pendingWithdraw = null }) { Text("Cancel") } },
        )
    }

    store.withdrawError?.let { message ->
        AlertDialog(
            onDismissRequest = { store.clearWithdrawError() },
            title = { Text("Could not withdraw") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { store.clearWithdrawError() }) { Text("OK") } },
        )
    }
}

@Composable
private fun StatusMenu(current: EventStatusFilter, onSelect: (EventStatusFilter) -> Unit) {
    var open by remember { mutableStateOf(false) }
    IconButton(onClick = { open = true }, modifier = Modifier.testTag("member_events_status_menu")) {
        Icon(Icons.Outlined.FilterList, contentDescription = "Filter by status")
    }
    DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
        EventStatusFilter.entries.forEach { status ->
            DropdownMenuItem(
                text = { Text(status.title) },
                trailingIcon = {
                    if (status == current) {
                        Icon(Icons.Outlined.Check, contentDescription = "Selected", tint = MaterialTheme.colorScheme.primary)
                    }
                },
                onClick = { open = false; onSelect(status) },
            )
        }
    }
}

@Composable
private fun EventRow(
    registration: EventRegistration,
    onOpen: (EventRegistration) -> Unit,
    onWithdraw: (EventRegistration) -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .clickable { onOpen(registration) }
            .padding(horizontal = 16.dp, vertical = 10.dp)
            .testTag("member_event_row"),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(
            registration.eventName,
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.Medium,
        )
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                registration.status.replaceFirstChar { it.uppercase() },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            registration.nextSessionTime?.let { next ->
                Text(
                    " · ${MemberDisplay.dateTimeLabel(next)}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        if (registration.status == "active") {
            Text(
                "Withdraw",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
                modifier = Modifier
                    .clickable { onWithdraw(registration) }
                    .testTag("member_event_withdraw_${registration.registrantId}"),
            )
        }
    }
}
