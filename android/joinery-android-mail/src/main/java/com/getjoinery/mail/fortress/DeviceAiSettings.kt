package com.getjoinery.mail.fortress

import android.content.Context
import com.getjoinery.android.ApiClient
import com.getjoinery.android.JsonValue

/**
 * Where this phone's AI model lives (specs/fortress_mobile_apps.md § R13).
 * The base-URL origin is registered with the account under a step-up on a
 * computer (`device_ai_origin`, read from ai_device_recipes); the path, the
 * key and the model name are this phone's own, entered once and kept beside
 * the mail secret behind the biometric gate. The platform's model key never
 * reaches a phone. "Wi-Fi only" (default on) keeps message bodies off cellular.
 */
class DeviceAiSettings(private val context: Context, private val fortress: FortressMail) {
    data class Saved(val path: String, val key: String, val model: String, val wifiOnly: Boolean)

    private val prefs by lazy { context.getSharedPreferences("joinery.deviceai", Context.MODE_PRIVATE) }

    var wifiOnly: Boolean
        get() = prefs.getBoolean("wifi_only", true)
        set(value) { prefs.edit().putBoolean("wifi_only", value).apply() }

    /** What this phone saved, or null (none, or the key is locked). */
    fun load(): Saved? {
        val bytes = fortress.keys.readExtra(FortressOpener.SCOPE, "ai") ?: return null
        return try {
            val j = JsonValue.parse(String(bytes, Charsets.UTF_8))
            val model = j["model"]?.stringValue ?: ""
            if (model.isEmpty()) null
            else Saved(j["path"]?.stringValue ?: "", j["key"]?.stringValue ?: "", model, wifiOnly)
        } catch (e: Exception) {
            null
        } finally {
            bytes.fill(0)
        }
    }

    /** Save (the biometric window must be open: the key is a secret). */
    fun save(path: String, key: String, model: String) {
        val j = JsonValue.obj("path" to JsonValue.Str(path), "key" to JsonValue.Str(key), "model" to JsonValue.Str(model))
        fortress.keys.writeExtra(FortressOpener.SCOPE, "ai", j.encodedBytes())
    }

    fun clear() = fortress.keys.writeExtra(FortressOpener.SCOPE, "ai", null)

    /** The Test button: the made-up sample digest sent to the model, and which step failed. */
    suspend fun test(client: ApiClient, origin: String, path: String, key: String, model: String, mailbox: String): String {
        val url = callUrl(origin, path) ?: return "That address does not work with your registered model host."
        val prompt = try {
            client.submitAction("mailbox/device_ai_test_prompt", JsonValue.obj("mailbox" to JsonValue.Str(mailbox)))["data"]
        } catch (e: Exception) {
            return "The test message could not be fetched from the server."
        } ?: return "The test message could not be fetched from the server."
        val body = JsonValue.obj(
            "model" to JsonValue.Str(model),
            "messages" to JsonValue.Arr(listOf(
                JsonValue.obj("role" to JsonValue.Str("system"), "content" to (prompt["system"] ?: JsonValue.Str(""))),
                JsonValue.obj("role" to JsonValue.Str("user"), "content" to (prompt["user"] ?: JsonValue.Str(""))),
            )),
            "max_tokens" to (prompt["max_tokens"] ?: JsonValue.Num(1024.0)),
            "reasoning_effort" to (prompt["reasoning_effort"] ?: JsonValue.Str("none")),
            "response_format" to JsonValue.obj("type" to JsonValue.Str("json_object")),
            "stream" to JsonValue.Bool(false),
        )
        val headers = LinkedHashMap<String, String>()
        headers["Content-Type"] = "application/json"
        if (key.isNotEmpty()) headers["Authorization"] = "Bearer $key"
        val (status, text) = try {
            client.postExternal(url, origin, body.encodedBytes(), headers)
        } catch (e: Exception) {
            return "Could not reach your model. Check the address, and that this phone is on the same network " +
                "(or tailnet) as your model."
        }
        return classify(status, text, model)
    }

    companion object {
        /** The rest of the address as typed, tidied; null when it could leave the origin. */
        fun normalizePath(raw: String): String? {
            val path = raw.trim()
            if (path == "" || path == "/") return ""
            if (Regex("^[a-z][a-z0-9+.-]*:", RegexOption.IGNORE_CASE).containsMatchIn(path) || path.startsWith("//")) return null
            if (Regex("[?#\\s\\\\]|\\.\\.").containsMatchIn(path)) return null
            return (if (path.startsWith("/")) path else "/$path").trimEnd('/')
        }

        /** The chat URL for the registered origin and the saved path, or null
         *  when the two do not make an address on that origin. */
        fun callUrl(origin: String?, path: String): String? {
            val p = normalizePath(path) ?: return null
            if (origin.isNullOrEmpty()) return null
            return try {
                val u = java.net.URI(origin + p + "/chat/completions")
                val o = u.scheme + "://" + u.rawAuthority
                if (o.equals(origin, ignoreCase = false)) u.toString() else null
            } catch (e: Exception) {
                null
            }
        }

        fun classify(status: Int, text: String, model: String): String {
            val body = try { JsonValue.parse(text) } catch (e: Exception) { null }
            val err = body?.get("error")
            val msg = when {
                err == null || err.isNull -> ""
                err is JsonValue.Str -> err.value
                else -> err["message"]?.stringValue ?: err.encoded()
            }
            if (status == 401 || status == 403) return "Your model says the key is wrong" + (if (msg.isNotEmpty()) ": $msg" else ".")
            if (status >= 400 && Regex("context|too long|too many tokens|maximum.*tokens|exceed", RegexOption.IGNORE_CASE).containsMatchIn(msg)) {
                return "Your model's context is too small for a message: $msg"
            }
            if (status == 404 && msg.contains("model", ignoreCase = true)) return "Your model says it has no model named \"$model\": $msg"
            if (status !in 200..299) return "Your model answered with an error ($status)" + (if (msg.isNotEmpty()) ": $msg" else ".")
            val choice = body?.get("choices")?.arrayValue?.firstOrNull()
            val content = choice?.get("message")?.get("content")?.stringValue ?: ""
            if (content.isEmpty()) {
                return if (choice?.get("finish_reason")?.stringValue == "length")
                    "Your model spent its whole answer reasoning and never answered, even when asked not to reason. Choose a model that can answer directly."
                else "Your model answered, but with nothing in it."
            }
            val json = try { JsonValue.parse(content.replace(Regex("<think>[\\s\\S]*?</think>"), "").trim()) is JsonValue.Obj } catch (e: Exception) { false }
            val tokens = body?.get("usage")?.get("prompt_tokens")?.intValue?.let { " The test message was $it tokens." } ?: ""
            return "Reachable: ${body?.get("model")?.stringValue ?: model} answered.$tokens" +
                (if (json) "" else " Its answer was not the JSON asked for, so it may not suit the security scan.")
        }
    }
}
