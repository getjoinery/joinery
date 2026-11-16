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
 * Fortress card says) and this phone records it. Each check sends a one-time
 * challenge the relay signs into the statement; an answer without it is an
 * old one shown again and is refused. The phone verifies and alarms; trusting
 * a new relay needs a passkey step-up on a computer.
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
        /** For reason "identity": the new relay signed this check's challenge and names this phone's key. */
        val approvable: Boolean = false,
    )

    fun pinMessage(aliasId: Int, identity: String): ByteArray = "$PIN_PREFIX$aliasId\n$identity".toByteArray(Charsets.UTF_8)

    private fun sameText(a: String?, b: String?): Boolean =
        VaultCrypto.constantTimeEquals((a ?: "").toByteArray(), (b ?: "").toByteArray())

    /**
     * [answer]: mailbox/relay_seal_target's data. [mac]: this vault's pin MAC.
     * [keys]: the public keys this phone worked out from secrets it holds.
     * [localPin]: the relay this phone pinned before, if the server lost it.
     * [challenge]: the one this check sent ([newChallenge]).
     */
    fun judge(
        answer: JsonValue,
        aliasId: Int,
        address: String,
        mac: (ByteArray) -> ByteArray,
        keys: List<String>,
        challenge: String,
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
        val signedChallenge = st["challenge"]?.stringValue ?: ""
        val current = signedChallenge.isNotEmpty() && challenge.isNotEmpty() && sameText(signedChallenge, challenge)
        if (!sameText(stIdentity, pinned)) {
            // A new relay: whether its own statement is signed by it, carries
            // this check's challenge and names this phone's key decides which
            // words the alarm uses. A phone never trusts it (a computer does).
            val selfSigned = stIdentity != null && try {
                VaultCrypto.verifyEd25519(stIdentity, signed, signature.value)
            } catch (e: Exception) { false }
            return Verdict(false, reason = "identity", expected = pinned, reported = stIdentity, identity = stIdentity,
                approvable = selfSigned && names && current)
        }
        if (!VaultCrypto.verifyEd25519(pinned, signed, signature.value)) {
            return Verdict(false, reason = "signature", expected = pinned, reported = pinned)
        }
        if (!current) {
            return Verdict(false, reason = "replayed", expected = pinned, reported = pinned)
        }
        if (!names) return Verdict(false, reason = "key", expected = keys.firstOrNull(), reported = st["public_key"]?.stringValue)
        return Verdict(true, firstUse = firstUse, identity = pinned)
    }

    private const val INTRO = "Your mail passes through a relay on its way to this site, and the relay seals it so only you can read it."
    private const val TO_TRUST = "To trust a new relay, open your mail on a computer: it asks you to confirm it is you."

    /** Every alarm but a new relay that passed this phone's check: what happened, what it can mean, what to do. */
    val ALARMS = mapOf(
        "pin" to ("Your mail protection was changed without your devices" to
            "Your devices keep a record, made with your own key, of which relay protects this mail. The record this site holds now was not made by any of your devices."),
        "identity" to ("A different relay is answering, and it did not pass this phone's check" to
            "A different relay is answering for this mailbox, and it did not sign this phone's one-time question, or it does not say it seals your mail to your own key."),
        "signature" to ("The relay's answer could not be confirmed" to
            "The answer that should come from your relay is not signed by it. Someone may be answering in its place."),
        "replayed" to ("The relay's answer was not made for this check" to
            "This phone asked your relay a one-time question, and the answer it got back was made for another one: an old answer shown again. That can hide a change to your relay."),
        "key" to ("Your mail is not being sealed to your key" to
            "Your relay says it seals mail to this address with a key that is not yours, so whoever holds that key could read new mail."),
        "unreadable" to ("This phone could not read the relay's answer" to
            "This can be a passing fault. If it keeps happening, tell your site admin."),
    )

    /** An alarm's title and text, as the computer's popup words them (specs/relay_update_trust_step.md R2). */
    data class AlarmText(val title: String, val body: String)

    fun alarmText(reason: String, address: String): AlarmText {
        val (title, what) = ALARMS[reason] ?: ALARMS.getValue("unreadable")
        val lines = mutableListOf("$INTRO $what")
        if (reason != "unreadable") lines += "This can mean that someone who controls this site is trying to read your mail."
        lines += "Mailbox: $address"
        lines += "What to do: do not trust anything new yet. Show this message to your site admin. Your existing mail is safe; this is about new mail."
        return AlarmText(title, lines.joinToString("\n\n"))
    }

    /**
     * A new relay that signed this phone's check and names its key. [update] is
     * the site's account (relay_seal_target's update), shown as what the site
     * says and never as a check; [lastSeen] when this phone last saw the old
     * relay answer as it should, shown for information only.
     */
    fun relayChangeText(identity: String, update: JsonValue?, addresses: List<String>, lastSeen: Long?, dateText: (String?) -> String): AlarmText {
        val u = update?.takeUnless { it.isNull }
        val named = u != null && sameText(u["identity_public_key"]?.stringValue, identity)
        val status = u?.get("status")?.stringValue ?: ""
        val by = u?.get("started_by")?.stringValue?.takeIf { it.isNotEmpty() }?.let { " by $it" } ?: ""
        val started = if (named) dateText(u?.get("started_time")?.stringValue) else ""
        val done = if (named) dateText(u?.get("done_time")?.stringValue) else ""
        val on = if (started.isNotEmpty()) " on $started" else ""
        val title: String
        val head = mutableListOf<String>()
        when {
            !named -> {
                title = "Your mail relay changed without an update"
                head += INTRO
                head += "A different relay is answering, and this site has no record of an update. This can mean that someone who controls this site is trying to read your mail."
            }
            status == "done" -> {
                title = "Your mail relay was updated"
                head += "ⓘ This site says your mail relay was rebuilt or upgraded" + (if (done.isNotEmpty()) " on $done" else on) + "$by, which gives it a new key."
            }
            status == "failed" -> {
                title = "Your mail relay update did not finish"
                head += "ⓘ This site says an update of your mail relay was started$on$by and did not finish."
            }
            else -> {
                title = "Your mail relay is being updated"
                head += "ⓘ This site says your mail relay is being rebuilt or upgraded (started$on$by), which gives it a new key."
            }
        }
        head += "✓ The new relay passed this phone's checks."
        if (lastSeen != null && !named) head += "This phone last confirmed your old relay on ${dateText(java.time.Instant.ofEpochMilli(lastSeen).toString())}."
        head += if (named) "If you know about this update, trust the new relay. If you do not, contact your site admin before you trust it."
            else "Contact your site admin before you trust it. Your existing mail is safe; this is about new mail."
        head += "New relay key: ${fingerprint(identity)}"
        head += "Mailboxes: " + addresses.joinToString(", ")
        head += TO_TRUST
        return AlarmText(title, head.joinToString("\n\n"))
    }

    /** A one-time challenge for one relay check: 18 random bytes, base64url (24 characters). */
    fun newChallenge(): String =
        VaultCrypto.b64encode(VaultCrypto.randomBytes(18)).replace('+', '-').replace('/', '_').trimEnd('=')

    /** A key's fingerprint for people: SHA-256, first 16 bytes, groups of four hex digits. */
    fun fingerprint(b64: String?): String = try {
        VaultCrypto.toHex(VaultCrypto.sha256(VaultCrypto.b64decode(b64 ?: "")).copyOf(16)).chunked(4).joinToString(" ")
    } catch (e: Exception) {
        "(unreadable)"
    }
}
