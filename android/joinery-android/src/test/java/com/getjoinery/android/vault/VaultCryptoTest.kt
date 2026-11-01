package com.getjoinery.android.vault

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/** VaultCrypto against the shared vectors the browser, PHP and the Rust client
 *  are held to: `xwing_vector.json` (X-Wing and the v2 seal),
 *  `device_handoff_vector.json` (a handoff and one Fortress row) and
 *  `edge_vector.json` (a field under a DEK). */
class VaultCryptoTest {

    private fun vector(name: String): JsonValue {
        val stream = VaultCryptoTest::class.java.classLoader!!.getResourceAsStream(name)
            ?: error("vector not found: $name")
        return JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
    }

    private fun JsonValue.str(key: String): String = this[key]?.stringValue ?: error("missing $key")

    @Test
    fun xwingDraftVectorsReproduce() {
        val list = vector("xwing_vector.json")["xwing"]!!.arrayValue!!
        assertEquals(3, list.size)
        list.forEachIndexed { i, v ->
            val seed = VaultCrypto.hex(v.str("seed"))
            val pk = VaultCrypto.hex(v.str("pk"))
            assertArrayEquals("vector $i: public key", pk, XWing.publicKey(seed))
            val enc = XWing.encapsulateWith(pk, VaultCrypto.hex(v.str("eseed")))
            assertArrayEquals("vector $i: ciphertext", VaultCrypto.hex(v.str("ct")), enc.ciphertext)
            assertArrayEquals("vector $i: shared secret", VaultCrypto.hex(v.str("ss")), enc.sharedSecret)
            assertArrayEquals("vector $i: decapsulate", VaultCrypto.hex(v.str("ss")), XWing.decapsulate(seed, enc.ciphertext))
        }
    }

    @Test
    fun sealVectorsReproduceAndOpenOnlyUnderTheirScope() {
        val list = vector("xwing_vector.json")["seal"]!!.arrayValue!!
        assertTrue(list.any { it.str("scope") == "dek" })
        for (s in list) {
            val scope = s.str("scope")
            val seed = VaultCrypto.hex(s.str("seed_hex"))
            val public = VaultCrypto.b64decode(s.str("public_b64"))
            val plain = VaultCrypto.hex(s.str("plaintext_hex"))
            val sealed = VaultCrypto.b64decode(s.str("sealed_b64"))
            assertArrayEquals("$scope: public key", public, XWing.publicKey(seed))
            assertArrayEquals("$scope: seal", sealed,
                VaultCrypto.sealWith(plain, public, scope, VaultCrypto.hex(s.str("eseed_hex")), VaultCrypto.hex(s.str("iv_hex"))))
            assertArrayEquals("$scope: open", plain, VaultCrypto.openRaw(sealed, seed, scope))
            val other = if (scope == "dek") "joinery-restore-approval:" else "dek"
            try {
                VaultCrypto.openRaw(sealed, seed, other)
                fail("$scope: opened under $other")
            } catch (e: VaultCryptoException) {
            }
            // The public opener takes the dek scope only.
            val opened = try { VaultCrypto.openWithSecret(s.str("sealed_b64"), seed); true } catch (e: VaultCryptoException) { false }
            assertEquals(scope == "dek", opened)
        }
    }

    @Test
    fun edgeVectorFieldOpensUnderItsDek() {
        val v = vector("edge_vector.json")
        val dek = VaultCrypto.hex(v.str("dek_hex"))
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
    fun sealRoundTripsToAFreshKeyAndNoOther() {
        val pair = VaultCrypto.generateKeypair()
        assertEquals(32, pair.secret.size)
        assertEquals(1216, pair.publicKey.size)
        assertEquals(pair.publicKeyB64, VaultCrypto.publicKeyFromSecret(pair.secret))
        val data = VaultCrypto.randomBytes(48)
        val sealed = VaultCrypto.sealToPublicKey(data, pair.publicKeyB64)
        assertEquals(1120 + 12 + 48 + 16, VaultCrypto.b64decode(sealed).size)
        assertArrayEquals(data, VaultCrypto.openWithSecret(sealed, pair.secret))
        try {
            VaultCrypto.openWithSecret(sealed, VaultCrypto.generateKeypair().secret)
            fail("another key must not open the seal")
        } catch (e: VaultCryptoException) {
        }
    }

    @Test
    fun sealingToAnOldKeyIsRefusedAndNamesTheRotation() {
        val old = VaultCrypto.b64encode(ByteArray(32) { 9 })
        assertTrue(VaultCrypto.isOldPublicKey(old))
        try {
            VaultCrypto.sealToPublicKey(byteArrayOf(1), old)
            fail("a 32-byte key must be refused")
        } catch (e: VaultCryptoException) {
            assertTrue(e.message!!.contains("rotate"))
        }
    }

    @Test
    fun aSecretThatIsNotASeedIsRefused() {
        try {
            VaultCrypto.publicKeyFromSecret(ByteArray(48))
            fail("a 48-byte secret is not an X-Wing seed")
        } catch (e: VaultCryptoException) {
        }
    }

    @Test
    fun fieldRoundTripsWithAd() {
        val key = VaultCrypto.randomBytes(32)
        val blob = VaultCrypto.encryptString("hello ✓", key, "mail:7:iem_subject")
        assertEquals("hello ✓", VaultCrypto.decryptString(blob, key, "mail:7:iem_subject"))
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
        val handoff = v["handoff"]!!
        val deviceSeed = VaultCrypto.hex(device.str("seed_hex"))
        val vaultSeed = VaultCrypto.hex(vault.str("seed_hex"))
        assertEquals(device.str("public_b64"), VaultCrypto.publicKeyFromSecret(deviceSeed))
        assertEquals(vault.str("public_b64"), VaultCrypto.publicKeyFromSecret(vaultSeed))

        // The handoff reproduces byte for byte, and opens to the vault's seed.
        assertEquals(handoff.str("blob"), VaultCrypto.b64encode(VaultCrypto.sealWith(vaultSeed,
            VaultCrypto.b64decode(device.str("public_b64")), VaultCrypto.SCOPE_DEK,
            VaultCrypto.hex(handoff.str("eseed_hex")), VaultCrypto.hex(handoff.str("iv_hex")))))
        assertArrayEquals(vaultSeed, VaultCrypto.openWithSecret(handoff.str("blob"), deviceSeed))

        val row = v["row"]!!
        assertTrue(row.str("sealed_dek").startsWith("v2.edgeseal.mail."))
        val dek = VaultCrypto.openWithSecret(row.str("sealed_dek").removePrefix("v2.edgeseal.mail."), vaultSeed)
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
        try { VaultCrypto.openWithSecret(handoff.str("blob"), VaultCrypto.generateKeypair().secret) } catch (e: VaultCryptoException) { refused = true }
        assertTrue(refused)
    }

    @Test
    fun relayPinVectorMacAndSignature() {
        // plugins/mailbox/tests/fixtures/relay_pin_vector.json: the pin MAC (under
        // the vault secret's bytes as the keyring holds them) and the statement.
        // The vector's secret is a pre-X-Wing PKCS#8; the MAC takes any bytes.
        val v = vector("relay_pin_vector.json")
        val pkcs8 = VaultCrypto.hex("302e020100300506032b656e04220420" + v.str("vault_secret_hex"))
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
