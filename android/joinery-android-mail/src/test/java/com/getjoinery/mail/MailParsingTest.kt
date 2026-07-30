package com.getjoinery.mail

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.Calendar
import java.util.TimeZone

/** Parsing tests over live `mailbox` API payloads captured from dev
 *  (the fixtures dir holds verbatim API envelopes — the same files backing
 *  the iOS JoineryMailKit tests, parity by construction). */
class MailParsingTest {

    private fun fixtureData(name: String): JsonValue {
        val stream = FixtureAnchor::class.java.classLoader!!
            .getResourceAsStream("fixtures/$name.json")
            ?: error("fixture not found: fixtures/$name.json")
        val envelope = JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
        return envelope["data"] ?: error("fixture $name has no data")
    }

    @Test
    fun mailboxesFixtureParses() {
        val home = MailboxHome.from(fixtureData("mailboxes"))!!
        assertTrue(home.canCompose)
        assertEquals(1, home.mailboxes.size)
        val box = home.mailboxes.first()
        assertEquals("appdev.phase2@dev.getjoinery.com", box.address)
        assertEquals("appdev.phase2", box.localPart)
        assertTrue(box.total > 0)
    }

    @Test
    fun mailboxesFixtureCarriesFolderRail() {
        val home = MailboxHome.from(fixtureData("mailboxes"))!!
        val box = home.mailboxes.first()
        assertEquals(listOf("deals", "test label"), box.folders.map { it.name })
        assertFalse(box.foldersExclusive)
    }

    @Test
    fun threadListFixtureParses() {
        val page = ThreadPage.from(fixtureData("thread_list"))!!
        assertTrue(page.threads.isNotEmpty())
        assertEquals(1, page.page)
        for (thread in page.threads) {
            assertTrue(thread.threadKey.isNotEmpty())
            assertTrue(thread.latestTime.isNotEmpty())
            assertEquals(thread.hasUnread, thread.unreadCount > 0)
        }
        val probe = page.threads.first { it.subject.startsWith("NativeMail Probe2") }
        assertTrue(probe.snippet.contains("NativeMailProbe2Body"))
    }

    @Test
    fun threadFixtureParsesSignedTransport() {
        val thread = MailThread.from(fixtureData("thread"))!!
        assertEquals(1, thread.messages.size)
        val message = thread.messages.first()
        assertEquals("inbound", message.direction)
        assertFalse(message.isOutbound)
        assertEquals(195, message.aliasId)
        assertEquals("NativeMailProbe2Body-1783038188", message.bodyPlain)

        // Inline cid: image was rewritten server-side to a signed URL.
        assertFalse(message.bodyHtml.contains("cid:"))
        assertTrue(message.bodyHtml.contains("/uploads/"))
        assertTrue(message.bodyHtml.contains("sig="))

        // The non-inline attachment carries its signed download URL.
        assertEquals(1, message.attachments.size)
        val attachment = message.attachments.first()
        assertEquals("probe.txt", attachment.filename)
        assertNotNull(attachment.url)
        assertTrue(attachment.url!!.contains("sig="))
        assertEquals("27 B", attachment.sizeLabel)
    }

    @Test
    fun attachmentWithoutFileBackingHasNullUrl() {
        val json = JsonValue.parse(
            """{"id": 5, "filename": "x.pdf", "content_type": "application/pdf", "size_bytes": 2048, "url": null}""",
        )
        val attachment = MailAttachment.from(json)!!
        assertNull(attachment.url)
        assertEquals("2 KB", attachment.sizeLabel)
    }

    @Test
    fun senderDisplayParsing() {
        assertEquals("Jane Doe", MailDisplay.senderName("Jane Doe <jane@example.com>"))
        // With no display name the sending organization is the label, not the local part.
        assertEquals("Example", MailDisplay.senderName("jane@example.com"))
        assertEquals("jane@example.com", MailDisplay.address("Jane Doe <jane@example.com>"))
        assertEquals("jane@example.com", MailDisplay.address("  jane@example.com "))
        // Stable avatar bucket, in range, blind to the display name.
        val a = MailDisplay.avatarColorIndex("Jane <jane@example.com>", 8)
        val b = MailDisplay.avatarColorIndex("jane@example.com", 8)
        assertEquals(a, b)
        assertTrue(a in 0 until 8)
    }

    /**
     * The label rules, mirroring the web reader's sender_name.mjs gate: the same
     * message must read the same way in the app and in the browser.
     */
    @Test
    fun senderLabelRules() {
        // A display name always wins, verbatim.
        assertEquals("Fireworks Team", MailDisplay.senderName("\"Fireworks Team\" <hello@fireworks.ai>"))
        assertEquals("iA Writer", MailDisplay.senderName("\"iA Writer\" <news@ia.net>"))

        // No name: the organization, not the local part.
        assertEquals("Fireworks", MailDisplay.senderName("hello@fireworks.ai"))
        assertEquals("Google", MailDisplay.senderName("no-reply@accounts.google.com"))
        assertEquals("E-Trade", MailDisplay.senderName("alerts@e-trade.com"))
        assertEquals("Example", MailDisplay.senderName("noreply@mail.notifications.example.co.uk"))
        assertEquals("Bundesbank", MailDisplay.senderName("info@bundesbank.de"))

        // Consumer providers: the person is the identity.
        assertEquals("Jeremy Tunnell", MailDisplay.senderName("jeremy.tunnell@gmail.com"))
        assertEquals("A B Cooper", MailDisplay.senderName("a.b.cooper@icloud.com"))
        assertEquals("Someone", MailDisplay.senderName("someone@proton.me"))

        // ...but only for what could be a person's mailbox.
        assertEquals("Proton", MailDisplay.senderName("no-reply@notify.proton.me"))
        assertEquals("Proton", MailDisplay.senderName("support@proton.me"))
        assertEquals("Americanexpress", MailDisplay.senderName("AmericanExpress-no-reply@alerts.americanexpress.com"))
        assertEquals("Info Smith", MailDisplay.senderName("info.smith@gmail.com"))

        // Degenerate input never crashes or shows an empty label.
        assertEquals("(unknown)", MailDisplay.senderName(""))
        assertEquals("garbage", MailDisplay.senderName("garbage"))
        assertEquals("@gmail.com", MailDisplay.senderName("@gmail.com"))

        // The host reducer.
        assertEquals("google", MailDisplay.orgLabel("accounts.google.com"))
        assertEquals("example", MailDisplay.orgLabel("example.co.uk"))
        assertEquals("localhost", MailDisplay.orgLabel("localhost"))
        assertTrue(MailDisplay.hasSubdomain("notify.proton.me"))
        assertFalse(MailDisplay.hasSubdomain("proton.me"))
        assertFalse(MailDisplay.hasSubdomain("example.co.uk"))
        assertTrue(MailDisplay.hasSubdomain("mail.example.co.uk"))
    }

    @Test
    fun dateStamps() {
        val date = MailDisplay.date("2026-07-03 00:23:08")
        assertNotNull(date)
        // DB times are UTC.
        val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC")).apply { time = date!! }
        assertEquals(0, cal.get(Calendar.HOUR_OF_DAY))
        assertTrue(MailDisplay.listStamp("2026-07-03 00:23:08").isNotEmpty())
        assertTrue(MailDisplay.messageStamp("2026-07-03 00:23:08").isNotEmpty())
        assertEquals("", MailDisplay.listStamp("garbage"))
        // Fractional-second suffixes parse via the 19-char prefix.
        assertNotNull(MailDisplay.date("2026-07-03 00:23:08.123456"))
    }
}

private class FixtureAnchor
