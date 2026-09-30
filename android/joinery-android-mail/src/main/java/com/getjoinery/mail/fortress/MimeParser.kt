package com.getjoinery.mail.fortress

import java.nio.ByteBuffer
import java.nio.charset.Charset
import java.nio.charset.CharsetDecoder
import java.nio.charset.CodingErrorAction

/**
 * An RFC 5322 / MIME parser for relay-sealed mail — a port of
 * `plugins/mailbox/assets/mailbox_mime.js` (specs/fortress_mobile_apps.md § R7,
 * WP7). A message sealed at the relay reaches the server as ciphertext, so
 * the device that opens it does the parse the server would have done; its
 * mimePart numbers are what each part's AD is built from, so they must match
 * the browser's exactly. Pinned by the `*.expected.json` files of `plugins/mailbox/tests/fixtures/mime`.
 *
 * It never throws: a part that cannot be read becomes an opaque attachment
 * or is dropped.
 */
object MimeParser {
    private const val MAX_DEPTH = 30
    private const val CR = 13
    private const val LF = 10
    private const val SP = 32
    private const val TAB = 9
    private const val DASH = 45
    private const val EQ = 61

    data class Part(
        val filename: String,
        val contentType: String,
        val contentId: String,
        val mimePart: String,
        val inline: Boolean,
        val bytes: ByteArray,
    )

    data class Parsed(
        var headers: String = "",
        var from: String = "",
        var to: String = "",
        var cc: String = "",
        var subject: String = "",
        var date: String = "",
        var messageId: String = "",
        var inReplyTo: String = "",
        var references: String = "",
        var textPlain: String = "",
        var textHtml: String = "",
        val attachments: MutableList<Part> = ArrayList(),
    )

    // MARK: bytes → text

    private val CHARSET_ALIASES = mapOf(
        "utf8" to "utf-8", "utf-8" to "utf-8", "unicode-1-1-utf-8" to "utf-8",
        "ascii" to "us-ascii", "us_ascii" to "us-ascii", "ansi_x3.4-1968" to "us-ascii",
        "latin1" to "iso-8859-1", "latin-1" to "iso-8859-1", "iso8859-1" to "iso-8859-1",
        "iso_8859-1" to "iso-8859-1", "iso-latin-1" to "iso-8859-1",
        "cp1252" to "windows-1252", "win-1252" to "windows-1252",
        "ks_c_5601-1987" to "euc-kr", "x-sjis" to "shift_jis", "sjis" to "shift_jis",
        "gb2312" to "gbk", "x-gbk" to "gbk", "cp936" to "gbk", "big5-hkscs" to "big5",
    )

    /** WHATWG windows-1252, which TextDecoder uses for every Latin-1 label:
     *  0x80–0x9F map through this table, the five holes to themselves. */
    private val W1252_HIGH = intArrayOf(
        0x20AC, 0x0081, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, 0x008D, 0x017D, 0x008F,
        0x0090, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x009D, 0x017E, 0x0178,
    )

    internal val LATIN1_LABELS = setOf(
        "windows-1252", "iso-8859-1", "us-ascii", "l1", "cp819", "ibm819", "iso-ir-100", "iso_8859-1:1987",
        "csisolatin1", "iso88591", "x-cp1252", "iso-8859-1",
    )

    internal fun decode1252(bytes: ByteArray, from: Int = 0, to: Int = bytes.size): String {
        val sb = StringBuilder(to - from)
        for (i in from until to) {
            val b = bytes[i].toInt() and 0xFF
            sb.append(if (b in 0x80..0x9F) W1252_HIGH[b - 0x80].toChar() else b.toChar())
        }
        return sb.toString()
    }

    private fun isValidUtf8(bytes: ByteArray): Boolean = try {
        Charsets.UTF_8.newDecoder()
            .onMalformedInput(CodingErrorAction.REPORT)
            .onUnmappableCharacter(CodingErrorAction.REPORT)
            .decode(ByteBuffer.wrap(bytes))
        true
    } catch (e: Exception) {
        false
    }

    private fun hasHighBytes(bytes: ByteArray): Boolean = bytes.any { (it.toInt() and 0xFF) > 127 }

    private fun decoderFor(label: String): CharsetDecoder? {
        if (label in LATIN1_LABELS) return null
        return try {
            Charset.forName(label).newDecoder()
                .onMalformedInput(CodingErrorAction.REPLACE)
                .onUnmappableCharacter(CodingErrorAction.REPLACE)
        } catch (e: Exception) {
            null
        }
    }

    private fun decodeWith(label: String, bytes: ByteArray): String {
        if (label in LATIN1_LABELS) return decode1252(bytes)
        val d = decoderFor(label) ?: decoderFor("utf-8")!!
        val text = try {
            d.decode(ByteBuffer.wrap(bytes)).toString()
        } catch (e: Exception) {
            String(bytes, Charsets.UTF_8)
        }
        // TextDecoder drops a leading byte-order mark.
        return if (text.startsWith("\uFEFF") && d.charset().name().startsWith("UTF")) text.substring(1) else text
    }

    /** Bytes to a string under a charset label; an unknown label is utf-8. */
    fun decodeText(bytes: ByteArray, charset: String?): String {
        var label = (charset ?: "").trim().lowercase().replace(Regex("^[\"']|[\"']$"), "")
        label = CHARSET_ALIASES[label] ?: label
        if (label == "" || label == "us-ascii") {
            if (!hasHighBytes(bytes)) return latin1(bytes)
            label = if (isValidUtf8(bytes)) "utf-8" else "windows-1252"
        }
        if (label !in LATIN1_LABELS && decoderFor(label) == null) label = "utf-8"
        return decodeWith(label, bytes)
    }

    /** Each byte as one code unit: for ASCII syntax that must not be re-coded. */
    private fun latin1(bytes: ByteArray, start: Int = 0, end: Int = bytes.size): String {
        val sb = StringBuilder(maxOf(0, end - start))
        for (i in start until end) sb.append((bytes[i].toInt() and 0xFF).toChar())
        return sb.toString()
    }

    /** A header block's text: UTF-8 when it is valid UTF-8 (RFC 6532), else 1252. */
    private fun headerText(bytes: ByteArray): String {
        if (!hasHighBytes(bytes)) return latin1(bytes)
        return if (isValidUtf8(bytes)) String(bytes, Charsets.UTF_8).removePrefix("\uFEFF") else decode1252(bytes)
    }

    /** UTF-8 of one UTF-16 code unit, as TextEncoder does it (a lone surrogate is U+FFFD). */
    private fun utf8Unit(c: Char): ByteArray =
        if (Character.isSurrogate(c)) byteArrayOf(0xEF.toByte(), 0xBF.toByte(), 0xBD.toByte())
        else c.toString().toByteArray(Charsets.UTF_8)

    // MARK: Transfer encodings

    private val B64 = IntArray(256) { -1 }.also { t ->
        val abc = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"
        for (j in abc.indices) t[abc[j].code] = j
        t['-'.code] = 62  // the URL-safe alphabet, seen in the wild
        t['_'.code] = 63
    }

    fun decodeBase64(src: ByteArray): ByteArray {
        val out = ByteArray(src.size * 3 / 4 + 3)
        var o = 0
        var acc = 0
        var n = 0
        for (x in src) {
            val c = x.toInt() and 0xFF
            if (c == EQ) {
                if (n == 2) out[o++] = ((acc shr 4) and 255).toByte()
                else if (n == 3) { out[o++] = ((acc shr 10) and 255).toByte(); out[o++] = ((acc shr 2) and 255).toByte() }
                acc = 0; n = 0
                continue
            }
            val v = B64[c]
            if (v < 0) continue
            acc = (acc shl 6) or v
            if (++n == 4) {
                out[o++] = ((acc shr 16) and 255).toByte()
                out[o++] = ((acc shr 8) and 255).toByte()
                out[o++] = (acc and 255).toByte()
                acc = 0; n = 0
            }
        }
        if (n == 2) out[o++] = ((acc shr 4) and 255).toByte()
        else if (n == 3) { out[o++] = ((acc shr 10) and 255).toByte(); out[o++] = ((acc shr 2) and 255).toByte() }
        return out.copyOf(o)
    }

    private fun hexVal(c: Int): Int = when (c) {
        in 48..57 -> c - 48
        in 65..70 -> c - 55
        in 97..102 -> c - 87
        else -> -1
    }

    fun decodeQuotedPrintable(src: ByteArray): ByteArray {
        val out = ByteArray(src.size)
        var o = 0
        var lineStart = 0
        fun at(i: Int) = if (i < src.size) src[i].toInt() and 0xFF else -1
        var i = 0
        while (i < src.size) {
            val c = at(i)
            if (c == EQ) {
                val h1 = if (i + 1 < src.size) hexVal(at(i + 1)) else -1
                val h2 = if (i + 2 < src.size) hexVal(at(i + 2)) else -1
                if (h1 >= 0 && h2 >= 0) {
                    out[o++] = ((h1 shl 4) or h2).toByte()
                    i += 3
                    continue
                }
                var j = i + 1
                while (j < src.size && (at(j) == SP || at(j) == TAB)) j++
                if (j >= src.size) { i = j + 1; continue }
                if (at(j) == CR && at(j + 1) == LF) { i = j + 2; lineStart = o; continue }
                if (at(j) == LF) { i = j + 1; lineStart = o; continue }
                out[o++] = c.toByte()
                i++
                continue
            }
            if (c == CR || c == LF) {
                while (o > lineStart && (out[o - 1].toInt() == SP || out[o - 1].toInt() == TAB)) o--
                out[o++] = c.toByte()
                if (c == CR && at(i + 1) == LF) { out[o++] = LF.toByte(); i++ }
                lineStart = o
                i++
                continue
            }
            out[o++] = c.toByte()
            i++
        }
        while (o > lineStart && (out[o - 1].toInt() == SP || out[o - 1].toInt() == TAB)) o--
        return out.copyOf(o)
    }

    private fun decodeTransfer(bytes: ByteArray, cte: String?): ByteArray = when ((cte ?: "").trim().lowercase()) {
        "base64" -> decodeBase64(bytes)
        "quoted-printable" -> decodeQuotedPrintable(bytes)
        else -> bytes.copyOf()
    }

    // MARK: RFC 2047 encoded words

    private fun qWordBytes(text: String): ByteArray {
        val out = java.io.ByteArrayOutputStream()
        var i = 0
        while (i < text.length) {
            val ch = text[i]
            if (ch == '_') { out.write(32); i++; continue }
            if (ch == '=' && i + 2 < text.length) {
                val h1 = hexVal(text[i + 1].code)
                val h2 = hexVal(text[i + 2].code)
                if (h1 >= 0 && h2 >= 0) { out.write((h1 shl 4) or h2); i += 3; continue }
            }
            if (ch.code < 128) out.write(ch.code) else out.write(utf8Unit(ch))
            i++
        }
        return out.toByteArray()
    }

    private val ENCODED_WORD = Regex("=\\?([^?\\s]+)\\?([BbQq])\\?([^?]*)\\?=")
    private val ONLY_SPACE = Regex("^[ \\t\\r\\n]*$")

    fun decodeWords(value: String?): String {
        val s = value ?: ""
        if (!s.contains("=?")) return s
        val out = StringBuilder()
        var last = 0
        var pendingCharset: String? = null
        val pendingParts = java.io.ByteArrayOutputStream()

        fun flush() {
            val cs = pendingCharset ?: return
            out.append(decodeText(pendingParts.toByteArray(), cs))
            pendingParts.reset()
            pendingCharset = null
        }

        for (m in ENCODED_WORD.findAll(s)) {
            val between = s.substring(last, m.range.first)
            val joined = pendingCharset != null && ONLY_SPACE.matches(between)
            if (!joined) {
                flush()
                out.append(between)
            }
            val charset = m.groupValues[1].split('*')[0].lowercase()
            val bytes = if (m.groupValues[2].uppercase() == "B") decodeBase64(latin1Bytes(m.groupValues[3]))
            else qWordBytes(m.groupValues[3])
            if (pendingCharset != null && pendingCharset != charset) flush()
            if (pendingCharset == null) pendingCharset = charset
            pendingParts.write(bytes)
            last = m.range.last + 1
        }
        flush()
        out.append(s.substring(last))
        return out.toString()
    }

    private fun latin1Bytes(str: String): ByteArray = ByteArray(str.length) { (str[it].code and 255).toByte() }

    // MARK: Headers

    private class Split(val headerEnd: Int, val bodyStart: Int)

    private val FIRST_HEADER = Regex("^[!-9;-~]+[ \\t]*:")
    private val HEADER_NAME = Regex("^[!-9;-~]+[ \\t]*$")

    private fun indexOfByte(bytes: ByteArray, b: Int, from: Int, end: Int): Int {
        for (i in from until end) if ((bytes[i].toInt() and 0xFF) == b) return i
        return -1
    }

    private fun splitHeaders(bytes: ByteArray, start: Int, end: Int): Split {
        val firstNl = indexOfByte(bytes, LF, start, end)
        val first = latin1(bytes, start, minOf(if (firstNl < 0) end else firstNl, start + 1000))
        if (first != "" && first != "\r" && !FIRST_HEADER.containsMatchIn(first) && !first.startsWith("From ")) {
            return Split(start, start)
        }
        var i = start
        while (i < end) {
            val b = bytes[i].toInt() and 0xFF
            if (b == LF) return Split(i, i + 1)
            if (b == CR && i + 1 < end && (bytes[i + 1].toInt() and 0xFF) == LF) return Split(i, i + 2)
            if (b == CR && i + 1 == end) return Split(i, end)
            val nl = indexOfByte(bytes, LF, i, end)
            if (nl < 0) return Split(end, end)
            i = nl + 1
        }
        return Split(end, end)
    }

    private class Field(val name: String, var value: String)

    private fun parseHeaderFields(text: String): List<Field> {
        val fields = ArrayList<Field>()
        var cur: Field? = null
        for (raw in text.split('\n')) {
            val line = if (raw.endsWith('\r')) raw.dropLast(1) else raw
            if (line.isEmpty()) continue
            val first = line[0]
            if ((first == ' ' || first == '\t') && cur != null) {
                cur.value += line
                continue
            }
            val colon = line.indexOf(':')
            if (colon <= 0 || !HEADER_NAME.matches(line.substring(0, colon))) {
                cur = null
                continue
            }
            cur = Field(line.substring(0, colon).trim().lowercase(), line.substring(colon + 1))
            fields.add(cur)
        }
        return fields
    }

    private fun firstField(fields: List<Field>, name: String): String? {
        val n = name.lowercase()
        return fields.firstOrNull { it.name == n }?.value?.trim()
    }

    private fun splitOutsideQuotes(s: String, delim: Char): List<String> {
        val parts = ArrayList<String>()
        val cur = StringBuilder()
        var inQ = false
        var i = 0
        while (i < s.length) {
            val ch = s[i]
            if (inQ) {
                cur.append(ch)
                if (ch == '\\' && i + 1 < s.length) { cur.append(s[i + 1]); i += 2; continue }
                else if (ch == '"') inQ = false
                i++
                continue
            }
            if (ch == '"') { inQ = true; cur.append(ch); i++; continue }
            if (ch == delim) { parts.add(cur.toString()); cur.setLength(0); i++; continue }
            cur.append(ch)
            i++
        }
        parts.add(cur.toString())
        return parts
    }

    private val ESCAPED = Regex("\\\\(.)")

    private fun unquote(raw: String): String {
        val v = raw.trim()
        if (v.length >= 2 && v.first() == '"' && v.last() == '"') return v.substring(1, v.length - 1).replace(ESCAPED, "$1")
        if (v.startsWith("\"")) return v.substring(1).replace(ESCAPED, "$1")
        return v
    }

    private fun percentBytes(s: String, out: java.io.ByteArrayOutputStream) {
        var i = 0
        while (i < s.length) {
            val c = s[i].code
            if (c == 37 && i + 2 < s.length) {
                val h1 = hexVal(s[i + 1].code)
                val h2 = hexVal(s[i + 2].code)
                if (h1 >= 0 && h2 >= 0) { out.write((h1 shl 4) or h2); i += 3; continue }
            }
            if (c < 128) out.write(c) else out.write(utf8Unit(s[i]))
            i++
        }
    }

    private class Structured(val value: String, val params: MutableMap<String, String>)

    private val COMMENT = Regex("\\([^)]*\\)")
    private val EXT_KEY = Regex("^([^*]+)\\*(?:(\\d+)\\*?)?$")

    private class Seg(val index: Int, val encoded: Boolean, val value: String)

    private fun parseStructured(raw: String?): Structured {
        val pieces = splitOutsideQuotes(raw ?: "", ';')
        val value = pieces[0].replace(COMMENT, "").trim().lowercase()
        val plain = LinkedHashMap<String, String>()
        val ext = LinkedHashMap<String, MutableList<Seg>>()
        for (i in 1 until pieces.size) {
            val p = pieces[i]
            val eq = p.indexOf('=')
            if (eq <= 0) continue
            val key = p.substring(0, eq).trim().lowercase()
            val uq = unquote(p.substring(eq + 1))
            val m = EXT_KEY.find(key)
            if (m != null && key.contains('*')) {
                val encoded = key.last() == '*'
                val index = m.groupValues[2].takeIf { it.isNotEmpty() }?.toIntOrNull() ?: 0
                ext.getOrPut(m.groupValues[1]) { ArrayList() }.add(Seg(index, encoded, uq))
            } else if (!plain.containsKey(key)) {
                plain[key] = decodeWords(uq)
            }
        }
        val params = LinkedHashMap<String, String>(plain)
        for ((k, list) in ext) {
            val segs = list.sortedBy { it.index }
            var charset = ""
            val bytes = java.io.ByteArrayOutputStream()
            var anyEncoded = false
            segs.forEachIndexed { s, seg ->
                var v = seg.value
                if (seg.encoded) {
                    anyEncoded = true
                    if (s == 0) {
                        val q1 = v.indexOf('\'')
                        val q2 = if (q1 >= 0) v.indexOf('\'', q1 + 1) else -1
                        if (q1 >= 0 && q2 >= 0) {
                            charset = v.substring(0, q1)
                            v = v.substring(q2 + 1)
                        }
                    }
                    percentBytes(v, bytes)
                } else {
                    bytes.write(v.toByteArray(Charsets.UTF_8))
                }
            }
            val text = decodeText(bytes.toByteArray(), if (anyEncoded) charset.ifEmpty { "utf-8" } else "utf-8")
            params[k] = if (anyEncoded) text else decodeWords(text)
        }
        return Structured(value, params)
    }

    // MARK: Multipart

    private fun splitMultipart(bytes: ByteArray, start: Int, end: Int, boundary: String): List<IntArray>? {
        val delim = latin1Bytes("--$boundary")
        val dl = delim.size
        val ranges = ArrayList<IntArray>()
        var partStart = -1
        var found = false
        var i = start
        while (i + dl <= end) {
            var match = true
            for (k in 0 until dl) if (bytes[i + k] != delim[k]) { match = false; break }
            val nl = indexOfByte(bytes, LF, i, end)
            val lineEnd = if (nl < 0) end else nl
            if (match) {
                var j = i + dl
                var close = false
                if (j + 1 < lineEnd && bytes[j].toInt() == DASH && bytes[j + 1].toInt() == DASH) { close = true; j += 2 }
                var rest = true
                for (r in j until lineEnd) {
                    val c = bytes[r].toInt() and 0xFF
                    if (c != SP && c != TAB && c != CR) { rest = false; break }
                }
                if (rest) {
                    found = true
                    if (partStart >= 0) {
                        var pe = i
                        if (pe > partStart && bytes[pe - 1].toInt() == LF) pe--
                        if (pe > partStart && bytes[pe - 1].toInt() == CR) pe--
                        ranges.add(intArrayOf(partStart, maxOf(partStart, pe)))
                    }
                    if (close) return ranges
                    partStart = if (nl < 0) end else nl + 1
                }
            }
            if (nl < 0) break
            i = nl + 1
        }
        if (!found) return null
        if (partStart in 0 until end) ranges.add(intArrayOf(partStart, end))
        return ranges
    }

    // MARK: The walk

    private class Leaf(
        val type: String,
        val charset: String,
        val disposition: String,
        val hasName: Boolean,
        val filename: String,
        val contentId: String,
        val mimePart: String,
        val inRelated: Boolean,
        val bytes: ByteArray,
    )

    private val TYPE_SHAPE = Regex("^[^/\\s]+/[^/\\s]+$")
    private val CID_TRIM = Regex("^[\\s<]+|[\\s>]+$")

    private fun walk(bytes: ByteArray, start: Int, end: Int, partNo: String, depth: Int, ancestors: List<String>, leaves: MutableList<Leaf>) {
        val split = splitHeaders(bytes, start, end)
        val fields = parseHeaderFields(headerText(bytes.copyOfRange(start, split.headerEnd)))
        val parent = ancestors.lastOrNull() ?: ""

        val ctRaw = firstField(fields, "content-type")
        val ct = parseStructured(ctRaw ?: "")
        var type = ct.value
        if (ctRaw == null || !TYPE_SHAPE.matches(type)) {
            type = if (ctRaw == null && parent == "digest") "message/rfc822" else "text/plain"
            if (ct.params["charset"].isNullOrEmpty()) ct.params["charset"] = "us-ascii"
        }

        if (type.startsWith("multipart/") && depth < MAX_DEPTH) {
            val boundary = ct.params["boundary"]
            val ranges = if (!boundary.isNullOrEmpty()) splitMultipart(bytes, split.bodyStart, end, boundary) else null
            if (ranges != null) {
                val inner = ancestors + type.substring(10)
                ranges.forEachIndexed { i, r ->
                    val childNo = if (partNo == "") (i + 1).toString() else "$partNo.${i + 1}"
                    try {
                        walk(bytes, r[0], r[1], childNo, depth + 1, inner, leaves)
                    } catch (e: Exception) {
                        leaves.add(opaqueLeaf(bytes, r[0], r[1], childNo))
                    }
                }
                return
            }
            type = "text/plain"
        }

        val disp = parseStructured(firstField(fields, "content-disposition") ?: "")
        val cid = (firstField(fields, "content-id") ?: "").replace(CID_TRIM, "")
        var filename = disp.params["filename"]?.takeIf { it.isNotEmpty() } ?: ct.params["name"] ?: ""
        if (filename.isEmpty() && (type == "message/rfc822" || type == "message/global")) filename = "message.eml"
        val decoded = try {
            decodeTransfer(bytes.copyOfRange(split.bodyStart, end), firstField(fields, "content-transfer-encoding"))
        } catch (e: Exception) {
            bytes.copyOfRange(split.bodyStart, end)
        }
        leaves.add(
            Leaf(
                type = type,
                charset = ct.params["charset"] ?: "",
                disposition = disp.value,
                hasName = !disp.params["filename"].isNullOrEmpty() || !ct.params["name"].isNullOrEmpty(),
                filename = filename,
                contentId = cid,
                mimePart = if (partNo == "") "1" else partNo,
                inRelated = ancestors.contains("related"),
                bytes = decoded,
            ),
        )
    }

    private fun opaqueLeaf(bytes: ByteArray, start: Int, end: Int, partNo: String) = Leaf(
        "application/octet-stream", "", "attachment", false, "", "", partNo.ifEmpty { "1" }, false, bytes.copyOfRange(start, end),
    )

    fun parse(bytes: ByteArray?): Parsed {
        val result = Parsed()
        if (bytes == null) return result
        try {
            val split = splitHeaders(bytes, 0, bytes.size)
            result.headers = headerText(bytes.copyOfRange(0, split.headerEnd))
            val fields = parseHeaderFields(result.headers)
            fun dec(n: String) = firstField(fields, n)?.let { decodeWords(it).trim() } ?: ""
            fun raw(n: String) = firstField(fields, n) ?: ""
            result.from = dec("from")
            result.to = dec("to")
            result.cc = dec("cc")
            result.subject = dec("subject")
            result.date = raw("date")
            result.messageId = raw("message-id")
            result.inReplyTo = raw("in-reply-to")
            result.references = raw("references")
        } catch (e: Exception) {
            // headers stay as far as they got
        }

        val leaves = ArrayList<Leaf>()
        try {
            walk(bytes, 0, bytes.size, "", 0, emptyList(), leaves)
        } catch (e: Exception) {
            if (leaves.isEmpty()) leaves.add(opaqueLeaf(bytes, 0, bytes.size, "1"))
        } catch (e: StackOverflowError) {
            if (leaves.isEmpty()) leaves.add(opaqueLeaf(bytes, 0, bytes.size, "1"))
        }

        var plainAt = -1
        var htmlAt = -1
        leaves.forEachIndexed { i, l ->
            if (l.disposition == "attachment" || l.hasName) return@forEachIndexed
            if (plainAt < 0 && l.type == "text/plain") plainAt = i
            else if (htmlAt < 0 && l.type == "text/html") htmlAt = i
        }
        leaves.forEachIndexed { j, leaf ->
            try {
                if (j == plainAt) { result.textPlain = decodeText(leaf.bytes, leaf.charset); return@forEachIndexed }
                if (j == htmlAt) { result.textHtml = decodeText(leaf.bytes, leaf.charset); return@forEachIndexed }
            } catch (e: Exception) {
                // an unreadable body falls through to an attachment
            }
            result.attachments.add(
                Part(
                    filename = leaf.filename,
                    contentType = leaf.type,
                    contentId = leaf.contentId,
                    mimePart = leaf.mimePart,
                    inline = leaf.disposition == "inline" || (leaf.contentId != "" && leaf.inRelated),
                    bytes = leaf.bytes,
                ),
            )
        }
        return result
    }

    /** The first top-level header called [name], unfolded and decoded, or "". */
    fun headerValue(parsed: Parsed, name: String): String = try {
        firstField(parseHeaderFields(parsed.headers), name)?.let { decodeWords(it).trim() } ?: ""
    } catch (e: Exception) {
        ""
    }
}
