package com.getjoinery.mail.notify

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** The new-mail high-water mark (specs/fortress_mobile_apps.md § R15). */
class NewMailCheckTest {
    private fun box(alias: Int, newest: Int?, level: String = "standard", unread: Int = 1) =
        NewMailCheck.Box(alias, "box$alias@example.test", level, newest, unread)

    @Test
    fun firstCheckOnlyRecordsMarks() {
        val r = NewMailCheck.decide(listOf(box(1, 50), box(2, 70)), setOf(1, 2), emptyMap(), firstCheck = true)
        assertTrue(r.notices.isEmpty())
        assertEquals(mapOf(1 to 50, 2 to 70), r.marks)
    }

    @Test
    fun aHigherIdNotifiesOnce() {
        val r1 = NewMailCheck.decide(listOf(box(1, 51)), setOf(1), mapOf(1 to 50), firstCheck = false)
        assertEquals(listOf(51), r1.notices.map { it.newestId })
        val r2 = NewMailCheck.decide(listOf(box(1, 51)), setOf(1), r1.marks, firstCheck = false)
        assertTrue(r2.notices.isEmpty())
    }

    @Test
    fun readingElsewhereNeverMakesNewMailLookOld() {
        // All read: no newest id; the mark stays where it was.
        val read = NewMailCheck.decide(listOf(box(1, null, unread = 0)), setOf(1), mapOf(1 to 60), firstCheck = false)
        assertTrue(read.notices.isEmpty())
        assertEquals(60, read.marks[1])
        // An old message marked unread again: below the mark, silent.
        val older = NewMailCheck.decide(listOf(box(1, 40)), setOf(1), read.marks, firstCheck = false)
        assertTrue(older.notices.isEmpty())
        // A new arrival: above it.
        val fresh = NewMailCheck.decide(listOf(box(1, 61)), setOf(1), older.marks, firstCheck = false)
        assertEquals(1, fresh.notices.size)
    }

    @Test
    fun onlyMailboxesTurnedOnNotifyAndCountTowardsTheBadge() {
        val r = NewMailCheck.decide(listOf(box(1, 80, unread = 3), box(2, 90, "fortress", unread = 4)), setOf(2), mapOf(1 to 1, 2 to 1), false)
        assertEquals(listOf(2), r.notices.map { it.aliasId })
        assertEquals("fortress", r.notices[0].level)
        assertEquals(4, r.badge)
    }
}

/** F15: a revoked key stops the check; a network failure is tried again. */
class NewMailCheckErrorTest {
    @Test
    fun aRevokedKeyStopsTheCheck() {
        assertEquals(NewMailCheck.OnError.STOP, NewMailCheck.onError(com.getjoinery.android.JoineryApiError.Authentication("revoked", 401)))
        assertEquals(NewMailCheck.OnError.STOP, NewMailCheck.onError(com.getjoinery.android.JoineryApiError.UpgradeRequired("update")))
        assertEquals(NewMailCheck.OnError.RETRY, NewMailCheck.onError(com.getjoinery.android.JoineryApiError.Network(java.io.IOException("offline"))))
        assertEquals(NewMailCheck.OnError.RETRY, NewMailCheck.onError(com.getjoinery.android.JoineryApiError.Server("ActionError", "x", 500)))
    }
}
