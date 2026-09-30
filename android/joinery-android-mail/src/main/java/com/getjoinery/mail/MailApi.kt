package com.getjoinery.mail

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AllInbox
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Report
import androidx.compose.material.icons.outlined.StarBorder
import androidx.compose.ui.graphics.vector.ImageVector
import com.getjoinery.android.ApiClient
import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.JsonValue
import com.getjoinery.android.MultipartFile

/** Which slice of a mailbox the thread list shows — the reader's views. */
enum class MailView(val title: String) {
    INBOX("Inbox"),
    STARRED("Starred"),
    ALL("All Mail"),
    SPAM("Spam");

    val icon: ImageVector
        get() = when (this) {
            INBOX -> Icons.Outlined.Inbox
            STARRED -> Icons.Outlined.StarBorder
            ALL -> Icons.Outlined.AllInbox
            SPAM -> Icons.Outlined.Report
        }
}

/** How a send relates to an existing message, if at all. */
enum class ComposeMode(val wire: String) {
    REPLY("reply"),
    REPLY_ALL("reply_all"),
    FORWARD("forward"),
    NEW("new"),
}

/**
 * Thin typed face over the `mailbox` API actions
 * (specs/mobile_native_email.md § Server-side). Every call rides the app's
 * session key through [ApiClient]; scoping is entirely server-side.
 */
class MailApi(val client: ApiClient) {

    suspend fun mailboxes(): MailboxHome {
        val envelope = client.submitAction("mailbox/mailboxes", JsonValue.Obj(emptyList()))
        return MailboxHome.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun threadList(
        aliasId: Int?,
        view: MailView,
        folderId: Int?,
        query: String,
        page: Int,
        drafts: Boolean = false,
        deviceHits: List<Int>? = null,
        deviceOnly: Boolean = false,
        perpage: Int? = null,
    ): ThreadPage {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("page" to JsonValue.Num(page.toDouble()))
        if (perpage != null) body.add("perpage" to JsonValue.Num(perpage.toDouble()))
        if (aliasId != null) body.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
        if (drafts) {
            body.add("drafts" to JsonValue.Bool(true))
        } else if (folderId != null) {
            // A folder is its own view: membership-filtered, no inbox/spam flag.
            body.add("folder_id" to JsonValue.Num(folderId.toDouble()))
        } else when (view) {
            MailView.INBOX -> body.add("inbox" to JsonValue.Bool(true))
            MailView.STARRED -> body.add("starred_only" to JsonValue.Bool(true))
            MailView.ALL -> {}
            MailView.SPAM -> body.add("spam" to JsonValue.Bool(true))
        }
        if (query.isNotEmpty()) body.add("q" to JsonValue.Str(query))
        if (deviceHits != null) {
            // What this phone's own index found in end-to-end mail (§ R5).
            body.add("device_hits" to JsonValue.Str(DeviceHits.pack(deviceHits.map { it.toLong() })))
            if (deviceOnly) body.add("device_only" to JsonValue.Bool(true))
        }
        val envelope = client.submitAction("mailbox/thread_list", JsonValue.Obj(body))
        return ThreadPage.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun thread(key: String, aliasId: Int?): MailThread {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("thread_key" to JsonValue.Str(key))
        if (aliasId != null) body.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
        val envelope = client.submitAction("mailbox/thread", JsonValue.Obj(body))
        return MailThread.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /** A thread-level state mutation (mark_read, star, archive, delete,
     *  mark_spam, set_membership, …). Returns the number of affected messages. */
    suspend fun threadAction(
        action: String,
        threadKey: String,
        aliasId: Int?,
        folderId: Int? = null,
        present: Boolean? = null,
    ): Int {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("action" to JsonValue.Str(action))
        body.add("thread_key" to JsonValue.Str(threadKey))
        if (aliasId != null) body.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
        if (folderId != null) body.add("folder_id" to JsonValue.Num(folderId.toDouble()))
        if (present != null) body.add("present" to JsonValue.Bool(present))
        val envelope = client.submitAction("mailbox/thread_action", JsonValue.Obj(body))
        return envelope["data"]?.get("count")?.intValue ?: 0
    }

    /** Create a folder/label on the thread's mailbox and file the thread into
     *  it — one call, matching the web reader's "New label / New folder" row. */
    suspend fun createFolder(name: String, threadKey: String, aliasId: Int?): MailFolder? {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("action" to JsonValue.Str("create_folder"))
        body.add("thread_key" to JsonValue.Str(threadKey))
        body.add("name" to JsonValue.Str(name))
        if (aliasId != null) body.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
        val envelope = client.submitAction("mailbox/thread_action", JsonValue.Obj(body))
        return envelope["data"]?.get("folder")?.let { MailFolder.from(it) }
    }

    /** What `mailbox/send` answered. */
    sealed class SendResult {
        data class Sent(val warning: String?) : SendResult()
        /** The sending lock is shut (HTTP 200 `{locked: true}`, B2): nothing
         *  was sent. The lock is a browser ceremony, so the phone says to send
         *  from a computer and keeps the sheet open. */
        data class Locked(val message: String) : SendResult()
    }

    /**
     * Send as the mailbox. For reply/reply-all/forward the server quotes the
     * original, normalizes the subject, and applies threading headers; for a
     * new message ([sourceId] null, [aliasId] set) it sends exactly as entered
     * and starts a fresh conversation. Either way the outbound copy is stored
     * (with an attachment manifest, so the sent copy shows what was attached).
     *
     * End-to-end mail (specs/fortress_mobile_apps.md § R6): the server cannot
     * read a Fortress source, so a reply or forward carries [sourceOpen] (what
     * this phone opened, for the quote) and a forward its parts, opened here,
     * with the inline ones named in [inlineManifest] (Content-ID → filename).
     * [draftId] turns a saved draft into the Sent row. `upload_count` lets the
     * server refuse a send PHP cut short.
     */
    suspend fun send(
        mode: ComposeMode,
        sourceId: Int? = null,
        aliasId: Int? = null,
        to: String,
        cc: String,
        subject: String,
        body: String,
        attachments: List<MailOutgoingAttachment> = emptyList(),
        bcc: String = "",
        sourceOpen: JsonValue? = null,
        inlineManifest: Map<String, String> = emptyMap(),
        draftId: Int? = null,
    ): SendResult {
        val fields = ArrayList<Pair<String, String>>()
        fields.add("mode" to mode.wire)
        if (sourceId != null) fields.add("source_id" to sourceId.toString())
        if (aliasId != null) fields.add("alias_id" to aliasId.toString())
        fields.add("to" to to)
        fields.add("cc" to cc)
        if (bcc.isNotEmpty()) fields.add("bcc" to bcc)
        fields.add("subject" to subject)
        fields.add("body" to body)
        if (sourceOpen != null) fields.add("source_open" to sourceOpen.encoded())
        if (inlineManifest.isNotEmpty()) {
            fields.add("inline_manifest" to JsonValue.Obj(inlineManifest.map { it.key to JsonValue.Str(it.value) }).encoded())
        }
        if (draftId != null) fields.add("draft_id" to draftId.toString())
        fields.add("upload_count" to attachments.size.toString())

        val envelope = if (attachments.isEmpty()) {
            client.submitAction("mailbox/send", JsonValue.Obj(fields.map { it.first to JsonValue.Str(it.second) }))
        } else {
            val files = attachments.map {
                MultipartFile("attachments[]", it.filename, it.mimeType, it.data)
            }
            client.submitMultipart("mailbox/send", fields, files)
        }
        val data = envelope["data"]
        if (data?.get("locked")?.boolValue == true) {
            return SendResult.Locked(
                data["message"]?.stringValue?.takeIf { it.isNotEmpty() }
                    ?: "Sending from this address needs your vault unlocked.",
            )
        }
        return SendResult.Sent(data?.get("warning")?.stringValue?.takeIf { it.isNotEmpty() })
    }
}
