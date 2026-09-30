package com.getjoinery.mail

import com.getjoinery.android.JsonValue

/** Shared vectors, read in place from the web tree (the test resource roots
 *  include plugins/mailbox/tests/fixtures and tests/vault/fixtures). */
object Vectors {
    fun bytes(name: String): ByteArray =
        (Vectors::class.java.classLoader!!.getResourceAsStream(name) ?: error("vector not found: $name")).readBytes()

    fun json(name: String): JsonValue = JsonValue.parse(bytes(name).toString(Charsets.UTF_8))
}

fun JsonValue.str(key: String): String = this[key]?.takeUnless { it.isNull }?.stringValue ?: ""
