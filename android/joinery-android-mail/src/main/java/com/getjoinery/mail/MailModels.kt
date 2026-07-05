package com.getjoinery.mail

import com.getjoinery.android.JsonValue
import java.text.DateFormat
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

/** One tracked folder/label on a mailbox — the unit the reader's Move/Labels
 *  control and folder-filtered views operate on. */
data class MailFolder(
    val id: Int,
    val name: String,
    val role: String,
) {
    companion object {
        fun from(json: JsonValue): MailFolder? {
            val id = json["id"]?.intValue ?: return null
            return MailFolder(
                id = id,
                name = json["name"]?.stringValue ?: "",
                role = json["role"]?.stringValue ?: "custom",
            )
        }
    }
}

/** One granted mailbox from `inbound_email/mailboxes`. `foldersExclusive`
 *  drives whether the folder control is a single-pick "Move" (exclusive feed)
 *  or checkbox "Labels" (Gmail-style). */
data class Mailbox(
    val aliasId: Int,
    val address: String,
    val unread: Int,
    val total: Int,
    val folders: List<MailFolder>,
    val foldersExclusive: Boolean,
) {
    /** The local part — what the switcher shows when every grant shares a domain. */
    val localPart: String
        get() = address.substringBefore('@')

    companion object {
        fun from(json: JsonValue): Mailbox? {
            val aliasId = json["alias_id"]?.intValue ?: return null
            val address = json["address"]?.stringValue ?: return null
            if (address.isEmpty()) return null
            return Mailbox(
                aliasId = aliasId,
                address = address,
                unread = json["unread"]?.intValue ?: 0,
                total = json["total"]?.intValue ?: 0,
                folders = (json["folders"]?.arrayValue ?: emptyList())
                    .mapNotNull { MailFolder.from(it) },
                foldersExclusive = json["folders_exclusive"]?.boolValue ?: false,
            )
        }
    }
}

/** The `inbound_email/mailboxes` payload. */
data class MailboxHome(
    val mailboxes: List<Mailbox>,
    val canCompose: Boolean,
) {
    companion object {
        fun from(data: JsonValue?): MailboxHome? {
            if (data == null) return null
            return MailboxHome(
                mailboxes = (data["mailboxes"]?.arrayValue ?: emptyList())
                    .mapNotNull { Mailbox.from(it) },
                canCompose = data["can_compose"]?.boolValue ?: false,
            )
        }
    }
}

/** One row of `inbound_email/thread_list`. */
data class ThreadSummary(
    val threadKey: String,
    val subject: String,
    val sender: String,
    val snippet: String,
    val messageCount: Int,
    val unreadCount: Int,
    val isStarred: Boolean,
    val isArchived: Boolean,
    val latestTime: String,
) {
    val hasUnread: Boolean get() = unreadCount > 0

    companion object {
        fun from(json: JsonValue): ThreadSummary? {
            val key = json["thread_key"]?.stringValue ?: return null
            if (key.isEmpty()) return null
            return ThreadSummary(
                threadKey = key,
                subject = json["subject"]?.stringValue ?: "",
                sender = json["sender"]?.stringValue ?: "",
                snippet = json["snippet"]?.stringValue ?: "",
                messageCount = json["msg_count"]?.intValue ?: 1,
                unreadCount = json["unread_count"]?.intValue ?: 0,
                isStarred = json["any_starred"]?.boolValue ?: false,
                isArchived = json["any_archived"]?.boolValue ?: false,
                latestTime = json["latest_time"]?.stringValue ?: "",
            )
        }
    }
}

/** The `inbound_email/thread_list` payload. */
data class ThreadPage(
    val threads: List<ThreadSummary>,
    val hasMore: Boolean,
    val page: Int,
) {
    companion object {
        fun from(data: JsonValue?): ThreadPage? {
            if (data == null) return null
            return ThreadPage(
                threads = (data["threads"]?.arrayValue ?: emptyList())
                    .mapNotNull { ThreadSummary.from(it) },
                hasMore = data["has_more"]?.boolValue ?: false,
                page = data["page"]?.intValue ?: 1,
            )
        }
    }
}

/** A non-inline attachment on a message. [url] is a short-lived signed
 *  download URL when the bytes are file-backed, null otherwise. */
data class MailAttachment(
    val id: Int,
    val filename: String,
    val contentType: String,
    val sizeBytes: Int,
    val url: String?,
) {
    val sizeLabel: String
        get() {
            val bytes = sizeBytes.toDouble()
            if (bytes >= 1_048_576) return String.format(Locale.US, "%.1f MB", bytes / 1_048_576)
            if (bytes >= 1024) return String.format(Locale.US, "%.0f KB", bytes / 1024)
            return "$sizeBytes B"
        }

    companion object {
        fun from(json: JsonValue): MailAttachment? {
            val id = json["id"]?.intValue ?: return null
            return MailAttachment(
                id = id,
                filename = json["filename"]?.stringValue ?: "attachment",
                contentType = json["content_type"]?.stringValue ?: "application/octet-stream",
                sizeBytes = json["size_bytes"]?.intValue ?: 0,
                url = json["url"]?.takeUnless { it.isNull }?.stringValue,
            )
        }
    }
}

/** A file the user picked to attach to a send. Carried as a multipart part;
 *  the server re-detects the type and enforces the size/count caps and is the
 *  sole authority — the client only pre-filters the picker. */
data class MailOutgoingAttachment(
    val id: String = UUID.randomUUID().toString(),
    val filename: String,
    val mimeType: String,
    val data: ByteArray,
) {
    override fun equals(other: Any?): Boolean = other is MailOutgoingAttachment && other.id == id
    override fun hashCode(): Int = id.hashCode()
}

/** One message of `inbound_email/thread`. */
data class MailMessage(
    val id: Int,
    val aliasId: Int?,
    val sender: String,
    val recipient: String,
    val subject: String,
    val receivedTime: String,
    val isRead: Boolean,
    val isStarred: Boolean,
    val direction: String,
    val bodyPlain: String,
    val bodyHtml: String,
    val attachments: List<MailAttachment>,
) {
    val isOutbound: Boolean get() = direction == "outbound"

    companion object {
        fun from(json: JsonValue): MailMessage? {
            val id = json["id"]?.intValue ?: return null
            return MailMessage(
                id = id,
                aliasId = json["alias_id"]?.takeUnless { it.isNull }?.intValue,
                sender = json["sender"]?.stringValue ?: "",
                recipient = json["recipient"]?.stringValue ?: "",
                subject = json["subject"]?.stringValue ?: "",
                receivedTime = json["received_time"]?.stringValue ?: "",
                isRead = json["is_read"]?.boolValue ?: true,
                isStarred = json["is_starred"]?.boolValue ?: false,
                direction = json["direction"]?.stringValue ?: "inbound",
                bodyPlain = json["body_plain"]?.stringValue ?: "",
                bodyHtml = json["body_html"]?.stringValue ?: "",
                attachments = (json["attachments"]?.arrayValue ?: emptyList())
                    .mapNotNull { MailAttachment.from(it) },
            )
        }
    }
}

/** The `inbound_email/thread` payload: the in-scope messages plus the
 *  thread's current folder/label memberships (ids into the mailbox's
 *  [Mailbox.folders]). */
data class MailThread(
    val messages: List<MailMessage>,
    val folderIds: List<Int>,
) {
    companion object {
        fun from(data: JsonValue?): MailThread? {
            if (data == null) return null
            return MailThread(
                messages = (data["messages"]?.arrayValue ?: emptyList())
                    .mapNotNull { MailMessage.from(it) },
                folderIds = (data["folders"]?.arrayValue ?: emptyList())
                    .mapNotNull { it.intValue },
            )
        }
    }
}

// MARK: - Address + date display helpers

object MailDisplay {
    /** "Jane Doe <jane@x.com>" → "Jane Doe"; bare addresses → local part. */
    fun senderName(raw: String): String {
        val trimmed = raw.trim()
        val lt = trimmed.indexOf('<')
        if (lt > -1) {
            val name = trimmed.substring(0, lt).trim(' ', '"', '\'')
            if (name.isNotEmpty()) return name
        }
        val addr = address(trimmed)
        return addr.substringBefore('@')
    }

    /** The bare address inside an RFC-style sender string. */
    fun address(raw: String): String {
        val lt = raw.indexOf('<')
        val gt = raw.indexOf('>')
        if (lt > -1 && gt > lt) return raw.substring(lt + 1, gt)
        return raw.trim()
    }

    private fun dbFormatter(): SimpleDateFormat =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
            isLenient = false
        }

    /** Server times are UTC "yyyy-MM-dd HH:mm:ss(.ffffff)". */
    fun date(dbTime: String): Date? {
        if (dbTime.length < 19) return null
        return try {
            dbFormatter().parse(dbTime.substring(0, 19))
        } catch (e: Exception) {
            null
        }
    }

    /** Gmail-style list stamp: time today, "Jul 3" this year, else "7/3/25". */
    fun listStamp(dbTime: String, now: Date = Date()): String {
        val date = date(dbTime) ?: return ""
        val cal = Calendar.getInstance()
        val dateCal = Calendar.getInstance().apply { time = date }
        val nowCal = Calendar.getInstance().apply { time = now }
        cal.time = date
        val sameDay = dateCal.get(Calendar.YEAR) == nowCal.get(Calendar.YEAR) &&
            dateCal.get(Calendar.DAY_OF_YEAR) == nowCal.get(Calendar.DAY_OF_YEAR)
        return when {
            sameDay -> DateFormat.getTimeInstance(DateFormat.SHORT).format(date)
            dateCal.get(Calendar.YEAR) == nowCal.get(Calendar.YEAR) ->
                SimpleDateFormat("MMM d", Locale.getDefault()).format(date)
            else -> DateFormat.getDateInstance(DateFormat.SHORT).format(date)
        }
    }

    /** Header stamp inside a thread: "Jul 3, 2026, 9:41 AM". */
    fun messageStamp(dbTime: String): String {
        val date = date(dbTime) ?: return ""
        return DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(date)
    }

    /** Stable avatar hue for a sender (Gmail-style colored initial circle).
     *  FNV-1a over the bare lowercased address — the same bucketing as iOS. */
    fun avatarColorIndex(raw: String, paletteSize: Int): Int {
        val addr = address(raw).lowercase()
        var hash = 2166136261L.toInt()
        for (byte in addr.toByteArray(Charsets.UTF_8)) {
            hash = (hash xor (byte.toInt() and 0xFF)) * 16777619
        }
        val size = maxOf(paletteSize, 1)
        return ((hash.toLong() and 0xFFFFFFFFL) % size).toInt()
    }
}
