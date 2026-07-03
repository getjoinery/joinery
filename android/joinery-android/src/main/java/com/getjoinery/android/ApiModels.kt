package com.getjoinery.android

import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * The session-key pair minted by `auth/login`. The secret is only ever held in
 * memory and Keystore-backed EncryptedSharedPreferences.
 */
data class ApiCredentials(val publicKey: String, val secretKey: String)

/** Subscription tier summary from `auth/login` / `auth/session`. Null for users
 *  with no subscription. */
data class TierSummary(
    val name: String,
    val tierLevel: Int,
    val features: Map<String, Boolean>,
) {
    companion object {
        fun from(json: JsonValue?): TierSummary? {
            val name = json?.get("name")?.stringValue ?: return null
            val features = LinkedHashMap<String, Boolean>()
            json["features"]?.objectValue?.forEach { (key, value) ->
                features[key] = value.boolValue ?: false
            }
            return TierSummary(name, json["tier_level"]?.intValue ?: 0, features)
        }
    }
}

/** The "who am I" summary from `auth/login` and `auth/session`. */
data class UserSummary(
    val userId: Int,
    val firstName: String,
    val lastName: String,
    val displayName: String,
    val email: String,
    val permission: Int,
    val tier: TierSummary?,
) {
    companion object {
        fun from(json: JsonValue?): UserSummary? {
            val userId = json?.get("user_id")?.intValue ?: return null
            return UserSummary(
                userId = userId,
                firstName = json["first_name"]?.stringValue ?: "",
                lastName = json["last_name"]?.stringValue ?: "",
                displayName = json["display_name"]?.stringValue ?: "",
                email = json["email"]?.stringValue ?: "",
                permission = json["permission"]?.intValue ?: 0,
                tier = TierSummary.from(json["tier"]),
            )
        }

        /** Shown when the app launches offline with stored credentials. */
        val offlinePlaceholder: UserSummary
            get() = UserSummary(0, "", "", "", "", 0, null)
    }
}

/** Successful `auth/login` payload. */
data class LoginResult(
    val credentials: ApiCredentials,
    val expiresTime: Date?,
    val user: UserSummary?,
) {
    companion object {
        fun from(data: JsonValue?): LoginResult? {
            val publicKey = data?.get("public_key")?.stringValue ?: return null
            val secretKey = data["secret_key"]?.stringValue ?: return null
            return LoginResult(
                credentials = ApiCredentials(publicKey, secretKey),
                expiresTime = JoineryTimestamp.parse(data["expires_time"]?.stringValue),
                user = UserSummary.from(data["user"]),
            )
        }
    }
}

/** API timestamps: `YYYY-MM-DD HH:MM:SS`, UTC, no zone suffix
 *  (docs/api.md § Timestamps). */
object JoineryTimestamp {
    private fun formatter(): SimpleDateFormat =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
            isLenient = false
        }

    fun parse(string: String?): Date? {
        if (string.isNullOrEmpty()) return null
        return try {
            formatter().parse(string)
        } catch (e: Exception) {
            null
        }
    }

    fun format(date: Date): String = formatter().format(date)
}
