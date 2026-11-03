package com.getjoinery.android.vault

import com.google.crypto.tink.subtle.Ed25519Verify
import com.google.crypto.tink.subtle.Hkdf
import okio.ByteString.Companion.decodeBase64
import okio.ByteString.Companion.toByteString
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.security.GeneralSecurityException
import java.security.SecureRandom
import java.util.zip.GZIPInputStream
import java.util.zip.GZIPOutputStream
import javax.crypto.Cipher
import javax.crypto.Mac
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/** A blob that would not open: wrong key, wrong AD, tampered bytes, or bad
 *  framing. Callers show "cannot be opened" and never the cause. */
class VaultCryptoException(message: String, cause: Throwable? = null) : Exception(message, cause)

/**
 * The Sealed Vault's client-custody primitives on Android — the same bytes as
 * `assets/js/vault-crypto.js` and `sync/jd-crypto/src/vault.rs`, pinned by
 * `tests/vault/fixtures/xwing_vector.json`, `device_handoff_vector.json` and
 * `edge_vector.json`.
 *
 *   sealed blob  : base64( ct[1120] ‖ IV[12] ‖ AES-GCM ct )
 *                  X-Wing to the vault's 1216-byte public key; AES key =
 *                  HKDF-SHA256(shared secret, salt '', info 'joinery-seal:v2:dek'),
 *                  no AAD (specs/post_quantum_sealing.md R2)
 *   content blob : base64( IV[12] ‖ AES-GCM ct ‖ tag[16] ), AAD = caller's string
 *
 * A vault secret, and this phone's device secret, is a 32-byte X-Wing seed
 * ([XWing]); its public key is worked out from it.
 *
 * Bouncy Castle supplies ML-KEM and SHA-3, Tink X25519, HKDF and Ed25519
 * (java.security has no X25519 before API 33); AES-GCM and HMAC are the
 * platform's own. Everything here is pure — no Android types — so it runs
 * under JVM unit tests against the shared vectors.
 */
object VaultCrypto {
    private const val SEAL_INFO = "joinery-seal:v2:"
    const val SCOPE_DEK = "dek"
    private val random = SecureRandom()

    // MARK: Bytes

    fun randomBytes(n: Int): ByteArray = ByteArray(n).also { random.nextBytes(it) }

    /** Standard base64 with padding, the only encoding the platform's blobs use. */
    fun b64encode(bytes: ByteArray): String = bytes.toByteString().base64()

    fun b64decode(text: String): ByteArray =
        text.trim().decodeBase64()?.toByteArray() ?: throw VaultCryptoException("Not base64.")

    fun hex(text: String): ByteArray {
        val out = ByteArray(text.length / 2)
        for (i in out.indices) out[i] = text.substring(i * 2, i * 2 + 2).toInt(16).toByte()
        return out
    }

    fun toHex(bytes: ByteArray): String = bytes.joinToString("") { "%02x".format(it.toInt() and 0xFF) }

    /** Overwrite a secret in place. Dropped keys are zeroed, not left to the GC. */
    fun wipe(bytes: ByteArray?) {
        bytes?.fill(0)
    }

    // MARK: Keys

    /** A fresh keypair: the 32-byte seed and the 1216-byte public key. */
    class Keypair(val secret: ByteArray, val publicKey: ByteArray) {
        val publicKeyB64: String get() = b64encode(publicKey)
    }

    fun generateKeypair(): Keypair {
        val seed = randomBytes(XWing.SEED_BYTES)
        return Keypair(seed, XWing.publicKey(seed))
    }

    /** The public key (std base64) of a seed — derived, never stored beside
     *  it, so the two can never be out of step. */
    fun publicKeyFromSecret(seed: ByteArray): String = b64encode(XWing.publicKey(seed))

    /** True for a stored public key this app cannot seal with (present, but
     *  not 1216 bytes): whatever install holds it starts over. */
    fun isUnsealablePublicKey(publicB64: String?): Boolean =
        publicB64 != null && (publicB64.trim().decodeBase64()?.size ?: 0) != XWing.PUBLIC_KEY_BYTES

    // MARK: Sealing

    private fun sealKey(sharedSecret: ByteArray, scope: String): ByteArray =
        Hkdf.computeHkdf("HMACSHA256", sharedSecret, ByteArray(0), (SEAL_INFO + scope).toByteArray(Charsets.UTF_8), 32)

    /** The seal with its randomness supplied (the vector tests), raw bytes. */
    internal fun sealWith(data: ByteArray, recipient: ByteArray, scope: String, eseed: ByteArray?, iv: ByteArray): ByteArray {
        val enc = if (eseed != null) XWing.encapsulateWith(recipient, eseed) else XWing.encapsulate(recipient)
        val key = sealKey(enc.sharedSecret, scope)
        try {
            return enc.ciphertext + iv + gcm(Cipher.ENCRYPT_MODE, key, iv, null, data)
        } finally {
            wipe(enc.sharedSecret); wipe(key)
        }
    }

    /** Seal [data] to a 1216-byte X-Wing public key (std base64). */
    fun sealToPublicKey(data: ByteArray, recipientPublicB64: String): String {
        val recipient = b64decode(recipientPublicB64)
        if (recipient.size != XWing.PUBLIC_KEY_BYTES) throw VaultCryptoException("A public key is 1216 bytes.")
        return b64encode(sealWith(data, recipient, SCOPE_DEK, null, randomBytes(12)))
    }

    /** Open raw seal bytes with a seed under [scope]. */
    internal fun openRaw(raw: ByteArray, seed: ByteArray, scope: String): ByteArray {
        val head = XWing.CIPHERTEXT_BYTES
        if (raw.size < head + 12 + 16) throw VaultCryptoException("Sealed blob too short.")
        val ss = XWing.decapsulate(seed, raw.copyOfRange(0, head))
        val key = sealKey(ss, scope)
        try {
            return gcm(Cipher.DECRYPT_MODE, key, raw.copyOfRange(head, head + 12), null, raw.copyOfRange(head + 12, raw.size))
        } finally {
            wipe(ss); wipe(key)
        }
    }

    /** Open a sealed blob with the recipient's seed (the browser's
     *  `openFromSecretKey`, scope 'dek'). */
    fun openWithSecret(sealedB64: String, seed: ByteArray): ByteArray =
        openRaw(b64decode(sealedB64), seed, SCOPE_DEK)

    // MARK: Content blobs

    /** `base64(IV ‖ ct ‖ tag)` under [key] with [ad] as AAD. */
    fun encrypt(plaintext: ByteArray, key: ByteArray, ad: String?): String {
        val iv = randomBytes(12)
        return b64encode(iv + gcm(Cipher.ENCRYPT_MODE, key, iv, ad?.toByteArray(Charsets.UTF_8), plaintext))
    }

    fun encryptString(plaintext: String, key: ByteArray, ad: String?): String =
        encrypt(plaintext.toByteArray(Charsets.UTF_8), key, ad)

    fun decrypt(blobB64: String, key: ByteArray, ad: String?): ByteArray =
        decryptRaw(b64decode(blobB64), key, ad)

    /** Open raw `IV ‖ ct ‖ tag` bytes — a part's content is this, base64 in a
     *  field or stored raw as a file's bytes. */
    fun decryptRaw(raw: ByteArray, key: ByteArray, ad: String?): ByteArray {
        if (raw.size < 12 + 16) throw VaultCryptoException("Blob too short.")
        return gcm(
            Cipher.DECRYPT_MODE, key, raw.copyOfRange(0, 12), ad?.toByteArray(Charsets.UTF_8),
            raw.copyOfRange(12, raw.size),
        )
    }

    fun decryptString(blobB64: String, key: ByteArray, ad: String?): String =
        String(decrypt(blobB64, key, ad), Charsets.UTF_8)

    private fun gcm(mode: Int, key: ByteArray, iv: ByteArray, ad: ByteArray?, input: ByteArray): ByteArray {
        try {
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(mode, SecretKeySpec(key, "AES"), GCMParameterSpec(128, iv))
            if (ad != null) cipher.updateAAD(ad)
            return cipher.doFinal(input)
        } catch (e: GeneralSecurityException) {
            throw VaultCryptoException("The blob would not open.", e)
        }
    }

    // MARK: Derivations

    fun hkdf(ikm: ByteArray, salt: ByteArray?, info: String, size: Int = 32): ByteArray =
        Hkdf.computeHkdf("HMACSHA256", ikm, salt ?: ByteArray(0), info.toByteArray(Charsets.UTF_8), size)

    fun hmacSha256(key: ByteArray, message: ByteArray): ByteArray {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(key, "HmacSHA256"))
        return mac.doFinal(message)
    }

    /** `HMAC(HKDF(secret, info), message)` — the browser's `macFromSecret`,
     *  used for the relay pin. [secret] is the vault's seed, as the keyring holds it. */
    fun macFromSecret(secret: ByteArray, info: String, message: ByteArray): ByteArray {
        val key = hkdf(secret, null, info)
        try {
            return hmacSha256(key, message)
        } finally {
            wipe(key)
        }
    }

    fun verifyEd25519(publicKeyB64: String, message: ByteArray, signatureB64: String): Boolean = try {
        Ed25519Verify(b64decode(publicKeyB64)).verify(b64decode(signatureB64), message)
        true
    } catch (e: Exception) {
        false
    }

    fun sha256(bytes: ByteArray): ByteArray = java.security.MessageDigest.getInstance("SHA-256").digest(bytes)

    // MARK: gzip (the `gz:` field prefix)

    fun gunzip(bytes: ByteArray): ByteArray =
        GZIPInputStream(ByteArrayInputStream(bytes)).use { it.readBytes() }

    fun gzip(bytes: ByteArray): ByteArray {
        val out = ByteArrayOutputStream()
        GZIPOutputStream(out).use { it.write(bytes) }
        return out.toByteArray()
    }

    fun constantTimeEquals(a: ByteArray, b: ByteArray): Boolean =
        java.security.MessageDigest.isEqual(a, b)
}
