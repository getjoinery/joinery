package com.getjoinery.dnsfilter

import android.app.Activity
import android.content.Intent
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
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
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import kotlinx.coroutines.launch

/**
 * The app's home: whether this phone is protected, the guided one-tap enable
 * (a single system VPN consent dialog — no Settings trip), the protection-level
 * control, and Turn Off. Everything account- and shell-shaped comes from
 * joinery-android; this screen is the ScrollDaddy-specific activation surface.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProtectionScreen(client: ApiClient, config: DnsFilterConfig, onBack: (() -> Unit)? = null) {
    val context = LocalContext.current
    val store = remember {
        ProtectionStore(DnsFilterApi(client), config, PhoneDeviceStore(context, config.baseUrl))
    }
    val scope = rememberCoroutineScope()

    // The tunnel's live status drives the banner and enable/turn-off gating.
    val vpnStatus by VpnController.status.collectAsState()
    LaunchedEffect(vpnStatus) { store.updateStatus(vpnStatus) }

    LaunchedEffect(Unit) { if (store.phase is DnsPhase.Loading) store.load() }

    // One system VPN consent dialog, then start. On API 33+ we also ask for the
    // notification permission first so the ongoing "Protected" notice is visible.
    val consentLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result -> if (result.resultCode == Activity.RESULT_OK) store.enable(context) }

    fun launchConsent() {
        val intent = VpnController.consentIntent(context)
        if (intent != null) consentLauncher.launch(intent) else store.enable(context)
    }

    val notifLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { _ -> launchConsent() }

    fun beginEnable() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            notifLauncher.launch(android.Manifest.permission.POST_NOTIFICATIONS)
        } else {
            launchConsent()
        }
    }

    // Child navigation: the always-on editor. Returning refreshes the pinned
    // device (a hard-block change may need re-syncing to a strict tunnel).
    var editing by remember { mutableStateOf(false) }
    if (editing) {
        val back = {
            editing = false
            scope.launch { store.refreshDevice(context) }
            Unit
        }
        BackHandler(onBack = back)
        val account = store.account
        val device = store.phoneDevice
        val block = device?.alwaysOnBlock
        if (account != null && device != null && block != null) {
            AlwaysOnEditorScreen(
                client = client,
                account = account,
                deviceId = device.deviceId,
                blockId = block.blockId,
                onBack = back,
                onHardBlockChange = { scope.launch { store.refreshDevice(context) } },
            )
        } else {
            back()
        }
        return
    }

    Scaffold(topBar = { DnsTopBar("Protection", onBack) }) { padding ->
        when (val phase = store.phase) {
            is DnsPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("protection_loading"))
                }
            is DnsPhase.Failed ->
                DnsRetryBox(phase.message, "protection_error", "protection_retry", Modifier.padding(padding)) {
                    scope.launch { store.load() }
                }
            is DnsPhase.Loaded ->
                Loaded(store, Modifier.padding(padding), onEnable = { beginEnable() }, onEdit = { editing = true }, context = context, scope = scope)
        }
    }
}

@Composable
private fun Loaded(
    store: ProtectionStore,
    modifier: Modifier,
    onEnable: () -> Unit,
    onEdit: () -> Unit,
    context: android.content.Context,
    scope: kotlinx.coroutines.CoroutineScope,
) {
    var confirmOff by remember { mutableStateOf(false) }

    LazyColumn(modifier.fillMaxSize().testTag("protection_list")) {
        item { StatusHeader(store) }
        item { HorizontalDivider() }

        if (store.needsRegistration) {
            item { RegisterSection(store, scope) }
        } else {
            item { ProtectionSection(store, onEnable) }
            item { HorizontalDivider() }
            item {
                Row(
                    Modifier.fillMaxWidth().clickable(onClick = onEdit)
                        .padding(horizontal = 16.dp, vertical = 16.dp).testTag("protection_edit_rules"),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text("Always-On Rules", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            if (store.isProtected) {
                item { HorizontalDivider() }
                item { ReliabilitySection(context) }
                item {
                    Box(Modifier.fillMaxWidth().padding(16.dp)) {
                        Button(
                            onClick = { confirmOff = true },
                            modifier = Modifier.fillMaxWidth().testTag("protection_turn_off"),
                        ) { Text("Turn off protection") }
                    }
                }
            }
        }
    }

    if (confirmOff) {
        AlertDialog(
            onDismissRequest = { confirmOff = false },
            title = { Text("Turn off protection?") },
            text = { Text("Filtering stops and this device goes back to its normal DNS. You can turn it back on anytime.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmOff = false
                    store.turnOff(context)
                }) { Text("Turn Off") }
            },
            dismissButton = { TextButton(onClick = { confirmOff = false }) { Text("Cancel") } },
        )
    }
}

@Composable
private fun StatusHeader(store: ProtectionStore) {
    Row(
        Modifier.fillMaxWidth().padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Icon(
            Icons.Filled.Shield,
            contentDescription = null,
            tint = if (store.isProtected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline,
            modifier = Modifier.size(36.dp),
        )
        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(
                if (store.isProtected) "Protected" else "Not protected",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.testTag("protection_status_label"),
            )
            Text(
                when {
                    store.status == ProtectionStatus.CONNECTING -> "Turning on…"
                    store.isProtected && store.mode == ProtectionMode.STRICT -> "Strict mode is on."
                    store.isProtected -> "Encrypted DNS filtering is on."
                    else -> "Finish setup to start filtering."
                },
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.outline,
            )
        }
    }
}

@Composable
private fun RegisterSection(store: ProtectionStore, scope: kotlinx.coroutines.CoroutineScope) {
    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(
            "Register this phone to apply filtering to it.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.outline,
        )
        Button(
            onClick = { scope.launch { store.registerThisPhone() } },
            enabled = !store.isWorking,
            modifier = Modifier.fillMaxWidth().testTag("protection_register"),
        ) {
            if (store.isWorking) {
                CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(8.dp))
            }
            Text("Set up this phone")
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ProtectionSection(store: ProtectionStore, onEnable: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        // Strict mode (all-traffic tunnel + connection-level hard-blocking) isn't
        // shipped in this build — Standard (encrypted DNS) only. No mode picker,
        // so there's no way to switch into a mode whose datapath isn't built yet.
        if (store.config.strictModeAvailable) {
            Text("Protection level", style = MaterialTheme.typography.labelLarge)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.testTag("protection_mode_picker")) {
                ProtectionMode.values().forEach { m ->
                    FilterChip(
                        selected = store.mode == m,
                        onClick = { store.selectMode(m) },
                        label = { Text(m.title) },
                    )
                }
            }
        }
        Text(store.mode.summary, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.outline)
        if (!store.isProtected) {
            Button(
                onClick = onEnable,
                enabled = store.status != ProtectionStatus.CONNECTING,
                modifier = Modifier.fillMaxWidth().testTag("protection_enable"),
            ) {
                if (store.status == ProtectionStatus.CONNECTING) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    Spacer(Modifier.width(8.dp))
                }
                Text(if (store.mode == ProtectionMode.STRICT) "Turn on Strict mode" else "Turn on filtering")
            }
        }
    }
}

/** Always-on/lockdown guidance: a documented deep link to the system VPN
 *  settings (guardrail 6), where the recovery audience can make the tunnel
 *  harder to disable. Guidance only — the app can't set these itself. */
@Composable
private fun ReliabilitySection(context: android.content.Context) {
    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Make it stick", style = MaterialTheme.typography.labelLarge)
        Text(
            "For the strongest protection, turn on \"Always-on VPN\" and \"Block connections without VPN\" for this app in system settings.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.outline,
        )
        TextButton(
            onClick = {
                runCatching {
                    context.startActivity(Intent(Settings.ACTION_VPN_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                }
            },
            modifier = Modifier.testTag("protection_open_vpn_settings"),
        ) { Text("Open VPN settings") }
    }
}
