package com.getjoinery.mail.ai

import com.getjoinery.android.JsonValue

/**
 * A model's answer turned into a verdict on the phone — a port of
 * `assets/js/verdict-check.js` (PipelineRunner::parseVerdict(),
 * DescriptorValidator::coerce() and each device-capable job's
 * validateVerdict()). The same answer must be accepted or refused with the
 * same words wherever it is read: a refusal's message is what the one retry
 * feeds back to the model. Pinned by device_ai_vectors.json.
 */
object VerdictCheck {
    sealed class Result {
        data class Verdict(val verdict: JsonValue.Obj) : Result()
        data class Error(val message: String) : Result()
    }

    private class Invalid(message: String) : Exception(message)

    private const val PHP_TRIM = " \t\n\r\u0000\u000B"
    private fun trim(s: String): String {
        var a = 0
        var b = s.length
        while (a < b && PHP_TRIM.indexOf(s[a]) != -1) a++
        while (b > a && PHP_TRIM.indexOf(s[b - 1]) != -1) b--
        return s.substring(a, b)
    }

    private fun cpLen(s: String) = s.codePointCount(0, s.length)

    /** PipelineRunner::extractFirstJsonObject(). */
    fun extractFirstJsonObject(text: String): String? {
        val start = text.indexOf('{')
        if (start == -1) return null
        var depth = 0
        var inString = false
        var escaped = false
        for (i in start until text.length) {
            val ch = text[i]
            if (escaped) { escaped = false; continue }
            if (ch == '\\') { escaped = true; continue }
            if (ch == '"') { inString = !inString; continue }
            if (inString) continue
            if (ch == '{') depth++
            else if (ch == '}') {
                depth--
                if (depth == 0) return text.substring(start, i + 1)
            }
        }
        return null
    }

    /** JSON.parse keeps the last of a repeated key. */
    private fun lastGet(obj: JsonValue, key: String): JsonValue? =
        (obj as? JsonValue.Obj)?.pairs?.lastOrNull { it.first == key }?.second

    private fun has(obj: JsonValue, key: String) = (obj as? JsonValue.Obj)?.pairs?.any { it.first == key } == true

    private fun isArrayish(v: JsonValue?) = v is JsonValue.Obj || v is JsonValue.Arr

    private val NUMERIC = Regex("^[ \\t\\n\\r\\u000B\\f]*[+-]?(\\d+(\\.\\d*)?|\\.\\d+)([eE][+-]?\\d+)?[ \\t\\n\\r\\u000B\\f]*$")

    /** JS's String(number). */
    private fun jsNum(d: Double): String =
        if (d % 1.0 == 0.0 && kotlin.math.abs(d) < 1e21) d.toLong().toString() else d.toString()

    private fun specStr(v: JsonValue?): String = when (v) {
        is JsonValue.Num -> jsNum(v.value)
        null -> "undefined"
        else -> v.stringValue ?: v.encoded()
    }

    /** DescriptorValidator::coerceValue(). */
    private fun coerceValue(value: JsonValue, type: String, field: String, label: String): JsonValue = when (type) {
        "int", "integer" -> when {
            value is JsonValue.Num && value.value % 1.0 == 0.0 && value.value.isFinite() -> value
            value is JsonValue.Str && value.value.matches(Regex("^-?\\d+$")) -> JsonValue.Num(value.value.toDouble())
            else -> throw Invalid("$label ($field) must be an integer.")
        }
        "float", "number" -> when {
            value is JsonValue.Num -> value
            value is JsonValue.Str && NUMERIC.matches(value.value) -> JsonValue.Num(value.value.trim().toDouble())
            else -> throw Invalid("$label ($field) must be a number.")
        }
        "bool", "boolean" -> when {
            value is JsonValue.Bool -> value
            (value is JsonValue.Num && value.value == 1.0) || (value is JsonValue.Str && (value.value == "1" || value.value == "true" || value.value == "on")) -> JsonValue.Bool(true)
            (value is JsonValue.Num && value.value == 0.0) || (value is JsonValue.Str && (value.value == "0" || value.value == "false" || value.value == "off")) -> JsonValue.Bool(false)
            else -> throw Invalid("$label ($field) must be a boolean.")
        }
        "object" -> if (isArrayish(value)) value else throw Invalid("$label ($field) must be an object.")
        else -> when (value) {
            is JsonValue.Str -> value
            is JsonValue.Num -> JsonValue.Str(jsNum(value.value))
            is JsonValue.Bool -> JsonValue.Str(if (value.value) "1" else "")
            else -> throw Invalid("$label ($field) must be a string.")
        }
    }

    private fun sameJs(a: JsonValue, b: JsonValue): Boolean = when {
        a is JsonValue.Num && b is JsonValue.Num -> a.value == b.value
        a is JsonValue.Str && b is JsonValue.Str -> a.value == b.value
        a is JsonValue.Bool && b is JsonValue.Bool -> a.value == b.value
        else -> false
    }

    /** DescriptorValidator::checkBounds(). */
    private fun checkBounds(value: JsonValue, spec: JsonValue, type: String, field: String, label: String) {
        val enum = lastGet(spec, "enum")?.arrayValue
        if (enum != null && enum.isNotEmpty() && enum.none { sameJs(it, value) }) {
            throw Invalid("$label ($field) must be one of: ${enum.joinToString(", ") { specStr(it) }}.")
        }
        if (type in setOf("int", "integer", "float", "number")) {
            val v = (value as? JsonValue.Num)?.value
            val min = lastGet(spec, "min")?.takeUnless { it.isNull }
            val max = lastGet(spec, "max")?.takeUnless { it.isNull }
            if (v != null && min?.doubleValue != null && v < min.doubleValue!!) throw Invalid("$label ($field) must be at least ${specStr(min)}.")
            if (v != null && max?.doubleValue != null && v > max.doubleValue!!) throw Invalid("$label ($field) must be at most ${specStr(max)}.")
        }
        if (type in setOf("string", "text", "password")) {
            val ml = lastGet(spec, "max_length")?.takeUnless { it.isNull }
            if (ml != null) {
                val limit = ml.intValue ?: Int.MAX_VALUE
                if (cpLen((value as? JsonValue.Str)?.value ?: "") > limit) {
                    throw Invalid("$label ($field) must be at most ${specStr(ml)} characters.")
                }
            }
        }
    }

    /** DescriptorValidator::coerce(). */
    fun coerce(descriptor: JsonValue?, input: JsonValue): JsonValue.Obj {
        val schema = descriptor?.let { lastGet(it, "input") }?.takeIf { isArrayish(it) }
        val out = ArrayList<Pair<String, JsonValue>>()
        val fields = (schema as? JsonValue.Obj)?.pairs ?: emptyList()
        for ((field, spec) in fields) {
            if (!isArrayish(spec)) continue
            val type = lastGet(spec, "type")?.stringValue ?: "string"
            val required = lastGet(spec, "required")?.let { it is JsonValue.Bool && it.value || (it is JsonValue.Num && it.value != 0.0) || (it is JsonValue.Str && it.value.isNotEmpty()) } ?: false
            val label = lastGet(spec, "label")?.stringValue ?: field
            val present = input is JsonValue.Obj && has(input, field)
            val value = if (present) lastGet(input, field) else null
            if (!present || value == null || value.isNull || (value is JsonValue.Str && value.value == "") ||
                (type == "array" && value is JsonValue.Arr && value.items.isEmpty())
            ) {
                if (required) throw Invalid("Missing required field: $label ($field).")
                if (has(spec, "default")) out.add(field to lastGet(spec, "default")!!)
                continue
            }
            if (type == "array") {
                out.add(field to coerceArray(value, spec, field, label))
                continue
            }
            val coerced = coerceValue(value, type, field, label)
            checkBounds(coerced, spec, type, field, label)
            out.add(field to coerced)
        }
        return JsonValue.Obj(out)
    }

    private fun coerceArray(value: JsonValue, spec: JsonValue, field: String, label: String): JsonValue {
        val list = (value as? JsonValue.Arr)?.items ?: throw Invalid("$label ($field) must be an array.")
        val maxItems = lastGet(spec, "max_items")?.takeUnless { it.isNull }
        if (maxItems != null && list.size > (maxItems.intValue ?: Int.MAX_VALUE)) {
            throw Invalid("$label ($field) must have at most ${specStr(maxItems)} items.")
        }
        val itemSchema = lastGet(spec, "items")?.takeIf { isArrayish(it) } ?: JsonValue.Obj(emptyList())
        val scalarType = (lastGet(itemSchema, "type") as? JsonValue.Str)?.value
        return JsonValue.Arr(list.mapIndexed { i, item ->
            if (scalarType != null) {
                val c = coerceValue(item, scalarType, "$field[$i]", "$label #$i")
                checkBounds(c, itemSchema, scalarType, "$field[$i]", "$label #$i")
                c
            } else {
                if (!isArrayish(item)) throw Invalid("$label ($field)[$i] must be an object.")
                coerce(JsonValue.obj("input" to itemSchema), item)
            }
        })
    }

    /** Each device-capable job's validateVerdict(). */
    private fun validateJob(jobId: String, verdict: JsonValue.Obj) {
        if (jobId != "email_security_scan") return
        val score = (lastGet(verdict, "score") as? JsonValue.Num)?.value ?: -1.0
        val expected = if (score >= 7) "dangerous" else if (score >= 5) "caution" else "safe"
        val word = lastGet(verdict, "verdict")?.stringValue ?: ""
        if (word != expected) {
            throw Invalid("verdict ('$word') does not match the required band for score ${jsNum(score)} ('$expected'). 0-4=safe, 5-6=caution, 7-10=dangerous.")
        }
    }

    private val THINK = Regex("<think>[\\s\\S]*?</think>")

    fun parse(text: String, descriptor: JsonValue?, jobId: String): Result {
        val stripped = trim(text.replace(THINK, ""))
        val json = extractFirstJsonObject(stripped) ?: return Result.Error("no JSON object found in the model response")
        val decoded = try { JsonValue.parse(json) } catch (e: Exception) { return Result.Error("model response was not valid JSON: Syntax error") }
        if (!isArrayish(decoded)) return Result.Error("model response was not valid JSON: Syntax error")
        return try {
            val verdict = coerce(descriptor, decoded)
            validateJob(jobId, verdict)
            Result.Verdict(verdict)
        } catch (e: Invalid) {
            Result.Error(e.message ?: "")
        }
    }

    fun retryMessage(error: String): String =
        "That response was invalid: $error\n\nRespond again with ONLY the corrected JSON object."
}
