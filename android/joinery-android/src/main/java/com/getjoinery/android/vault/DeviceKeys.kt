package com.getjoinery.android.vault

import kotlinx.coroutines.sync.withLock
import android.content.Context

/**
 * What this phone holds for end-to-end encrypted content
 * (specs/fortress_mobile_apps.md § R2–R3, R9):
 *
 *  - **the device key**: an X25519 keypair generated once, at first
 *    enrollment. Its public half is posted to the server so the browser can
 *    seal a vault's secret to it. Kept across sign-out and across a retired
 *    vault key, so the next enrollment approves the same public key;
 *  - **each vault secret** the phone was handed (`mail`), as PKCS#8, and the
 *    public key derived from it (plain, so the retired-key check needs no
 *    prompt);
 *  - **per-scope extras** the mail features keep beside the secret (the
 *    search index's record key, the AI endpoint key) — gone with the secret.
 *
 * Every secret sits in the [SecretStore] behind the biometric gate and, once
 * the person confirms, in [held] until the lock rule drops it.
 */
class DeviceKeys(
    private val store: SecretStore,
    private val prefs: KeyValueStore,
    val held: HeldKeys = HeldKeys(),
) {
    init {
        // The store shuts whenever the held keys go (the background lock,
        // sign-out): nothing stays readable without another prompt.
        held.onDropAll { store.lock() }
    }

    // MARK: Unlocking (a biometric crypto operation)

    /** Whether the store is open (secrets can be read and written now). */
    val storeUnlocked: Boolean get() = store.isUnlocked

    /** The Cipher a strong-biometric prompt must authorize to open the store,
     *  or null when the store needs none. Throws
     *  [SecretStoreInvalidatedException] (after wiping) when a new biometric
     *  destroyed the key. */
    fun unlockCipher(): javax.crypto.Cipher? = try {
        store.unlockCipher()
    } catch (e: SecretStoreInvalidatedException) {
        forgetEverything()
        throw e
    }

    /**
     * One unlock at a time (R14): on first use an unlock makes the Keystore
     * key, so two at once would remake it under each other. A second caller
     * waits, then finds the store open and asks nothing.
     * [prompt] shows the biometric prompt for the Cipher (null: nothing to
     * authorize) and answers the authorized Cipher, or throws.
     */
    suspend fun unlock(prompt: suspend (javax.crypto.Cipher?) -> javax.crypto.Cipher?) {
        unlockMutex.withLock {
            if (store.isUnlocked) {
                loadAll()
                return
            }
            val cipher = unlockCipher()
            completeUnlock(prompt(cipher))
        }
    }

    private val unlockMutex = kotlinx.coroutines.sync.Mutex()

    /** Finish an unlock with the authorized Cipher, then hold every secret. */
    fun completeUnlock(cipher: javax.crypto.Cipher?) {
        try {
            store.unlockWith(cipher)
        } catch (e: SecretStoreInvalidatedException) {
            forgetEverything()
            throw e
        }
        loadAll()
    }

    // MARK: The device key

    /** The device's X25519 public key (std base64), or null before the first
     *  enrollment made one. Public — readable without a prompt. */
    val devicePublicKey: String?
        get() = prefs.get(PREF_DEVICE_PUB)

    /**
     * The device public key, generating and storing the keypair on first use.
     * Generating writes the secret, so the biometric window must be open then.
     * The fresh secret is also held, so the handoff can be opened without a
     * second prompt.
     */
    fun ensureDeviceKey(): String {
        devicePublicKey?.let { pub ->
            if (store.names().contains(DEVICE_SECRET)) return pub
        }
        val pair = VaultCrypto.generateKeypair()
        try {
            store.write(DEVICE_SECRET, pair.secret)
            held.hold(DEVICE_SECRET, pair.secret)
            prefs.put(PREF_DEVICE_PUB, pair.publicKeyB64)
            return pair.publicKeyB64
        } finally {
            VaultCrypto.wipe(pair.secret)
        }
    }

    // MARK: Vault secrets

    /** Whether this phone was handed [scope]'s key (it may be locked right now). */
    fun holds(scope: String): Boolean =
        prefs.get(prefScopePub(scope)) != null && store.names().contains(scopeSecret(scope))

    /** Whether [scope]'s key is open in memory now. */
    fun isOpen(scope: String): Boolean = held.has(scopeSecret(scope))

    /** The public key of the secret this phone holds for [scope], derived when
     *  it was stored. */
    fun scopePublicKey(scope: String): String? = prefs.get(prefScopePub(scope))

    /**
     * Read every stored secret into memory. The store must be unlocked
     * ([completeUnlock] does both); throws [SecretStoreLockedException] when it
     * is not and [SecretStoreInvalidatedException] when a new biometric wiped
     * the key.
     */
    fun loadAll() {
        try {
            for (name in store.names()) {
                val bytes = store.read(name) ?: continue
                try { held.hold(name, bytes) } finally { VaultCrypto.wipe(bytes) }
            }
        } catch (e: SecretStoreInvalidatedException) {
            forgetEverything()
            throw e
        }
    }

    /**
     * Open a vault secret the browser sealed to this device (the device-link
     * handoff) and keep it: stored behind the gate and held. Refuses a secret
     * that is not the vault's current or pending key when [expectedPublicKeys]
     * is non-empty. Returns the derived public key.
     */
    fun acceptHandoff(scope: String, sealedB64: String, expectedPublicKeys: List<String> = emptyList()): String {
        val scalar = held.get(DEVICE_SECRET) ?: store.read(DEVICE_SECRET)
            ?: throw IllegalStateException("This phone has no device key to open the handoff with.")
        try {
            val pkcs8 = VaultCrypto.openWithScalar(sealedB64, scalar)
            try {
                val pub = VaultCrypto.publicKeyFromSecret(pkcs8)
                if (expectedPublicKeys.isNotEmpty() && pub !in expectedPublicKeys) {
                    throw VaultCryptoException("The key handed to this phone is not your vault's key.")
                }
                store.write(scopeSecret(scope), pkcs8)
                prefs.put(prefScopePub(scope), pub)
                held.hold(scopeSecret(scope), pkcs8)
                return pub
            } finally {
                VaultCrypto.wipe(pkcs8)
            }
        } finally {
            VaultCrypto.wipe(scalar)
        }
    }

    /** Open a blob sealed to [scope]'s key (a row's DEK). Needs the key held. */
    fun openSealed(scope: String, sealedB64: String): ByteArray {
        val secret = held.get(scopeSecret(scope)) ?: throw SecretStoreLockedException()
        try {
            return VaultCrypto.openWithSecret(sealedB64, secret)
        } finally {
            VaultCrypto.wipe(secret)
        }
    }

    /** `HMAC(HKDF(secret, info), message)` under [scope]'s secret (the relay pin). */
    fun macWith(scope: String, info: String, message: ByteArray): ByteArray {
        val secret = held.get(scopeSecret(scope)) ?: throw SecretStoreLockedException()
        try {
            return VaultCrypto.macFromSecret(secret, info, message)
        } finally {
            VaultCrypto.wipe(secret)
        }
    }

    /** A key derived from [scope]'s secret: HKDF-SHA256(secret, salt '', [info]).
     *  Nothing is stored, so it comes back with the same key after a re-enrollment. */
    fun deriveFrom(scope: String, info: String): ByteArray {
        val secret = held.get(scopeSecret(scope)) ?: throw SecretStoreLockedException()
        try {
            return VaultCrypto.hkdf(secret, null, info)
        } finally {
            VaultCrypto.wipe(secret)
        }
    }

    // MARK: Extras kept beside a scope's secret

    /** A random secret kept beside [scope]'s key under [name] — made once,
     *  stored behind the gate, gone when the scope is forgotten. */
    fun scopeExtra(scope: String, name: String, size: Int = 32): ByteArray {
        val key = extraName(scope, name)
        held.get(key)?.let { return it }
        val existing = store.read(key)
        if (existing != null) {
            held.hold(key, existing)
            return existing
        }
        val fresh = VaultCrypto.randomBytes(size)
        store.write(key, fresh)
        held.hold(key, fresh)
        return fresh
    }

    /** A stored extra value (not generated), or null. Held copy first. */
    fun readExtra(scope: String, name: String): ByteArray? {
        val key = extraName(scope, name)
        return held.get(key) ?: try { store.read(key)?.also { held.hold(key, it) } } catch (e: SecretStoreLockedException) { null }
    }

    fun writeExtra(scope: String, name: String, bytes: ByteArray?) {
        val key = extraName(scope, name)
        if (bytes == null) {
            store.delete(key)
            held.drop(key)
        } else {
            store.write(key, bytes)
            held.hold(key, bytes)
        }
    }

    // MARK: Forgetting

    /**
     * Wipe [scope]'s secret and its extras, from storage and memory — a retired
     * key, a device no longer listed as holding it, a recovery-code use. The
     * device key stays, so a re-enrollment approves the same public key.
     */
    fun forgetScope(scope: String) {
        val prefix = "scope.$scope"
        store.names().filter { it == prefix || it.startsWith("$prefix.") }.forEach { store.delete(it); held.drop(it) }
        held.drop(scopeSecret(scope))
        prefs.put(prefScopePub(scope), null)
        // A key forgotten while locked was never held, so the drop above
        // changed nothing a screen observes; the stored key did change.
        held.changed()
    }

    /** Sign-out and a 401: every vault secret goes; the device key stays. */
    fun onSignOut() {
        prefs.keys().filter { it.startsWith("fortress.scope.") && it.endsWith(".pub") }
            .map { it.removePrefix("fortress.scope.").removeSuffix(".pub") }
            .forEach { forgetScope(it) }
        store.names().filter { it.startsWith("scope.") }.forEach { store.delete(it) }
        held.dropAll()
    }

    /** "Remove keys from this phone": everything, the device key included. */
    fun forgetEverything() {
        store.deleteAll()
        prefs.keys().filter { it.startsWith("fortress.") }.forEach { prefs.put(it, null) }
        held.dropAll()
        held.changed()
    }

    companion object {
        const val DEVICE_SECRET = "device.x25519"
        private const val PREF_DEVICE_PUB = "fortress.device.pub"

        fun scopeSecret(scope: String) = "scope.$scope"
        private fun prefScopePub(scope: String) = "fortress.scope.$scope.pub"
        private fun extraName(scope: String, name: String) = "scope.$scope.$name"

        @Volatile
        private var shared: DeviceKeys? = null

        /** The app-wide instance: Keystore-backed, one per process. */
        fun shared(context: Context): DeviceKeys {
            shared?.let { return it }
            synchronized(this) {
                shared?.let { return it }
                val app = context.applicationContext
                val prefs = PrefsKeyValueStore(app.getSharedPreferences("joinery.fortress", Context.MODE_PRIVATE))
                resetV1(app, prefs)
                val keys = DeviceKeys(
                    store = WrappedSecretStore(java.io.File(app.noBackupFilesDir, "joinery_fortress_v2"),
                        KeystoreMasterGuard("joinery.fortress.master.v2")),
                    prefs = prefs,
                )
                shared = keys
                HeldKeysLifecycle.install(keys.held)
                return keys
            }
        }

        /**
         * v1 wrapped each secret under a time-window key a PIN could open on
         * API 24-29. An install that still has it starts over: its files, its
         * Keystore entry and the public keys it recorded all go (R13). Nothing
         * of v2 is touched.
         */
        private fun resetV1(app: Context, prefs: KeyValueStore) {
            val ks = try { java.security.KeyStore.getInstance("AndroidKeyStore").apply { load(null) } } catch (e: Exception) { null }
            resetV1(
                v1Dir = java.io.File(app.noBackupFilesDir, "joinery_fortress"),
                hasV1Key = try { ks?.containsAlias("joinery.fortress.wrap.v1") == true } catch (e: Exception) { false },
                deleteV1Key = { try { ks?.deleteEntry("joinery.fortress.wrap.v1") } catch (_: Exception) {} },
                prefs = prefs,
            )
        }

        /** The reset itself, apart from Android: true when there was a v1 to reset. */
        internal fun resetV1(v1Dir: java.io.File, hasV1Key: Boolean, deleteV1Key: () -> Unit, prefs: KeyValueStore): Boolean {
            if (!v1Dir.exists() && !hasV1Key) return false
            v1Dir.deleteRecursively()
            deleteV1Key()
            prefs.keys().filter { it.startsWith("fortress.") }.forEach { prefs.put(it, null) }
            return true
        }

        /** The instance if one was made in this process (sign-out wipes it). */
        fun sharedIfCreated(): DeviceKeys? = shared
    }
}
