package com.getjoinery.mail.search

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto

/** Where the index's records live (sealed files on the phone; a map in tests). */
interface RecordIo {
    fun read(name: String): ByteArray?
    fun write(name: String, bytes: ByteArray)
    fun clear()
}

/**
 * The index's records, saved so that a save cut short (a lock, the process
 * killed) can never leave them disagreeing (F17): `ids` and `tail` each carry
 * the save's `seq`, and `meta` is written last with it; a load that finds
 * them apart starts over. A merge rewrites shards before the rest, so it is
 * journaled: `meta` says `merging` until the save after it lands, and a load
 * that finds that flag starts over too. Starting over is only ever a
 * rebuild — the mail is still on the server.
 */
class IndexRecords(private val io: RecordIo) {
    data class Meta(
        var seq: String = "",
        var docCount: Int = 0,
        var catchup: Cursor? = null,
        var backfill: Cursor? = null,
        var backfillDone: Boolean = false,
        var buildStarted: String? = null,
        var total: Int = 0,
        var merging: Boolean = false,
    )

    data class Cursor(val time: String, val id: Long)

    class Loaded(val index: SearchCore.Index, val meta: Meta)

    val shards = object : SearchCore.ShardStore {
        override suspend fun get(k: Int): ByteArray? = io.read(shardName(k))
        override suspend fun put(k: Int, bytes: ByteArray) = io.write(shardName(k), bytes)
    }

    /** The saved index, or a fresh one when there is none or it does not hold together. */
    fun load(): Loaded {
        val metaBytes = io.read("meta") ?: return fresh()
        val meta = try { parseMeta(JsonValue.parse(String(metaBytes, Charsets.UTF_8))) } catch (e: Exception) { return fresh() }
        if (meta.merging || meta.seq.isEmpty()) return fresh()
        val ids = stamped(io.read("ids"), meta.seq) ?: return fresh()
        val tail = stamped(io.read("tail"), meta.seq) ?: return fresh()
        return try {
            val index = SearchCore.Index(SearchCore.decodeIds(ids), SearchCore.shardToMap(tail))
            if (index.ids.size != meta.docCount) fresh() else Loaded(index, meta)
        } catch (e: Exception) {
            fresh()
        }
    }

    private fun fresh(): Loaded {
        io.clear()
        return Loaded(SearchCore.Index(), Meta())
    }

    /** A record's payload when it was written by the save [seq] names. */
    private fun stamped(bytes: ByteArray?, seq: String): ByteArray? {
        if (bytes == null) return null
        val tag = seq.toByteArray(Charsets.US_ASCII)
        if (bytes.size < tag.size + 1 || bytes[0].toInt() != tag.size) return null
        if (!bytes.copyOfRange(1, 1 + tag.size).contentEquals(tag)) return null
        return bytes.copyOfRange(1 + tag.size, bytes.size)
    }

    private fun stamp(seq: String, payload: ByteArray): ByteArray {
        val tag = seq.toByteArray(Charsets.US_ASCII)
        return byteArrayOf(tag.size.toByte()) + tag + payload
    }

    /** Save the index: ids and tail under a new seq, then meta naming it. */
    fun save(index: SearchCore.Index, meta: Meta) {
        meta.docCount = index.ids.size
        meta.merging = false
        val seq = VaultCrypto.toHex(VaultCrypto.randomBytes(8))
        io.write("ids", stamp(seq, SearchCore.encodeIds(index.ids)))
        io.write("tail", stamp(seq, SearchCore.mapToShard(index.tail)))
        meta.seq = seq
        io.write("meta", metaJson(meta).encodedBytes())
    }

    /** Add opened entries, merge when the tail is full (journaled), save. */
    suspend fun add(index: SearchCore.Index, meta: Meta, entries: List<Pair<Long, String>>, mergeAt: Int) {
        for ((id, text) in entries) index.add(id, text)
        if (index.tailDocs >= mergeAt) {
            meta.merging = true
            io.write("meta", metaJson(meta).encodedBytes())
            index.merge(shards)
        }
        save(index, meta)
    }

    companion object {
        fun shardName(k: Int) = "shard:%02x".format(k)

        private fun cursorJson(c: Cursor?): JsonValue =
            c?.let { JsonValue.obj("time" to JsonValue.Str(it.time), "id" to JsonValue.Num(it.id.toDouble())) } ?: JsonValue.Null

        fun metaJson(m: Meta): JsonValue = JsonValue.obj(
            "seq" to JsonValue.Str(m.seq),
            "doc_count" to JsonValue.Num(m.docCount.toDouble()),
            "catchup" to cursorJson(m.catchup),
            "backfill" to cursorJson(m.backfill),
            "backfill_done" to JsonValue.Bool(m.backfillDone),
            "build_started" to (m.buildStarted?.let { JsonValue.Str(it) } ?: JsonValue.Null),
            "total" to JsonValue.Num(m.total.toDouble()),
            "merging" to JsonValue.Bool(m.merging),
        )

        fun parseMeta(j: JsonValue): Meta {
            fun cur(x: JsonValue?) = x?.takeUnless { it.isNull }?.let { c ->
                c["time"]?.stringValue?.let { Cursor(it, c["id"]?.doubleValue?.toLong() ?: 0) }
            }
            return Meta(
                seq = j["seq"]?.stringValue ?: "",
                docCount = j["doc_count"]?.intValue ?: 0,
                catchup = cur(j["catchup"]),
                backfill = cur(j["backfill"]),
                backfillDone = j["backfill_done"]?.boolValue ?: false,
                buildStarted = j["build_started"]?.takeUnless { it.isNull }?.stringValue,
                total = j["total"]?.intValue ?: 0,
                merging = j["merging"]?.boolValue ?: false,
            )
        }
    }
}
