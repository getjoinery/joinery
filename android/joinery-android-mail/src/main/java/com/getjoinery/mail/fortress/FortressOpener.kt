package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.DeviceKeys
import com.getjoinery.android.vault.SecretStoreLockedException
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.android.vault.VaultCryptoException
import com.getjoinery.mail.MailAttachment
import com.getjoinery.mail.MailMessage
import com.getjoinery.mail.ThreadSummary

/**
 * An end-to-end row as the server hands it: its columns as stored, ciphertext
 * untouched — `{key, sealed_scope, sealed_dek, sealed_ad_prefix, iem_*}` plus
 * `pending` (a relay-sealed row not yet parsed), `unopenable` (sealed to a key
 * no vault holds) and `foreign` (another person's mailbox).
 */
data class SealedRow(
    val key: Int,
    val scope: String,
    val sealedDek: String,
    val adPrefix: String,
    val fields: Map<String, String>,
    val pending: Boolean = false,
    val unopenable: Boolean = false,
    val foreign: Boolean = false,
) {
    /** The AD one of this row's fields is bound to. */
    fun ad(field: String): String = "$adPrefix$key:$field"

    companion object {
        fun from(json: JsonValue): SealedRow? {
            val pairs = json.objectValue ?: return null
            val key = json["key"]?.intValue ?: json["id"]?.intValue ?: return null
            val fields = LinkedHashMap<String, String>()
            pairs.forEach { (k, v) ->
                if (k.startsWith("iem_")) (v as? JsonValue.Str)?.value?.let { fields[k] = it }
            }
            return SealedRow(
                key = key,
                scope = json["sealed_scope"]?.stringValue ?: "",
                sealedDek = json["sealed_dek"]?.stringValue ?: "",
                adPrefix = json["sealed_ad_prefix"]?.stringValue ?: "",
                fields = fields,
                pending = json["pending"]?.boolValue ?: false,
                unopenable = json["unopenable"]?.boolValue ?: false,
                foreign = json["foreign"]?.boolValue ?: false,
            )
        }
    }
}

/** An attachment manifest entry (the sealed `iem_attachment_manifest`). */
data class ManifestEntry(
    val id: Int?,
    val mimePart: String,
    val filename: String,
    val contentType: String,
    val contentId: String,
    val inline: Boolean,
    val size: Int,
) {
    companion object {
        fun parse(text: String): List<ManifestEntry> {
            if (text.isBlank()) return emptyList()
            val arr = try { JsonValue.parse(text).arrayValue } catch (e: Exception) { null } ?: return emptyList()
            return arr.map { e ->
                ManifestEntry(
                    id = e["id"]?.takeUnless { it.isNull }?.intValue,
                    mimePart = e["mime_part"]?.stringValue ?: "",
                    filename = e["filename"]?.stringValue ?: "",
                    contentType = e["content_type"]?.stringValue ?: "",
                    contentId = e["content_id"]?.stringValue ?: "",
                    inline = e["inline"]?.boolValue ?: false,
                    size = e["size"]?.intValue ?: 0,
                )
            }
        }
    }
}

/**
 * The phone's half of end-to-end (Fortress) mail reading
 * (specs/fortress_mobile_apps.md § R4) — the browser's MailboxFortress
 * openList / openThread / attachmentBytes, over the key this phone holds:
 * each row's DEK opened with the mail secret, each field with the DEK under
 * AD `sealed_ad_prefix + key + ':' + field`, each part's stored bytes under
 * AD `…:att:{mime_part}`.
 *
 * Row keys are cached per message while the mail key is held and zeroed with
 * it (the held-keys lock clears this cache).
 */
class FortressOpener(
    private val keys: DeviceKeys,
    /** Fetch a signed URL's bytes (no credentials). */
    private val fetchBytes: suspend (String) -> ByteArray,
) {
    private val rowKeys = HashMap<Int, ByteArray>()
    private val lockListener: () -> Unit = { wipe() }

    init {
        keys.held.onLock(lockListener)
    }

    /** Sign-out: forget the row keys and stop listening for locks. */
    fun close() {
        keys.held.removeOnLock(lockListener)
        wipe()
    }

    val isOpen: Boolean get() = keys.isOpen(SCOPE)
    val holdsKey: Boolean get() = keys.holds(SCOPE)

    fun wipe() {
        synchronized(rowKeys) {
            rowKeys.values.forEach { it.fill(0) }
            rowKeys.clear()
        }
    }

    // MARK: Keys

    /** A row's DEK (a copy; the caller zeroes it). */
    fun dek(row: SealedRow): ByteArray = dekFor(row.key, row.sealedDek)

    fun dekFor(id: Int, sealedDek: String): ByteArray {
        synchronized(rowKeys) { rowKeys[id]?.let { return it.copyOf() } }
        val blob = stripEdgeSeal(sealedDek, SCOPE)
        val dek = keys.openSealed(SCOPE, blob)
        synchronized(rowKeys) { rowKeys[id] = dek.copyOf() }
        return dek
    }

    /** Open every `v1.edge.` field of a row. */
    fun openFields(row: SealedRow, only: Collection<String>? = null): Map<String, String> {
        val dek = dek(row)
        try {
            val out = LinkedHashMap<String, String>()
            for ((field, value) in row.fields) {
                if (only != null && field !in only) continue
                if (!value.startsWith(EDGE_FIELD)) continue
                out[field] = VaultCrypto.decryptString(value.removePrefix(EDGE_FIELD), dek, row.ad(field))
            }
            return out
        } finally {
            dek.fill(0)
        }
    }

    // MARK: The list

    /** The note a row shows when it is not opened here, or null to open it. */
    fun noteFor(row: SealedRow): String? = when {
        row.foreign -> FOREIGN_NOTE
        row.unopenable -> UNOPENABLE_NOTE
        row.pending -> PENDING_NOTE
        !holdsKey -> NO_KEY_NOTE
        !isOpen -> LOCKED_NOTE
        else -> null
    }

    fun openSummary(t: ThreadSummary): ThreadSummary {
        val row = t.sealed ?: return t
        noteFor(row)?.let { return placeholder(t, it) }
        return try {
            val o = openFields(row, LIST_FIELDS)
            t.copy(
                subject = o["iem_subject"] ?: "",
                sender = o["iem_sender"] ?: "",
                snippet = o["iem_snippet"] ?: "",
                aiSummary = o["iem_ai_summary"] ?: "",
                fortressNote = null,
            )
        } catch (e: SecretStoreLockedException) {
            placeholder(t, LOCKED_NOTE)
        } catch (e: Exception) {
            placeholder(t, FAILED_NOTE)
        }
    }

    private fun placeholder(t: ThreadSummary, note: String) =
        t.copy(subject = "", sender = "Encrypted", snippet = note, fortressNote = note)

    // MARK: A thread

    fun placeholderMessage(m: MailMessage, note: String): MailMessage = m.copy(
        bodyPlain = note,
        bodyHtml = "",
        fortressNote = note,
        attachments = m.attachments.filter { !it.inline }.map {
            it.copy(filename = "Encrypted attachment", contentType = "application/octet-stream", fortress = true, url = null)
        },
    )

    /**
     * Open one message: headers, bodies, the manifest naming its parts, and
     * its inline images rewritten to `data:` URLs (never written to disk).
     */
    suspend fun openMessage(m: MailMessage): MailMessage {
        val row = m.sealed ?: return m
        noteFor(row)?.let { return placeholderMessage(m, it) }
        return try {
            val o = openFields(row)
            val manifest = ManifestEntry.parse(o["iem_attachment_manifest"] ?: "")
            val byId = manifest.filter { it.id != null }.associateBy { it.id!! }
            // A row a device parsed from the relay names its parts by number: it
            // sealed the manifest before the server gave them ids.
            val byPart = manifest.filter { it.id == null && it.mimePart.isNotEmpty() }.associateBy { it.mimePart }
            val parts = m.attachments.map { a ->
                val e = byId[a.id] ?: byPart[a.mimePart]
                a.copy(
                    filename = e?.filename?.ifEmpty { null } ?: "attachment",
                    contentType = e?.contentType?.ifEmpty { null } ?: "application/octet-stream",
                    contentId = e?.contentId ?: "",
                    fortress = true,
                    messageId = m.id,
                    adPrefix = row.adPrefix,
                    sizeBytes = if (a.sizeBytes > 0) a.sizeBytes else (e?.size ?: 0),
                )
            }
            val inline = parts.filter { it.inline && it.contentId.isNotEmpty() }
            val html = o["iem_body_html"] ?: ""
            m.copy(
                sender = o["iem_sender"] ?: "",
                subject = o["iem_subject"] ?: "",
                bodyPlain = o["iem_body_plain"] ?: "",
                bodyHtml = if (html.isNotEmpty() && inline.isNotEmpty()) inlineRewrite(inline, html) else html,
                bodyHtmlSource = html,
                to = o["iem_to"] ?: "",
                cc = o["iem_cc"] ?: "",
                recipient = o["iem_recipient"]?.ifEmpty { null } ?: m.recipient,
                bcc = o["iem_bcc"] ?: m.bcc,
                aiSummary = o["iem_ai_summary"] ?: "",
                aiScan = o["iem_ai_scan"] ?: "",
                rawHeaders = o["iem_raw_headers"] ?: "",
                attachments = parts.filter { !it.inline },
                inlineParts = inline,
                fortressNote = null,
            )
        } catch (e: SecretStoreLockedException) {
            placeholderMessage(m, LOCKED_NOTE)
        } catch (e: Exception) {
            placeholderMessage(m, FAILED_NOTE)
        }
    }

    // MARK: Parts

    /** One part's plaintext: the stored ciphertext fetched by its signed URL,
     *  opened under the row DEK with the MIME-part AD. */
    suspend fun partBytes(att: MailAttachment): ByteArray {
        val url = att.url ?: throw VaultCryptoException("This attachment has no download link.")
        val stored = fetchBytes(url)
        val dek = synchronized(rowKeys) { rowKeys[att.messageId]?.copyOf() }
            ?: throw VaultCryptoException("Open the message again to read this attachment.")
        try {
            return openStoredPart(stored, dek, att.partAd)
        } finally {
            dek.fill(0)
        }
    }

    /** cid: references in an opened body → data: URLs of the opened images. */
    suspend fun inlineRewrite(inline: List<MailAttachment>, html: String): String {
        val map = HashMap<String, String>()
        for (a in inline) {
            val type = a.contentType.lowercase()
            if (type !in INLINE_IMAGE_TYPES || a.sizeBytes > INLINE_MAX_BYTES) continue
            try {
                val bytes = partBytes(a)
                map[a.contentId.trim('<', '>')] = "data:$type;base64," + VaultCrypto.b64encode(bytes)
                bytes.fill(0)
            } catch (e: Exception) {
                // The reference stays unresolved; the rest of the body renders.
            }
        }
        return rewriteCids(html, map)
    }

    companion object {
        const val SCOPE = "mail"
        const val EDGE_SEAL = "v1.edgeseal."
        const val EDGE_FIELD = "v1.edge."

        const val PENDING_NOTE = "Waiting to be opened on one of your devices."
        const val LOCKED_NOTE = "End-to-end encrypted. Unlock to read it."
        const val NO_KEY_NOTE = "Encrypted on your other devices."
        const val FAILED_NOTE = "This message could not be opened on this device."
        const val FOREIGN_NOTE = "End-to-end encrypted. Only the mailbox owner's devices can open it."
        const val UNOPENABLE_NOTE = "This message arrived sealed to a key your vault does not hold, so it cannot be opened. You can delete it."

        val LIST_FIELDS = setOf("iem_subject", "iem_sender", "iem_snippet", "iem_ai_summary")

        val INLINE_IMAGE_TYPES = setOf("image/png", "image/jpeg", "image/gif", "image/webp", "image/avif", "image/bmp")
        const val INLINE_MAX_BYTES = 5 * 1024 * 1024

        /** The raw blob of a `v1.edgeseal.{scope}.` key; refuses another scope. */
        fun stripEdgeSeal(sealed: String, scope: String): String {
            if (!sealed.startsWith(EDGE_SEAL)) throw VaultCryptoException("This row carries no device-sealed key.")
            val rest = sealed.removePrefix(EDGE_SEAL)
            val dot = rest.indexOf('.')
            if (dot < 1 || rest.substring(0, dot) != scope) throw VaultCryptoException("This is not sealed to your vault.")
            return rest.substring(dot + 1)
        }

        /** A stored part (`v1.edge.` + base64, as text) opened under [dek]. */
        fun openStoredPart(stored: ByteArray, dek: ByteArray, ad: String): ByteArray {
            val text = String(stored, Charsets.US_ASCII).trim()
            if (!text.startsWith(EDGE_FIELD)) throw VaultCryptoException("This part is not sealed for this device.")
            return VaultCrypto.decrypt(text.removePrefix(EDGE_FIELD), dek, ad)
        }

        /** A part sealed for storage: `v1.edge.` + base64(IV ‖ ct ‖ tag). */
        fun sealPart(bytes: ByteArray, dek: ByteArray, ad: String): String =
            EDGE_FIELD + VaultCrypto.encrypt(bytes, dek, ad)

        fun sealField(value: String, dek: ByteArray, ad: String): String =
            EDGE_FIELD + VaultCrypto.encryptString(value, dek, ad)

        private val CID = Regex("cid:([^\"'\\s>]+)", RegexOption.IGNORE_CASE)

        fun rewriteCids(html: String, map: Map<String, String>): String =
            CID.replace(html) { m ->
                val raw = m.groupValues[1]
                val key = try { java.net.URLDecoder.decode(raw.replace("+", "%2B"), "UTF-8") } catch (e: Exception) { raw }
                map[key.trim('<', '>')] ?: m.value
            }
    }
}
