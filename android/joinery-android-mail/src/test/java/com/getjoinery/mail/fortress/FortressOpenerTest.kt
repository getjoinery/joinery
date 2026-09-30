package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.DeviceKeys
import com.getjoinery.android.vault.HeldKeys
import com.getjoinery.android.vault.MemoryKeyValueStore
import com.getjoinery.android.vault.MemorySecretStore
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.MailThread
import com.getjoinery.mail.MailboxHome
import com.getjoinery.mail.ThreadPage
import com.getjoinery.mail.Vectors
import com.getjoinery.mail.str
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Reading end-to-end mail on the phone (specs/fortress_mobile_apps.md § R4),
 * over envelopes captured verbatim from dev by the lead's phone-flow test
 * (plugins/mailbox/tests/fixtures/fortress_api/) and the throwaway key that
 * opens them.
 */
class FortressOpenerTest {
    private val keys = Vectors.json("fortress_api/fortress_capture_keys.json")

    private fun data(name: String) = Vectors.json("fortress_api/$name.json")["data"]

    /** A phone that was handed the capture's mail key (through a handoff). */
    private fun phone(holdKey: Boolean = true, open: Boolean = true): Pair<DeviceKeys, FortressOpener> {
        val k = DeviceKeys(MemorySecretStore(), MemoryKeyValueStore(), HeldKeys())
        if (holdKey) {
            val pkcs8 = VaultCrypto.pkcs8Encode(VaultCrypto.b64decode(keys.str("mail_secret_b64")))
            val sealed = VaultCrypto.sealToPublicKey(pkcs8, k.ensureDeviceKey())
            assertEquals(keys.str("mail_public_b64"), k.acceptHandoff("mail", sealed, listOf(keys.str("mail_public_b64"))))
            if (!open) k.held.dropAll()
        }
        val stored = keys.str("pdf_stored").toByteArray()
        return k to FortressOpener(k) { url -> if (url.contains("part-2")) stored else error("unexpected fetch $url") }
    }

    @Test
    fun mailboxesSayFortress() {
        val home = MailboxHome.from(data("fortress_mailboxes"))!!
        assertTrue(home.mailboxes.any { it.isFortress })
    }

    @Test
    fun listRowsOpen() {
        val page = ThreadPage.from(data("fortress_thread_list"))!!
        assertTrue(page.fortress)
        val row = page.threads.first { it.sealed != null }
        assertEquals("", row.subject)
        val (_, opener) = phone()
        val opened = opener.openSummary(row)
        assertNull(opened.fortressNote)
        assertEquals(keys.str("subject"), opened.subject)
        assertTrue(opened.sender.isNotEmpty())
    }

    @Test
    fun withoutTheKeyRowsSaySo() {
        val row = ThreadPage.from(data("fortress_thread_list"))!!.threads.first { it.sealed != null }
        val (_, none) = phone(holdKey = false)
        assertEquals(FortressOpener.NO_KEY_NOTE, none.openSummary(row).fortressNote)
        val (_, locked) = phone(open = false)
        assertEquals(FortressOpener.LOCKED_NOTE, locked.openSummary(row).fortressNote)
    }

    @Test
    fun threadOpensAndPartsAreNamedAndOpen() = runBlocking {
        val thread = MailThread.from(data("fortress_thread"))!!
        val m = thread.messages.first { it.id == keys["message_id"]!!.intValue }
        assertTrue(m.fortress)
        assertNotNull(m.sealed)
        val (_, opener) = phone()
        val o = opener.openMessage(m)
        assertNull(o.fortressNote)
        assertEquals(keys.str("subject"), o.subject)
        assertTrue(o.bodyPlain.isNotEmpty())
        // Inline parts are carried apart; regular ones are named from the manifest.
        assertTrue(o.attachments.none { it.inline })
        val pdf = o.attachments.first { it.mimePart == keys.str("pdf_mime_part") }
        assertTrue(pdf.fortress)
        assertEquals("mail:${m.id}:att:2", pdf.partAd)
        assertFalse(pdf.filename == "attachment")
        val bytes = opener.partBytes(pdf)
        assertEquals(keys.str("pdf_plain"), String(bytes, Charsets.UTF_8))
    }

    @Test
    fun aLockWipesRowKeys() = runBlocking {
        val thread = MailThread.from(data("fortress_thread"))!!
        val m = thread.messages.first { it.sealed != null }
        val (k, opener) = phone()
        val o = opener.openMessage(m)
        val pdf = o.attachments.first { it.mimePart == "2" }
        k.held.dropAll()
        var refused = false
        try { opener.partBytes(pdf) } catch (e: Exception) { refused = true }
        assertTrue(refused)
        assertEquals(FortressOpener.LOCKED_NOTE, opener.openMessage(m).fortressNote)
    }

    @Test
    fun storedPartOpensUnderItsAdOnly() {
        val dek = VaultCrypto.randomBytes(32)
        val stored = FortressOpener.sealPart(byteArrayOf(1, 2, 3), dek, "mail:9:att:2")
        assertArrayEquals(byteArrayOf(1, 2, 3), FortressOpener.openStoredPart(stored.toByteArray(), dek, "mail:9:att:2"))
        var refused = false
        try { FortressOpener.openStoredPart(stored.toByteArray(), dek, "mail:9:att:3") } catch (e: Exception) { refused = true }
        assertTrue(refused)
    }

    @Test
    fun cidRewrite() {
        val html = "<img src=\"cid:logo@example.com\"><img src='cid:%3Cother%3E'><img src=cid:missing>"
        val out = FortressOpener.rewriteCids(html, mapOf("logo@example.com" to "data:image/png;base64,AA", "other" to "data:x"))
        assertEquals("<img src=\"data:image/png;base64,AA\"><img src='data:x'><img src=cid:missing>", out)
    }

    @Test
    fun edgeSealScopeIsChecked() {
        assertEquals("abc", FortressOpener.stripEdgeSeal("v1.edgeseal.mail.abc", "mail"))
        var refused = false
        try { FortressOpener.stripEdgeSeal("v1.edgeseal.drive.abc", "mail") } catch (e: Exception) { refused = true }
        assertTrue(refused)
    }

    @Test
    fun deviceHitsVector() {
        val v = Vectors.json("device_hits_vector.json")
        val ids = v["ids"]!!.arrayValue!!.map { it.doubleValue!!.toLong() }
        assertEquals(v.str("packed"), com.getjoinery.mail.DeviceHits.pack(ids))
    }

    @Test
    fun relayPinVector() {
        val v = Vectors.json("relay_pin_vector.json")
        // The pin MAC is under the secret as the browser's session and the
        // phone both hold it: PKCS#8.
        val pkcs8 = VaultCrypto.pkcs8Encode(VaultCrypto.hex(v.str("vault_secret_hex")))
        val alias = v["alias_id"]!!.intValue!!
        val identity = v.str("relay_identity_public_key")
        val mac = VaultCrypto.macFromSecret(pkcs8, "sealed-vault:pin", RelayPinCheck.pinMessage(alias, identity))
        assertEquals(v.str("pin_mac"), VaultCrypto.b64encode(mac))

        val statement = JsonValue.parse(v.str("statement"))
        val relayAnswer = JsonValue.obj("statement" to JsonValue.Str(v.str("statement")), "signature" to JsonValue.Str(v.str("signature"))).encoded()
        val answer = JsonValue.obj(
            "relay_answer" to JsonValue.Str(relayAnswer),
            "relay_identity_public_key" to JsonValue.Str(identity),
            "pin" to JsonValue.obj("relay_identity_public_key" to JsonValue.Str(identity), "mac" to JsonValue.Str(v.str("pin_mac"))),
        )
        val myKey = statement.str("public_key")
        val macFn: (ByteArray) -> ByteArray = { VaultCrypto.macFromSecret(pkcs8, "sealed-vault:pin", it) }
        val ok = RelayPinCheck.judge(answer, alias, statement.str("recipient"), macFn, listOf(myKey))
        assertTrue(ok.reason ?: "", ok.ok)
        // Another key: the relay seals to something this phone does not hold.
        assertEquals("key", RelayPinCheck.judge(answer, alias, statement.str("recipient"), macFn, listOf("other")).reason)
        // A pin the server wrote: its MAC is not this vault's.
        val forged = JsonValue.Obj(answer.pairs.map { if (it.first == "pin") "pin" to JsonValue.obj("relay_identity_public_key" to JsonValue.Str(identity), "mac" to JsonValue.Str(VaultCrypto.b64encode(ByteArray(32)))) else it })
        assertEquals("pin", RelayPinCheck.judge(forged, alias, statement.str("recipient"), macFn, listOf(myKey)).reason)
        // A changed signature byte.
        val sig = VaultCrypto.b64decode(v.str("signature")).also { it[0] = (it[0].toInt() xor 1).toByte() }
        val badAnswer = JsonValue.Obj(answer.pairs.map {
            if (it.first == "relay_answer") "relay_answer" to JsonValue.Str(JsonValue.obj("statement" to JsonValue.Str(v.str("statement")), "signature" to JsonValue.Str(VaultCrypto.b64encode(sig))).encoded()) else it
        })
        assertEquals("signature", RelayPinCheck.judge(badAnswer, alias, statement.str("recipient"), macFn, listOf(myKey)).reason)
    }
}
