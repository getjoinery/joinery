package com.getjoinery.android

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Session-key storage. Secrets live only in Keystore-backed
 * EncryptedSharedPreferences — never plain SharedPreferences or files (spec
 * security note). The master key sits in the AndroidKeyStore; the values file
 * is AES-256-GCM encrypted with it.
 */
class EncryptedCredentialStore(context: Context, fileName: String) : CredentialStore {

    private val prefs: SharedPreferences by lazy {
        val app = context.applicationContext
        val masterKey = MasterKey.Builder(app)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            app,
            fileName,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    override fun loadCredentials(): ApiCredentials? {
        val publicKey = prefs.getString(KEY_PUBLIC, null) ?: return null
        val secretKey = prefs.getString(KEY_SECRET, null) ?: return null
        return ApiCredentials(publicKey, secretKey)
    }

    override fun saveCredentials(credentials: ApiCredentials) {
        prefs.edit()
            .putString(KEY_PUBLIC, credentials.publicKey)
            .putString(KEY_SECRET, credentials.secretKey)
            .apply()
    }

    override fun deleteCredentials() {
        prefs.edit().remove(KEY_PUBLIC).remove(KEY_SECRET).apply()
    }

    private companion object {
        const val KEY_PUBLIC = "joinery.session.public_key"
        const val KEY_SECRET = "joinery.session.secret_key"
    }
}
