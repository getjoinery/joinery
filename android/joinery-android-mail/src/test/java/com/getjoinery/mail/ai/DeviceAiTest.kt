package com.getjoinery.mail.ai

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.Vectors
import com.getjoinery.mail.fortress.DeviceAiSettings
import com.getjoinery.mail.str
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The device-AI ports against the browser modules' own output and the PHP
 *  side (plugins/mailbox/tests/fixtures/device_ai_vectors.json). */
class DeviceAiTest {
    private val v = Vectors.json("device_ai_vectors.json")

    @Test
    fun digests() {
        v["digests"]!!.arrayValue!!.forEach { d ->
            assertEquals(d.str("name"), d.str("digest"), EmailDigest.build(EmailDigest.Columns.from(d["input"]!!)))
        }
    }

    @Test
    fun attachmentSections() {
        v["attachments"]!!.arrayValue!!.forEach { a ->
            assertEquals(a.str("name"), a.str("section"), EmailDigest.attachments(a["manifest"]!!.arrayValue!!))
        }
    }

    @Test
    fun envelopes() {
        v["envelopes"]!!.arrayValue!!.forEach { e ->
            assertEquals(e.str("name"), e.str("wrapped"), EmailDigest.wrapBlock(e.str("text"), e.str("nonce")))
        }
    }

    @Test
    fun verdicts() {
        val descriptors = v["descriptors"]!!
        v["verdicts"]!!.arrayValue!!.forEachIndexed { i, c ->
            val job = c.str("job_id")
            val got = VerdictCheck.parse(c.str("answer"), descriptors[job], job)
            val want = c["result"]!!
            val label = "verdict $i ($job): ${c.str("answer").take(60)}"
            if (want["error"] != null) {
                assertTrue("$label expected an error", got is VerdictCheck.Result.Error)
                assertEquals(label, want.str("error"), (got as VerdictCheck.Result.Error).message)
                assertEquals(label, c.str("retry_message"), VerdictCheck.retryMessage(got.message))
            } else {
                assertTrue("$label expected a verdict, got $got", got is VerdictCheck.Result.Verdict)
                assertEquals(label, canon(want["verdict"]!!), canon((got as VerdictCheck.Result.Verdict).verdict))
            }
        }
    }

    /** JSON with object keys sorted, numbers as numbers: compare meaning, not order. */
    private fun canon(j: JsonValue): String = when (j) {
        is JsonValue.Obj -> j.pairs.sortedBy { it.first }.joinToString(",", "{", "}") { "\"${it.first}\":" + canon(it.second) }
        is JsonValue.Arr -> j.items.joinToString(",", "[", "]") { canon(it) }
        else -> j.encoded()
    }

    @Test
    fun judgements() = runBlocking {
        val inputs = v["judge_inputs"]!!
        val entry = inputs["entry"]!!
        val opened = inputs["opened"]!!.objectValue!!.associate { it.first to (it.second.stringValue ?: "") }
        val endpoint = inputs["endpoint"]!!
        val dek = VaultCrypto.randomBytes(32)
        v["judgements"]!!.arrayValue!!.forEach { j ->
            val name = j.str("name")
            val recipe0 = j["recipe"]!!
            val recipe = JsonValue.Obj(recipe0.objectValue!! + ("verdict_descriptor" to v["descriptors"]!![recipe0.str("job_id")]!!))
            val replies = ArrayDeque(j["replies"]!!.arrayValue!!)
            val requests = ArrayList<JsonValue>()
            val posts = ArrayList<Pair<String, JsonValue>>()
            val judge = AiJudge(
                open = { opened },
                keyOf = { dek.copyOf() },
                transport = { url, headers, body ->
                    requests.add(JsonValue.obj(
                        "url" to JsonValue.Str(url), "method" to JsonValue.Str("POST"),
                        "headers" to JsonValue.Obj(headers.map { it.key to JsonValue.Str(it.value) }),
                        "body" to JsonValue.parse(String(body, Charsets.UTF_8)),
                    ))
                    val r = replies.removeFirst()
                    val b = r["body"]!!
                    r["status"]!!.intValue!! to (if (b is JsonValue.Str) b.value else b.encoded())
                },
                post = { action, body -> posts.add(action to body); JsonValue.obj("recorded" to JsonValue.Bool(true)) },
                epoch = { 0 },
            )
            val out = judge.judge(entry, recipe, AiJudge.Endpoint(endpoint.str("url"), endpoint.str("key"), endpoint.str("model")))

            val wantRequests = j["requests"]!!.arrayValue!!
            assertEquals("$name request count", wantRequests.size, requests.size)
            wantRequests.forEachIndexed { i, want ->
                assertEquals("$name request $i", canon(want), canon(requests[i]))
            }

            val wantPosts = j["posts"]!!.arrayValue!!
            assertEquals("$name post count", wantPosts.size, posts.size)
            wantPosts.forEachIndexed { i, want ->
                val (action, body) = posts[i]
                assertEquals("$name post $i action", want.str("action"), action)
                val wb = want["body"]!!
                wb.objectValue!!.forEach { (k, wv) ->
                    if (k == "fields") {
                        wv.objectValue!!.forEach { (field, desc) ->
                            val sealed = body["fields"]!![field]!!.stringValue!!
                            val plain = VaultCrypto.decryptString(sealed.removePrefix("v1.edge."), dek, desc.str("sealed_under_row_dek_with_ad"))
                            assertEquals("$name sealed $field", j["outcome"]!!.str("plaintext"), plain)
                        }
                    } else {
                        assertEquals("$name post $i $k", canon(wv), canon(body[k]!!))
                    }
                }
            }

            val o = j["outcome"]!!
            when (o.str("status")) {
                "done" -> {
                    assertTrue("$name outcome", out is AiJudge.Outcome.Done)
                    out as AiJudge.Outcome.Done
                    assertEquals(name, o.str("field"), out.field)
                    assertEquals(name, canonText(o.str("plaintext")), canonText(out.plaintext))
                    assertEquals(name, o.str("model"), out.model)
                }
                "error" -> assertTrue("$name outcome", out is AiJudge.Outcome.Error)
                "stop" -> {
                    assertTrue("$name outcome $out", out is AiJudge.Outcome.Stop)
                    out as AiJudge.Outcome.Stop
                    assertEquals(name, o.str("reason"), out.reason)
                    assertEquals(name, o["http"]?.intValue, out.http)
                }
            }
        }
    }

    private fun canonText(s: String): String = try { canon(JsonValue.parse(s)) } catch (e: Exception) { s }

    /** The app's entity table is generated from assets/js/html-entities.js;
     *  a regenerated JS table must be copied here too. */
    @Test
    fun entityTableMatchesTheBrowsers() {
        val js = java.io.File("../../public_html/assets/js/html-entities.js")
        assertTrue("html-entities.js not found at ${js.absolutePath}", js.exists())
        val src = js.readText()
        val body = src.substring(src.indexOf('{', src.indexOf("*/")), src.lastIndexOf('}') + 1)
        val theirs = JsonValue.parse(body).objectValue!!.associate { it.first to it.second.stringValue }
        val ours = JsonValue.parse(Vectors.bytes("com/getjoinery/mail/html_entities.json").toString(Charsets.UTF_8))
            .objectValue!!.associate { it.first to it.second.stringValue }
        assertEquals(theirs, ours)
    }

    @Test
    fun endpointPathStaysOnTheOrigin() {
        assertEquals("https://llm.example/v1/chat/completions", DeviceAiSettings.callUrl("https://llm.example", "v1/"))
        assertEquals("http://192.168.1.5:11434/v1/chat/completions", DeviceAiSettings.callUrl("http://192.168.1.5:11434", "/v1"))
        assertNull(DeviceAiSettings.callUrl("https://llm.example", "//evil.example/v1"))
        assertNull(DeviceAiSettings.callUrl("https://llm.example", "https://evil.example"))
        assertNull(DeviceAiSettings.callUrl("https://llm.example", "/v1/../x"))
        assertNull(DeviceAiSettings.callUrl(null, "/v1"))
    }
}

/** F23: encoded words decode exactly as PHP's iconv_mime_decode does (the
 *  expected strings are PHP's own output, iconv_words_vectors.json). */
class IconvWordsTest {
    @Test
    fun wordsDecodeAsIconvDoes() {
        val cases = Vectors.json("iconv_words_vectors.json")["cases"]!!.arrayValue!!
        assertTrue(cases.size >= 47)
        cases.forEach { c ->
            assertEquals(c.str("word"), c.str("php"), EmailDigest.decodeHeaderValue(c.str("word")))
        }
    }

    @Test
    fun lenientBase64() {
        assertEquals("ab\u0018", String(EmailDigest.phpBase64("YWI=Yw=="), Charsets.ISO_8859_1))
        assertEquals("a", String(EmailDigest.phpBase64("YQ="), Charsets.ISO_8859_1))
        assertEquals("", String(EmailDigest.phpBase64("Y"), Charsets.ISO_8859_1))
    }
}
