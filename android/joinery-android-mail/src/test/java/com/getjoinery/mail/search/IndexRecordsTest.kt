package com.getjoinery.mail.search

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** F17: a save cut short never leaves records that disagree. */
class IndexRecordsTest {
    private class MapIo : RecordIo {
        val map = LinkedHashMap<String, ByteArray>()
        /** Fail the Nth write from now (a lock or a killed process). */
        var failAfter = -1
        override fun read(name: String) = map[name]?.copyOf()
        override fun write(name: String, bytes: ByteArray) {
            if (failAfter == 0) throw IllegalStateException("died")
            if (failAfter > 0) failAfter--
            map[name] = bytes.copyOf()
        }
        override fun clear() = map.clear()
    }

    private fun msgs(from: Int, n: Int) = (from until from + n).map { it.toLong() to "word$it common shared" }

    @Test
    fun aSavedIndexReloads() = runBlocking {
        val io = MapIo()
        val r = IndexRecords(io)
        val l = r.load()
        r.add(l.index, l.meta, msgs(1, 5), mergeAt = 100)
        val back = IndexRecords(io).load()
        assertEquals(5, back.index.ids.size)
        assertEquals(listOf(3L), back.index.search(r.shards, "word3"))
    }

    @Test
    fun aSaveCutAfterIdsStartsOver() = runBlocking {
        val io = MapIo()
        val r = IndexRecords(io)
        val l = r.load()
        r.add(l.index, l.meta, msgs(1, 5), mergeAt = 100)
        val l2 = IndexRecords(io).load()
        io.failAfter = 1 // ids lands, tail does not
        try { IndexRecords(io).add(l2.index, l2.meta, msgs(6, 3), mergeAt = 100) } catch (e: IllegalStateException) {}
        io.failAfter = -1
        val back = IndexRecords(io).load()
        assertEquals("the torn save is not believed", 0, back.index.ids.size)
    }

    @Test
    fun aMergeCutShortStartsOver() = runBlocking {
        val io = MapIo()
        val r = IndexRecords(io)
        val l = r.load()
        r.add(l.index, l.meta, msgs(1, 5), mergeAt = 100)
        val l2 = IndexRecords(io).load()
        io.failAfter = 2 // the merging mark and one shard land, then it dies
        try { IndexRecords(io).add(l2.index, l2.meta, msgs(6, 5), mergeAt = 8) } catch (e: IllegalStateException) {}
        io.failAfter = -1
        val back = IndexRecords(io).load()
        assertEquals(0, back.index.ids.size)
        assertTrue(io.map.isEmpty())
    }

    @Test
    fun aCompletedMergeKeepsEverything() = runBlocking {
        val io = MapIo()
        val r = IndexRecords(io)
        val l = r.load()
        r.add(l.index, l.meta, msgs(1, 10), mergeAt = 8)
        val back = IndexRecords(io).load()
        assertEquals(10, back.index.ids.size)
        assertEquals(0, back.index.tailDocs)
        assertEquals((1L..10L).toList(), back.index.search(r.shards, "common"))
    }
}
