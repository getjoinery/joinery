package com.getjoinery.android

/** Loads a captured API JSON fixture from `src/test/resources/fixtures`. The
 *  same fixtures back the iOS JoineryKit tests — parity by construction. */
fun fixture(name: String): String {
    val stream = FixtureAnchor::class.java.classLoader!!.getResourceAsStream("fixtures/$name")
        ?: error("fixture not found: fixtures/$name")
    return stream.readBytes().toString(Charsets.UTF_8)
}

private class FixtureAnchor
