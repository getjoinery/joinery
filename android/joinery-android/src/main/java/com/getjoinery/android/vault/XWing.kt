package com.getjoinery.android.vault

import com.google.crypto.tink.subtle.X25519
import org.bouncycastle.crypto.digests.SHA3Digest
import org.bouncycastle.crypto.digests.SHAKEDigest
import org.bouncycastle.pqc.crypto.mlkem.MLKEMExtractor
import org.bouncycastle.pqc.crypto.mlkem.MLKEMGenerator
import org.bouncycastle.pqc.crypto.mlkem.MLKEMParameters
import org.bouncycastle.pqc.crypto.mlkem.MLKEMPrivateKeyParameters
import org.bouncycastle.pqc.crypto.mlkem.MLKEMPublicKeyParameters

/**
 * X-Wing (draft-connolly-cfrg-xwing-kem-11): ML-KEM-768 and X25519 in one KEM,
 * the one every runtime seals to (specs/post_quantum_sealing.md R1). Composed
 * from Bouncy Castle's ML-KEM, SHAKE256 and SHA3-256 and Tink's X25519 exactly
 * as `includes/XWing.php` and `sync/jd-crypto/src/xwing.rs` compose it, and held
 * to the same known answers (`tests/vault/fixtures/xwing_vector.json`).
 *
 *  - secret: a 32-byte seed; SHAKE256(seed, 96) gives ML-KEM's d ‖ z (64) and
 *    the X25519 scalar (32);
 *  - public: ML-KEM encapsulation key (1184) ‖ X25519 public (32) = 1216;
 *  - ciphertext: ML-KEM ciphertext (1088) ‖ X25519 ephemeral public (32) = 1120;
 *  - shared secret: SHA3-256(ss_M ‖ ss_X ‖ ct_X ‖ pk_X ‖ label).
 */
object XWing {
    const val SEED_BYTES = 32
    const val PUBLIC_KEY_BYTES = 1216
    const val CIPHERTEXT_BYTES = 1120
    /** Encapsulation randomness: ML-KEM's m (32), then the X25519 ephemeral (32). */
    const val ENCAPS_SEED_BYTES = 64

    private const val MLKEM_PUBLIC_BYTES = 1184
    private const val MLKEM_CIPHERTEXT_BYTES = 1088
    private val LABEL = byteArrayOf(0x5c, 0x2e, 0x2f, 0x2f, 0x5e, 0x5c)
    private val PARAMS = MLKEMParameters.ml_kem_768

    private class Expanded(val dk: MLKEMPrivateKeyParameters, val skX: ByteArray)

    private fun expand(seed: ByteArray): Expanded {
        if (seed.size != SEED_BYTES) throw VaultCryptoException("An X-Wing secret is a 32-byte seed.")
        val out = ByteArray(96)
        val xof = SHAKEDigest(256)
        xof.update(seed, 0, seed.size)
        xof.doFinal(out, 0, out.size)
        val mSeed = out.copyOfRange(0, 64)
        val skX = out.copyOfRange(64, 96)
        VaultCrypto.wipe(out)
        try {
            return Expanded(MLKEMPrivateKeyParameters(PARAMS, mSeed), skX)
        } finally {
            VaultCrypto.wipe(mSeed)
        }
    }

    /** The 1216-byte public key a seed generates. */
    fun publicKey(seed: ByteArray): ByteArray {
        val e = expand(seed)
        try {
            return e.dk.publicKey + X25519.publicFromPrivate(e.skX)
        } finally {
            VaultCrypto.wipe(e.skX)
        }
    }

    private fun combine(ssM: ByteArray, ssX: ByteArray, ctX: ByteArray, pkX: ByteArray): ByteArray {
        val h = SHA3Digest(256)
        for (part in arrayOf(ssM, ssX, ctX, pkX, LABEL)) h.update(part, 0, part.size)
        return ByteArray(32).also { h.doFinal(it, 0) }
    }

    /** X25519 that refuses a non-contributory result (a small-order point). */
    private fun dh(secret: ByteArray, peer: ByteArray): ByteArray {
        val ss = try {
            X25519.computeSharedSecret(secret, peer)
        } catch (e: Exception) {
            throw VaultCryptoException("An X-Wing key or ciphertext is not usable.", e)
        }
        if (ss.all { it == 0.toByte() }) throw VaultCryptoException("An X-Wing key or ciphertext is not usable.")
        return ss
    }

    class Encapsulation(val ciphertext: ByteArray, val sharedSecret: ByteArray)

    /** Encapsulate to a 1216-byte key with fresh randomness. */
    fun encapsulate(public: ByteArray): Encapsulation {
        val eseed = VaultCrypto.randomBytes(ENCAPS_SEED_BYTES)
        try {
            return encapsulateWith(public, eseed)
        } finally {
            VaultCrypto.wipe(eseed)
        }
    }

    /** Encapsulate with the randomness given (draft § EncapsulateDerand). Only
     *  the known-answer vectors pass their own; [encapsulate] draws it fresh. */
    fun encapsulateWith(public: ByteArray, eseed: ByteArray): Encapsulation {
        if (public.size != PUBLIC_KEY_BYTES) throw VaultCryptoException("An X-Wing public key is 1216 bytes.")
        require(eseed.size == ENCAPS_SEED_BYTES) { "X-Wing encapsulation randomness is 64 bytes." }
        val pkM = public.copyOfRange(0, MLKEM_PUBLIC_BYTES)
        val pkX = public.copyOfRange(MLKEM_PUBLIC_BYTES, PUBLIC_KEY_BYTES)
        val m = eseed.copyOfRange(0, 32)
        val ekX = eseed.copyOfRange(32, 64)
        try {
            val enc = try {
                MLKEMGenerator.internalGenerateEncapsulated(MLKEMPublicKeyParameters(PARAMS, pkM), m)
            } catch (e: Exception) {
                throw VaultCryptoException("An X-Wing public key is not usable.", e)
            }
            val ssM = enc.secret
            val ctX = X25519.publicFromPrivate(ekX)
            val ssX = dh(ekX, pkX)
            try {
                return Encapsulation(enc.encapsulation + ctX, combine(ssM, ssX, ctX, pkX))
            } finally {
                VaultCrypto.wipe(ssM); VaultCrypto.wipe(ssX)
            }
        } finally {
            VaultCrypto.wipe(m); VaultCrypto.wipe(ekX)
        }
    }

    /** The shared secret a 1120-byte ciphertext carries for this seed. */
    fun decapsulate(seed: ByteArray, ciphertext: ByteArray): ByteArray {
        if (ciphertext.size != CIPHERTEXT_BYTES) throw VaultCryptoException("An X-Wing ciphertext is 1120 bytes.")
        val e = expand(seed)
        try {
            val ctM = ciphertext.copyOfRange(0, MLKEM_CIPHERTEXT_BYTES)
            val ctX = ciphertext.copyOfRange(MLKEM_CIPHERTEXT_BYTES, CIPHERTEXT_BYTES)
            val ssM = MLKEMExtractor(e.dk).extractSecret(ctM)
            val ssX = dh(e.skX, ctX)
            try {
                return combine(ssM, ssX, ctX, X25519.publicFromPrivate(e.skX))
            } finally {
                VaultCrypto.wipe(ssM); VaultCrypto.wipe(ssX)
            }
        } finally {
            VaultCrypto.wipe(e.skX)
        }
    }
}
