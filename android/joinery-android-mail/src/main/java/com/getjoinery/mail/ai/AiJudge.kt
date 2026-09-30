package com.getjoinery.mail.ai

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.fortress.FortressOpener
import com.getjoinery.mail.fortress.SealedRow

/**
 * One AI judgement on the person's own model, from the phone
 * (specs/fortress_mobile_apps.md § R13) — a port of MailboxFortress
 * .judgeEntry(). Opens the entry, builds the digest exactly as a server run
 * would, asks the model (OpenAI-compatible chat completions, Bearer key, JSON
 * response format), validates the verdict with one retry, seals it under the
 * row's key and posts it. The server stores ciphertext and the clear score.
 * Pinned by the `judgements` of device_ai_vectors.json.
 */
class AiJudge(
    /** Open a row's sealed fields (the phone's opener). */
    private val open: (SealedRow) -> Map<String, String>,
    /** The row's DEK (a copy the judge zeroes). */
    private val keyOf: (SealedRow) -> ByteArray,
    /** POST to the model: (url, headers, body) → (status, text); throws when unreachable. */
    private val transport: suspend (String, Map<String, String>, ByteArray) -> Pair<Int, String>,
    /** A Joinery API action: (action, body) → data. */
    private val post: suspend (String, JsonValue) -> JsonValue?,
    /** The lock epoch: nothing is sealed or posted once it moves. */
    private val epoch: () -> Int,
) {
    data class Endpoint(val url: String, val key: String, val model: String)

    sealed class Outcome {
        data class Done(val field: String, val plaintext: String, val model: String, val recorded: Boolean) : Outcome()
        data class Error(val error: String) : Outcome()
        /** The model could not be asked (unreachable, or an HTTP refusal): stop the drain. */
        data class Stop(val reason: String, val http: Int? = null) : Outcome()
        /** The key locked part way: nothing was posted. */
        object Dropped : Outcome()
        /** The row does not open with the key this phone holds (sealed to
         *  another key): nothing is recorded, so a device that holds it can judge it. */
        object Unopenable : Outcome()
    }

    suspend fun judge(entry: JsonValue, recipe: JsonValue, endpoint: Endpoint): Outcome {
        val at = epoch()
        val row = SealedRow.from(entry["sealed"] ?: return Outcome.Error("no sealed row"))
            ?.let { if (it.key == 0) it.copy(key = entry["id"]?.intValue ?: 0) else it }
            ?: return Outcome.Error("no sealed row")
        val o = try { open(row) } catch (e: com.getjoinery.android.vault.SecretStoreLockedException) {
            return Outcome.Dropped
        } catch (e: Exception) {
            return Outcome.Unopenable
        }
        fun s(k: String) = entry[k]?.takeUnless { it.isNull }?.stringValue ?: ""
        fun r(k: String) = recipe[k]?.takeUnless { it.isNull }?.stringValue ?: ""
        var digest = EmailDigest.build(
            EmailDigest.Columns(
                raw = o["iem_raw_headers"]?.takeIf { it.isNotEmpty() },
                sender = o["iem_sender"] ?: "",
                recipient = (o["iem_recipient"] ?: "").ifEmpty { s("recipient") },
                receivedTime = s("received_time"),
                subject = o["iem_subject"] ?: "",
                bodyPlain = o["iem_body_plain"] ?: "",
                bodyHtml = o["iem_body_html"] ?: "",
                spfResult = s("spf_result"),
                dkimResult = s("dkim_result"),
                dmarcResult = s("dmarc_result"),
                authservId = r("authserv_id"),
            ),
        )
        if (recipe["attachments"]?.boolValue == true) {
            val manifest = try { JsonValue.parse((o["iem_attachment_manifest"] ?: "").ifEmpty { "[]" }).arrayValue } catch (e: Exception) { null } ?: emptyList()
            val att = EmailDigest.attachments(manifest)
            if (att != "") digest += "\n\n" + att
        }
        val messages = arrayListOf<JsonValue>(
            JsonValue.obj("role" to JsonValue.Str("system"), "content" to JsonValue.Str(r("system"))),
            JsonValue.obj("role" to JsonValue.Str("user"), "content" to JsonValue.Str(EmailDigest.wrapBlock(digest, r("nonce")))),
        )
        val headers = LinkedHashMap<String, String>()
        headers["Content-Type"] = "application/json"
        if (endpoint.key.isNotEmpty()) headers["Authorization"] = "Bearer ${endpoint.key}"

        var verdict: JsonValue.Obj? = null
        var error = ""
        var served = endpoint.model
        var effort = r("reasoning_effort").ifEmpty { "none" }
        val jobId = r("job_id")
        for (attempt in 1..2) {
            if (epoch() != at) return Outcome.Dropped
            val body = JsonValue.obj(
                "model" to JsonValue.Str(endpoint.model),
                "messages" to JsonValue.Arr(messages.toList()),
                "max_tokens" to (recipe["max_tokens"] ?: JsonValue.Null),
                "reasoning_effort" to JsonValue.Str(effort),
                "response_format" to JsonValue.obj("type" to JsonValue.Str("json_object")),
                "stream" to JsonValue.Bool(false),
            )
            val (status, text) = try {
                transport(endpoint.url, headers, body.encodedBytes())
            } catch (e: Exception) {
                return Outcome.Stop("unreachable")
            }
            val parsed = try { JsonValue.parse(text) } catch (e: Exception) { null }
            if (status !in 200..299) {
                val err = parsed?.get("error")
                val reason = when {
                    err == null || err.isNull -> text.take(200)
                    err is JsonValue.Str -> err.value
                    else -> err["message"]?.stringValue ?: err.encoded()
                }
                return Outcome.Stop(reason, status)
            }
            parsed?.get("model")?.stringValue?.takeIf { it.isNotEmpty() }?.let { served = it }
            val choice = parsed?.get("choices")?.arrayValue?.firstOrNull()
            val content = choice?.get("message")?.get("content")?.takeUnless { it.isNull }?.stringValue ?: ""
            if (content.isEmpty() && choice?.get("finish_reason")?.stringValue == "length" && effort != "none") {
                effort = "none"
                error = "The model spent its whole answer reasoning and never answered."
                continue
            }
            when (val v = VerdictCheck.parse(content, recipe["verdict_descriptor"], jobId)) {
                is VerdictCheck.Result.Verdict -> { verdict = v.verdict }
                is VerdictCheck.Result.Error -> {
                    error = v.message
                    if (attempt < 2) {
                        messages.add(JsonValue.obj("role" to JsonValue.Str("assistant"), "content" to JsonValue.Str(content)))
                        messages.add(JsonValue.obj("role" to JsonValue.Str("user"), "content" to JsonValue.Str(VerdictCheck.retryMessage(error))))
                    }
                }
            }
            if (verdict != null) break
        }
        if (epoch() != at) return Outcome.Dropped
        val recipeId = recipe["recipe_id"] ?: JsonValue.Null
        if (verdict == null) {
            post("mailbox/ai_device_record", JsonValue.obj("recipe_id" to recipeId, "item_key" to JsonValue.Str(entry["id"]?.stringValue ?: "")))
            return Outcome.Error(error)
        }

        // The shapes the server's jobs write: the triage's bare summary; the
        // scan's JSON with the score kept out, in the clear.
        val field: String
        val plaintext: String
        var score: JsonValue? = null
        if (jobId == "email_security_scan") {
            field = "iem_ai_scan"
            plaintext = JsonValue.obj(
                "verdict" to (verdict["verdict"] ?: JsonValue.Str("")),
                "red_flags" to (verdict["red_flags"] ?: JsonValue.Arr(emptyList())),
                "summary" to (verdict["summary"] ?: JsonValue.Str("")),
                "model" to JsonValue.Str(served),
                "recipe_id" to recipeId,
            ).encoded()
            score = verdict["score"]
        } else {
            field = "iem_ai_summary"
            plaintext = verdict["summary"]?.stringValue ?: ""
        }
        val key = keyOf(row)
        val sealed = try {
            if (epoch() != at) return Outcome.Dropped
            FortressOpener.EDGE_FIELD + VaultCrypto.encryptString(plaintext, key, row.ad(field))
        } finally {
            key.fill(0)
        }
        if (epoch() != at) return Outcome.Dropped
        val payload = ArrayList<Pair<String, JsonValue>>()
        payload.add("id" to (entry["id"] ?: JsonValue.Null))
        payload.add("recipe_id" to recipeId)
        payload.add("fields" to JsonValue.obj(field to JsonValue.Str(sealed)))
        if (score != null) payload.add("danger_score" to score)
        val r = post("mailbox/device_ai_verdict", JsonValue.Obj(payload))
        return Outcome.Done(field, plaintext, served, r?.get("recorded")?.boolValue == true)
    }
}
