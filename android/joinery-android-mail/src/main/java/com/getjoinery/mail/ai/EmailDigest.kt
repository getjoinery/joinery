package com.getjoinery.mail.ai

import com.getjoinery.android.JsonValue
import com.getjoinery.mail.fortress.MimeParser
import java.nio.ByteBuffer
import java.nio.charset.Charset
import java.nio.charset.CodingErrorAction

/**
 * The AI email digest, built on the phone — a port of
 * `assets/js/email-digest.js`, itself a byte-for-byte port of
 * EmailSecurityDigest::buildFromColumns(), EmailAttachmentDigest and
 * UntrustedEnvelope (specs/fortress_mobile_apps.md § R13). The model must see
 * exactly what a server run would show it, so PHP's semantics are kept where
 * Kotlin's nearest equivalent differs: strip_tags()'s state machine,
 * html_entity_decode() with the HTML5 table, PHP's trim() set, PCRE's \s
 * with and without /u, lengths in code points, parse_url()'s host. Pinned by
 * `plugins/mailbox/tests/fixtures/device_ai_vectors.json`.
 */
object EmailDigest {
    private const val SUBJECT_CAP_CHARS = 1024
    private const val BODY_CAP_CHARS = 4096
    private const val URL_CAP = 20
    private const val DOMAIN_CAP = 15
    private const val ANCHOR_TEXT_CAP_CHARS = 120
    private const val WHITESPACE_ANNOTATE_THRESHOLD = 200
    private const val ATT_MAX_PARTS = 10
    private const val ATT_FILENAME_CAP_CHARS = 120

    private val WHITESPACE_RUN = Regex("[ \\t\\u00A0\\u200B\\u200C\\u200D\\u2060\\uFEFF\\u3000]{4,}")
    // PCRE's \s without /u: ASCII whitespace only.
    private const val S_ASCII = "[ \\t\\n\\u000B\\f\\r]"
    private const val S_ASCII_BODY = " \\t\\n\\u000B\\f\\r"
    // PCRE's \s with /u, as PHP matches it.
    private const val S_UCP_BODY = "\\t\\n\\u000B\\f\\r \\u0085\\u00A0\\u1680\\u180E\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000"
    private const val S_UCP = "[$S_UCP_BODY]"
    // JS's ASCII \b, not Java's Unicode one.
    private const val W = "A-Za-z0-9_"

    // MARK: PHP's primitives

    private fun mbLen(s: String) = s.codePointCount(0, s.length)
    private fun mbSub(s: String, n: Int): String {
        if (mbLen(s) <= n) return s
        return s.substring(0, s.offsetByCodePoints(0, n))
    }

    private const val PHP_TRIM = " \t\n\r\u0000\u000B"
    fun trim(s: String, chars: String = PHP_TRIM): String {
        var a = 0
        var b = s.length
        while (a < b && chars.indexOf(s[a]) != -1) a++
        while (b > a && chars.indexOf(s[b - 1]) != -1) b--
        return s.substring(a, b)
    }

    private fun rtrim(s: String, chars: String): String {
        var b = s.length
        while (b > 0 && chars.indexOf(s[b - 1]) != -1) b--
        return s.substring(0, b)
    }

    private fun asciiLower(s: String): String {
        val sb = StringBuilder(s.length)
        for (c in s) sb.append(if (c in 'A'..'Z') (c + 32) else c)
        return sb.toString()
    }

    private fun isCSpace(c: Char) = c == ' ' || c == '\t' || c == '\n' || c == '\u000B' || c == '\u000C' || c == '\r'

    /** PHP's strip_tags() with no allowed tags (php_strip_tags_ex's states). */
    fun stripTags(str: String): String {
        val out = StringBuilder()
        var state = 0
        var depth = 0
        var inQ = 0.toChar()
        var lc = 0.toChar()
        var br = 0
        var isXml = false
        val n = str.length
        var i = 0
        while (i < n) {
            val c = str[i]
            val prev = if (i > 0) str[i - 1] else 0.toChar()
            val hasQ = inQ != 0.toChar()
            try {
                if (c == '\u0000') continue
                when (state) {
                    0 -> {
                        if (c == '<') {
                            if (hasQ) continue
                            if (i + 1 < n && isCSpace(str[i + 1])) { out.append(c); continue }
                            lc = '<'
                            state = 1
                            continue
                        }
                        if (c == '>') {
                            if (depth > 0) { depth--; continue }
                            if (hasQ) continue
                            out.append(c)
                            continue
                        }
                        out.append(c)
                    }
                    1 -> {
                        if (c == '<') {
                            if (hasQ) continue
                            if (i + 1 < n && isCSpace(str[i + 1])) continue
                            depth++
                            continue
                        }
                        if (c == '>') {
                            if (depth > 0) { depth--; continue }
                            if (hasQ) continue
                            lc = '>'
                            if (isXml && prev == '-') continue
                            inQ = 0.toChar(); state = 0; isXml = false
                            continue
                        }
                        if (c == '"' || c == '\'') {
                            if (i != 0 && (!hasQ || c == inQ)) inQ = if (hasQ) 0.toChar() else c
                            continue
                        }
                        if (c == '!' && prev == '<') { state = 3; lc = c; continue }
                        if (c == '?' && prev == '<') { br = 0; state = 2; continue }
                    }
                    2 -> {
                        if (c == '(') { if (lc != '"' && lc != '\'') { lc = '('; br++ }; continue }
                        if (c == ')') { if (lc != '"' && lc != '\'') { lc = ')'; br-- }; continue }
                        if (c == '>') {
                            if (depth > 0) { depth--; continue }
                            if (hasQ) continue
                            if (br == 0 && lc != '"' && prev == '?') { inQ = 0.toChar(); state = 0 }
                            continue
                        }
                        if (c == '"' || c == '\'') {
                            if (prev != '\\') {
                                if (lc == c) lc = 0.toChar()
                                else if (lc != '\\') lc = c
                            }
                            if (i != 0 && (!hasQ || c == inQ)) inQ = if (hasQ) 0.toChar() else c
                            continue
                        }
                        if ((c == 'l' || c == 'L') && i > 4 && (prev == 'm' || prev == 'M') &&
                            (str[i - 2] == 'x' || str[i - 2] == 'X') && str[i - 3] == '?' && str[i - 4] == '<'
                        ) {
                            state = 1; isXml = true
                        }
                    }
                    3 -> {
                        if (c == '>') {
                            if (depth > 0) { depth--; continue }
                            if (hasQ) continue
                            inQ = 0.toChar(); state = 0
                            continue
                        }
                        if (c == '"' || c == '\'') {
                            if (i != 0 && prev != '\\' && (!hasQ || c == inQ)) inQ = if (hasQ) 0.toChar() else c
                            continue
                        }
                        if (c == '-' && i >= 2 && prev == '-' && str[i - 2] == '!') { state = 4; continue }
                        if ((c == 'E' || c == 'e') && i > 6 && asciiLower(str.substring(i - 6, i)) == "doctyp") {
                            state = 1
                            continue
                        }
                    }
                    else -> {
                        // inside <!-- ... -->
                        if (c == '>' && !hasQ && i >= 2 && prev == '-' && str[i - 2] == '-') {
                            inQ = 0.toChar(); state = 0
                        }
                    }
                }
            } finally {
                i++
            }
        }
        return out.toString()
    }

    private val ENTITIES: Map<String, String> by lazy {
        val stream = EmailDigest::class.java.classLoader!!.getResourceAsStream("com/getjoinery/mail/html_entities.json")
            ?: return@lazy emptyMap()
        val json = JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
        json.objectValue!!.associate { it.first to (it.second.stringValue ?: "") }
    }

    private fun cpAllowedHtml5(cp: Long): Boolean =
        (cp in 0x20..0x7E) || (cp in 0x09..0x0D && cp != 0x0BL) ||
            (cp in 0xA0..0xD7FF) ||
            (cp in 0xE000..0x10FFFF && (cp and 0xFFFF) < 0xFFFE && (cp < 0xFDD0 || cp > 0xFDEF))

    private fun isHex(c: Char) = c in '0'..'9' || c in 'a'..'f' || c in 'A'..'F'
    private fun isAlnum(c: Char) = c in '0'..'9' || c in 'a'..'z' || c in 'A'..'Z'

    /** PHP's html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'). */
    fun decodeEntities(s: String): String {
        if (!s.contains('&')) return s
        val out = StringBuilder()
        var i = 0
        val n = s.length
        while (i < n) {
            val c = s[i]
            if (c != '&' || i + 3 >= n) { out.append(c); i++; continue }
            if (s[i + 1] == '#') {
                var j = i + 2
                val hex = s[j] == 'x' || s[j] == 'X'
                if (hex) j++
                val start = j
                while (j < n && (if (hex) isHex(s[j]) else s[j] in '0'..'9')) j++
                val next = j
                if (j == start || j >= n || s[j] != ';') { out.append(s, i, next); i = next; continue }
                val digits = s.substring(start, j).replace(Regex("^0+(?=.)"), "")
                val cp = if (digits.length > 8) Long.MAX_VALUE else digits.toLong(if (hex) 16 else 10)
                if (cp > 0x10FFFF || !cpAllowedHtml5(cp) || cp == 0x0DL) { out.append(s, i, next); i = next; continue }
                out.appendCodePoint(cp.toInt())
                i = j + 1
                continue
            }
            var k = i + 1
            while (k < n && isAlnum(s[k])) k++
            val next = k
            if (k == i + 1 || k >= n || s[k] != ';') { out.append(s, i, next); i = next; continue }
            val name = s.substring(i + 1, k)
            val value = ENTITIES[name]
            if (value == null) { out.append(s, i, next); i = next; continue }
            out.append(value)
            i = k + 1
        }
        return out.toString()
    }

    private val SCHEME = Regex("^([a-zA-Z][a-zA-Z0-9+.\\-]*):")

    /** parse_url($url, PHP_URL_HOST), lower-cased as strtolower() does; "" for none. */
    fun urlHost(url: String): String {
        val rest: String
        val m = SCHEME.find(url)
        if (m != null) {
            val r = url.substring(m.value.length)
            if (!r.startsWith("//")) return ""
            rest = r.substring(2)
        } else if (url.startsWith("//")) {
            rest = url.substring(2)
        } else {
            return ""
        }
        val end = rest.indexOfFirst { it == '/' || it == '?' || it == '#' }
        var auth = if (end == -1) rest else rest.substring(0, end)
        val at = auth.lastIndexOf('@')
        if (at != -1) auth = auth.substring(at + 1)
        var host = auth
        if (!(auth.startsWith("[") && auth.endsWith("]") && auth.isNotEmpty())) {
            val colon = auth.lastIndexOf(':')
            if (colon != -1) {
                val port = auth.substring(colon + 1)
                if (port != "") {
                    if (!port.matches(Regex("^[0-9]{1,5}$")) || port.toInt() > 65535) return ""
                }
                host = auth.substring(0, colon)
            }
        }
        if (host == "") return ""
        return asciiLower(host.replace(Regex("[\\x00-\\x1F\\x7F]"), "_"))
    }

    // MARK: The digest's own steps

    private fun collapse(text: String): Pair<String, Int> {
        val before = mbLen(text)
        val collapsed = text.replace(WHITESPACE_RUN, " ")
        return collapsed to maxOf(0, before - mbLen(collapsed))
    }

    private fun capSize(text: String, cap: Int): String {
        val total = mbLen(text)
        if (total <= cap) return text
        return mbSub(text, cap) + "\n[truncated, $total characters total]"
    }

    private fun annotation(removed: Int): String =
        if (removed <= WHITESPACE_ANNOTATE_THRESHOLD) "" else "; preprocessor removed $removed invisible/whitespace characters"

    private fun selectBody(plain: String, html: String): Pair<String, String> {
        if (trim(plain) != "") return plain to "text/plain"
        if (trim(html) != "") return decodeEntities(stripTags(html)) to "text/html tag-stripped"
        return "(no body text)" to "text/plain"
    }

    private val UCP_RUN = Regex("$S_UCP+")

    private fun anchorText(inner: String): String {
        var text = decodeEntities(stripTags(inner))
        text = trim(text.replace(UCP_RUN, " "))
        if (mbLen(text) > ANCHOR_TEXT_CAP_CHARS) text = mbSub(text, ANCHOR_TEXT_CAP_CHARS) + "…"
        return text
    }

    private fun addUrl(found: LinkedHashMap<String, String>, raw: String, text: String) {
        val url = trim(raw)
        if (url == "") return
        if (!found.containsKey(url)) found[url] = text
        else if (found[url] == "" && text != "") found[url] = text
    }

    private val ANCHOR = Regex("<a(?![$W])[^>]*href$S_ASCII*=$S_ASCII*[\"']([^\"']+)[\"'][^>]*>([\\s\\S]*?)</a>", RegexOption.IGNORE_CASE)
    private val HREF = Regex("href$S_ASCII*=$S_ASCII*[\"']([^\"']+)[\"']", RegexOption.IGNORE_CASE)
    private val BARE_URL = Regex("(?<![$W])https?://(?:(?!$S_ASCII)[^\"'<>])+", RegexOption.IGNORE_CASE)

    private fun extractUrls(html: String, plain: String): List<Pair<String, String>> {
        val found = LinkedHashMap<String, String>()
        if (trim(html) != "") {
            for (m in ANCHOR.findAll(html)) addUrl(found, decodeEntities(m.groupValues[1]), anchorText(m.groupValues[2]))
            for (m in HREF.findAll(html)) addUrl(found, decodeEntities(m.groupValues[1]), "")
        }
        val visible = trim("$plain " + stripTags(html))
        for (m in BARE_URL.findAll(visible)) addUrl(found, rtrim(m.value, ".,;:!?)]}'\""), "")
        return found.map { it.key to it.value }
    }

    private fun domainSummary(urls: List<Pair<String, String>>): String {
        val counts = LinkedHashMap<String, Int>()
        for ((url, _) in urls) {
            val host = urlHost(url)
            if (host == "") continue
            counts[host] = (counts[host] ?: 0) + 1
        }
        if (counts.isEmpty()) return ""
        val list = counts.entries.map { it.key to it.value }.sortedByDescending { it.second } // stable, as PHP 8's arsort
        val parts = list.take(DOMAIN_CAP).map { "${it.first} (${it.second})" }.toMutableList()
        if (list.size > DOMAIN_CAP) parts.add("+${list.size - DOMAIN_CAP} more domains")
        return parts.joinToString(", ")
    }

    private val WORD = Regex("=\\?([^?\\s]+)\\?([BbQq])\\?([^?\\s]*)\\?=")
    private val ONLY_SPACE = Regex("^[ \\t\\r\\n]*$")

    /** What an encoded word decodes to, as PHP's iconv_mime_decode reads it. */
    private sealed class Word {
        class Text(val value: String) : Word()
        /** A bad Q escape or an unknown charset: the word stays as written. */
        object Undecodable : Word()
        /** Bytes the charset cannot take: kept, and at the value's end it loses its final '='. */
        object Unconvertible : Word()
    }

    /** RFC 2047 encoded words, as iconv_mime_decode(CONTINUE_ON_ERROR) reads them
     *  (email-digest.js 1.3). */
    fun decodeHeaderValue(value: String?): String {
        val v = value ?: ""
        if (v == "") return ""
        if (!v.contains("=?")) return v
        val out = StringBuilder()
        var last = 0
        var prevWasWord = false
        for (m in WORD.findAll(v)) {
            val between = v.substring(last, m.range.first)
            if (!(prevWasWord && ONLY_SPACE.matches(between))) out.append(between)
            val w = decodeWord(m.groupValues[1], m.groupValues[2], m.groupValues[3])
            when (w) {
                is Word.Text -> out.append(w.value)
                Word.Unconvertible -> out.append(if (m.range.last + 1 == v.length) m.value.dropLast(1) else m.value)
                Word.Undecodable -> out.append(m.value)
            }
            prevWasWord = w is Word.Text
            last = m.range.last + 1
        }
        return out.append(v.substring(last)).toString()
    }

    private const val B64_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"

    /** php_base64_decode(), non-strict: '=' and anything outside the
     *  alphabet skipped, padding optional, packing straight through. */
    fun phpBase64(text: String): ByteArray {
        val out = java.io.ByteArrayOutputStream()
        var i = 0
        var cur = 0
        for (ch in text) {
            if (ch == '=') continue
            val v = B64_ALPHABET.indexOf(ch)
            if (v < 0) continue
            when (i % 4) {
                0 -> cur = (v shl 2) and 0xFF
                1 -> { out.write(cur or (v shr 4)); cur = ((v and 0x0F) shl 4) and 0xFF }
                2 -> { out.write(cur or (v shr 2)); cur = ((v and 0x03) shl 6) and 0xFF }
                3 -> out.write(cur or v)
            }
            i++
        }
        return out.toByteArray()
    }

    private val CP1252_LABELS = setOf("windows-1252", "cp1252", "x-cp1252")
    private val CP1252_UNDEFINED = setOf(0x81, 0x8D, 0x8F, 0x90, 0x9D)

    private fun decodeWord(charset: String, enc: String, text: String): Word {
        val bytes = if (enc == "B" || enc == "b") {
            phpBase64(text)
        } else {
            val q = text.replace('_', ' ')
            val arr = java.io.ByteArrayOutputStream()
            var j = 0
            while (j < q.length) {
                val ch = q[j]
                if (ch == '=') {
                    // A lone '=' ending the text is dropped (email-digest.js 1.3.1).
                    if (j == q.length - 1) break
                    if (j + 2 >= q.length || !isHex(q[j + 1]) || !isHex(q[j + 2])) return Word.Undecodable
                    arr.write(q.substring(j + 1, j + 3).toInt(16))
                    j += 3
                    continue
                }
                arr.write(ch.code and 0xFF)
                j++
            }
            arr.toByteArray()
        }
        // iconv reads ISO-8859-1 byte for byte, 0x80-0x9F included; it refuses a
        // US-ASCII byte past 0x7F and windows-1252's five undefined bytes.
        val label = charset.split('*')[0].trim().lowercase()
        if (label in ICONV_LATIN1) return Word.Text(String(CharArray(bytes.size) { (bytes[it].toInt() and 0xFF).toChar() }))
        if (label in ICONV_ASCII) {
            if (bytes.any { (it.toInt() and 0xFF) > 0x7F }) return Word.Unconvertible
            return Word.Text(String(bytes, Charsets.US_ASCII))
        }
        if (label in CP1252_LABELS) {
            if (bytes.any { (it.toInt() and 0xFF) in CP1252_UNDEFINED }) return Word.Unconvertible
            return Word.Text(MimeParser.decode1252(bytes))
        }
        // glibc's iconv (PHP's) reads BOM-less UTF-16 as little-endian.
        val utf16 = label == "utf-16" || label == "utf16"
        val name = if (utf16 && !(bytes.size >= 2 && (bytes[0].toInt() and 0xFF) == 0xFE && (bytes[1].toInt() and 0xFF) == 0xFF)) "UTF-16LE"
        else ALIASES[label] ?: label
        val cs = try { Charset.forName(name) } catch (e: Exception) { return Word.Undecodable }
        return try {
            val decoded = cs.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)
                .decode(ByteBuffer.wrap(bytes)).toString()
            Word.Text(if (cs.name().startsWith("UTF")) decoded.removePrefix("\uFEFF") else decoded)
        } catch (e: Exception) {
            Word.Unconvertible
        }
    }

    /** TextDecoder's names for labels Java spells differently. */
    private val ALIASES = mapOf("gb2312" to "GBK", "x-gbk" to "GBK", "ks_c_5601-1987" to "EUC-KR")

    private val ICONV_LATIN1 = setOf("iso-8859-1", "latin1", "l1", "iso8859-1", "iso_8859-1", "cp819", "ibm819", "iso-ir-100", "csisolatin1")
    private val ICONV_ASCII = setOf("us-ascii", "ascii", "ansi_x3.4-1968", "iso646-us", "us")

    /** The first occurrence of a header's unfolded value, or null. */
    fun extractHeader(raw: String, name: String): String? {
        val norm = raw.split("\r\n").joinToString("\n")
        val split = norm.indexOf("\n\n")
        val block = if (split != -1) norm.substring(0, split) else norm
        val n = asciiLower(name)
        var collecting = false
        var current = StringBuilder()
        for (line in block.split('\n')) {
            if (line != "" && (line[0] == ' ' || line[0] == '\t')) {
                if (collecting) current.append(' ').append(trim(line))
                continue
            }
            if (collecting) return trim(current.toString())
            val colon = line.indexOf(':')
            if (colon == -1) continue
            if (asciiLower(trim(line.substring(0, colon))) == n) {
                collecting = true
                current = StringBuilder(trim(line.substring(colon + 1)))
            }
        }
        return if (collecting) trim(current.toString()) else null
    }

    private fun extractHeaders(raw: String, name: String): List<String> {
        val norm = raw.split("\r\n").joinToString("\n")
        val split = norm.indexOf("\n\n")
        val block = if (split != -1) norm.substring(0, split) else norm
        val n = asciiLower(name)
        val values = ArrayList<String>()
        var collecting = false
        var current = ""
        for (line in block.split('\n')) {
            if (line != "" && (line[0] == ' ' || line[0] == '\t')) {
                if (collecting) current += " " + trim(line)
                continue
            }
            if (collecting) { values.add(current); collecting = false; current = "" }
            val colon = line.indexOf(':')
            if (colon == -1) continue
            if (asciiLower(trim(line.substring(0, colon))) == n) {
                collecting = true
                current = trim(line.substring(colon + 1))
            }
        }
        if (collecting) values.add(current)
        return values
    }

    private val ASCII_SPACES = Regex("$S_ASCII+")
    private val RESULT_KV = Regex("^([A-Za-z][A-Za-z0-9-]*)$S_ASCII*=$S_ASCII*([A-Za-z0-9]+)")

    /** The DKIM d= from our own Authentication-Results stamp. */
    fun dkimDomain(raw: String?, authservId: String?): String {
        if (raw == null) return ""
        val ours = asciiLower(trim(authservId ?: ""))
        if (ours == "") return ""
        var domain: String? = null
        for (line in extractHeaders(raw, "authentication-results")) {
            val segs = line.split(';').map { trim(it) }.filter { it != "" }
            if (segs.isEmpty()) continue
            val first = trim(segs[0]).split(ASCII_SPACES)
            if (asciiLower(first.firstOrNull() ?: "") != ours) continue
            for (i in 1 until segs.size) {
                val seg = trim(segs[i])
                val mm = RESULT_KV.find(seg) ?: continue
                if (asciiLower(mm.groupValues[1]) != "dkim") continue
                val result = asciiLower(mm.groupValues[2])
                var d: String? = null
                for (key in listOf("header\\.d", "header\\.i")) {
                    val pm = Regex("(?<![$W])$key$S_ASCII*=$S_ASCII*\"?([^\";$S_ASCII_BODY]+)\"?", RegexOption.IGNORE_CASE).find(seg)
                    if (pm != null) { d = asciiLower(rtrim(trim(pm.groupValues[1]), ".")); break }
                }
                if (d != null && (domain == null || result == "pass")) domain = d
            }
        }
        return domain ?: ""
    }

    // MARK: The digest

    /** The opened columns the digest is built from (keys as the PHP side). */
    data class Columns(
        val raw: String? = null,
        val sender: String = "",
        val recipient: String = "",
        val receivedTime: String = "",
        val subject: String = "",
        val bodyPlain: String = "",
        val bodyHtml: String = "",
        val spfResult: String = "",
        val dkimResult: String = "",
        val dmarcResult: String = "",
        val authservId: String = "",
    ) {
        companion object {
            fun from(j: JsonValue): Columns {
                fun s(k: String) = j[k]?.takeUnless { it.isNull }?.stringValue ?: ""
                return Columns(
                    raw = j["raw"]?.takeUnless { it.isNull }?.stringValue,
                    sender = s("sender"), recipient = s("recipient"), receivedTime = s("received_time"),
                    subject = s("subject"), bodyPlain = s("body_plain"), bodyHtml = s("body_html"),
                    spfResult = s("spf_result"), dkimResult = s("dkim_result"), dmarcResult = s("dmarc_result"),
                    authservId = s("authserv_id"),
                )
            }
        }
    }

    /** EmailSecurityDigest::buildFromColumns(), key for key. */
    fun build(c: Columns): String {
        val raw = c.raw?.takeIf { it.isNotEmpty() }
        val fromRaw = if (raw != null) extractHeader(raw, "from") else c.sender
        val replyRaw = if (raw != null) extractHeader(raw, "reply-to") else null
        val returnRaw = if (raw != null) extractHeader(raw, "return-path") else null
        val toRaw = if (raw != null) extractHeader(raw, "to") else c.recipient
        val dateRaw = if (raw != null) extractHeader(raw, "date") else c.receivedTime
        val subjectRaw = if (raw != null) extractHeader(raw, "subject") else c.subject

        val from = decodeHeaderValue(fromRaw ?: "")
        val replyTo = if (replyRaw != null && trim(replyRaw) != "") decodeHeaderValue(replyRaw) else "(none)"
        val returnPath = if (returnRaw != null && trim(returnRaw) != "") trim(returnRaw, "<> \t") else "(none)"
        val to = decodeHeaderValue(toRaw ?: "")
        val date = if (trim(dateRaw ?: "") != "") trim(dateRaw!!) else "(unknown)"

        val spf = c.spfResult.ifEmpty { "unverified" }
        val dkim = c.dkimResult.ifEmpty { "unverified" }
        val dmarc = c.dmarcResult.ifEmpty { "unverified" }
        val dDomain = dkimDomain(raw, c.authservId)

        val subj = collapse(decodeHeaderValue(subjectRaw ?: ""))
        val subjectText = capSize(subj.first, SUBJECT_CAP_CHARS)

        val sel = selectBody(c.bodyPlain, c.bodyHtml)
        val body = collapse(sel.first)
        val bodyText = capSize(body.first, BODY_CAP_CHARS)

        val urls = extractUrls(c.bodyHtml, c.bodyPlain)

        val lines = ArrayList<String>()
        lines.add("=== EMAIL DIGEST ===")
        lines.add("FROM: $from")
        lines.add("REPLY-TO: $replyTo")
        lines.add("RETURN-PATH: $returnPath")
        lines.add("TO: $to")
        lines.add("DATE: $date")
        lines.add("AUTHENTICATION: spf=$spf dkim=$dkim (d=${dDomain.ifEmpty { "none" }}) dmarc=$dmarc")
        lines.add("")
        lines.add("SUBJECT (decoded${annotation(subj.second)}):")
        lines.add(subjectText)
        lines.add("")
        lines.add("URLS FOUND (${urls.size}):")
        if (urls.isEmpty()) {
            lines.add("(none found)")
        } else {
            val summary = domainSummary(urls)
            if (summary != "") lines.add("DOMAINS: $summary")
            val shown = urls.take(URL_CAP)
            shown.forEachIndexed { i, (url, text) ->
                var line = "${i + 1}. $url"
                if (text != "" && text != url) line += " — link text: \"$text\""
                lines.add(line)
            }
            if (urls.size > shown.size) lines.add("(+${urls.size - shown.size} more)")
        }
        lines.add("")
        lines.add("BODY (${sel.second}, decoded${annotation(body.second)}):")
        lines.add(bodyText)
        return lines.joinToString("\n")
    }

    private fun truthy(v: JsonValue?): Boolean = when (v) {
        null, JsonValue.Null -> false
        is JsonValue.Bool -> v.value
        is JsonValue.Num -> v.value != 0.0
        is JsonValue.Str -> v.value.isNotEmpty()
        else -> true
    }

    private fun parseIntJs(v: JsonValue?): Long {
        val s = when (v) {
            is JsonValue.Num -> return v.value.toLong()
            is JsonValue.Str -> v.value
            else -> return 0
        }
        return Regex("^[ \\t\\n\\r\\u000B\\f]*[+-]?\\d+").find(s)?.value?.trim()?.toLongOrNull() ?: 0
    }

    /** EmailAttachmentDigest::buildFromManifest(). */
    fun attachments(manifest: List<JsonValue>): String {
        val parts = manifest.filter { it is JsonValue.Obj && !truthy(it["inline"]) }
        if (parts.isEmpty()) return ""
        val lines = arrayListOf("ATTACHMENTS (${parts.size}):")
        var shown = 0
        for (e in parts) {
            if (shown >= ATT_MAX_PARTS) break
            shown++
            val fnRaw = e["filename"]?.takeUnless { it.isNull }?.stringValue ?: ""
            var filename = trim(fnRaw).replace(WHITESPACE_RUN, " ")
            if (filename == "") filename = "(unnamed)"
            else if (mbLen(filename) > ATT_FILENAME_CAP_CHARS) filename = mbSub(filename, ATT_FILENAME_CAP_CHARS)
            var type = trim(e["content_type"]?.takeUnless { it.isNull }?.stringValue ?: "")
            if (type == "") type = "application/octet-stream"
            val size = parseIntJs(e["size"])
            lines.add("$shown. $filename — $type, $size bytes")
        }
        if (parts.size > shown) lines.add("(+${parts.size - shown} more attachments)")
        return lines.joinToString("\n")
    }

    // MARK: The untrusted-input envelope

    private val GAP = "[${S_UCP_BODY}\\u00AD\\u200B-\\u200F\\u202A-\\u202E\\u2060-\\u2064\\u2066-\\u206F\\uFEFF]*"
    private val MARKER = Regex("<<$GAP/?${GAP}UNTRUSTED_", setOf(RegexOption.IGNORE_CASE))

    fun neutralize(content: String): String = content.replace(MARKER, "[marker removed]")

    fun wrapBlock(content: String, nonce: String): String =
        "<<UNTRUSTED_$nonce>>\n${neutralize(content)}\n<</UNTRUSTED_$nonce>>"
}
