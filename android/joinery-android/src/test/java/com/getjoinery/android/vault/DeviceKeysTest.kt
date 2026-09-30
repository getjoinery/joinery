package com.getjoinery.android.vault

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/** Key custody on the phone (specs/fortress_mobile_apps.md § R2, R3, R9, B6). */
class DeviceKeysTest {

    private fun keys(): Triple<DeviceKeys, MemorySecretStore, MemoryKeyValueStore> {
        val store = MemorySecretStore()
        val prefs = MemoryKeyValueStore()
        return Triple(DeviceKeys(store, prefs, HeldKeys()), store, prefs)
    }

    /** A vault keypair as the browser holds it, and its secret sealed to [devicePub]. */
    private fun handoff(devicePub: String): Pair<String, String> {
        val vault = VaultCrypto.generateKeypair()
        val pkcs8 = VaultCrypto.pkcs8Encode(vault.secret)
        return VaultCrypto.sealToPublicKey(pkcs8, devicePub) to vault.publicKeyB64
    }

    @Test
    fun deviceKeyIsMadeOnceAndReused() {
        val (k, store, _) = keys()
        val a = k.ensureDeviceKey()
        val b = k.ensureDeviceKey()
        assertEquals(a, b)
        assertTrue(store.names().contains(DeviceKeys.DEVICE_SECRET))
    }

    @Test
    fun handoffOpensStoresAndHolds() {
        val (k, _, _) = keys()
        val (sealed, vaultPub) = handoff(k.ensureDeviceKey())
        assertEquals(vaultPub, k.acceptHandoff("mail", sealed, listOf(vaultPub)))
        assertTrue(k.holds("mail"))
        assertTrue(k.isOpen("mail"))
        assertEquals(vaultPub, k.scopePublicKey("mail"))
        // A DEK sealed to the vault opens through the held secret.
        val dek = VaultCrypto.randomBytes(32)
        assertArrayEquals(dek, k.openSealed("mail", VaultCrypto.sealToPublicKey(dek, vaultPub)))
    }

    @Test
    fun handoffOfAnotherKeyIsRefused() {
        val (k, _, _) = keys()
        val (sealed, _) = handoff(k.ensureDeviceKey())
        try {
            k.acceptHandoff("mail", sealed, listOf(VaultCrypto.generateKeypair().publicKeyB64))
            fail("a secret that is not the vault's key must not be kept")
        } catch (e: VaultCryptoException) {
        }
        assertFalse(k.holds("mail"))
    }

    @Test
    fun lockedStoreRefusesReadsButDeletesStillWork() {
        val (k, store, _) = keys()
        val (sealed, pub) = handoff(k.ensureDeviceKey())
        k.acceptHandoff("mail", sealed, listOf(pub))
        k.held.dropAll()
        store.locked = true
        try {
            k.loadAll()
            fail("a shut biometric window must refuse reads")
        } catch (e: SecretStoreLockedException) {
        }
        k.onSignOut()
        assertFalse(k.holds("mail"))
        store.locked = false
        assertNull(store.read(DeviceKeys.scopeSecret("mail")))
    }

    @Test
    fun signOutKeepsTheDeviceKey() {
        val (k, store, _) = keys()
        val devicePub = k.ensureDeviceKey()
        val (sealed, pub) = handoff(devicePub)
        k.acceptHandoff("mail", sealed, listOf(pub))
        k.scopeExtra("mail", "search")
        k.onSignOut()
        assertFalse(k.holds("mail"))
        assertFalse(k.isOpen("mail"))
        assertEquals(devicePub, k.devicePublicKey)
        assertTrue(store.names().contains(DeviceKeys.DEVICE_SECRET))
        assertFalse(store.names().any { it.startsWith("scope.") })
        // The next enrollment approves the same public key.
        assertEquals(devicePub, k.ensureDeviceKey())
    }

    @Test
    fun forgetScopeWipesItsExtras() {
        val (k, store, _) = keys()
        val (sealed, pub) = handoff(k.ensureDeviceKey())
        k.acceptHandoff("mail", sealed, listOf(pub))
        val search = k.scopeExtra("mail", "search")
        assertArrayEquals(search, k.scopeExtra("mail", "search"))
        k.forgetScope("mail")
        assertFalse(store.names().any { it.startsWith("scope.mail") })
        assertNull(k.scopePublicKey("mail"))
    }

    @Test
    fun loadAllReopensAfterALock() {
        val (k, _, _) = keys()
        val (sealed, pub) = handoff(k.ensureDeviceKey())
        k.acceptHandoff("mail", sealed, listOf(pub))
        k.held.dropAll()
        assertFalse(k.isOpen("mail"))
        // The lock shut the store too: reopening is an unlock, not a read.
        try { k.loadAll(); org.junit.Assert.fail("a locked store must refuse") } catch (e: SecretStoreLockedException) {}
        k.completeUnlock(k.unlockCipher())
        assertTrue(k.isOpen("mail"))
    }

    @Test
    fun heldKeysDropAfterTheBackgroundLimit() {
        var now = 0L
        val held = HeldKeys { now }
        held.lockAfterMillis = 1_000
        held.hold("scope.mail", byteArrayOf(1, 2, 3))
        val epoch0 = held.epoch
        var locked = 0
        held.onLock { locked++ }
        held.onBackground()
        now = 500
        assertFalse(held.onForeground())
        assertTrue(held.has("scope.mail"))
        held.onBackground()
        now = 2_000
        held.backgroundTimerFired()
        assertFalse(held.has("scope.mail"))
        assertTrue(held.epoch > epoch0)
        assertEquals(1, locked)
    }

    @Test
    fun heldCopiesAreIndependent() {
        val held = HeldKeys()
        val secret = byteArrayOf(9, 9, 9)
        held.hold("x", secret)
        secret.fill(0)
        val copy = held.get("x")!!
        assertArrayEquals(byteArrayOf(9, 9, 9), copy)
        copy.fill(0)
        assertArrayEquals(byteArrayOf(9, 9, 9), held.get("x"))
    }

    // MARK: The probe (B6)

    private fun probe(json: String) = VaultProbe.from(JsonValue.parse(json))!!

    @Test
    fun probeVerdicts() {
        val p = probe("""{"set_up":true,"public_key":"A","key_generation":2,"pending_public_key":null,"held_by_this_device":true}""")
        assertEquals(VaultProbe.Verdict.KEEP, p.verdict(true, "A"))
        assertEquals(VaultProbe.Verdict.WIPE, p.verdict(true, "OLD"))
        assertEquals(VaultProbe.Verdict.ENROLL, p.verdict(false, null))

        val forgotten = probe("""{"set_up":true,"public_key":"A","key_generation":2,"pending_public_key":null,"held_by_this_device":false}""")
        assertEquals(VaultProbe.Verdict.WIPE, forgotten.verdict(true, "A"))

        val rotating = probe("""{"set_up":true,"public_key":"A","key_generation":2,"pending_public_key":"B","held_by_this_device":true}""")
        assertEquals(VaultProbe.Verdict.KEEP, rotating.verdict(true, "B"))
        assertEquals(listOf("A", "B"), rotating.acceptedKeys)

        val older = probe("""{"set_up":true,"public_key":"A","key_generation":2}""")
        assertNull(older.heldByThisDevice)
        assertEquals(VaultProbe.Verdict.KEEP, older.verdict(true, "A"))

        val none = probe("""{"set_up":false,"public_key":null,"key_generation":0}""")
        assertEquals(VaultProbe.Verdict.NOT_SET_UP, none.verdict(false, null))
        assertEquals(VaultProbe.Verdict.WIPE, none.verdict(true, "A"))
    }

    @Test
    fun pollShapes() {
        fun poll(json: String) = DeviceEnrollment.Poll.from(JsonValue.parse(json))
        assertEquals(DeviceEnrollment.Poll.Pending(3), poll("""{"status":"pending","poll_after":3}"""))
        assertEquals(DeviceEnrollment.Poll.Denied, poll("""{"status":"denied"}"""))
        val approved = poll("""{"status":"approved","device_id":12,"sealed_vault_keys":{"mail":"QUJD"}}""")
        assertTrue(approved is DeviceEnrollment.Poll.Approved)
        approved as DeviceEnrollment.Poll.Approved
        assertEquals("QUJD", approved.sealedKeys["mail"])
        assertEquals(12, approved.deviceId)
        val ticket = DeviceEnrollment.Ticket.from(JsonValue.parse(
            """{"link_code":"ABCD-EFGH","verify_url":"https://x/profile/devices/link?code=ABCD-EFGH","poll_token":"t","expires_time":"2026-09-30 12:00:00","poll_after":3,"device_id":12}""",
        ))
        assertNotNull(ticket)
        assertEquals("ABCD-EFGH", ticket!!.linkCode)
    }
}
