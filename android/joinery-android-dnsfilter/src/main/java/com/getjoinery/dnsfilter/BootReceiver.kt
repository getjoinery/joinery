package com.getjoinery.dnsfilter

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.net.VpnService

/**
 * Re-establishes the tunnel after a reboot or an app update, when the user had
 * protection on (acceptance item 6). Only restarts if consent is still granted
 * (VpnService.prepare returns null) — a reboot never re-prompts for the VPN
 * dialog, so if consent was somehow cleared we stay off rather than fail
 * silently.
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val action = intent?.action ?: return
        if (action != Intent.ACTION_BOOT_COMPLETED && action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        if (!DnsFilterState.shouldBeRunning(context)) return
        if (VpnService.prepare(context) != null) return // consent no longer granted
        val config = DnsFilterState.savedConfig(context) ?: return
        VpnController.start(context, config)
    }
}
