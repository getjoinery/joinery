package com.getjoinery.android.vault

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import kotlinx.coroutines.launch
import java.nio.file.Files

/**
 * F16: the store opens only through one guard operation (on the phone, a
 * Keystore key only a strong biometric CryptoObject can authorize, per
 * operation, on every API level); a software guard stands in for it here.
 */
class WrappedSecretStoreTest {
    private fun dir() = Files.createTempDirectory("wss").toFile()

    private fun unlock(store: WrappedSecretStore) = store.unlockWith(store.unlockCipher())

    private fun assertLocked(block: () -> Unit) {
        try { block(); fail("a shut store must refuse") } catch (e: SecretStoreLockedException) {}
    }

    @Test
    fun shutUntilUnlockedAndAgainAfterLock() {
        val store = WrappedSecretStore(dir(), SoftwareMasterGuard())
        assertFalse(store.isUnlocked)
        assertLocked { store.write("x", byteArrayOf(1)) }
        unlock(store)
        store.write("x", byteArrayOf(1, 2, 3))
        assertArrayEquals(byteArrayOf(1, 2, 3), store.read("x"))
        store.lock()
        assertLocked { store.read("x") }
        // Deleting never needs the store open.
        store.delete("x")
        assertFalse(store.names().contains("x"))
    }

    @Test
    fun theMasterPersistsAndReopens() {
        val d = dir()
        val guard = SoftwareMasterGuard()
        val a = WrappedSecretStore(d, guard)
        unlock(a)
        a.write("device.x25519", byteArrayOf(7, 7))
        val b = WrappedSecretStore(d, guard)
        assertFalse(b.isUnlocked)
        unlock(b)
        assertArrayEquals(byteArrayOf(7, 7), b.read("device.x25519"))
        assertTrue(java.io.File(d, WrappedSecretStore.MASTER_FILE).exists())
    }

    @Test
    fun anotherGuardKeyIsAnInvalidatedStore() {
        val d = dir()
        val a = WrappedSecretStore(d, SoftwareMasterGuard())
        unlock(a)
        a.write("s", byteArrayOf(1))
        val b = WrappedSecretStore(d, SoftwareMasterGuard())
        try {
            unlock(b)
            fail("a master wrapped under another key must not open")
        } catch (e: SecretStoreInvalidatedException) {
        }
        assertTrue(b.names().isEmpty())
    }

    @Test
    fun secretsAreBoundToTheirNames() {
        val d = dir()
        val store = WrappedSecretStore(d, SoftwareMasterGuard())
        unlock(store)
        store.write("a", byteArrayOf(1))
        // A file swapped under another name does not open as that name.
        java.io.File(d, "a.bin").copyTo(java.io.File(d, "b.bin"))
        assertNull(store.read("b"))
    }

    @Test
    fun deleteAllDestroysTheGuardAndShuts() {
        val guard = SoftwareMasterGuard()
        val store = WrappedSecretStore(dir(), guard)
        unlock(store)
        store.write("s", byteArrayOf(1))
        store.deleteAll()
        assertTrue(guard.destroyed)
        assertFalse(store.isUnlocked)
        assertTrue(store.names().isEmpty())
    }

    @Test
    fun heldKeysDroppingAllShutsTheStore() {
        val store = WrappedSecretStore(dir(), SoftwareMasterGuard())
        val keys = DeviceKeys(store, MemoryKeyValueStore(), HeldKeys())
        keys.completeUnlock(keys.unlockCipher())
        assertTrue(keys.storeUnlocked)
        keys.ensureDeviceKey()
        keys.held.dropAll()
        assertFalse(keys.storeUnlocked)
        assertLocked { store.read(DeviceKeys.DEVICE_SECRET) }
    }

    @Test
    fun epochIsAtomicUnderContention() {
        val held = HeldKeys()
        val threads = (1..8).map { t -> Thread { repeat(500) { held.hold("k$t", byteArrayOf(1)); held.drop("k$t") } } }
        threads.forEach { it.start() }
        threads.forEach { it.join() }
        assertEquals(8 * 500, held.epoch)
    }
}

/** The re-check's store items (R13–R15). */
class ReCheckStoreTest {
    private fun dir() = java.nio.file.Files.createTempDirectory("wss").toFile()

    @Test
    fun repeatedUnusableCiphersSayRemoveTheKeys() {
        val store = WrappedSecretStore(dir(), SoftwareMasterGuard())
        store.unlockCipher()
        // A Cipher the Keystore will not use (never initialized, as some
        // API 28-29 phones hand back): the first time, confirm again…
        try { store.unlockWith(javax.crypto.Cipher.getInstance("AES/GCM/NoPadding")); fail() } catch (e: SecretStoreLockedException) {}
        // …the second time, the way out.
        try { store.unlockWith(javax.crypto.Cipher.getInstance("AES/GCM/NoPadding")); fail() } catch (e: SecretStoreStuckException) {}
        // A good unlock starts the count over.
        store.unlockWith(store.unlockCipher())
        store.lock()
        store.unlockCipher()
        try { store.unlockWith(javax.crypto.Cipher.getInstance("AES/GCM/NoPadding")); fail() } catch (e: SecretStoreLockedException) {}
    }

    /** A guard that counts how often a new wrapping key is made. */
    private class CountingGuard : MasterGuard {
        val inner = SoftwareMasterGuard()
        val creates = java.util.concurrent.atomic.AtomicInteger()
        override fun cipher(mode: Int, iv: ByteArray?): javax.crypto.Cipher {
            if (mode == javax.crypto.Cipher.ENCRYPT_MODE) creates.incrementAndGet()
            return inner.cipher(mode, iv)
        }
        override fun destroy() = inner.destroy()
    }

    @Test
    fun concurrentFirstUnlocksMakeOneKey() = kotlinx.coroutines.runBlocking {
        val guard = CountingGuard()
        val keys = DeviceKeys(WrappedSecretStore(dir(), guard), MemoryKeyValueStore(), HeldKeys())
        val prompts = java.util.concurrent.atomic.AtomicInteger()
        val prompt: suspend (javax.crypto.Cipher?) -> javax.crypto.Cipher? = { c ->
            prompts.incrementAndGet()
            kotlinx.coroutines.delay(50) // the person looks at the prompt
            c
        }
        val a = this.launch(kotlinx.coroutines.Dispatchers.Default) { keys.unlock(prompt) }
        val b = this.launch(kotlinx.coroutines.Dispatchers.Default) { keys.unlock(prompt) }
        a.join(); b.join()
        assertEquals(1, guard.creates.get())
        assertEquals(1, prompts.get())
        assertTrue(keys.storeUnlocked)
    }

    @Test
    fun theV1ResetClearsItsPrefsOnlyWhenThereWasAV1() {
        val prefs = MemoryKeyValueStore()
        prefs.put("fortress.device.pub", "old")
        prefs.put("fortress.scope.mail.pub", "old")
        prefs.put("other", "keep")
        val none = java.io.File(dir(), "joinery_fortress")
        assertFalse(DeviceKeys.resetV1(none, hasV1Key = false, deleteV1Key = {}, prefs = prefs))
        assertEquals("old", prefs.get("fortress.device.pub"))
        val v1 = java.io.File(dir(), "joinery_fortress").apply { mkdirs(); java.io.File(this, "device.x25519.bin").writeText("x") }
        var deleted = false
        assertTrue(DeviceKeys.resetV1(v1, hasV1Key = true, deleteV1Key = { deleted = true }, prefs = prefs))
        assertTrue(deleted)
        assertFalse(v1.exists())
        assertNull(prefs.get("fortress.device.pub"))
        assertNull(prefs.get("fortress.scope.mail.pub"))
        assertEquals("keep", prefs.get("other"))
    }
}
