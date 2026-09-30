package com.getjoinery.mail.search

import java.text.Normalizer

/**
 * The pure half of the phone's search index over end-to-end (Fortress) mail —
 * a port of `plugins/mailbox/assets/mailbox_search_core.js`, byte for byte
 * (specs/fortress_mobile_apps.md § R5, D2). Node runs the JS against the same
 * vectors (`plugins/mailbox/tests/fixtures/search_core_vectors.json`), so the
 * browser, iOS and Android build the same shards from the same mail.
 *
 * A word maps to the messages that contain it, and nothing else. Messages are
 * numbered 0, 1, 2… in the order this phone indexed them ("docs"); a word's
 * docs are stored as LEB128 gaps, or a bitmap when that is smaller. Words live
 * in [SHARDS] shards by FNV-1a of their UTF-8 bytes; new messages go to a tail
 * until it is merged. The tokenizer mirrors SQLite FTS5 unicode61 with
 * remove_diacritics: NFKD, combining marks dropped, lower case, split on
 * anything that is not a letter or a number.
 */
object SearchCore {
    const val FORMAT = 1
    const val MAX_WORD = 40
    const val SHARDS = 256
    const val LIST = 0
    const val BITMAP = 1

    private val SPLIT = Regex("[^\\p{L}\\p{N}]+")
    private val MARKS = Regex("\\p{M}+")

    // MARK: Words

    private fun tokens(text: String): List<String> =
        Normalizer.normalize(text, Normalizer.Form.NFKD).replace(MARKS, "").lowercase(java.util.Locale.ROOT).split(SPLIT)

    /** The distinct words of a message worth indexing. */
    fun words(text: String): LinkedHashSet<String> {
        val out = LinkedHashSet<String>()
        for (w in tokens(text)) if (w.isNotEmpty() && w.length <= MAX_WORD) out.add(w)
        return out
    }

    /** A query's distinct words, long ones kept (they match nothing). */
    fun queryWords(q: String): List<String> {
        val out = ArrayList<String>()
        val seen = HashSet<String>()
        for (w in tokens(q)) if (w.isNotEmpty() && seen.add(w)) out.add(w)
        return out
    }

    fun fnv1a(bytes: ByteArray): Long {
        var h = 0x811c9dc5L
        for (b in bytes) {
            h = h xor (b.toLong() and 0xFF)
            h = (h * 0x01000193L) and 0xFFFFFFFFL
        }
        return h
    }

    fun shardOf(wordBytes: ByteArray): Int = (fnv1a(wordBytes) % SHARDS).toInt()

    fun compareBytes(a: ByteArray, b: ByteArray, aLen: Int = a.size, bLen: Int = b.size): Int {
        val n = minOf(aLen, bLen)
        for (i in 0 until n) {
            val x = a[i].toInt() and 0xFF
            val y = b[i].toInt() and 0xFF
            if (x != y) return x - y
        }
        return aLen - bLen
    }

    fun utf8(s: String): ByteArray = s.toByteArray(Charsets.UTF_8)

    // MARK: Bytes

    class Out(size: Int = 1024) {
        var buf = ByteArray(maxOf(size, 8))
        var n = 0
        private fun room(k: Int) {
            if (n + k <= buf.size) return
            buf = buf.copyOf(maxOf(buf.size * 2, n + k))
        }
        fun byte(b: Int) { room(1); buf[n++] = b.toByte() }
        fun varint(value: Long) {
            room(10)
            var v = value
            while (v >= 128) { buf[n++] = ((v % 128) + 128).toByte(); v /= 128 }
            buf[n++] = v.toByte()
        }
        fun varint(v: Int) = varint(v.toLong())
        fun bytes(b: ByteArray, from: Int = 0, to: Int = b.size) {
            room(to - from); System.arraycopy(b, from, buf, n, to - from); n += to - from
        }
        fun done(): ByteArray = buf.copyOf(n)
    }

    class CutShortException : Exception("A search index record is cut short.")

    class In(val buf: ByteArray, var p: Int = 0, val end: Int = buf.size) {
        fun varint(): Long {
            var v = 0L
            var mul = 1L
            var b: Int
            do {
                if (p >= end) throw CutShortException()
                b = buf[p++].toInt() and 0xFF
                v += (b and 127) * mul
                mul *= 128
            } while (b and 128 != 0)
            return v
        }
        fun take(k: Int): IntRange {
            if (p + k > end) throw CutShortException()
            val r = p until p + k
            p += k
            return r
        }
    }

    // MARK: One word's docs

    /** A posting: kind [LIST] or [BITMAP] and its bytes. */
    class Posting(val kind: Int, val bytes: ByteArray) {
        override fun equals(other: Any?) = other is Posting && other.kind == kind && other.bytes.contentEquals(bytes)
        override fun hashCode() = kind * 31 + bytes.contentHashCode()
    }

    private fun bitmapLength(last: Int) = (last shr 3) + 1

    private fun encodeList(docs: List<Int>, prevIn: Int, out: Out) {
        var prev = prevIn
        for (d in docs) {
            out.varint(if (prev < 0) d else d - prev)
            prev = d
        }
    }

    private fun listBytes(docs: List<Int>): ByteArray {
        val out = Out(docs.size + 8)
        encodeList(docs, -1, out)
        return out.done()
    }

    private fun bitmapBytes(docs: List<Int>, last: Int): ByteArray {
        val b = ByteArray(bitmapLength(last))
        for (d in docs) b[d shr 3] = (b[d shr 3].toInt() or (1 shl (d and 7))).toByte()
        return b
    }

    /** The smaller encoding of an ascending doc list; a tie goes to the list. */
    fun posting(docs: List<Int>): Posting {
        val last = docs.last()
        val list = listBytes(docs)
        if (bitmapLength(last) < list.size) return Posting(BITMAP, bitmapBytes(docs, last))
        return Posting(LIST, list)
    }

    fun docsOf(p: Posting): List<Int> {
        val out = ArrayList<Int>()
        val b = p.bytes
        if (p.kind == LIST) {
            val r = In(b)
            var d = -1
            while (r.p < b.size) {
                d = if (d < 0) r.varint().toInt() else d + r.varint().toInt()
                out.add(d)
            }
            return out
        }
        for (byte in b.indices) {
            val v = b[byte].toInt() and 0xFF
            if (v == 0) continue
            for (bit in 0 until 8) if (v and (1 shl bit) != 0) out.add((byte shl 3) + bit)
        }
        return out
    }

    fun countOf(p: Posting): Int {
        var n = 0
        if (p.kind == LIST) {
            for (x in p.bytes) if ((x.toInt() and 0xFF) < 128) n++
            return n
        }
        for (x in p.bytes) n += Integer.bitCount(x.toInt() and 0xFF)
        return n
    }

    fun lastOf(p: Posting): Int {
        if (p.kind == BITMAP) {
            val b = p.bytes
            for (byte in b.indices.reversed()) {
                val v = b[byte].toInt() and 0xFF
                if (v != 0) return (byte shl 3) + (31 - Integer.numberOfLeadingZeros(v))
            }
            return -1
        }
        val r = In(p.bytes)
        var d = -1
        while (r.p < p.bytes.size) d = if (d < 0) r.varint().toInt() else d + r.varint().toInt()
        return d
    }

    /** [p] with more docs appended (each past p's last, ascending), choosing
     *  exactly as [posting] would, so a merge equals a build. */
    fun extend(p: Posting?, more: List<Int>): Posting {
        if (p == null) return posting(more)
        val last = more.last()
        if (p.kind == LIST) {
            val out = Out(p.bytes.size + more.size + 8)
            out.bytes(p.bytes)
            encodeList(more, lastOf(p), out)
            val list = out.done()
            if (bitmapLength(last) < list.size) return Posting(BITMAP, bitmapBytes(docsOf(p) + more, last))
            return Posting(LIST, list)
        }
        val b = p.bytes.copyOf(bitmapLength(last))
        for (d in more) b[d shr 3] = (b[d shr 3].toInt() or (1 shl (d and 7))).toByte()
        // Gone sparse (a word common early and rare since): a list may be smaller now.
        if (countOf(p) + more.size <= b.size) {
            val asList = listBytes(docsOf(p) + more)
            if (asList.size <= b.size) return Posting(LIST, asList)
        }
        return Posting(BITMAP, b)
    }

    // MARK: Shards

    private fun shared(a: ByteArray, aLen: Int, b: ByteArray): Int {
        val n = minOf(aLen, b.size)
        var i = 0
        while (i < n && a[i] == b[i]) i++
        return i
    }

    class Writer {
        private val out = Out(1024)
        private var prev = ByteArray(0)
        private var words = 0
        fun entry(word: ByteArray, wordLen: Int, p: Posting) {
            val w = if (wordLen == word.size) word else word.copyOf(wordLen)
            val k = shared(prev, prev.size, w)
            out.varint(k)
            out.varint(w.size - k)
            out.bytes(w, k, w.size)
            out.varint(p.bytes.size.toLong() * 2 + p.kind)
            out.bytes(p.bytes)
            prev = w.copyOf()
            words++
        }
        fun done(): ByteArray {
            val head = Out(8)
            head.varint(words)
            return head.done() + out.done()
        }
    }

    /** Reads a shard's entries in order. [word] is valid until the next call. */
    class Reader(shard: ByteArray) {
        private val r = In(shard)
        private var left = r.varint()
        var word = ByteArray(256)
        var wordLen = 0
        var posting: Posting? = null

        fun next(): Boolean {
            if (left <= 0) return false
            left--
            val k = r.varint().toInt()
            val restLen = r.varint().toInt()
            val rest = r.take(restLen)
            if (k + restLen > word.size) word = word.copyOf(k + restLen)
            System.arraycopy(r.buf, rest.first, word, k, restLen)
            wordLen = k + restLen
            val lk = r.varint()
            val range = r.take((lk / 2).toInt())
            posting = Posting((lk % 2).toInt(), r.buf.copyOfRange(range.first, range.first + range.count()))
            return true
        }
    }

    class Add(val word: ByteArray, val docs: List<Int>)

    /** A shard with [adds] merged in (sorted by word bytes; every doc past
     *  anything the shard holds for the word). */
    fun mergeShard(shard: ByteArray?, adds: List<Add>): ByteArray {
        val w = Writer()
        val rd = shard?.let { Reader(it) }
        var have = rd?.next() ?: false
        var i = 0
        while (have || i < adds.size) {
            val c = when {
                !have -> 1
                i >= adds.size -> -1
                else -> compareBytes(rd!!.word, adds[i].word, rd.wordLen, adds[i].word.size)
            }
            when {
                c < 0 -> w.entry(rd!!.word, rd.wordLen, rd.posting!!)
                c > 0 -> { w.entry(adds[i].word, adds[i].word.size, posting(adds[i].docs)); i++ }
                else -> { w.entry(rd!!.word, rd.wordLen, extend(rd.posting, adds[i].docs)); i++ }
            }
            if (c <= 0) have = rd!!.next()
        }
        return w.done()
    }

    fun findWord(shard: ByteArray?, word: ByteArray): Posting? {
        if (shard == null) return null
        val rd = Reader(shard)
        while (rd.next()) {
            val c = compareBytes(rd.word, word, rd.wordLen, word.size)
            if (c == 0) return rd.posting
            if (c > 0) return null
        }
        return null
    }

    fun shardToMap(shard: ByteArray?): LinkedHashMap<String, MutableList<Int>> {
        val m = LinkedHashMap<String, MutableList<Int>>()
        if (shard == null) return m
        val rd = Reader(shard)
        while (rd.next()) m[String(rd.word, 0, rd.wordLen, Charsets.UTF_8)] = docsOf(rd.posting!!).toMutableList()
        return m
    }

    fun sortedAdds(map: Map<String, List<Int>>): List<Add> =
        map.map { (w, d) -> Add(utf8(w), d) }.sortedWith { a, b -> compareBytes(a.word, b.word) }

    fun mapToShard(map: Map<String, List<Int>>): ByteArray = mergeShard(null, sortedAdds(map))

    // MARK: Ids

    /** Doc order is not id order, so each id is its signed difference from
     *  the one before, zigzagged. */
    fun encodeIds(ids: List<Long>): ByteArray {
        val out = Out(ids.size * 2 + 8)
        out.varint(ids.size)
        var prev = 0L
        for (id in ids) {
            val d = id - prev
            out.varint(if (d >= 0) d * 2 else -d * 2 - 1)
            prev = id
        }
        return out.done()
    }

    fun decodeIds(bytes: ByteArray): MutableList<Long> {
        val r = In(bytes)
        val n = r.varint().toInt()
        val ids = ArrayList<Long>(n)
        var prev = 0L
        repeat(n) {
            val z = r.varint()
            prev += if (z % 2 == 0L) z / 2 else -(z + 1) / 2
            ids.add(prev)
        }
        return ids
    }

    // MARK: The index

    interface ShardStore {
        suspend fun get(k: Int): ByteArray?
        suspend fun put(k: Int, bytes: ByteArray)
    }

    /** The in-memory half of an index: ids and the tail. Shards live in a store. */
    class Index(ids: List<Long> = emptyList(), tail: Map<String, List<Int>> = emptyMap()) {
        val ids: MutableList<Long> = ids.toMutableList()
        private val known = HashSet<Long>(ids)
        var tail: LinkedHashMap<String, MutableList<Int>> = LinkedHashMap<String, MutableList<Int>>().apply {
            tail.forEach { (k, v) -> put(k, v.toMutableList()) }
        }
            private set
        var tailDocs: Int
            private set

        init {
            var from = this.ids.size
            this.tail.values.forEach { d -> if (d.isNotEmpty() && d[0] < from) from = d[0] }
            tailDocs = this.ids.size - from
        }

        fun has(id: Long) = known.contains(id)

        /** Add one message; false when it is already indexed. */
        fun add(id: Long, text: String): Boolean {
            if (!known.add(id)) return false
            val doc = ids.size
            ids.add(id)
            for (w in words(text)) tail.getOrPut(w) { ArrayList() }.add(doc)
            tailDocs++
            return true
        }

        /** Merge the tail into the shards it touches; returns their keys. */
        suspend fun merge(store: ShardStore): List<Int> {
            val groups = java.util.TreeMap<Int, MutableList<Add>>()
            for (a in sortedAdds(tail)) groups.getOrPut(shardOf(a.word)) { ArrayList() }.add(a)
            for ((k, g) in groups) store.put(k, mergeShard(store.get(k), g))
            tail = LinkedHashMap()
            tailDocs = 0
            return groups.keys.toList()
        }

        /** The message ids holding every word of [q], ascending. */
        suspend fun search(store: ShardStore, q: String): List<Long> {
            val qw = queryWords(q)
            if (qw.isEmpty()) return emptyList()
            val postings = ArrayList<Posting?>()
            val tails = ArrayList<List<Int>?>()
            for (w in qw) {
                if (w.length > MAX_WORD) return emptyList()
                val wb = utf8(w)
                postings.add(findWord(store.get(shardOf(wb)), wb))
                tails.add(tail[w])
            }
            val docs = intersectPostings(postings) + intersectLists(tails)
            return docs.map { ids[it] }.sorted()
        }
    }

    private fun intersectPostings(ps: List<Posting?>): List<Int> {
        val lists = ArrayList<Posting>()
        val maps = ArrayList<Posting>()
        for (p in ps) {
            if (p == null) return emptyList()
            (if (p.kind == BITMAP) maps else lists).add(p)
        }
        var and: ByteArray? = null
        if (maps.isNotEmpty()) {
            val len = maps.minOf { it.bytes.size }
            val a = maps[0].bytes.copyOf(len)
            for (j in 1 until maps.size) {
                val b = maps[j].bytes
                for (k in 0 until len) a[k] = (a[k].toInt() and b[k].toInt()).toByte()
            }
            and = a
        }
        if (lists.isEmpty()) return docsOf(Posting(BITMAP, and!!))
        lists.sortBy { it.bytes.size }
        var docs = docsOf(lists[0])
        if (and != null) docs = docs.filter { (it shr 3) < and.size && (and[it shr 3].toInt() and (1 shl (it and 7))) != 0 }
        var n = 1
        while (n < lists.size && docs.isNotEmpty()) {
            val other = HashSet(docsOf(lists[n]))
            docs = docs.filter { other.contains(it) }
            n++
        }
        return docs
    }

    private fun intersectLists(ls: List<List<Int>?>): List<Int> {
        if (ls.any { it == null }) return emptyList()
        val sorted = ls.map { it!! }.sortedBy { it.size }
        var docs = sorted[0].toList()
        var n = 1
        while (n < sorted.size && docs.isNotEmpty()) {
            val other = HashSet(sorted[n])
            docs = docs.filter { other.contains(it) }
            n++
        }
        return docs
    }
}
