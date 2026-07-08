package com.getjoinery.dnsfilter

import androidx.activity.compose.BackHandler
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
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Laptop
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material.icons.filled.Router
import androidx.compose.material.icons.filled.Tablet
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import com.getjoinery.android.WebSessionCoordinator
import kotlinx.coroutines.launch

/**
 * The device list — every device on the account, each opening its always-on
 * editor. This app applies the *tunnel* only to the phone it runs on (that
 * lives on the Protection screen); this screen edits the shared *policy* for
 * any device, exactly as the web devices page does.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DevicesScreen(client: ApiClient, web: WebSessionCoordinator?, onBack: (() -> Unit)? = null) {
    val store = remember { DeviceListStore(DnsFilterApi(client)) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) { if (store.phase is DnsPhase.Loading) store.load() }

    var editing by remember { mutableStateOf<Pair<Int, Int>?>(null) } // deviceId, blockId
    val target = editing
    if (target != null) {
        val account = store.account
        val back = { editing = null; Unit }
        BackHandler(onBack = back)
        if (account != null) {
            AlwaysOnEditorScreen(client, account, target.first, target.second, onBack = back)
        } else {
            back()
        }
        return
    }

    Scaffold(topBar = { DnsTopBar("Devices", onBack) }) { padding ->
        when (val phase = store.phase) {
            is DnsPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("devices_loading"))
                }
            is DnsPhase.Failed ->
                DnsRetryBox(phase.message, "devices_error", "devices_retry", Modifier.padding(padding)) {
                    scope.launch { store.load() }
                }
            is DnsPhase.Loaded ->
                DeviceList(store, Modifier.padding(padding)) { deviceId, blockId -> editing = deviceId to blockId }
        }
    }
}

@Composable
private fun DeviceList(store: DeviceListStore, modifier: Modifier, onOpen: (Int, Int) -> Unit) {
    LazyColumn(modifier.fillMaxSize().testTag("devices_list")) {
        if (store.devices.isEmpty()) {
            item {
                Text(
                    "No devices yet.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.outline,
                    modifier = Modifier.padding(16.dp).testTag("devices_empty"),
                )
            }
        }
        store.devices.forEach { device ->
            item { DeviceRow(device) { device.alwaysOnBlock?.let { onOpen(device.deviceId, it.blockId) } } }
            item { HorizontalDivider() }
        }
        store.account?.let { account ->
            item {
                Text(
                    "${account.deviceCount} of ${account.deviceMax} devices used.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.outline,
                    modifier = Modifier.padding(16.dp),
                )
            }
        }
    }
}

@Composable
private fun DeviceRow(device: DnsDevice, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp).testTag("device_${device.deviceId}"),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Icon(deviceIcon(device.deviceType), contentDescription = null, tint = MaterialTheme.colorScheme.outline, modifier = Modifier.size(24.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(device.name, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
            Text(if (device.isActive) "Active" else "Paused", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.outline)
        }
        if (device.alwaysOnBlock != null) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

private fun deviceIcon(type: String): ImageVector = when (type.lowercase()) {
    "phone" -> Icons.Filled.PhoneAndroid
    "tablet" -> Icons.Filled.Tablet
    "laptop", "computer", "desktop" -> Icons.Filled.Laptop
    else -> Icons.Filled.Router
}
