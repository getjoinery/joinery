package com.getjoinery.android

/**
 * An order-preserving JSON value.
 *
 * Form definitions carry `options` objects whose key order is meaningful — the
 * server emits options in display order and the web renders them that way.
 * Android's `org.json` (like Foundation on iOS) hands back unordered maps, so
 * JoineryKit parses API JSON with its own small parser and represents objects
 * as ordered key/value pair lists.
 *
 * Also absorbs a server quirk: an empty `data` serializes as `[]` (PHP empty
 * array), a populated one as a `{}`-style object. Both decode fine here and
 * [objectValue] treats an empty array as an empty object.
 */
sealed class JsonValue {
    data class Str(val value: String) : JsonValue()
    data class Num(val value: Double) : JsonValue()
    data class Bool(val value: Boolean) : JsonValue()
    object Null : JsonValue()
    data class Arr(val items: List<JsonValue>) : JsonValue()
    /** Object pairs in document order. */
    data class Obj(val pairs: List<Pair<String, JsonValue>>) : JsonValue()

    // MARK: Accessors

    val stringValue: String?
        get() = when (this) {
            is Str -> value
            is Num -> if (value % 1.0 == 0.0 && kotlin.math.abs(value) < 1e15) value.toLong().toString() else value.toString()
            is Bool -> if (value) "1" else "0"
            else -> null
        }

    val intValue: Int?
        get() = when (this) {
            is Num -> value.toInt()
            is Str -> value.toIntOrNull()
            is Bool -> if (value) 1 else 0
            else -> null
        }

    val doubleValue: Double?
        get() = when (this) {
            is Num -> value
            is Str -> value.toDoubleOrNull()
            else -> null
        }

    val boolValue: Boolean?
        get() = when (this) {
            is Bool -> value
            is Num -> value != 0.0
            is Str -> when {
                value == "1" || value.lowercase() == "true" -> true
                value == "0" || value.lowercase() == "false" || value.isEmpty() -> false
                else -> null
            }
            else -> null
        }

    val arrayValue: List<JsonValue>?
        get() = (this as? Arr)?.items

    /** Object pairs in document order. An empty JSON array also reads as an
     *  empty object (the PHP empty-array quirk). */
    val objectValue: List<Pair<String, JsonValue>>?
        get() = when (this) {
            is Obj -> pairs
            is Arr -> if (items.isEmpty()) emptyList() else null
            else -> null
        }

    val isNull: Boolean
        get() = this is Null

    /** First value for a key (document order). */
    operator fun get(key: String): JsonValue? =
        objectValue?.firstOrNull { it.first == key }?.second

    // MARK: Encoding

    /** Serialize back to compact JSON text (object order preserved). */
    fun encoded(): String = when (this) {
        is Str -> encodeString(value)
        is Num -> if (value % 1.0 == 0.0 && kotlin.math.abs(value) < 1e15) value.toLong().toString() else value.toString()
        is Bool -> if (value) "true" else "false"
        is Null -> "null"
        is Arr -> "[" + items.joinToString(",") { it.encoded() } + "]"
        is Obj -> "{" + pairs.joinToString(",") { encodeString(it.first) + ":" + it.second.encoded() } + "}"
    }

    fun encodedBytes(): ByteArray = encoded().toByteArray(Charsets.UTF_8)

    companion object {
        fun obj(vararg pairs: Pair<String, JsonValue>): Obj = Obj(pairs.toList())

        private fun encodeString(s: String): String {
            val out = StringBuilder("\"")
            for (ch in s) {
                when (ch) {
                    '"' -> out.append("\\\"")
                    '\\' -> out.append("\\\\")
                    '\n' -> out.append("\\n")
                    '\r' -> out.append("\\r")
                    '\t' -> out.append("\\t")
                    else -> if (ch.code < 0x20) out.append("\\u%04x".format(ch.code)) else out.append(ch)
                }
            }
            return out.append("\"").toString()
        }

        // MARK: Parsing

        class ParseException(message: String) : Exception(message)

        fun parse(bytes: ByteArray): JsonValue = parse(String(bytes, Charsets.UTF_8))

        fun parse(text: String): JsonValue {
            val parser = Parser(text)
            val value = parser.parseValue()
            parser.skipWhitespace()
            if (!parser.isAtEnd) throw ParseException("trailing characters")
            return value
        }

        private class Parser(text: String) {
            private val chars: IntArray = text.codePoints().toArray()
            private var pos = 0

            val isAtEnd: Boolean get() = pos >= chars.size

            fun skipWhitespace() {
                while (pos < chars.size) {
                    val c = chars[pos]
                    if (c == ' '.code || c == '\t'.code || c == '\n'.code || c == '\r'.code) pos++ else break
                }
            }

            fun parseValue(): JsonValue {
                skipWhitespace()
                if (isAtEnd) throw ParseException("unexpected end")
                return when (chars[pos]) {
                    '{'.code -> parseObject()
                    '['.code -> parseArray()
                    '"'.code -> Str(parseString())
                    't'.code -> { expect("true"); Bool(true) }
                    'f'.code -> { expect("false"); Bool(false) }
                    'n'.code -> { expect("null"); Null }
                    else -> parseNumber()
                }
            }

            private fun expect(word: String) {
                for (c in word) {
                    if (pos >= chars.size || chars[pos] != c.code) throw ParseException("expected $word")
                    pos++
                }
            }

            private fun parseObject(): JsonValue {
                pos++ // {
                val pairs = ArrayList<Pair<String, JsonValue>>()
                skipWhitespace()
                if (pos < chars.size && chars[pos] == '}'.code) { pos++; return Obj(pairs) }
                while (true) {
                    skipWhitespace()
                    if (pos >= chars.size || chars[pos] != '"'.code) throw ParseException("expected object key")
                    val key = parseString()
                    skipWhitespace()
                    if (pos >= chars.size || chars[pos] != ':'.code) throw ParseException("expected ':'")
                    pos++
                    val value = parseValue()
                    pairs.add(key to value)
                    skipWhitespace()
                    if (pos >= chars.size) throw ParseException("unterminated object")
                    when (chars[pos]) {
                        ','.code -> { pos++; continue }
                        '}'.code -> { pos++; return Obj(pairs) }
                        else -> throw ParseException("expected ',' or '}'")
                    }
                }
            }

            private fun parseArray(): JsonValue {
                pos++ // [
                val items = ArrayList<JsonValue>()
                skipWhitespace()
                if (pos < chars.size && chars[pos] == ']'.code) { pos++; return Arr(items) }
                while (true) {
                    items.add(parseValue())
                    skipWhitespace()
                    if (pos >= chars.size) throw ParseException("unterminated array")
                    when (chars[pos]) {
                        ','.code -> { pos++; continue }
                        ']'.code -> { pos++; return Arr(items) }
                        else -> throw ParseException("expected ',' or ']'")
                    }
                }
            }

            private fun parseString(): String {
                pos++ // opening quote
                val sb = StringBuilder()
                while (pos < chars.size) {
                    val c = chars[pos]
                    if (c == '"'.code) { pos++; return sb.toString() }
                    if (c == '\\'.code) {
                        pos++
                        if (pos >= chars.size) break
                        when (chars[pos]) {
                            '"'.code -> { sb.append('"'); pos++ }
                            '\\'.code -> { sb.append('\\'); pos++ }
                            '/'.code -> { sb.append('/'); pos++ }
                            'b'.code -> { sb.append(''); pos++ }
                            'f'.code -> { sb.append(''); pos++ }
                            'n'.code -> { sb.append('\n'); pos++ }
                            'r'.code -> { sb.append('\r'); pos++ }
                            't'.code -> { sb.append('\t'); pos++ }
                            'u'.code -> {
                                pos++
                                val unit = parseHex4()
                                if (unit in 0xD800..0xDBFF && pos + 1 < chars.size &&
                                    chars[pos] == '\\'.code && chars[pos + 1] == 'u'.code) {
                                    pos += 2
                                    val low = parseHex4()
                                    if (low in 0xDC00..0xDFFF) {
                                        val combined = 0x10000 + ((unit - 0xD800) shl 10) + (low - 0xDC00)
                                        sb.appendCodePoint(combined)
                                    } else throw ParseException("invalid surrogate pair")
                                } else {
                                    sb.appendCodePoint(unit)
                                }
                            }
                            else -> throw ParseException("bad escape")
                        }
                    } else {
                        sb.appendCodePoint(c)
                        pos++
                    }
                }
                throw ParseException("unterminated string")
            }

            private fun parseHex4(): Int {
                var value = 0
                repeat(4) {
                    if (pos >= chars.size) throw ParseException("bad \\u escape")
                    val digit = hexDigit(chars[pos]) ?: throw ParseException("bad \\u escape")
                    value = value * 16 + digit
                    pos++
                }
                return value
            }

            private fun hexDigit(c: Int): Int? = when (c) {
                in '0'.code..'9'.code -> c - '0'.code
                in 'a'.code..'f'.code -> c - 'a'.code + 10
                in 'A'.code..'F'.code -> c - 'A'.code + 10
                else -> null
            }

            private fun parseNumber(): JsonValue {
                val start = pos
                if (pos < chars.size && chars[pos] == '-'.code) pos++
                while (pos < chars.size && isNumberChar(chars[pos])) pos++
                if (pos <= start) throw ParseException("expected value")
                val literal = String(chars, start, pos - start)
                val n = literal.toDoubleOrNull() ?: throw ParseException("bad number $literal")
                return Num(n)
            }

            private fun isNumberChar(c: Int): Boolean =
                (c in '0'.code..'9'.code) || c == '.'.code || c == 'e'.code || c == 'E'.code || c == '+'.code || c == '-'.code
        }
    }
}
