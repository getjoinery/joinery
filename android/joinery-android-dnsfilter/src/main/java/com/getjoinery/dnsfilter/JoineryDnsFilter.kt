package com.getjoinery.dnsfilter

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.getjoinery.android.NativeScreenRegistry

/**
 * Module entry point: call once at app launch to make the DNS-filtering native
 * screens available. The server flips each `dns_filtering` menu entry to
 * `{type: "native", screen: "dns_protection" | "dns_devices"}`; builds without
 * this module keep loading the matching `/profile/dns_filtering/…` web page via
 * the entry's fallback URL. Mirrors joinery-android-member's JoineryMember.
 *
 * Screen names (matched against `amu_native_screen`, live since plugin.json
 * 1.1.2 shipped with the iOS app):
 *   `dns_protection` → ProtectionScreen (this phone's activation + mode)
 *   `dns_devices`    → DevicesScreen (per-device always-on policy editor)
 */
object JoineryDnsFilter {
    private val SCREENS = listOf("dns_protection", "dns_devices")

    /** Register the native DNS screens. The app passes its [DnsFilterConfig]
     *  (deployment origin, brand name, strict-mode availability) so the
     *  activation layer is brand-neutral in the kit. */
    fun registerScreens(config: DnsFilterConfig) {
        NativeScreenRegistry.register("dns_protection") { ctx ->
            ProtectionScreen(ctx.session.client, config, onBack = ctx.onExit)
        }
        NativeScreenRegistry.register("dns_devices") { ctx ->
            DevicesScreen(ctx.session.client, ctx.web, onBack = ctx.onExit)
        }
    }

    /** Remove the DNS screens so their entries resolve to the web fallback. */
    fun unregisterScreens() {
        SCREENS.forEach { NativeScreenRegistry.unregister(it) }
    }
}

// MARK: - Shared UI helpers

/** A top app bar with an optional back arrow, shown with an arrow when pushed
 *  from another screen, without one when reached as a navigation root. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun DnsTopBar(title: String, onBack: (() -> Unit)?, actions: @Composable () -> Unit = {}) {
    TopAppBar(
        title = { Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
        navigationIcon = {
            if (onBack != null) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                }
            }
        },
        actions = { actions() },
    )
}

/** The standard retry-on-failure box shared by every DNS screen. */
@Composable
internal fun DnsRetryBox(
    message: String,
    errorTag: String,
    retryTag: String,
    modifier: Modifier = Modifier,
    onRetry: () -> Unit,
) {
    Box(modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                message,
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.testTag(errorTag),
            )
            Button(onClick = onRetry, modifier = Modifier.testTag(retryTag)) { Text("Try Again") }
        }
    }
}
