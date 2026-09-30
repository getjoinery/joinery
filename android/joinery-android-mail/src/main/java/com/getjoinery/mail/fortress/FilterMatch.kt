package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue

/**
 * A mailbox's mail rules, evaluated on the phone that opens a relay-sealed
 * message (specs/fortress_mobile_apps.md § R14) — a port of
 * `plugins/mailbox/assets/mailbox_filter_match.js`, itself a port of
 * `InboundEmailFilter::matches()` with PHP's semantics (trim's character set,
 * preg_split('/\s+/u'), intval()). The device reports which rules matched;
 * the server applies their actions. Pinned by
 * `plugins/mailbox/tests/fixtures/filter_match_cases.json`, written from the
 * PHP matcher itself.
 *
 *   rule: {id, match: {from, to, subject, has_words, excludes,
 *     size_op ('' | 'gt' | 'lt'), size_bytes, has_attachment}}
 *   message: {sender, recipient, subject, body_plain, body_html, size_bytes,
 *     has_attachment}
 */
object FilterMatch {
    private const val PHP_TRIM = " \t\n\r\u0000\u000B"
    private val S_UCP = Regex("[\\t\\n\\u000B\\f\\r \\u0085\\u00A0\\u1680\\u180E\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000]+")
    private val NUMERIC_PREFIX = Regex("^[ \\t\\n\\r\\u000B\\f]*[+-]?(\\d+(\\.\\d*)?|\\.\\d+)([eE][+-]?\\d+)?")

    private fun str(v: JsonValue?): String = when (v) {
        null, JsonValue.Null -> ""
        is JsonValue.Bool -> if (v.value) "1" else ""
        is JsonValue.Str -> v.value
        is JsonValue.Num -> v.stringValue ?: ""
        else -> v.encoded()
    }

    private fun trim(s: String): String {
        var a = 0
        var b = s.length
        while (a < b && PHP_TRIM.indexOf(s[a]) != -1) a++
        while (b > a && PHP_TRIM.indexOf(s[b - 1]) != -1) b--
        return s.substring(a, b)
    }

    private fun lower(s: String) = s.lowercase(java.util.Locale.ROOT)

    private fun intval(v: JsonValue?): Long = when (v) {
        is JsonValue.Num -> if (v.value.isFinite()) v.value.toLong() else 0
        is JsonValue.Bool -> if (v.value) 1 else 0
        is JsonValue.Str -> NUMERIC_PREFIX.find(v.value)?.value?.trim()?.toDoubleOrNull()?.toLong() ?: 0
        else -> 0
    }

    private fun anyTermIn(list: String, hay: String): Boolean =
        list.split(',').any { term -> lower(trim(term)).let { it.isNotEmpty() && hay.contains(it) } }

    private fun tokens(phrase: String): List<String> =
        trim(phrase).split(S_UCP).map { lower(trim(it)) }.filter { it.isNotEmpty() }

    fun matches(rule: JsonValue, m: JsonValue): Boolean {
        val c = rule["match"] ?: JsonValue.Obj(emptyList())
        val senderRaw = str(m["sender"])
        val subjectRaw = str(m["subject"])
        val sender = lower(senderRaw)
        val recipient = lower(str(m["recipient"]))
        val subject = lower(subjectRaw)
        val hay = lower(senderRaw + " " + subjectRaw + " " + str(m["body_plain"]) + " " + str(m["body_html"]))

        val from = trim(str(c["from"]))
        if (from.isNotEmpty() && !anyTermIn(from, sender)) return false

        val to = trim(str(c["to"]))
        if (to.isNotEmpty() && !anyTermIn(to, recipient)) return false

        val subj = trim(str(c["subject"]))
        if (subj.isNotEmpty() && !subject.contains(lower(subj))) return false

        val words = trim(str(c["has_words"]))
        if (words.isNotEmpty() && tokens(words).any { !hay.contains(it) }) return false

        val excl = trim(str(c["excludes"]))
        if (excl.isNotEmpty() && tokens(excl).any { hay.contains(it) }) return false

        val op = str(c["size_op"])
        val bytes = intval(c["size_bytes"])
        if ((op == "gt" || op == "lt") && bytes > 0) {
            val size = intval(m["size_bytes"])
            if (op == "gt" && size <= bytes) return false
            if (op == "lt" && size >= bytes) return false
        }

        if ((c["has_attachment"] as? JsonValue.Bool)?.value == true && (m["has_attachment"] as? JsonValue.Bool)?.value != true) return false

        return true
    }

    /** The ids of the rules that match, in the order given. */
    fun matchingIds(rules: List<JsonValue>, m: JsonValue): List<Long> =
        rules.filter { matches(it, m) }.map { intval(it["id"]) }

    /** The matcher's message from a parse and the row's clear columns. */
    fun message(sender: String, recipient: String, subject: String, bodyPlain: String, bodyHtml: String, sizeBytes: Long, hasAttachment: Boolean): JsonValue =
        JsonValue.obj(
            "sender" to JsonValue.Str(sender),
            "recipient" to JsonValue.Str(recipient),
            "subject" to JsonValue.Str(subject),
            "body_plain" to JsonValue.Str(bodyPlain),
            "body_html" to JsonValue.Str(bodyHtml),
            "size_bytes" to JsonValue.Num(sizeBytes.toDouble()),
            "has_attachment" to JsonValue.Bool(hasAttachment),
        )
}
