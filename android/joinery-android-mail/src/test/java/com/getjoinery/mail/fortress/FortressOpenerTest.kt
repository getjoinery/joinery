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
            val seed = VaultCrypto.b64decode(keys.str("mail_secret_b64"))
            val sealed = VaultCrypto.sealToPublicKey(seed, k.ensureDeviceKey())
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
        assertEquals("abc", FortressOpener.stripEdgeSeal("v2.edgeseal.mail.abc", "mail"))
        var refused = false
        try { FortressOpener.stripEdgeSeal("v2.edgeseal.drive.abc", "mail") } catch (e: Exception) { refused = true }
        assertTrue(refused)
    }

    @Test
    fun deviceHitsVector() {
        val v = Vectors.json("device_hits_vector.json")
        val ids = v["ids"]!!.arrayValue!!.map { it.doubleValue!!.toLong() }
        assertEquals(v.str("packed"), com.getjoinery.mail.DeviceHits.pack(ids))
    }

    @Test
    fun relayChangeWords() {
        val id = "beLRmH6k7NuV/nZUn/k7bG0xw022H71XCBnqdgjArS4="
        fun run(status: String, idk: String = id) = JsonValue.obj(
            "relay_id" to JsonValue.Num(4.0), "run_id" to JsonValue.Num(9.0), "status" to JsonValue.Str(status),
            "started_by" to JsonValue.Str("Pat Admin"), "started_time" to JsonValue.Str("2026-11-15T11:52:00Z"),
            "done_time" to JsonValue.Str("2026-11-15T12:07:00Z"), "identity_public_key" to JsonValue.Str(idk))
        val date: (String?) -> String = { it ?: "" }
        val done = RelayPinCheck.relayChangeText(id, run("done"), listOf("a@x.test", "b@x.test"), null, date)
        assertEquals("Your mail relay was updated", done.title)
        assertTrue(done.body.contains("ⓘ This site says your mail relay was rebuilt or upgraded"))
        assertFalse("the site's account is never a tick", done.body.lines().any { it.startsWith("✓") && it.contains("Pat Admin") })
        assertTrue(done.body.contains("If you do not, contact your site admin") && done.body.contains("by Pat Admin"))
        assertTrue(done.body.contains("a@x.test, b@x.test") && done.body.contains("open your mail on a computer"))
        assertEquals("Your mail relay is being updated", RelayPinCheck.relayChangeText(id, run("booting"), listOf("a"), null, date).title)
        assertEquals("Your mail relay update did not finish", RelayPinCheck.relayChangeText(id, run("failed"), listOf("a"), null, date).title)
        val none = RelayPinCheck.relayChangeText(id, null, listOf("a"), null, date)
        val other = RelayPinCheck.relayChangeText(id, run("done", "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="), listOf("a"), null, date)
        assertEquals("Your mail relay changed without an update", none.title)
        assertEquals(none.title, other.title)
        assertEquals("the last good check never changes the words' state", done.title,
            RelayPinCheck.relayChangeText(id, run("done"), listOf("a"), 1_700_000_000_000L, date).title)
        val pin = RelayPinCheck.alarmText("pin", "a@x.test")
        assertEquals("Your mail protection was changed without your devices", pin.title)
        assertTrue(pin.body.contains("Show this message to your site admin"))
    }

    @Test
    fun relayPinVector() {
        val v = Vectors.json("relay_pin_vector.json")
        // The pin MAC is under the secret's bytes as the browser's session and
        // the phone both hold them. The vector's secret is a pre-X-Wing PKCS#8;
        // the MAC takes any bytes.
        val pkcs8 = VaultCrypto.hex("302e020100300506032b656e04220420" + v.str("vault_secret_hex"))
        val alias = v["alias_id"]!!.intValue!!
        val identity = v.str("relay_identity_public_key")
        val mac = VaultCrypto.macFromSecret(pkcs8, "sealed-vault:pin", RelayPinCheck.pinMessage(alias, identity))
        assertEquals(v.str("pin_mac"), VaultCrypto.b64encode(mac))

        val macFn: (ByteArray) -> ByteArray = { VaultCrypto.macFromSecret(pkcs8, "sealed-vault:pin", it) }
        fun answerOf(statement: String, signature: String, id: String) = JsonValue.obj(
            "relay_answer" to JsonValue.Str(JsonValue.obj("statement" to JsonValue.Str(statement), "signature" to JsonValue.Str(signature)).encoded()),
            "relay_identity_public_key" to JsonValue.Str(id),
            "pin" to JsonValue.obj("relay_identity_public_key" to JsonValue.Str(id),
                "mac" to JsonValue.Str(VaultCrypto.b64encode(macFn(RelayPinCheck.pinMessage(alias, id))))),
        )
        val myKey = JsonValue.parse(v.str("statement")).str("public_key")
        val address = JsonValue.parse(v.str("statement")).str("recipient")

        // The relay signs this check's challenge back (challenge_vector).
        val cv = v["challenge_vector"]!!
        val challenge = cv.str("challenge")
        val answer = answerOf(cv.str("statement"), cv.str("signature"), cv.str("relay_identity_public_key"))
        val ok = RelayPinCheck.judge(answer, alias, address, macFn, listOf(myKey), challenge)
        assertTrue(ok.reason ?: "", ok.ok)
        // With no pin anywhere, it passes as a first use.
        val unpinned = JsonValue.Obj(answer.pairs.map { if (it.first == "pin") "pin" to JsonValue.Null else it })
        assertTrue(RelayPinCheck.judge(unpinned, alias, address, macFn, listOf(myKey), challenge).firstUse)
        // An answer carrying another check's challenge, or none, is an old one shown again (B4).
        assertEquals("replayed", RelayPinCheck.judge(answer, alias, address, macFn, listOf(myKey), "zzzzzzzzzzzzzzzzzzzzzz").reason)
        val old = answerOf(v.str("statement"), v.str("signature"), identity)
        assertEquals("replayed", RelayPinCheck.judge(old, alias, address, macFn, listOf(myKey), challenge).reason)
        // Another key: the relay seals to something this phone does not hold.
        assertEquals("key", RelayPinCheck.judge(answer, alias, address, macFn, listOf("other"), challenge).reason)
        // A pin the server wrote: its MAC is not this vault's.
        val forged = JsonValue.Obj(answer.pairs.map { if (it.first == "pin") "pin" to JsonValue.obj("relay_identity_public_key" to JsonValue.Str(cv.str("relay_identity_public_key")), "mac" to JsonValue.Str(VaultCrypto.b64encode(ByteArray(32)))) else it })
        assertEquals("pin", RelayPinCheck.judge(forged, alias, address, macFn, listOf(myKey), challenge).reason)
        // A changed signature byte.
        val sig = VaultCrypto.b64decode(cv.str("signature")).also { it[0] = (it[0].toInt() xor 1).toByte() }
        val bad = answerOf(cv.str("statement"), VaultCrypto.b64encode(sig), cv.str("relay_identity_public_key"))
        assertEquals("signature", RelayPinCheck.judge(bad, alias, address, macFn, listOf(myKey), challenge).reason)
        // A pin this vault made for another relay: the answering relay is new.
        val elsewhere = VaultCrypto.b64encode(ByteArray(32))
        val moved = JsonValue.Obj(answer.pairs.map { if (it.first == "pin") "pin" to JsonValue.obj(
            "relay_identity_public_key" to JsonValue.Str(elsewhere),
            "mac" to JsonValue.Str(VaultCrypto.b64encode(macFn(RelayPinCheck.pinMessage(alias, elsewhere))))) else it })
        val movedOk = RelayPinCheck.judge(moved, alias, address, macFn, listOf(myKey), challenge)
        assertEquals("identity", movedOk.reason)
        assertTrue("a new relay that signed this check and names this key passes the phone's check", movedOk.approvable)
        assertFalse("one signed for another check does not",
            RelayPinCheck.judge(moved, alias, address, macFn, listOf(myKey), "zzzzzzzzzzzzzzzzzzzzzz").approvable)
        assertFalse("nor one naming another key", RelayPinCheck.judge(moved, alias, address, macFn, listOf("other"), challenge).approvable)

        // A challenge is 24 base64url characters, fresh each time.
        val a = RelayPinCheck.newChallenge()
        assertTrue(a, Regex("^[A-Za-z0-9_-]{24}$").matches(a))
        assertTrue(a != RelayPinCheck.newChallenge())
    }
}
