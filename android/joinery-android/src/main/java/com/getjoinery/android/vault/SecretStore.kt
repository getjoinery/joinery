package com.getjoinery.android.vault

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import java.io.File
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/** The store is shut: confirm with a fingerprint or face (an unlock), then retry. */
class SecretStoreLockedException : Exception("Confirm with your face or fingerprint to continue.")

/**
 * Unlocks keep failing though the prompt succeeded (some phones on API 28-29
 * hand back a Cipher the Keystore will not use): asking again will not help
 * (R15).
 */
class SecretStoreStuckException : Exception(
    "This phone's secure storage keeps refusing your fingerprint or face. Choose Remove keys from this phone in the mailbox menu, then hand this phone the key again.",
)

/** A new face or finger was enrolled (or the lock screen removed), so the
 *  Keystore destroyed the key every stored secret was wrapped under. The
 *  secrets are gone; the phone must be handed the key again. */
class SecretStoreInvalidatedException : Exception("This phone's security settings changed, so its encrypted-mail key was removed.")

/**
 * Named secrets at rest (the device X25519 secret, each vault secret the phone
 * holds, the AI endpoint key). Reading or writing needs the store unlocked;
 * deleting never does, so sign-out and a retired key can always wipe.
 *
 * Unlocking is bound to a biometric crypto operation: [unlockCipher] hands
 * out a Cipher that only a strong-biometric prompt can authorize (passed to
 * BiometricPrompt as its CryptoObject), and [unlockWith] finishes with the
 * authorized Cipher. A store with nothing to authorize returns null.
 */
interface SecretStore {
    val isUnlocked: Boolean
    fun unlockCipher(): Cipher?
    fun unlockWith(cipher: Cipher?)
    fun lock()
    fun names(): Set<String>
    fun read(name: String): ByteArray?
    fun write(name: String, bytes: ByteArray)
    fun delete(name: String)
    fun deleteAll()
}

/** Plain, non-secret facts beside the secrets: public keys, preferences. */
interface KeyValueStore {
    fun get(key: String): String?
    fun put(key: String, value: String?)
    fun keys(): Set<String>
}

class MemorySecretStore : SecretStore {
    private val map = LinkedHashMap<String, ByteArray>()
    var locked = false
    override val isUnlocked: Boolean get() = !locked
    override fun unlockCipher(): Cipher? = null
    override fun unlockWith(cipher: Cipher?) { locked = false }
    override fun lock() { locked = true }
    override fun names(): Set<String> = synchronized(map) { map.keys.toSet() }
    override fun read(name: String): ByteArray? {
        if (locked) throw SecretStoreLockedException()
        return synchronized(map) { map[name]?.copyOf() }
    }
    override fun write(name: String, bytes: ByteArray) {
        if (locked) throw SecretStoreLockedException()
        synchronized(map) { map[name] = bytes.copyOf() }
    }
    override fun delete(name: String) { synchronized(map) { map.remove(name)?.fill(0) } }
    override fun deleteAll() { synchronized(map) { map.values.forEach { it.fill(0) }; map.clear() } }
}

class MemoryKeyValueStore : KeyValueStore {
    private val map = LinkedHashMap<String, String>()
    override fun get(key: String): String? = synchronized(map) { map[key] }
    override fun put(key: String, value: String?) {
        synchronized(map) { if (value == null) map.remove(key) else map[key] = value }
    }
    override fun keys(): Set<String> = synchronized(map) { map.keys.toSet() }
}

class PrefsKeyValueStore(private val prefs: SharedPreferences) : KeyValueStore {
    override fun get(key: String): String? = prefs.getString(key, null)
    override fun put(key: String, value: String?) {
        prefs.edit().apply { if (value == null) remove(key) else putString(key, value) }.apply()
    }
    override fun keys(): Set<String> = prefs.all.keys.toSet()
}

/**
 * The key that wraps the store's master key, and the only thing that decides
 * who may unwrap it. [KeystoreMasterGuard] is the phone's; tests supply a
 * software one.
 */
interface MasterGuard {
    /** A Cipher to open the stored master ([iv] given) or wrap a new one. */
    fun cipher(mode: Int, iv: ByteArray?): Cipher
    fun destroy()
}

/**
 * Secrets under one random master key M, itself wrapped by a [MasterGuard]
 * (specs/fortress_mobile_apps.md § R3, F5). Each secret is its own file
 * (`IV ‖ ct ‖ tag`, AES-GCM under M, AAD its name) in [dir]; `master.key` is
 * `IV ‖ M wrapped`. An unlock authorizes exactly one guard operation — open
 * M, or wrap a fresh M on first use — and M then lives in memory until
 * [lock]. Deleting needs nothing, so a wipe never waits on a prompt.
 */
class WrappedSecretStore(private val dir: File, private val guard: MasterGuard) : SecretStore {
    @Volatile
    private var master: ByteArray? = null

    init { dir.mkdirs() }

    private val masterFile get() = File(dir, MASTER_FILE)

    private fun file(name: String): File {
        require(name.matches(Regex("[a-z0-9_.]{1,64}"))) { "bad secret name" }
        return File(dir, "$name.bin")
    }

    override val isUnlocked: Boolean get() = master != null

    /** Whether the Cipher handed out last opens the stored master (else it
     *  wraps a fresh one). */
    @Volatile
    private var pendingOpen = false

    override fun unlockCipher(): Cipher {
        val raw = if (masterFile.exists()) masterFile.readBytes() else null
        return if (raw != null && raw.size > 12 + 16) {
            pendingOpen = true
            guard.cipher(Cipher.DECRYPT_MODE, raw.copyOfRange(0, 12))
        } else {
            pendingOpen = false
            guard.cipher(Cipher.ENCRYPT_MODE, null)
        }
    }

    /** Finish an unlock with the Cipher the prompt authorized. */
    override fun unlockWith(cipher: Cipher?) {
        cipher ?: throw SecretStoreLockedException()
        try {
            if (pendingOpen) {
                val raw = masterFile.readBytes()
                master = cipher.doFinal(raw, 12, raw.size - 12)
            } else {
                // First use: a fresh master, wrapped under the authorized Cipher.
                // Anything stored under an earlier master is unreadable now.
                (dir.listFiles() ?: emptyArray()).filter { it.name.endsWith(".bin") }.forEach { overwrite(it); it.delete() }
                val m = VaultCrypto.randomBytes(32)
                val wrapped = cipher.doFinal(m)
                val tmp = File(dir, "$MASTER_FILE.tmp")
                tmp.writeBytes(cipher.iv + wrapped)
                if (!tmp.renameTo(masterFile)) throw IllegalStateException("could not store the master key")
                master = m
            }
        } catch (e: javax.crypto.AEADBadTagException) {
            deleteAll()
            throw SecretStoreInvalidatedException()
        } catch (e: Exception) {
            // Not the wrong key: the Keystore would not use an authorized
            // Cipher. Once may be a hiccup; again and again is this phone.
            val failures = failuresInARow() + 1
            recordFailures(failures)
            if (failures >= STUCK_AFTER) throw SecretStoreStuckException()
            throw SecretStoreLockedException()
        }
        recordFailures(0)
    }

    private val failuresFile get() = File(dir, "unlock_failures")
    private fun failuresInARow(): Int = try { failuresFile.readText().trim().toInt() } catch (e: Exception) { 0 }
    private fun recordFailures(n: Int) {
        try { if (n == 0) failuresFile.delete() else failuresFile.writeText(n.toString()) } catch (_: Exception) {}
    }

    override fun lock() {
        master?.fill(0)
        master = null
    }

    override fun names(): Set<String> =
        (dir.listFiles() ?: emptyArray()).map { it.name }.filter { it.endsWith(".bin") }.map { it.removeSuffix(".bin") }.toSet()

    private fun masterOrLocked(): ByteArray = master?.copyOf() ?: throw SecretStoreLockedException()

    override fun read(name: String): ByteArray? {
        val f = file(name)
        if (!f.exists()) return null
        val m = masterOrLocked()
        try {
            return VaultCrypto.decryptRaw(f.readBytes(), m, name)
        } catch (e: VaultCryptoException) {
            return null
        } finally {
            m.fill(0)
        }
    }

    override fun write(name: String, bytes: ByteArray) {
        val m = masterOrLocked()
        try {
            val sealed = VaultCrypto.b64decode(VaultCrypto.encrypt(bytes, m, name))
            val tmp = File(dir, "$name.tmp")
            tmp.writeBytes(sealed)
            if (!tmp.renameTo(file(name))) throw IllegalStateException("could not store $name")
        } finally {
            m.fill(0)
        }
    }

    override fun delete(name: String) {
        file(name).let { if (it.exists()) { overwrite(it); it.delete() } }
    }

    override fun deleteAll() {
        lock()
        (dir.listFiles() ?: emptyArray()).forEach { overwrite(it); it.delete() }
        try { guard.destroy() } catch (_: Exception) {}
    }

    /** Ciphertext is harmless without the key, but a removed secret's bytes
     *  should not linger on flash either. */
    private fun overwrite(f: File) {
        try { f.writeBytes(ByteArray(f.length().toInt())) } catch (_: Exception) {}
    }

    companion object {
        const val MASTER_FILE = "master.key"
        /** Consecutive unlock failures (not a wrong key) before the person is
         *  told to remove the keys rather than confirm again. */
        const val STUCK_AFTER = 2
    }
}

/**
 * The phone's guard: an Android Keystore AES-256-GCM key that only a strong
 * biometric can use, once per operation, on every API level:
 *
 *  - API 30+: `setUserAuthenticationParameters(0, AUTH_BIOMETRIC_STRONG)`;
 *  - API 24–29: `setUserAuthenticationValidityDurationSeconds(-1)`: a
 *    per-operation key, which only a biometric CryptoObject can authorize
 *    (a PIN or pattern unlock cannot).
 *
 * Invalidated when a new face or finger is enrolled; StrongBox-backed where
 * the phone has one.
 */
class KeystoreMasterGuard(private val alias: String) : MasterGuard {
    private fun keyStore(): KeyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }

    private fun existingKey(): SecretKey? = keyStore().getKey(alias, null) as? SecretKey

    private fun createKey(): SecretKey {
        fun spec(strongBox: Boolean): KeyGenParameterSpec {
            val b = KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .setUserAuthenticationRequired(true)
                .setInvalidatedByBiometricEnrollment(true)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                b.setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
            } else {
                @Suppress("DEPRECATION")
                b.setUserAuthenticationValidityDurationSeconds(-1)
            }
            if (strongBox && Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) b.setIsStrongBoxBacked(true)
            return b.build()
        }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            try {
                gen.init(spec(strongBox = true))
                return gen.generateKey()
            } catch (e: Exception) {
                // No StrongBox here: the TEE-backed key.
            }
        }
        gen.init(spec(strongBox = false))
        return gen.generateKey()
    }

    override fun cipher(mode: Int, iv: ByteArray?): Cipher {
        val key = if (mode == Cipher.ENCRYPT_MODE) {
            // A new master is wrapped under a new key: any old one is gone.
            try { keyStore().deleteEntry(alias) } catch (_: Exception) {}
            createKey()
        } else {
            existingKey() ?: throw SecretStoreInvalidatedException()
        }
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        try {
            if (iv == null) c.init(mode, key) else c.init(mode, key, GCMParameterSpec(128, iv))
        } catch (e: KeyPermanentlyInvalidatedException) {
            throw SecretStoreInvalidatedException()
        }
        return c
    }

    override fun destroy() {
        try { keyStore().deleteEntry(alias) } catch (_: Exception) {}
    }
}

/** A software guard (no authorization): the unit tests' stand-in for the Keystore. */
class SoftwareMasterGuard(private val key: ByteArray = VaultCrypto.randomBytes(32)) : MasterGuard {
    var destroyed = false
    override fun cipher(mode: Int, iv: ByteArray?): Cipher {
        if (destroyed && mode == Cipher.DECRYPT_MODE) throw SecretStoreInvalidatedException()
        destroyed = false
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        val spec = SecretKeySpec(key, "AES")
        if (iv == null) c.init(mode, spec) else c.init(mode, spec, GCMParameterSpec(128, iv))
        return c
    }
    override fun destroy() { destroyed = true }
}
