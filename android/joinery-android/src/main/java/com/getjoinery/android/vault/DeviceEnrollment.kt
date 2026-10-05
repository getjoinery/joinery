package com.getjoinery.android.vault

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue

/**
 * `vault_client_probe` for one client-custody scope: whether the vault exists,
 * its keys, and whether the server still lists this phone as holding it.
 */
data class VaultProbe(
    val setUp: Boolean,
    val publicKey: String?,
    val keyGeneration: Int,
    val pendingPublicKey: String?,
    /** Null when the server predates the field; then only the key check runs. */
    val heldByThisDevice: Boolean?,
) {
    /** The keys a secret this phone holds may legitimately be: current, or the
     *  pending one of a rotation in progress. */
    val acceptedKeys: List<String> get() = listOfNotNull(publicKey, pendingPublicKey)

    enum class Verdict {
        /** The phone's key is current: keep it. */
        KEEP,
        /** The phone holds a key the server retired or no longer lists for this
         *  phone (a rotation, a recovery-code use, an unlink): wipe it and ask to
         *  be enrolled again (B6). */
        WIPE,
        /** The phone holds nothing and the vault exists: offer Enroll. */
        ENROLL,
        /** No vault yet: nothing to hold. */
        NOT_SET_UP,
    }

    fun verdict(phoneHolds: Boolean, phonePublicKey: String?): Verdict {
        if (!setUp) return if (phoneHolds) Verdict.WIPE else Verdict.NOT_SET_UP
        if (!phoneHolds) return Verdict.ENROLL
        if (heldByThisDevice == false) return Verdict.WIPE
        if (phonePublicKey == null || phonePublicKey !in acceptedKeys) return Verdict.WIPE
        return Verdict.KEEP
    }

    companion object {
        fun from(data: JsonValue?): VaultProbe? {
            if (data == null) return null
            fun str(k: String) = data[k]?.takeUnless { it.isNull }?.stringValue?.takeIf { it.isNotEmpty() }
            return VaultProbe(
                setUp = data["set_up"]?.boolValue ?: false,
                publicKey = str("public_key"),
                keyGeneration = data["key_generation"]?.intValue ?: 0,
                pendingPublicKey = str("pending_public_key"),
                heldByThisDevice = data["held_by_this_device"]?.takeUnless { it.isNull }?.boolValue,
            )
        }
    }
}

/**
 * Handing this phone a vault's key (specs/fortress_mobile_apps.md § R2): the
 * phone attaches its device key to the session key it already signed in with
 * (`device_key_enroll`), the person approves on a computer where the vault is
 * open (the page seals the chosen vaults' secrets to the device key), and the
 * phone collects the sealed keys from the device-link poll and opens them.
 * Nothing new is minted: the phone stays one credential, one Security-page row.
 */
class DeviceEnrollment(
    private val client: ApiClient,
    private val keys: DeviceKeys,
    private val deviceName: String,
    private val platform: String = "android",
) {
    data class Ticket(
        val linkCode: String,
        val verifyUrl: String,
        val pollToken: String,
        val expiresTime: String,
        val pollAfterSeconds: Int,
        val deviceId: Int?,
    ) {
        companion object {
            fun from(data: JsonValue?): Ticket? {
                if (data == null) return null
                val code = data["link_code"]?.stringValue ?: return null
                val token = data["poll_token"]?.stringValue ?: return null
                return Ticket(
                    linkCode = code,
                    verifyUrl = data["verify_url"]?.stringValue ?: "",
                    pollToken = token,
                    expiresTime = data["expires_time"]?.stringValue ?: "",
                    pollAfterSeconds = (data["poll_after"]?.intValue ?: 3).coerceIn(1, 30),
                    deviceId = data["device_id"]?.intValue,
                )
            }
        }
    }

    sealed class Poll {
        data class Pending(val afterSeconds: Int) : Poll()
        object Denied : Poll()
        data class Approved(val sealedKeys: Map<String, String>, val deviceId: Int?) : Poll()
        /** Expired, unknown, or already claimed: start again. */
        data class Over(val message: String) : Poll()

        companion object {
            fun from(data: JsonValue?): Poll {
                if (data == null) return Over("The server returned an unexpected response.")
                return when (data["status"]?.stringValue) {
                    "pending" -> Pending((data["poll_after"]?.intValue ?: 3).coerceIn(1, 30))
                    "denied" -> Denied
                    "approved" -> {
                        val sealed = LinkedHashMap<String, String>()
                        data["sealed_vault_keys"]?.objectValue?.forEach { (scope, blob) ->
                            blob.stringValue?.let { sealed[scope] = it }
                        }
                        Approved(sealed, data["device_id"]?.intValue)
                    }
                    else -> Over("The server returned an unexpected response.")
                }
            }
        }
    }

    /**
     * Open an enrollment. Generates the device key on first use, which writes
     * a secret, so the biometric window must be open when the phone has none.
     */
    suspend fun begin(): Ticket {
        val pub = keys.ensureDeviceKey()
        val envelope = client.submitAction(
            "device_key_enroll",
            JsonValue.obj(
                "device_pubkey" to JsonValue.Str(pub),
                "platform" to JsonValue.Str(platform),
                "device_name" to JsonValue.Str(deviceName),
            ),
        )
        return Ticket.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun poll(ticket: Ticket): Poll = try {
        val envelope = client.request("POST", "/api/v1/auth/device_link/${ticket.pollToken}", authenticated = false)
        Poll.from(envelope["data"])
    } catch (e: JoineryApiError.Authentication) {
        // 404 expired or unknown, 409 already claimed.
        Poll.Over(if (e.status == 409) "This code was already used. Start again." else "This code has expired. Start again.")
    }

    /**
     * Keep what the approval handed over. Opens [scope]'s blob with the device
     * secret (held since [begin], or loaded after a prompt), checks it is the
     * vault's current or pending key, and stores it behind the gate. Returns
     * the scopes kept.
     */
    suspend fun accept(approved: Poll.Approved, scopes: List<String> = listOf("mail")): List<String> {
        val kept = ArrayList<String>()
        for (scope in scopes) {
            val blob = approved.sealedKeys[scope] ?: continue
            val probe = probe(client, scope)
            keys.acceptHandoff(scope, blob, probe?.acceptedKeys ?: emptyList())
            kept.add(scope)
        }
        return kept
    }

    companion object {
        suspend fun probe(client: ApiClient, scope: String): VaultProbe? {
            val envelope = client.submitAction("vault_client_probe", JsonValue.obj("scope" to JsonValue.Str(scope)))
            return VaultProbe.from(envelope["data"])
        }

        /**
         * The launch and foreground check (B6): ask the server about [scope] and
         * wipe a key it retired or no longer lists for this phone. Returns the
         * verdict; network failures keep what the phone holds.
         */
        suspend fun checkHeld(client: ApiClient, keys: DeviceKeys, scope: String): VaultProbe.Verdict? {
            val probe = try { probe(client, scope) } catch (e: Exception) { return null } ?: return null
            val verdict = probe.verdict(keys.holds(scope), keys.scopePublicKey(scope))
            if (verdict == VaultProbe.Verdict.WIPE) keys.forgetScope(scope)
            return verdict
        }
    }
}
