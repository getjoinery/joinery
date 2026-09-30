package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto

/**
 * What a relay-sealed message becomes once this phone has parsed it
 * (specs/fortress_mobile_apps.md § R7) — the plaintext the browser's
 * sealAndStore() seals and posts to `fortress_parse_store`, before sealing.
 * Pinned by the `store` of the mime fixtures' expected.json files.
 */
object RelayParse {
    private val FIELD_MAX = mapOf("iem_subject" to 4000, "iem_sender" to 500)

    // JS's \s (Unicode whitespace), not Java's ASCII one.
    private val JS_SPACE = Regex("[\\t\\n\\u000B\\f\\r \\u00A0\\u1680\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000\\uFEFF]+")

    data class Store(
        /** Column → plaintext, in the browser's order. */
        val fields: LinkedHashMap<String, String>,
        /** [{mime_part, size, inline}] for each part, in order. */
        val parts: List<MimeParser.Part>,
        val spamHeaders: JsonValue.Obj,
    )

    /** The list preview, as the server derives it for a Fortress row. */
    fun snippetOf(plain: String): String = plain.take(4000).replace(JS_SPACE, " ").trim(*JS_TRIM).take(240)

    private val JS_TRIM = charArrayOf(' ', '\t', '\n', '\u000B', '\u000C', '\r', ' ', ' ', ' ', ' ', ' ', ' ',
        ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', '　', '﻿')

    /** An HTML body's readable text, for search and the preview (the browser
     *  uses DOMParser; this keeps its gist: no script, style or head, no tags). */
    fun readableText(html: String): String {
        if (html.isEmpty()) return ""
        var t = html.replace(Regex("(?is)<(script|style|head)\\b[^>]*>.*?</\\1\\s*>"), " ")
        t = t.replace(Regex("(?s)<!--.*?-->"), " ")
        t = t.replace(Regex("<[^>]*>"), " ")
        return com.getjoinery.mail.ai.EmailDigest.decodeEntities(t)
    }

    /**
     * A search text as the server stores one: whitespace folded, cut at 32768
     * characters, and `gz:` + base64(gzip) when that is a third or more shorter.
     */
    fun packSearchText(text: String): String {
        var folded = text.replace(JS_SPACE, " ").trim(*JS_TRIM)
        if (folded.codePointCount(0, folded.length) > 32768) folded = folded.substring(0, folded.offsetByCodePoints(0, 32768))
        if (folded.isEmpty()) return folded
        val plain = folded.toByteArray(Charsets.UTF_8)
        val gz = "gz:" + VaultCrypto.b64encode(VaultCrypto.gzip(plain))
        return if (gz.length * 3 <= plain.size * 2) gz else folded
    }

    fun store(p: MimeParser.Parsed): Store {
        val readable = readableText(p.textHtml)
        val names = p.attachments.map { it.filename }.filter { it.isNotEmpty() }
        val manifest = if (p.attachments.isEmpty()) "" else JsonValue.Arr(p.attachments.map { a ->
            JsonValue.obj(
                "mime_part" to JsonValue.Str(a.mimePart),
                "filename" to JsonValue.Str(a.filename),
                "content_type" to JsonValue.Str(a.contentType),
                "content_id" to JsonValue.Str(a.contentId),
                "inline" to JsonValue.Bool(a.inline),
                "size" to JsonValue.Num(a.bytes.size.toDouble()),
            )
        }).encoded()
        val values = linkedMapOf(
            "iem_sender" to p.from,
            "iem_subject" to p.subject,
            "iem_body_plain" to p.textPlain,
            "iem_body_html" to p.textHtml,
            "iem_raw_headers" to p.headers,
            "iem_to" to p.to,
            "iem_cc" to p.cc,
            "iem_snippet" to snippetOf(p.textPlain).ifEmpty { readable.replace(JS_SPACE, " ").trim(*JS_TRIM).take(240) },
            "iem_search_text" to packSearchText(listOf(p.from, p.subject, names.joinToString(" "), p.textPlain, readable).joinToString(" ")),
            "iem_attachment_manifest" to manifest,
        )
        val fields = LinkedHashMap<String, String>()
        for ((col, v) in values) fields[col] = FIELD_MAX[col]?.let { v.take(it) } ?: v
        val spam = JsonValue.obj(
            "x_spam" to JsonValue.Str(MimeParser.headerValue(p, "X-Spam")),
            "x_spam_flag" to JsonValue.Str(MimeParser.headerValue(p, "X-Spam-Flag")),
            "x_spam_score" to JsonValue.Str(MimeParser.headerValue(p, "X-Spam-Score")),
            "x_spam_status" to JsonValue.Str(MimeParser.headerValue(p, "X-Spam-Status")),
        )
        return Store(fields, p.attachments, spam)
    }
}
