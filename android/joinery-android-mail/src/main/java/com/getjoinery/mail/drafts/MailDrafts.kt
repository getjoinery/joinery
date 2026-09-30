package com.getjoinery.mail.drafts

import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue
import com.getjoinery.android.MultipartFile
import com.getjoinery.android.vault.DeviceEnrollment
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.ComposeMode
import com.getjoinery.mail.MailOutgoingAttachment
import com.getjoinery.mail.fortress.FortressOpener
import com.getjoinery.mail.fortress.ManifestEntry

/** What the compose sheet holds, as a draft save or a restore carries it. */
data class ComposeContent(
    val aliasId: Int?,
    val mode: ComposeMode,
    val sourceId: Int?,
    /** The From address (sealed into a Fortress draft; the server derives it otherwise). */
    val sender: String = "",
    val to: String = "",
    val cc: String = "",
    val bcc: String = "",
    val subject: String = "",
    val body: String = "",
) {
    val hasContent: Boolean
        get() = to.isNotBlank() || cc.isNotBlank() || bcc.isNotBlank() || subject.isNotBlank() || body.isNotBlank()
}

/** A part a draft already holds on the server. */
data class SavedPart(
    val id: Int?,
    val mimePart: String,
    val filename: String,
    val contentType: String,
    val contentId: String,
    val inline: Boolean,
    val size: Int,
    /** A signed link to the stored bytes (ciphertext for a Fortress draft). */
    val url: String? = null,
)

/** A draft opened for the compose sheet. */
data class OpenedDraft(
    val draftId: Int,
    val content: ComposeContent,
    /** Regular parts the draft holds (chips; kept on the next save). */
    val parts: List<SavedPart>,
    val fortress: Boolean,
    /** A draft made on a computer can carry inline images; the phone composes
     *  plain text, so it shows them as attachments. */
    val threadKey: String = "",
)

class DraftException(message: String) : Exception(message)

/**
 * Drafts on the phone, at every level (specs/fortress_mobile_apps.md § R12).
 *
 * Standard and Private: `draft_save` with the plain columns, new files as
 * uploads; the server seals a Private draft as it does for the web reader.
 *
 * Fortress: the browser's two-step save (client_custody_mail.md § WP4). The
 * first save posts only the clear columns and gets `{draft_id, id,
 * sealed_ad_prefix}`; every save after posts `sealed_dek` (one DEK for the
 * draft's life, sealed to the vault's current or pending key), `public_key`,
 * `fields` (the eleven sealed columns, each under the DEK with AD
 * `mail:{id}:{field}`), `parts` and `keep`. Text goes first, then one request
 * per new part (the browser's B7 shape), each sealed under the draft DEK with
 * AD `mail:{id}:att:{part}` and named `draft:` + 24 hex. `keep` is
 * authoritative. The server never opens anything.
 */
class MailDrafts(
    private val client: ApiClient,
    private val opener: FortressOpener?,
) {
    // MARK: One draft's state (one per open compose)

    class Session(val fortress: Boolean) {
        var draftId: Int? = null
        var adPrefix: String? = null
        /** The Fortress draft's DEK for its whole life; zeroed on lock or close. */
        var dek: ByteArray? = null
        val parts: MutableList<SavedPart> = ArrayList()

        fun wipe() {
            dek?.fill(0)
            dek = null
        }
    }

    // MARK: Save

    /**
     * Save [c] into [s]. [newFiles] are parts not saved yet; [keep] names the
     * saved parts still wanted (null = all). Returns the new files the save
     * persisted, so the caller drops exactly those from its pending list.
     */
    suspend fun save(
        s: Session,
        c: ComposeContent,
        newFiles: List<MailOutgoingAttachment>,
        keep: Set<String>?,
        isStillOpen: () -> Boolean = { true },
    ): List<MailOutgoingAttachment> =
        if (s.fortress) saveFortress(s, c, newFiles, keep, isStillOpen) else savePlain(s, c, newFiles)

    private suspend fun savePlain(s: Session, c: ComposeContent, newFiles: List<MailOutgoingAttachment>): List<MailOutgoingAttachment> {
        val fields = ArrayList<Pair<String, String>>()
        fields.add("alias_id" to (c.aliasId?.toString() ?: ""))
        s.draftId?.let { fields.add("draft_id" to it.toString()) }
        fields.add("mode" to c.mode.wire)
        fields.add("source_id" to (c.sourceId?.toString() ?: ""))
        fields.add("to" to c.to)
        fields.add("cc" to c.cc)
        fields.add("bcc" to c.bcc)
        fields.add("subject" to c.subject)
        fields.add("body" to c.body)
        fields.add("body_html" to "")
        val files = newFiles.map { MultipartFile("attachments[]", it.filename, it.mimeType, it.data) }
        val envelope = client.submitMultipart("mailbox/draft_save", fields, files)
        val data = envelope["data"] ?: throw JoineryApiError.Malformed
        if (data["locked"]?.boolValue == true) {
            throw DraftException(data["message"]?.stringValue ?: "Unlock your vault to save this draft with attachments.")
        }
        s.draftId = data["draft_id"]?.intValue ?: throw JoineryApiError.Malformed
        s.parts.clear()
        data["attachments"]?.arrayValue?.forEach { a ->
            s.parts.add(
                SavedPart(
                    id = a["id"]?.intValue, mimePart = "", filename = a["filename"]?.stringValue ?: "attachment",
                    contentType = a["content_type"]?.stringValue ?: "application/octet-stream", contentId = "",
                    inline = false, size = a["size_bytes"]?.intValue ?: 0,
                ),
            )
        }
        return newFiles
    }

    private fun clearFields(s: Session, c: ComposeContent): ArrayList<Pair<String, String>> {
        val fields = ArrayList<Pair<String, String>>()
        fields.add("fortress" to "1")
        fields.add("alias_id" to (c.aliasId?.toString() ?: ""))
        fields.add("mode" to c.mode.wire)
        fields.add("source_id" to (c.sourceId?.toString() ?: ""))
        s.draftId?.let { fields.add("draft_id" to it.toString()) }
        return fields
    }

    private suspend fun saveFortress(
        s: Session,
        c: ComposeContent,
        newFiles: List<MailOutgoingAttachment>,
        keep: Set<String>?,
        isStillOpen: () -> Boolean,
    ): List<MailOutgoingAttachment> {
        // Text first, then one request per new part.
        val steps = ArrayList<MailOutgoingAttachment?>()
        steps.add(null)
        newFiles.forEach { steps.add(it) }
        val saved = ArrayList<MailOutgoingAttachment>()
        var first = true
        for (file in steps) {
            val keepNow = if (first) (keep ?: s.parts.map { it.mimePart }.toSet()) else s.parts.map { it.mimePart }.toSet()
            first = false
            saveFortressStep(s, c, file, keepNow)
            if (file != null) saved.add(file)
            if (!isStillOpen()) break
        }
        return saved
    }

    private suspend fun saveFortressStep(s: Session, c: ComposeContent, file: MailOutgoingAttachment?, keep: Set<String>) {
        if (s.draftId == null) {
            val envelope = client.submitMultipart("mailbox/draft_save", clearFields(s, c), emptyList())
            val data = envelope["data"] ?: throw JoineryApiError.Malformed
            s.draftId = data["draft_id"]?.intValue ?: throw JoineryApiError.Malformed
            s.adPrefix = data["sealed_ad_prefix"]?.stringValue ?: "mail:"
        }
        val id = s.draftId!!
        val prefix = s.adPrefix ?: "mail:"
        val dek = s.dek ?: VaultCrypto.randomBytes(32).also { s.dek = it }
        val publicKey = mailPublicKey()

        val uploads = ArrayList<MultipartFile>()
        val uploadMeta = ArrayList<JsonValue>()
        val added = ArrayList<SavedPart>()
        if (file != null) {
            val part = "draft:" + VaultCrypto.toHex(VaultCrypto.randomBytes(12))
            val sealed = FortressOpener.sealPart(file.data, dek, "$prefix$id:att:$part")
            uploads.add(MultipartFile("attachments[]", part, "application/octet-stream", sealed.toByteArray(Charsets.US_ASCII)))
            uploadMeta.add(JsonValue.obj("mime_part" to JsonValue.Str(part), "size" to JsonValue.Num(file.data.size.toDouble()), "inline" to JsonValue.Bool(false)))
            added.add(SavedPart(null, part, file.filename, file.mimeType, "", false, file.data.size))
        }
        val parts = s.parts.filter { it.mimePart in keep } + added
        val values = linkedMapOf(
            "iem_sender" to c.sender,
            "iem_recipient" to listOf(c.to, c.cc).filter { it.isNotEmpty() }.joinToString(", "),
            "iem_to" to c.to,
            "iem_cc" to c.cc,
            "iem_bcc" to c.bcc,
            "iem_subject" to c.subject,
            "iem_body_html" to "",
            "iem_body_plain" to c.body,
            "iem_draft_state" to JsonValue.obj(
                "mode" to JsonValue.Str(c.mode.wire),
                "source_id" to JsonValue.Num((c.sourceId ?: 0).toDouble()),
                "to" to JsonValue.Str(c.to),
                "cc" to JsonValue.Str(c.cc),
            ).encoded(),
            "iem_snippet" to snippetOf(c.body),
            "iem_attachment_manifest" to if (parts.isEmpty()) "" else JsonValue.Arr(parts.map { p ->
                JsonValue.obj(
                    "mime_part" to JsonValue.Str(p.mimePart),
                    "filename" to JsonValue.Str(p.filename),
                    "content_type" to JsonValue.Str(p.contentType),
                    "content_id" to JsonValue.Str(p.contentId),
                    "inline" to JsonValue.Bool(p.inline),
                    "size" to JsonValue.Num(p.size.toDouble()),
                )
            }).encoded(),
        )
        val sealedFields = values.map { (col, v) ->
            // Empty stays bare, as the server stores it.
            col to JsonValue.Str(if (v.isEmpty()) "" else FortressOpener.sealField(v, dek, "$prefix$id:$col"))
        }
        val fields = clearFields(s, c)
        fields.add("sealed_dek" to FortressOpener.EDGE_SEAL + FortressOpener.SCOPE + "." + VaultCrypto.sealToPublicKey(dek, publicKey))
        fields.add("public_key" to publicKey)
        fields.add("fields" to JsonValue.Obj(sealedFields).encoded())
        fields.add("parts" to JsonValue.Arr(uploadMeta).encoded())
        fields.add("keep" to JsonValue.Arr(parts.map { JsonValue.Str(it.mimePart) }).encoded())

        val envelope = client.submitMultipart("mailbox/draft_save", fields, uploads)
        val data = envelope["data"] ?: throw JoineryApiError.Malformed
        val ids = HashMap<String, Pair<Int, String?>>()
        data["parts"]?.arrayValue?.forEach { p ->
            val mp = p["mime_part"]?.stringValue ?: return@forEach
            val pid = p["id"]?.intValue ?: return@forEach
            ids[mp] = pid to p["url"]?.takeUnless { it.isNull }?.stringValue
        }
        // What the server holds after this save, and nothing else.
        val now = parts.mapNotNull { p -> ids[p.mimePart]?.let { (pid, url) -> p.copy(id = pid, url = url ?: p.url) } }
        s.parts.clear()
        s.parts.addAll(now)
    }

    /** The key new material seals to: the pending key during a rotation. */
    private suspend fun mailPublicKey(): String {
        val probe = DeviceEnrollment.probe(client, FortressOpener.SCOPE)
        if (probe == null || !probe.setUp) throw DraftException("Set up your vault to write from an end-to-end encrypted mailbox.")
        return probe.pendingPublicKey ?: probe.publicKey ?: throw DraftException("Your vault has no key to seal to.")
    }

    // MARK: Open

    suspend fun open(draftId: Int): Pair<OpenedDraft, Session> {
        val envelope = client.submitAction("mailbox/draft_get", JsonValue.obj("draft_id" to JsonValue.Num(draftId.toDouble())))
        val data = envelope["data"] ?: throw JoineryApiError.Malformed
        if (data["locked"]?.boolValue == true) throw DraftException("Unlock your vault on a computer to open this draft.")
        val id = data["draft_id"]?.intValue ?: throw DraftException("This draft could not be opened.")
        val aliasId = data["alias_id"]?.intValue
        if (data["fortress"]?.boolValue == true) return openFortress(data, id, aliasId)

        val s = Session(fortress = false).apply { this.draftId = id }
        data["attachments"]?.arrayValue?.forEach { a ->
            s.parts.add(SavedPart(a["id"]?.intValue, "", a["filename"]?.stringValue ?: "attachment",
                a["content_type"]?.stringValue ?: "application/octet-stream", "", false, a["size_bytes"]?.intValue ?: 0))
        }
        val content = ComposeContent(
            aliasId = aliasId,
            mode = modeOf(data["mode"]?.stringValue),
            sourceId = data["source_id"]?.intValue?.takeIf { it > 0 },
            to = data["to"]?.stringValue ?: "",
            cc = data["cc"]?.stringValue ?: "",
            bcc = data["bcc"]?.stringValue ?: "",
            subject = data["subject"]?.stringValue ?: "",
            body = htmlToText(data["body_html"]?.stringValue ?: ""),
        )
        return OpenedDraft(id, content, s.parts.toList(), fortress = false) to s
    }

    private fun openFortress(data: JsonValue, id: Int, aliasId: Int?): Pair<OpenedDraft, Session> {
        val opener = opener ?: throw DraftException("This app cannot open end-to-end encrypted drafts.")
        val s = Session(fortress = true).apply {
            draftId = id
            adPrefix = data["sealed_ad_prefix"]?.stringValue ?: "mail:"
        }
        val sealed = data["sealed"]?.takeUnless { it.isNull }
        val serverParts = data["parts"]?.arrayValue ?: emptyList()
        if (sealed == null) {
            // A hollow draft: nothing sealed yet, an empty compose.
            return OpenedDraft(id, ComposeContent(aliasId, ComposeMode.NEW, null), emptyList(), true, data["thread_key"]?.stringValue ?: "") to s
        }
        val sealedDek = sealed["sealed_dek"]?.stringValue ?: ""
        val dek = opener.dekFor(id, sealedDek)
        s.dek = dek
        fun open(col: String): String {
            val v = sealed[col]?.stringValue ?: return ""
            if (!v.startsWith(FortressOpener.EDGE_FIELD)) return ""
            return VaultCrypto.decryptString(v.removePrefix(FortressOpener.EDGE_FIELD), dek, "${s.adPrefix}$id:$col")
        }
        val state = try { JsonValue.parse(open("iem_draft_state").ifEmpty { "{}" }) } catch (e: Exception) { JsonValue.Obj(emptyList()) }
        val manifest = ManifestEntry.parse(open("iem_attachment_manifest")).associateBy { it.mimePart }
        serverParts.forEach { p ->
            val mp = p["mime_part"]?.stringValue ?: return@forEach
            val e = manifest[mp]
            s.parts.add(
                SavedPart(
                    id = p["id"]?.intValue, mimePart = mp,
                    filename = e?.filename?.ifEmpty { null } ?: "attachment",
                    contentType = e?.contentType?.ifEmpty { null } ?: "application/octet-stream",
                    contentId = e?.contentId ?: "",
                    inline = p["inline"]?.boolValue ?: false,
                    size = p["size_bytes"]?.intValue ?: 0,
                    url = p["url"]?.takeUnless { it.isNull }?.stringValue,
                ),
            )
        }
        val plain = open("iem_body_plain").ifEmpty { htmlToText(open("iem_body_html")) }
        val content = ComposeContent(
            aliasId = aliasId,
            mode = modeOf(state["mode"]?.stringValue),
            sourceId = state["source_id"]?.intValue?.takeIf { it > 0 },
            sender = open("iem_sender"),
            to = state["to"]?.stringValue ?: "",
            cc = state["cc"]?.stringValue ?: "",
            bcc = open("iem_bcc"),
            subject = open("iem_subject"),
            body = plain,
        )
        return OpenedDraft(id, content, s.parts.toList(), true, data["thread_key"]?.stringValue ?: "") to s
    }

    /** A Fortress draft's saved parts named in [keep], opened, for a send. */
    suspend fun openedParts(s: Session, keep: Set<String>): List<MailOutgoingAttachment> {
        val dek = s.dek ?: throw DraftException("Open the draft again to send it.")
        val prefix = s.adPrefix ?: "mail:"
        val out = ArrayList<MailOutgoingAttachment>()
        for (p in s.parts) {
            if (p.mimePart !in keep) continue
            val url = p.url ?: throw DraftException("An attachment of this draft could not be fetched.")
            val stored = client.fetchBytes(url)
            val bytes = FortressOpener.openStoredPart(stored, dek, "$prefix${s.draftId}:att:${p.mimePart}")
            out.add(MailOutgoingAttachment(filename = p.filename, mimeType = p.contentType, data = bytes))
        }
        return out
    }

    // MARK: Delete

    suspend fun delete(draftId: Int) {
        client.submitAction("mailbox/draft_delete", JsonValue.obj("draft_id" to JsonValue.Num(draftId.toDouble())))
    }

    /** Remove one saved attachment from a Standard or Private draft. */
    suspend fun deleteAttachment(draftId: Int, attachmentId: Int) {
        client.submitAction(
            "mailbox/draft_attachment_delete",
            JsonValue.obj("draft_id" to JsonValue.Num(draftId.toDouble()), "attachment_id" to JsonValue.Num(attachmentId.toDouble())),
        )
    }

    companion object {
        fun modeOf(wire: String?): ComposeMode = ComposeMode.entries.firstOrNull { it.wire == wire } ?: ComposeMode.NEW

        /** The list preview, as the server derives it for a Fortress row. */
        fun snippetOf(plain: String): String =
            plain.take(4000).replace(Regex("\\s+"), " ").trim().take(240)

        /** A draft saved on a computer as HTML, as the plain text the phone edits. */
        fun htmlToText(html: String): String {
            if (html.isEmpty()) return ""
            var t = html.replace(Regex("(?is)<(script|style)[^>]*>.*?</\\1>"), "")
            t = t.replace(Regex("(?i)<br\\s*/?>"), "\n").replace(Regex("(?i)</(p|div|li|h[1-6]|tr)>"), "\n")
            t = t.replace(Regex("<[^>]+>"), "")
            t = t.replace("&nbsp;", " ").replace("&lt;", "<").replace("&gt;", ">").replace("&quot;", "\"")
                .replace("&#39;", "'").replace("&amp;", "&")
            return t.replace(Regex("\n{3,}"), "\n\n").trim()
        }
    }
}
