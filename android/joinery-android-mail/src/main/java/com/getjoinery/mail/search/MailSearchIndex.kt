package com.getjoinery.mail.search

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.ApiClient
import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.fortress.FortressMail
import com.getjoinery.mail.fortress.FortressOpener
import com.getjoinery.mail.fortress.SealedRow
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import com.getjoinery.mail.search.IndexRecords.Cursor
import com.getjoinery.mail.search.IndexRecords.Meta
import java.io.File

/**
 * The phone's own search index over end-to-end (Fortress) mail
 * (specs/fortress_mobile_apps.md § R5): the server cannot search what it
 * cannot read, so the phone pages the sealed search texts from
 * `mailbox/search_entries`, opens them with the mail key, and builds the
 * browser's index ([SearchCore]) — the same records, sealed the same way
 * (gzip, then AES-256-GCM under a record key derived from the mail secret,
 * HKDF info `joinery-mailsearch:record:v1`, AD
 * `mailsearch:{user_id}:{record}:{format}`), in the app's no-backup files.
 * Nothing about the key is stored: a sign-out wipes the secret and leaves the
 * sealed index, which reopens after the next enrollment. A search sends the
 * hits as `device_hits`.
 *
 * The first build walks newest first, in the background while the mailbox is
 * open ("Indexing mail on this phone: 42,000 of 105,000"); each search first
 * catches up with mail written since. Rebuild and Remove sit in the mailbox
 * menu. Nothing runs while the mail key is locked.
 */
class MailSearchIndex private constructor(
    private val context: Context,
    private val client: ApiClient,
    private val fortress: FortressMail,
    private val userId: Int,
) {
    private val lockListener: () -> Unit = { onLock() }

    /** What the menu and the progress line show. */
    var status by mutableStateOf<String?>(null)
        private set
    var building by mutableStateOf(false)
        private set

    private val mutex = Mutex()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var buildJob: Job? = null
    private var index: SearchCore.Index? = null
    private var meta: Meta? = null

    private val dir: File get() = File(context.noBackupFilesDir, "joinery_mailsearch/$userId")

    // MARK: Records (gzip, then sealed)

    private fun recordKey(): ByteArray = fortress.keys.deriveFrom(FortressOpener.SCOPE, RECORD_KEY_INFO)

    private fun ad(record: String) = "mailsearch:$userId:$record:${SearchCore.FORMAT}"

    private fun fileOf(record: String) = File(dir, record.replace(':', '_'))

    private fun write(record: String, bytes: ByteArray) {
        dir.mkdirs()
        val key = recordKey()
        try {
            val sealed = VaultCrypto.b64decode(VaultCrypto.encrypt(VaultCrypto.gzip(bytes), key, ad(record)))
            val tmp = File(dir, record.replace(':', '_') + ".tmp")
            tmp.writeBytes(sealed)
            tmp.renameTo(fileOf(record))
        } finally {
            key.fill(0)
        }
    }

    private fun read(record: String): ByteArray? {
        val f = fileOf(record)
        if (!f.exists()) return null
        val key = recordKey()
        try {
            return VaultCrypto.gunzip(VaultCrypto.decryptRaw(f.readBytes(), key, ad(record)))
        } finally {
            key.fill(0)
        }
    }

    private val records = IndexRecords(object : RecordIo {
        override fun read(name: String): ByteArray? = this@MailSearchIndex.read(name)
        override fun write(name: String, bytes: ByteArray) = this@MailSearchIndex.write(name, bytes)
        override fun clear() {
            (dir.listFiles() ?: emptyArray()).filter { it.name != "head" }.forEach { it.delete() }
        }
    })
    private val store get() = records.shards

    // MARK: Opening the saved index

    private fun fingerprint(): String {
        val key = recordKey()
        try { return VaultCrypto.toHex(VaultCrypto.sha256(key)).take(16) } finally { key.fill(0) }
    }

    /** Load (or start) the index. A different format or key clears it. */
    private fun loadLocked() {
        if (index != null && meta != null) return
        val head = File(dir, "head").takeIf { it.exists() }?.readText()?.let { try { JsonValue.parse(it) } catch (e: Exception) { null } }
        val fp = fingerprint()
        if (head == null || head["format"]?.intValue != SearchCore.FORMAT || head["fp"]?.stringValue != fp) {
            dir.deleteRecursively()
            dir.mkdirs()
            File(dir, "head").writeText(JsonValue.obj("format" to JsonValue.Num(SearchCore.FORMAT.toDouble()), "fp" to JsonValue.Str(fp)).encoded())
            index = SearchCore.Index()
            meta = Meta()
            return
        }
        try {
            val loaded = records.load()
            index = loaded.index
            meta = loaded.meta
        } catch (e: Exception) {
            // A record that will not open (a rotated key, a torn write): start over.
            dir.deleteRecursively()
            index = null
            meta = null
            loadLocked()
        }
    }

    /** Add opened entries, move cursors forward, merge when the tail is full,
     *  save (atomically, IndexRecords). */
    private suspend fun addLocked(entries: List<Pair<Long, String>>, cursor: Meta.() -> Unit) {
        val idx = index ?: return
        val m = meta ?: return
        m.cursor()
        records.add(idx, m, entries, MERGE_AT)
    }

    // MARK: Reading the mail

    private suspend fun page(args: List<Pair<String, JsonValue>>): JsonValue {
        val envelope = client.submitAction("mailbox/search_entries", JsonValue.Obj(args))
        return envelope["data"] ?: JsonValue.Obj(emptyList())
    }

    /** Open a page's search texts. A row that will not open is left out; a
     *  page where none opens stops the walk (a key that changed under it). */
    private fun openEntries(entries: List<JsonValue>): List<Pair<Long, String>> {
        var tried = 0
        val out = ArrayList<Pair<Long, String>>()
        for (e in entries) {
            val id = e["id"]?.doubleValue?.toLong() ?: continue
            val row = e["sealed"]?.let { SealedRow.from(it) } ?: continue
            if (row.fields["iem_search_text"] == null) continue
            tried++
            try {
                val text = fortress.opener.openFields(row, setOf("iem_search_text"))["iem_search_text"] ?: continue
                out.add(id to inflate(text))
            } catch (x: Exception) {
                // Left out; not asked for again.
            }
        }
        if (tried > 0 && out.isEmpty()) throw IllegalStateException("Mail could not be opened for search on this phone.")
        return out
    }

    private fun cursorOf(j: JsonValue?): Cursor? {
        if (j == null || j.isNull) return null
        val time = j["time"]?.stringValue ?: return null
        return Cursor(time, j["id"]?.doubleValue?.toLong() ?: 0L)
    }

    private fun cursorArgs(c: Cursor) = listOf("time" to JsonValue.Str(c.time), "id" to JsonValue.Num(c.id.toDouble()))

    /** New mail since this phone last looked; the first page reaches back ten minutes. */
    private suspend fun catchUpLocked() {
        val m = meta ?: return
        val started = m.buildStarted ?: return
        var cur = m.catchup ?: Cursor(started, 0)
        var batch = ArrayList<Pair<Long, String>>()
        var last: Cursor? = null
        var first = true
        while (fortress.isOpen) {
            val args = ArrayList<Pair<String, JsonValue>>()
            args.add("order" to JsonValue.Str("new"))
            args.addAll(cursorArgs(cur))
            args.add("overlap" to JsonValue.Bool(first))
            first = false
            val p = page(args)
            batch.addAll(openEntries(p["entries"]?.arrayValue ?: emptyList()))
            cursorOf(p["last"])?.let { last = it }
            val next = cursorOf(p["next"])
            if (next == null || batch.size >= CATCHUP_BATCH) {
                val l = last
                if (batch.isNotEmpty() || l != null) addLocked(batch) { if (l != null) catchup = l }
                batch = ArrayList()
            }
            if (next == null) break
            cur = next
        }
    }

    /** The first build: newest first. */
    private suspend fun backfillOnce(): Boolean = mutex.withLock {
        loadLocked()
        val m = meta ?: return@withLock false
        if (m.backfillDone || !fortress.isOpen) return@withLock false
        val args = ArrayList<Pair<String, JsonValue>>()
        args.add("order" to JsonValue.Str("old"))
        args.add("with_total" to JsonValue.Bool(m.total == 0))
        m.backfill?.let { args.addAll(cursorArgs(it)) }
        val p = page(args)
        p["total"]?.intValue?.let { m.total = it }
        if (m.buildStarted == null) {
            // Mail written from here on is the catch-up's.
            val now = p["server_time"]?.stringValue ?: ""
            m.buildStarted = now
            m.catchup = Cursor(now, 0)
        }
        val opened = openEntries(p["entries"]?.arrayValue ?: emptyList())
        val next = cursorOf(p["next"])
        addLocked(opened) {
            if (next != null) backfill = next else backfillDone = true
        }
        updateStatus()
        next != null
    }

    private fun updateStatus() {
        val m = meta ?: return
        status = if (!m.backfillDone && m.total > 0) {
            "Indexing mail on this phone: %,d of %,d".format(m.docCount, m.total)
        } else null
    }

    /** Start (or continue) the first build in the background while the key is open. */
    fun ensureBuilding() {
        if (buildJob?.isActive == true || !fortress.isOpen) return
        buildJob = scope.launch {
            building = true
            try {
                while (fortress.isOpen && backfillOnce()) { /* next page */ }
            } catch (e: Exception) {
                status = "Search on this phone stopped: ${e.message ?: "unknown error"}"
            } finally {
                building = false
                updateStatusSafe()
            }
        }
    }

    private suspend fun updateStatusSafe() = mutex.withLock { updateStatus() }

    // MARK: What the mailbox calls

    /** The message ids matching [q], or null when the phone cannot search now. */
    suspend fun query(q: String): List<Int>? {
        if (!fortress.isOpen || q.isBlank()) return null
        return try {
            val hits = withContext(Dispatchers.IO) {
                mutex.withLock {
                    loadLocked()
                    catchUpLocked()
                    index!!.search(store, q)
                }
            }
            ensureBuilding()
            hits.filter { it <= Int.MAX_VALUE }.map { it.toInt() }
        } catch (e: Exception) {
            status = "Search on this phone stopped: ${e.message ?: "unknown error"}"
            null
        }
    }

    /** Throw the index away and build it again. */
    suspend fun rebuild() {
        buildJob?.cancel()
        withContext(Dispatchers.IO) {
            mutex.withLock {
                dir.deleteRecursively()
                index = null
                meta = null
                loadLocked()
            }
        }
        ensureBuilding()
    }

    /** Remove the index from this phone (it rebuilds at the next search). */
    suspend fun remove() {
        buildJob?.cancel()
        withContext(Dispatchers.IO) {
            mutex.withLock {
                dir.deleteRecursively()
                index = null
                meta = null
            }
        }
        status = null
    }

    /** Drop the in-memory index when the key locks (the records stay sealed). */
    private fun onLock() {
        buildJob?.cancel()
        index = null
        meta = null
    }

    companion object {
        const val MERGE_AT = 2000
        const val RECORD_KEY_INFO = "joinery-mailsearch:record:v1"
        const val CATCHUP_BATCH = 5000


        /** A search text as stored, `gz:` + base64(gzip) or plain, to its text. */
        fun inflate(value: String): String =
            if (value.startsWith("gz:")) String(VaultCrypto.gunzip(VaultCrypto.b64decode(value.removePrefix("gz:"))), Charsets.UTF_8)
            else value

        @Volatile
        private var instance: MailSearchIndex? = null

        fun get(context: Context, client: ApiClient, fortress: FortressMail, userId: Int): MailSearchIndex {
            instance?.let { if (it.client === client && it.userId == userId) return it }
            synchronized(this) {
                instance?.let { if (it.client === client && it.userId == userId) return it }
                val made = MailSearchIndex(context.applicationContext, client, fortress, userId)
                fortress.keys.held.onLock(made.lockListener)
                instance = made
                return made
            }
        }

        /** Sign-out: the in-memory index goes; the sealed records stay, opened
         *  only by the key a re-enrollment brings back. */
        fun onSignOut(@Suppress("UNUSED_PARAMETER") context: Context) {
            instance?.let {
                it.onLock()
                it.fortress.keys.held.removeOnLock(it.lockListener)
            }
            instance = null
        }
    }
}
