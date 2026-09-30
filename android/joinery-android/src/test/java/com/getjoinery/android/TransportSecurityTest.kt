package com.getjoinery.android

import kotlinx.coroutines.runBlocking
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/**
 * The app allows cleartext for one purpose (the AI endpoint on the person's
 * network), so the client itself guarantees no credential and no mail byte
 * leaves over plain http. A recording transport proves nothing was sent.
 */
class TransportSecurityTest {
    private val sent = ArrayList<okhttp3.Request>()

    private fun recordingHttp() = OkHttpClient.Builder().addInterceptor(Interceptor { chain ->
        sent.add(chain.request())
        Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(200).message("OK")
            .body("{\"success\":true,\"data\":{}}".toByteArray().toResponseBody(null)).build()
    }).build()

    private fun client(base: String) = ApiClient(
        JoineryConfig(baseUrl = base, clientApp = "test", clientVersion = "1", appName = "t"),
        recordingHttp(),
    ).apply { credentials = ApiCredentials("pub", "secret") }

    private fun refused(block: suspend () -> Unit) {
        try {
            runBlocking { block() }
            fail("an insecure request was allowed")
        } catch (e: JoineryApiError.InsecureUrl) {
        }
    }

    @Test
    fun anHttpDeploymentWithTheSessionKeyIsRefused() {
        val c = client("http://dev.example.test")
        refused { c.request("GET", "/api/v1/auth/session") }
        refused { c.submitAction("mailbox/mailboxes", JsonValue.Obj(emptyList())) }
        refused { c.submitMultipart("mailbox/send", emptyList(), emptyList()) }
        assertTrue("nothing may reach the wire", sent.isEmpty())
    }

    @Test
    fun anHttpSignedLinkIsRefused() {
        val c = client("https://dev.example.test")
        refused { c.fetchBytes("http://dev.example.test/uploads/part.bin?sig=x") }
        refused { c.fetchBytes("http://elsewhere.example/part.bin") }
        assertTrue(sent.isEmpty())
        runBlocking { c.fetchBytes("/uploads/part.bin?sig=x") }
        assertEquals("https", sent.single().url.scheme)
        assertEquals(null, sent.single().header("secret-key"))
    }

    @Test
    fun httpsRequestsCarryTheKey() {
        val c = client("https://dev.example.test")
        runBlocking { c.request("GET", "/api/v1/auth/session") }
        assertEquals("secret", sent.single().header("secret-key"))
    }

    @Test
    fun theAiClientGoesOnlyToTheRegisteredOrigin() {
        val c = client("https://dev.example.test")
        val registered = "http://192.168.1.5:11434"
        // Another http origin, another port, another scheme, another host: refused.
        refused { c.postExternal("http://192.168.1.6:11434/v1/chat/completions", registered, ByteArray(0), emptyMap()) }
        refused { c.postExternal("http://192.168.1.5:8080/v1/chat/completions", registered, ByteArray(0), emptyMap()) }
        refused { c.postExternal("http://evil.example/v1/chat/completions", registered, ByteArray(0), emptyMap()) }
        refused { c.postExternal("https://192.168.1.5:11434/v1/chat/completions", registered, ByteArray(0), emptyMap()) }
        assertTrue(sent.isEmpty())
        // The registered one: allowed, and no Joinery credential rides along.
        runBlocking { c.postExternal("http://192.168.1.5:11434/v1/chat/completions", registered, ByteArray(0), mapOf("Authorization" to "Bearer model-key")) }
        val r = sent.single()
        assertEquals(null, r.header("secret-key"))
        assertEquals(null, r.header("public-key"))
        assertEquals(null, r.header("client-app"))
        assertEquals("Bearer model-key", r.header("Authorization"))
    }

    /** F5: a model that answers with a redirect gets no second request; the
     *  opened message never reaches the host it points at. */
    @Test
    fun theAiClientFollowsNoRedirect() {
        val model = okhttp3.mockwebserver.MockWebServer()
        val elsewhere = okhttp3.mockwebserver.MockWebServer()
        model.start(); elsewhere.start()
        try {
            elsewhere.enqueue(okhttp3.mockwebserver.MockResponse().setBody("{}"))
            model.enqueue(okhttp3.mockwebserver.MockResponse().setResponseCode(307)
                .setHeader("Location", elsewhere.url("/v1/chat/completions").toString()))
            val origin = model.url("/").toString().trimEnd('/')
            val c = ApiClient(JoineryConfig(baseUrl = "https://dev.example.test", clientApp = "t", clientVersion = "1", appName = "t"))
            val (status, _) = runBlocking {
                c.postExternal(model.url("/v1/chat/completions").toString(), origin, "{\"digest\":\"secret mail\"}".toByteArray(), emptyMap())
            }
            assertEquals(307, status)
            assertEquals(1, model.requestCount)
            assertEquals("the redirect target must get nothing", 0, elsewhere.requestCount)
        } finally {
            model.shutdown(); elsewhere.shutdown()
        }
    }
}
