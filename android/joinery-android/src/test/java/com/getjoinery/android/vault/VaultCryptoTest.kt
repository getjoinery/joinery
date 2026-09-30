package com.getjoinery.android.vault

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/** VaultCrypto against the shared edge vector
 *  (`public_html/tests/vault/fixtures/edge_vector.json`), the bytes the
 *  browser, PHP and the Rust client already open. */
class VaultCryptoTest {

    private fun vector(name: String): JsonValue {
        val stream = VaultCryptoTest::class.java.classLoader!!.getResourceAsStream(name)
            ?: error("vector not found: $name")
        return JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
    }

    private fun JsonValue.str(key: String): String = this[key]?.stringValue ?: error("missing $key")

    @Test
    fun edgeVectorOpensSealedDekAndField() {
        val v = vector("edge_vector.json")
        val pkcs8 = VaultCrypto.pkcs8Encode(VaultCrypto.hex(v.str("recipient_secret_hex")))
        assertEquals(v.str("recipient_public_b64"), VaultCrypto.publicKeyFromSecret(pkcs8))
        val dek = VaultCrypto.openWithSecret(v.str("sealed_dek_b64"), pkcs8)
        assertArrayEquals(VaultCrypto.hex(v.str("dek_hex")), dek)
        assertEquals(v.str("field_plaintext"), VaultCrypto.decryptString(v.str("field_blob_b64"), dek, v.str("field_ad")))
    }

    @Test
    fun wrongAdIsRefused() {
        val v = vector("edge_vector.json")
        val dek = VaultCrypto.hex(v.str("dek_hex"))
        try {
            VaultCrypto.decryptString(v.str("field_blob_b64"), dek, v.str("field_ad") + "x")
            fail("a changed AD must not open")
        } catch (e: VaultCryptoException) {
        }
    }

    @Test
    fun wrongRecipientIsRefused() {
        val v = vector("edge_vector.json")
        val other = VaultCrypto.generateKeypair()
        try {
            VaultCrypto.openWithScalar(v.str("sealed_dek_b64"), other.secret)
            fail("another key must not open the seal")
        } catch (e: VaultCryptoException) {
        }
    }

    @Test
    fun sealRoundTripsToAFreshKey() {
        val pair = VaultCrypto.generateKeypair()
        val data = VaultCrypto.randomBytes(48)
        val sealed = VaultCrypto.sealToPublicKey(data, pair.publicKeyB64)
        assertArrayEquals(data, VaultCrypto.openWithScalar(sealed, pair.secret))
        assertArrayEquals(data, VaultCrypto.openWithSecret(sealed, VaultCrypto.pkcs8Encode(pair.secret)))
    }

    @Test
    fun fieldRoundTripsWithAd() {
        val key = VaultCrypto.randomBytes(32)
        val blob = VaultCrypto.encryptString("hello ✓", key, "mail:7:iem_subject")
        assertEquals("hello ✓", VaultCrypto.decryptString(blob, key, "mail:7:iem_subject"))
    }

    @Test
    fun pkcs8RefusesOtherShapes() {
        try {
            VaultCrypto.pkcs8Decode(ByteArray(48))
            fail("a zero prefix is not X25519 PKCS#8")
        } catch (e: VaultCryptoException) {
        }
    }

    @Test
    fun gzipRoundTrips() {
        val text = "the quick brown fox ".repeat(50).toByteArray()
        assertArrayEquals(text, VaultCrypto.gunzip(VaultCrypto.gzip(text)))
    }

    @Test
    fun deviceHandoffVectorOpensEndToEnd() {
        // tests/vault/fixtures/device_handoff_vector.json: the browser's handoff
        // to a device, then one Fortress row opened with the handed-over key.
        val v = vector("device_handoff_vector.json")
        val device = v["device"]!!
        val vault = v["vault"]!!
        val deviceScalar = VaultCrypto.hex(device.str("secret_hex"))
        assertEquals(device.str("public_b64"), VaultCrypto.b64encode(VaultCrypto.publicFromScalar(deviceScalar)))

        val pkcs8 = VaultCrypto.openWithScalar(v["handoff"]!!.str("blob"), deviceScalar)
        assertArrayEquals(VaultCrypto.hex(vault.str("pkcs8_hex")), pkcs8)
        assertEquals(vault.str("public_b64"), VaultCrypto.publicKeyFromSecret(pkcs8))

        val row = v["row"]!!
        val sealedDek = row.str("sealed_dek").removePrefix("v1.edgeseal.mail.")
        val dek = VaultCrypto.openWithSecret(sealedDek, pkcs8)
        assertArrayEquals(VaultCrypto.hex(row.str("dek_hex")), dek)

        row["fields"]!!.arrayValue!!.forEach { f ->
            val value = f.str("value").removePrefix("v1.edge.")
            assertEquals(f.str("plaintext"), VaultCrypto.decryptString(value, dek, f.str("ad")))
        }
        val search = row["search_text"]!!
        val packed = VaultCrypto.decryptString(search.str("value").removePrefix("v1.edge."), dek, search.str("ad"))
        assertEquals(search.str("packed"), packed)
        val text = String(VaultCrypto.gunzip(VaultCrypto.b64decode(packed.removePrefix("gz:"))), Charsets.UTF_8)
        assertEquals(search.str("text"), text)

        val part = row["part"]!!
        val bytes = VaultCrypto.decrypt(part.str("stored").removePrefix("v1.edge."), dek, part.str("ad"))
        assertArrayEquals(VaultCrypto.b64decode(part.str("bytes_b64")), bytes)
        assertEquals(part.str("sha256_hex"), VaultCrypto.toHex(VaultCrypto.sha256(bytes)))

        // Another device's key opens nothing.
        var refused = false
        try { VaultCrypto.openWithScalar(v["handoff"]!!.str("blob"), VaultCrypto.generateKeypair().secret) } catch (e: VaultCryptoException) { refused = true }
        assertTrue(refused)
    }

    @Test
    fun relayPinVectorMacAndSignature() {
        // plugins/mailbox/tests/fixtures/relay_pin_vector.json: the pin MAC (under
        // the vault secret as PKCS#8, what session.mac() holds) and the statement.
        val v = vector("relay_pin_vector.json")
        val pkcs8 = VaultCrypto.pkcs8Encode(VaultCrypto.hex(v.str("vault_secret_hex")))
        val message = "joinery-relay-pin:v1\n${v["alias_id"]!!.stringValue}\n${v.str("relay_identity_public_key")}".toByteArray()
        assertEquals(v.str("pin_mac"), VaultCrypto.b64encode(VaultCrypto.macFromSecret(pkcs8, "sealed-vault:pin", message)))
        val signed = ("joinery-relay:seal-target:v1\n" + v.str("statement")).toByteArray()
        assertTrue(VaultCrypto.verifyEd25519(v.str("relay_identity_public_key"), signed, v.str("signature")))
    }

    @Test
    fun ed25519VerifiesAndRefusesAChangedByte() {
        val pair = com.google.crypto.tink.subtle.Ed25519Sign.KeyPair.newKeyPair()
        val message = "relay statement".toByteArray()
        val sig = com.google.crypto.tink.subtle.Ed25519Sign(pair.privateKey).sign(message)
        val pub = VaultCrypto.b64encode(pair.publicKey)
        assertTrue(VaultCrypto.verifyEd25519(pub, message, VaultCrypto.b64encode(sig)))
        sig[5] = (sig[5].toInt() xor 1).toByte()
        assertFalse(VaultCrypto.verifyEd25519(pub, message, VaultCrypto.b64encode(sig)))
    }
}
