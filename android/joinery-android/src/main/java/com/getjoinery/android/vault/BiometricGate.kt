package com.getjoinery.android.vault

import android.content.Context
import android.content.ContextWrapper
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * The face-or-fingerprint confirmation that opens the Keystore key the
 * phone's secrets are wrapped under. Strong biometrics only: a stolen phone
 * with a known PIN still opens nothing (specs/fortress_mobile_apps.md § R1).
 */
object BiometricGate {
    enum class Availability { READY, NONE_ENROLLED, UNAVAILABLE }

    sealed class Outcome {
        object Confirmed : Outcome()
        /** Confirmed, and the prompt authorized this Cipher. */
        class Authorized(val cipher: javax.crypto.Cipher?) : Outcome()
        object Cancelled : Outcome()
        data class Failed(val message: String) : Outcome()
    }

    fun availability(context: Context): Availability =
        when (BiometricManager.from(context).canAuthenticate(BIOMETRIC_STRONG)) {
            BiometricManager.BIOMETRIC_SUCCESS -> Availability.READY
            BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> Availability.NONE_ENROLLED
            else -> Availability.UNAVAILABLE
        }

    /** The hosting FragmentActivity of a Compose context. */
    fun activity(context: Context): FragmentActivity? {
        var c: Context? = context
        while (c != null) {
            if (c is FragmentActivity) return c
            c = (c as? ContextWrapper)?.baseContext
        }
        return null
    }

    const val NOT_SET_UP = "Set up a fingerprint or face unlock on this phone to read end-to-end encrypted mail here."

    /**
     * A strong-biometric prompt. With [cipher] the prompt authorizes that
     * Cipher (a CryptoObject): the store's key opens to a biometric only,
     * never to a PIN, on every API level.
     */
    suspend fun confirm(activity: FragmentActivity, title: String, subtitle: String, cipher: javax.crypto.Cipher? = null): Outcome {
        when (availability(activity)) {
            Availability.READY -> {}
            Availability.NONE_ENROLLED, Availability.UNAVAILABLE -> return Outcome.Failed(NOT_SET_UP)
        }
        return suspendCancellableCoroutine { cont ->
            val prompt = BiometricPrompt(
                activity,
                ContextCompat.getMainExecutor(activity),
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                        if (!cont.isActive) return
                        cont.resume(if (cipher != null) Outcome.Authorized(result.cryptoObject?.cipher) else Outcome.Confirmed)
                    }

                    override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                        if (!cont.isActive) return
                        val cancelled = errorCode == BiometricPrompt.ERROR_USER_CANCELED ||
                            errorCode == BiometricPrompt.ERROR_NEGATIVE_BUTTON ||
                            errorCode == BiometricPrompt.ERROR_CANCELED
                        cont.resume(if (cancelled) Outcome.Cancelled else Outcome.Failed(errString.toString()))
                    }
                },
            )
            val info = BiometricPrompt.PromptInfo.Builder()
                .setTitle(title)
                .setSubtitle(subtitle)
                .setAllowedAuthenticators(BIOMETRIC_STRONG)
                .setNegativeButtonText("Cancel")
                .build()
            if (cipher != null) prompt.authenticate(info, BiometricPrompt.CryptoObject(cipher)) else prompt.authenticate(info)
            cont.invokeOnCancellation { try { prompt.cancelAuthentication() } catch (_: Exception) {} }
        }
    }

    private class PromptRefused(val text: String) : Exception(text)

    /**
     * Open the secret store with one prompt and hold every secret. Returns
     * null on success or the message to show. Already open: nothing to ask.
     * Unlocks are serialized (DeviceKeys.unlock).
     */
    suspend fun unlock(activity: FragmentActivity, keys: DeviceKeys, reason: String, title: String = "Unlock encrypted mail"): String? =
        try {
            keys.unlock { cipher ->
                when (val o = confirm(activity, title, reason, cipher)) {
                    is Outcome.Authorized -> o.cipher
                    Outcome.Confirmed -> null
                    Outcome.Cancelled -> throw PromptRefused("Unlock cancelled.")
                    is Outcome.Failed -> throw PromptRefused(o.message)
                }
            }
            null
        } catch (e: PromptRefused) {
            e.text
        } catch (e: SecretStoreInvalidatedException) {
            e.message
        } catch (e: SecretStoreStuckException) {
            e.message
        } catch (e: SecretStoreLockedException) {
            e.message
        } catch (e: Exception) {
            e.message ?: "This phone's secure storage is not available."
        }
}
