package com.getjoinery.android

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class JsonValueTest {

    @Test
    fun parsesScalarsAndAccessors() {
        val v = JsonValue.parse("""{"s":"hi","n":42,"f":3.5,"b":true,"z":null}""")
        assertEquals("hi", v["s"]?.stringValue)
        assertEquals(42, v["n"]?.intValue)
        assertEquals("42", v["n"]?.stringValue) // integral number stringifies without ".0"
        assertEquals(3.5, v["f"]?.doubleValue!!, 0.0001)
        assertEquals(true, v["b"]?.boolValue)
        assertTrue(v["z"]!!.isNull)
        assertNull(v["missing"])
    }

    @Test
    fun preservesObjectKeyOrder() {
        val v = JsonValue.parse("""{"c":1,"a":2,"b":3}""")
        assertEquals(listOf("c", "a", "b"), v.objectValue!!.map { it.first })
    }

    @Test
    fun emptyArrayReadsAsEmptyObject() {
        // PHP serializes an empty `data` as [] — objectValue must tolerate it.
        val v = JsonValue.parse("""{"data":[]}""")
        assertEquals(emptyList<Pair<String, JsonValue>>(), v["data"]?.objectValue)
    }

    @Test
    fun decodesEscapesAndUnicode() {
        val v = JsonValue.parse(""""line\nbreak A 😀"""")
        assertEquals("line\nbreak A 😀", v.stringValue)
    }

    @Test
    fun encodesObjectInOrderAndEscapes() {
        val obj = JsonValue.obj(
            "a" to JsonValue.Str("x\"y"),
            "b" to JsonValue.Num(7.0),
            "c" to JsonValue.Arr(listOf(JsonValue.Bool(false), JsonValue.Null)),
        )
        assertEquals("""{"a":"x\"y","b":7,"c":[false,null]}""", obj.encoded())
    }

    @Test
    fun boolCoercions() {
        assertEquals(true, JsonValue.Str("1").boolValue)
        assertEquals(false, JsonValue.Str("0").boolValue)
        assertEquals(true, JsonValue.Str("true").boolValue)
        assertEquals(false, JsonValue.Str("").boolValue)
        assertNull(JsonValue.Str("maybe").boolValue)
    }
}
