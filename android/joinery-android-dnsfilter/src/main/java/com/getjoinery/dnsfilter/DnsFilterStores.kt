package com.getjoinery.dnsfilter

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.JoineryApiError

/**
 * The brand-neutral configuration the DNS filter kit needs from the app: the
 * deployment origin (for per-deployment phone pinning), a display name for the
 * tunnel/notification, and whether Strict mode is offered.
 */
data class DnsFilterConfig(
    val baseUrl: String,
    val brandName: String,
    /**
     * Whether Strict mode (all-traffic tunnel + connection-level hard-blocking)
     * is offered. Off until that datapath exists end-to-end: the VpnService
     * routes only DNS and the SNI/hard-block engine isn't wired into it yet, so
     * offering Strict would let a tap change nothing (or, once wired wrong,
     * black-hole traffic). The app flips this true only in a release that ships
     * the strict datapath (guardrail 3).
     */
    val strictModeAvailable: Boolean = false,
)

/** Shared phase for the DNS screens (loading / loaded / failed). */
sealed class DnsPhase {
    object Loading : DnsPhase()
    object Loaded : DnsPhase()
    data class Failed(val message: String) : DnsPhase()
}

internal fun dnsDisplayMessage(e: Exception): String =
    (e as? JoineryApiError)?.displayMessage ?: (e.message ?: "Something went wrong.")

/**
 * Remembers which device row *is this phone*. A user manages several devices
 * from one account, but this app applies the tunnel only to the handset it runs
 * on, so it pins one `device_id` locally. Keyed by deployment origin so a build
 * pointed at a second deployment tracks its own.
 */
class PhoneDeviceStore(context: Context, baseUrl: String) {
    private val prefs = context.applicationContext.getSharedPreferences("scrolldaddy.phone", Context.MODE_PRIVATE)
    private val key = "phone_device_id." + (hostOf(baseUrl))

    var deviceId: Int?
        get() = prefs.getInt(key, 0).takeIf { it > 0 }
        set(value) {
            if (value != null && value > 0) prefs.edit().putInt(key, value).apply()
            else prefs.edit().remove(key).apply()
        }

    private companion object {
        fun hostOf(baseUrl: String): String = try {
            java.net.URI(baseUrl).host ?: baseUrl
        } catch (e: Exception) {
            baseUrl
        }
    }
}

/**
 * The status/home screen's state machine. Resolves *this phone's* device row,
 * drives the standard-mode VpnService, and keeps the pinned device fresh. The
 * live tunnel status is owned by [VpnController]; the screen feeds it in through
 * [updateStatus] so `isProtected` reflects the actual service.
 */
class ProtectionStore(
    val api: DnsFilterApi,
    val config: DnsFilterConfig,
    private val phoneStore: PhoneDeviceStore,
) {
    var phase by mutableStateOf<DnsPhase>(DnsPhase.Loading)
        private set
    var account by mutableStateOf<DnsAccountSummary?>(null)
        private set
    var phoneDevice by mutableStateOf<DnsDevice?>(null)
        private set
    var status by mutableStateOf(ProtectionStatus.OFF)
        private set
    var mode by mutableStateOf(ProtectionMode.STANDARD)
        private set
    var errorMessage by mutableStateOf<String?>(null)
    var isWorking by mutableStateOf(false)
        private set

    private var loadGeneration = 0

    /** "Protected" once the tunnel is up. Drives the big status banner. */
    val isProtected: Boolean get() = status == ProtectionStatus.ON
    val needsRegistration: Boolean get() = phoneDevice == null

    /** Fed from VpnController.status by the screen. */
    fun updateStatus(newStatus: ProtectionStatus) {
        status = newStatus
    }

    suspend fun load() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val devices = api.devices()
            val account = api.accountSummary()
            if (generation != loadGeneration) return
            this.account = account
            this.phoneDevice = resolvePhone(devices)
            phase = DnsPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is DnsPhase.Loaded) return
            phase = DnsPhase.Failed(dnsDisplayMessage(e))
        }
    }

    /** Match the persisted phone device_id against the fresh list; if the row
     *  was deleted server-side, forget it. */
    private fun resolvePhone(devices: List<DnsDevice>): DnsDevice? {
        val id = phoneStore.deviceId
        if (id != null) {
            val device = devices.firstOrNull { it.deviceId == id }
            if (device != null) return device
        }
        phoneStore.deviceId = null
        return null
    }

    // MARK: Onboarding — register this phone

    suspend fun registerThisPhone() {
        if (isWorking) return
        isWorking = true
        try {
            // Snapshot existing ids first: the account may already own devices
            // (laptop, other phones). Prefer the device the server returns from
            // the API-create contract; if a server predates it, pin the row that
            // *appeared* between snapshots — never a "newest id" heuristic, which
            // could claim another device as this phone (guardrail 1).
            val before = api.devices().map { it.deviceId }.toHashSet()
            val created = api.createDevice(
                name = defaultDeviceName(),
                deviceType = "phone",
                timezone = java.util.TimeZone.getDefault().id,
            )
            val newDevice = if (created != null && !before.contains(created.deviceId)) {
                created
            } else {
                val after = api.devices()
                after.filter { !before.contains(it.deviceId) }.maxByOrNull { it.deviceId }
            }
            if (newDevice == null) {
                errorMessage = "This phone could not be registered. Please try again."
                return
            }
            phoneStore.deviceId = newDevice.deviceId
            phoneDevice = newDevice
        } catch (e: Exception) {
            errorMessage = (e as? JoineryApiError)?.displayMessage ?: "Could not register this phone."
        } finally {
            isWorking = false
        }
    }

    private fun defaultDeviceName(): String = "My Phone"

    // MARK: Enable / disable

    fun selectMode(newMode: ProtectionMode) {
        mode = newMode
    }

    /** The tunnel configuration for the pinned phone, or null if not ready. */
    fun vpnConfig(): DnsFilterState.Config? {
        val device = phoneDevice ?: return null
        if (device.dohUrl.isEmpty()) return null
        return DnsFilterState.Config(
            dohUrl = device.dohUrl,
            mode = mode,
            brandName = config.brandName,
            hardBlockHostnames = device.hardBlockHostnames,
        )
    }

    /** Start (or reconfigure) protection. Consent is handled by the screen; this
     *  is called once consent is granted. */
    fun enable(context: Context) {
        val vpnConfig = vpnConfig()
        if (vpnConfig == null) {
            errorMessage = "This phone isn't registered with a resolver yet."
            return
        }
        VpnController.start(context, vpnConfig)
    }

    fun turnOff(context: Context) {
        VpnController.stop(context)
    }

    /** Re-read this phone's device (picking up a fresh hard-block list). Only
     *  Strict mode enforces that list in-tunnel, so a running standard tunnel
     *  needs no restart — the resolver applies server-side rule changes within
     *  its reload window. */
    suspend fun refreshDevice(context: Context) {
        val id = phoneDevice?.deviceId ?: return
        val refreshed = try {
            api.devices().firstOrNull { it.deviceId == id }
        } catch (e: Exception) {
            null
        } ?: return
        phoneDevice = refreshed
        if (status == ProtectionStatus.ON && mode == ProtectionMode.STRICT) {
            vpnConfig()?.let { VpnController.start(context, it) }
        }
    }
}

/**
 * Loads the account summary and device list. Backs both the device picker and
 * the protection screen's "this phone" resolution.
 */
class DeviceListStore(val api: DnsFilterApi) {
    var phase by mutableStateOf<DnsPhase>(DnsPhase.Loading)
        private set
    var devices by mutableStateOf<List<DnsDevice>>(emptyList())
        private set
    var account by mutableStateOf<DnsAccountSummary?>(null)
        private set

    private var loadGeneration = 0

    suspend fun load() {
        loadGeneration += 1
        val generation = loadGeneration
        try {
            val devices = api.devices()
            val account = api.accountSummary()
            if (generation != loadGeneration) return
            this.devices = devices
            this.account = account
            phase = DnsPhase.Loaded
        } catch (e: Exception) {
            if (generation != loadGeneration) return
            if (phase is DnsPhase.Loaded) return
            phase = DnsPhase.Failed(dnsDisplayMessage(e))
        }
    }
}

/**
 * State for the native always-on block editor: the catalog (cached), the
 * block's current Block/Allow state, and the custom domain rules. Category and
 * service toggles are save-on-change (`block_filter_set`); custom rules
 * add/delete iteratively. "Allow" submits as *removing the row* — the
 * resolver-merge invariant lives server-side, so the client sends the same
 * semantics.
 */
class BlockEditorStore(
    val api: DnsFilterApi,
    val account: DnsAccountSummary,
    val deviceId: Int,
    val blockId: Int,
) {
    var phase by mutableStateOf<DnsPhase>(DnsPhase.Loading)
        private set
    var blockedFilters by mutableStateOf<Set<String>>(emptySet())
        private set
    var blockedServices by mutableStateOf<Set<String>>(emptySet())
        private set
    var rules by mutableStateOf<List<DnsDomainRule>>(emptyList())
        private set
    var catalog by mutableStateOf<DnsCatalog?>(null)
        private set
    var errorMessage by mutableStateOf<String?>(null)
    var busyKeys by mutableStateOf<Set<String>>(emptySet())
        private set

    /** Fired after any change that can alter the hard-block hostname list, so
     *  the protection layer can re-sync a running strict-mode tunnel. */
    var onHardBlockChange: (() -> Unit)? = null

    suspend fun load() {
        try {
            val catalog = api.catalog()
            val contents = api.blockContents(deviceId, blockId)
            this.catalog = catalog
            blockedFilters = contents.filters.filterValues { it == 0 }.keys.toSet()
            blockedServices = contents.services.filterValues { it == 0 }.keys.toSet()
            rules = contents.rules
            phase = DnsPhase.Loaded
        } catch (e: Exception) {
            if (phase is DnsPhase.Loaded) return
            phase = DnsPhase.Failed(dnsDisplayMessage(e))
        }
    }

    fun isFilterBlocked(key: String) = blockedFilters.contains(key)
    fun isServiceBlocked(key: String) = blockedServices.contains(key)
    fun isBusy(key: String) = busyKeys.contains(key)

    suspend fun toggleFilter(key: String) {
        if (busyKeys.contains(key)) return
        val block = !blockedFilters.contains(key)
        busyKeys = busyKeys + key
        try {
            api.setFilter(blockId, key, if (block) 0 else null)
            blockedFilters = if (block) blockedFilters + key else blockedFilters - key
        } catch (e: Exception) {
            errorMessage = dnsDisplayMessage(e)
        } finally {
            busyKeys = busyKeys - key
        }
    }

    suspend fun toggleService(key: String) {
        if (busyKeys.contains(key)) return
        val block = !blockedServices.contains(key)
        busyKeys = busyKeys + key
        try {
            api.setService(blockId, key, if (block) 0 else null)
            blockedServices = if (block) blockedServices + key else blockedServices - key
        } catch (e: Exception) {
            errorMessage = dnsDisplayMessage(e)
        } finally {
            busyKeys = busyKeys - key
        }
    }

    suspend fun addRule(hostname: String, action: Int, hardBlock: Boolean) {
        val host = hostname.trim()
        if (host.isEmpty()) return
        try {
            val rule = api.addDomainRule(blockId, host, action, hardBlock)
            rules = rules + rule
            if (rule.hardBlock) onHardBlockChange?.invoke()
        } catch (e: Exception) {
            errorMessage = dnsDisplayMessage(e)
        }
    }

    suspend fun deleteRule(rule: DnsDomainRule) {
        try {
            api.deleteDomainRule(rule.ruleId)
            rules = rules.filter { it.ruleId != rule.ruleId }
            if (rule.hardBlock) onHardBlockChange?.invoke()
        } catch (e: Exception) {
            errorMessage = dnsDisplayMessage(e)
        }
    }

    /** Flip a rule's hard-block flag. The API has no in-place update, so
     *  re-create it (delete + add); the server re-validates the
     *  always-on/block-action constraint on the add. */
    suspend fun setHardBlock(rule: DnsDomainRule, hardBlock: Boolean) {
        if (rule.hardBlock == hardBlock) return
        try {
            api.deleteDomainRule(rule.ruleId)
            val replacement = api.addDomainRule(blockId, rule.hostname, rule.action, hardBlock)
            rules = rules.map { if (it.ruleId == rule.ruleId) replacement else it }
            onHardBlockChange?.invoke()
        } catch (e: Exception) {
            errorMessage = dnsDisplayMessage(e)
            load()
        }
    }
}
