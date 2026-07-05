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
 * Thin typed face over the `inbound_email` API actions
 * (specs/mobile_native_email.md § Server-side). Every call rides the app's
 * session key through [ApiClient]; scoping is entirely server-side.
 */
class MailApi(val client: ApiClient) {

    suspend fun mailboxes(): MailboxHome {
        val envelope = client.submitAction("inbound_email/mailboxes", JsonValue.Obj(emptyList()))
        return MailboxHome.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun threadList(
        aliasId: Int?,
        view: MailView,
        folderId: Int?,
        query: String,
        page: Int,
    ): ThreadPage {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("page" to JsonValue.Num(page.toDouble()))
        if (aliasId != null) body.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
        if (folderId != null) {
            // A folder is its own view: membership-filtered, no inbox/spam flag.
            body.add("folder_id" to JsonValue.Num(folderId.toDouble()))
        } else when (view) {
            MailView.INBOX -> body.add("inbox" to JsonValue.Bool(true))
            MailView.STARRED -> body.add("starred_only" to JsonValue.Bool(true))
            MailView.ALL -> {}
            MailView.SPAM -> body.add("spam" to JsonValue.Bool(true))
        }
        if (query.isNotEmpty()) body.add("q" to JsonValue.Str(query))
        val envelope = client.submitAction("inbound_email/thread_list", JsonValue.Obj(body))
        return ThreadPage.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    suspend fun thread(key: String, aliasId: Int?): MailThread {
        val body = ArrayList<Pair<String, JsonValue>>()
        body.add("thread_key" to JsonValue.Str(key))
        if (aliasId != null) body.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
        val envelope = client.submitAction("inbound_email/thread", JsonValue.Obj(body))
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
        val envelope = client.submitAction("inbound_email/thread_action", JsonValue.Obj(body))
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
        val envelope = client.submitAction("inbound_email/thread_action", JsonValue.Obj(body))
        return envelope["data"]?.get("folder")?.let { MailFolder.from(it) }
    }

    /**
     * Send as the mailbox. For reply/reply-all/forward the server quotes the
     * original, normalizes the subject, and applies threading headers; for a
     * new message ([sourceId] null, [aliasId] set) it sends exactly as entered
     * and starts a fresh conversation. Either way the outbound copy is stored
     * (with an attachment manifest, so the sent copy shows what was attached).
     * When [attachments] is non-empty the call goes out as multipart so the
     * files reach the server's `$_FILES['attachments']`; otherwise it's a
     * plain JSON action.
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
    ) {
        if (attachments.isEmpty()) {
            val fields = ArrayList<Pair<String, JsonValue>>()
            fields.add("mode" to JsonValue.Str(mode.wire))
            if (sourceId != null) fields.add("source_id" to JsonValue.Num(sourceId.toDouble()))
            if (aliasId != null) fields.add("alias_id" to JsonValue.Num(aliasId.toDouble()))
            fields.add("to" to JsonValue.Str(to))
            fields.add("cc" to JsonValue.Str(cc))
            fields.add("subject" to JsonValue.Str(subject))
            fields.add("body" to JsonValue.Str(body))
            client.submitAction("inbound_email/send", JsonValue.Obj(fields))
        } else {
            val textFields = ArrayList<Pair<String, String>>()
            textFields.add("mode" to mode.wire)
            if (sourceId != null) textFields.add("source_id" to sourceId.toString())
            if (aliasId != null) textFields.add("alias_id" to aliasId.toString())
            textFields.add("to" to to)
            textFields.add("cc" to cc)
            textFields.add("subject" to subject)
            textFields.add("body" to body)
            val files = attachments.map {
                MultipartFile("attachments[]", it.filename, it.mimeType, it.data)
            }
            client.submitMultipart("inbound_email/send", textFields, files)
        }
    }
}
