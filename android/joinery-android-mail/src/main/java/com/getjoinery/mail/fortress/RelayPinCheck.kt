package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto

/**
 * The relay pin check (specs/fortress_mobile_apps.md § R7; the browser's
 * judgeSealTarget, client_custody_mail.md § R10): under Seal at the relay the
 * relay signs which key it seals a mailbox's mail to; the phone checks that
 * statement against the relay pinned for the mailbox (a pin only the vault's
 * secret can MAC) and against the key it holds. Once a relay is pinned — by a
 * browser's pin on the server, or this phone's own record — the server can
 * withhold the answer but not forge it. With neither, the first check trusts
 * the relay the server names (trust on first use, as the browser does and the
 * Fortress card says) and this phone records it. The phone verifies and
 * alarms; trusting a new relay needs a passkey step-up on a computer.
 */
object RelayPinCheck {
    private const val SEAL_TARGET_PREFIX = "joinery-relay:seal-target:v1\n"
    private const val PIN_PREFIX = "joinery-relay-pin:v1\n"

    data class Verdict(
        val ok: Boolean,
        val firstUse: Boolean = false,
        val identity: String? = null,
        val reason: String? = null,
        val expected: String? = null,
        val reported: String? = null,
    )

    fun pinMessage(aliasId: Int, identity: String): ByteArray = "$PIN_PREFIX$aliasId\n$identity".toByteArray(Charsets.UTF_8)

    private fun sameText(a: String?, b: String?): Boolean =
        VaultCrypto.constantTimeEquals((a ?: "").toByteArray(), (b ?: "").toByteArray())

    /**
     * [answer]: mailbox/relay_seal_target's data. [mac]: this vault's pin MAC.
     * [keys]: the public keys this phone worked out from secrets it holds.
     * [localPin]: the relay this phone pinned before, if the server lost it.
     */
    fun judge(
        answer: JsonValue,
        aliasId: Int,
        address: String,
        mac: (ByteArray) -> ByteArray,
        keys: List<String>,
        localPin: String? = null,
    ): Verdict {
        val relay = try { JsonValue.parse(answer["relay_answer"]?.stringValue ?: "") } catch (e: Exception) { null }
        val statementText = relay?.get("statement")?.stringValue
        val st = try { statementText?.let { JsonValue.parse(it) } } catch (e: Exception) { null }
        val signature = relay?.get("signature") as? JsonValue.Str
        if (relay == null || st == null || signature == null || statementText == null) return Verdict(false, reason = "unreadable")

        val stIdentity = st["relay_identity_public_key"]?.stringValue
        val pin = answer["pin"]?.takeUnless { it.isNull }
        val pinned: String
        var firstUse = false
        if (pin != null) {
            val pinIdentity = pin["relay_identity_public_key"]?.stringValue ?: ""
            val mine = sameText(VaultCrypto.b64encode(mac(pinMessage(aliasId, pinIdentity))), pin["mac"]?.stringValue)
            if (!mine) return Verdict(false, reason = "pin", expected = pinIdentity, reported = stIdentity)
            pinned = pinIdentity
        } else if (localPin != null) {
            // Pinned here before, and the server no longer has it: not a first use.
            pinned = localPin
        } else {
            pinned = answer["relay_identity_public_key"]?.stringValue ?: ""
            firstUse = true
        }
        val signed = (SEAL_TARGET_PREFIX + statementText).toByteArray(Charsets.UTF_8)
        val names = st["recipient"]?.stringValue == address.lowercase() &&
            st["key_scope"]?.stringValue == FortressOpener.SCOPE &&
            st["key_kind"]?.stringValue == "client" &&
            st["public_key"]?.stringValue in keys
        if (!sameText(stIdentity, pinned)) {
            return Verdict(false, reason = "identity", expected = pinned, reported = stIdentity, identity = stIdentity)
        }
        if (!VaultCrypto.verifyEd25519(pinned, signed, signature.value)) {
            return Verdict(false, reason = "signature", expected = pinned, reported = pinned)
        }
        if (!names) return Verdict(false, reason = "key", expected = keys.firstOrNull(), reported = st["public_key"]?.stringValue)
        return Verdict(true, firstUse = firstUse, identity = pinned)
    }

    val REASONS = mapOf(
        "pin" to "The relay this mailbox trusts was changed without your devices.",
        "identity" to "A different relay is answering for this mailbox than the one your devices trust.",
        "signature" to "The relay's answer is not signed by the relay your devices trust.",
        "key" to "The relay is sealing this mailbox's mail to a key that is not your vault's.",
        "unreadable" to "The relay's answer could not be read.",
    )

    /** A key's fingerprint for people: SHA-256, first 16 bytes, groups of four hex digits. */
    fun fingerprint(b64: String?): String = try {
        VaultCrypto.toHex(VaultCrypto.sha256(VaultCrypto.b64decode(b64 ?: "")).copyOf(16)).chunked(4).joinToString(" ")
    } catch (e: Exception) {
        "(unreadable)"
    }
}
