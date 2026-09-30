package com.getjoinery.mail.search

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.DeviceHits
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test

/** The Kotlin port of mailbox_search_core.js against the vectors node writes
 *  from the JS itself (plugins/mailbox/tests/fixtures/search_core_vectors.json). */
class SearchCoreTest {

    private val v: JsonValue by lazy {
        val stream = SearchCoreTest::class.java.classLoader!!.getResourceAsStream("search_core_vectors.json")
            ?: error("search_core_vectors.json not on the test classpath")
        JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
    }

    private fun JsonValue.strs(key: String) = this[key]!!.arrayValue!!.map { it.stringValue!! }
    private fun JsonValue.ints(key: String) = this[key]!!.arrayValue!!.map { it.intValue!! }
    private fun JsonValue.longs(key: String) = this[key]!!.arrayValue!!.map { it.doubleValue!!.toLong() }
    private fun hex(b: ByteArray) = VaultCrypto.toHex(b)

    private class MapStore : SearchCore.ShardStore {
        val shards = java.util.TreeMap<Int, ByteArray>()
        override suspend fun get(k: Int) = shards[k]
        override suspend fun put(k: Int, bytes: ByteArray) { shards[k] = bytes }
    }

    @Test
    fun constants() {
        assertEquals(v["format"]!!.intValue, SearchCore.FORMAT)
        assertEquals(v["max_word"]!!.intValue, SearchCore.MAX_WORD)
        assertEquals(v["shards_count"]!!.intValue, SearchCore.SHARDS)
    }

    @Test
    fun tokenizer() {
        v["tokenizer"]!!.arrayValue!!.forEach { c ->
            val text = c["text"]!!.stringValue!!
            assertEquals("words($text)", c.strs("words"), SearchCore.words(text).toList())
            assertEquals("queryWords($text)", c.strs("query_words"), SearchCore.queryWords(text))
        }
    }

    @Test
    fun fnv1aAndShards() {
        v["fnv1a"]!!.arrayValue!!.forEach { c ->
            val bytes = VaultCrypto.hex(c["utf8_hex"]!!.stringValue!!)
            assertEquals(c["word"]!!.stringValue, hex(SearchCore.utf8(c["word"]!!.stringValue!!)), c["utf8_hex"]!!.stringValue)
            assertEquals(c["fnv1a"]!!.doubleValue!!.toLong(), SearchCore.fnv1a(bytes))
            assertEquals(c["shard"]!!.intValue, SearchCore.shardOf(bytes))
        }
    }

    @Test
    fun postings() {
        v["postings"]!!.arrayValue!!.forEach { c ->
            val p = SearchCore.posting(c.ints("docs"))
            assertEquals(c["kind_bit"]!!.intValue, p.kind)
            assertEquals(c["bytes_hex"]!!.stringValue, hex(p.bytes))
            assertEquals(c["count"]!!.intValue, SearchCore.countOf(p))
            assertEquals(c["last"]!!.intValue, SearchCore.lastOf(p))
            assertEquals(c.ints("docs"), SearchCore.docsOf(p))
        }
    }

    @Test
    fun extend() {
        v["extend"]!!.arrayValue!!.forEach { c ->
            val p = SearchCore.extend(SearchCore.posting(c.ints("docs")), c.ints("more"))
            assertEquals(c["kind_bit"]!!.intValue, p.kind)
            assertEquals(c["bytes_hex"]!!.stringValue, hex(p.bytes))
            assertEquals(c.ints("docs_after"), SearchCore.docsOf(p))
        }
    }

    @Test
    fun ids() {
        v["ids"]!!.arrayValue!!.forEach { c ->
            val ids = c.longs("ids")
            assertEquals(c["encoded_hex"]!!.stringValue, hex(SearchCore.encodeIds(ids)))
            assertEquals(ids, SearchCore.decodeIds(VaultCrypto.hex(c["encoded_hex"]!!.stringValue!!)))
        }
        val pack = v["pack_ids"]!!
        assertEquals(pack["packed"]!!.stringValue, DeviceHits.pack(pack.longs("ids")))
        assertEquals(pack.longs("ids"), DeviceHits.unpack(pack["packed"]!!.stringValue!!))
    }

    private fun messages() = v["build"]!!["messages"]!!.arrayValue!!.map {
        it["id"]!!.doubleValue!!.toLong() to it["text"]!!.stringValue!!
    }

    private fun assertShards(expected: JsonValue, store: MapStore) {
        val want = expected.objectValue!!.associate { it.first.toInt() to it.second.stringValue!! }
        assertEquals(want.keys.sorted(), store.shards.keys.toList())
        want.forEach { (k, h) -> assertEquals("shard $k", h, hex(store.shards[k]!!)) }
    }

    @Test
    fun buildFromScratch() = runBlocking {
        val build = v["build"]!!
        val index = SearchCore.Index()
        messages().forEach { (id, text) -> index.add(id, text) }
        assertEquals(build.longs("doc_ids"), index.ids)
        val store = MapStore()
        val keys = index.merge(store)
        assertEquals(build.ints("merged_shard_keys"), keys)
        assertShards(build["shards"]!!, store)
        assertEquals(build["ids_hex"]!!.stringValue, hex(SearchCore.encodeIds(index.ids)))
    }

    @Test
    fun scheduledMergesEqualTheBuildAndQueriesMatch() = runBlocking {
        val sched = v["schedule"]!!
        val batches = sched.ints("batches")
        val msgs = messages()
        val index = SearchCore.Index()
        val store = MapStore()
        var at = 0
        batches.forEachIndexed { i, n ->
            msgs.subList(at, at + n).forEach { (id, text) -> index.add(id, text) }
            at += n
            if (i < batches.size - 1) index.merge(store)
        }
        val before = sched["before_final_merge"]!!
        assertShards(before["shards"]!!, store)
        assertEquals(before["tail_hex"]!!.stringValue, hex(SearchCore.mapToShard(index.tail)))
        assertEquals(before["tail_docs"]!!.intValue, index.tailDocs)
        assertEquals(before["ids_hex"]!!.stringValue, hex(SearchCore.encodeIds(index.ids)))

        // A reopened index (ids + tail from their records) finds the same.
        val reopened = SearchCore.Index(SearchCore.decodeIds(SearchCore.encodeIds(index.ids)), SearchCore.shardToMap(SearchCore.mapToShard(index.tail)))
        assertEquals(index.tailDocs, reopened.tailDocs)

        v["queries"]!!.arrayValue!!.forEach { q ->
            val text = q["q"]!!.stringValue!!
            val got = index.search(store, text)
            assertEquals("query $text", q.longs("ids"), got)
            assertEquals("packed $text", q["packed"]!!.stringValue, DeviceHits.pack(got))
            assertEquals("reopened $text", got, reopened.search(store, text))
        }

        index.merge(store)
        assertShards(v["build"]!!["shards"]!!, store)
    }
}
