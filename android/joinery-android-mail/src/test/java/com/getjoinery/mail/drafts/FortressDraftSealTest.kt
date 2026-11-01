package com.getjoinery.mail.drafts

import com.getjoinery.android.ApiClient
import com.getjoinery.android.ApiCredentials
import com.getjoinery.android.JoineryConfig
import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.ComposeMode
import com.getjoinery.mail.MailOutgoingAttachment
import com.getjoinery.mail.fortress.FortressOpener
import kotlinx.coroutines.runBlocking
import okhttp3.Interceptor
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * A Fortress draft saved through the mail kit is sealed to the vault's X-Wing
 * key (specs/post_quantum_sealing.md WP6): the row key goes up as
 * `v2.edgeseal.mail.`, opens with the vault's seed, and that DEK opens every
 * sealed column and part under its AD. The server's half (draft_save keeping a
 * v2 row) is plugins/mailbox/tests/fortress_compose_test.php.
 */
class FortressDraftSealTest {
    private val vault = VaultCrypto.generateKeypair()
    private val saves = ArrayList<Map<String, ByteArray>>()

    private fun reply(json: String) = json.toByteArray().toResponseBody(null)

    private fun fields(body: MultipartBody): Map<String, ByteArray> = body.parts.associate { part ->
        val disposition = part.headers?.get("Content-Disposition") ?: ""
        val name = Regex("name=\"([^\"]+)\"").find(disposition)!!.groupValues[1]
        val buf = Buffer().also { part.body.writeTo(it) }
        (if (name == "attachments[]") "attachment" else name) to buf.readByteArray()
    }

    private val http = OkHttpClient.Builder().addInterceptor(Interceptor { chain ->
        val req = chain.request()
        val body = when {
            req.url.encodedPath.endsWith("/vault_client_probe") ->
                """{"success":true,"data":{"set_up":true,"public_key":"${vault.publicKeyB64}","key_generation":1}}"""
            req.url.encodedPath.endsWith("/mailbox/draft_save") -> {
                saves.add(fields(req.body as MultipartBody))
                if (saves.size == 1) """{"success":true,"data":{"draft_id":7,"id":7,"sealed_ad_prefix":"mail:"}}"""
                else """{"success":true,"data":{"draft_id":7,"parts":[{"mime_part":"${String(saves.last()["parts"]!!).let { Regex("draft:[0-9a-f]+").find(it)?.value ?: "" }}","id":91}]}}"""
            }
            else -> error("unexpected request ${req.url}")
        }
        Response.Builder().request(req).protocol(Protocol.HTTP_1_1).code(200).message("OK").body(reply(body)).build()
    }).build()

    private val client = ApiClient(JoineryConfig(baseUrl = "https://dev.example.test", clientApp = "t", clientVersion = "1", appName = "t"), http)
        .apply { credentials = ApiCredentials("pub", "secret") }

    @Test
    fun aFortressDraftIsSealedToTheVaultsXWingKey() = runBlocking {
        val drafts = MailDrafts(client, null)
        val s = MailDrafts.Session(fortress = true)
        val content = ComposeContent(aliasId = 3, mode = ComposeMode.NEW, sourceId = null,
            sender = "me@example.test", to = "you@example.test", subject = "Quarterly", body = "The numbers.")
        val file = MailOutgoingAttachment(filename = "r.pdf", mimeType = "application/pdf", data = "%PDF-1.4 r".toByteArray())
        drafts.save(s, content, listOf(file), null)

        // The clear first save, then the text, then the one part.
        assertEquals(3, saves.size)
        for (save in saves.drop(1)) {
            val sealedDek = String(save["sealed_dek"]!!)
            assertTrue(sealedDek, sealedDek.startsWith("v2.edgeseal.mail."))
            assertEquals(vault.publicKeyB64, String(save["public_key"]!!))
            val dek = VaultCrypto.openWithSecret(FortressOpener.stripEdgeSeal(sealedDek, "mail"), vault.secret)
            assertArrayEquals(s.dek, dek)
        }
        val dek = s.dek!!
        val sealed = JsonValue.parse(String(saves[1]["fields"]!!))
        assertEquals("Quarterly", openField(sealed["iem_subject"]!!.stringValue!!, dek, "mail:7:iem_subject"))
        assertEquals("The numbers.", openField(sealed["iem_body_plain"]!!.stringValue!!, dek, "mail:7:iem_body_plain"))
        val partName = Regex("draft:[0-9a-f]+").find(String(saves[2]["parts"]!!))!!.value
        assertArrayEquals(file.data, FortressOpener.openStoredPart(saves[2]["attachment"]!!, dek, "mail:7:att:$partName"))
    }

    private fun openField(value: String, dek: ByteArray, ad: String): String {
        assertTrue(value.startsWith(FortressOpener.EDGE_FIELD))
        return VaultCrypto.decryptString(value.removePrefix(FortressOpener.EDGE_FIELD), dek, ad)
    }
}
