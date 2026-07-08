package com.getjoinery.dnsfilter

import android.content.Context
import android.content.Intent
import android.net.VpnService
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** Where this phone's protection stands. Derived only from real service
 *  lifecycle transitions — no state the service can't actually reach
 *  (guardrail 7). */
enum class ProtectionStatus { OFF, CONNECTING, ON }

/**
 * Process-wide protection state and the persisted "should be running"
 * configuration. The VpnService runs in the app's main process, so a shared
 * [StateFlow] is all the UI needs to observe the tunnel; the persisted config
 * is what the boot receiver re-establishes after a restart.
 */
object DnsFilterState {
    private val _status = MutableStateFlow(ProtectionStatus.OFF)
    val status: StateFlow<ProtectionStatus> = _status.asStateFlow()

    internal fun setStatus(status: ProtectionStatus) { _status.value = status }

    private const val PREFS = "scrolldaddy.vpn"
    private const val KEY_RUNNING = "running"
    private const val KEY_DOH = "doh_url"
    private const val KEY_MODE = "mode"
    private const val KEY_BRAND = "brand"
    private const val KEY_HARDBLOCKS = "hard_block_hostnames"

    /** The tunnel configuration the app last asked for. Persisted so a reboot
     *  can restore it (acceptance item 6). */
    data class Config(
        val dohUrl: String,
        val mode: ProtectionMode,
        val brandName: String,
        val hardBlockHostnames: List<String>,
    )

    fun saveConfig(context: Context, config: Config, running: Boolean) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putBoolean(KEY_RUNNING, running)
            .putString(KEY_DOH, config.dohUrl)
            .putString(KEY_MODE, config.mode.slug)
            .putString(KEY_BRAND, config.brandName)
            .putStringSet(KEY_HARDBLOCKS, config.hardBlockHostnames.toSet())
            .apply()
    }

    fun setRunning(context: Context, running: Boolean) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putBoolean(KEY_RUNNING, running).apply()
    }

    fun savedConfig(context: Context): Config? {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val doh = prefs.getString(KEY_DOH, null) ?: return null
        val mode = if (prefs.getString(KEY_MODE, "standard") == "strict") ProtectionMode.STRICT else ProtectionMode.STANDARD
        return Config(
            dohUrl = doh,
            mode = mode,
            brandName = prefs.getString(KEY_BRAND, "") ?: "",
            hardBlockHostnames = prefs.getStringSet(KEY_HARDBLOCKS, emptySet())?.toList() ?: emptyList(),
        )
    }

    fun shouldBeRunning(context: Context): Boolean =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(KEY_RUNNING, false)
}

/**
 * The app's handle on the filtering tunnel: consent, start, and stop. Every
 * mode is one VpnService; [start] hands it the resolver URL and (for strict)
 * the hard-block list, and switching modes reconfigures the same service in
 * place rather than tearing protection down (guardrail 4).
 */
object VpnController {
    val status: StateFlow<ProtectionStatus> get() = DnsFilterState.status

    /** Null when consent is already granted; otherwise the system VPN consent
     *  intent to launch. A single in-app dialog — no Settings trip. */
    fun consentIntent(context: Context): Intent? = VpnService.prepare(context)

    /** Establish (or reconfigure) the tunnel. Persists the config so a reboot
     *  can restore it. */
    fun start(context: Context, config: DnsFilterState.Config) {
        DnsFilterState.saveConfig(context, config, running = true)
        DnsFilterState.setStatus(ProtectionStatus.CONNECTING)
        val intent = Intent(context, DnsFilterVpnService::class.java).apply {
            action = DnsFilterVpnService.ACTION_START
            putExtra(DnsFilterVpnService.EXTRA_DOH_URL, config.dohUrl)
            putExtra(DnsFilterVpnService.EXTRA_MODE, config.mode.slug)
            putExtra(DnsFilterVpnService.EXTRA_BRAND, config.brandName)
            putExtra(DnsFilterVpnService.EXTRA_HARDBLOCKS, config.hardBlockHostnames.toTypedArray())
        }
        androidx.core.content.ContextCompat.startForegroundService(context, intent)
    }

    /** Tear the tunnel down. Android restores the network's original DNS
     *  immediately (nothing to restore). */
    fun stop(context: Context) {
        DnsFilterState.setRunning(context, false)
        val intent = Intent(context, DnsFilterVpnService::class.java).apply {
            action = DnsFilterVpnService.ACTION_STOP
        }
        androidx.core.content.ContextCompat.startForegroundService(context, intent)
    }
}
