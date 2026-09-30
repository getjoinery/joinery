package com.getjoinery.mail.fortress

import android.content.Context
import android.os.Build
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.fragment.app.FragmentActivity
import com.getjoinery.android.ApiClient
import com.getjoinery.android.vault.BiometricGate
import com.getjoinery.android.vault.DeviceEnrollment
import com.getjoinery.android.vault.DeviceKeys
import com.getjoinery.android.vault.VaultProbe

/**
 * End-to-end mail on this phone, one per app process: the device keys, the
 * opener over them, and what the screens need to know — whether the phone
 * holds the mail key, whether it is open, and whether the server still counts
 * it as current (specs/fortress_mobile_apps.md § R2–R4, R9, R10).
 */
class FortressMail(val client: ApiClient, val keys: DeviceKeys) {
    val opener = FortressOpener(keys) { client.fetchBytes(it) }

    /** The last probe's verdict, null before one ran or when it failed. */
    var verdict by mutableStateOf<VaultProbe.Verdict?>(null)
        private set

    /** Bumps whenever a key is opened or dropped; read it to re-render. */
    val keyVersion: Int get() = keys.held.version

    val holdsKey: Boolean get() = keys.holds(FortressOpener.SCOPE)
    val isOpen: Boolean get() = keys.isOpen(FortressOpener.SCOPE)
    val lockEpoch: Int get() = keys.held.epoch

    /**
     * Ask the server whether the key this phone holds is still current, and
     * wipe it when not (B6). Runs at launch and on foreground while a Fortress
     * mailbox is in view.
     */
    /** The mail vault's key generation, as the last probe reported it. */
    @Volatile
    var keyGeneration: Int? = null
        private set

    /** Runs when the phone's mail key is wiped (retired, forgotten). */
    var onKeyWiped: (() -> Unit)? = null

    suspend fun checkHeld(): VaultProbe.Verdict? {
        val probe = try { DeviceEnrollment.probe(client, FortressOpener.SCOPE) } catch (e: Exception) { null } ?: return null
        keyGeneration = probe.keyGeneration
        val v = probe.verdict(keys.holds(FortressOpener.SCOPE), keys.scopePublicKey(FortressOpener.SCOPE))
        if (v == VaultProbe.Verdict.WIPE) keys.forgetScope(FortressOpener.SCOPE)
        verdict = v
        if (v == VaultProbe.Verdict.WIPE) {
            opener.wipe()
            onKeyWiped?.invoke()
        }
        return v
    }

    /** One biometric prompt, then every stored secret opens. Null on success. */
    suspend fun unlock(activity: FragmentActivity): String? =
        BiometricGate.unlock(activity, keys, "To read your end-to-end encrypted mail")

    fun lock() {
        keys.held.dropAll()
        opener.wipe()
    }

    /** "Remove keys from this phone": the mail key and the device key both. */
    fun forgetEverything() {
        keys.forgetEverything()
        opener.wipe()
        onKeyWiped?.invoke()
        verdict = VaultProbe.Verdict.ENROLL
    }

    fun enrollment(): DeviceEnrollment = DeviceEnrollment(client, keys, deviceName())

    companion object {
        @Volatile
        private var instance: FortressMail? = null

        fun get(context: Context, client: ApiClient): FortressMail {
            instance?.let { if (it.client === client) return it }
            synchronized(this) {
                instance?.let { if (it.client === client) return it }
                return FortressMail(client, DeviceKeys.shared(context)).also { instance = it }
            }
        }

        /** Sign-out: this session's opener goes, and its lock listener with it. */
        fun reset() {
            synchronized(this) {
                instance?.opener?.close()
                instance = null
            }
        }

        /** What the Security page names this phone ("Pixel 8, Android"). */
        fun deviceName(): String {
            val model = listOf(Build.MANUFACTURER, Build.MODEL)
                .filter { !it.isNullOrBlank() }
                .joinToString(" ")
                .ifEmpty { "Android phone" }
            return model.take(56)
        }
    }
}
