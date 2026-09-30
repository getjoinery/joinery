package com.getjoinery.mail.fortress

import com.getjoinery.mail.Vectors
import org.junit.Assert.assertEquals
import org.junit.Test

/** The mail-rule matcher against the ids the PHP matcher itself accepted
 *  (plugins/mailbox/tests/fixtures/filter_match_cases.json). */
class FilterMatchTest {
    @Test
    fun cases() {
        val cases = Vectors.json("filter_match_cases.json")["cases"]!!.arrayValue!!
        for (c in cases) {
            val want = c["expected_ids"]!!.arrayValue!!.map { it.doubleValue!!.toLong() }
            val got = FilterMatch.matchingIds(c["rules"]!!.arrayValue!!, c["message"]!!)
            assertEquals(c["name"]!!.stringValue, want, got)
        }
    }
}
