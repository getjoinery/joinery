package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.Vectors
import com.getjoinery.mail.str
import org.junit.Assert.assertEquals
import org.junit.Test

/** The Kotlin MIME parser against mailbox_mime.js's own output
 *  (plugins/mailbox/tests/fixtures/mime/<name>.expected.json). */
class MimeParserTest {
    private val files = listOf(
        "alternative", "bare_lf", "malformed", "nested_inline_attachment", "plain", "qp_latin1", "rfc2047_subject", "rfc2231_filename",
    )

    private fun check(label: String, raw: ByteArray, expected: JsonValue) {
        val want = expected["parsed"]!!
        val p = MimeParser.parse(raw)
        assertEquals("$label headers", want.str("headers"), p.headers)
        assertEquals("$label from", want.str("from"), p.from)
        assertEquals("$label to", want.str("to"), p.to)
        assertEquals("$label cc", want.str("cc"), p.cc)
        assertEquals("$label subject", want.str("subject"), p.subject)
        assertEquals("$label date", want.str("date"), p.date)
        assertEquals("$label message_id", want.str("message_id"), p.messageId)
        assertEquals("$label in_reply_to", want.str("in_reply_to"), p.inReplyTo)
        assertEquals("$label references", want.str("references"), p.references)
        assertEquals("$label text_plain", want.str("text_plain"), p.textPlain)
        assertEquals("$label text_html", want.str("text_html"), p.textHtml)
        val atts = want["attachments"]!!.arrayValue!!
        assertEquals("$label attachment count", atts.size, p.attachments.size)
        atts.forEachIndexed { i, a ->
            val got = p.attachments[i]
            assertEquals("$label part $i mime_part", a.str("mime_part"), got.mimePart)
            assertEquals("$label part $i filename", a.str("filename"), got.filename)
            assertEquals("$label part $i content_type", a.str("content_type"), got.contentType)
            assertEquals("$label part $i content_id", a.str("content_id"), got.contentId)
            assertEquals("$label part $i inline", a["inline"]!!.boolValue, got.inline)
            assertEquals("$label part $i size", a["size"]!!.intValue, got.bytes.size)
            assertEquals("$label part $i sha256", a.str("sha256_hex"), VaultCrypto.toHex(VaultCrypto.sha256(got.bytes)))
        }
        expected["header_values"]?.objectValue?.forEach { (name, value) ->
            assertEquals("$label header $name", value.stringValue, MimeParser.headerValue(p, name))
        }
        val store = expected["store"] ?: error("$label: the vector has no store section")
        val s = RelayParse.store(p)
        store["fields"]!!.objectValue!!.forEach { (col, value) ->
            assertEquals("$label store $col", value.stringValue, s.fields[col])
        }
        val parts = store["parts"]!!.arrayValue!!
        assertEquals("$label store parts", parts.size, s.parts.size)
        parts.forEachIndexed { i, sp ->
            assertEquals(sp.str("mime_part"), s.parts[i].mimePart)
            assertEquals(sp["size"]!!.intValue, s.parts[i].bytes.size)
            assertEquals(sp["inline"]!!.boolValue, s.parts[i].inline)
        }
        assertEquals("$label spam", store["spam_headers"]!!.encoded(), s.spamHeaders.encoded())
        // snippet_from_plain is the snippet whenever the plain body has text.
        if (p.textPlain.isNotBlank()) {
            assertEquals("$label snippet", store.str("snippet_from_plain"), s.fields["iem_snippet"])
        }
    }

    @Test
    fun fixtureMessages() {
        for (f in files) {
            val raw = Vectors.bytes("mime/$f.eml")
            val expected = Vectors.json("mime/$f.expected.json")
            assertEquals("$f sha", expected.str("raw_sha256_hex"), VaultCrypto.toHex(VaultCrypto.sha256(raw)))
            check(f, raw, expected)
        }
    }

    @Test
    fun builtinCases() {
        Vectors.json("mime/builtin.expected.json")["cases"]!!.arrayValue!!.forEach { c ->
            check(c.str("label"), VaultCrypto.b64decode(c.str("raw_b64")), c)
        }
    }

    @Test
    fun searchTextPacksLikeTheServer() {
        val long = "the quarterly numbers are attached ".repeat(40)
        val packed = RelayParse.packSearchText(long)
        assertEquals(true, packed.startsWith("gz:"))
        assertEquals(long.trim(), String(VaultCrypto.gunzip(VaultCrypto.b64decode(packed.removePrefix("gz:")))))
        assertEquals("short text", RelayParse.packSearchText("  short \n text "))
    }
}
