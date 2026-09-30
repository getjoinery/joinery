package com.getjoinery.android.vault

import com.google.crypto.tink.subtle.Ed25519Verify
import com.google.crypto.tink.subtle.Hkdf
import com.google.crypto.tink.subtle.X25519
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
 * `tests/vault/fixtures/edge_vector.json`.
 *
 *   sealed blob  : base64( ephPub[32] ‖ IV[12] ‖ AES-GCM ct )
 *                  AES key = HKDF-SHA256(X25519(eph, recipient), salt '',
 *                  info 'sealed-vault:dek' ‖ ephPub ‖ recipientPub), no AAD
 *   content blob : base64( IV[12] ‖ AES-GCM ct ‖ tag[16] ), AAD = caller's string
 *
 * A vault secret travels as PKCS#8 (48 bytes: the fixed X25519 prefix and the
 * 32-byte scalar), which is what the browser exports and seals to a device.
 *
 * Tink supplies X25519, HKDF and Ed25519 (java.security has no X25519 before
 * API 33); AES-GCM and HMAC are the platform's own. Everything here is pure —
 * no Android types — so it runs under JVM unit tests against the shared vectors.
 */
object VaultCrypto {
    private val PKCS8_PREFIX = hex("302e020100300506032b656e04220420")
    private const val SEAL_INFO = "sealed-vault:dek"
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

    // MARK: X25519 keys

    /** A fresh X25519 keypair: raw 32-byte scalar and raw 32-byte public key. */
    class Keypair(val secret: ByteArray, val publicKey: ByteArray) {
        val publicKeyB64: String get() = b64encode(publicKey)
    }

    fun generateKeypair(): Keypair {
        val secret = X25519.generatePrivateKey()
        return Keypair(secret, X25519.publicFromPrivate(secret))
    }

    fun publicFromScalar(scalar: ByteArray): ByteArray = X25519.publicFromPrivate(scalar)

    /** PKCS#8 → the raw 32-byte scalar. Refuses anything but the X25519 shape. */
    fun pkcs8Decode(der: ByteArray): ByteArray {
        if (der.size != PKCS8_PREFIX.size + 32 ||
            !der.copyOfRange(0, PKCS8_PREFIX.size).contentEquals(PKCS8_PREFIX)
        ) {
            throw VaultCryptoException("Not an X25519 PKCS#8 secret key.")
        }
        return der.copyOfRange(PKCS8_PREFIX.size, der.size)
    }

    fun pkcs8Encode(scalar: ByteArray): ByteArray {
        require(scalar.size == 32) { "An X25519 scalar is 32 bytes." }
        return PKCS8_PREFIX + scalar
    }

    /** The public key (std base64) of a PKCS#8 vault secret — derived, never
     *  stored beside it, so the two can never be out of step. */
    fun publicKeyFromSecret(pkcs8: ByteArray): String {
        val scalar = pkcs8Decode(pkcs8)
        try {
            return b64encode(X25519.publicFromPrivate(scalar))
        } finally {
            wipe(scalar)
        }
    }

    // MARK: Sealing

    private fun sealKey(shared: ByteArray, ephPub: ByteArray, recipientPub: ByteArray): ByteArray {
        val info = SEAL_INFO.toByteArray(Charsets.UTF_8) + ephPub + recipientPub
        return Hkdf.computeHkdf("HMACSHA256", shared, ByteArray(0), info, 32)
    }

    /** Seal [data] to an X25519 public key (std base64). */
    fun sealToPublicKey(data: ByteArray, recipientPublicB64: String): String {
        val recipient = b64decode(recipientPublicB64)
        if (recipient.size != 32) throw VaultCryptoException("A public key is 32 bytes.")
        val eph = generateKeypair()
        val shared = X25519.computeSharedSecret(eph.secret, recipient)
        val key = sealKey(shared, eph.publicKey, recipient)
        try {
            val iv = randomBytes(12)
            val ct = gcm(Cipher.ENCRYPT_MODE, key, iv, null, data)
            return b64encode(eph.publicKey + iv + ct)
        } finally {
            wipe(shared); wipe(key); wipe(eph.secret)
        }
    }

    /** Open a sealed blob with the recipient's raw scalar. The recipient's
     *  public key is derived from it (the KDF mixes it in). */
    fun openWithScalar(sealedB64: String, scalar: ByteArray): ByteArray {
        val raw = b64decode(sealedB64)
        if (raw.size < 32 + 12 + 16) throw VaultCryptoException("Sealed blob too short.")
        val ephPub = raw.copyOfRange(0, 32)
        val iv = raw.copyOfRange(32, 44)
        val ct = raw.copyOfRange(44, raw.size)
        val recipientPub = X25519.publicFromPrivate(scalar)
        val shared = try {
            X25519.computeSharedSecret(scalar, ephPub)
        } catch (e: Exception) {
            throw VaultCryptoException("Sealed blob has a bad key.", e)
        }
        val key = sealKey(shared, ephPub, recipientPub)
        try {
            return gcm(Cipher.DECRYPT_MODE, key, iv, null, ct)
        } finally {
            wipe(shared); wipe(key)
        }
    }

    /** Open a sealed blob with a PKCS#8 vault secret (the browser's
     *  `openFromSecretKey`). */
    fun openWithSecret(sealedB64: String, pkcs8: ByteArray): ByteArray {
        val scalar = pkcs8Decode(pkcs8)
        try {
            return openWithScalar(sealedB64, scalar)
        } finally {
            wipe(scalar)
        }
    }

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
     *  used for the relay pin. [secret] is the PKCS#8 bytes, as in the browser. */
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
